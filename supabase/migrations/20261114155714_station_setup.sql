-- Phase 2 station setup: paste the entire file into Supabase SQL Editor.
-- The protected marker makes an exact rerun a no-op. Changed SQL with the same ID fails closed.
-- All initialization and the marker commit together. No owner prices or historical order values reset.
begin;
select pg_advisory_xact_lock(hashtextextended('20261114155714_station_setup', 0));
create table if not exists private.station_setup_installations (
 migration_id text primary key,
 sql_sha256 text not null check (sql_sha256 ~ '^[0-9a-f]{64}$'),
 installed_at timestamptz not null default now()
);
alter table private.station_setup_installations enable row level security;
revoke all on private.station_setup_installations from public, anon, authenticated, service_role;
do $install_guard$
declare
 migration_name constant text := '20261114155714_station_setup';
 migration_sql constant text := $phase2_sql$
-- Phase 2. Self-contained SQL Editor migration; no remote side effects.
-- Reference: PSA Hamtic 0600608000, retrieved 2026-11-14, CC BY 4.0.
create table public.barangays (
 id uuid primary key default gen_random_uuid(),  psgc_code text not null unique check(psgc_code ~
 '^0600608[0-9]{3}$'),
 municipality_code text not null default '0600608000' check(municipality_code='0600608000'),  name text not null,
 active boolean not null default true,  source text not null default
 'https://psa.gov.ph/classification/psgc/barangays/0600608000',
 retrieved_on date not null default '2026-11-14',  unique(id, municipality_code)
);
insert into public.barangays(psgc_code, name) values
 ('0600608001', 'Apdo'),
 ('0600608002', 'Asluman'),
 ('0600608003', 'Banawon'),
 ('0600608005', 'Bia-an'),
 ('0600608006', 'Bongbongan I-II'),
 ('0600608008', 'Bongbongan III'),
 ('0600608009', 'Botbot'),
 ('0600608010', 'Budbudan'),
 ('0600608011', 'Buhang'),
 ('0600608012', 'Calacja I'),
 ('0600608013', 'Calacja II'),
 ('0600608014', 'Calala'),
 ('0600608015', 'Cantulan'),
 ('0600608016', 'Caridad'),
 ('0600608017', 'Caromangay'),
 ('0600608018', 'Casalngan'),
 ('0600608019', 'Dangcalan'),
 ('0600608020', 'Del Pilar'),
 ('0600608021', 'Fabrica'),
 ('0600608022', 'Funda'),
 ('0600608023', 'General Fullon'),
 ('0600608024', 'Guintas'),
 ('0600608025', 'Igbical'),
 ('0600608026', 'Igbucagay'),
 ('0600608027', 'Inabasan'),
 ('0600608028', 'Ingwan-Batangan'),
 ('0600608029', 'La Paz'),
 ('0600608030', 'Gov. Evelio B. Javier'),
 ('0600608031', 'Linaban'),
 ('0600608033', 'Malandog'),
 ('0600608034', 'Mapatag'),
 ('0600608035', 'Masanag'),
 ('0600608036', 'Nalihawan'),
 ('0600608037', 'Pamandayan'),
 ('0600608038', 'Pasu-Jungao'),
 ('0600608039', 'Piapi I'),
 ('0600608040', 'Piapi II'),
 ('0600608041', 'Piapi III'),
 ('0600608042', 'Pili 1, 2, 3'),
 ('0600608045', 'Poblacion 1'),
 ('0600608046', 'Poblacion 2'),
 ('0600608047', 'Poblacion 3'),
 ('0600608048', 'Poblacion 4'),
 ('0600608049', 'Poblacion 5'),
 ('0600608050', 'Pu-ao'),
 ('0600608051', 'Suloc'),
 ('0600608053', 'Villavert-Jimenez');
alter table public.stations add column description text not null default '' check(length(description)<=2000),
 add column contact_name text not null default '' check(length(contact_name)<=120),
 add column municipality_code text not null default '0600608000' check(municipality_code='0600608000'),
 add column barangay_id uuid,  add column latitude numeric,  add column longitude numeric,
 add column profile_version integer not null default 1 check(profile_version>0),
 add column location_version integer not null default 1 check(location_version>0),
 add column updated_at timestamptz not null default now(),
 add foreign key(barangay_id, municipality_code) references public.barangays(id, municipality_code),
 add check((latitude is null and longitude is null) or (latitude is not null and longitude is not null and latitude between 10.584854 and 10.746484 and longitude
 between 121.961241 and 122.073730));
update public.stations s set barangay_id=b.id from public.barangays b where lower(trim(s.barangay))=lower(b.name);
create table private.station_membership_roles (
 station_id uuid not null,  user_id uuid not null,  role text not null check(role in('station_admin', 'rider')),
 active boolean not null default true,  actor_id uuid references auth.users(id),  updated_at timestamptz not null
 default now(), version int not null default 1,
 primary key(station_id, user_id, role),  foreign key(user_id, station_id) references
 private.station_memberships(user_id, station_id) on delete cascade
);
create index station_membership_roles_actor_idx on private.station_membership_roles(user_id, role, active,
station_id);
-- Only a single protected operational global role may be mapped. Customer bootstrap is not an operational role.
insert into private.station_membership_roles(station_id, user_id, role, active)
 select m.station_id, m.user_id, r.role, m.active from private.station_memberships m join private.user_roles r on
 r.user_id=m.user_id
 where r.role in('station_admin', 'rider') and not exists(select 1 from private.user_roles x where
 x.user_id=m.user_id and x.role='super_admin')
 and (select count(*) from private.user_roles x where x.user_id=m.user_id and x.role in('station_admin',
 'rider'))=1
 and ((r.role='station_admin' and (select count(*) from private.station_memberships x where x.user_id=m.user_id)=1)
 or (r.role='rider' and exists(select 1 from public.drivers d where d.user_id=m.user_id and
 d.station_id=m.station_id))) on conflict do nothing;
create or replace function private.station_admin(sid uuid) returns boolean language sql stable security definer set
search_path='' as $$
 select auth.uid() is not null and not private.has_role('super_admin') and private.has_role('station_admin') and
 exists(
 select 1 from private.station_memberships m join private.station_membership_roles r on (r.user_id,
 r.station_id)=(m.user_id, m.station_id)
 where m.user_id=auth.uid() and m.station_id=sid and m.active and r.active and r.role='station_admin')
