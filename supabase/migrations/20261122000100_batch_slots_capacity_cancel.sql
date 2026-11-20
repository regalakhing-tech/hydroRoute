-- M14-M16: one trip carries at most 15 containers from one barangay, scheduled times are 5-minute slots that only
-- one customer can book, an optional daily refill limit per station (with per-date overrides) and a customer
-- cancellation window. Requires 20261121000300_registry_workspace.sql. Paste the whole file into the Supabase SQL Editor.
begin;

alter table public.station_settings
 add column if not exists batch_capacity_load_units int not null default 15 check (batch_capacity_load_units between 1 and 500),
 add column if not exists daily_gallon_limit int check (daily_gallon_limit between 0 and 100000),
 add column if not exists customer_cancel_minutes int not null default 15 check (customer_cancel_minutes between 1 and 1440);

-- Snapshot per order, so changing the setting never shortens a window already promised. Null on older orders: the
-- status rule alone applies to them.
alter table public.orders add column if not exists customer_cancel_until timestamptz;

-- A different refill limit for one Manila date (holiday, short stock). No row means the station default.
create table if not exists public.station_capacity_days (
 station_id uuid not null references public.stations(id), service_date date not null,
 gallon_limit int not null check (gallon_limit between 0 and 100000), version int not null default 1,
 updated_by uuid references auth.users(id), updated_at timestamptz not null default now(),
 primary key (station_id, service_date));
alter table public.station_capacity_days enable row level security;
revoke all on public.station_capacity_days from public, anon, authenticated;
grant select on public.station_capacity_days to authenticated;
drop policy if exists station_capacity_days_owner_read on public.station_capacity_days;
create policy station_capacity_days_owner_read on public.station_capacity_days for select to authenticated using (private.station_admin(station_id));

create index if not exists orders_slot_idx on public.orders(station_id, requested_at) where schedule_mode = 'scheduled' and status not in ('cancelled','on_hold');
create index if not exists orders_service_day_idx on public.orders(station_id, service_date) where status not in ('cancelled','on_hold');

-- Helpers ---------------------------------------------------------------------------------------------
create or replace function private.slot_start(at_time timestamptz) returns timestamptz language sql immutable set search_path = '' as $$
 select date_bin('5 minutes', at_time, timestamptz '2000-01-01 00:00:00+00')
$$;

-- One customer per 5-minute slot per station. A crafted 8:07 competes with 8:05. The caller's own orders never block them.
create or replace function private.slot_taken(sid uuid, at_time timestamptz, cid uuid) returns boolean language sql stable security definer set search_path = '' as $$
 select exists (select 1 from public.orders o where o.station_id = sid and o.schedule_mode = 'scheduled' and o.status not in ('cancelled','on_hold')
  and o.customer_id is distinct from cid and o.requested_at >= private.slot_start(at_time) and o.requested_at < private.slot_start(at_time) + interval '5 minutes')
$$;

create or replace function private.daily_refill_limit(sid uuid, d date) returns int language sql stable security definer set search_path = '' as $$
 select coalesce((select c.gallon_limit from public.station_capacity_days c where c.station_id = sid and c.service_date = d),
  (select s.daily_gallon_limit from public.station_settings s where s.station_id = sid))
$$;

-- Refill containers (returnable items) promised for a date; completed orders keep consuming that day's stock.
create or replace function private.daily_refills_booked(sid uuid, d date, skip uuid) returns int language sql stable security definer set search_path = '' as $$
 select coalesce(sum(o.expected_empties), 0)::int from public.orders o
 where o.station_id = sid and o.service_date = d and o.status not in ('cancelled','on_hold') and o.id is distinct from skip
$$;

create or replace function private.capacity_error(sid uuid, d date, refills int, skip uuid) returns text language plpgsql stable security definer set search_path = '' as $$
declare lim int := private.daily_refill_limit(sid, d); left_n int;
begin
 if lim is null or coalesce(refills, 0) = 0 then return null; end if;
 left_n := greatest(lim - private.daily_refills_booked(sid, d, skip), 0);
 if refills <= left_n then return null; end if;
 return case when left_n = 0 then format('The station is fully booked for refills on %s. Choose another date.', to_char(d, 'Mon DD'))
  else format('The station can take only %s more refill%s on %s. Lower the quantity or choose another date.', left_n, case when left_n = 1 then '' else 's' end, to_char(d, 'Mon DD')) end;
