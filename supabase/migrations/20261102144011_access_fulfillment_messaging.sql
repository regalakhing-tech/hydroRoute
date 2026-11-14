-- Supabase Auth owns credentials; application authorization never trusts user metadata.
create schema if not exists private;
revoke all on schema private from public, anon, authenticated;
grant usage on schema private to authenticated;

create table private.user_roles (
 user_id uuid not null references auth.users(id) on delete cascade,
 role text not null check (role in ('customer','station_admin','rider','super_admin')),
 primary key (user_id, role)
);
create table public.profiles (
 id uuid primary key references auth.users(id) on delete cascade,
 display_name text not null default '' check (char_length(display_name) <= 120)
);
create table public.stations (
 id uuid primary key default gen_random_uuid(), name text not null check (char_length(trim(name)) between 1 and 120),
 address text not null, active boolean not null default true,
 registration_status text not null default 'pending' check (registration_status in ('pending','approved','rejected','suspended','archived'))
);
create table private.station_memberships (
 user_id uuid not null references auth.users(id) on delete cascade,
 station_id uuid not null references public.stations(id) on delete cascade,
 active boolean not null default true, primary key (user_id, station_id)
);
revoke all on private.user_roles, private.station_memberships from public, anon, authenticated;
create index station_memberships_station_idx on private.station_memberships(station_id);
create table public.addresses (
 id uuid primary key default gen_random_uuid(), customer_id uuid not null default auth.uid() references auth.users(id) on delete cascade,
 label text not null check (char_length(trim(label)) between 1 and 120),
 address text not null check (char_length(trim(address)) between 1 and 500),
 unique (id, customer_id)
);
create index addresses_customer_idx on public.addresses(customer_id);
create table public.products (
 id uuid primary key default gen_random_uuid(), station_id uuid not null references public.stations(id),
 name text not null check (char_length(trim(name)) between 1 and 120),
 price_centavos integer not null check (price_centavos >= 0), active boolean not null default true,
 unique (id, station_id)
);
create index products_station_idx on public.products(station_id);
create table public.orders (
 id uuid primary key default gen_random_uuid(), station_id uuid not null references public.stations(id),
 customer_id uuid not null references auth.users(id), fulfillment text not null check (fulfillment in ('delivery','pickup')),
 address_id uuid, driver_id uuid references auth.users(id),
 status text not null default 'pending', created_at timestamptz not null default now(),
 unique (id, station_id), unique (id, station_id, customer_id),
 foreign key (address_id, customer_id) references public.addresses(id, customer_id),
 check ((fulfillment = 'delivery' and address_id is not null and status in ('pending','confirmed','preparing','ready','out_for_delivery','delivered','cancelled')) or
        (fulfillment = 'pickup' and address_id is null and driver_id is null and status in ('pending','confirmed','preparing','ready','collected','cancelled')))
);
create index orders_customer_idx on public.orders(customer_id, created_at desc);
create index orders_station_idx on public.orders(station_id, created_at desc);
create index orders_address_idx on public.orders(address_id, customer_id);
create index orders_driver_idx on public.orders(driver_id);
create table public.order_items (
 id uuid primary key default gen_random_uuid(), order_id uuid not null, station_id uuid not null,
 product_id uuid not null, product_name text not null check (char_length(trim(product_name)) between 1 and 120),
 quantity integer not null check (quantity between 1 and 10000),
 unit_price_centavos integer not null check (unit_price_centavos between 0 and 100000000),
 line_total_centavos bigint generated always as (quantity::bigint * unit_price_centavos::bigint) stored,
 foreign key (order_id, station_id) references public.orders(id, station_id) on delete cascade,
 foreign key (product_id, station_id) references public.products(id, station_id)
);
create index order_items_order_idx on public.order_items(order_id, station_id);
create index order_items_product_idx on public.order_items(product_id, station_id);
create table public.conversations (
 id uuid primary key default gen_random_uuid(), station_id uuid not null references public.stations(id),
 customer_id uuid not null references auth.users(id), order_id uuid,
 created_at timestamptz not null default now(), unique(station_id, customer_id),
 foreign key (order_id, station_id, customer_id) references public.orders(id, station_id, customer_id)
);
create index conversations_customer_idx on public.conversations(customer_id);
create index conversations_station_idx on public.conversations(station_id);
create index conversations_order_idx on public.conversations(order_id, station_id, customer_id);
create table public.messages (
 id uuid primary key default gen_random_uuid(), conversation_id uuid not null references public.conversations(id) on delete cascade,
 sender_id uuid not null default auth.uid() references auth.users(id),
 body text not null check (char_length(body) between 1 and 2000 and body ~ '[^[:space:]]'), created_at timestamptz not null default now()
);
create index messages_timeline_idx on public.messages(conversation_id, created_at, id);
create index messages_sender_idx on public.messages(sender_id);
create table public.read_receipts (
 conversation_id uuid not null references public.conversations(id) on delete cascade,
 user_id uuid not null default auth.uid() references auth.users(id), read_at timestamptz not null default now(),
 primary key (conversation_id, user_id)
);
create index read_receipts_user_idx on public.read_receipts(user_id);

