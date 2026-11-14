-- Phase 3: customer profile and preferences, owned addresses with optional Hamtic map pins and one default,
-- served barangays for the public station page, and the delivery quote for a saved address.
-- Requires Phase 2 (20261114155714_station_setup.sql). Paste the whole file into the Supabase SQL Editor.
-- Existing rows are kept: phones and names are copied from sign-up details, and each customer's first address becomes the default.
begin;

-- Shared helpers (later phases reuse them) -------------------------------------------------------------
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

create or replace function private.uuid_text(v text) returns boolean language sql immutable set search_path = '' as $$
 select coalesce(v, '') ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
$$;

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

-- Profiles ---------------------------------------------------------------------------------------------
alter table public.profiles add column if not exists phone text, add column if not exists order_updates boolean not null default true,
 add column if not exists station_updates boolean not null default true, add column if not exists version int not null default 1,
 add column if not exists updated_at timestamptz not null default now();
alter table public.profiles drop constraint if exists profiles_phone_check;
alter table public.profiles add constraint profiles_phone_check check (phone is null or phone ~ '^\+639[0-9]{9}$');
update public.profiles p set phone = u.raw_user_meta_data->>'phone' from auth.users u
 where u.id = p.id and p.phone is null and coalesce(u.raw_user_meta_data->>'phone', '') ~ '^\+639[0-9]{9}$';
update public.profiles p set display_name = left(trim(u.raw_user_meta_data->>'full_name'), 120) from auth.users u
 where u.id = p.id and p.display_name = '' and coalesce(trim(u.raw_user_meta_data->>'full_name'), '') <> '';

-- Addresses --------------------------------------------------------------------------------------------
alter table public.addresses add column if not exists barangay_id uuid references public.barangays(id),
 add column if not exists street text not null default '' check (length(street) <= 200),
 add column if not exists landmark text not null default '' check (length(landmark) <= 200),
 add column if not exists latitude numeric, add column if not exists longitude numeric,
 add column if not exists pin_source text check (pin_source in ('map','device','manual')),
 add column if not exists is_default boolean not null default false, add column if not exists deleted_at timestamptz,
 add column if not exists version int not null default 1, add column if not exists created_at timestamptz not null default now(),
 add column if not exists updated_at timestamptz not null default now();
-- D8 envelope. An address without a pin is allowed but can only be used for pickup.
alter table public.addresses drop constraint if exists addresses_pin_check;
alter table public.addresses add constraint addresses_pin_check check ((latitude is null and longitude is null and pin_source is null)
 or (latitude between 10.584854 and 10.746484 and longitude between 121.961241 and 122.073730 and pin_source is not null));
create unique index if not exists addresses_one_default_idx on public.addresses(customer_id) where is_default and deleted_at is null;
create index if not exists addresses_barangay_idx on public.addresses(barangay_id);
update public.addresses a set is_default = true
 where a.deleted_at is null and not exists (select 1 from public.addresses y where y.customer_id = a.customer_id and y.is_default and y.deleted_at is null)
  and a.id = (select x.id from public.addresses x where x.customer_id = a.customer_id and x.deleted_at is null order by x.created_at, x.id limit 1);

-- Writes now go through the RPCs below; station staff use order snapshots instead of the address book.
revoke insert, update, delete on public.addresses from authenticated;
drop policy if exists addresses_station_read on public.addresses;
drop policy if exists addresses_insert on public.addresses;
drop policy if exists addresses_update on public.addresses;
drop policy if exists addresses_delete on public.addresses;
drop policy if exists addresses_read on public.addresses;
create policy addresses_read on public.addresses for select to authenticated
 using (customer_id = (select auth.uid()) and deleted_at is null and private.has_role('customer') and not private.has_role('super_admin'));

-- Customers may see which barangays an approved station serves.
grant select on public.station_service_areas to anon, authenticated;
drop policy if exists service_areas_public_read on public.station_service_areas;
create policy service_areas_public_read on public.station_service_areas for select to anon, authenticated
 using (served and exists (select 1 from public.stations s where s.id = station_id and s.active and s.registration_status = 'approved'));