end $$;

-- Cancellation: an unconfirmed repeat can always be withdrawn; otherwise until preparation starts and within the window.
create or replace function private.customer_can_cancel(o public.orders) returns boolean language sql stable set search_path = '' as $$
 select o.status = 'on_hold' or (o.status in ('pending','scheduled','confirmed') and (o.customer_cancel_until is null or now() < o.customer_cancel_until))
$$;

-- Backstop for races between the quote check and the insert: serializes one station-day and rechecks both rules.
create or replace function private.orders_capacity_guard() returns trigger language plpgsql security definer set search_path = '' as $$
declare minutes int; msg text;
begin
 if tg_op = 'INSERT' and new.customer_cancel_until is null then
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
drop trigger if exists orders_capacity_guard on public.orders;
create trigger orders_capacity_guard before insert or update of requested_at, service_date, schedule_mode, status on public.orders
 for each row execute function private.orders_capacity_guard();

-- Replaces 20261120000100: friendlier wording for the queue option (shown as "Wait for your turn").
create or replace function private.schedule_check(sid uuid, ful text, mode text, requested text, t timestamptz) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare av jsonb; days int; at_time timestamptz; today date := (t at time zone 'Asia/Manila')::date; d date;
begin
 if mode = 'asap' then
  av := private.availability(sid, t);
  if not coalesce((av->>'accepting_asap')::boolean, false) then
   return jsonb_build_object('ok', false, 'code', 'station_closed', 'field', 'schedule', 'message',
    case when coalesce((av->>'can_book')::boolean, false) then 'The line is closed right now. Pick a time to schedule your order instead.'
    else 'The station is closed and is not taking orders right now.' end);
  end if;
  return jsonb_build_object('ok', true, 'requested_at', t, 'service_date', today, 'status', 'confirmed');
 end if;
 begin
  at_time := (nullif(requested, '')::timestamp at time zone 'Asia/Manila');
 exception when invalid_datetime_format or datetime_field_overflow or invalid_text_representation then at_time := null;
 end;
 if at_time is null then return jsonb_build_object('ok', false, 'code', 'validation', 'field', 'requested_at', 'message', 'Choose a date and time.'); end if;
 if at_time <= t then return jsonb_build_object('ok', false, 'code', 'validation', 'field', 'requested_at', 'message', 'Choose a time later than now.'); end if;
 select booking_days into days from public.station_settings where station_id = sid;
 d := (at_time at time zone 'Asia/Manila')::date;
 if d > today + days then
  return jsonb_build_object('ok', false, 'code', 'outside_window', 'field', 'requested_at', 'message', format('You can schedule up to %s days ahead. For a later date, send the station a reservation request.', days));
 end if;
 if not exists (select 1 from private.bookable_windows(sid, d, d) w where w.opens_at <= at_time and at_time < w.closes_at
   and at_time <= case when ful = 'delivery' then w.delivery_cutoff else w.order_cutoff end) then
  return jsonb_build_object('ok', false, 'code', 'outside_hours', 'field', 'requested_at', 'message', 'The station is not open for orders at that time. Choose a time within its hours.');
 end if;
 return jsonb_build_object('ok', true, 'requested_at', at_time, 'service_date', d, 'status', case when d = today then 'confirmed' else 'scheduled' end);
end $$;

-- Replaces 20261120000100: adds the per-trip batch ceiling, the 5-minute slot rule and the daily refill limit.
create or replace function private.price_order(sid uuid, cid uuid, p jsonb, t timestamptz) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare st public.stations; cfg public.station_settings; ful text; mode text; lines jsonb; n_in int; n_ok int; units int; subtotal bigint;
 loadu int; empties int; no_load boolean; terms jsonb := '{}'; fee int := 0; sch jsonb; phone text; cname text; cap_msg text;
