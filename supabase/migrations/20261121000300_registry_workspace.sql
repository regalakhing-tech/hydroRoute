-- Super Admin registry pages: one read that returns only the fields those pages show, plus saving the
-- administrator's own profile. Limited by design: no orders, payments, customers, addresses, messages,
-- driver emails or plate numbers. Station applications are still read from station_registrations (RLS).
create or replace function public.get_registry_workspace() returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare stations jsonb; drivers jsonb; activity jsonb; me jsonb;
begin
 if not private.has_role('super_admin') then raise exception 'Registry access denied.' using errcode = '42501'; end if;
 -- Public availability label (the same one customers see) for listed stations only.
 select coalesce(jsonb_agg(jsonb_build_object('id', s.id,
   'availability', case when s.registration_status = 'approved' and s.active then private.availability(s.id, now())->>'label' end) order by s.name, s.id), '[]')
  into stations from public.stations s;
 select coalesce(jsonb_agg(jsonb_build_object('name', d.full_name, 'station', s.name, 'phone', d.phone, 'vehicle', d.vehicle,
   'active', d.active, 'created_at', d.created_at) order by d.created_at desc, d.user_id), '[]')
  into drivers from public.drivers d join public.stations s on s.id = d.station_id;
 select coalesce(jsonb_agg(jsonb_build_object('at', u.at, 'event', u.event, 'station', u.station, 'type', u.kind) order by u.at desc), '[]') into activity
  from (select e.created_at as at, case e.action when 'save_station_profile' then 'Station profile updated'
          when 'save_station_location' then 'Station location updated' else 'Service areas updated' end as event, s.name as station, 'Profile' as kind
        from public.station_audit_events e join public.stations s on s.id = e.station_id
        where e.action in ('save_station_profile', 'save_station_location', 'save_station_service_areas')
        union all
        select d.created_at, 'Driver registration added', s.name, 'Driver' from public.drivers d join public.stations s on s.id = d.station_id
        order by 1 desc limit 50) u;
 select jsonb_build_object('display_name', p.display_name, 'phone', p.phone, 'version', p.version) into me from public.profiles p where p.id = auth.uid();
 return private.envelope(jsonb_build_object('stations', stations, 'drivers', drivers, 'activity', activity,
  'me', coalesce(me, jsonb_build_object('display_name', '', 'phone', null, 'version', 1))));
end $$;

create or replace function public.save_registry_profile(p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; scope text := 'registry:' || auth.uid(); pf public.profiles; nm text := trim(coalesce(p_payload->>'display_name', '')); v int;
begin
 if not private.has_role('super_admin') then raise exception 'Registry access denied.' using errcode = '42501'; end if;
 r := private.replay(scope, 'save_registry_profile', p_request_key, p_payload);
 if r is not null then return r; end if;
 if jsonb_typeof(p_payload) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p_payload) k where k not in ('display_name', 'phone')) then
  return private.envelope('{}', 'validation', jsonb_build_object('profile', 'Check the profile form.'));
 end if;
 if length(nm) not between 2 and 120 then return private.envelope('{}', 'validation', jsonb_build_object('display_name', 'Enter your name, 2 to 120 characters.')); end if;
 if coalesce(p_payload->>'phone', '') !~ '^\+639[0-9]{9}$' then return private.envelope('{}', 'validation', jsonb_build_object('phone', 'Enter a mobile number like 0917 123 4567.')); end if;
 insert into public.profiles(id) values (auth.uid()) on conflict do nothing;
 select * into pf from public.profiles where id = auth.uid() for update;
 if pf.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', pf.version), 'stale_version'); end if;
 update public.profiles set display_name = nm, phone = p_payload->>'phone', version = version + 1, updated_at = now() where id = auth.uid() returning version into v;
 -- The name shown after the next login comes from the account metadata.
 update auth.users set raw_user_meta_data = coalesce(raw_user_meta_data, '{}'::jsonb) || jsonb_build_object('full_name', nm) where id = auth.uid();
 return private.remember(scope, 'save_registry_profile', p_request_key, p_payload, private.envelope(jsonb_build_object('version', v)));
end $$;

revoke all on function public.get_registry_workspace(), public.save_registry_profile(jsonb, int, uuid) from public, anon;
grant execute on function public.get_registry_workspace(), public.save_registry_profile(jsonb, int, uuid) to authenticated;
