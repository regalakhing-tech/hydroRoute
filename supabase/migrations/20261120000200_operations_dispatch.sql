-- Phases 5 and 6: station queues and priority, preparation, pickup collection, dispatch with SSTF, rider arrival
-- and results, cash and empties verification, reviews, completion, customer classification and rider settings.
-- Requires 20261120000100_orders_tracking.sql. Paste the whole file into the Supabase SQL Editor.
-- Deviation from the design note: the separate order_collections table is merged into payments
-- (one row per collected order holding cash applied/tendered/change and expected/returned empties).
begin;

alter table private.station_counters add column if not exists next_trip_number bigint not null default 1;

create table if not exists public.customer_station_profiles (
 station_id uuid not null references public.stations(id), customer_id uuid not null references auth.users(id),
 classification text not null default 'household' check (classification in ('household','institution')),
 actor_id uuid references auth.users(id), updated_at timestamptz not null default now(), version int not null default 1,
 primary key (station_id, customer_id));
create index if not exists customer_station_profiles_customer_idx on public.customer_station_profiles(customer_id);

-- A human rider is busy across every station until their trip closes.
create table if not exists private.rider_reservations (
 user_id uuid primary key references auth.users(id), station_id uuid not null, trip_id uuid not null, created_at timestamptz not null default now());

create table if not exists public.delivery_trips (
 id uuid primary key default gen_random_uuid(), station_id uuid not null references public.stations(id), trip_no bigint not null,
 rider_id uuid not null, rider_user_id uuid not null references auth.users(id), capacity int not null check (capacity > 0),
 load_units int not null check (load_units >= 0), status text not null default 'active' check (status in ('active','closed')),
 planned_km numeric not null default 0, traveled_km numeric, dispatched_by uuid references auth.users(id), dispatched_at timestamptz not null default now(),
 closed_at timestamptz, closed_by uuid references auth.users(id), close_reason text check (length(close_reason) <= 300),
 request_key uuid not null, version int not null default 1,
 unique (id, station_id), unique (station_id, trip_no), unique (station_id, request_key), check (load_units <= capacity),
 foreign key (rider_id, station_id) references public.riders(id, station_id));
create unique index if not exists delivery_trips_active_rider_idx on public.delivery_trips(rider_user_id) where status = 'active';
create index if not exists delivery_trips_station_idx on public.delivery_trips(station_id, dispatched_at desc);
create index if not exists delivery_trips_rider_idx on public.delivery_trips(rider_id, station_id);

create table if not exists public.trip_orders (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, trip_id uuid not null, order_id uuid not null,
 sequence int not null check (sequence > 0), leg_km numeric not null, station_km numeric not null, priority_score int not null,
 manual_front_reason text check (length(manual_front_reason) <= 300),
 state text not null default 'pending' check (state in ('pending','arrived','delivered','failed')),
 active boolean not null default true, version int not null default 1, created_at timestamptz not null default now(),
 unique (id, station_id), unique (trip_id, sequence),
 foreign key (trip_id, station_id) references public.delivery_trips(id, station_id),
 foreign key (order_id, station_id) references public.orders(id, station_id));
create unique index if not exists trip_orders_active_order_idx on public.trip_orders(order_id) where active;
create index if not exists trip_orders_order_idx on public.trip_orders(order_id, station_id);

create table if not exists public.rider_locations (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, trip_id uuid not null, rider_user_id uuid not null references auth.users(id),
 latitude numeric not null check (latitude between -90 and 90), longitude numeric not null check (longitude between -180 and 180),
 accuracy numeric not null check (accuracy >= 0), recorded_at timestamptz not null default now(), request_key uuid,
 foreign key (trip_id, station_id) references public.delivery_trips(id, station_id) on delete cascade);
create index if not exists rider_locations_trip_idx on public.rider_locations(trip_id, recorded_at, id);
create index if not exists rider_locations_retention_idx on public.rider_locations(recorded_at);
create unique index if not exists rider_locations_key_idx on public.rider_locations(trip_id, request_key) where request_key is not null;

create table if not exists public.delivery_attempts (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, stop_id uuid not null unique, order_id uuid not null,
 state text not null default 'open' check (state in ('open','delivered','failed')), failure_reason text check (length(failure_reason) <= 500),
 empties_returned int check (empties_returned >= 0), arrival_latitude numeric, arrival_longitude numeric, arrival_accuracy numeric,
 fallback_reason text check (length(fallback_reason) <= 300), measured_km numeric, measured_fee_centavos int,
 fee_decision text not null check (fee_decision in ('unchanged','lowered','flagged','fallback')), amount_due_centavos bigint not null check (amount_due_centavos >= 0),
 arrived_at timestamptz not null default now(), resolved_at timestamptz, actor_id uuid not null references auth.users(id), version int not null default 1,
 unique (id, station_id), foreign key (stop_id, station_id) references public.trip_orders(id, station_id),
 foreign key (order_id, station_id) references public.orders(id, station_id));
create index if not exists delivery_attempts_order_idx on public.delivery_attempts(order_id, station_id);

create table if not exists public.payments (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, order_id uuid not null unique, attempt_id uuid,
 applied_centavos bigint not null check (applied_centavos >= 0), tendered_centavos bigint not null, change_centavos bigint not null check (change_centavos >= 0),
 expected_empties int not null default 0 check (expected_empties >= 0), empties_returned int not null default 0 check (empties_returned >= 0),
 state text not null default 'unverified' check (state in ('unverified','verified')),
 collected_by uuid not null references auth.users(id), collected_at timestamptz not null default now(),
 verified_by uuid references auth.users(id), verified_at timestamptz, verify_note text check (length(verify_note) <= 300),
 version int not null default 1, request_key uuid,
 check (tendered_centavos - change_centavos = applied_centavos), unique (id, station_id),
 foreign key (order_id, station_id) references public.orders(id, station_id),
 foreign key (attempt_id, station_id) references public.delivery_attempts(id, station_id));
create index if not exists payments_station_idx on public.payments(station_id, collected_at);
create index if not exists payments_attempt_idx on public.payments(attempt_id, station_id);

create table if not exists public.order_reviews (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, order_id uuid not null,
 kind text not null check (kind in ('overdue','fee_mismatch','failed_attempt','empties_mismatch','capacity')),
 state text not null default 'open' check (state in ('open','resolved')), detail text not null default '' check (length(detail) <= 500),
 resolution text check (length(resolution) <= 500), resolved_by uuid references auth.users(id), resolved_at timestamptz,
 version int not null default 1, created_at timestamptz not null default now(),
 foreign key (order_id, station_id) references public.orders(id, station_id));
create unique index if not exists order_reviews_open_idx on public.order_reviews(order_id, kind) where state = 'open';
create index if not exists order_reviews_station_idx on public.order_reviews(station_id, state, created_at);

do $$declare t text; begin
 foreach t in array array['customer_station_profiles','delivery_trips','trip_orders','rider_locations','delivery_attempts','payments','order_reviews'] loop
  execute format('alter table public.%I enable row level security', t);
  execute format('revoke all on public.%I from public, anon, authenticated', t);
 end loop;
 alter table private.rider_reservations enable row level security;
 revoke all on private.rider_reservations from public, anon, authenticated;
end $$;

-- Helpers ---------------------------------------------------------------------------------------------
-- Non-locking owner check for read RPCs (owner_guard takes FOR SHARE locks, which read-only transactions refuse).
create or replace function private.owner_check(sid uuid) returns void language plpgsql stable security definer set search_path = '' as $$
begin
 if not private.station_admin(sid) then raise exception 'Station access denied.' using errcode = '42501'; end if;
end $$;

create or replace function private.rider_guard() returns void language plpgsql stable security definer set search_path = '' as $$
begin
 if auth.uid() is null or private.has_role('super_admin') or not private.has_role('rider') then raise exception 'Rider access denied.' using errcode = '42501'; end if;
end $$;

create or replace function private.manila_midnight(t timestamptz) returns timestamptz language sql immutable set search_path = '' as $$
 select ((t at time zone 'Asia/Manila')::date)::timestamp at time zone 'Asia/Manila'
$$;

-- Stage 2: P = U + A + C, from one authoritative function evaluated at a given time.
create or replace function private.score(o public.orders, cfg public.station_settings, inst boolean, t timestamptz) returns jsonb language sql stable set search_path = '' as $$
 select jsonb_build_object('u', u, 'a', a, 'c', c, 'p', u + a + c, 'waited_minutes', waited) from (
  select case when o.schedule_mode is distinct from 'scheduled' or mins <= cfg.urgency_immediate_minutes then cfg.urgency_asap
    when mins <= cfg.urgency_soon_minutes then cfg.urgency_soon else cfg.urgency_later end u,
   least(cfg.aging_cap, (floor(greatest(waited, 0) / cfg.aging_interval_minutes) * cfg.aging_points)::int) a,
   case when cfg.institution_bonus_enabled and coalesce(inst, false) then cfg.institution_bonus else 0 end c, waited
  from (select extract(epoch from (o.requested_at - t)) / 60 mins,
   case when o.queued_at is null or o.redispatch_paused then 0 else floor(extract(epoch from (t - o.queued_at)) / 60)::int end waited) m) x