begin
 select * into st from public.stations where id = sid and active and registration_status = 'approved';
 if not found then return private.envelope('{}', 'station_unavailable', jsonb_build_object('station', 'This station is not taking orders.')); end if;
 select * into cfg from public.station_settings where station_id = sid;
 if jsonb_typeof(p) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p) k where k not in ('fulfillment','items','address_id','schedule','requested_at','note')) then
  return private.envelope('{}', 'validation', jsonb_build_object('order', 'Check the order form.'));
 end if;
 ful := p->>'fulfillment'; mode := coalesce(p->>'schedule', 'asap');
 if ful is null or ful not in ('delivery','pickup') then return private.envelope('{}', 'validation', jsonb_build_object('fulfillment', 'Choose delivery or pickup.')); end if;
 if mode not in ('asap','scheduled') then return private.envelope('{}', 'validation', jsonb_build_object('schedule', 'Choose "Wait for your turn" or pick a time.')); end if;
 if length(coalesce(p->>'note', '')) > 200 then return private.envelope('{}', 'validation', jsonb_build_object('note', 'Keep the note to 200 characters.')); end if;
 if jsonb_typeof(p->'items') is distinct from 'array' or jsonb_array_length(p->'items') not between 1 and 50 then
  return private.envelope('{}', 'validation', jsonb_build_object('items', 'Choose at least one product.'));
 end if;
 if exists (select 1 from jsonb_array_elements(p->'items') e where jsonb_typeof(e) <> 'object' or coalesce(e->>'quantity', '') !~ '^[0-9]{1,4}$'
   or (e->>'quantity')::int < 1 or not private.uuid_text(e->>'product_id')) then
  return private.envelope('{}', 'validation', jsonb_build_object('items', 'Quantities must be whole numbers of at least 1.'));
 end if;
 with req as (select (e->>'product_id')::uuid pid, sum((e->>'quantity')::int)::int q from jsonb_array_elements(p->'items') e group by 1),
  priced as (select r.q, pr.* from req r join public.products pr on pr.id = r.pid and pr.station_id = sid and pr.active)
 select (select count(*) from req), count(*),
  coalesce(jsonb_agg(jsonb_build_object('product_id', id, 'name', name, 'pack_text', pack_text, 'image_key', image_key, 'unit_price_centavos', price_centavos,
   'quantity', q, 'line_total_centavos', q::bigint * price_centavos, 'returnable', returnable, 'load_units', load_units) order by name, id), '[]'),
  coalesce(sum(q), 0), coalesce(sum(q::bigint * price_centavos), 0), coalesce(sum(q * load_units), 0),
  coalesce(sum(q) filter (where returnable), 0), coalesce(bool_or(load_units is null), false)
 into n_in, n_ok, lines, units, subtotal, loadu, empties, no_load from priced;
 if n_ok < n_in then return private.envelope('{}', 'product_unavailable', jsonb_build_object('items', 'A product you chose is no longer available. Review your basket.')); end if;
 if units > cfg.max_order_units then
  return private.envelope('{}', 'over_limit', jsonb_build_object('items', format('This station accepts up to %s items per order.', cfg.max_order_units)));
 end if;
 select coalesce(nullif(pf.phone, ''), u.raw_user_meta_data->>'phone'), coalesce(nullif(pf.display_name, ''), u.raw_user_meta_data->>'full_name', '')
  into phone, cname from auth.users u left join public.profiles pf on pf.id = u.id where u.id = cid;
 if coalesce(phone, '') !~ '^\+639[0-9]{9}$' then
  return private.envelope('{}', 'validation', jsonb_build_object('phone', 'Add a valid mobile number to your account before ordering.'));
 end if;
 if ful = 'delivery' then
  if not private.uuid_text(p->>'address_id') then return private.envelope('{}', 'validation', jsonb_build_object('address_id', 'Choose a delivery address.')); end if;
  terms := private.delivery_terms(sid, (p->>'address_id')::uuid, cid);
  if not (terms->>'eligible')::boolean then return private.envelope(terms, terms->>'code', jsonb_build_object('address_id', terms->>'message')); end if;
  -- D7: bottled-water boxes need a configured load size before a vehicle can carry them.
  if no_load then return private.envelope('{}', 'load_units_missing', jsonb_build_object('items', 'Some products, such as bottled water boxes, are available for pickup only at this station.')); end if;
  -- One trip serves one barangay and carries at most the station batch; orders are never split across trips.
  if loadu > cfg.batch_capacity_load_units then
   return private.envelope('{}', 'over_batch', jsonb_build_object('items', format('One delivery trip carries up to %s containers. Order fewer, split it into two orders, or choose pickup.', cfg.batch_capacity_load_units)));
  end if;
  fee := (terms->>'fee_centavos')::int;
 end if;
 sch := private.schedule_check(sid, ful, mode, p->>'requested_at', t);
 if not (sch->>'ok')::boolean then return private.envelope('{}', sch->>'code', jsonb_build_object(sch->>'field', sch->>'message')); end if;
 if mode = 'scheduled' and private.slot_taken(sid, (sch->>'requested_at')::timestamptz, cid) then
  return private.envelope('{}', 'slot_taken', jsonb_build_object('requested_at', 'Another customer already booked that time. Choose another time.'));
 end if;
 cap_msg := private.capacity_error(sid, (sch->>'service_date')::date, empties, null);
 if cap_msg is not null then return private.envelope('{}', 'daily_limit', jsonb_build_object(case when mode = 'scheduled' then 'requested_at' else 'items' end, cap_msg)); end if;
 return private.envelope(jsonb_build_object('station_id', sid, 'station_name', st.name, 'fulfillment', ful, 'schedule', mode,
  'requested_at', sch->'requested_at', 'service_date', sch->'service_date', 'initial_status', sch->>'status', 'items', lines,
  'total_units', units, 'load_units', case when ful = 'delivery' then loadu end, 'expected_empties', empties, 'subtotal_centavos', subtotal,
  'fee_centavos', fee, 'total_centavos', subtotal + fee, 'contact_name', cname, 'contact_phone', phone, 'note', coalesce(p->>'note', ''),
  'delivery', case when ful = 'delivery' then terms end));
