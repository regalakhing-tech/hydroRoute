-- Phase 4: customer quotes, order submission, duplicate holds, cancellation, rescheduling, tracking and notifications.
-- Requires Phase 2 (20261114155714_station_setup.sql). The address/profile columns read here belong to Phase 3;
-- they are added with "if not exists" so this file is safe whichever phase defines them first.
-- Paste the whole file into the Supabase SQL Editor. It changes no existing rows except widening the orders status rule.
begin;

alter table public.addresses add column if not exists barangay_id uuid references public.barangays(id),
 add column if not exists latitude numeric, add column if not exists longitude numeric,
 add column if not exists landmark text not null default '', add column if not exists deleted_at timestamptz;
alter table public.profiles add column if not exists phone text;

alter table public.orders
 add column if not exists order_no text, add column if not exists request_key uuid,
 add column if not exists version int not null default 1, add column if not exists contract_version int not null default 1,
 add column if not exists schedule_mode text check (schedule_mode in ('asap','scheduled')),
 add column if not exists requested_at timestamptz, add column if not exists service_date date,
 add column if not exists queued_at timestamptz, add column if not exists released_at timestamptz,
 add column if not exists completed_at timestamptz, add column if not exists cancelled_at timestamptz,
 add column if not exists total_units int check (total_units > 0), add column if not exists load_units int check (load_units >= 0),
 add column if not exists expected_empties int check (expected_empties >= 0),
 add column if not exists note text not null default '' check (length(note) <= 200),
 add column if not exists contact_name text, add column if not exists contact_phone text,
 add column if not exists address_label text, add column if not exists address_text text, add column if not exists address_landmark text,
 add column if not exists address_barangay_id uuid, add column if not exists address_barangay text,
 add column if not exists address_latitude numeric, add column if not exists address_longitude numeric,
 add column if not exists station_latitude numeric, add column if not exists station_longitude numeric,
 add column if not exists subtotal_centavos bigint check (subtotal_centavos >= 0),
 add column if not exists distance_km numeric check (distance_km >= 0), add column if not exists distance_display numeric(6,1),
 add column if not exists quoted_fee_centavos int check (quoted_fee_centavos >= 0), add column if not exists final_fee_centavos int check (final_fee_centavos >= 0),
 add column if not exists fee_version int, add column if not exists total_centavos bigint check (total_centavos >= 0),
 add column if not exists duplicate_of uuid, add column if not exists fingerprint text, add column if not exists duplicate_confirmed_at timestamptz,
 add column if not exists cancel_reason text check (length(cancel_reason) <= 500), add column if not exists cancelled_by uuid references auth.users(id),
 add column if not exists redispatch_paused boolean not null default false,
 add column if not exists updated_at timestamptz not null default now();

-- Widen the original status rule (system plan section 6, gap 1). Every current row stays valid.
do $$declare c text; begin
 for c in select conname from pg_constraint where conrelid = 'public.orders'::regclass and contype = 'c'
  and pg_get_constraintdef(oid) like '%out_for_delivery%' and conname <> 'orders_status_fulfillment_check' loop
  execute format('alter table public.orders drop constraint %I', c);
 end loop;
end $$;
alter table public.orders drop constraint if exists orders_status_fulfillment_check;
alter table public.orders add constraint orders_status_fulfillment_check check (
 (fulfillment = 'delivery' and address_id is not null and status in ('pending','on_hold','scheduled','confirmed','preparing','ready','out_for_delivery','delivered','completed','cancelled'))
 or (fulfillment = 'pickup' and address_id is null and driver_id is null and status in ('pending','on_hold','scheduled','confirmed','preparing','ready','collected','completed','cancelled')));
-- Rows created by submit_order (contract 2) always carry complete snapshots; legacy rows keep contract 1 and nulls.
alter table public.orders drop constraint if exists orders_contract_v2_check;
alter table public.orders add constraint orders_contract_v2_check check (contract_version = 1 or (
 order_no is not null and request_key is not null and schedule_mode is not null and requested_at is not null and service_date is not null
 and total_units is not null and subtotal_centavos is not null and quoted_fee_centavos is not null
 and total_centavos = subtotal_centavos + coalesce(final_fee_centavos, quoted_fee_centavos)
 and ((fulfillment = 'pickup' and quoted_fee_centavos = 0 and coalesce(final_fee_centavos, 0) = 0 and distance_km is null)
   or (fulfillment = 'delivery' and distance_km is not null and fee_version is not null and address_latitude is not null and station_latitude is not null))));
