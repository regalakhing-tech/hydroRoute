-- Removes the last "Guintas" and "Riverside" sample names from the owner logins (run after 2026-11-15-real-stations.sql).
--   guintas@gmail.com   -> frejch@gmail.com  (FREJCH - Water Refilling Station)
--   riverside@gmail.com -> altes@gmail.com   (Altes - Water Refilling Station)
-- Passwords are not changed here. The barangay "Guintas" (PSGC 0600608024) is a real Hamtic barangay and stays.
begin;

with m(id, email, name) as (values
 ('7073f777-681d-4d19-afa1-da88661a3ef2'::uuid, 'frejch@gmail.com', 'FREJCH Station Admin'),
 ('48dd2560-ea75-4c4d-a6e7-13b9efb016c7'::uuid, 'altes@gmail.com', 'Altes Station Admin'))
update auth.users u set email = m.email, raw_user_meta_data = coalesce(u.raw_user_meta_data, '{}'::jsonb) || jsonb_build_object('full_name', m.name), updated_at = now()
from m where u.id = m.id;

update auth.identities i set identity_data = i.identity_data || jsonb_build_object('email', u.email), updated_at = now()
from auth.users u where i.user_id = u.id and i.provider = 'email' and u.id in ('7073f777-681d-4d19-afa1-da88661a3ef2', '48dd2560-ea75-4c4d-a6e7-13b9efb016c7');

update public.profiles p set display_name = u.raw_user_meta_data->>'full_name'
from auth.users u where p.id = u.id and u.id in ('7073f777-681d-4d19-afa1-da88661a3ef2', '48dd2560-ea75-4c4d-a6e7-13b9efb016c7');

update public.notifications set title = replace(title, 'Guintas Water Station', 'FREJCH - Water Refilling Station')
 where title like '%Guintas Water Station%';

select u.email, u.raw_user_meta_data->>'full_name' as name, s.name as station
from auth.users u join private.user_roles r on r.user_id = u.id and r.role = 'station_admin'
left join private.station_memberships m on m.user_id = u.id and m.active left join public.stations s on s.id = m.station_id
order by s.name;
commit;