$$;
create table public.station_settings (
 station_id uuid primary key references public.stations(id),  version int not null default 1 check(version>0),
 max_order_units int not null default 20 check(max_order_units between 1 and 1000),  max_distance_km numeric not
 null default 10 check(max_distance_km>0 and max_distance_km<=100),
 booking_days int not null default 7 check(booking_days between 1 and 7),  duplicate_minutes int not null default
 30 check(duplicate_minutes between 1 and 1440),
 overdue_minutes int not null default 120 check(overdue_minutes between 1 and 1440), institution_bonus_enabled
 boolean not null default false,
 urgency_asap int not null default 40 check(urgency_asap between 0 and 1000), urgency_soon int not null default 25
 check(urgency_soon between 0 and 1000), urgency_later int not null default 10 check(urgency_later between 0 and
 1000),
 urgency_immediate_minutes int not null default 60 check(urgency_immediate_minutes between 1 and 1440),
 urgency_soon_minutes int not null default 180 check(urgency_soon_minutes between 1 and 1440),
 aging_interval_minutes int not null default 10 check(aging_interval_minutes between 1 and 1440), aging_points int
 not null default 5 check(aging_points between 0 and 1000), aging_cap int not null default 25 check(aging_cap
 between 0 and 1000),
 institution_bonus int not null default 10 check(institution_bonus between 0 and 1000), active_fee_version int not
 null default 1, algorithm_version text not null default 'priority-v1',
 check(urgency_immediate_minutes<urgency_soon_minutes)
);
create table public.station_service_areas (station_id uuid not null references public.stations(id), barangay_id
uuid not null references public.barangays(id), served boolean not null default true, actor_id uuid references
auth.users(id), updated_at timestamptz not null default now(), primary key(station_id, barangay_id));
create index station_service_areas_barangay_idx on public.station_service_areas(barangay_id);
create table public.delivery_fee_rule_sets(station_id uuid not null references public.stations(id), version int not
null check(version>0), effective_at timestamptz not null default now(), creator_id uuid references auth.users(id),
max_distance_km numeric not null check(max_distance_km>0 and max_distance_km<=100), algorithm_version text not null
default 'distance-v1', primary key(station_id, version));
create table public.delivery_fee_rules(station_id uuid not null, version int not null, band_index int not null,
min_km numeric not null check(min_km>=0), max_km numeric, min_inclusive boolean not null, max_inclusive boolean not
null, fee_centavos int not null check(fee_centavos between 0 and 100000000), primary key(station_id, version,
band_index), foreign key(station_id, version) references public.delivery_fee_rule_sets(station_id, version),
check(max_km is null or max_km>min_km));
alter table public.station_settings add foreign key(station_id, active_fee_version) references
public.delivery_fee_rule_sets(station_id, version);
create table public.station_opening_hours(id uuid primary key default gen_random_uuid(), station_id uuid not null
references public.stations(id), weekday int not null check(weekday between 0 and 6), window_no int not null
check(window_no between 1 and 2), opens_at time not null, closes_at time not null, order_cutoff time,
delivery_cutoff time, enabled boolean not null default true, unique(station_id, weekday, window_no),
check(opens_at<closes_at), check(order_cutoff is null or (order_cutoff>=opens_at and order_cutoff<closes_at)),
check(delivery_cutoff is null or (delivery_cutoff>=opens_at and delivery_cutoff<closes_at)));
create table public.station_closures(id uuid primary key default gen_random_uuid(), station_id uuid not null
references public.stations(id), starts_at timestamptz not null, ends_at timestamptz, mode text not null check(mode
in('closed', 'maintenance', 'no_operations')), internal_reason text, public_notice text not null default ''
check(length(public_notice)<=200), is_manual boolean not null default false, actor_id uuid references
auth.users(id), cancelled_at timestamptz, cancelled_by uuid references auth.users(id), cancel_reason text, version
int not null default 1, unique(id, station_id), check(isfinite(starts_at) and (ends_at is null or (isfinite(ends_at) and ends_at>starts_at))), check(is_manual or
ends_at is not null));
create index station_closures_interval_idx on public.station_closures(station_id, starts_at, ends_at) where
cancelled_at is null;
create table public.station_availability(station_id uuid primary key references public.stations(id), manual_mode
text check(manual_mode in('closed', 'maintenance', 'no_operations')), begins_at timestamptz, expires_at
timestamptz, public_notice text not null default '' check(length(public_notice)<=200), version int not null default
1 check(version>0));
alter table public.products add column default_code text, add column pack_text text not null default ''
check(length(pack_text)<=120), add column description text not null default '' check(length(description)<=2000),
add column image_key text not null default 'water-jug' check(image_key in('water-jug', 'faucet-container',
'water-bottle')), add column returnable boolean not null default false, add column load_units int check(load_units
between 1 and 10000), add column version int not null default 1, add column updated_at timestamptz not null default
now();
create unique index products_default_code_idx on public.products(station_id, default_code) where default_code is
not null;
create table public.riders(id uuid primary key default gen_random_uuid(), station_id uuid not null references
public.stations(id), user_id uuid not null references auth.users(id), vehicle text not null check(vehicle
in('Motorcycle with sidecar', 'Tricycle', 'Delivery van')), plate_number text not null, capacity int not null
check(capacity between 1 and 100), enabled boolean not null default true, version int not null default 1,
unique(station_id, user_id), unique(id, station_id), foreign key(station_id, user_id) references
private.station_memberships(station_id, user_id));
create index riders_user_idx on public.riders(user_id);
insert into public.riders(station_id, user_id, vehicle, plate_number, capacity, enabled) select d.station_id,
d.user_id, d.vehicle, d.plate_number, d.capacity, d.active from public.drivers d join
private.station_membership_roles r on r.station_id=d.station_id and r.user_id=d.user_id and r.role='rider' and
r.active on conflict do nothing;
create table public.station_audit_events(id uuid primary key default gen_random_uuid(), station_id uuid not null
references public.stations(id), actor_id uuid references auth.users(id), system_job text, action text not null,
entity_id uuid, reason text, created_at timestamptz not null default now(), request_key uuid, before_values jsonb
not null default '{}', after_values jsonb not null default '{}', check(actor_id is not null or system_job is not
null));
create index station_audit_events_station_idx on public.station_audit_events(station_id, created_at);
create table private.mutation_requests(actor_id uuid not null references auth.users(id), scope_key text not null,
operation text not null, request_key uuid not null, payload_digest text not null, result jsonb not null, created_at
timestamptz not null default now(), primary key(actor_id, scope_key, operation, request_key));
create table private.station_counters(station_id uuid primary key references public.stations(id), next_order_number
bigint not null default 1 check(next_order_number>0));
create table public.order_review_flags(id uuid primary key default gen_random_uuid(), station_id uuid not null,
order_id uuid not null, closure_id uuid, kind text not null default 'station_closure', resolved_at timestamptz,
created_at timestamptz not null default now(), foreign key(order_id, station_id) references public.orders(id,
station_id), foreign key(closure_id, station_id) references public.station_closures(id, station_id),
unique(order_id, closure_id));
create index order_review_flags_station_idx on public.order_review_flags(station_id, order_id);
create table public.notifications(id uuid primary key default gen_random_uuid(), station_id uuid not null
references public.stations(id), recipient_id uuid not null references auth.users(id), event_key text not null, kind
text not null, created_at timestamptz not null default now(), read_at timestamptz, unique(recipient_id,
event_key));
create index notifications_recipient_idx on public.notifications(recipient_id, created_at);
create function private.initialize_station(sid uuid) returns void language plpgsql security definer set
search_path='' as $$
declare initialize_hours boolean;
begin
 insert into public.delivery_fee_rule_sets(station_id, version, max_distance_km) values(sid, 1, 10) on conflict do
 nothing;
 insert into public.delivery_fee_rules(station_id, version, band_index, min_km, max_km, min_inclusive,
 max_inclusive, fee_centavos) values(sid, 1, 1, 0, 1, true, false, 500), (sid, 1, 2, 1, 3, true, true, 1000), (sid,
 1, 3, 3, null, false, false, 1500) on conflict do nothing;
 insert into public.station_settings(station_id) values(sid) on conflict do nothing;
 insert into public.station_availability(station_id, manual_mode, begins_at, public_notice) select id, case when
 closed_notice is not null then 'closed' end, case when closed_notice is not null then now() end,
 coalesce(closed_notice, '') from public.stations where id=sid on conflict do nothing returning true into initialize_hours;
 insert into private.station_counters(station_id) values(sid) on conflict do nothing;
 -- Seed legacy windows only for the first initialization. An empty saved schedule is intentional.
 if initialize_hours then
 insert into public.station_opening_hours(station_id, weekday, window_no, opens_at, closes_at)
 select s.id, d, 1, case when d=0 then s.sunday_open else s.weekday_open end, case when d=0 then s.sunday_close
 else s.weekday_close end
 from public.stations s cross join generate_series(0, 6) d where s.id=sid and (d<>0 or s.sunday_open is not null)
 on conflict do nothing;
 end if;
 insert into public.products(station_id, default_code, name, price_centavos, pack_text, image_key, returnable,
 load_units, description)
 select sid, v.code, v.name, v.price, v.pack, v.image, v.ret, v.load, v.description from (values
 ('round', 'Water container refill (18.9 L)', 2000, '1 piece', 'water-jug', true, 1,
 'Refill for your 18.9 L water container.'),
 ('small', 'Small water container refill (9.5 L)', 1000, '1 piece', 'water-jug', true, 1,
 'Refill for your 9.5 L small water container.'),
 ('faucet', 'Water jug with faucet refill', 2000, '1 piece', 'faucet-container', true, 1,
 'Refill for your water jug with faucet.'),
 ('jug', 'New water jug with faucet', 19000, '1 piece', 'faucet-container', false, 1,
 'New water jug with faucet, filled with water.'),
 ('bottle350', 'Bottled water 350 ml', 22000, '1 box for 40 pcs', 'water-bottle', false, null::int,
 '1 box of 40 bottles.'),
 ('bottle500', 'Bottled water 500 ml', 27000, '1 box for 35 pcs', 'water-bottle', false, null::int,
 '1 box of 35 bottles.'),
 ('bottle1l', 'Bottled water 1 L', 26000, '1 box for 20 pcs', 'water-bottle', false, null::int,
 '1 box of 20 bottles.')) v(code, name, price, pack, image, ret, load, description)
 where not exists(select 1 from public.products p where p.station_id=sid and (p.default_code=v.code or
 p.name=v.name));
 update public.products p set default_code=v.code, pack_text=v.pack, description=v.description, image_key=v.image,
 returnable=v.ret, load_units=v.load
 from (values
 ('round', 'Water container refill (18.9 L)', 2000, '1 piece', 'water-jug', true, 1,
 'Refill for your 18.9 L water container.'),
 ('small', 'Small water container refill (9.5 L)', 1000, '1 piece', 'water-jug', true, 1,
 'Refill for your 9.5 L small water container.'),
 ('faucet', 'Water jug with faucet refill', 2000, '1 piece', 'faucet-container', true, 1,
 'Refill for your water jug with faucet.'),
 ('jug', 'New water jug with faucet', 19000, '1 piece', 'faucet-container', false, 1,
 'New water jug with faucet, filled with water.'),
 ('bottle350', 'Bottled water 350 ml', 22000, '1 box for 40 pcs', 'water-bottle', false, null::int,
 '1 box of 40 bottles.'),
 ('bottle500', 'Bottled water 500 ml', 27000, '1 box for 35 pcs', 'water-bottle', false, null::int,
 '1 box of 35 bottles.'),
 ('bottle1l', 'Bottled water 1 L', 26000, '1 box for 20 pcs', 'water-bottle', false, null::int,
 '1 box of 20 bottles.')) v(code, name, price, pack, image, ret, load, description)
 where p.station_id=sid and p.default_code is null and p.name=v.name
 and (select count(*) from public.products x where x.station_id=sid and x.name=v.name)=1
 and not exists(select 1 from public.products x where x.station_id=sid and x.default_code=v.code);
 -- Ambiguous same-name rows stay untagged; preflight reports them. Prices and identities are never reset.