alter table public.orders drop constraint if exists orders_fee_version_fkey;
alter table public.orders add constraint orders_fee_version_fkey foreign key (station_id, fee_version) references public.delivery_fee_rule_sets(station_id, version);
alter table public.orders drop constraint if exists orders_duplicate_of_fkey;
alter table public.orders add constraint orders_duplicate_of_fkey foreign key (duplicate_of, station_id, customer_id) references public.orders(id, station_id, customer_id);
create unique index if not exists orders_request_key_idx on public.orders(station_id, customer_id, request_key) where request_key is not null;
create unique index if not exists orders_order_no_idx on public.orders(station_id, order_no) where order_no is not null;
create index if not exists orders_station_status_idx on public.orders(station_id, status, service_date);
create index if not exists orders_station_customer_idx on public.orders(station_id, customer_id, created_at, id);
create index if not exists orders_fingerprint_idx on public.orders(station_id, fingerprint, created_at);
create index if not exists orders_scheduled_idx on public.orders(station_id, requested_at) where status = 'scheduled';
create index if not exists orders_duplicate_idx on public.orders(duplicate_of, station_id, customer_id);
create index if not exists orders_fee_version_idx on public.orders(station_id, fee_version);

alter table public.order_items add column if not exists pack_text text not null default '',
 add column if not exists returnable boolean not null default false, add column if not exists load_units int check (load_units > 0);

create table if not exists public.order_status_logs (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, order_id uuid not null,
 from_status text, to_status text not null, actor_id uuid references auth.users(id), system_job text,
 reason text check (length(reason) <= 500), order_version int not null, request_key uuid,
 created_at timestamptz not null default now(),
 foreign key (order_id, station_id) references public.orders(id, station_id) on delete cascade,
 unique (order_id, order_version), check (actor_id is not null or system_job is not null));
create index if not exists order_status_logs_order_idx on public.order_status_logs(station_id, order_id, created_at, id);
create index if not exists order_status_logs_actor_idx on public.order_status_logs(actor_id);

alter table public.notifications add column if not exists order_id uuid,
 add column if not exists title text not null default '' check (length(title) <= 160),
 add column if not exists body text not null default '' check (length(body) <= 500);
alter table public.notifications drop constraint if exists notifications_order_fkey;
alter table public.notifications add constraint notifications_order_fkey foreign key (order_id, station_id) references public.orders(id, station_id) on delete cascade;
create index if not exists notifications_unread_idx on public.notifications(recipient_id, read_at, created_at desc);
create index if not exists notifications_order_idx on public.notifications(order_id, station_id);

alter table public.order_status_logs enable row level security;
revoke all on public.order_status_logs from public, anon, authenticated;

-- Helpers ---------------------------------------------------------------------------------------------
create or replace function private.customer_guard() returns void language plpgsql stable security definer set search_path = '' as $$
begin
 if auth.uid() is null or private.has_role('super_admin') or not private.has_role('customer') then
  raise exception 'Customer access denied.' using errcode = '42501';
 end if;
end $$;

-- Idempotency for any actor scope. Only successful results are kept, so a corrected retry is not blocked.
create or replace function private.replay(scope text, op text, key uuid, payload jsonb) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r private.mutation_requests;
begin
 if key is null then return private.envelope('{}', 'validation', jsonb_build_object('request_key', 'A request key is required.')); end if;
 perform pg_advisory_xact_lock(hashtextextended(auth.uid()::text || ':' || scope || ':' || op || ':' || key::text, 0));
 select * into r from private.mutation_requests where actor_id = auth.uid() and scope_key = scope and operation = op and request_key = key;
 if found then
  if r.payload_digest <> md5(payload::text) then return private.envelope('{}', 'idempotency_conflict'); end if;
  return r.result;
 end if;
 return null;
end $$;

create or replace function private.remember(scope text, op text, key uuid, payload jsonb, result jsonb) returns jsonb language plpgsql security definer set search_path = '' as $$
begin
 if coalesce((result->>'ok')::boolean, false) then
  insert into private.mutation_requests(actor_id, scope_key, operation, request_key, payload_digest, result)
  values (auth.uid(), scope, op, key, md5(payload::text), result);
 end if;
 return result;
end $$;

create or replace function private.haversine_km(lat1 numeric, lon1 numeric, lat2 numeric, lon2 numeric) returns numeric language sql immutable set search_path = '' as $$
 select (2 * 6371 * asin(sqrt(power(sin(radians((lat2 - lat1)::float8) / 2), 2)
  + cos(radians(lat1::float8)) * cos(radians(lat2::float8)) * power(sin(radians((lon2 - lon1)::float8) / 2), 2))))::numeric