$$;

create or replace function private.queue_rows(sid uuid, statuses text[], ful text, t timestamptz) returns jsonb language sql stable security definer set search_path = '' as $$
 select coalesce(jsonb_agg(r.j order by (r.score->>'p')::int desc, r.queued_at nulls last, r.created_at, r.id), '[]') from (
  select o.id, o.queued_at, o.created_at, sc.score, jsonb_build_object('id', o.id, 'order_no', coalesce(o.order_no, upper(left(o.id::text, 8))), 'status', o.status,
   'fulfillment', o.fulfillment, 'version', o.version, 'customer_id', o.customer_id, 'customer_name', o.contact_name, 'contact_phone', o.contact_phone,
   'barangay', o.address_barangay, 'barangay_id', o.address_barangay_id, 'address_text', o.address_text, 'total_units', o.total_units, 'load_units', o.load_units,
   'total_centavos', o.total_centavos, 'schedule_mode', o.schedule_mode, 'requested_at', o.requested_at, 'queued_at', o.queued_at, 'created_at', o.created_at,
   'redispatch_paused', o.redispatch_paused, 'note', o.note, 'score', sc.score, 'classification', coalesce(cp.classification, 'household'),
   'overdue', (sc.score->>'waited_minutes')::int > cfg.overdue_minutes or (o.schedule_mode = 'scheduled' and o.requested_at < t and o.status in ('confirmed','preparing','ready')),
   'due', o.schedule_mode is distinct from 'scheduled' or o.requested_at <= t + make_interval(mins => cfg.urgency_immediate_minutes)) j
  from public.orders o join public.station_settings cfg on cfg.station_id = o.station_id
  left join public.customer_station_profiles cp on cp.station_id = o.station_id and cp.customer_id = o.customer_id
  cross join lateral private.score(o, cfg, cp.classification = 'institution', t) as sc(score)
  where o.station_id = sid and o.status = any(statuses) and (ful is null or o.fulfillment = ful)) r
$$;

-- Overdue alerts need an acknowledged reason; flag each waiting order once.
create or replace function private.flag_overdue(sid uuid) returns void language sql security definer set search_path = '' as $$
 insert into public.order_reviews(station_id, order_id, kind, detail)
 select o.station_id, o.id, 'overdue', 'Waiting since ' || to_char(o.queued_at at time zone 'Asia/Manila', 'Mon DD, HH12:MI AM')
 from public.orders o join public.station_settings cfg on cfg.station_id = o.station_id
 where o.station_id = sid and o.status in ('confirmed','preparing','ready') and not o.redispatch_paused
  and o.queued_at < now() - make_interval(mins => cfg.overdue_minutes)
  and not exists (select 1 from public.order_reviews r where r.order_id = o.id and r.kind = 'overdue')
 on conflict do nothing
$$;

create or replace function private.open_reviews(sid uuid) returns jsonb language sql stable security definer set search_path = '' as $$
 select coalesce(jsonb_agg(x order by x->>'created_at'), '[]') from (
  select jsonb_build_object('id', r.id, 'order_id', r.order_id, 'order_no', o.order_no, 'kind', r.kind, 'detail', r.detail, 'version', r.version, 'created_at', r.created_at) x
  from public.order_reviews r join public.orders o on o.id = r.order_id where r.station_id = sid and r.state = 'open'
  union all
  select jsonb_build_object('id', f.id, 'order_id', f.order_id, 'order_no', o.order_no, 'kind', 'closure', 'detail', 'Affected by a station closure. Contact the customer or reschedule.', 'version', 1, 'created_at', f.created_at)
  from public.order_review_flags f join public.orders o on o.id = f.order_id
  where f.station_id = sid and f.resolved_at is null and o.status not in ('completed','cancelled')) y
$$;

create or replace function private.free_riders(sid uuid) returns table(rider_id uuid, user_id uuid, capacity int, trips_today bigint, name text) language sql stable security definer set search_path = '' as $$
 select r.id, r.user_id, r.capacity,
  (select count(*) from public.delivery_trips d where d.station_id = sid and d.rider_user_id = r.user_id and d.dispatched_at >= private.manila_midnight(now())),
  coalesce(dr.full_name, 'Rider')
 from public.riders r
 join private.station_memberships m on m.station_id = r.station_id and m.user_id = r.user_id and m.active
 join private.station_membership_roles mr on mr.station_id = r.station_id and mr.user_id = r.user_id and mr.role = 'rider' and mr.active
 left join public.drivers dr on dr.user_id = r.user_id and dr.station_id = sid
 where r.station_id = sid and r.enabled and coalesce(dr.active, true)
  and not exists (select 1 from private.rider_reservations x where x.user_id = r.user_id)
  and not exists (select 1 from private.user_roles u where u.user_id = r.user_id and u.role = 'super_admin')
 order by 4, r.id
$$;

-- Stage 4 eligibility: ready delivery orders, due within the immediate window, not paused, not on a trip, with load and pin.
create or replace function private.dispatch_candidates(sid uuid, t timestamptz) returns table(order_id uuid, order_no text, load int, barangay_id uuid, barangay text, score int, overdue boolean, customer_name text)
language sql stable security definer set search_path = '' as $$
 select (j->>'id')::uuid, j->>'order_no', (j->>'load_units')::int, (j->>'barangay_id')::uuid, j->>'barangay', (j->'score'->>'p')::int, (j->>'overdue')::boolean, j->>'customer_name'
 from jsonb_array_elements(private.queue_rows(sid, array['ready'], 'delivery', t)) with ordinality q(j, n)
 where (j->>'due')::boolean and not (j->>'redispatch_paused')::boolean and j->>'load_units' is not null
  and not exists (select 1 from public.trip_orders s where s.order_id = (j->>'id')::uuid and s.active)
  and exists (select 1 from public.orders o where o.id = (j->>'id')::uuid and o.address_latitude is not null)
 order by n
$$;

-- SSTF: from the station pin, repeatedly visit the nearest unvisited stop (distance quantized to 0.000001 km, D11);
-- ties go to the higher priority, then the order id. An optional overdue stop goes first.
create or replace function private.sstf(sid uuid, oids uuid[], front uuid, t timestamptz) returns table(order_id uuid, seq int, leg_km numeric, station_km numeric, score int)
language plpgsql stable security definer set search_path = '' as $$
declare st public.stations; cur_lat numeric; cur_lng numeric; remaining uuid[] := oids; n int := 0; pick record;
begin
 select * into st from public.stations where id = sid;
 cur_lat := st.latitude; cur_lng := st.longitude;
 while coalesce(array_length(remaining, 1), 0) > 0 loop
  select o.id, o.address_latitude la, o.address_longitude lo, (sc.score->>'p')::int p,
   round(private.haversine_km(cur_lat, cur_lng, o.address_latitude, o.address_longitude), 6) d
  into pick from public.orders o join public.station_settings cfg on cfg.station_id = o.station_id
  left join public.customer_station_profiles cp on cp.station_id = o.station_id and cp.customer_id = o.customer_id
  cross join lateral private.score(o, cfg, cp.classification = 'institution', t) as sc(score)
  where o.id = any(remaining)
  order by coalesce(n = 0 and o.id = front, false) desc, d, p desc, o.id limit 1;
  n := n + 1;
  order_id := pick.id; seq := n; leg_km := round(pick.d, 3); score := pick.p;
  station_km := round(private.haversine_km(st.latitude, st.longitude, pick.la, pick.lo), 3);
  return next;
  cur_lat := pick.la; cur_lng := pick.lo; remaining := array_remove(remaining, pick.id);
 end loop;
end $$;

create or replace function private.order_step(oid uuid, expected int, key uuid, op text, from_states text[], to_state text, reason text default null) returns jsonb
language plpgsql security definer set search_path = '' as $$
declare o public.orders; r jsonb; payload jsonb := jsonb_build_object('order_id', oid, 'expected_version', expected, 'reason', reason); v int;
begin
 select * into o from public.orders where id = oid;
 if not found or not private.station_admin(o.station_id) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, op, key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(o.station_id);
 select * into o from public.orders where id = oid for update;
 if o.version is distinct from expected then return private.envelope(jsonb_build_object('status', o.status, 'version', o.version), 'stale_version'); end if;
 if not (o.status = any(from_states)) then return private.envelope(jsonb_build_object('status', o.status), 'invalid_transition', jsonb_build_object('order', 'That step is not allowed for this order right now.')); end if;
 v := private.transition(oid, to_state, reason, key);
 return private.remember(o.station_id::text, op, key, payload, private.envelope(jsonb_build_object('order_id', oid, 'status', to_state, 'version', v)));
end $$;