end $$;
create function private.initialize_approved_station() returns trigger language plpgsql security definer set
search_path='' as $$
begin
 if new.registration_status='approved' and (tg_op='INSERT' or old.registration_status is distinct from 'approved')
 then perform private.initialize_station(new.id);
end if;
 return new;
end $$;
create trigger initialize_approved_station after insert or update of registration_status on public.stations for
each row execute function private.initialize_approved_station();
select private.initialize_station(id) from public.stations where registration_status='approved';
create or replace function public.provision_station_member(p_user_id uuid, p_station_id uuid, p_role text) returns
void language plpgsql security definer set search_path='' as $$
begin
 if p_role not in('station_admin', 'rider') then raise exception 'Unknown station role.' using errcode='22023';
end if;
 perform 1 from auth.users where id=p_user_id for update;
 if exists(select 1 from private.user_roles where user_id=p_user_id and role='super_admin') then raise exception
 'Forbidden identity.' using errcode='42501';
end if;
 perform 1 from public.stations where id=p_station_id and registration_status='approved' for update;
 if not found then raise exception 'Station unavailable.' using errcode='22023';
end if;
 insert into private.user_roles(user_id, role) values(p_user_id, p_role) on conflict do nothing;
 insert into private.station_memberships(user_id, station_id, active) values(p_user_id, p_station_id, true) on
 conflict(user_id, station_id) do update set active=true;
 insert into private.station_membership_roles(station_id, user_id, role, active) values(p_station_id, p_user_id,
 p_role, true) on conflict(station_id, user_id, role) do update set active=true,
 version=private.station_membership_roles.version+1, updated_at=now();
 perform private.initialize_station(p_station_id);
end $$;
create or replace function public.register_driver(p_user_id uuid, p_station_id uuid, p_full_name text, p_phone
text, p_email text, p_vehicle text, p_plate text, p_capacity integer, p_created_by uuid) returns void language
plpgsql security definer set search_path='' as $$
begin
 perform 1 from private.station_memberships m join private.station_membership_roles r on (r.station_id,
 r.user_id)=(m.station_id, m.user_id)
 where m.user_id=p_created_by and m.station_id=p_station_id and m.active and r.active and r.role='station_admin'
 and exists(select 1 from private.user_roles u where u.user_id=p_created_by and u.role='station_admin')
 and not exists(select 1 from private.user_roles u where u.user_id=p_created_by and u.role='super_admin') for
 update of m, r;
 if not found then raise exception 'Station access denied.' using errcode='42501';
end if;
 perform public.provision_station_member(p_user_id, p_station_id, 'rider');
 insert into public.drivers(user_id, station_id, full_name, phone, email, vehicle, plate_number, capacity,
 created_by) values(p_user_id, p_station_id, trim(p_full_name), p_phone, lower(trim(p_email)), p_vehicle,
 upper(trim(p_plate)), p_capacity, p_created_by);
 insert into public.riders(station_id, user_id, vehicle, plate_number, capacity, enabled) values(p_station_id,
 p_user_id, p_vehicle, upper(trim(p_plate)), p_capacity, true);
end $$;
-- This grant stays service-only from the original migration; replacing does not broaden it.
create function private.envelope(d jsonb, code text default null, fields jsonb default '{}') returns jsonb language
sql immutable set search_path='' as $$select jsonb_build_object('ok', code is null, 'data', coalesce(d,
'{}'::jsonb), 'error_code', code, 'field_errors', fields)$$;
create function private.owner_guard(sid uuid) returns void language plpgsql security definer set search_path='' as
$$
begin
 if not private.station_admin(sid) then raise exception 'Station access denied.' using errcode='42501';
end if;
 -- Hold protected membership and global role rows until transaction end; revocations serialize with mutations.
 perform 1 from private.user_roles where user_id=auth.uid() for share;
 perform 1 from private.station_memberships where user_id=auth.uid() and station_id=sid and active for share;
 perform 1 from private.station_membership_roles where user_id=auth.uid() and station_id=sid and
 role='station_admin' and active for share;
 if not private.station_admin(sid) then raise exception 'Station access denied.' using errcode='42501';
end if;
end $$;
create function private.begin_mutation(sid uuid, op text, key uuid, payload jsonb) returns jsonb language plpgsql
security definer set search_path='' as $$
declare r private.mutation_requests;
begin
 perform private.owner_guard(sid);
 if key is null then return private.envelope('{}', 'invalid_input', jsonb_build_object('request_key',
 'A request key is required.'));
end if;
 perform pg_advisory_xact_lock(hashtextextended(auth.uid()::text||':'||sid::text||':'||op||':'||key::text, 0));
 select * into r from private.mutation_requests where actor_id=auth.uid() and scope_key=sid::text and operation=op
 and request_key=key;
 if found then
 if r.payload_digest<>encode(sha256(convert_to(payload::text, 'UTF8')), 'hex') then return private.envelope('{}',
 'idempotency_conflict');
end if;
 return r.result;
 end if;
 perform 1 from public.stations where id=sid for update;
 perform 1 from public.station_availability where station_id=sid for update;
 return null;
end $$;
create function private.finish_mutation(sid uuid, op text, key uuid, payload jsonb, v int, entity uuid default
null) returns jsonb language plpgsql security definer set search_path='' as $$
declare result jsonb;
av int;
begin
 update public.station_availability set version=version+1 where station_id=sid returning version into av;
 result:=private.envelope(jsonb_strip_nulls(jsonb_build_object('station_id', sid, 'version', v,
 'availability_version', av, 'product_id', case when op='save_product' then entity end)));
 insert into private.mutation_requests(actor_id, scope_key, operation, request_key, payload_digest, result)
 values(auth.uid(), sid::text, op, key, encode(sha256(convert_to(payload::text, 'UTF8')), 'hex'), result);
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, reason,
 before_values, after_values)
 values(sid, auth.uid(), op, entity, key, case when op='cancel_station_closure' then payload->>'reason' end,
 jsonb_build_object('version', greatest(v-1, 0), 'availability_version', av-1),
 jsonb_build_object('version', v, 'availability_version', av, 'change', payload));
 return result;