$$;

-- D1: the band is chosen from the unrounded distance.
create or replace function private.fee_for(sid uuid, ver int, km numeric) returns int language sql stable security definer set search_path = '' as $$
 select fee_centavos from public.delivery_fee_rules where station_id = sid and version = ver
  and (km > min_km or (min_inclusive and km = min_km)) and (max_km is null or km < max_km or (max_inclusive and km = max_km))
 order by band_index limit 1
$$;

create or replace function private.uuid_text(v text) returns boolean language sql immutable set search_path = '' as $$
 select coalesce(v, '') ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
$$;

-- Distance, service area and fee for one owned address. Shared by quote_delivery and price_order.
create or replace function private.delivery_terms(sid uuid, aid uuid, cid uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare st public.stations; a public.addresses; cfg public.station_settings; fs public.delivery_fee_rule_sets; km numeric; fee int;
begin
 select * into st from public.stations where id = sid;
 select * into a from public.addresses where id = aid and customer_id = cid and deleted_at is null;
 if not found then return jsonb_build_object('eligible', false, 'code', 'validation', 'message', 'Choose one of your saved addresses.'); end if;
 if a.latitude is null or a.longitude is null then
  return jsonb_build_object('eligible', false, 'code', 'address_no_pin', 'message', 'This address has no map pin. Add a pin on the Addresses page or choose pickup.');
 end if;
 if st.latitude is null or st.longitude is null then
  return jsonb_build_object('eligible', false, 'code', 'station_no_pin', 'message', 'This station has not set its map location yet, so it takes pickup orders only.');
 end if;
 if exists (select 1 from public.station_service_areas where station_id = sid and served)
  and not exists (select 1 from public.station_service_areas where station_id = sid and served and barangay_id = a.barangay_id) then
  return jsonb_build_object('eligible', false, 'code', 'out_of_area', 'message', 'This station does not deliver to that barangay.');
 end if;
 select * into cfg from public.station_settings where station_id = sid;
 select * into fs from public.delivery_fee_rule_sets where station_id = sid and version = cfg.active_fee_version;
 km := private.haversine_km(st.latitude, st.longitude, a.latitude, a.longitude);
 if km > fs.max_distance_km then
  return jsonb_build_object('eligible', false, 'code', 'out_of_range', 'distance_km', km, 'distance_display', round(km, 1), 'max_distance_km', fs.max_distance_km,
   'message', format('This address is about %s km away. The station delivers up to %s km.', round(km, 1), fs.max_distance_km));
 end if;
 fee := private.fee_for(sid, fs.version, km);
 if fee is null then return jsonb_build_object('eligible', false, 'code', 'fee_unavailable', 'message', 'The station has no delivery fee for this distance.'); end if;
 return jsonb_build_object('eligible', true, 'distance_km', km, 'distance_display', round(km, 1), 'fee_centavos', fee, 'fee_version', fs.version,
  'max_distance_km', fs.max_distance_km, 'address_id', a.id, 'label', a.label, 'address', a.address, 'landmark', a.landmark,
  'barangay_id', a.barangay_id, 'barangay', (select name from public.barangays where id = a.barangay_id), 'latitude', a.latitude, 'longitude', a.longitude,
  'station_latitude', st.latitude, 'station_longitude', st.longitude);
end $$;

-- V5, V6 and D4/D10: ASAP needs the station accepting now; a scheduled time must fall in a bookable window and cutoff.
create or replace function private.schedule_check(sid uuid, ful text, mode text, requested text, t timestamptz) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare av jsonb; days int; at_time timestamptz; today date := (t at time zone 'Asia/Manila')::date; d date;
begin
 if mode = 'asap' then
  av := private.availability(sid, t);
  if not coalesce((av->>'accepting_asap')::boolean, false) then
   return jsonb_build_object('ok', false, 'code', 'station_closed', 'field', 'schedule', 'message',
    case when coalesce((av->>'can_book')::boolean, false) then 'The station is not taking ASAP orders right now. Schedule your order for a later time.'
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

-- The single pricing engine. Ignores nothing silently: unknown payload keys (prices, fees, totals, roles) are rejected.
create or replace function private.price_order(sid uuid, cid uuid, p jsonb, t timestamptz) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare st public.stations; cfg public.station_settings; ful text; mode text; lines jsonb; n_in int; n_ok int; units int; subtotal bigint;
 loadu int; empties int; no_load boolean; terms jsonb := '{}'; fee int := 0; sch jsonb; phone text; cname text;
begin
 select * into st from public.stations where id = sid and active and registration_status = 'approved';
 if not found then return private.envelope('{}', 'station_unavailable', jsonb_build_object('station', 'This station is not taking orders.')); end if;
 select * into cfg from public.station_settings where station_id = sid;
 if jsonb_typeof(p) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p) k where k not in ('fulfillment','items','address_id','schedule','requested_at','note')) then
  return private.envelope('{}', 'validation', jsonb_build_object('order', 'Check the order form.'));
 end if;
 ful := p->>'fulfillment'; mode := coalesce(p->>'schedule', 'asap');
 if ful is null or ful not in ('delivery','pickup') then return private.envelope('{}', 'validation', jsonb_build_object('fulfillment', 'Choose delivery or pickup.')); end if;
 if mode not in ('asap','scheduled') then return private.envelope('{}', 'validation', jsonb_build_object('schedule', 'Choose ASAP or a scheduled time.')); end if;
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
  fee := (terms->>'fee_centavos')::int;
 end if;
 sch := private.schedule_check(sid, ful, mode, p->>'requested_at', t);
 if not (sch->>'ok')::boolean then return private.envelope('{}', sch->>'code', jsonb_build_object(sch->>'field', sch->>'message')); end if;
 return private.envelope(jsonb_build_object('station_id', sid, 'station_name', st.name, 'fulfillment', ful, 'schedule', mode,
  'requested_at', sch->'requested_at', 'service_date', sch->'service_date', 'initial_status', sch->>'status', 'items', lines,
  'total_units', units, 'load_units', case when ful = 'delivery' then loadu end, 'expected_empties', empties, 'subtotal_centavos', subtotal,
  'fee_centavos', fee, 'total_centavos', subtotal + fee, 'contact_name', cname, 'contact_phone', phone, 'note', coalesce(p->>'note', ''),
  'delivery', case when ful = 'delivery' then terms end));
