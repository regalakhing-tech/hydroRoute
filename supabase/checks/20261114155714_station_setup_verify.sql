-- Run after the Phase 2 migration. All statements are read-only.

select count(*) as official_barangays,
       count(*) filter (where municipality_code = '0600608000') as hamtic_barangays
from public.barangays;

-- Every approved station needs its settings, active fee version and availability.
select s.id, s.name,
       cfg.station_id is not null as has_settings,
       av.station_id is not null as has_availability,
       fees.station_id is not null as has_active_fee_version,
       (select count(*) from public.products p where p.station_id = s.id) as catalogue_rows
from public.stations s
left join public.station_settings cfg on cfg.station_id = s.id
left join public.station_availability av on av.station_id = s.id
left join public.delivery_fee_rule_sets fees
  on fees.station_id = cfg.station_id and fees.version = cfg.active_fee_version
where s.registration_status = 'approved'
order by s.id;

-- RLS must be enabled on every new public/private table.
select n.nspname as schema_name, c.relname, c.relrowsecurity,
       has_table_privilege('anon', c.oid, 'INSERT,UPDATE,DELETE') as anon_can_write,
       has_table_privilege('authenticated', c.oid, 'INSERT,UPDATE,DELETE') as user_can_write
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where (n.nspname, c.relname) in (
  ('public', 'barangays'), ('public', 'station_settings'),
  ('public', 'station_service_areas'), ('public', 'delivery_fee_rule_sets'),
  ('public', 'delivery_fee_rules'), ('public', 'station_opening_hours'),
  ('public', 'station_closures'), ('public', 'station_availability'),
  ('public', 'riders'), ('public', 'station_audit_events'),
  ('public', 'order_review_flags'), ('public', 'notifications'),
  ('private', 'station_membership_roles'), ('private', 'mutation_requests'),
  ('private', 'station_counters'), ('private', 'station_setup_installations')
)
order by n.nspname, c.relname;

-- Only the two public availability functions should allow visitor execution.
select p.oid::regprocedure as signature, p.prosecdef as security_definer,
       p.proconfig,
       has_function_privilege('anon', p.oid, 'EXECUTE') as visitor_execute,
       has_function_privilege('authenticated', p.oid, 'EXECUTE') as user_execute,
       has_function_privilege('service_role', p.oid, 'EXECUTE') as service_execute
from pg_proc p
join pg_namespace n on n.oid = p.pronamespace
where n.nspname = 'public'
  and p.proname in (
    'get_operation_context', 'get_station_availability', 'list_station_availability',
    'get_platform_station_registry', 'get_driver_registration_counts',
    'authorize_driver_credentials', 'save_station_profile', 'save_station_location',
    'save_station_service_areas', 'save_station_settings', 'save_delivery_fee_rules',
    'save_product', 'save_station_hours', 'set_station_closure',
    'cancel_station_closure', 'reopen_station', 'provision_station_member',
    'register_driver', 'session_read', 'session_write'
  )
order by p.proname;

select schemaname, tablename, policyname, roles, cmd, qual, with_check
from pg_policies
where (schemaname = 'public' and tablename in (
  'drivers', 'products', 'stations', 'station_settings', 'station_service_areas',
  'station_opening_hours', 'station_closures', 'station_availability',
  'delivery_fee_rule_sets', 'delivery_fee_rules', 'riders',
  'station_audit_events', 'order_review_flags', 'notifications'
)) or (schemaname = 'private' and tablename in (
  'station_membership_roles', 'mutation_requests', 'station_counters'
))
order by schemaname, tablename, policyname;

select migration_id, sql_sha256, installed_at
from private.station_setup_installations
where migration_id = '20261114155714_station_setup';

-- No scoped operational role may authorize a platform administrator.
select count(*) as platform_identities_with_active_operational_mapping
from private.station_membership_roles m
where m.active
  and exists (
    select 1 from private.user_roles r
    where r.user_id = m.user_id and r.role = 'super_admin'
  );