end $$;
create function public.authorize_driver_credentials(p_station_id uuid, p_driver_user_id uuid) returns jsonb
language plpgsql security definer set search_path='' as $$
declare allowed boolean;
begin
 perform private.owner_guard(p_station_id);
 allowed:=exists(select 1 from public.drivers where user_id=p_driver_user_id and station_id=p_station_id)
 and exists(select 1 from private.station_membership_roles where user_id=p_driver_user_id and
 station_id=p_station_id and role='rider' and active)
 and exists(select 1 from private.station_memberships where user_id=p_driver_user_id and station_id=p_station_id
 and active)
 and not exists(select 1 from private.user_roles where user_id=p_driver_user_id and role in('super_admin',
 'station_admin'))
 and not exists(select 1 from private.station_membership_roles where user_id=p_driver_user_id and
 (station_id<>p_station_id or role<>'rider'))
 and not exists(select 1 from private.station_memberships where user_id=p_driver_user_id and
 station_id<>p_station_id);
 return private.envelope(jsonb_build_object('allowed', allowed));
end $$;
drop policy drivers_registry_read on public.drivers;
drop policy drivers_self_read on public.drivers;
create policy drivers_self_read on public.drivers for select to authenticated using(user_id=(select auth.uid()) and
not private.has_role('super_admin'));
-- Hours are converted to UTC instants using the station's fixed Manila timezone.
-- Subtract closed ranges, retaining reopening segments on the same service service_day.
create function private.bookable_windows(sid uuid, first_day date, last_day date) returns table(service_date date,
opens_at timestamptz, closes_at timestamptz, order_cutoff timestamptz, delivery_cutoff timestamptz) language sql
stable security definer set search_path='' as $$
 with base as (
 select d::date service_day, h.*, ((d::date+h.opens_at) at time zone 'Asia/Manila') o, ((d::date+h.closes_at) at
 time zone 'Asia/Manila') c,
 ((d::date+coalesce(h.order_cutoff, h.closes_at)) at time zone 'Asia/Manila') oc,
 ((d::date+coalesce(h.delivery_cutoff, h.closes_at)) at time zone 'Asia/Manila') dc
 from generate_series(first_day::timestamp, last_day::timestamp, interval '1 day') d join
 public.station_opening_hours h on h.station_id=sid and h.enabled and h.weekday=extract(dow from d)
 ), segments as (
 select b.*, unnest(tstzmultirange(tstzrange(o, c, '[)'))-coalesce((select range_agg(tstzrange(x.starts_at,
 coalesce(x.ends_at, 'infinity'::timestamptz), '[)')) from public.station_closures x where x.station_id=sid and
 x.cancelled_at is null and x.mode in('closed', 'maintenance', 'no_operations')), '{}'::tstzmultirange)
 -coalesce((select tstzmultirange(tstzrange(a.begins_at, coalesce(a.expires_at, 'infinity'::timestamptz), '[)'))
 from public.station_availability a where a.station_id=sid and a.manual_mode in('closed', 'maintenance',
 'no_operations')), '{}'::tstzmultirange)) segment from base b
 ) select service_day, lower(segment), upper(segment), least(oc, upper(segment)), least(dc, upper(segment)) from
 segments where lower(segment)<=oc and lower(segment)<upper(segment) order by service_day, lower(segment)
$$;
create function private.availability(sid uuid, at_time timestamptz) returns jsonb language plpgsql stable security
definer set search_path='' as $$
declare a public.station_availability;
cfg public.station_settings;
t timestamptz:=coalesce(at_time, now());
service_day date;
opened boolean;
accepting boolean;
book boolean;
notice text:='';
mode text;
next_o timestamptz;
next_c timestamptz;
preview jsonb;
begin
 select * into a from public.station_availability where station_id=sid;
 select * into cfg from public.station_settings where station_id=sid;
 service_day:=(t at time zone 'Asia/Manila')::date;
 if a.manual_mode is not null and a.begins_at<=t and (a.expires_at is null or t<a.expires_at) then
 mode:=a.manual_mode;
notice:=a.public_notice;
end if;
 select x.mode, x.public_notice into mode, notice from public.station_closures x where x.station_id=sid and
 x.cancelled_at is null and x.starts_at<=t and (x.ends_at is null or t<x.ends_at) order by (x.mode in('closed',
 'maintenance', 'no_operations')) desc, x.is_manual desc, x.starts_at desc limit 1;
 if not found and a.manual_mode is not null and a.begins_at<=t and (a.expires_at is null or t<a.expires_at) then
 mode:=a.manual_mode;
notice:=a.public_notice;
end if;
 select exists(select 1 from private.bookable_windows(sid, service_day, service_day) w where w.opens_at<=t and
 t<w.closes_at),
 exists(select 1 from private.bookable_windows(sid, service_day, service_day) w where w.opens_at<=t and
 t<w.closes_at and t<=w.order_cutoff) into opened, accepting;
 accepting:=accepting and mode is null;
 select min(w.opens_at) filter(where w.opens_at>t), min(w.closes_at) filter(where w.opens_at<=t and t<w.closes_at)
 into next_o, next_c from private.bookable_windows(sid, service_day, service_day+cfg.booking_days) w;
 select exists(select 1 from private.bookable_windows(sid, service_day, service_day+cfg.booking_days) w where
 w.order_cutoff>=t and w.closes_at>t) into book;
 if a.manual_mode in('closed', 'maintenance', 'no_operations') and a.expires_at is null and a.begins_at<=t then
 book:=false;
end if;
 select coalesce(jsonb_agg(jsonb_build_object('date', d::date, 'windows', coalesce((select
 jsonb_agg(jsonb_build_object('opens_at', to_char(w.opens_at at time zone 'Asia/Manila', 'HH24:MI:SS'), 'closes_at',
 to_char(w.closes_at at time zone 'Asia/Manila', 'HH24:MI:SS'), 'order_cutoff', to_char(w.order_cutoff at time zone
 'Asia/Manila', 'HH24:MI:SS'), 'delivery_cutoff', to_char(w.delivery_cutoff at time zone 'Asia/Manila',
 'HH24:MI:SS')) order by w.opens_at) from private.bookable_windows(sid, d::date, d::date) w), '[]'::jsonb),
 'notice', coalesce((select x.public_notice from public.station_closures x where x.station_id=sid and x.cancelled_at
 is null and tstzrange(x.starts_at, x.ends_at, '[)') && tstzrange(d::date::timestamp at time zone 'Asia/Manila',
 (d::date+1)::timestamp at time zone 'Asia/Manila', '[)') order by x.starts_at limit 1), '')) order by d),
 '[]'::jsonb) into preview from generate_series(service_day::timestamp, (service_day+6)::timestamp, interval
 '1 day') d;
 return jsonb_build_object('version', a.version, 'manual_mode', a.manual_mode, 'begins_at', a.begins_at,
 'expires_at', a.expires_at, 'public_notice', coalesce(notice, ''), 'is_open', opened, 'accepting_asap', accepting,
 'can_book', book, 'status_key', case when accepting then 'open' when mode is not null then mode else 'closed' end,
 'label', case when accepting then 'Open now' when mode='maintenance' then 'Under Maintenance' when
 mode='no_operations' then 'No Operations' else 'Temporarily Closed' end, 'next_open_at', next_o, 'next_close_at',
 next_c, 'preview', preview);
end $$;
create function public.get_station_availability(p_station_id uuid, p_requested_at timestamptz) returns jsonb
language plpgsql stable security definer set search_path='' as $$
begin
 if not exists(select 1 from public.stations where id=p_station_id and active and registration_status='approved')
 then return private.envelope('{}', 'not_found');
end if;
 return private.envelope((private.availability(p_station_id, p_requested_at)-array['version', 'manual_mode',
 'begins_at', 'expires_at'])||jsonb_build_object('station_id', p_station_id));