exception when invalid_text_representation or numeric_value_out_of_range then
 return private.envelope('{}', 'validation', jsonb_build_object('order', 'Check the order form.'));
end $$;

create or replace function private.status_message(st text, ful text, no text, out title text, out body text) language sql immutable set search_path = '' as $$
 select case st when 'on_hold' then 'Please confirm order ' || no when 'scheduled' then 'Order ' || no || ' is scheduled'
  when 'confirmed' then 'Order ' || no || ' is confirmed' when 'preparing' then 'Order ' || no || ' is being prepared'
  when 'ready' then case when ful = 'pickup' then 'Order ' || no || ' is ready for pickup' else 'Order ' || no || ' is ready for delivery' end
  when 'out_for_delivery' then 'Your water is on the way' when 'delivered' then 'Order ' || no || ' was delivered'
  when 'collected' then 'Order ' || no || ' was picked up' when 'completed' then 'Order ' || no || ' is complete'
  when 'cancelled' then 'Order ' || no || ' was cancelled' else 'Order ' || no || ' was updated' end,
 case st when 'on_hold' then 'It matches an order you placed a few minutes ago. Confirm it if you want another, or cancel it.'
  when 'scheduled' then 'The station will start it on the day you chose.' when 'confirmed' then 'The station received your order.'
  when 'preparing' then 'The station is refilling your containers.'
  when 'ready' then case when ful = 'pickup' then 'Bring your empty containers and pay at the station.' else 'It will leave with the next delivery trip.' end
  when 'out_for_delivery' then 'Order ' || no || ' is out for delivery. Please prepare the exact amount if you can.'
  when 'delivered' then 'Thank you for ordering.' when 'collected' then 'Thank you for picking up your water.'
  when 'completed' then 'The station has closed this order.' when 'cancelled' then 'See the order page for the reason.' else '' end
$$;

create or replace function private.notify(sid uuid, rid uuid, ev text, k text, oid uuid, ttl text, msg text) returns void language sql security definer set search_path = '' as $$
 insert into public.notifications(station_id, recipient_id, event_key, kind, order_id, title, body)
 values (sid, rid, ev, k, oid, left(ttl, 160), left(msg, 500)) on conflict (recipient_id, event_key) do nothing
$$;

-- Station-side notices use the "staff_" kind prefix so a staff member's customer inbox never mixes them in.
create or replace function private.notify_station(sid uuid, ev text, k text, oid uuid, ttl text, msg text) returns void language sql security definer set search_path = '' as $$
 insert into public.notifications(station_id, recipient_id, event_key, kind, order_id, title, body)
 select sid, r.user_id, ev, 'staff_' || k, oid, left(ttl, 160), left(msg, 500)
 from private.station_membership_roles r join private.station_memberships m on (m.station_id, m.user_id) = (r.station_id, r.user_id)
 where r.station_id = sid and r.role = 'station_admin' and r.active and m.active
 on conflict (recipient_id, event_key) do nothing
