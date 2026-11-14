-- Station directory fields, station registrations, drivers, and the narrow RPCs that change them.
-- Applies after 20261102144011_access_fulfillment_messaging.sql.

alter table public.stations
  add column slug text unique check (slug ~ '^[a-z0-9][a-z0-9-]{1,59}$'),
  add column barangay text not null default '' check (char_length(barangay) <= 80),
  add column phone text not null default '' check (char_length(phone) <= 30),
  add column email text not null default '' check (char_length(email) <= 254),
  add column weekday_open time not null default '07:00',
  add column weekday_close time not null default '18:00',
  add column sunday_open time,
  add column sunday_close time,
  add column closed_notice text check (char_length(closed_notice) <= 200),
  add column created_at timestamptz not null default now(),
  add constraint stations_hours_valid check (
    weekday_close > weekday_open
    and (sunday_open is null) = (sunday_close is null)
    and (sunday_close is null or sunday_close > sunday_open));

-- Applications from businesses. Anyone may submit through submit_station_registration; only Super Admin reads.
create table public.station_registrations (
  id uuid primary key default gen_random_uuid(),
  station_name text not null check (char_length(trim(station_name)) between 2 and 120),
  barangay text not null check (char_length(trim(barangay)) between 2 and 80),
  address text not null check (char_length(trim(address)) between 3 and 300),
  station_phone text not null check (station_phone ~ '^\+639[0-9]{9}$'),
  station_email text not null default '' check (station_email = '' or (char_length(station_email) <= 254 and station_email ~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$')),
  owner_name text not null check (char_length(trim(owner_name)) between 2 and 120),
  owner_email text not null check (char_length(owner_email) <= 254 and owner_email ~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
  owner_phone text not null check (owner_phone ~ '^\+639[0-9]{9}$'),
  owner_role text not null check (char_length(trim(owner_role)) between 2 and 80),
  status text not null default 'pending' check (status in ('pending', 'approved', 'rejected')),
  decision_reason text check (char_length(decision_reason) <= 500),
  station_id uuid references public.stations(id),
  reviewed_by uuid references auth.users(id),
  reviewed_at timestamptz,
  created_at timestamptz not null default now()
);
create index station_registrations_status_idx on public.station_registrations(status, created_at desc);
create unique index station_registrations_one_pending_email on public.station_registrations(lower(owner_email)) where status = 'pending';
create index station_registrations_station_idx on public.station_registrations(station_id);

-- Drivers belong to one station; the account itself lives in auth.users.
create table public.drivers (
  user_id uuid primary key references auth.users(id) on delete cascade,
  station_id uuid not null references public.stations(id),
  full_name text not null check (char_length(trim(full_name)) between 2 and 120),
  phone text not null check (phone ~ '^\+639[0-9]{9}$'),
  email text not null check (char_length(email) <= 254),
  vehicle text not null check (vehicle in ('Motorcycle with sidecar', 'Tricycle', 'Delivery van')),
  plate_number text not null check (char_length(trim(plate_number)) between 2 and 20),
  capacity integer not null check (capacity between 1 and 100),
  active boolean not null default true,
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now()
);
create index drivers_station_idx on public.drivers(station_id);

alter table public.station_registrations enable row level security;
alter table public.drivers enable row level security;
revoke all on public.station_registrations, public.drivers from public, anon, authenticated;
grant select on public.station_registrations, public.drivers to authenticated;
create policy registrations_registry_read on public.station_registrations for select to authenticated using (private.has_role('super_admin'));
create policy drivers_station_read on public.drivers for select to authenticated using (private.station_admin(station_id));
create policy drivers_self_read on public.drivers for select to authenticated using (user_id = (select auth.uid()));
create policy drivers_registry_read on public.drivers for select to authenticated using (private.has_role('super_admin'));

-- Caller's own roles and active stations, so PHP can route a login to the right dashboard.
create function public.my_access() returns jsonb language sql stable security definer set search_path = '' as $$
  select jsonb_build_object(
    'roles', coalesce((select jsonb_agg(r.role order by r.role) from private.user_roles r where r.user_id = auth.uid()), '[]'::jsonb),
    'stations', coalesce((select jsonb_agg(jsonb_build_object('id', s.id, 'name', s.name, 'slug', s.slug) order by s.name)
      from private.station_memberships m join public.stations s on s.id = m.station_id
      where m.user_id = auth.uid() and m.active and s.active and s.registration_status = 'approved'), '[]'::jsonb))
$$;

create function public.submit_station_registration(
  p_station_name text, p_barangay text, p_address text, p_station_phone text, p_station_email text,
  p_owner_name text, p_owner_email text, p_owner_phone text, p_owner_role text
) returns uuid language plpgsql security definer set search_path = '' as $$
declare v_id uuid;
begin
  if (select count(*) from public.station_registrations where created_at > now() - interval '10 minutes') >= 20 then
    raise exception 'Too many registrations right now. Please try again later.' using errcode = '54000';
  end if;
  if exists (select 1 from public.station_registrations where lower(owner_email) = lower(trim(p_owner_email)) and status = 'pending') then
    raise exception 'A registration with this email is already waiting for review.' using errcode = '23505';
  end if;
  insert into public.station_registrations(station_name, barangay, address, station_phone, station_email, owner_name, owner_email, owner_phone, owner_role)
  values (trim(p_station_name), trim(p_barangay), trim(p_address), p_station_phone, lower(trim(coalesce(p_station_email, ''))),
          trim(p_owner_name), lower(trim(p_owner_email)), p_owner_phone, trim(p_owner_role))
  returning id into v_id;
  return v_id;
end $$;

create function public.review_station_registration(p_id uuid, p_decision text, p_reason text default null)
returns jsonb language plpgsql security definer set search_path = '' as $$
declare
  r public.station_registrations;
  v_station uuid;
  v_slug text;
begin
  if not private.has_role('super_admin') then
    raise exception 'Only the platform administrator can review registrations.' using errcode = '42501';
  end if;
  if p_decision not in ('approve', 'reject') then
    raise exception 'Unknown decision.' using errcode = '22023';
  end if;
  select * into r from public.station_registrations where id = p_id for update;
  if not found then
    raise exception 'Registration not found.' using errcode = 'P0002';
  end if;
  if r.status <> 'pending' then
    raise exception 'This registration was already reviewed.' using errcode = '55000';
  end if;
  if p_decision = 'reject' then
    if coalesce(trim(p_reason), '') = '' then
      raise exception 'A reason is required to reject a registration.' using errcode = '22023';
    end if;
    update public.station_registrations
      set status = 'rejected', decision_reason = trim(p_reason), reviewed_by = auth.uid(), reviewed_at = now()
      where id = p_id;
    return jsonb_build_object('status', 'rejected');
  end if;

  v_slug := left(trim(both '-' from regexp_replace(lower(r.station_name), '[^a-z0-9]+', '-', 'g')), 50);
  if char_length(v_slug) < 2 then v_slug := 'station'; end if;
  if exists (select 1 from public.stations where slug = v_slug) then
    v_slug := v_slug || '-' || substr(replace(p_id::text, '-', ''), 1, 6);
  end if;
  insert into public.stations(name, address, barangay, phone, email, slug, registration_status, active)
    values (r.station_name, r.address, r.barangay, r.station_phone, r.station_email, v_slug, 'approved', true)
    returning id into v_station;
  update public.station_registrations
    set status = 'approved', decision_reason = nullif(trim(coalesce(p_reason, '')), ''), station_id = v_station,
        reviewed_by = auth.uid(), reviewed_at = now()
    where id = p_id;
  return jsonb_build_object('status', 'approved', 'station_id', v_station, 'station_name', r.station_name,
                            'owner_email', r.owner_email, 'owner_name', r.owner_name);
end $$;

-- Server-only (secret key) provisioning. The PHP server creates the Auth account first, then links it here.
create function public.provision_station_member(p_user_id uuid, p_station_id uuid, p_role text)
returns void language plpgsql security definer set search_path = '' as $$
begin
  if p_role not in ('station_admin', 'rider') then
    raise exception 'Unknown station role.' using errcode = '22023';
  end if;
  if exists (select 1 from private.user_roles where user_id = p_user_id and role = 'super_admin') then
    raise exception 'Platform administrators cannot join a station.' using errcode = '42501';
  end if;
  if not exists (select 1 from public.stations where id = p_station_id and registration_status = 'approved') then
    raise exception 'The station is not approved.' using errcode = '22023';
  end if;
  insert into private.user_roles(user_id, role) values (p_user_id, p_role) on conflict do nothing;
  insert into private.station_memberships(user_id, station_id, active) values (p_user_id, p_station_id, true)
    on conflict (user_id, station_id) do update set active = true;
end $$;

create function public.provision_platform_admin(p_user_id uuid)
returns void language plpgsql security definer set search_path = '' as $$
begin
  if exists (select 1 from private.station_memberships where user_id = p_user_id and active) then
    raise exception 'Station members cannot become platform administrators.' using errcode = '42501';
  end if;
  insert into private.user_roles(user_id, role) values (p_user_id, 'super_admin') on conflict do nothing;
end $$;

create function public.register_driver(
  p_user_id uuid, p_station_id uuid, p_full_name text, p_phone text, p_email text,
  p_vehicle text, p_plate text, p_capacity integer, p_created_by uuid
) returns void language plpgsql security definer set search_path = '' as $$
begin
  -- Defense in depth: the database itself checks that the requesting admin manages this station.
  if not exists (
    select 1 from private.user_roles r join private.station_memberships m on m.user_id = r.user_id
    where r.user_id = p_created_by and r.role = 'station_admin' and m.station_id = p_station_id and m.active
  ) then
    raise exception 'Only this station''s admin can add its drivers.' using errcode = '42501';
  end if;
  perform public.provision_station_member(p_user_id, p_station_id, 'rider');
  insert into public.drivers(user_id, station_id, full_name, phone, email, vehicle, plate_number, capacity, created_by)
    values (p_user_id, p_station_id, trim(p_full_name), p_phone, lower(trim(p_email)), p_vehicle, upper(trim(p_plate)), p_capacity, p_created_by);
end $$;

revoke all on function public.my_access(), public.submit_station_registration(text, text, text, text, text, text, text, text, text),
  public.review_station_registration(uuid, text, text), public.provision_station_member(uuid, uuid, text),
  public.provision_platform_admin(uuid), public.register_driver(uuid, uuid, text, text, text, text, text, integer, uuid)
  from public, anon, authenticated;
grant execute on function public.submit_station_registration(text, text, text, text, text, text, text, text, text) to anon, authenticated;
grant execute on function public.my_access(), public.review_station_registration(uuid, text, text) to authenticated;
grant execute on function public.provision_station_member(uuid, uuid, text), public.provision_platform_admin(uuid),
  public.register_driver(uuid, uuid, text, text, text, text, text, integer, uuid) to service_role;