end $$;
create function public.list_station_availability(p_station_ids uuid[], p_requested_at timestamptz) returns jsonb
language sql stable security definer set search_path='' as $$
 select private.envelope(jsonb_build_object('stations', coalesce(jsonb_agg((public.get_station_availability(s.id,
 p_requested_at)->'data') order by s.id), '[]'::jsonb))) from public.stations s where s.active and
 s.registration_status='approved' and (p_station_ids is null or s.id=any(p_station_ids))
$$;
create function public.get_operation_context(p_station_id uuid) returns jsonb language plpgsql stable security
definer set search_path='' as $$
declare actor jsonb;
members jsonb;
st jsonb;
cfg jsonb;
fees jsonb;
begin
 if auth.uid() is null or private.has_role('super_admin') or not exists(select 1 from private.user_roles where
 user_id=auth.uid() and role in('customer', 'station_admin', 'rider')) then raise exception
 'Operation access denied.' using errcode='42501';
end if;
 select jsonb_build_object('id', auth.uid(), 'roles', coalesce(jsonb_agg(role order by role), '[]'::jsonb)) into
 actor from private.user_roles where user_id=auth.uid();
 select coalesce(jsonb_agg(jsonb_build_object('station_id', s.id, 'station_name', s.name, 'slug', s.slug, 'role',
 r.role) order by s.name, s.id, r.role), '[]'::jsonb) into members from private.station_membership_roles r join
 private.station_memberships m on (m.station_id, m.user_id)=(r.station_id, r.user_id) join public.stations s on
 s.id=r.station_id where r.user_id=auth.uid() and r.active and m.active;
 if p_station_id is null then return private.envelope(jsonb_build_object('actor', actor, 'memberships', members));
end if;
 if not private.station_admin(p_station_id) then raise exception 'Station access denied.' using errcode='42501';
end if;
 select jsonb_build_object('id', s.id, 'slug', s.slug, 'name', s.name, 'barangay', s.barangay, 'barangay_id',
 s.barangay_id, 'municipality_code', s.municipality_code, 'address', s.address, 'phone', s.phone, 'email', s.email,
 'description', s.description, 'contact_name', s.contact_name, 'latitude', s.latitude, 'longitude', s.longitude,
 'profile_version', s.profile_version, 'location_version', s.location_version) into st from public.stations s where
 id=p_station_id;
 select to_jsonb(x)-'station_id' into cfg from public.station_settings x where station_id=p_station_id;
 select jsonb_build_object('version', s.active_fee_version, 'max_distance_km', f.max_distance_km, 'bands',
 coalesce((select jsonb_agg(to_jsonb(b)-array['station_id', 'version', 'band_index'] order by band_index) from
 public.delivery_fee_rules b where b.station_id=p_station_id and b.version=s.active_fee_version), '[]'::jsonb)) into
 fees from public.station_settings s join public.delivery_fee_rule_sets f on f.station_id=s.station_id and
 f.version=s.active_fee_version where s.station_id=p_station_id;
 return private.envelope(jsonb_build_object('actor', actor, 'memberships', members, 'station', st, 'settings', cfg,
 'barangays', coalesce((select jsonb_agg(jsonb_build_object('id', id, 'psgc_code', psgc_code, 'name', name) order by
 name) from public.barangays where active), '[]'::jsonb),
 'service_areas', coalesce((select jsonb_agg(jsonb_build_object('barangay_id', barangay_id, 'served', served) order
 by barangay_id) from public.station_service_areas where station_id=p_station_id), '[]'::jsonb),
 'products', coalesce((select jsonb_agg(to_jsonb(p)-array['station_id', 'updated_at'] order by default_code nulls
 last, name, id) from public.products p where station_id=p_station_id), '[]'::jsonb),
 'opening_hours', coalesce((select jsonb_agg(to_jsonb(h)-'station_id' order by weekday, window_no) from
 public.station_opening_hours h where station_id=p_station_id), '[]'::jsonb),
 'closures', coalesce((select jsonb_agg(jsonb_build_object('id', id, 'starts_at', starts_at, 'ends_at', ends_at,
 'mode', mode, 'public_notice', public_notice, 'is_manual', is_manual, 'cancelled_at', cancelled_at, 'version',
 version) order by starts_at, id) from public.station_closures where station_id=p_station_id), '[]'::jsonb),
 'availability', private.availability(p_station_id, null), 'fee_rules', fees));
end $$;
create function public.get_driver_registration_counts(p_filters jsonb) returns jsonb language plpgsql stable
security definer set search_path='' as $$
declare rows jsonb;
unique_count int;
begin
 if not private.has_role('super_admin') then raise exception 'Registry access denied.' using errcode='42501';
end if;
 select coalesce(jsonb_agg(jsonb_build_object('station_id', s.id, 'station_name', s.name, 'slug', s.slug,
 'registered_count', (select count(*) from public.drivers d where d.station_id=s.id), 'active_count', (select
 count(*) from public.drivers d where d.station_id=s.id and d.active)) order by s.name, s.id), '[]'::jsonb) into
 rows from public.stations s where p_filters->>'station_id' is null or s.id::text=p_filters->>'station_id';
 select count(distinct user_id) into unique_count from public.drivers d where p_filters->>'station_id' is null or
 d.station_id::text=p_filters->>'station_id';
 return private.envelope(jsonb_build_object('rows', rows, 'unique_driver_count', unique_count));
end $$;
create function public.get_platform_station_registry(p_filters jsonb) returns jsonb language plpgsql stable
security definer set search_path='' as $$
declare rows jsonb;
counts jsonb;
begin
 counts:=public.get_driver_registration_counts(p_filters);
 select coalesce(jsonb_agg(jsonb_build_object('id', s.id, 'station_id', s.id, 'station_name', s.name, 'slug',
 s.slug, 'name', s.name, 'barangay', s.barangay, 'address', s.address, 'phone', s.phone, 'email', s.email, 'active',
 s.active, 'registration_status', s.registration_status, 'created_at', s.created_at, 'registered_count', (select
 count(*) from public.drivers d where d.station_id=s.id), 'active_count', (select count(*) from public.drivers d
 where d.station_id=s.id and d.active)) order by s.name, s.id), '[]'::jsonb) into rows from public.stations s where
 p_filters->>'station_id' is null or s.id::text=p_filters->>'station_id';
 return private.envelope(jsonb_build_object('rows', rows, 'unique_driver_count',
 counts->'data'->'unique_driver_count'));