$$;

-- Every status change: one row lock held by the caller, one version bump, one log row, one customer notice.
create or replace function private.transition(oid uuid, new_status text, reason text, key uuid, job text default null) returns int language plpgsql security definer set search_path = '' as $$
declare o public.orders; v int; m record;
begin
 select * into o from public.orders where id = oid;
 update public.orders set status = new_status, version = version + 1, updated_at = now(),
  queued_at = case when new_status = 'confirmed' and o.status in ('scheduled','on_hold') then now() when new_status = 'scheduled' then null else queued_at end,
  released_at = case when new_status = 'confirmed' and o.status = 'scheduled' then now() else released_at end,
  completed_at = case when new_status = 'completed' then now() else completed_at end,
  cancelled_at = case when new_status = 'cancelled' then now() else cancelled_at end,
  cancel_reason = case when new_status = 'cancelled' then reason else cancel_reason end,
  cancelled_by = case when new_status = 'cancelled' then auth.uid() else cancelled_by end
 where id = oid returning version into v;
 insert into public.order_status_logs(station_id, order_id, from_status, to_status, actor_id, system_job, reason, order_version, request_key)
 values (o.station_id, oid, o.status, new_status, case when job is null then auth.uid() end, job, reason, v, key);
 if auth.uid() is distinct from o.customer_id then
  select * into m from private.status_message(new_status, o.fulfillment, coalesce(o.order_no, 'order'));
  perform private.notify(o.station_id, o.customer_id, 'order:' || oid || ':' || v, 'order_' || new_status, oid, m.title, m.body);
 end if;
 return v;
end $$;

