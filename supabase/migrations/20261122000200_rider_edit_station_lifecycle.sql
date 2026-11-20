-- M13 and M17: station admins edit a rider's name, phone, vehicle, capacity and status; the Super Admin suspends,
-- removes (archives) or reactivates a registered station. Requires 20261122000100_batch_slots_capacity_cancel.sql.
-- Paste the whole file into the Supabase SQL Editor.
begin;

-- M13 ---------------------------------------------------------------------------------------------------
-- Drivers page: one row per registered driver with the rider record to edit and any active trip.
create or replace function public.get_station_riders(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
begin
 perform private.owner_check(p_station_id);
 return private.envelope(jsonb_build_object('riders', coalesce((select jsonb_agg(jsonb_build_object(
   'user_id', d.user_id, 'rider_id', r.id, 'version', r.version, 'full_name', d.full_name, 'phone', d.phone, 'email', d.email,
   'vehicle', coalesce(r.vehicle, d.vehicle), 'plate_number', coalesce(r.plate_number, d.plate_number), 'capacity', coalesce(r.capacity, d.capacity),
   'enabled', coalesce(r.enabled, true) and d.active, 'trip_id', t.id, 'trip_no', t.trip_no) order by d.full_name, d.user_id)
  from public.drivers d
  left join public.riders r on r.user_id = d.user_id and r.station_id = d.station_id
  left join public.delivery_trips t on t.rider_id = r.id and t.station_id = d.station_id and t.status = 'active'
  where d.station_id = p_station_id), '[]'::jsonb)));
end $$;

-- Replaces 20261120000200: also edits the station-local name and phone. Omitted keys keep their values. Disabling a
-- rider stops future dispatch; a trip already under way finishes normally. Trip capacity snapshots never change.
create or replace function public.save_rider(p_rider_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rd public.riders; dr public.drivers; r jsonb; payload jsonb := jsonb_build_object('rider_id', p_rider_id, 'payload', p_payload, 'expected_version', p_expected_version);
 v int; nm text; ph text;
begin
 select * into rd from public.riders where id = p_rider_id;
 if not found or not private.station_admin(rd.station_id) then raise exception 'Rider access denied.' using errcode = '42501'; end if;
 r := private.replay(rd.station_id::text, 'save_rider', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(rd.station_id);
 select * into rd from public.riders where id = p_rider_id for update;
 if rd.version is distinct from p_expected_version then return private.envelope('{}', 'stale_version'); end if;
 select * into dr from public.drivers where user_id = rd.user_id and station_id = rd.station_id for update;
 if jsonb_typeof(p_payload) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p_payload) k where k not in ('vehicle','plate_number','capacity','enabled','full_name','phone')) then
  return private.envelope('{}', 'validation', jsonb_build_object('rider', 'Check the rider details.'));
 end if;
 if coalesce(p_payload->>'vehicle', '') not in ('Motorcycle with sidecar','Tricycle','Delivery van') or length(trim(coalesce(p_payload->>'plate_number', ''))) not between 2 and 20
   or coalesce(p_payload->>'capacity', '') !~ '^[0-9]{1,3}$' or (p_payload->>'capacity')::int not between 1 and 100 or coalesce(p_payload->>'enabled', '') not in ('true','false') then
  return private.envelope('{}', 'validation', jsonb_build_object('rider', 'Check the vehicle, plate number and capacity (1 to 100).'));
 end if;
 nm := case when p_payload ? 'full_name' then regexp_replace(trim(coalesce(p_payload->>'full_name', '')), '\s+', ' ', 'g') else dr.full_name end;
 ph := case when p_payload ? 'phone' then trim(coalesce(p_payload->>'phone', '')) else dr.phone end;
 if p_payload ? 'full_name' and length(coalesce(nm, '')) not between 2 and 120 then
  return private.envelope('{}', 'validation', jsonb_build_object('full_name', 'Enter the rider''s full name (2 to 120 characters).'));
 end if;
 if p_payload ? 'phone' and coalesce(ph, '') !~ '^\+639[0-9]{9}$' then
  return private.envelope('{}', 'validation', jsonb_build_object('phone', 'Enter a mobile number such as 0917 123 4567.'));
 end if;
 update public.riders set vehicle = p_payload->>'vehicle', plate_number = upper(trim(p_payload->>'plate_number')), capacity = (p_payload->>'capacity')::int,
  enabled = (p_payload->>'enabled')::boolean, version = version + 1 where id = rd.id returning version into v;
 update public.drivers set vehicle = p_payload->>'vehicle', plate_number = upper(trim(p_payload->>'plate_number')), capacity = (p_payload->>'capacity')::int,
  active = (p_payload->>'enabled')::boolean, full_name = coalesce(nm, full_name), phone = coalesce(ph, phone)
 where user_id = rd.user_id and station_id = rd.station_id;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, before_values, after_values)
 values (rd.station_id, auth.uid(), 'save_rider', rd.id, p_request_key,
  jsonb_build_object('full_name', dr.full_name, 'phone', dr.phone, 'vehicle', rd.vehicle, 'plate_number', rd.plate_number, 'capacity', rd.capacity, 'enabled', rd.enabled), p_payload);
 return private.remember(rd.station_id::text, 'save_rider', p_request_key, payload, private.envelope(jsonb_build_object('rider_id', rd.id, 'version', v)));