create or replace function private.resolve_reviews(oid uuid, kinds text[], why text) returns void language sql security definer set search_path = '' as $$
 update public.order_reviews set state = 'resolved', resolution = why, resolved_by = auth.uid(), resolved_at = now(), version = version + 1
 where order_id = oid and state = 'open' and kind = any(kinds)
$$;

-- Owner reads -----------------------------------------------------------------------------------------
create or replace function public.get_station_overview(p_station_id uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare start_t timestamptz := private.manila_midnight(now()); av jsonb;
begin
 perform private.owner_check(p_station_id);
 perform private.flag_overdue(p_station_id);
 av := private.availability(p_station_id, null);
 return private.envelope(jsonb_build_object('today', (now() at time zone 'Asia/Manila')::date,
  'counts', (select jsonb_build_object('new', count(*) filter (where status in ('pending','confirmed')), 'on_hold', count(*) filter (where status = 'on_hold'),
   'scheduled', count(*) filter (where status = 'scheduled'), 'preparing', count(*) filter (where status = 'preparing'), 'ready', count(*) filter (where status = 'ready'),
   'out_for_delivery', count(*) filter (where status = 'out_for_delivery'), 'awaiting_completion', count(*) filter (where status in ('delivered','collected')),
   'completed_today', count(*) filter (where status = 'completed' and completed_at >= start_t), 'cancelled_today', count(*) filter (where status = 'cancelled' and cancelled_at >= start_t),
   'orders_today', count(*) filter (where created_at >= start_t)) from public.orders where station_id = p_station_id),
  'cash_collected_today', (select coalesce(sum(applied_centavos), 0) from public.payments where station_id = p_station_id and collected_at >= start_t),
  'cash_verified_today', (select coalesce(sum(applied_centavos), 0) from public.payments where station_id = p_station_id and state = 'verified' and verified_at >= start_t),
  'unverified_cash', (select coalesce(sum(applied_centavos), 0) from public.payments where station_id = p_station_id and state = 'unverified'),
  'overdue', (select count(*) from public.order_reviews where station_id = p_station_id and state = 'open' and kind = 'overdue'),
  'open_reviews', jsonb_array_length(private.open_reviews(p_station_id)),
  'riders_free', (select count(*) from private.free_riders(p_station_id)),
  'riders_total', (select count(*) from public.riders where station_id = p_station_id and enabled),
  'active_trips', (select count(*) from public.delivery_trips where station_id = p_station_id and status = 'active'),
  'availability', jsonb_build_object('label', av->>'label', 'status_key', av->>'status_key', 'accepting_asap', av->'accepting_asap', 'is_open', av->'is_open', 'next_open_at', av->'next_open_at', 'next_close_at', av->'next_close_at'),
  'queue', private.queue_rows(p_station_id, array['pending','confirmed'], null, now()),
  'ready_delivery', private.queue_rows(p_station_id, array['ready'], 'delivery', now())));
end $$;

create or replace function public.get_preparation_queue(p_station_id uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
begin
 perform private.owner_check(p_station_id);
 perform private.flag_overdue(p_station_id);
 return private.envelope(jsonb_build_object('evaluated_at', now(),
  'preparing', private.queue_rows(p_station_id, array['preparing'], null, now()),
  'queue', private.queue_rows(p_station_id, array['pending','confirmed'], null, now()),
  'ready', private.queue_rows(p_station_id, array['ready'], null, now()),
  'on_hold', private.queue_rows(p_station_id, array['on_hold'], null, now()),
  'scheduled', private.queue_rows(p_station_id, array['scheduled'], null, now()),
  'awaiting_completion', private.queue_rows(p_station_id, array['delivered','collected'], null, now()),
  'reviews', private.open_reviews(p_station_id),
  'settings', (select jsonb_build_object('overdue_minutes', overdue_minutes, 'institution_bonus_enabled', institution_bonus_enabled, 'algorithm_version', algorithm_version) from public.station_settings where station_id = p_station_id)));
end $$;

create or replace function public.get_dispatch_queue(p_station_id uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare start_t timestamptz := private.manila_midnight(now());
begin
 perform private.owner_check(p_station_id);
 perform private.flag_overdue(p_station_id);
 return private.envelope(jsonb_build_object(
  'ready_delivery', private.queue_rows(p_station_id, array['ready'], 'delivery', now()),
  'ready_pickup', private.queue_rows(p_station_id, array['ready'], 'pickup', now()),
  'collected', private.queue_rows(p_station_id, array['collected'], 'pickup', now()),
  'delivered', private.queue_rows(p_station_id, array['delivered'], 'delivery', now()),
  'riders', coalesce((select jsonb_agg(jsonb_build_object('id', r.id, 'user_id', r.user_id, 'name', coalesce(d.full_name, 'Rider'), 'phone', d.phone, 'vehicle', r.vehicle,
    'plate_number', r.plate_number, 'capacity', r.capacity, 'enabled', r.enabled, 'version', r.version,
    'free', exists (select 1 from private.free_riders(p_station_id) f where f.rider_id = r.id),
    'trips_today', (select count(*) from public.delivery_trips t where t.station_id = p_station_id and t.rider_user_id = r.user_id and t.dispatched_at >= start_t)) order by coalesce(d.full_name, ''), r.id)
   from public.riders r left join public.drivers d on d.user_id = r.user_id and d.station_id = p_station_id where r.station_id = p_station_id), '[]'),
  'trips', coalesce((select jsonb_agg(jsonb_build_object('id', t.id, 'trip_no', t.trip_no, 'status', t.status, 'rider_name', coalesce(d.full_name, 'Rider'), 'load_units', t.load_units,
    'capacity', t.capacity, 'dispatched_at', t.dispatched_at, 'closed_at', t.closed_at, 'version', t.version,
    'stops', (select count(*) from public.trip_orders s where s.trip_id = t.id), 'resolved', (select count(*) from public.trip_orders s where s.trip_id = t.id and s.state in ('delivered','failed')),
    'barangay', (select min(o.address_barangay) from public.trip_orders s join public.orders o on o.id = s.order_id where s.trip_id = t.id)) order by t.dispatched_at desc)
   from public.delivery_trips t left join public.drivers d on d.user_id = t.rider_user_id and d.station_id = t.station_id
   where t.station_id = p_station_id and (t.status = 'active' or t.dispatched_at >= start_t - interval '6 days')), '[]'),
  'payments', coalesce((select jsonb_agg(jsonb_build_object('id', p.id, 'order_id', p.order_id, 'order_no', o.order_no, 'fulfillment', o.fulfillment, 'customer_name', o.contact_name,
    'applied_centavos', p.applied_centavos, 'tendered_centavos', p.tendered_centavos, 'change_centavos', p.change_centavos, 'expected_empties', p.expected_empties,
    'empties_returned', p.empties_returned, 'collected_at', p.collected_at, 'version', p.version) order by p.collected_at)
   from public.payments p join public.orders o on o.id = p.order_id where p.station_id = p_station_id and p.state = 'unverified'), '[]'),
  'reviews', private.open_reviews(p_station_id)));
end $$;

create or replace function public.get_station_order(p_order_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare o public.orders; cfg public.station_settings; inst boolean;
begin
 select * into o from public.orders where id = p_order_id;
 if not found then return private.envelope('{}', 'not_found'); end if;
 perform private.owner_check(o.station_id);
 select * into cfg from public.station_settings where station_id = o.station_id;
 select classification = 'institution' into inst from public.customer_station_profiles where station_id = o.station_id and customer_id = o.customer_id;
 return private.envelope(jsonb_build_object(
  'order', to_jsonb(o) - array['request_key','fingerprint'], 'score', private.score(o, cfg, inst, now()),
  'items', coalesce((select jsonb_agg(jsonb_build_object('name', i.product_name, 'pack_text', i.pack_text, 'quantity', i.quantity, 'unit_price_centavos', i.unit_price_centavos,
   'line_total_centavos', i.line_total_centavos, 'returnable', i.returnable) order by i.product_name, i.id) from public.order_items i where i.order_id = o.id), '[]'),
  'timeline', coalesce((select jsonb_agg(jsonb_build_object('from', l.from_status, 'to', l.to_status, 'reason', l.reason, 'at', l.created_at, 'job', l.system_job) order by l.created_at, l.id)
   from public.order_status_logs l where l.order_id = o.id), '[]'),
  'stops', coalesce((select jsonb_agg(jsonb_build_object('trip_id', s.trip_id, 'trip_no', t.trip_no, 'sequence', s.sequence, 'state', s.state, 'active', s.active,
   'attempt', (select to_jsonb(a) - array['station_id'] from public.delivery_attempts a where a.stop_id = s.id)) order by s.created_at)
   from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id where s.order_id = o.id), '[]'),
  'payment', (select to_jsonb(p) - array['station_id','request_key'] from public.payments p where p.order_id = o.id),
  'reviews', coalesce((select jsonb_agg(jsonb_build_object('id', r.id, 'kind', r.kind, 'state', r.state, 'detail', r.detail, 'resolution', r.resolution, 'version', r.version, 'created_at', r.created_at) order by r.created_at)
   from public.order_reviews r where r.order_id = o.id), '[]'),
  'classification', coalesce((select classification from public.customer_station_profiles where station_id = o.station_id and customer_id = o.customer_id), 'household'),
  'driver', (select jsonb_build_object('name', d.full_name, 'phone', d.phone) from public.drivers d where d.user_id = o.driver_id)));
end $$;

create or replace function public.get_station_customers(p_station_id uuid, p_filters jsonb, p_cursor jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare rows jsonb; term text := lower(trim(coalesce(p_filters->>'search', '')));
begin
 perform private.owner_check(p_station_id);
 select coalesce(jsonb_agg(c order by c->>'last_order_at' desc), '[]') into rows from (
  select jsonb_build_object('customer_id', o.customer_id, 'name', (array_agg(o.contact_name order by o.created_at desc))[1],
   'phone', (array_agg(o.contact_phone order by o.created_at desc))[1], 'barangay', (array_agg(o.address_barangay order by o.created_at desc) filter (where o.address_barangay is not null))[1],
   'orders', count(*), 'completed', count(*) filter (where o.status = 'completed'), 'last_order_at', max(o.created_at),
   'total_spent_centavos', coalesce(sum(o.total_centavos) filter (where o.status = 'completed'), 0),
   'classification', coalesce(max(cp.classification), 'household'), 'version', max(cp.version)) c
  from public.orders o left join public.customer_station_profiles cp on cp.station_id = o.station_id and cp.customer_id = o.customer_id
  where o.station_id = p_station_id group by o.customer_id
  having term = '' or lower(coalesce((array_agg(o.contact_name order by o.created_at desc))[1], '') || ' ' || coalesce((array_agg(o.contact_phone order by o.created_at desc))[1], '')) like '%' || term || '%'
  limit 500) x;
 return private.envelope(jsonb_build_object('customers', rows));
end $$;

-- Owner actions ---------------------------------------------------------------------------------------
create or replace function public.start_preparation(p_order_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
begin return private.order_step(p_order_id, p_expected_version, p_request_key, 'start_preparation', array['pending','confirmed'], 'preparing'); end $$;

create or replace function public.mark_order_ready(p_order_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
begin return private.order_step(p_order_id, p_expected_version, p_request_key, 'mark_order_ready', array['preparing'], 'ready'); end $$;

-- Replaces the Phase 4 version: adds the owner's ready-order exception (D9) with no active trip and no payment.
create or replace function public.cancel_order(p_order_id uuid, p_expected_version int, p_reason text, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'expected_version', p_expected_version, 'reason', p_reason);
 o public.orders; is_customer boolean; is_owner boolean; v int;
begin
 if auth.uid() is null or private.has_role('super_admin') then raise exception 'Order access denied.' using errcode = '42501'; end if;
 select * into o from public.orders where id = p_order_id;
 is_customer := found and o.customer_id = auth.uid() and private.has_role('customer');
 is_owner := found and private.station_admin(o.station_id);
 if not coalesce(is_customer or is_owner, false) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'cancel_order', p_request_key, payload);
 if r is not null then return r; end if;
 if is_owner then perform private.owner_guard(o.station_id); end if;
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('status', o.status, 'version', o.version), 'stale_version'); end if;
 if length(trim(coalesce(p_reason, ''))) not between 1 and 500 then return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Give a reason for cancelling.')); end if;
 if not ((is_customer and o.status in ('pending','on_hold','scheduled','confirmed'))
   or (is_owner and (o.status in ('pending','on_hold','scheduled','confirmed','preparing')
    or (o.status = 'ready' and not exists (select 1 from public.trip_orders s where s.order_id = o.id and s.active) and not exists (select 1 from public.payments p where p.order_id = o.id))))) then
  return private.envelope(jsonb_build_object('status', o.status), 'invalid_transition', jsonb_build_object('order', 'This order can no longer be cancelled.'));
 end if;
 v := private.transition(p_order_id, 'cancelled', trim(p_reason), p_request_key);
 update public.orders set redispatch_paused = false where id = p_order_id;
 perform private.resolve_reviews(p_order_id, array['overdue','failed_attempt','capacity'], 'Order cancelled');
 if is_customer and not is_owner and o.status <> 'on_hold' then
  perform private.notify_station(o.station_id, 'order:' || p_order_id || ':' || v, 'order_cancelled', p_order_id, 'Order ' || o.order_no || ' was cancelled', 'Customer reason: ' || trim(p_reason));
 end if;
 return private.remember(o.station_id::text, 'cancel_order', p_request_key, payload, private.envelope(jsonb_build_object('order_id', p_order_id, 'status', 'cancelled', 'version', v)));
end $$;

create or replace function public.preview_trip(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare t timestamptz := now(); c record; anchor record; rider record; anchored boolean := false; load int := 0; ids uuid[] := '{}'; warnings jsonb := '[]'; stops jsonb; av jsonb;
begin
 perform private.owner_check(p_station_id);
 av := private.availability(p_station_id, t);
 if not coalesce((av->>'is_open')::boolean, false) then
  return private.envelope(jsonb_build_object('proposal', null, 'warnings', '[]'::jsonb, 'message', 'The station is closed, so trips cannot be dispatched now.'));
 end if;
 for c in select * from private.dispatch_candidates(p_station_id, t) loop
  select * into rider from private.free_riders(p_station_id) f where f.capacity >= c.load order by f.trips_today, f.rider_id limit 1;
  if found then anchor := c; anchored := true; exit; end if;
  warnings := warnings || jsonb_build_object('order_id', c.order_id, 'order_no', c.order_no, 'load', c.load,
   'message', case when exists (select 1 from private.free_riders(p_station_id)) then 'No free vehicle can carry this whole order.' else 'No rider is free right now.' end);
 end loop;
 if not anchored then return private.envelope(jsonb_build_object('proposal', null, 'warnings', warnings)); end if;
 for c in select * from private.dispatch_candidates(p_station_id, t) loop
  if c.barangay_id is not distinct from anchor.barangay_id and load + c.load <= rider.capacity then ids := ids || c.order_id; load := load + c.load; end if;
 end loop;
 select coalesce(jsonb_agg(jsonb_build_object('order_id', s.order_id, 'sequence', s.seq, 'leg_km', s.leg_km, 'station_km', s.station_km, 'score', s.score,
   'order_no', d.order_no, 'customer_name', d.customer_name, 'barangay', d.barangay, 'load', d.load, 'overdue', d.overdue) order by s.seq), '[]')
 into stops from private.sstf(p_station_id, ids, null, t) s join private.dispatch_candidates(p_station_id, t) d on d.order_id = s.order_id;
 return private.envelope(jsonb_build_object('proposal', jsonb_build_object('rider_id', rider.rider_id, 'rider_name', rider.name, 'capacity', rider.capacity, 'trips_today', rider.trips_today,
   'load', load, 'barangay', anchor.barangay, 'stops', stops, 'planned_km', (select coalesce(sum((x->>'leg_km')::numeric), 0) from jsonb_array_elements(stops) x)),
  'other_riders', coalesce((select jsonb_agg(jsonb_build_object('rider_id', f.rider_id, 'name', f.name, 'capacity', f.capacity, 'trips_today', f.trips_today)) from private.free_riders(p_station_id) f where f.rider_id <> rider.rider_id), '[]'),
  'eligible', coalesce((select jsonb_agg(jsonb_build_object('order_id', d.order_id, 'order_no', d.order_no, 'customer_name', d.customer_name, 'barangay', d.barangay, 'load', d.load, 'score', d.score, 'overdue', d.overdue)) from private.dispatch_candidates(p_station_id, t) d), '[]'),
  'warnings', warnings));
end $$;

create or replace function public.dispatch_trip(p_station_id uuid, p_rider_id uuid, p_order_ids uuid[], p_request_key uuid, p_proposal jsonb) returns jsonb language plpgsql security definer set search_path = '' as $$
declare ids uuid[]; payload jsonb; r jsonb; rd public.riders; t timestamptz := now(); total int; front uuid; front_reason text; n bigint; trip uuid; s record; tno text; v int;
begin
 perform private.owner_check(p_station_id);
 select array_agg(distinct x order by x) into ids from unnest(p_order_ids) x where x is not null;
 payload := jsonb_build_object('rider_id', p_rider_id, 'order_ids', to_jsonb(ids), 'proposal', coalesce(p_proposal, '{}'::jsonb));
 r := private.replay(p_station_id::text, 'dispatch_trip', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(p_station_id);
 perform 1 from public.stations where id = p_station_id for share;
 perform 1 from public.station_availability where station_id = p_station_id for share;
 if not coalesce((private.availability(p_station_id, t)->>'is_open')::boolean, false) then return private.envelope('{}', 'station_closed', jsonb_build_object('trip', 'The station is closed, so trips cannot be dispatched now.')); end if;
 select * into rd from public.riders where id = p_rider_id and station_id = p_station_id for update;
 if not found then raise exception 'Rider access denied.' using errcode = '42501'; end if;
 perform pg_advisory_xact_lock(hashtextextended('rider:' || rd.user_id::text, 0));
 if not exists (select 1 from private.free_riders(p_station_id) f where f.rider_id = rd.id) then return private.envelope('{}', 'rider_busy', jsonb_build_object('rider_id', 'This rider is not free.')); end if;
 if coalesce(array_length(ids, 1), 0) = 0 then return private.envelope('{}', 'validation', jsonb_build_object('order_ids', 'Choose at least one ready order.')); end if;
 perform 1 from public.orders where id = any(ids) order by id for update;
 if (select count(*) from private.dispatch_candidates(p_station_id, t) d where d.order_id = any(ids)) <> array_length(ids, 1) then
  return private.envelope('{}', 'order_not_eligible', jsonb_build_object('order_ids', 'An order is no longer ready for dispatch. Refresh the proposal.'));
 end if;
 if (select count(distinct coalesce(address_barangay_id::text, '-')) from public.orders where id = any(ids)) > 1 then
  return private.envelope('{}', 'validation', jsonb_build_object('order_ids', 'A trip serves one barangay. Remove orders from other barangays.'));
 end if;
 select sum(load_units) into total from public.orders where id = any(ids);
 if total > rd.capacity then return private.envelope('{}', 'over_capacity', jsonb_build_object('order_ids', format('This load (%s) is more than the vehicle carries (%s).', total, rd.capacity))); end if;
 if p_proposal ? 'front_order_id' and coalesce(p_proposal->>'front_order_id', '') <> '' then
  front := (p_proposal->>'front_order_id')::uuid; front_reason := trim(coalesce(p_proposal->>'front_reason', ''));
  if not (front = any(ids)) or not coalesce((select d.overdue from private.dispatch_candidates(p_station_id, t) d where d.order_id = front), false) or length(front_reason) not between 3 and 300 then
   return private.envelope('{}', 'validation', jsonb_build_object('front_order_id', 'Only an overdue order in this trip can go first, and it needs a reason.'));
  end if;
 end if;
 insert into private.station_counters as c(station_id, next_trip_number) values (p_station_id, 2)
 on conflict (station_id) do update set next_trip_number = c.next_trip_number + 1 returning c.next_trip_number - 1 into n;
 insert into public.delivery_trips(station_id, trip_no, rider_id, rider_user_id, capacity, load_units, dispatched_by, request_key)
 values (p_station_id, n, rd.id, rd.user_id, rd.capacity, total, auth.uid(), p_request_key) returning id into trip;
 for s in select * from private.sstf(p_station_id, ids, front, t) loop
  insert into public.trip_orders(station_id, trip_id, order_id, sequence, leg_km, station_km, priority_score, manual_front_reason)
  values (p_station_id, trip, s.order_id, s.seq, s.leg_km, s.station_km, s.score, case when s.order_id = front then front_reason end);
  update public.orders set driver_id = rd.user_id where id = s.order_id;
  v := private.transition(s.order_id, 'out_for_delivery', 'Trip ' || n, p_request_key);
 end loop;
 update public.delivery_trips set planned_km = (select sum(leg_km) from public.trip_orders where trip_id = trip) where id = trip;
 insert into private.rider_reservations(user_id, station_id, trip_id) values (rd.user_id, p_station_id, trip);
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, after_values)
 values (p_station_id, auth.uid(), 'dispatch_trip', trip, p_request_key, jsonb_build_object('trip_no', n, 'orders', to_jsonb(ids), 'front_order_id', front));
 return private.remember(p_station_id::text, 'dispatch_trip', p_request_key, payload, private.envelope(jsonb_build_object('trip_id', trip, 'trip_no', n, 'stops', array_length(ids, 1))));
end $$;

create or replace function public.record_pickup_collection(p_order_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare o public.orders; r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'payload', p_payload, 'expected_version', p_expected_version);
 tendered bigint; empties int; v int; pid uuid;
begin
 select * into o from public.orders where id = p_order_id;
 if not found or not private.station_admin(o.station_id) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'record_pickup_collection', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(o.station_id);
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', o.version), 'stale_version'); end if;
 if o.fulfillment <> 'pickup' or o.status <> 'ready' then return private.envelope('{}', 'invalid_transition', jsonb_build_object('order', 'Only a ready pickup order can be collected.')); end if;
 if coalesce(p_payload->>'tendered_centavos', '') !~ '^[0-9]{1,9}$' or coalesce(p_payload->>'empties_returned', '') !~ '^[0-9]{1,4}$' then
  return private.envelope('{}', 'validation', jsonb_build_object('tendered_centavos', 'Enter the cash received and the empty containers returned.'));
 end if;
 tendered := (p_payload->>'tendered_centavos')::bigint; empties := (p_payload->>'empties_returned')::int;
 if tendered < o.total_centavos then return private.envelope('{}', 'validation', jsonb_build_object('tendered_centavos', 'The customer must pay the full amount of ' || to_char(o.total_centavos / 100.0, 'FM999,990.00') || ' pesos.')); end if;
 insert into public.payments(station_id, order_id, applied_centavos, tendered_centavos, change_centavos, expected_empties, empties_returned, collected_by, request_key)
 values (o.station_id, o.id, o.total_centavos, tendered, tendered - o.total_centavos, coalesce(o.expected_empties, 0), empties, auth.uid(), p_request_key) returning id into pid;
 v := private.transition(o.id, 'collected', null, p_request_key);
 if empties <> coalesce(o.expected_empties, 0) then
  insert into public.order_reviews(station_id, order_id, kind, detail) values (o.station_id, o.id, 'empties_mismatch', format('Expected %s empty containers, received %s.', coalesce(o.expected_empties, 0), empties)) on conflict do nothing;
 end if;
 return private.remember(o.station_id::text, 'record_pickup_collection', p_request_key, payload, private.envelope(jsonb_build_object('order_id', o.id, 'payment_id', pid, 'status', 'collected', 'version', v, 'change_centavos', tendered - o.total_centavos)));
end $$;

create or replace function public.verify_payment(p_payment_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare p public.payments; r jsonb; payload jsonb := jsonb_build_object('payment_id', p_payment_id, 'payload', p_payload, 'expected_version', p_expected_version); v int;
begin
 select * into p from public.payments where id = p_payment_id;
 if not found or not private.station_admin(p.station_id) then raise exception 'Payment access denied.' using errcode = '42501'; end if;
 r := private.replay(p.station_id::text, 'verify_payment', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(p.station_id);
 select * into p from public.payments where id = p_payment_id for update;
 if p.version is distinct from p_expected_version or p.state <> 'unverified' then return private.envelope(jsonb_build_object('state', p.state, 'version', p.version), 'stale_version'); end if;
 if length(coalesce(p_payload->>'note', '')) > 300 then return private.envelope('{}', 'validation', jsonb_build_object('note', 'Keep the note to 300 characters.')); end if;
 update public.payments set state = 'verified', verified_by = auth.uid(), verified_at = now(), verify_note = nullif(trim(coalesce(p_payload->>'note', '')), ''), version = version + 1
 where id = p_payment_id returning version into v;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, after_values)
 values (p.station_id, auth.uid(), 'verify_payment', p.id, p_request_key, jsonb_build_object('applied_centavos', p.applied_centavos, 'empties_returned', p.empties_returned));
 return private.remember(p.station_id::text, 'verify_payment', p_request_key, payload, private.envelope(jsonb_build_object('payment_id', p.id, 'state', 'verified', 'version', v)));
end $$;

-- D2: a higher measured fee never becomes a surcharge; the owner records why the quoted fee stands.
create or replace function public.review_delivery_fee(p_order_id uuid, p_decision text, p_reason text, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare o public.orders; r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'decision', p_decision, 'reason', p_reason, 'expected_version', p_expected_version);
begin
 select * into o from public.orders where id = p_order_id;
 if not found or not private.station_admin(o.station_id) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'review_delivery_fee', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(o.station_id);
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', o.version), 'stale_version'); end if;
 if p_decision is distinct from 'keep_quote' or length(trim(coalesce(p_reason, ''))) not between 3 and 500 then
  return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Explain why the quoted fee stands (for example, the pin was misplaced).'));
 end if;
 if not exists (select 1 from public.order_reviews where order_id = o.id and kind = 'fee_mismatch' and state = 'open') then
  return private.envelope('{}', 'invalid_transition', jsonb_build_object('order', 'There is no open fee review for this order.'));
 end if;
 perform private.resolve_reviews(o.id, array['fee_mismatch'], trim(p_reason));
 return private.remember(o.station_id::text, 'review_delivery_fee', p_request_key, payload, private.envelope(jsonb_build_object('order_id', o.id, 'version', o.version)));
end $$;

create or replace function public.complete_order(p_order_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare o public.orders; r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'expected_version', p_expected_version); v int;
begin
 select * into o from public.orders where id = p_order_id;
 if not found or not private.station_admin(o.station_id) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'complete_order', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(o.station_id);
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', o.version), 'stale_version'); end if;
 if o.status not in ('delivered','collected') then return private.envelope('{}', 'invalid_transition', jsonb_build_object('order', 'Only delivered or picked-up orders can be completed.')); end if;
 if not exists (select 1 from public.payments p where p.order_id = o.id and p.state = 'verified') then
  return private.envelope('{}', 'payment_unverified', jsonb_build_object('order', 'Verify the cash for this order first.'));
 end if;
 if exists (select 1 from public.order_reviews where order_id = o.id and state = 'open' and kind in ('fee_mismatch','empties_mismatch')) then
  return private.envelope('{}', 'review_open', jsonb_build_object('order', 'Resolve the open fee or empty-container review first.'));
 end if;
 v := private.transition(o.id, 'completed', null, p_request_key);
 perform private.resolve_reviews(o.id, array['overdue'], 'Order completed');
 return private.remember(o.station_id::text, 'complete_order', p_request_key, payload, private.envelope(jsonb_build_object('order_id', o.id, 'status', 'completed', 'version', v)));
end $$;

create or replace function public.approve_reattempt(p_order_id uuid, p_expected_version int, p_reason text, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare o public.orders; r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'expected_version', p_expected_version, 'reason', p_reason); v int;
begin
 select * into o from public.orders where id = p_order_id;
 if not found or not private.station_admin(o.station_id) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'approve_reattempt', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(o.station_id);
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', o.version), 'stale_version'); end if;
 if o.status <> 'ready' or not o.redispatch_paused then return private.envelope('{}', 'invalid_transition', jsonb_build_object('order', 'This order is not waiting for a reattempt decision.')); end if;
 if length(trim(coalesce(p_reason, ''))) not between 3 and 500 then return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Say why another attempt will work (for example, the customer confirmed they are home).')); end if;
 update public.orders set redispatch_paused = false, queued_at = now(), version = version + 1, updated_at = now() where id = o.id returning version into v;
 perform private.resolve_reviews(o.id, array['failed_attempt'], trim(p_reason));
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, reason, request_key) values (o.station_id, auth.uid(), 'approve_reattempt', o.id, trim(p_reason), p_request_key);
 return private.remember(o.station_id::text, 'approve_reattempt', p_request_key, payload, private.envelope(jsonb_build_object('order_id', o.id, 'version', v)));
end $$;

create or replace function public.resolve_order_review(p_review_id uuid, p_action text, p_reason text, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rv public.order_reviews; sid uuid; r jsonb; payload jsonb := jsonb_build_object('review_id', p_review_id, 'action', p_action, 'reason', p_reason, 'expected_version', p_expected_version);
begin
 select station_id into sid from public.order_reviews where id = p_review_id;
 if sid is null then select station_id into sid from public.order_review_flags where id = p_review_id; end if;
 if sid is null or not private.station_admin(sid) then raise exception 'Review access denied.' using errcode = '42501'; end if;
 r := private.replay(sid::text, 'resolve_order_review', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(sid);
 if p_action is distinct from 'acknowledge' or length(trim(coalesce(p_reason, ''))) not between 3 and 500 then
  return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Write what you did about it.'));
 end if;
 select * into rv from public.order_reviews where id = p_review_id for update;
 if found then
  if rv.state <> 'open' or rv.version is distinct from p_expected_version then return private.envelope('{}', 'stale_version'); end if;
  if rv.kind = 'failed_attempt' then return private.envelope('{}', 'invalid_transition', jsonb_build_object('review', 'Approve a reattempt or cancel the order instead.')); end if;
  update public.order_reviews set state = 'resolved', resolution = trim(p_reason), resolved_by = auth.uid(), resolved_at = now(), version = version + 1 where id = p_review_id;
 else
  update public.order_review_flags set resolved_at = now() where id = p_review_id and resolved_at is null;
  if not found then return private.envelope('{}', 'stale_version'); end if;
 end if;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, reason, request_key) values (sid, auth.uid(), 'resolve_order_review', p_review_id, trim(p_reason), p_request_key);
 return private.remember(sid::text, 'resolve_order_review', p_request_key, payload, private.envelope(jsonb_build_object('review_id', p_review_id, 'state', 'resolved')));
end $$;

create or replace function public.set_customer_classification(p_station_id uuid, p_customer_id uuid, p_classification text, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('customer_id', p_customer_id, 'classification', p_classification, 'expected_version', p_expected_version); cur public.customer_station_profiles; v int;
begin
 perform private.owner_check(p_station_id);
 r := private.replay(p_station_id::text, 'set_customer_classification', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(p_station_id);
 if p_classification not in ('household','institution') then return private.envelope('{}', 'validation', jsonb_build_object('classification', 'Choose household or institution.')); end if;
 if not exists (select 1 from public.orders where station_id = p_station_id and customer_id = p_customer_id) then raise exception 'Customer access denied.' using errcode = '42501'; end if;
 select * into cur from public.customer_station_profiles where station_id = p_station_id and customer_id = p_customer_id for update;
 if coalesce(cur.version, 0) is distinct from coalesce(p_expected_version, 0) then return private.envelope('{}', 'stale_version'); end if;
 insert into public.customer_station_profiles as c(station_id, customer_id, classification, actor_id) values (p_station_id, p_customer_id, p_classification, auth.uid())
 on conflict (station_id, customer_id) do update set classification = excluded.classification, actor_id = auth.uid(), updated_at = now(), version = c.version + 1 returning c.version into v;
 return private.remember(p_station_id::text, 'set_customer_classification', p_request_key, payload, private.envelope(jsonb_build_object('customer_id', p_customer_id, 'classification', p_classification, 'version', v)));
end $$;

create or replace function public.save_rider(p_rider_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rd public.riders; r jsonb; payload jsonb := jsonb_build_object('rider_id', p_rider_id, 'payload', p_payload, 'expected_version', p_expected_version); v int;
begin
 select * into rd from public.riders where id = p_rider_id;
 if not found or not private.station_admin(rd.station_id) then raise exception 'Rider access denied.' using errcode = '42501'; end if;
 r := private.replay(rd.station_id::text, 'save_rider', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(rd.station_id);
 select * into rd from public.riders where id = p_rider_id for update;
 if rd.version is distinct from p_expected_version then return private.envelope('{}', 'stale_version'); end if;
 if coalesce(p_payload->>'vehicle', '') not in ('Motorcycle with sidecar','Tricycle','Delivery van') or length(trim(coalesce(p_payload->>'plate_number', ''))) not between 2 and 20
   or coalesce(p_payload->>'capacity', '') !~ '^[0-9]{1,3}$' or (p_payload->>'capacity')::int not between 1 and 100 or coalesce(p_payload->>'enabled', '') not in ('true','false') then
  return private.envelope('{}', 'validation', jsonb_build_object('rider', 'Check the vehicle, plate number and capacity (1 to 100).'));
 end if;
 update public.riders set vehicle = p_payload->>'vehicle', plate_number = upper(trim(p_payload->>'plate_number')), capacity = (p_payload->>'capacity')::int,
  enabled = (p_payload->>'enabled')::boolean, version = version + 1 where id = rd.id returning version into v;
 update public.drivers set vehicle = p_payload->>'vehicle', plate_number = upper(trim(p_payload->>'plate_number')), capacity = (p_payload->>'capacity')::int, active = (p_payload->>'enabled')::boolean
 where user_id = rd.user_id and station_id = rd.station_id;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, before_values, after_values)
 values (rd.station_id, auth.uid(), 'save_rider', rd.id, p_request_key, jsonb_build_object('capacity', rd.capacity, 'enabled', rd.enabled), p_payload);
 return private.remember(rd.station_id::text, 'save_rider', p_request_key, payload, private.envelope(jsonb_build_object('rider_id', rd.id, 'version', v)));
end $$;

-- Rider -----------------------------------------------------------------------------------------------
create or replace function private.assigned_trip(tid uuid) returns public.delivery_trips language plpgsql stable security definer set search_path = '' as $$
declare tr public.delivery_trips;
begin
 perform private.rider_guard();
 select * into tr from public.delivery_trips where id = tid and rider_user_id = auth.uid() and status = 'active';
 if not found or not exists (select 1 from private.station_membership_roles mr join private.station_memberships m on m.station_id = mr.station_id and m.user_id = mr.user_id and m.active
   where mr.station_id = tr.station_id and mr.user_id = auth.uid() and mr.role = 'rider' and mr.active) then
  raise exception 'Trip access denied.' using errcode = '42501';
 end if;
 return tr;
end $$;

create or replace function public.get_trip(p_trip_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare tr public.delivery_trips; owner boolean;
begin
 if auth.uid() is null or private.has_role('super_admin') then raise exception 'Trip access denied.' using errcode = '42501'; end if;
 if p_trip_id is null then
  perform private.rider_guard();
  select * into tr from public.delivery_trips where rider_user_id = auth.uid() and status = 'active' limit 1;
  if not found then return private.envelope(jsonb_build_object('trip', null)); end if;
 else
  select * into tr from public.delivery_trips where id = p_trip_id;
  if not found then raise exception 'Trip access denied.' using errcode = '42501'; end if;
 end if;
 owner := private.station_admin(tr.station_id);
 if not owner and not (tr.rider_user_id = auth.uid() and private.has_role('rider')) then raise exception 'Trip access denied.' using errcode = '42501'; end if;
 return private.envelope(jsonb_build_object(
  'trip', to_jsonb(tr) - array['request_key','dispatched_by','closed_by'] || jsonb_build_object('rider_name', (select full_name from public.drivers d where d.user_id = tr.rider_user_id and d.station_id = tr.station_id)),
  'station', (select jsonb_build_object('name', s.name, 'phone', s.phone, 'latitude', s.latitude, 'longitude', s.longitude) from public.stations s where s.id = tr.station_id),
  'stops', coalesce((select jsonb_agg(jsonb_build_object('id', s.id, 'sequence', s.sequence, 'state', s.state, 'active', s.active, 'version', s.version, 'leg_km', s.leg_km,
    'station_km', s.station_km, 'manual_front_reason', s.manual_front_reason,
    'order', jsonb_build_object('id', o.id, 'order_no', o.order_no, 'status', o.status, 'customer_name', o.contact_name, 'contact_phone', o.contact_phone,
     'address_label', o.address_label, 'address_text', o.address_text, 'landmark', o.address_landmark, 'barangay', o.address_barangay,
     'latitude', o.address_latitude, 'longitude', o.address_longitude, 'total_units', o.total_units, 'expected_empties', o.expected_empties, 'note', o.note,
     'quoted_fee_centavos', o.quoted_fee_centavos, 'fee_centavos', coalesce(o.final_fee_centavos, o.quoted_fee_centavos), 'amount_due_centavos', o.total_centavos,
     'items', (select jsonb_agg(jsonb_build_object('name', i.product_name, 'quantity', i.quantity) order by i.product_name) from public.order_items i where i.order_id = o.id)),
    'attempt', (select jsonb_build_object('id', a.id, 'state', a.state, 'fee_decision', a.fee_decision, 'amount_due_centavos', a.amount_due_centavos, 'measured_km', a.measured_km,
     'failure_reason', a.failure_reason, 'empties_returned', a.empties_returned, 'arrived_at', a.arrived_at, 'resolved_at', a.resolved_at) from public.delivery_attempts a where a.stop_id = s.id),
    'payment', (select jsonb_build_object('tendered_centavos', p.tendered_centavos, 'change_centavos', p.change_centavos) from public.payments p join public.delivery_attempts a on a.id = p.attempt_id where a.stop_id = s.id)
   ) order by s.sequence) from public.trip_orders s join public.orders o on o.id = s.order_id where s.trip_id = tr.id), '[]'),
  'last_location', case when owner then (select jsonb_build_object('latitude', l.latitude, 'longitude', l.longitude, 'accuracy', l.accuracy, 'recorded_at', l.recorded_at)
   from public.rider_locations l where l.trip_id = tr.id order by l.recorded_at desc, l.id desc limit 1) end));
end $$;

create or replace function public.list_rider_history(p_cursor jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare rows jsonb;
begin
 perform private.rider_guard();
 select coalesce(jsonb_agg(x.j order by x.at desc), '[]') into rows from (
  select coalesce(a.resolved_at, s.created_at) at, jsonb_build_object('order_no', o.order_no, 'customer_name', o.contact_name, 'barangay', o.address_barangay, 'state', s.state,
   'trip_no', t.trip_no, 'station_name', st.name, 'total_units', o.total_units, 'empties_returned', a.empties_returned, 'failure_reason', a.failure_reason,
   'resolved_at', a.resolved_at) j
  from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id join public.orders o on o.id = s.order_id join public.stations st on st.id = t.station_id
  left join public.delivery_attempts a on a.stop_id = s.id
  where t.rider_user_id = auth.uid() and s.state in ('delivered','failed') order by coalesce(a.resolved_at, s.created_at) desc limit 100) x;
 return private.envelope(jsonb_build_object('stops', rows));
end $$;

create or replace function public.record_rider_location(p_trip_id uuid, p_latitude numeric, p_longitude numeric, p_accuracy numeric, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare tr public.delivery_trips;
begin
 tr := private.assigned_trip(p_trip_id);
 if p_latitude is null or p_longitude is null or p_accuracy is null or p_latitude not between -90 and 90 or p_longitude not between -180 and 180 or p_accuracy < 0 or p_accuracy > 100000 then
  return private.envelope('{}', 'validation', jsonb_build_object('location', 'Invalid position.'));
 end if;
 insert into public.rider_locations(station_id, trip_id, rider_user_id, latitude, longitude, accuracy, request_key)
 values (tr.station_id, tr.id, auth.uid(), p_latitude, p_longitude, p_accuracy, p_request_key) on conflict do nothing;
 return private.envelope(jsonb_build_object('trip_id', tr.id));
end $$;

-- M11 and D2: measure on arrival; lower automatically, flag a higher fee for review, keep the quote without good GPS.
create or replace function public.mark_arrived(p_stop_id uuid, p_latitude numeric, p_longitude numeric, p_accuracy numeric, p_request_key uuid, p_fallback_reason text) returns jsonb language plpgsql security definer set search_path = '' as $$
declare s public.trip_orders; tr public.delivery_trips; o public.orders; r jsonb; payload jsonb; cur int; km numeric; mfee int; decision text; why text; aid uuid;
begin
 select * into s from public.trip_orders where id = p_stop_id;
 if not found then raise exception 'Stop access denied.' using errcode = '42501'; end if;
 tr := private.assigned_trip(s.trip_id);
 payload := jsonb_build_object('stop_id', p_stop_id, 'latitude', p_latitude, 'longitude', p_longitude, 'accuracy', p_accuracy, 'fallback_reason', p_fallback_reason);
 r := private.replay(tr.station_id::text, 'mark_arrived', p_request_key, payload);
 if r is not null then return r; end if;
 select * into s from public.trip_orders where id = p_stop_id for update;
 select * into o from public.orders where id = s.order_id for update;
 if s.state <> 'pending' or not s.active then return private.envelope(jsonb_build_object('state', s.state), 'invalid_transition', jsonb_build_object('stop', 'This stop is already recorded.')); end if;
 cur := coalesce(o.final_fee_centavos, o.quoted_fee_centavos);
 if p_latitude is null or p_longitude is null then
  why := trim(coalesce(p_fallback_reason, ''));
  if length(why) not between 3 and 300 then return private.envelope('{}', 'validation', jsonb_build_object('fallback_reason', 'Location is unavailable. Say why, for example "GPS permission denied".')); end if;
  decision := 'fallback';
 elsif p_latitude not between -90 and 90 or p_longitude not between -180 and 180 or p_accuracy is null or p_accuracy < 0 then
  return private.envelope('{}', 'validation', jsonb_build_object('location', 'Invalid position.'));
 elsif p_accuracy > 100 then
  decision := 'fallback'; why := 'Location accuracy worse than 100 m';
 else
  km := private.haversine_km(o.station_latitude, o.station_longitude, p_latitude, p_longitude);
  mfee := private.fee_for(o.station_id, o.fee_version, km);
  decision := case when mfee is null or mfee = cur then 'unchanged' when mfee < cur then 'lowered' else 'flagged' end;
 end if;
 if decision = 'lowered' then
  update public.orders set final_fee_centavos = mfee, total_centavos = subtotal_centavos + mfee, version = version + 1, updated_at = now() where id = o.id;
  insert into public.station_audit_events(station_id, system_job, action, entity_id, request_key, before_values, after_values)
  values (o.station_id, 'arrival_fee_check', 'lower_delivery_fee', o.id, p_request_key, jsonb_build_object('fee_centavos', cur), jsonb_build_object('fee_centavos', mfee, 'measured_km', round(km, 3)));
  perform private.notify(o.station_id, o.customer_id, 'order:' || o.id || ':fee', 'order_fee_lowered', o.id, 'Your delivery fee was lowered',
   'The rider measured a shorter distance, so order ' || o.order_no || ' now costs ' || to_char((o.subtotal_centavos + mfee) / 100.0, 'FM999,990.00') || ' pesos.');
 elsif decision = 'flagged' then
  insert into public.order_reviews(station_id, order_id, kind, detail)
  values (o.station_id, o.id, 'fee_mismatch', format('Measured %s km (fee %s) is above the quoted fee %s. The customer pays the quote.', round(km, 1), mfee / 100, cur / 100)) on conflict do nothing;
 end if;
 insert into public.delivery_attempts(station_id, stop_id, order_id, arrival_latitude, arrival_longitude, arrival_accuracy, fallback_reason, measured_km, measured_fee_centavos,
  fee_decision, amount_due_centavos, actor_id)
 values (o.station_id, s.id, o.id, p_latitude, p_longitude, p_accuracy, why, km, mfee, decision, o.subtotal_centavos + case when decision = 'lowered' then mfee else cur end, auth.uid())
 returning id into aid;
 update public.trip_orders set state = 'arrived', version = version + 1 where id = s.id;
 return private.remember(tr.station_id::text, 'mark_arrived', p_request_key, payload, private.envelope(jsonb_build_object('stop_id', s.id, 'attempt_id', aid, 'fee_decision', decision,
  'amount_due_centavos', o.subtotal_centavos + case when decision = 'lowered' then mfee else cur end, 'measured_km', round(km, 2))));
end $$;

create or replace function public.record_delivery(p_payload jsonb, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare s public.trip_orders; tr public.delivery_trips; o public.orders; a public.delivery_attempts; r jsonb; tendered bigint; empties int; why text; v int;
begin
 if not private.uuid_text(p_payload->>'stop_id') then return private.envelope('{}', 'validation', jsonb_build_object('stop', 'Choose a stop.')); end if;
 select * into s from public.trip_orders where id = (p_payload->>'stop_id')::uuid;
 if not found then raise exception 'Stop access denied.' using errcode = '42501'; end if;
 tr := private.assigned_trip(s.trip_id);
 r := private.replay(tr.station_id::text, 'record_delivery', p_request_key, p_payload);
 if r is not null then return r; end if;
 select * into s from public.trip_orders where id = s.id for update;
 select * into o from public.orders where id = s.order_id for update;
 select * into a from public.delivery_attempts where stop_id = s.id for update;
 if s.version::text is distinct from p_payload->>'version' then return private.envelope(jsonb_build_object('version', s.version), 'stale_version'); end if;
 if s.state <> 'arrived' or a.state <> 'open' then return private.envelope('{}', 'invalid_transition', jsonb_build_object('stop', 'Tap "Arrived" before recording the result.')); end if;
 if p_payload->>'result' = 'delivered' then
  if coalesce(p_payload->>'tendered_centavos', '') !~ '^[0-9]{1,9}$' or coalesce(p_payload->>'empties_returned', '') !~ '^[0-9]{1,4}$' then
   return private.envelope('{}', 'validation', jsonb_build_object('tendered_centavos', 'Enter the cash received and the empty containers collected.'));
  end if;
  tendered := (p_payload->>'tendered_centavos')::bigint; empties := (p_payload->>'empties_returned')::int;
  if tendered < a.amount_due_centavos then
   return private.envelope('{}', 'validation', jsonb_build_object('tendered_centavos', 'The customer must pay the full amount of ' || to_char(a.amount_due_centavos / 100.0, 'FM999,990.00') || ' pesos.'));
  end if;
  update public.delivery_attempts set state = 'delivered', empties_returned = empties, resolved_at = now(), version = version + 1 where id = a.id;
  insert into public.payments(station_id, order_id, attempt_id, applied_centavos, tendered_centavos, change_centavos, expected_empties, empties_returned, collected_by, request_key)
  values (o.station_id, o.id, a.id, a.amount_due_centavos, tendered, tendered - a.amount_due_centavos, coalesce(o.expected_empties, 0), empties, auth.uid(), p_request_key);
  update public.trip_orders set state = 'delivered', version = version + 1 where id = s.id;
  v := private.transition(o.id, 'delivered', null, p_request_key);
  if empties <> coalesce(o.expected_empties, 0) then
   insert into public.order_reviews(station_id, order_id, kind, detail) values (o.station_id, o.id, 'empties_mismatch', format('Expected %s empty containers, rider collected %s.', coalesce(o.expected_empties, 0), empties)) on conflict do nothing;
  end if;
  r := private.envelope(jsonb_build_object('stop_id', s.id, 'result', 'delivered', 'change_centavos', tendered - a.amount_due_centavos, 'order_version', v));
 elsif p_payload->>'result' = 'failed' then
  why := trim(coalesce(p_payload->>'failure_reason', ''));
  if length(why) not between 3 and 500 then return private.envelope('{}', 'validation', jsonb_build_object('failure_reason', 'Say why the delivery failed.')); end if;
  update public.delivery_attempts set state = 'failed', failure_reason = why, resolved_at = now(), version = version + 1 where id = a.id;
  update public.trip_orders set state = 'failed', active = false, version = version + 1 where id = s.id;
  update public.orders set redispatch_paused = true, driver_id = null where id = o.id;
  v := private.transition(o.id, 'ready', 'Delivery attempt failed: ' || why, p_request_key);
  insert into public.order_reviews(station_id, order_id, kind, detail) values (o.station_id, o.id, 'failed_attempt', why) on conflict do nothing;
  perform private.notify_station(o.station_id, 'order:' || o.id || ':' || v, 'delivery_failed', o.id, 'Delivery failed: ' || o.order_no, why);
  r := private.envelope(jsonb_build_object('stop_id', s.id, 'result', 'failed', 'order_version', v));
 else
  return private.envelope('{}', 'validation', jsonb_build_object('result', 'Choose delivered or failed.'));
 end if;
 return private.remember(tr.station_id::text, 'record_delivery', p_request_key, p_payload, r);
end $$;

create or replace function public.close_trip(p_trip_id uuid, p_expected_version int, p_reason text, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare tr public.delivery_trips; is_rider boolean; is_owner boolean; r jsonb; payload jsonb := jsonb_build_object('trip_id', p_trip_id, 'expected_version', p_expected_version, 'reason', p_reason);
 s record; v int; why text := trim(coalesce(p_reason, '')); traveled numeric;
begin
 if auth.uid() is null or private.has_role('super_admin') then raise exception 'Trip access denied.' using errcode = '42501'; end if;
 select * into tr from public.delivery_trips where id = p_trip_id;
 if not found then raise exception 'Trip access denied.' using errcode = '42501'; end if;
 is_owner := private.station_admin(tr.station_id);
 is_rider := tr.rider_user_id = auth.uid() and private.has_role('rider');
 if not (is_owner or is_rider) then raise exception 'Trip access denied.' using errcode = '42501'; end if;
 r := private.replay(tr.station_id::text, 'close_trip', p_request_key, payload);
 if r is not null then return r; end if;
 if is_owner then perform private.owner_guard(tr.station_id); end if;
 select * into tr from public.delivery_trips where id = p_trip_id for update;
 if tr.version is distinct from p_expected_version or tr.status <> 'active' then return private.envelope(jsonb_build_object('status', tr.status, 'version', tr.version), 'stale_version'); end if;
 if exists (select 1 from public.trip_orders where trip_id = tr.id and state in ('pending','arrived')) then
  if not is_owner then return private.envelope('{}', 'invalid_transition', jsonb_build_object('trip', 'Record a result for every stop before closing the trip.')); end if;
  if length(why) not between 3 and 300 then return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Say why you are closing a trip with open stops.')); end if;
  for s in select ts.id, ts.order_id from public.trip_orders ts where ts.trip_id = tr.id and ts.state in ('pending','arrived') order by ts.order_id for update loop
   update public.delivery_attempts set state = 'failed', failure_reason = 'Trip closed by station: ' || why, resolved_at = now(), version = version + 1 where stop_id = s.id and state = 'open';
   update public.trip_orders set state = 'failed', active = false, version = version + 1 where id = s.id;
   update public.orders set redispatch_paused = true, driver_id = null where id = s.order_id;
   v := private.transition(s.order_id, 'ready', 'Trip closed by station: ' || why, p_request_key);
   insert into public.order_reviews(station_id, order_id, kind, detail) values (tr.station_id, s.order_id, 'failed_attempt', 'Trip closed by station: ' || why) on conflict do nothing;
  end loop;
 end if;
 select coalesce(sum(private.haversine_km(l.plat, l.plng, l.latitude, l.longitude)), 0) into traveled
 from (select latitude, longitude, lag(latitude) over w plat, lag(longitude) over w plng from public.rider_locations
  where trip_id = tr.id and accuracy <= 100 window w as (order by recorded_at, id)) l where l.plat is not null;
 update public.delivery_trips set status = 'closed', closed_at = now(), closed_by = auth.uid(), close_reason = nullif(why, ''), traveled_km = round(traveled, 3), version = version + 1
 where id = tr.id returning version into v;
 delete from private.rider_reservations where user_id = tr.rider_user_id and trip_id = tr.id;
 return private.remember(tr.station_id::text, 'close_trip', p_request_key, payload, private.envelope(jsonb_build_object('trip_id', tr.id, 'status', 'closed', 'version', v)));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private' and p.proname in
  ('owner_check','rider_guard','manila_midnight','score','queue_rows','flag_overdue','open_reviews','free_riders','dispatch_candidates','sstf','order_step','resolve_reviews','assigned_trip') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in
  ('get_station_overview','get_preparation_queue','get_dispatch_queue','get_station_order','get_station_customers','start_preparation','mark_order_ready','cancel_order',
   'preview_trip','dispatch_trip','record_pickup_collection','verify_payment','review_delivery_fee','complete_order','approve_reattempt','resolve_order_review',
   'set_customer_classification','save_rider','get_trip','list_rider_history','record_rider_location','mark_arrived','record_delivery','close_trip') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