-- Customer RPCs ---------------------------------------------------------------------------------------
create or replace function public.quote_order(p_station_id uuid, p_payload jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
begin
 perform private.customer_guard();
 return private.price_order(p_station_id, auth.uid(), p_payload, now());
end $$;

create or replace function public.quote_delivery(p_station_id uuid, p_address_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare terms jsonb;
begin
 perform private.customer_guard();
 if not exists (select 1 from public.stations where id = p_station_id and active and registration_status = 'approved') then return private.envelope('{}', 'not_found'); end if;
 terms := private.delivery_terms(p_station_id, p_address_id, auth.uid());
 return private.envelope(terms || jsonb_build_object('station_id', p_station_id, 'address_id', p_address_id));
end $$;

-- Inserts one priced order (data from private.price_order) with items and its first status log. Callers lock and notify.
create or replace function private.insert_order(sid uuid, cid uuid, d jsonb, st text, key uuid, dup uuid, fp text, reason text) returns jsonb language plpgsql security definer set search_path = '' as $$
declare dl jsonb := d->'delivery'; n bigint; no text; oid uuid; v int;
begin
 insert into private.station_counters as c(station_id, next_order_number) values (sid, 2)
 on conflict (station_id) do update set next_order_number = c.next_order_number + 1 returning c.next_order_number - 1 into n;
 no := 'HMT-' || lpad(n::text, 4, '0');
 insert into public.orders(station_id, customer_id, fulfillment, address_id, status, order_no, request_key, contract_version, schedule_mode, requested_at,
  service_date, queued_at, total_units, load_units, expected_empties, note, contact_name, contact_phone, address_label, address_text, address_landmark,
  address_barangay_id, address_barangay, address_latitude, address_longitude, station_latitude, station_longitude, subtotal_centavos, distance_km,
  distance_display, quoted_fee_centavos, fee_version, total_centavos, duplicate_of, fingerprint)
 values (sid, cid, d->>'fulfillment', (dl->>'address_id')::uuid, st, no, key, 2, d->>'schedule', (d->>'requested_at')::timestamptz,
  (d->>'service_date')::date, case when st = 'confirmed' then now() end, (d->>'total_units')::int, (d->>'load_units')::int, (d->>'expected_empties')::int,
  d->>'note', d->>'contact_name', d->>'contact_phone', dl->>'label', dl->>'address', dl->>'landmark', (dl->>'barangay_id')::uuid, dl->>'barangay',
  (dl->>'latitude')::numeric, (dl->>'longitude')::numeric, (dl->>'station_latitude')::numeric, (dl->>'station_longitude')::numeric,
  (d->>'subtotal_centavos')::bigint, (dl->>'distance_km')::numeric, (dl->>'distance_display')::numeric, (d->>'fee_centavos')::int,
  (dl->>'fee_version')::int, (d->>'total_centavos')::bigint, dup, fp)
 returning id, version into oid, v;
 insert into public.order_items(order_id, station_id, product_id, product_name, quantity, unit_price_centavos, pack_text, returnable, load_units)
 select oid, sid, (i->>'product_id')::uuid, i->>'name', (i->>'quantity')::int, (i->>'unit_price_centavos')::int,
  coalesce(i->>'pack_text', ''), (i->>'returnable')::boolean, (i->>'load_units')::int from jsonb_array_elements(d->'items') i;
 insert into public.order_status_logs(station_id, order_id, from_status, to_status, actor_id, reason, order_version, request_key)
 values (sid, oid, null, st, auth.uid(), reason, v, key);
 return jsonb_build_object('order_id', oid, 'order_no', no, 'version', v);
end $$;

create or replace function public.submit_order(p_station_id uuid, p_payload jsonb, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; q jsonb; d jsonb; dl jsonb; fp text; orig public.orders; st text; n bigint; no text; oid uuid; v int; dup_minutes int;
begin
 perform private.customer_guard();
 r := private.replay(p_station_id::text, 'submit_order', p_request_key, p_payload);
 if r is not null then return r; end if;
 -- Shared locks: closures and schedule changes (which lock these rows for update) serialize with submissions.
 perform 1 from public.stations where id = p_station_id for share;
 perform 1 from public.station_availability where station_id = p_station_id for share;
 q := private.price_order(p_station_id, auth.uid(), p_payload, now());
 if not (q->>'ok')::boolean then return q; end if;
 d := q->'data'; dl := d->'delivery';
 fp := md5(concat_ws('|', d->>'fulfillment', coalesce(dl->>'address_id', '-'), case when d->>'schedule' = 'asap' then 'asap' else d->>'requested_at' end,
  (select string_agg((i->>'product_id') || 'x' || (i->>'quantity'), ',' order by i->>'product_id') from jsonb_array_elements(d->'items') i)));
 perform pg_advisory_xact_lock(hashtextextended('dup:' || p_station_id || ':' || auth.uid() || ':' || fp, 0));
 select duplicate_minutes into dup_minutes from public.station_settings where station_id = p_station_id;
 select * into orig from public.orders where station_id = p_station_id and customer_id = auth.uid() and fingerprint = fp
  and status in ('on_hold','scheduled','confirmed','preparing','ready','out_for_delivery') and created_at >= now() - make_interval(mins => dup_minutes)
 order by created_at limit 1;
 st := case when orig.id is not null then 'on_hold' else d->>'initial_status' end;
 r := private.insert_order(p_station_id, auth.uid(), d, st, p_request_key, orig.id, fp, case when st = 'on_hold' then 'Possible repeat of ' || orig.order_no end);
 oid := (r->>'order_id')::uuid; no := r->>'order_no'; v := (r->>'version')::int;
 if st = 'on_hold' then
  perform private.notify(p_station_id, auth.uid(), 'order:' || oid || ':' || v, 'order_on_hold', oid, 'Please confirm order ' || no,
   'It matches order ' || orig.order_no || '. Confirm it if you want another, or cancel it.');
 else
  perform private.notify_station(p_station_id, 'order:' || oid || ':new', 'order_new', oid,
   case when st = 'scheduled' then 'New scheduled order ' else 'New order ' end || no,
   format('%s item(s), %s, %s.', d->>'total_units', d->>'fulfillment', case when d->>'schedule' = 'asap' then 'as soon as possible' else 'scheduled' end));
 end if;
 r := private.envelope(jsonb_build_object('order_id', oid, 'order_no', no, 'status', st, 'version', v, 'total_centavos', (d->>'total_centavos')::bigint,
  'duplicate_of', orig.id, 'duplicate_order_no', orig.order_no));
 return private.remember(p_station_id::text, 'submit_order', p_request_key, p_payload, r);
end $$;

create or replace function public.confirm_duplicate(p_order_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'expected_version', p_expected_version); o public.orders; sch jsonb; v int;
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
 sch := private.schedule_check(o.station_id, o.fulfillment, o.schedule_mode, to_char(o.requested_at at time zone 'Asia/Manila', 'YYYY-MM-DD"T"HH24:MI:SS'), now());
 if not (sch->>'ok')::boolean then return private.envelope('{}', sch->>'code', jsonb_build_object(sch->>'field', sch->>'message')); end if;
 update public.orders set duplicate_confirmed_at = now() where id = p_order_id;
 v := private.transition(p_order_id, sch->>'status', 'Customer confirmed an intentional repeat order', p_request_key);
 perform private.notify_station(o.station_id, 'order:' || p_order_id || ':new', 'order_new', p_order_id, 'New order ' || o.order_no, 'The customer confirmed this repeat order.');
 return private.remember(o.station_id::text, 'confirm_duplicate', p_request_key, payload,
  private.envelope(jsonb_build_object('order_id', p_order_id, 'status', sch->>'status', 'version', v)));
end $$;

-- Customer: on_hold, scheduled or confirmed before preparation. Owner: the same plus preparing, with a reason.
-- Phase 5 replaces this to add the owner's reviewed ready-order exception (D9) once trips and payments exist.
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
 if not ((is_customer and o.status in ('pending','on_hold','scheduled','confirmed')) or (is_owner and o.status in ('pending','on_hold','scheduled','confirmed','preparing'))) then
  return private.envelope(jsonb_build_object('status', o.status), 'invalid_transition', jsonb_build_object('order', 'This order can no longer be cancelled.'));
 end if;
 v := private.transition(p_order_id, 'cancelled', trim(p_reason), p_request_key);
 if is_customer and o.status <> 'on_hold' then
  perform private.notify_station(o.station_id, 'order:' || p_order_id || ':' || v, 'order_cancelled', p_order_id, 'Order ' || o.order_no || ' was cancelled', 'Customer reason: ' || trim(p_reason));
 end if;
 return private.remember(o.station_id::text, 'cancel_order', p_request_key, payload, private.envelope(jsonb_build_object('order_id', p_order_id, 'status', 'cancelled', 'version', v)));
end $$;

create or replace function public.reschedule_order(p_order_id uuid, p_requested_at timestamptz, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'requested_at', p_requested_at, 'expected_version', p_expected_version); o public.orders; sch jsonb; v int;
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
 update public.orders set schedule_mode = 'scheduled', requested_at = (sch->>'requested_at')::timestamptz, service_date = (sch->>'service_date')::date,
  version = version + 1, updated_at = now() where id = p_order_id returning version into v;
 if sch->>'status' <> o.status then v := private.transition(p_order_id, sch->>'status', 'Customer rescheduled the order', p_request_key); end if;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, before_values, after_values)
 values (o.station_id, auth.uid(), 'reschedule_order', p_order_id, p_request_key, jsonb_build_object('requested_at', o.requested_at), jsonb_build_object('requested_at', sch->'requested_at'));
 perform private.notify_station(o.station_id, 'order:' || p_order_id || ':' || v, 'order_rescheduled', p_order_id, 'Order ' || o.order_no || ' was rescheduled',
  'New time: ' || to_char((sch->>'requested_at')::timestamptz at time zone 'Asia/Manila', 'Mon DD, HH12:MI AM'));
 return private.remember(o.station_id::text, 'reschedule_order', p_request_key, payload, private.envelope(jsonb_build_object('order_id', p_order_id, 'status', sch->>'status', 'version', v)));
end $$;

create or replace function public.list_customer_orders(p_filters jsonb, p_cursor jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare g text := coalesce(p_filters->>'group', 'all'); rows jsonb;
begin
 perform private.customer_guard();
 if g not in ('all','active','completed','cancelled') then g := 'all'; end if;
 select coalesce(jsonb_agg(x.j order by x.created_at desc, x.id desc), '[]') into rows from (
  select o.created_at, o.id, jsonb_build_object('id', o.id, 'order_no', coalesce(o.order_no, 'Order ' || upper(left(o.id::text, 8))), 'station_id', o.station_id,
   'station_name', s.name, 'fulfillment', o.fulfillment, 'status', o.status, 'version', o.version, 'created_at', o.created_at,
   'requested_at', o.requested_at, 'schedule_mode', o.schedule_mode,
   'total_units', coalesce(o.total_units, (select sum(i.quantity) from public.order_items i where i.order_id = o.id)),
   'total_centavos', coalesce(o.total_centavos, (select sum(i.line_total_centavos) from public.order_items i where i.order_id = o.id))) j
  from public.orders o join public.stations s on s.id = o.station_id
  where o.customer_id = auth.uid()
   and (g = 'all' or (g = 'active' and o.status in ('pending','on_hold','scheduled','confirmed','preparing','ready','out_for_delivery'))
    or (g = 'completed' and o.status in ('delivered','collected','completed')) or (g = 'cancelled' and o.status = 'cancelled'))
   and (p_cursor->>'before' is null or (o.created_at, o.id) < ((p_cursor->>'before')::timestamptz, (p_cursor->>'id')::uuid))
  order by o.created_at desc, o.id desc limit 50) x;
 return private.envelope(jsonb_build_object('orders', rows));
end $$;

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
  'can_cancel', o.status in ('pending','on_hold','scheduled','confirmed'), 'can_confirm_duplicate', o.status = 'on_hold',
  'can_reschedule', o.status in ('scheduled','confirmed')));
end $$;

-- Notifications: always the caller's own. Null station = customer inbox; a station = that station's staff notices.
create or replace function private.notice_scope(sid uuid) returns void language plpgsql stable security definer set search_path = '' as $$
begin
 if auth.uid() is null or private.has_role('super_admin') then raise exception 'Notification access denied.' using errcode = '42501'; end if;
 if sid is not null and not private.station_admin(sid) then raise exception 'Notification access denied.' using errcode = '42501'; end if;
end $$;

create or replace function public.list_notifications(p_station_id uuid, p_cursor jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare rows jsonb; unread int;
begin
 perform private.notice_scope(p_station_id);
 select coalesce(jsonb_agg(x.j order by x.created_at desc, x.id desc), '[]') into rows from (
  select n.created_at, n.id, jsonb_build_object('id', n.id, 'kind', n.kind, 'title', n.title, 'body', n.body, 'order_id', n.order_id,
   'order_no', o.order_no, 'station_name', s.name, 'created_at', n.created_at, 'read_at', n.read_at) j
  from public.notifications n join public.stations s on s.id = n.station_id left join public.orders o on o.id = n.order_id
  where n.recipient_id = auth.uid() and ((p_station_id is null and n.kind not like 'staff\_%') or (n.station_id = p_station_id and n.kind like 'staff\_%'))
   and (p_cursor->>'before' is null or (n.created_at, n.id) < ((p_cursor->>'before')::timestamptz, (p_cursor->>'id')::uuid))
  order by n.created_at desc, n.id desc limit 50) x;
 select count(*) into unread from public.notifications n where n.recipient_id = auth.uid() and n.read_at is null
  and ((p_station_id is null and n.kind not like 'staff\_%') or (n.station_id = p_station_id and n.kind like 'staff\_%'));
 return private.envelope(jsonb_build_object('notifications', rows, 'unread', unread));
end $$;

create or replace function public.mark_notification_read(p_notification_id uuid, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
begin
 perform private.notice_scope(null);
 update public.notifications set read_at = coalesce(read_at, now()) where id = p_notification_id and recipient_id = auth.uid();
 return private.envelope(jsonb_build_object('id', p_notification_id));
end $$;

create or replace function public.mark_all_notifications_read(p_cutoff timestamptz, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare n int;
begin
 perform private.notice_scope(null);
 update public.notifications set read_at = now() where recipient_id = auth.uid() and read_at is null and created_at <= least(coalesce(p_cutoff, now()), now());
 get diagnostics n = row_count;
 return private.envelope(jsonb_build_object('marked', n));
end $$;

-- Unread badges. Phase 7 replaces the message count with read-cursor accounting.
create or replace function public.get_navigation_counts(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare notes int; msgs int;
begin
 perform private.notice_scope(p_station_id);
 select count(*) into notes from public.notifications n where n.recipient_id = auth.uid() and n.read_at is null
  and ((p_station_id is null and n.kind not like 'staff\_%') or (n.station_id = p_station_id and n.kind like 'staff\_%'));
 select count(*) into msgs from public.messages m join public.conversations c on c.id = m.conversation_id
  left join public.read_receipts rr on rr.conversation_id = c.id and rr.user_id = auth.uid()
  where m.sender_id <> auth.uid() and m.created_at > coalesce(rr.read_at, '-infinity'::timestamptz)
   and ((p_station_id is null and c.customer_id = auth.uid()) or (c.station_id = p_station_id and m.sender_id = c.customer_id));
 return private.envelope(jsonb_build_object('notifications', notes, 'messages', msgs));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private' and p.proname in
  ('customer_guard','replay','remember','haversine_km','fee_for','uuid_text','delivery_terms','schedule_check','price_order','status_message','notify','notify_station','transition','notice_scope','insert_order') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in
  ('quote_order','quote_delivery','submit_order','confirm_duplicate','cancel_order','reschedule_order','list_customer_orders','get_customer_order',
   'list_notifications','mark_notification_read','mark_all_notifications_read','get_navigation_counts') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