end $$;

-- M17 ---------------------------------------------------------------------------------------------------
alter table public.stations add column if not exists status_reason text check (length(status_reason) <= 500),
 add column if not exists status_changed_at timestamptz, add column if not exists status_changed_by uuid references auth.users(id);

-- Suspended: hidden from customers and closed to new orders (price_order and public reads require 'approved'), while
-- the owner and riders keep their workspace to finish open orders. Archived ("removed"): only once nothing is open;
-- staff memberships for the station end at once and its riders are disabled. Orders, payments, accounts and history
-- are kept. Removal is final: a closed business registers again to return.
create or replace function public.set_station_status(p_station_id uuid, p_status text, p_expected_status text, p_reason text, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare st public.stations; r jsonb; payload jsonb := jsonb_build_object('station_id', p_station_id, 'status', p_status, 'expected_status', p_expected_status, 'reason', p_reason);
begin
 if auth.uid() is null or not private.has_role('super_admin') then raise exception 'Registry access denied.' using errcode = '42501'; end if;
 r := private.replay('registry', 'set_station_status', p_request_key, payload);
 if r is not null then return r; end if;
 perform 1 from private.user_roles where user_id = auth.uid() and role = 'super_admin' for share;
 select * into st from public.stations where id = p_station_id for update;
 if not found then return private.envelope('{}', 'not_found', jsonb_build_object('station', 'This station no longer exists.')); end if;
 if p_status is null or p_status not in ('approved','suspended','archived') then
  return private.envelope('{}', 'validation', jsonb_build_object('status', 'Choose suspend, remove or reactivate.'));
 end if;
 if length(trim(coalesce(p_reason, ''))) not between 3 and 500 then
  return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Write a reason (3 to 500 characters). It is kept with the station record.'));
 end if;
 if st.registration_status is distinct from p_expected_status then
  return private.envelope(jsonb_build_object('status', st.registration_status), 'stale_version', jsonb_build_object('status', 'This station changed a moment ago. Review it and try again.'));
 end if;
 if st.registration_status not in ('approved','suspended') or st.registration_status = p_status then
  return private.envelope(jsonb_build_object('status', st.registration_status), 'invalid_transition', jsonb_build_object('status', case when st.registration_status = 'archived'
   then 'This station was removed. The business must register again to return.' else 'Only approved or suspended stations can change status here.' end));
 end if;
 if p_status = 'archived' and (
   exists (select 1 from public.orders o where o.station_id = p_station_id and o.status not in ('completed','cancelled'))
   or exists (select 1 from public.delivery_trips t where t.station_id = p_station_id and t.status = 'active')
   or exists (select 1 from public.reservations x where x.station_id = p_station_id and x.state = 'requested')) then
  return private.envelope('{}', 'station_busy', jsonb_build_object('status', 'This station still has open orders, trips or reservation requests. Suspend it first so the owner can finish them, then remove it.'));
 end if;
 update public.stations set registration_status = p_status, active = (p_status <> 'archived'), status_reason = trim(p_reason),
  status_changed_at = now(), status_changed_by = auth.uid() where id = p_station_id;
 if p_status = 'archived' then
  update public.riders set enabled = false, version = version + 1 where station_id = p_station_id and enabled;
  update public.drivers set active = false where station_id = p_station_id and active;
  update private.station_memberships set active = false where station_id = p_station_id and active;
  update private.station_membership_roles set active = false where station_id = p_station_id and active;
 end if;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, reason, before_values, after_values)
 values (p_station_id, auth.uid(), 'set_station_status', p_station_id, p_request_key, trim(p_reason),
  jsonb_build_object('status', st.registration_status), jsonb_build_object('status', p_status));
 return private.remember('registry', 'set_station_status', p_request_key, payload, private.envelope(jsonb_build_object('station_id', p_station_id, 'status', p_status)));
end $$;

-- Replaces 20261114120000: a suspended station stays in its owner's and riders' workspace (to finish open work);
-- archived, rejected and pending stations do not. 'status' lets the workspace show a notice.
create or replace function public.my_access() returns jsonb language sql stable security definer set search_path = '' as $$
  select jsonb_build_object(
    'roles', coalesce((select jsonb_agg(r.role order by r.role) from private.user_roles r where r.user_id = auth.uid()), '[]'::jsonb),
    'stations', coalesce((select jsonb_agg(jsonb_build_object('id', s.id, 'name', s.name, 'slug', s.slug, 'status', s.registration_status) order by s.name)
      from private.station_memberships m join public.stations s on s.id = m.station_id
      where m.user_id = auth.uid() and m.active and s.active and s.registration_status in ('approved','suspended')), '[]'::jsonb))
$$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in
  ('get_station_riders','save_rider','set_station_status','my_access') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
