-- Plan M8 and M11 gaps: order-update emails through a retrying outbox, and re-ordering a trip's remaining stops
-- (SSTF from the rider's position) after a failed stop or when the rider asks to re-plan.
-- Requires 20261122000300_capacity_review_fixes.sql. Paste the whole file into the Supabase SQL Editor.
begin;

-- M8 ----------------------------------------------------------------------------------------------------
-- One row per customer notice worth emailing. The PHP sender claims due rows with the service key, sends them over
-- SMTP and reports back; failures retry with backoff (2, 4, 8, 16 minutes) and give up after 5 attempts. A failed email
-- never blocks or undoes the order change that caused it.
create table if not exists private.email_outbox (
 id uuid primary key default gen_random_uuid(), notification_id uuid unique, recipient_id uuid not null references auth.users(id) on delete cascade,
 station_id uuid, order_id uuid, subject text not null check (length(subject) <= 200), body text not null check (length(body) <= 1000), link text check (length(link) <= 300),
 status text not null default 'pending' check (status in ('pending','sending','sent','failed')),
 attempts int not null default 0, next_attempt_at timestamptz not null default now(), locked_until timestamptz,
 last_error text check (length(last_error) <= 300), created_at timestamptz not null default now(), sent_at timestamptz);
create index if not exists email_outbox_due_idx on private.email_outbox(next_attempt_at) where status in ('pending','sending');
revoke all on private.email_outbox from public, anon, authenticated;

-- Customer order and reservation notices are emailed when the customer keeps "order updates" on (the default).
-- Station-side notices (kind 'staff_...') stay in the app.
create or replace function private.enqueue_notice_email() returns trigger language plpgsql security definer set search_path = '' as $$
begin
 if new.kind like 'staff\_%' or not (new.kind like 'order\_%' or new.kind like 'reservation\_%') then return null; end if;
 if coalesce((select p.order_updates from public.profiles p where p.id = new.recipient_id), true) is not true then return null; end if;
 insert into private.email_outbox(notification_id, recipient_id, station_id, order_id, subject, body, link)
 values (new.id, new.recipient_id, new.station_id, new.order_id, left(coalesce(nullif(new.title, ''), 'HydroRoute update'), 200), left(coalesce(new.body, ''), 1000),
  case when new.order_id is not null then '/customer/tracking?order=' || new.order_id else '/customer/notifications' end)
 on conflict (notification_id) do nothing;
 return null;
end $$;
drop trigger if exists notifications_email on public.notifications;
create trigger notifications_email after insert on public.notifications for each row execute function private.enqueue_notice_email();

-- Service key only: lock up to 20 due emails for two minutes and return what the sender needs.
create or replace function public.claim_email_batch(p_limit int) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rows jsonb;
begin
 with picked as (
  select id from private.email_outbox where status in ('pending','sending') and next_attempt_at <= now() and (locked_until is null or locked_until < now())
  order by next_attempt_at, created_at limit greatest(1, least(coalesce(p_limit, 5), 20)) for update skip locked),
 claimed as (
  update private.email_outbox e set status = 'sending', locked_until = now() + interval '2 minutes', attempts = e.attempts + 1
  from picked where e.id = picked.id returning e.*)
 select coalesce(jsonb_agg(jsonb_build_object('id', c.id, 'email', u.email, 'name', coalesce(nullif(p.display_name, ''), u.raw_user_meta_data->>'full_name', ''),
   'subject', c.subject, 'body', c.body, 'link', c.link, 'attempts', c.attempts, 'station', s.name) order by c.next_attempt_at), '[]'::jsonb)
 into rows from claimed c join auth.users u on u.id = c.recipient_id left join public.profiles p on p.id = c.recipient_id left join public.stations s on s.id = c.station_id;
 return jsonb_build_object('emails', rows);
end $$;

create or replace function public.finish_email(p_id uuid, p_ok boolean, p_error text) returns jsonb language plpgsql security definer set search_path = '' as $$
declare e private.email_outbox;
begin
 select * into e from private.email_outbox where id = p_id for update;
 if not found or e.status <> 'sending' then return jsonb_build_object('ok', false); end if;
 if p_ok then
  update private.email_outbox set status = 'sent', sent_at = now(), locked_until = null, last_error = null where id = p_id;
 elsif e.attempts >= 5 then
  update private.email_outbox set status = 'failed', locked_until = null, last_error = left(coalesce(p_error, 'unknown'), 300) where id = p_id;
 else
  update private.email_outbox set status = 'pending', locked_until = null, last_error = left(coalesce(p_error, 'unknown'), 300),
   next_attempt_at = now() + make_interval(mins => (2 ^ e.attempts)::int) where id = p_id;
 end if;
 return jsonb_build_object('ok', true);
end $$;

-- M11 ---------------------------------------------------------------------------------------------------
-- SSTF from any point: nearest unvisited stop next, ties to the higher priority, then the order id (as private.sstf).
create or replace function private.sstf_from(sid uuid, oids uuid[], start_lat numeric, start_lng numeric, t timestamptz)
returns table(order_id uuid, seq int, leg_km numeric) language plpgsql stable security definer set search_path = '' as $$
declare cur_lat numeric := start_lat; cur_lng numeric := start_lng; remaining uuid[] := oids; n int := 0; pick record;
begin
 while coalesce(array_length(remaining, 1), 0) > 0 loop
  select o.id, o.address_latitude la, o.address_longitude lo, round(private.haversine_km(cur_lat, cur_lng, o.address_latitude, o.address_longitude), 6) d
  into pick from public.orders o join public.station_settings cfg on cfg.station_id = o.station_id
  left join public.customer_station_profiles cp on cp.station_id = o.station_id and cp.customer_id = o.customer_id
  cross join lateral private.score(o, cfg, cp.classification = 'institution', t) as sc(score)
  where o.id = any(remaining)
  order by d, (sc.score->>'p')::int desc, o.id limit 1;
  n := n + 1; order_id := pick.id; seq := n; leg_km := round(pick.d, 3);
  return next;
  cur_lat := pick.la; cur_lng := pick.lo; remaining := array_remove(remaining, pick.id);
 end loop;
end $$;

-- Reorders an active trip's pending stops from a point, reusing their stop numbers. Arrived and resolved stops keep theirs.
create or replace function private.resequence_stops(tid uuid, from_lat numeric, from_lng numeric) returns int language plpgsql security definer set search_path = '' as $$
declare tr public.delivery_trips; ids uuid[]; seqs int[]; s record; i int := 0;
begin
 select * into tr from public.delivery_trips where id = tid;
 if not found or tr.status <> 'active' or from_lat is null or from_lng is null then return 0; end if;
 select array_agg(x.order_id order by x.sequence), array_agg(x.sequence order by x.sequence) into ids, seqs
 from public.trip_orders x where x.trip_id = tid and x.active and x.state = 'pending';
 if coalesce(array_length(ids, 1), 0) < 2 then return 0; end if;
 update public.trip_orders set sequence = sequence + 100000 where trip_id = tid and active and state = 'pending';
 for s in select * from private.sstf_from(tr.station_id, ids, from_lat, from_lng, now()) loop
  i := i + 1;
  update public.trip_orders set sequence = seqs[i], leg_km = s.leg_km, version = version + 1 where trip_id = tid and order_id = s.order_id and active and state = 'pending';
 end loop;
 return i;
end $$;

-- After a failed stop the rider is at that address: continue with the nearest remaining stop from there.
create or replace function private.trip_stop_failed() returns trigger language plpgsql security definer set search_path = '' as $$
declare la numeric; lo numeric;
begin
 if new.state = 'failed' and old.state is distinct from 'failed' then
  select address_latitude, address_longitude into la, lo from public.orders where id = new.order_id;
  perform private.resequence_stops(new.trip_id, la, lo);
 end if;
 return null;
end $$;
drop trigger if exists trip_orders_resequence on public.trip_orders;
create trigger trip_orders_resequence after update of state on public.trip_orders for each row execute function private.trip_stop_failed();

-- Rider: "Re-plan from here" when they change course. Own active trip only; the point must be a real coordinate.
create or replace function public.resequence_trip(p_trip_id uuid, p_latitude numeric, p_longitude numeric, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare tr public.delivery_trips; r jsonb; payload jsonb := jsonb_build_object('trip_id', p_trip_id, 'latitude', p_latitude, 'longitude', p_longitude); n int;
begin
 tr := private.assigned_trip(p_trip_id);
 r := private.replay(tr.station_id::text, 'resequence_trip', p_request_key, payload);
 if r is not null then return r; end if;
 if tr.status <> 'active' then return private.envelope('{}', 'invalid_transition', jsonb_build_object('trip', 'This trip is closed.')); end if;
 if p_latitude is null or p_longitude is null or p_latitude not between -90 and 90 or p_longitude not between -180 and 180 then
  return private.envelope('{}', 'validation', jsonb_build_object('location', 'Turn on location to re-plan from where you are.'));
 end if;
 perform 1 from public.delivery_trips where id = p_trip_id for update;
 n := private.resequence_stops(p_trip_id, p_latitude, p_longitude);
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, after_values)
 values (tr.station_id, auth.uid(), 'resequence_trip', p_trip_id, p_request_key, jsonb_build_object('stops', n));
 return private.remember(tr.station_id::text, 'resequence_trip', p_request_key, payload, private.envelope(jsonb_build_object('trip_id', p_trip_id, 'stops', n)));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private'
   and p.proname in ('enqueue_notice_email','sstf_from','resequence_stops','trip_stop_failed') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in ('claim_email_batch','finish_email') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated';
  execute 'grant execute on function ' || f.sig || ' to service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname = 'resequence_trip' loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