-- Customer RPCs ----------------------------------------------------------------------------------------
create or replace function public.save_customer_profile(p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; scope text := 'customer:' || auth.uid(); pf public.profiles; nm text := trim(coalesce(p_payload->>'display_name', '')); v int;
begin
 perform private.customer_guard();
 r := private.replay(scope, 'save_customer_profile', p_request_key, p_payload);
 if r is not null then return r; end if;
 if jsonb_typeof(p_payload) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p_payload) k where k not in ('display_name','phone','order_updates','station_updates')) then
  return private.envelope('{}', 'validation', jsonb_build_object('profile', 'Check the profile form.'));
 end if;
 if length(nm) not between 2 and 120 then return private.envelope('{}', 'validation', jsonb_build_object('display_name', 'Enter your name, 2 to 120 characters.')); end if;
 if coalesce(p_payload->>'phone', '') !~ '^\+639[0-9]{9}$' then return private.envelope('{}', 'validation', jsonb_build_object('phone', 'Enter a mobile number like 0917 123 4567.')); end if;
 if coalesce(p_payload->>'order_updates', '') not in ('true','false') or coalesce(p_payload->>'station_updates', '') not in ('true','false') then
  return private.envelope('{}', 'validation', jsonb_build_object('profile', 'Check the notification choices.'));
 end if;
 insert into public.profiles(id) values (auth.uid()) on conflict do nothing;
 select * into pf from public.profiles where id = auth.uid() for update;
 if pf.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', pf.version), 'stale_version'); end if;
 update public.profiles set display_name = nm, phone = p_payload->>'phone', order_updates = (p_payload->>'order_updates')::boolean,
  station_updates = (p_payload->>'station_updates')::boolean, version = version + 1, updated_at = now() where id = auth.uid() returning version into v;
 return private.remember(scope, 'save_customer_profile', p_request_key, p_payload, private.envelope(jsonb_build_object('version', v)));
end $$;

create or replace function public.save_customer_address(p_address_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; scope text := 'customer:' || auth.uid(); payload jsonb := jsonb_build_object('address_id', p_address_id, 'address', p_payload, 'expected_version', p_expected_version);
 a public.addresses; b public.barangays; lbl text; st text; lm text; lat numeric; lng numeric; src text; aid uuid := p_address_id; v int; make_default boolean;
begin
 perform private.customer_guard();
 r := private.replay(scope, 'save_customer_address', p_request_key, payload);
 if r is not null then return r; end if;
 if jsonb_typeof(p_payload) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p_payload) k where k not in ('label','barangay_id','street','landmark','latitude','longitude','pin_source','is_default')) then
  return private.envelope('{}', 'validation', jsonb_build_object('address', 'Check the address form.'));
 end if;
 lbl := trim(coalesce(p_payload->>'label', '')); st := trim(coalesce(p_payload->>'street', '')); lm := trim(coalesce(p_payload->>'landmark', ''));
 if length(lbl) not between 1 and 60 then return private.envelope('{}', 'validation', jsonb_build_object('label', 'Give the address a short name, like Home.')); end if;
 if length(st) not between 3 and 200 then return private.envelope('{}', 'validation', jsonb_build_object('street', 'Enter the street, purok or house details.')); end if;
 if length(lm) > 200 then return private.envelope('{}', 'validation', jsonb_build_object('landmark', 'Keep the landmark to 200 characters.')); end if;
 if private.uuid_text(p_payload->>'barangay_id') then select * into b from public.barangays where id = (p_payload->>'barangay_id')::uuid and active; end if;
 if b.id is null then return private.envelope('{}', 'validation', jsonb_build_object('barangay_id', 'Choose a Hamtic barangay.')); end if;
 if coalesce(p_payload->>'latitude', '') = '' and coalesce(p_payload->>'longitude', '') = '' then
  lat := null; lng := null; src := null;
 else
  lat := round((p_payload->>'latitude')::numeric, 7); lng := round((p_payload->>'longitude')::numeric, 7);
  if lat is null or lng is null or lat not between 10.584854 and 10.746484 or lng not between 121.961241 and 122.073730 then
   return private.envelope('{}', 'validation', jsonb_build_object('latitude', 'Place the pin inside Hamtic.'));
  end if;
  src := case when p_payload->>'pin_source' in ('map','device','manual') then p_payload->>'pin_source' else 'manual' end;
 end if;
 make_default := coalesce(p_payload->>'is_default', 'false') = 'true';
 insert into public.profiles(id) values (auth.uid()) on conflict do nothing;
 perform 1 from public.profiles where id = auth.uid() for update; -- serializes default changes for this customer
 if aid is null then
  if p_expected_version is not null then return private.envelope('{}', 'validation', jsonb_build_object('address', 'A new address has no version.')); end if;
  if (select count(*) from public.addresses where customer_id = auth.uid() and deleted_at is null) >= 20 then
   return private.envelope('{}', 'validation', jsonb_build_object('address', 'You can keep up to 20 addresses.'));
  end if;
  make_default := make_default or not exists (select 1 from public.addresses where customer_id = auth.uid() and deleted_at is null);
  insert into public.addresses(customer_id, label, address, barangay_id, street, landmark, latitude, longitude, pin_source)
  values (auth.uid(), lbl, st || ', ' || b.name || ', Hamtic, Antique', b.id, st, lm, lat, lng, src) returning id into aid;
 else
  select * into a from public.addresses where id = aid and customer_id = auth.uid() and deleted_at is null for update;
  if not found then raise exception 'Address access denied.' using errcode = '42501'; end if;
  if a.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', a.version), 'stale_version'); end if;
  update public.addresses set label = lbl, address = st || ', ' || b.name || ', Hamtic, Antique', barangay_id = b.id, street = st, landmark = lm,
   latitude = lat, longitude = lng, pin_source = src, version = version + 1, updated_at = now() where id = aid;
 end if;
 if make_default then
  update public.addresses set is_default = false, updated_at = now() where customer_id = auth.uid() and is_default and id <> aid;
  update public.addresses set is_default = true where id = aid;
 end if;
 select version into v from public.addresses where id = aid;
 return private.remember(scope, 'save_customer_address', p_request_key, payload, private.envelope(jsonb_build_object('address_id', aid, 'version', v)));