end $$;
create function public.save_station_profile(p_station_id uuid, p_payload jsonb, p_expected_version int,
p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare r jsonb;
v int;
b public.barangays;
begin
 r:=private.begin_mutation(p_station_id, 'save_station_profile', p_request_key, p_payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select profile_version from public.stations where id=p_station_id) then
 return private.envelope('{}', 'stale_version');
end if;
 if p_payload is null or jsonb_typeof(p_payload)<>'object' or exists(select 1 from jsonb_object_keys(p_payload) k
 where k not in('name', 'contact_name', 'phone', 'email', 'address', 'barangay_id', 'description')) or
 length(trim(coalesce(p_payload->>'name', ''))) not between 2 and 120 or
 length(trim(coalesce(p_payload->>'contact_name', ''))) not between 2 and 120 or
 length(trim(coalesce(p_payload->>'address', ''))) not between 3 and 500 or coalesce(p_payload->>'phone', '') !~
 '^\+639[0-9]{9}$' or (coalesce(p_payload->>'email', '')<>'' and (length(p_payload->>'email')>254 or
 (p_payload->>'email') !~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$')) or
 length(coalesce(p_payload->>'description', ''))>2000 then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('profile', 'Check the station profile fields.'));
end if;
 select * into b from public.barangays where id=(p_payload->>'barangay_id')::uuid and active;
 if not found then return private.envelope('{}', 'invalid_input', jsonb_build_object('barangay_id',
 'Choose an official Hamtic barangay.'));
end if;
 update public.stations set name=trim(p_payload->>'name'), contact_name=trim(p_payload->>'contact_name'),
 phone=p_payload->>'phone', email=lower(trim(coalesce(p_payload->>'email', ''))),
 address=trim(p_payload->>'address'), barangay_id=b.id, barangay=b.name,
 description=coalesce(p_payload->>'description', ''), profile_version=profile_version+1, updated_at=now() where
 id=p_station_id returning profile_version into v;
 return private.finish_mutation(p_station_id, 'save_station_profile', p_request_key, p_payload, v);
 exception when invalid_parameter_value or invalid_text_representation or check_violation or not_null_violation or numeric_value_out_of_range
 then return private.envelope('{}', 'invalid_input', jsonb_build_object('profile',
 'Check the station profile fields.'));
end $$;
create function public.save_station_location(p_station_id uuid, p_latitude numeric, p_longitude numeric,
p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as
$$
declare r jsonb;
payload jsonb:=jsonb_build_object('latitude', p_latitude, 'longitude', p_longitude);
v int;
begin
 r:=private.begin_mutation(p_station_id, 'save_station_location', p_request_key, payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select location_version from public.stations where id=p_station_id) then
 return private.envelope('{}', 'stale_version');
end if;
 if p_latitude is null or p_longitude is null or p_latitude not between 10.584854 and 10.746484 or p_longitude not
 between 121.961241 and 122.073730 then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('latitude', 'Choose a pin within Hamtic.'));
end if;
 update public.stations set latitude=p_latitude, longitude=p_longitude, location_version=location_version+1,
 updated_at=now() where id=p_station_id returning location_version into v;
 return private.finish_mutation(p_station_id, 'save_station_location', p_request_key, payload, v);
end $$;
create function public.save_station_service_areas(p_station_id uuid, p_barangay_ids uuid[], p_expected_version int,
p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare r jsonb;
payload jsonb;
v int;
begin
 payload:=jsonb_build_object('barangay_ids', coalesce((select jsonb_agg(distinct x order by x) from
 unnest(p_barangay_ids) x), '[]'::jsonb));
 r:=private.begin_mutation(p_station_id, 'save_station_service_areas', p_request_key, payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select version from public.station_settings where station_id=p_station_id)
 then return private.envelope('{}', 'stale_version');
end if;
 if p_barangay_ids is null or array_position(p_barangay_ids, null) is not null or exists(select 1 from
 unnest(p_barangay_ids) x where not exists(select 1 from public.barangays b where b.id=x and b.active)) then return
 private.envelope('{}', 'invalid_input', jsonb_build_object('barangay_ids', 'Choose official Hamtic barangays.'));
end if;
 delete from public.station_service_areas where station_id=p_station_id;
 insert into public.station_service_areas(station_id, barangay_id, actor_id) select p_station_id, x, auth.uid()
 from (select distinct unnest(p_barangay_ids) x) d;
 update public.station_settings set version=version+1 where station_id=p_station_id returning version into v;
 return private.finish_mutation(p_station_id, 'save_station_service_areas', p_request_key, payload, v);
end $$;
create function public.save_station_settings(p_station_id uuid, p_payload jsonb, p_expected_version int,
p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare r jsonb;
v int;
begin
 r:=private.begin_mutation(p_station_id, 'save_station_settings', p_request_key, p_payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select version from public.station_settings where station_id=p_station_id)
 then return private.envelope('{}', 'stale_version');
end if;
 if p_payload is null or jsonb_typeof(p_payload)<>'object' or exists(select 1 from jsonb_object_keys(p_payload) k
 where k not in('max_order_units', 'max_distance_km', 'booking_days', 'duplicate_minutes', 'overdue_minutes',
 'institution_bonus_enabled', 'urgency_asap', 'urgency_soon', 'urgency_later', 'urgency_immediate_minutes',
 'urgency_soon_minutes', 'aging_interval_minutes', 'aging_points', 'aging_cap', 'institution_bonus')) then return
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
 version=version+1 where station_id=p_station_id returning version into v;
 return private.finish_mutation(p_station_id, 'save_station_settings', p_request_key, p_payload, v);
 exception when invalid_parameter_value or invalid_text_representation or check_violation or not_null_violation or numeric_value_out_of_range
 then return private.envelope('{}', 'invalid_input', jsonb_build_object('settings', 'Check the order rules.'));
end $$;
create function public.save_delivery_fee_rules(p_station_id uuid, p_bands jsonb, p_max_km numeric,
p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as
$$
declare r jsonb;
payload jsonb:=jsonb_build_object('bands', p_bands, 'max_km', p_max_km);
v int;
fv int;
b jsonb;
i int:=0;
last_max numeric;
last_inclusive boolean;
lo numeric;
hi numeric;
li boolean;
ui boolean;
fee int;
begin
 r:=private.begin_mutation(p_station_id, 'save_delivery_fee_rules', p_request_key, payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select version from public.station_settings where station_id=p_station_id)
 then return private.envelope('{}', 'stale_version');
end if;
 if p_max_km is null or p_max_km<=0 or p_max_km>100 or p_bands is null or jsonb_typeof(p_bands)<>'array' or
 jsonb_array_length(p_bands) not between 1 and 20 then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('bands', 'Provide complete contiguous fee bands.'));
end if;
 if exists(select 1 from jsonb_array_elements(p_bands) band_obj,
 jsonb_object_keys(band_obj) k where k not in('min_km','max_km','min_inclusive','max_inclusive','fee_centavos')) then
 return private.envelope('{}','invalid_input',jsonb_build_object('bands','Check the fee band fields.'));
 end if;
 for b in select value from jsonb_array_elements(p_bands) loop
 i:=i+1;
lo:=(b->>'min_km')::numeric;
hi:=(b->>'max_km')::numeric;
li:=(b->>'min_inclusive')::boolean;
ui:=(b->>'max_inclusive')::boolean;
fee:=(b->>'fee_centavos')::int;
 if lo is null or li is null or ui is null or fee is null or lo<0 or fee<0 or fee>100000000 or (i=1 and (lo<>0 or
 not li)) or (i>1 and (last_max is null or lo<>last_max or li=last_inclusive)) or (hi is not null and hi<=lo) or (hi
 is null and (i<>jsonb_array_length(p_bands) or ui)) or (i=jsonb_array_length(p_bands) and hi is not null) then
 return private.envelope('{}', 'invalid_input', jsonb_build_object('bands',
 'Fee bands must cover every distance once.'));
end if;
 last_max:=hi;
last_inclusive:=ui;
 end loop;
 select active_fee_version+1 into fv from public.station_settings where station_id=p_station_id;
 insert into public.delivery_fee_rule_sets(station_id, version, max_distance_km, creator_id) values(p_station_id,
 fv, p_max_km, auth.uid());
 insert into public.delivery_fee_rules(station_id, version, band_index, min_km, max_km, min_inclusive,
 max_inclusive, fee_centavos) select p_station_id, fv, ordinality, (value->>'min_km')::numeric,
 (value->>'max_km')::numeric, (value->>'min_inclusive')::boolean, (value->>'max_inclusive')::boolean,
 (value->>'fee_centavos')::int from jsonb_array_elements(p_bands) with ordinality;
 update public.station_settings set active_fee_version=fv, max_distance_km=p_max_km, version=version+1 where
 station_id=p_station_id returning version into v;
 return private.finish_mutation(p_station_id, 'save_delivery_fee_rules', p_request_key, payload, v);
 exception when invalid_parameter_value or invalid_text_representation or check_violation or not_null_violation or numeric_value_out_of_range
 then return private.envelope('{}', 'invalid_input', jsonb_build_object('bands', 'Check the fee bands.'));
end $$;
create function public.save_product(p_station_id uuid, p_product_id uuid, p_payload jsonb, p_expected_version int,
p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare r jsonb;
payload jsonb:=jsonb_build_object('product_id', p_product_id, 'product', p_payload);
v int;
pid uuid:=p_product_id;
begin
 r:=private.begin_mutation(p_station_id, 'save_product', p_request_key, payload);
if r is not null then return r;
end if;
 if p_product_id is not null then
 perform 1 from public.products where id=p_product_id and station_id=p_station_id for update;
 if not found then raise exception 'Product access denied.' using errcode='42501';
end if;
 if p_expected_version is distinct from (select version from public.products where id=p_product_id) then return
 private.envelope('{}', 'stale_version');
end if;
 elsif p_expected_version is not null then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('product', 'New products have no version.'));
end if;
 if p_payload is null or jsonb_typeof(p_payload)<>'object' or exists(select 1 from jsonb_object_keys(p_payload) k
 where k not in('name', 'pack_text', 'description', 'image_key', 'price_centavos', 'returnable', 'load_units',
 'active')) or length(trim(coalesce(p_payload->>'name', ''))) not between 1 and 120 or
 (p_payload->>'price_centavos') !~ '^[0-9]+$' then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('product', 'Check the product fields.'));
end if;
 if pid is null then
 insert into public.products(station_id, name, price_centavos, pack_text, description, image_key, returnable,
 load_units, active) values(p_station_id, trim(p_payload->>'name'), (p_payload->>'price_centavos')::int,
 coalesce(p_payload->>'pack_text', ''), coalesce(p_payload->>'description', ''), p_payload->>'image_key',
 (p_payload->>'returnable')::boolean, (p_payload->>'load_units')::int, (p_payload->>'active')::boolean) returning
 id, version into pid, v;
 else
 update public.products set name=trim(p_payload->>'name'), price_centavos=(p_payload->>'price_centavos')::int,
 pack_text=coalesce(p_payload->>'pack_text', ''), description=coalesce(p_payload->>'description', ''),
 image_key=p_payload->>'image_key', returnable=(p_payload->>'returnable')::boolean,
 load_units=(p_payload->>'load_units')::int, active=(p_payload->>'active')::boolean, version=version+1,
 updated_at=now() where id=pid returning version into v;
 end if;
 return private.finish_mutation(p_station_id, 'save_product', p_request_key, payload, v, pid);
 exception when invalid_parameter_value or invalid_text_representation or check_violation or not_null_violation or numeric_value_out_of_range
 then return private.envelope('{}', 'invalid_input', jsonb_build_object('product', 'Check the product fields.'));
end $$;
create function public.save_station_hours(p_station_id uuid, p_windows jsonb, p_cutoffs jsonb, p_expected_version
int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare r jsonb;
payload jsonb:=jsonb_build_object('windows', p_windows, 'cutoffs', p_cutoffs);
v int;
begin
 r:=private.begin_mutation(p_station_id, 'save_station_hours', p_request_key, payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select version from public.station_availability where
 station_id=p_station_id) then return private.envelope('{}', 'stale_version');
end if;
 if p_windows is null or p_cutoffs is null or jsonb_typeof(p_windows)<>'array' or jsonb_typeof(p_cutoffs)<>'array'
 or jsonb_array_length(p_windows)>14 or jsonb_array_length(p_cutoffs)>14 then return private.envelope('{}',
 'invalid_input', jsonb_build_object('hours', 'Check the weekly windows.'));
end if;
 if exists(select 1 from jsonb_array_elements(p_windows) w group by w->>'weekday', w->>'window_no' having
 count(*)>1) or exists(select 1 from jsonb_array_elements(p_cutoffs) c group by c->>'weekday', c->>'window_no'
 having count(*)>1) or exists(select 1 from jsonb_array_elements(p_cutoffs) c where not exists(select 1 from
 jsonb_array_elements(p_windows) w where w->>'weekday'=c->>'weekday' and w->>'window_no'=c->>'window_no')) then
 return private.envelope('{}', 'invalid_input', jsonb_build_object('hours',
 'Windows and cutoffs must identify a unique service_day and window.'));
end if;
 if exists(select 1 from jsonb_array_elements(p_windows) w,
 jsonb_object_keys(w) k where k not in('weekday','window_no','opens_at','closes_at','enabled'))
 or exists(select 1 from jsonb_array_elements(p_cutoffs) c,
 jsonb_object_keys(c) k where k not in('weekday','window_no','order_cutoff','delivery_cutoff')) then
 return private.envelope('{}','invalid_input',jsonb_build_object('hours','Check the window fields.'));
 end if;
 delete from public.station_opening_hours where station_id=p_station_id;
 insert into public.station_opening_hours(station_id, weekday, window_no, opens_at, closes_at, enabled,
 order_cutoff, delivery_cutoff)
 select p_station_id, (w->>'weekday')::int, (w->>'window_no')::int, (w->>'opens_at')::time,
 (w->>'closes_at')::time, (w->>'enabled')::boolean, nullif(c->>'order_cutoff', '')::time,
 nullif(c->>'delivery_cutoff', '')::time
 from jsonb_array_elements(p_windows) w left join jsonb_array_elements(p_cutoffs) c on w->>'weekday'=c->>'weekday'
 and w->>'window_no'=c->>'window_no';
 if exists(select 1 from public.station_opening_hours a join public.station_opening_hours b on
 a.station_id=b.station_id and a.weekday=b.weekday and a.id<b.id and a.enabled and b.enabled and
 a.opens_at<b.closes_at and b.opens_at<a.closes_at where a.station_id=p_station_id) then raise check_violation;
end if;
 select version+1 into v from public.station_availability where station_id=p_station_id;
 return private.finish_mutation(p_station_id, 'save_station_hours', p_request_key, payload, v);
 exception when invalid_parameter_value or invalid_text_representation or invalid_datetime_format or datetime_field_overflow or
 check_violation or not_null_violation or numeric_value_out_of_range then return private.envelope('{}',
 'invalid_input', jsonb_build_object('hours', 'Check nonoverlapping windows and cutoffs.'));
end $$;
create function private.flag_closure(sid uuid, cid uuid) returns void language plpgsql security definer set
search_path='' as $$
begin
 -- Legacy orders lack requested instants. Flag unresolved orders for an explicit owner review, never invent history.
 perform 1 from public.orders where station_id=sid and status in('pending', 'confirmed', 'preparing', 'ready',
 'out_for_delivery') order by id for update;
 insert into public.order_review_flags(station_id, order_id, closure_id) select sid, id, cid from public.orders
 where station_id=sid and status in('pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery') on conflict do
 nothing;
 insert into public.notifications(station_id, recipient_id, event_key, kind) select distinct sid, customer_id,
 'closure:'||cid::text||':'||customer_id::text, 'station_closure' from public.orders where station_id=sid and status
 in('pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery') on conflict do nothing;
end $$;
create function public.set_station_closure(p_station_id uuid, p_interval jsonb, p_mode text, p_public_notice text,
p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as
$$
declare r jsonb;
payload jsonb:=jsonb_build_object('interval', p_interval, 'mode', p_mode, 'public_notice', p_public_notice);
v int;
cid uuid;
starts timestamptz;
ends timestamptz;
manual boolean;
cv int;
begin
 r:=private.begin_mutation(p_station_id, 'set_station_closure', p_request_key, payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select version from public.station_availability where
 station_id=p_station_id) then return private.envelope('{}', 'stale_version');
end if;
 if p_interval is null or jsonb_typeof(p_interval)<>'object'
 or exists(select 1 from jsonb_object_keys(p_interval) k
 where k not in('starts_at','ends_at','is_manual','closure_id','closure_version')) then
 return private.envelope('{}','invalid_input',jsonb_build_object('closure','Check the closure fields.'));
 end if;
 starts:=(p_interval->>'starts_at')::timestamptz;
ends:=nullif(p_interval->>'ends_at', '')::timestamptz;
manual:=(p_interval->>'is_manual')::boolean;
cid:=(p_interval->>'closure_id')::uuid;
 if starts is null or not isfinite(starts) or (ends is not null and not isfinite(ends)) or manual is null or p_mode is null or p_mode not in('closed', 'maintenance', 'no_operations')
 or length(coalesce(p_public_notice, ''))>200 or (ends is not null and ends<=starts) or (not manual and ends is
 null) or (manual and starts>now()) then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('closure', 'Check the closure interval and notice.'));
end if;
 if cid is not null then
 select version into cv from public.station_closures where id=cid and station_id=p_station_id and cancelled_at is
 null for update;
 if not found then raise exception 'Closure access denied.' using errcode='42501';
end if;
 if (p_interval->>'closure_version')::int is distinct from cv then return private.envelope('{}', 'stale_version');
end if;
 end if;
 if not manual and exists(select 1 from public.station_closures c where c.station_id=p_station_id and not
 c.is_manual and c.cancelled_at is null and c.id is distinct from cid and tstzrange(c.starts_at, c.ends_at, '[)') &&
 tstzrange(starts, ends, '[)')) then return private.envelope('{}', 'invalid_input', jsonb_build_object('closure',
 'Dated closures cannot overlap.'));
end if;
 if manual then
 update public.station_closures set cancelled_at=now(), cancelled_by=auth.uid(),
 cancel_reason='Replaced manual override', version=version+1 where station_id=p_station_id and is_manual and
 cancelled_at is null and id is distinct from cid;
 update public.station_availability set manual_mode=p_mode, begins_at=starts, expires_at=ends,
 public_notice=coalesce(p_public_notice, '') where station_id=p_station_id;
 end if;
 if cid is null then
 insert into public.station_closures(station_id, starts_at, ends_at, mode, public_notice, is_manual, actor_id)
 values(p_station_id, starts, ends, p_mode, coalesce(p_public_notice, ''), manual, auth.uid()) returning id into
 cid;
 else
 update public.station_closures set starts_at=starts, ends_at=ends, mode=p_mode,
 public_notice=coalesce(p_public_notice, ''), is_manual=manual, actor_id=auth.uid(), version=version+1 where id=cid;
 -- Editing an existing manual entry into a planned entry clears its override cache.
 if not manual then update public.station_availability a set manual_mode=null, begins_at=null, expires_at=null,
 public_notice='' where a.station_id=p_station_id and not exists(select 1 from public.station_closures c where
 c.station_id=p_station_id and c.is_manual and c.cancelled_at is null);
end if;
 end if;
 perform private.flag_closure(p_station_id, cid);
 select version+1 into v from public.station_availability where station_id=p_station_id;
 return private.finish_mutation(p_station_id, 'set_station_closure', p_request_key, payload, v, cid);
 exception when invalid_parameter_value or invalid_text_representation or invalid_datetime_format or datetime_field_overflow or
 check_violation or not_null_violation or numeric_value_out_of_range then return private.envelope('{}',
 'invalid_input', jsonb_build_object('closure', 'Check the closure interval.'));
end $$;
create function public.cancel_station_closure(p_closure_id uuid, p_reason text, p_expected_version int,
p_request_key uuid) returns jsonb language plpgsql security definer set search_path='' as $$
declare sid uuid;
r jsonb;
payload jsonb:=jsonb_build_object('closure_id', p_closure_id, 'reason', p_reason);
v int;
c public.station_closures;
begin
 select station_id into sid from public.station_closures where id=p_closure_id;
 if sid is null then raise exception 'Closure access denied.' using errcode='42501';
end if;
 r:=private.begin_mutation(sid, 'cancel_station_closure', p_request_key, payload);
if r is not null then return r;
end if;
 select * into c from public.station_closures where id=p_closure_id and station_id=sid for update;
 if p_expected_version is distinct from c.version or c.cancelled_at is not null then return private.envelope('{}',
 'stale_version');
end if;
 if length(trim(coalesce(p_reason, ''))) not between 1 and 500 then return private.envelope('{}', 'invalid_input',
 jsonb_build_object('reason', 'A reason is required.'));
end if;
 update public.station_closures set cancelled_at=now(), cancelled_by=auth.uid(), cancel_reason=trim(p_reason),
 version=version+1 where id=p_closure_id returning version into v;
 if c.is_manual then update public.station_availability set manual_mode=null, begins_at=null, expires_at=null,
 public_notice='' where station_id=sid;
end if;
 return private.finish_mutation(sid, 'cancel_station_closure', p_request_key, payload, v, p_closure_id);
end $$;
create function public.reopen_station(p_station_id uuid, p_expected_version int, p_request_key uuid) returns jsonb
language plpgsql security definer set search_path='' as $$
declare r jsonb;
v int;
payload jsonb:='{}';
begin
 r:=private.begin_mutation(p_station_id, 'reopen_station', p_request_key, payload);
if r is not null then return r;
end if;
 if p_expected_version is distinct from (select version from public.station_availability where
 station_id=p_station_id) then return private.envelope('{}', 'stale_version');
end if;
 update public.station_closures set cancelled_at=now(), cancelled_by=auth.uid(), cancel_reason='Manual reopen',
 version=version+1 where station_id=p_station_id and is_manual and cancelled_at is null;
 update public.station_availability set manual_mode=null, begins_at=null, expires_at=null, public_notice='' where
 station_id=p_station_id returning version+1 into v;
 return private.finish_mutation(p_station_id, 'reopen_station', p_request_key, payload, v);
end $$;
-- No client workflow DML. RLS defends every new table, including private caches.
do $$declare t text;
begin
 foreach t in array array['barangays', 'station_settings', 'station_service_areas', 'delivery_fee_rule_sets',
 'delivery_fee_rules', 'station_opening_hours', 'station_closures', 'station_availability', 'riders',
 'station_audit_events', 'order_review_flags', 'notifications'] loop
 execute format('alter table public.%I enable row level security', t);
 execute format('revoke all on public.%I from public,anon,authenticated', t);
 end loop;
 foreach t in array array['station_membership_roles', 'mutation_requests', 'station_counters'] loop execute
 format('alter table private.%I enable row level security', t);
execute format('revoke all on private.%I from public,anon,authenticated', t);
end loop;
end $$;
grant select on public.barangays to anon, authenticated;
create policy barangays_public_read on public.barangays for select to anon, authenticated using(active);
grant select on public.station_settings, public.station_service_areas, public.delivery_fee_rule_sets,
public.delivery_fee_rules, public.station_opening_hours, public.station_closures, public.station_availability,
public.riders, public.station_audit_events, public.order_review_flags to authenticated;
do $$declare t text;
begin
 foreach t in array array['station_settings', 'station_service_areas', 'delivery_fee_rule_sets',
 'delivery_fee_rules', 'station_opening_hours', 'station_closures', 'station_availability', 'riders',
 'station_audit_events', 'order_review_flags'] loop execute
 format('create policy owner_read on public.%I for select to authenticated using(private.station_admin(station_id))',
 t);
end loop;
end $$;
-- Protect internal SQL helpers and expose only the named API operations.
do $$declare f record;
begin
 for f in select p.oid::regprocedure signature from pg_proc p join pg_namespace n on n.oid=p.pronamespace where
 n.nspname='private' and p.proname in('initialize_station', 'initialize_approved_station', 'envelope',
 'owner_guard', 'begin_mutation', 'finish_mutation', 'flag_closure', 'bookable_windows', 'availability') loop
 execute 'revoke all on function '||f.signature||' from public,anon,authenticated,service_role';
end loop;
 for f in select p.oid::regprocedure signature from pg_proc p join pg_namespace n on n.oid=p.pronamespace where
 n.nspname='public' and p.proname in('get_operation_context', 'get_station_availability',
 'list_station_availability', 'get_driver_registration_counts', 'get_platform_station_registry',
 'authorize_driver_credentials', 'save_station_profile', 'save_station_location', 'save_station_service_areas',
 'save_station_settings', 'save_delivery_fee_rules', 'save_product', 'save_station_hours', 'set_station_closure',
 'cancel_station_closure', 'reopen_station') loop
 execute 'revoke all on function '||f.signature||' from public,anon,authenticated,service_role';
execute 'grant execute on function '||f.signature||' to authenticated';
end loop;
end $$;
grant execute on function public.get_station_availability(uuid, timestamptz),
public.list_station_availability(uuid[], timestamptz) to anon;
$phase2_sql$;
 migration_hash text;
 prior_hash text;
begin
 -- SQL Editor and Git may normalize Windows line endings. Hash the same canonical LF text.
 migration_hash := encode(sha256(convert_to(
  replace(replace(migration_sql, E'\r\n', E'\n'), E'\r', E'\n'), 'UTF8')), 'hex');
 select sql_sha256 into prior_hash from private.station_setup_installations
 where migration_id = migration_name;
 if found then
  if prior_hash <> migration_hash then
   raise exception 'This migration ID was installed with different SQL. Review the migration before proceeding.'
    using errcode = '55000';
  end if;
  return;
 end if;
 execute migration_sql;
 insert into private.station_setup_installations(migration_id, sql_sha256)
 values (migration_name, migration_hash);
end $install_guard$;
commit;