exception when invalid_text_representation or numeric_value_out_of_range then
 return private.envelope('{}', 'validation', jsonb_build_object('order', 'Check the order form.'));
end $$;

-- Replaces 20261120000100: a new time must be a free slot and fit the new date's refill limit.
create or replace function public.reschedule_order(p_order_id uuid, p_requested_at timestamptz, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'requested_at', p_requested_at, 'expected_version', p_expected_version); o public.orders; sch jsonb; v int; cap_msg text;
begin
 perform private.customer_guard();
 select * into o from public.orders where id = p_order_id and customer_id = auth.uid();
 if not found then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'reschedule_order', p_request_key, payload);
 if r is not null then return r; end if;
 perform 1 from public.stations where id = o.station_id for share;
 perform 1 from public.station_availability where station_id = o.station_id for share;
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('status', o.status, 'version', o.version), 'stale_version'); end if;
 if o.status not in ('scheduled','confirmed') then return private.envelope('{}', 'invalid_transition', jsonb_build_object('order', 'Only orders not yet being prepared can be rescheduled.')); end if;
 sch := private.schedule_check(o.station_id, o.fulfillment, 'scheduled', to_char(p_requested_at at time zone 'Asia/Manila', 'YYYY-MM-DD"T"HH24:MI:SS'), now());
 if not (sch->>'ok')::boolean then return private.envelope('{}', sch->>'code', jsonb_build_object(sch->>'field', sch->>'message')); end if;
 if private.slot_taken(o.station_id, (sch->>'requested_at')::timestamptz, o.customer_id) then
  return private.envelope('{}', 'slot_taken', jsonb_build_object('requested_at', 'Another customer already booked that time. Choose another time.'));
 end if;
 cap_msg := private.capacity_error(o.station_id, (sch->>'service_date')::date, coalesce(o.expected_empties, 0), o.id);
 if cap_msg is not null then return private.envelope('{}', 'daily_limit', jsonb_build_object('requested_at', cap_msg)); end if;
 update public.orders set schedule_mode = 'scheduled', requested_at = (sch->>'requested_at')::timestamptz, service_date = (sch->>'service_date')::date,
  version = version + 1, updated_at = now() where id = p_order_id returning version into v;
 if sch->>'status' <> o.status then v := private.transition(p_order_id, sch->>'status', 'Customer rescheduled the order', p_request_key); end if;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, before_values, after_values)
 values (o.station_id, auth.uid(), 'reschedule_order', p_order_id, p_request_key, jsonb_build_object('requested_at', o.requested_at), jsonb_build_object('requested_at', sch->'requested_at'));
 perform private.notify_station(o.station_id, 'order:' || p_order_id || ':' || v, 'order_rescheduled', p_order_id, 'Order ' || o.order_no || ' was rescheduled',
  'New time: ' || to_char((sch->>'requested_at')::timestamptz at time zone 'Asia/Manila', 'Mon DD, HH12:MI AM'));
 return private.remember(o.station_id::text, 'reschedule_order', p_request_key, payload, private.envelope(jsonb_build_object('order_id', p_order_id, 'status', sch->>'status', 'version', v)));
