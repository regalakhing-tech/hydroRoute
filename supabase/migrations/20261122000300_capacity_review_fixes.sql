-- Fixes from the independent review of 20261122000100: prepared-then-cancelled refills keep using the day's stock,
-- a held repeat order starts its cancel window when it is confirmed, and confirming a held repeat rechecks the station,
-- its (possibly new) date, the 5-minute slot and the refill limit with clear messages instead of a database error.
-- Also adds get_cancelled_orders for the order queue's "Cancelled" filter.
-- Requires 20261122000200_rider_edit_station_lifecycle.sql. Paste the whole file into the Supabase SQL Editor.
begin;

-- Replaces 20261122000100: an order that reached preparation used its water even if it was cancelled afterwards.
create or replace function private.daily_refills_booked(sid uuid, d date, skip uuid) returns int language sql stable security definer set search_path = '' as $$
 select coalesce(sum(o.expected_empties), 0)::int from public.orders o
 where o.station_id = sid and o.service_date = d and o.id is distinct from skip
  and (o.status not in ('cancelled','on_hold')
   or (o.status = 'cancelled' and exists (select 1 from public.order_status_logs l where l.order_id = o.id and l.to_status = 'preparing')))
$$;

-- Replaces 20261122000100: the cancel window starts when an order is committed, so a held repeat gets its full window
-- when the customer confirms it, not when the hold was created.
create or replace function private.orders_capacity_guard() returns trigger language plpgsql security definer set search_path = '' as $$
declare minutes int; msg text;
begin
 if new.customer_cancel_until is null and new.status not in ('on_hold','cancelled')
   and (tg_op = 'INSERT' or old.status = 'on_hold') then
  select customer_cancel_minutes into minutes from public.station_settings where station_id = new.station_id;
  new.customer_cancel_until := now() + make_interval(mins => coalesce(minutes, 15));
 end if;
 if new.status in ('cancelled','on_hold') then return new; end if;
 if tg_op = 'UPDATE' and new.requested_at is not distinct from old.requested_at and new.service_date is not distinct from old.service_date
   and new.schedule_mode is not distinct from old.schedule_mode and old.status not in ('cancelled','on_hold') then return new; end if;
 if new.service_date is not null then
  perform pg_advisory_xact_lock(hashtextextended('capacity:' || new.station_id || ':' || new.service_date, 0));
  msg := private.capacity_error(new.station_id, new.service_date, coalesce(new.expected_empties, 0), new.id);
  if msg is not null then raise exception '%', msg using errcode = 'P0001', hint = 'daily_limit'; end if;
 end if;
 if new.schedule_mode = 'scheduled' and new.requested_at is not null and private.slot_taken(new.station_id, new.requested_at, new.customer_id) then
  raise exception 'Another customer already booked that time. Choose another time.' using errcode = 'P0001', hint = 'slot_taken';
 end if;
 return new;
end $$;

-- Replaces 20261120000100: a held repeat is a new commitment, so it must pass today's checks when confirmed.
create or replace function public.confirm_duplicate(p_order_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'expected_version', p_expected_version); o public.orders; sch jsonb; v int; cap_msg text;
begin
 perform private.customer_guard();
 select * into o from public.orders where id = p_order_id and customer_id = auth.uid();
 if not found then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'confirm_duplicate', p_request_key, payload);
 if r is not null then return r; end if;
 perform 1 from public.stations where id = o.station_id for share;
 perform 1 from public.station_availability where station_id = o.station_id for share;
 select * into o from public.orders where id = p_order_id for update;
 if o.status <> 'on_hold' or o.version is distinct from p_expected_version then
  return private.envelope(jsonb_build_object('status', o.status, 'version', o.version), 'stale_version');
 end if;
 if not exists (select 1 from public.stations s where s.id = o.station_id and s.active and s.registration_status = 'approved') then
  return private.envelope('{}', 'station_unavailable', jsonb_build_object('station', 'This station is not taking orders right now. Cancel this repeat order or choose another station.'));
 end if;
 sch := private.schedule_check(o.station_id, o.fulfillment, o.schedule_mode, to_char(o.requested_at at time zone 'Asia/Manila', 'YYYY-MM-DD"T"HH24:MI:SS'), now());
 if not (sch->>'ok')::boolean then return private.envelope('{}', sch->>'code', jsonb_build_object(sch->>'field', sch->>'message')); end if;
 if o.schedule_mode = 'scheduled' and private.slot_taken(o.station_id, o.requested_at, o.customer_id) then
  return private.envelope('{}', 'slot_taken', jsonb_build_object('requested_at', 'Another customer booked that time while this order was on hold. Cancel it and order again with another time.'));
 end if;
 cap_msg := private.capacity_error(o.station_id, (sch->>'service_date')::date, coalesce(o.expected_empties, 0), o.id);
 if cap_msg is not null then return private.envelope('{}', 'daily_limit', jsonb_build_object('order', cap_msg)); end if;
 -- A "wait for your turn" order confirmed on a later day belongs to that day's queue and stock.
 if o.schedule_mode is distinct from 'scheduled' then
  update public.orders set requested_at = (sch->>'requested_at')::timestamptz, service_date = (sch->>'service_date')::date where id = p_order_id;
 end if;
 update public.orders set duplicate_confirmed_at = now() where id = p_order_id;
 v := private.transition(p_order_id, sch->>'status', 'Customer confirmed an intentional repeat order', p_request_key);
 perform private.notify_station(o.station_id, 'order:' || p_order_id || ':new', 'order_new', p_order_id, 'New order ' || o.order_no, 'The customer confirmed this repeat order.');
 return private.remember(o.station_id::text, 'confirm_duplicate', p_request_key, payload,
  private.envelope(jsonb_build_object('order_id', p_order_id, 'status', sch->>'status', 'version', v)));
end $$;

-- Order queue "Cancelled" filter: the station's orders cancelled in the last 30 days (newest first, at most 200).
create or replace function public.get_cancelled_orders(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
begin
 perform private.owner_check(p_station_id);
 return private.envelope(jsonb_build_object('orders', coalesce((select jsonb_agg(x.j order by x.cancelled_at desc, x.id) from (
  select o.cancelled_at, o.id, jsonb_build_object('id', o.id, 'order_no', coalesce(o.order_no, upper(left(o.id::text, 8))), 'status', o.status, 'fulfillment', o.fulfillment,
   'customer_name', o.contact_name, 'barangay', o.address_barangay, 'total_units', o.total_units, 'cancel_reason', o.cancel_reason, 'cancelled_at', o.cancelled_at,
   'overdue', false, 'score', jsonb_build_object('u', 0, 'a', 0, 'c', 0, 'p', 0)) j
  from public.orders o where o.station_id = p_station_id and o.status = 'cancelled' and o.cancelled_at >= now() - interval '30 days'
  order by o.cancelled_at desc limit 200) x), '[]'::jsonb)));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private' and p.proname in ('daily_refills_booked','orders_capacity_guard') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in ('confirm_duplicate','get_cancelled_orders') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
