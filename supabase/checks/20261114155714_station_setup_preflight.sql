-- Run in Supabase SQL Editor before the Phase 2 migration.
-- Every statement is read-only. Keep these results with the migration checkpoint.
-- Ambiguous legacy identities remain unauthorized until explicitly provisioned.
-- The migration maps a sole owner role with one membership, or a registered rider
-- with a matching driver station. It never infers an owner role across stations.
with operational_roles as (
  select user_id, array_agg(role order by role) as roles
  from private.user_roles
  where role in ('station_admin', 'rider', 'super_admin')
  group by user_id
), membership_counts as (
  select user_id, count(*) as station_count
  from private.station_memberships
  group by user_id
), ambiguous_roles as (
select r.user_id, r.roles, coalesce(m.station_count, 0) as station_count
from operational_roles r
left join membership_counts m using (user_id)
where cardinality(r.roles) > 1
   or ('station_admin' = any(r.roles) and coalesce(m.station_count, 0) <> 1)
order by r.user_id
), unusual_hours as (

-- Inspect unusual legacy schedules before converting them to weekly windows.
select id, name, weekday_open, weekday_close, sunday_open, sunday_close
from public.stations
where weekday_open >= weekday_close
   or (sunday_open is null) <> (sunday_close is null)
   or sunday_open >= sunday_close
order by id
), duplicate_products as (

-- Existing product rows and prices are preserved. Duplicate default names need
-- review so an owner can distinguish the intended catalogue item afterward.
select station_id, name, count(*) as matching_rows
from public.products
where name in (
  'Water container refill (18.9 L)', 'Small water container refill (9.5 L)',
  'Water jug with faucet refill', 'New water jug with faucet',
  'Bottled water 350 ml', 'Bottled water 500 ml', 'Bottled water 1 L'
)
group by station_id, name
having count(*) > 1
order by station_id, name
)
select jsonb_build_object(
  'stations', to_regclass('public.stations'),
  'drivers', to_regclass('public.drivers'),
  'shared_sessions', to_regclass('public.app_sessions'),
  'access_rpc', to_regprocedure('public.my_access()'),
  'session_read_rpc', to_regprocedure('public.session_read(text)'),
  'approved_stations', (select count(*) from public.stations where registration_status = 'approved'),
  'ambiguous_roles', coalesce((select jsonb_agg(to_jsonb(r)) from ambiguous_roles r), '[]'::jsonb),
  'unusual_hours', coalesce((select jsonb_agg(to_jsonb(h)) from unusual_hours h), '[]'::jsonb),
  'duplicate_products', coalesce((select jsonb_agg(to_jsonb(p)) from duplicate_products p), '[]'::jsonb)
) as preflight;