-- Only these narrowly scoped authorization lookups bypass RLS. They use the caller's UID.
create function private.has_role(wanted text) returns boolean language sql stable security definer set search_path = '' as $$
 select auth.uid() is not null and exists (select 1 from private.user_roles where user_id = auth.uid() and role = wanted)
$$;
create function private.station_admin(sid uuid) returns boolean language sql stable security definer set search_path = '' as $$
 select auth.uid() is not null and not private.has_role('super_admin') and private.has_role('station_admin')
 and exists (select 1 from private.station_memberships where user_id = auth.uid() and station_id = sid and active)
$$;
create function private.can_access_customer_data(sid uuid, cid uuid) returns boolean language sql stable security definer set search_path = '' as $$
 select auth.uid() is not null and not private.has_role('super_admin') and
 ((auth.uid() = cid and private.has_role('customer')) or private.station_admin(sid))
$$;
create function private.can_access_conversation(cid uuid) returns boolean language sql stable security definer set search_path = '' as $$
 select auth.uid() is not null and not private.has_role('rider') and exists (select 1 from public.conversations c where c.id = cid and private.can_access_customer_data(c.station_id, c.customer_id))
$$;
revoke all on function private.has_role(text), private.station_admin(uuid), private.can_access_customer_data(uuid,uuid), private.can_access_conversation(uuid) from public, anon, authenticated;
grant execute on function private.has_role(text), private.station_admin(uuid), private.can_access_customer_data(uuid,uuid), private.can_access_conversation(uuid) to authenticated;

-- Trigger is not callable as a client API, and does not rely on session UID.
create function private.bootstrap_customer() returns trigger language plpgsql security definer set search_path = '' as $$
begin
 if tg_op <> 'INSERT' or tg_table_schema <> 'auth' or tg_table_name <> 'users' then raise exception 'invalid trigger context'; end if;
 insert into public.profiles(id) values (new.id) on conflict do nothing;
 insert into private.user_roles(user_id, role) values (new.id, 'customer') on conflict do nothing;
 return new;
end $$;
revoke all on function private.bootstrap_customer() from public, anon, authenticated;
create trigger bootstrap_customer after insert on auth.users for each row execute function private.bootstrap_customer();
insert into public.profiles(id) select id from auth.users on conflict do nothing;
insert into private.user_roles(user_id, role) select id, 'customer' from auth.users on conflict do nothing;