end $$;

-- Replaces 20261120000200: customers cancel only within their order's window (owner rules unchanged).
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
 if not ((is_customer and private.customer_can_cancel(o))
   or (is_owner and (o.status in ('pending','on_hold','scheduled','confirmed','preparing')
    or (o.status = 'ready' and not exists (select 1 from public.trip_orders s where s.order_id = o.id and s.active) and not exists (select 1 from public.payments p where p.order_id = o.id))))) then
  if is_customer and not is_owner and o.status in ('pending','scheduled','confirmed') then
   return private.envelope(jsonb_build_object('status', o.status), 'cancel_window_closed', jsonb_build_object('order', 'The time to cancel this order has passed. Message the station if you need help.'));
  end if;
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

-- Replaces 20261120000100: can_cancel follows the same window as cancel_order.
create or replace function public.get_customer_order(p_order_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare o public.orders;
begin
 perform private.customer_guard();
 select * into o from public.orders where id = p_order_id and customer_id = auth.uid();
 if not found then return private.envelope('{}', 'not_found'); end if;
 return private.envelope(jsonb_build_object(
  'order', to_jsonb(o) - array['customer_id','request_key','fingerprint','cancelled_by'],
  'station', (select jsonb_build_object('id', s.id, 'name', s.name, 'address', s.address, 'barangay', s.barangay, 'phone', s.phone) from public.stations s where s.id = o.station_id),
  'items', coalesce((select jsonb_agg(jsonb_build_object('product_id', i.product_id, 'name', i.product_name, 'pack_text', i.pack_text, 'quantity', i.quantity,
   'unit_price_centavos', i.unit_price_centavos, 'line_total_centavos', i.line_total_centavos) order by i.product_name, i.id) from public.order_items i where i.order_id = o.id), '[]'),
  'timeline', coalesce((select jsonb_agg(jsonb_build_object('from', l.from_status, 'to', l.to_status, 'reason', l.reason, 'at', l.created_at) order by l.created_at, l.id)
   from public.order_status_logs l where l.order_id = o.id), '[]'),
  'driver', case when o.status in ('out_for_delivery','delivered') then (select jsonb_build_object('name', d.full_name, 'phone', d.phone) from public.drivers d where d.user_id = o.driver_id) end,
  'duplicate_of', (select jsonb_build_object('id', x.id, 'order_no', x.order_no, 'status', x.status, 'created_at', x.created_at, 'total_centavos', x.total_centavos)
   from public.orders x where x.id = o.duplicate_of),
  'can_cancel', private.customer_can_cancel(o), 'cancel_until', case when o.status <> 'on_hold' then o.customer_cancel_until end,
  'can_confirm_duplicate', o.status = 'on_hold', 'can_reschedule', o.status in ('scheduled','confirmed')));
end $$;

-- Replaces 20261120000200: a rider's usable capacity is the smaller of the vehicle and the station batch.
create or replace function private.free_riders(sid uuid) returns table(rider_id uuid, user_id uuid, capacity int, trips_today bigint, name text) language sql stable security definer set search_path = '' as $$
 select r.id, r.user_id, least(r.capacity, coalesce(cfg.batch_capacity_load_units, r.capacity)),
  (select count(*) from public.delivery_trips d where d.station_id = sid and d.rider_user_id = r.user_id and d.dispatched_at >= private.manila_midnight(now())),
  coalesce(dr.full_name, 'Rider')
 from public.riders r
 join private.station_memberships m on m.station_id = r.station_id and m.user_id = r.user_id and m.active
 join private.station_membership_roles mr on mr.station_id = r.station_id and mr.user_id = r.user_id and mr.role = 'rider' and mr.active
 left join public.drivers dr on dr.user_id = r.user_id and dr.station_id = sid
 left join public.station_settings cfg on cfg.station_id = r.station_id
 where r.station_id = sid and r.enabled and coalesce(dr.active, true)
  and not exists (select 1 from private.rider_reservations x where x.user_id = r.user_id)
  and not exists (select 1 from private.user_roles u where u.user_id = r.user_id and u.role = 'super_admin')
 order by 4, r.id
$$;

-- Replaces 20261120000200: the trip ceiling is min(vehicle, station batch), snapshotted on the trip.
create or replace function public.dispatch_trip(p_station_id uuid, p_rider_id uuid, p_order_ids uuid[], p_request_key uuid, p_proposal jsonb) returns jsonb language plpgsql security definer set search_path = '' as $$
declare ids uuid[]; payload jsonb; r jsonb; rd public.riders; t timestamptz := now(); total int; cap int; front uuid; front_reason text; n bigint; trip uuid; s record; tno text; v int;
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
 select least(rd.capacity, coalesce(batch_capacity_load_units, rd.capacity)) into cap from public.station_settings where station_id = p_station_id;
 cap := coalesce(cap, rd.capacity);
 if total > cap then return private.envelope('{}', 'over_capacity', jsonb_build_object('order_ids', format('This load (%s) is more than one trip carries (%s).', total, cap))); end if;
 if p_proposal ? 'front_order_id' and coalesce(p_proposal->>'front_order_id', '') <> '' then
  front := (p_proposal->>'front_order_id')::uuid; front_reason := trim(coalesce(p_proposal->>'front_reason', ''));
  if not (front = any(ids)) or not coalesce((select d.overdue from private.dispatch_candidates(p_station_id, t) d where d.order_id = front), false) or length(front_reason) not between 3 and 300 then
   return private.envelope('{}', 'validation', jsonb_build_object('front_order_id', 'Only an overdue order in this trip can go first, and it needs a reason.'));
  end if;
 end if;
 insert into private.station_counters as c(station_id, next_trip_number) values (p_station_id, 2)
 on conflict (station_id) do update set next_trip_number = c.next_trip_number + 1 returning c.next_trip_number - 1 into n;
 insert into public.delivery_trips(station_id, trip_no, rider_id, rider_user_id, capacity, load_units, dispatched_by, request_key)
 values (p_station_id, n, rd.id, rd.user_id, cap, total, auth.uid(), p_request_key) returning id into trip;
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

-- Replaces 20261114155714: accepts the batch size, daily refill limit (null = no limit) and cancellation window.
-- Older clients that omit the new keys keep the stored values.
create or replace function public.save_station_settings(p_station_id uuid, p_payload jsonb, p_expected_version int,
p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare r jsonb;
v int;
begin
 r:=private.begin_mutation(p_station_id, 'save_station_settings', p_request_key, p_payload);
 if r is not null then return r; end if;
 if p_expected_version is distinct from (select version from public.station_settings where station_id=p_station_id)
 then return private.envelope('{}', 'stale_version');
 end if;
 if p_payload is null or jsonb_typeof(p_payload)<>'object' or exists(select 1 from jsonb_object_keys(p_payload) k
 where k not in('max_order_units', 'max_distance_km', 'booking_days', 'duplicate_minutes', 'overdue_minutes',
 'institution_bonus_enabled', 'urgency_asap', 'urgency_soon', 'urgency_later', 'urgency_immediate_minutes',
 'urgency_soon_minutes', 'aging_interval_minutes', 'aging_points', 'aging_cap', 'institution_bonus',
 'batch_capacity_load_units', 'daily_gallon_limit', 'customer_cancel_minutes')) then return
 private.envelope('{}', 'invalid_input', jsonb_build_object('settings', 'Check the order rules.'));
 end if;
 if p_payload->>'max_distance_km' is null then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('max_distance_km', 'A maximum distance is required.'));
 end if;
 if (p_payload->>'max_distance_km')::numeric is distinct from (select max_distance_km from public.station_settings
 where station_id=p_station_id) then
 insert into public.delivery_fee_rule_sets(station_id, version, max_distance_km, creator_id)
 select station_id, active_fee_version+1, (p_payload->>'max_distance_km')::numeric, auth.uid() from
 public.station_settings where station_id=p_station_id;
 insert into public.delivery_fee_rules(station_id, version, band_index, min_km, max_km, min_inclusive,
 max_inclusive, fee_centavos)
 select b.station_id, b.version+1, b.band_index, b.min_km, b.max_km, b.min_inclusive, b.max_inclusive,
 b.fee_centavos from public.delivery_fee_rules b join public.station_settings c on c.station_id=b.station_id and
 c.active_fee_version=b.version where c.station_id=p_station_id;
 update public.station_settings set active_fee_version=active_fee_version+1,
 max_distance_km=(p_payload->>'max_distance_km')::numeric where station_id=p_station_id;
 end if;
 update public.station_settings set max_order_units=(p_payload->>'max_order_units')::int,
 booking_days=(p_payload->>'booking_days')::int, duplicate_minutes=(p_payload->>'duplicate_minutes')::int,
 overdue_minutes=(p_payload->>'overdue_minutes')::int,
 institution_bonus_enabled=(p_payload->>'institution_bonus_enabled')::boolean,
 urgency_asap=(p_payload->>'urgency_asap')::int, urgency_soon=(p_payload->>'urgency_soon')::int,
 urgency_later=(p_payload->>'urgency_later')::int,
 urgency_immediate_minutes=(p_payload->>'urgency_immediate_minutes')::int,
 urgency_soon_minutes=(p_payload->>'urgency_soon_minutes')::int,
 aging_interval_minutes=(p_payload->>'aging_interval_minutes')::int, aging_points=(p_payload->>'aging_points')::int,
 aging_cap=(p_payload->>'aging_cap')::int, institution_bonus=(p_payload->>'institution_bonus')::int,
 batch_capacity_load_units=coalesce((p_payload->>'batch_capacity_load_units')::int, batch_capacity_load_units),
 daily_gallon_limit=case when p_payload ? 'daily_gallon_limit' then (p_payload->>'daily_gallon_limit')::int else daily_gallon_limit end,
 customer_cancel_minutes=coalesce((p_payload->>'customer_cancel_minutes')::int, customer_cancel_minutes),
 version=version+1 where station_id=p_station_id returning version into v;
 return private.finish_mutation(p_station_id, 'save_station_settings', p_request_key, p_payload, v);
 exception when invalid_parameter_value or invalid_text_representation or check_violation or not_null_violation or numeric_value_out_of_range
 then return private.envelope('{}', 'invalid_input', jsonb_build_object('settings', 'Check the order rules.'));
end $$;

-- Customer checkout: other customers' booked slots (times only) and refills left per date in the booking horizon.
create or replace function public.get_booked_slots(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare today date := (now() at time zone 'Asia/Manila')::date; days int;
begin
 perform private.customer_guard();
 select coalesce(booking_days, 7) into days from public.station_settings where station_id = p_station_id;
 days := coalesce(days, 7);
 return private.envelope(jsonb_build_object(
  'slots', coalesce((select jsonb_agg(distinct to_char(private.slot_start(o.requested_at) at time zone 'Asia/Manila', 'YYYY-MM-DD"T"HH24:MI'))
   from public.orders o where o.station_id = p_station_id and o.schedule_mode = 'scheduled' and o.status not in ('cancelled','on_hold')
    and o.customer_id <> auth.uid() and o.requested_at >= now() - interval '5 minutes' and o.service_date <= today + days), '[]'::jsonb),
  'refills_left', coalesce((select jsonb_object_agg(x.d, greatest(x.lim - private.daily_refills_booked(p_station_id, x.d, null), 0))
   from (select g::date d, private.daily_refill_limit(p_station_id, g::date) lim from generate_series(today::timestamp, (today + days)::timestamp, interval '1 day') g) x
   where x.lim is not null), '{}'::jsonb)));
end $$;

-- Owner schedules page: the next 14 days with the limit in force, whether it is an override, and refills booked.
create or replace function public.get_capacity_days(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare today date := (now() at time zone 'Asia/Manila')::date;
begin
 perform private.owner_check(p_station_id);
 return private.envelope(jsonb_build_object(
  'default_limit', (select daily_gallon_limit from public.station_settings where station_id = p_station_id),
  'days', (select jsonb_agg(jsonb_build_object('date', g::date, 'limit', private.daily_refill_limit(p_station_id, g::date),
    'override', exists (select 1 from public.station_capacity_days c where c.station_id = p_station_id and c.service_date = g::date),
    'booked', private.daily_refills_booked(p_station_id, g::date, null)) order by g)
   from generate_series(today::timestamp, (today + 13)::timestamp, interval '1 day') g)));
end $$;

-- Set (or with a null limit, clear) one date's refill limit. Lowering it never cancels orders already booked.
create or replace function public.save_capacity_day(p_station_id uuid, p_service_date date, p_gallon_limit int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('service_date', p_service_date, 'gallon_limit', p_gallon_limit); today date := (now() at time zone 'Asia/Manila')::date;
begin
 perform private.owner_check(p_station_id);
 r := private.replay(p_station_id::text, 'save_capacity_day', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(p_station_id);
 if p_service_date is null or p_service_date < today or p_service_date > today + 90 then
  return private.envelope('{}', 'validation', jsonb_build_object('service_date', 'Choose a date from today to 90 days ahead.'));
 end if;
 if p_gallon_limit is not null and p_gallon_limit not between 0 and 100000 then
  return private.envelope('{}', 'validation', jsonb_build_object('gallon_limit', 'Enter a whole number from 0 to 100000.'));
 end if;
 if p_gallon_limit is null then
  delete from public.station_capacity_days where station_id = p_station_id and service_date = p_service_date;
 else
  insert into public.station_capacity_days as c(station_id, service_date, gallon_limit, updated_by) values (p_station_id, p_service_date, p_gallon_limit, auth.uid())
  on conflict (station_id, service_date) do update set gallon_limit = excluded.gallon_limit, version = c.version + 1, updated_by = auth.uid(), updated_at = now();
 end if;
 insert into public.station_audit_events(station_id, actor_id, action, request_key, after_values)
 values (p_station_id, auth.uid(), 'save_capacity_day', p_request_key, payload);
 return private.remember(p_station_id::text, 'save_capacity_day', p_request_key, payload, private.envelope(jsonb_build_object('service_date', p_service_date,
  'gallon_limit', p_gallon_limit, 'booked', private.daily_refills_booked(p_station_id, p_service_date, null))));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private' and p.proname in
  ('slot_start','slot_taken','daily_refill_limit','daily_refills_booked','capacity_error','customer_can_cancel','orders_capacity_guard','schedule_check','price_order','free_riders') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in
  ('reschedule_order','cancel_order','get_customer_order','dispatch_trip','save_station_settings','get_booked_slots','get_capacity_days','save_capacity_day') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