exception when invalid_text_representation or numeric_value_out_of_range then
 return private.envelope('{}', 'validation', jsonb_build_object('latitude', 'Enter the pin as numbers.'));
end $$;

-- Soft delete keeps the row, so past orders and their snapshots stay intact.
create or replace function public.delete_customer_address(p_address_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; scope text := 'customer:' || auth.uid(); payload jsonb := jsonb_build_object('address_id', p_address_id, 'expected_version', p_expected_version); a public.addresses;
begin
 perform private.customer_guard();
 r := private.replay(scope, 'delete_customer_address', p_request_key, payload);
 if r is not null then return r; end if;
 insert into public.profiles(id) values (auth.uid()) on conflict do nothing;
 perform 1 from public.profiles where id = auth.uid() for update;
 select * into a from public.addresses where id = p_address_id and customer_id = auth.uid() and deleted_at is null for update;
 if not found then raise exception 'Address access denied.' using errcode = '42501'; end if;
 if a.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', a.version), 'stale_version'); end if;
 update public.addresses set deleted_at = now(), is_default = false, version = version + 1, updated_at = now() where id = a.id;
 if a.is_default then
  update public.addresses set is_default = true where id = (select id from public.addresses where customer_id = auth.uid() and deleted_at is null order by updated_at desc, id limit 1);
 end if;
 return private.remember(scope, 'delete_customer_address', p_request_key, payload, private.envelope(jsonb_build_object('address_id', a.id)));
end $$;

create or replace function public.set_default_address(p_address_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; scope text := 'customer:' || auth.uid(); payload jsonb := jsonb_build_object('address_id', p_address_id, 'expected_version', p_expected_version); a public.addresses;
begin
 perform private.customer_guard();
 r := private.replay(scope, 'set_default_address', p_request_key, payload);
 if r is not null then return r; end if;
 insert into public.profiles(id) values (auth.uid()) on conflict do nothing;
 perform 1 from public.profiles where id = auth.uid() for update;
 select * into a from public.addresses where id = p_address_id and customer_id = auth.uid() and deleted_at is null for update;
 if not found then raise exception 'Address access denied.' using errcode = '42501'; end if;
 if a.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', a.version), 'stale_version'); end if;
 update public.addresses set is_default = false, updated_at = now() where customer_id = auth.uid() and is_default and id <> a.id;
 update public.addresses set is_default = true where id = a.id;
 return private.remember(scope, 'set_default_address', p_request_key, payload, private.envelope(jsonb_build_object('address_id', a.id)));
end $$;

-- Distance, service area and fee for one owned address. Shared by quote_delivery and the Phase 4 pricing engine.
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

create or replace function public.quote_delivery(p_station_id uuid, p_address_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
begin
 perform private.customer_guard();
 if not exists (select 1 from public.stations where id = p_station_id and active and registration_status = 'approved') then return private.envelope('{}', 'not_found'); end if;
 return private.envelope(private.delivery_terms(p_station_id, p_address_id, auth.uid()) || jsonb_build_object('station_id', p_station_id, 'address_id', p_address_id));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private' and p.proname in
  ('customer_guard','replay','remember','uuid_text','haversine_km','fee_for','delivery_terms') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in
  ('save_customer_profile','save_customer_address','delete_customer_address','set_default_address','quote_delivery') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