alter table private.user_roles enable row level security;
alter table private.station_memberships enable row level security;
alter table public.profiles enable row level security;
alter table public.stations enable row level security;
alter table public.addresses enable row level security;
alter table public.products enable row level security;
alter table public.orders enable row level security;
alter table public.order_items enable row level security;
alter table public.conversations enable row level security;
alter table public.messages enable row level security;
alter table public.read_receipts enable row level security;
revoke all on public.profiles, public.stations, public.addresses, public.products, public.orders, public.order_items, public.conversations, public.messages, public.read_receipts from public, anon, authenticated;
grant select on public.stations, public.products to anon, authenticated;
grant select on public.profiles, public.addresses, public.orders, public.order_items, public.conversations, public.messages, public.read_receipts to authenticated;
grant update(display_name) on public.profiles to authenticated;
grant insert(label, address), update(label, address), delete on public.addresses to authenticated;
grant insert(conversation_id, body) on public.messages to authenticated;
grant insert(conversation_id) on public.read_receipts to authenticated;
create policy profiles_read on public.profiles for select to authenticated using (id = (select auth.uid()));
create policy profiles_update on public.profiles for update to authenticated using (id = (select auth.uid())) with check (id = (select auth.uid()));
create policy stations_read on public.stations for select to anon, authenticated using (active and registration_status = 'approved');
create policy stations_registry_read on public.stations for select to authenticated using (private.has_role('super_admin'));
create policy stations_member_read on public.stations for select to authenticated using (private.station_admin(id));
create policy products_read on public.products for select to anon, authenticated using (active and exists (select 1 from public.stations s where s.id = station_id and s.active and s.registration_status = 'approved'));
create policy products_member_read on public.products for select to authenticated using (private.station_admin(station_id));
create policy addresses_read on public.addresses for select to authenticated using (customer_id = (select auth.uid()) and private.has_role('customer') and not private.has_role('super_admin'));
create policy addresses_station_read on public.addresses for select to authenticated using (exists (select 1 from public.orders o where o.address_id = addresses.id and o.customer_id = addresses.customer_id and private.station_admin(o.station_id)));
create policy addresses_insert on public.addresses for insert to authenticated with check (customer_id = (select auth.uid()) and private.has_role('customer') and not private.has_role('super_admin'));
create policy addresses_update on public.addresses for update to authenticated using (customer_id = (select auth.uid()) and private.has_role('customer') and not private.has_role('super_admin')) with check (customer_id = (select auth.uid()) and private.has_role('customer') and not private.has_role('super_admin'));
create policy addresses_delete on public.addresses for delete to authenticated using (customer_id = (select auth.uid()) and private.has_role('customer') and not private.has_role('super_admin'));
create policy orders_read on public.orders for select to authenticated using (private.can_access_customer_data(station_id, customer_id));
create policy items_read on public.order_items for select to authenticated using (exists (select 1 from public.orders o where o.id = order_items.order_id and o.station_id = order_items.station_id));
create policy conversations_read on public.conversations for select to authenticated using (not private.has_role('rider') and private.can_access_customer_data(station_id, customer_id));
create policy messages_read on public.messages for select to authenticated using (private.can_access_conversation(conversation_id));
create policy messages_insert on public.messages for insert to authenticated with check (sender_id = (select auth.uid()) and private.can_access_conversation(conversation_id));
create policy receipts_read on public.read_receipts for select to authenticated using (private.can_access_conversation(conversation_id));
create policy receipts_insert on public.read_receipts for insert to authenticated with check (user_id = (select auth.uid()) and private.can_access_conversation(conversation_id));


-- A receipt refresh always uses the database clock, even if a client supplies a value.
create function private.stamp_receipt() returns trigger language plpgsql set search_path = '' as $$
begin
 if tg_op <> 'UPDATE' or tg_table_schema <> 'public' or tg_table_name <> 'read_receipts' then raise exception 'invalid trigger context'; end if;
 new.read_at := now();
 return new;
end $$;
revoke all on function private.stamp_receipt() from public, anon, authenticated;
create trigger stamp_receipt before update on public.read_receipts for each row execute function private.stamp_receipt();
grant update(read_at) on public.read_receipts to authenticated;
create policy receipts_update on public.read_receipts for update to authenticated
 using (user_id = (select auth.uid()) and private.can_access_conversation(conversation_id))
 with check (user_id = (select auth.uid()) and private.can_access_conversation(conversation_id));
