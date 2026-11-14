-- Phase 7: live inbox and chat (system plan M12) and reservations that become scheduled orders.
-- Requires 20261120000200_operations_dispatch.sql. Paste the whole file into the Supabase SQL Editor.
-- Existing messages keep working; client INSERT/UPDATE on messages and receipts moves behind the RPCs below.
begin;

alter table public.conversations add column if not exists version int not null default 1, add column if not exists last_message_at timestamptz;
do $$begin
 if not exists (select 1 from pg_constraint where conname = 'conversations_scope_key') then
  alter table public.conversations add constraint conversations_scope_key unique (id, station_id, customer_id);
 end if;
end $$;
update public.conversations c set last_message_at = (select max(created_at) from public.messages m where m.conversation_id = c.id) where last_message_at is null;

alter table public.messages add column if not exists station_id uuid, add column if not exists customer_id uuid,
 add column if not exists kind text not null default 'text' check (kind in ('text','reservation','system')),
 add column if not exists topic text not null default 'general' check (topic in ('general','reservation','order','delivery')),
 add column if not exists order_id uuid, add column if not exists reservation_id uuid, add column if not exists request_key uuid;
update public.messages m set station_id = c.station_id, customer_id = c.customer_id from public.conversations c where c.id = m.conversation_id and m.station_id is null;
alter table public.messages drop constraint if exists messages_scope_fkey;
alter table public.messages add constraint messages_scope_fkey foreign key (conversation_id, station_id, customer_id) references public.conversations(id, station_id, customer_id);
alter table public.messages drop constraint if exists messages_order_fkey;
alter table public.messages add constraint messages_order_fkey foreign key (order_id, station_id, customer_id) references public.orders(id, station_id, customer_id);
create index if not exists messages_sender_time_idx on public.messages(sender_id, created_at, id);
create index if not exists messages_order_idx on public.messages(order_id, station_id, customer_id);
create unique index if not exists messages_request_idx on public.messages(conversation_id, sender_id, request_key) where request_key is not null;

alter table public.read_receipts add column if not exists last_message_id uuid references public.messages(id) on delete set null;
-- Receipts now advance only to a message the reader saw (mark_conversation_read), so the now() stamp goes.
drop trigger if exists stamp_receipt on public.read_receipts;
revoke insert, update, delete on public.messages, public.read_receipts from authenticated;

create table if not exists public.reservations (
 id uuid primary key default gen_random_uuid(), station_id uuid not null references public.stations(id), customer_id uuid not null references auth.users(id),
 conversation_id uuid not null, requested_at timestamptz not null, fulfillment text not null check (fulfillment in ('delivery','pickup')),
 address_id uuid, note text not null default '' check (length(note) <= 500),
 state text not null default 'requested' check (state in ('requested','accepted','declined','cancelled','expired')),
 decision_reason text check (length(decision_reason) <= 500), decided_by uuid references auth.users(id), decided_at timestamptz,
 order_id uuid unique, version int not null default 1, request_key uuid not null, created_at timestamptz not null default now(),
 unique (id, station_id), unique (station_id, customer_id, request_key),
 foreign key (conversation_id, station_id, customer_id) references public.conversations(id, station_id, customer_id),
 foreign key (address_id, customer_id) references public.addresses(id, customer_id),
 foreign key (order_id, station_id, customer_id) references public.orders(id, station_id, customer_id),
 check ((fulfillment = 'delivery') = (address_id is not null)));
create index if not exists reservations_station_idx on public.reservations(station_id, state, requested_at);
create index if not exists reservations_customer_idx on public.reservations(customer_id, created_at);
create index if not exists reservations_conversation_idx on public.reservations(conversation_id, station_id, customer_id);
create index if not exists reservations_address_idx on public.reservations(address_id, customer_id);
create table if not exists public.reservation_items (
 id uuid primary key default gen_random_uuid(), station_id uuid not null, reservation_id uuid not null, product_id uuid not null,
 quantity int not null check (quantity between 1 and 10000), unique (reservation_id, product_id),
 foreign key (reservation_id, station_id) references public.reservations(id, station_id) on delete cascade,
 foreign key (product_id, station_id) references public.products(id, station_id));
create index if not exists reservation_items_product_idx on public.reservation_items(product_id, station_id);
alter table public.messages drop constraint if exists messages_reservation_fkey;
alter table public.messages add constraint messages_reservation_fkey foreign key (reservation_id, station_id) references public.reservations(id, station_id);
create index if not exists messages_reservation_idx on public.messages(reservation_id, station_id);
create table if not exists private.message_rate_limits (sender_id uuid primary key references auth.users(id) on delete cascade, updated_at timestamptz not null default now());

alter table public.reservations enable row level security;
alter table public.reservation_items enable row level security;
alter table private.message_rate_limits enable row level security;
revoke all on public.reservations, public.reservation_items, private.message_rate_limits from public, anon, authenticated;

-- Helpers ---------------------------------------------------------------------------------------------
-- Customers and that station's admins only. Riders and Super Admin never take part (M12).
create or replace function private.chat_role(c public.conversations) returns text language plpgsql stable security definer set search_path = '' as $$
begin
 if auth.uid() is null or private.has_role('super_admin') or private.has_role('rider') then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 if c.customer_id = auth.uid() and private.has_role('customer') then return 'customer'; end if;
 if private.station_admin(c.station_id) then return 'station'; end if;
 raise exception 'Conversation access denied.' using errcode = '42501';
end $$;

create or replace function private.post_message(c public.conversations, who text, txt text, top text, k text, oid uuid, rid uuid, key uuid) returns uuid language plpgsql security definer set search_path = '' as $$
declare mid uuid; at timestamptz; slot text; sname text;
begin
 insert into public.messages(conversation_id, sender_id, body, station_id, customer_id, kind, topic, order_id, reservation_id, request_key)
 values (c.id, auth.uid(), txt, c.station_id, c.customer_id, k, top, oid, rid, key) returning id, created_at into mid, at;
 update public.conversations set last_message_at = at, version = version + 1 where id = c.id;
 insert into public.read_receipts as rr(conversation_id, user_id, read_at, last_message_id) values (c.id, auth.uid(), at, mid)
 on conflict (conversation_id, user_id) do update set read_at = greatest(rr.read_at, excluded.read_at), last_message_id = excluded.last_message_id;
 slot := 'chat:' || c.id || ':' || to_char(at at time zone 'Asia/Manila', 'YYYYMMDDHH24');
 if who = 'customer' then
  perform private.notify_station(c.station_id, slot, 'message', null, 'New message from a customer', left(txt, 200));
 else
  select name into sname from public.stations where id = c.station_id;
  perform private.notify(c.station_id, c.customer_id, slot, 'message', null, 'New message from ' || sname, left(txt, 200));
 end if;
 return mid;
end $$;

create or replace function private.rate_limit() returns boolean language plpgsql security definer set search_path = '' as $$
begin
 insert into private.message_rate_limits as l(sender_id) values (auth.uid()) on conflict (sender_id) do update set updated_at = now();
 return (select count(*) from public.messages where sender_id = auth.uid() and created_at > now() - interval '60 seconds') < 20;
end $$;

create or replace function private.reservation_card(r public.reservations) returns jsonb language sql stable security definer set search_path = '' as $$
 select jsonb_build_object('id', r.id, 'state', r.state, 'version', r.version, 'requested_at', r.requested_at, 'fulfillment', r.fulfillment, 'note', r.note,
  'address', (select a.label || ' — ' || a.address from public.addresses a where a.id = r.address_id), 'decision_reason', r.decision_reason, 'order_id', r.order_id,
  'order_no', (select order_no from public.orders where id = r.order_id),
  'items', coalesce((select jsonb_agg(jsonb_build_object('product_id', i.product_id, 'name', p.name, 'quantity', i.quantity) order by p.name) from public.reservation_items i join public.products p on p.id = i.product_id where i.reservation_id = r.id), '[]'))
$$;

-- Chat RPCs -------------------------------------------------------------------------------------------
create or replace function public.start_conversation(p_station_id uuid, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare cid uuid;
begin
 perform private.customer_guard();
 if private.has_role('rider') then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 if not exists (select 1 from public.stations where id = p_station_id and active and registration_status = 'approved') then return private.envelope('{}', 'not_found'); end if;
 insert into public.conversations(station_id, customer_id) values (p_station_id, auth.uid()) on conflict (station_id, customer_id) do nothing;
 select id into cid from public.conversations where station_id = p_station_id and customer_id = auth.uid();
 return private.envelope(jsonb_build_object('conversation_id', cid));
end $$;

create or replace function public.send_message(p_conversation_id uuid, p_body text, p_topic text, p_order_id uuid, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare c public.conversations; who text; r jsonb; txt text := trim(coalesce(p_body, '')); top text := coalesce(nullif(p_topic, ''), 'general');
 payload jsonb := jsonb_build_object('conversation_id', p_conversation_id, 'body', p_body, 'topic', p_topic, 'order_id', p_order_id);
begin
 select * into c from public.conversations where id = p_conversation_id;
 if not found then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 who := private.chat_role(c);
 r := private.replay(c.id::text, 'send_message', p_request_key, payload);
 if r is not null then return r; end if;
 if length(txt) not between 1 and 2000 then return private.envelope('{}', 'validation', jsonb_build_object('body', 'Write a message of up to 2,000 characters.')); end if;
 if top not in ('general','reservation','order','delivery') then return private.envelope('{}', 'validation', jsonb_build_object('topic', 'Choose a topic.')); end if;
 if p_order_id is not null and not exists (select 1 from public.orders where id = p_order_id and station_id = c.station_id and customer_id = c.customer_id) then
  return private.envelope('{}', 'validation', jsonb_build_object('order_id', 'Link one of this customer''s orders at this station.'));
 end if;
 if not private.rate_limit() then return private.envelope('{}', 'rate_limited', jsonb_build_object('body', 'You are sending messages too quickly. Wait a minute and try again.')); end if;
 return private.remember(c.id::text, 'send_message', p_request_key, payload,
  private.envelope(jsonb_build_object('message_id', private.post_message(c, who, txt, top, 'text', p_order_id, null, p_request_key), 'conversation_id', c.id)));
end $$;

create or replace function public.list_inbox(p_station_id uuid, p_filters jsonb, p_cursor jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare rows jsonb; term text := lower(trim(coalesce(p_filters->>'search', ''))); top text := nullif(p_filters->>'topic', ''); only_unread boolean := coalesce((p_filters->>'unread')::boolean, false);
begin
 if auth.uid() is null or private.has_role('super_admin') or private.has_role('rider') then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 if p_station_id is null then perform private.customer_guard(); elsif not private.station_admin(p_station_id) then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 select coalesce(jsonb_agg(x.j order by x.at desc nulls last, x.id), '[]') into rows from (
  select c.id, coalesce(c.last_message_at, c.created_at) at, jsonb_build_object('id', c.id, 'station_id', c.station_id, 'customer_id', c.customer_id,
   'name', case when p_station_id is null then s.name else coalesce(nullif(pf.display_name, ''), u.raw_user_meta_data->>'full_name', 'Customer') end,
   'last_body', lm.body, 'last_kind', lm.kind, 'last_at', lm.created_at, 'last_mine', lm.sender_id = auth.uid(), 'unread', un.n) j
  from public.conversations c join public.stations s on s.id = c.station_id
  left join public.profiles pf on pf.id = c.customer_id left join auth.users u on u.id = c.customer_id
  left join public.read_receipts rr on rr.conversation_id = c.id and rr.user_id = auth.uid()
  left join lateral (select m.body, m.kind, m.created_at, m.sender_id from public.messages m where m.conversation_id = c.id order by m.created_at desc, m.id desc limit 1) lm on true
  cross join lateral (select count(*) n from public.messages m where m.conversation_id = c.id and m.sender_id <> auth.uid() and m.created_at > coalesce(rr.read_at, '-infinity'::timestamptz)) un
  where ((p_station_id is null and c.customer_id = auth.uid()) or c.station_id = p_station_id)
   and (term = '' or lower(case when p_station_id is null then s.name else coalesce(nullif(pf.display_name, ''), u.raw_user_meta_data->>'full_name', '') end) like '%' || term || '%'
    or exists (select 1 from public.messages m where m.conversation_id = c.id and lower(m.body) like '%' || term || '%'))
   and (top is null or exists (select 1 from public.messages m where m.conversation_id = c.id and m.topic = top))
   and (not only_unread or un.n > 0)
  order by coalesce(c.last_message_at, c.created_at) desc limit 100) x;
 return private.envelope(jsonb_build_object('conversations', rows));
end $$;

create or replace function public.get_messages(p_conversation_id uuid, p_after jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare c public.conversations; who text; msgs jsonb; av jsonb; st public.stations;
begin
 select * into c from public.conversations where id = p_conversation_id;
 if not found then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 who := private.chat_role(c);
 select * into st from public.stations where id = c.station_id;
 av := private.availability(c.station_id, null);
 select coalesce(jsonb_agg(x.j order by x.created_at, x.id), '[]') into msgs from (
  select * from (select m.created_at, m.id, jsonb_build_object('id', m.id, 'body', m.body, 'kind', m.kind, 'topic', m.topic, 'created_at', m.created_at,
    'mine', m.sender_id = auth.uid(), 'side', case when m.sender_id = c.customer_id then 'customer' else 'station' end,
    'order_id', m.order_id, 'order_no', o.order_no, 'reservation', case when r.id is not null then private.reservation_card(r) end) j
   from public.messages m left join public.orders o on o.id = m.order_id left join public.reservations r on r.id = m.reservation_id
   where m.conversation_id = c.id and (p_after->>'created_at' is null or (m.created_at, m.id) > ((p_after->>'created_at')::timestamptz, (p_after->>'id')::uuid))
   order by m.created_at desc, m.id desc limit 200) y) x;
 return private.envelope(jsonb_build_object('role', who, 'messages', msgs,
  'conversation', jsonb_build_object('id', c.id, 'station_id', c.station_id, 'customer_id', c.customer_id, 'version', c.version,
   'station_name', st.name, 'customer_name', (select coalesce(nullif(pf.display_name, ''), u.raw_user_meta_data->>'full_name', 'Customer') from auth.users u left join public.profiles pf on pf.id = u.id where u.id = c.customer_id),
   'availability', jsonb_build_object('label', av->>'label', 'accepting_asap', av->'accepting_asap', 'public_notice', av->>'public_notice', 'next_open_at', av->'next_open_at')),
  'orders', coalesce((select jsonb_agg(jsonb_build_object('id', o.id, 'order_no', o.order_no, 'status', o.status) order by o.created_at desc) from (select * from public.orders where station_id = c.station_id and customer_id = c.customer_id order by created_at desc limit 20) o), '[]'),
  'products', case when who = 'customer' then coalesce((select jsonb_agg(jsonb_build_object('id', p.id, 'name', p.name, 'price_centavos', p.price_centavos) order by p.name) from public.products p where p.station_id = c.station_id and p.active), '[]') end,
  'addresses', case when who = 'customer' then coalesce((select jsonb_agg(jsonb_build_object('id', a.id, 'label', a.label, 'address', a.address, 'pinned', a.latitude is not null) order by a.label) from public.addresses a where a.customer_id = auth.uid() and a.deleted_at is null), '[]') end));
end $$;

create or replace function public.mark_conversation_read(p_conversation_id uuid, p_last_seen_message_id uuid, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare c public.conversations; seen timestamptz;
begin
 select * into c from public.conversations where id = p_conversation_id;
 if not found then raise exception 'Conversation access denied.' using errcode = '42501'; end if;
 perform private.chat_role(c);
 select created_at into seen from public.messages where id = p_last_seen_message_id and conversation_id = c.id;
 if seen is null then return private.envelope('{}', 'validation', jsonb_build_object('message', 'That message is not in this conversation.')); end if;
 insert into public.read_receipts as rr(conversation_id, user_id, read_at, last_message_id) values (c.id, auth.uid(), seen, p_last_seen_message_id)
 on conflict (conversation_id, user_id) do update set read_at = greatest(rr.read_at, excluded.read_at),
  last_message_id = case when excluded.read_at >= rr.read_at then excluded.last_message_id else rr.last_message_id end;
 return private.envelope(jsonb_build_object('conversation_id', c.id));
end $$;

-- Reservations ----------------------------------------------------------------------------------------
create or replace function public.request_reservation(p_station_id uuid, p_payload jsonb, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare r jsonb; at_time timestamptz; ful text := p_payload->>'fulfillment'; aid uuid; terms jsonb; c public.conversations; rid uuid; n_in int; n_ok int; rr public.reservations;
begin
 perform private.customer_guard();
 if private.has_role('rider') then raise exception 'Reservation access denied.' using errcode = '42501'; end if;
 if not exists (select 1 from public.stations where id = p_station_id and active and registration_status = 'approved') then return private.envelope('{}', 'not_found'); end if;
 r := private.replay(p_station_id::text, 'request_reservation', p_request_key, p_payload);
 if r is not null then return r; end if;
 if jsonb_typeof(p_payload) is distinct from 'object' or exists (select 1 from jsonb_object_keys(p_payload) k where k not in ('requested_at','fulfillment','items','address_id','note')) then
  return private.envelope('{}', 'validation', jsonb_build_object('reservation', 'Check the reservation form.'));
 end if;
 begin at_time := (nullif(p_payload->>'requested_at', '')::timestamp at time zone 'Asia/Manila');
 exception when invalid_datetime_format or datetime_field_overflow or invalid_text_representation then at_time := null; end;
 if at_time is null or at_time <= now() or at_time > now() + interval '90 days' then return private.envelope('{}', 'validation', jsonb_build_object('requested_at', 'Choose a future date within 90 days.')); end if;
 if ful is null or ful not in ('delivery','pickup') then return private.envelope('{}', 'validation', jsonb_build_object('fulfillment', 'Choose delivery or pickup.')); end if;
 if length(coalesce(p_payload->>'note', '')) > 500 then return private.envelope('{}', 'validation', jsonb_build_object('note', 'Keep the note to 500 characters.')); end if;
 if jsonb_typeof(p_payload->'items') is distinct from 'array' or jsonb_array_length(p_payload->'items') not between 1 and 50
  or exists (select 1 from jsonb_array_elements(p_payload->'items') e where coalesce(e->>'quantity', '') !~ '^[0-9]{1,4}$' or (e->>'quantity')::int < 1 or not private.uuid_text(e->>'product_id')) then
  return private.envelope('{}', 'validation', jsonb_build_object('items', 'Choose products and whole-number quantities.'));
 end if;
 select count(distinct (e->>'product_id')::uuid), count(distinct p.id) into n_in, n_ok from jsonb_array_elements(p_payload->'items') e
  left join public.products p on p.id = (e->>'product_id')::uuid and p.station_id = p_station_id and p.active;
 if n_ok < n_in then return private.envelope('{}', 'product_unavailable', jsonb_build_object('items', 'A product you chose is not available at this station.')); end if;
 if ful = 'delivery' then
  if not private.uuid_text(p_payload->>'address_id') then return private.envelope('{}', 'validation', jsonb_build_object('address_id', 'Choose a delivery address.')); end if;
  aid := (p_payload->>'address_id')::uuid;
  terms := private.delivery_terms(p_station_id, aid, auth.uid());
  if not (terms->>'eligible')::boolean then return private.envelope('{}', terms->>'code', jsonb_build_object('address_id', terms->>'message')); end if;
 end if;
 insert into public.conversations(station_id, customer_id) values (p_station_id, auth.uid()) on conflict (station_id, customer_id) do nothing;
 select * into c from public.conversations where station_id = p_station_id and customer_id = auth.uid() for update;
 insert into public.reservations(station_id, customer_id, conversation_id, requested_at, fulfillment, address_id, note, request_key)
 values (p_station_id, auth.uid(), c.id, at_time, ful, aid, coalesce(trim(p_payload->>'note'), ''), p_request_key) returning * into rr;
 rid := rr.id;
 insert into public.reservation_items(station_id, reservation_id, product_id, quantity)
 select p_station_id, rid, (e->>'product_id')::uuid, sum((e->>'quantity')::int) from jsonb_array_elements(p_payload->'items') e group by 3;
 perform private.post_message(c, 'customer', 'Reservation request for ' || to_char(at_time at time zone 'Asia/Manila', 'Dy, Mon DD, HH12:MI AM') || ' (' || ful || ').', 'reservation', 'reservation', null, rid, null);
 return private.remember(p_station_id::text, 'request_reservation', p_request_key, p_payload, private.envelope(jsonb_build_object('reservation_id', rid, 'conversation_id', c.id)));
end $$;

create or replace function public.cancel_reservation(p_reservation_id uuid, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rr public.reservations; c public.conversations; r jsonb; payload jsonb := jsonb_build_object('reservation_id', p_reservation_id, 'expected_version', p_expected_version);
begin
 perform private.customer_guard();
 select * into rr from public.reservations where id = p_reservation_id and customer_id = auth.uid();
 if not found then raise exception 'Reservation access denied.' using errcode = '42501'; end if;
 r := private.replay(rr.station_id::text, 'cancel_reservation', p_request_key, payload);
 if r is not null then return r; end if;
 select * into rr from public.reservations where id = p_reservation_id for update;
 if rr.state <> 'requested' or rr.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('state', rr.state), 'stale_version'); end if;
 update public.reservations set state = 'cancelled', decided_at = now(), version = version + 1 where id = rr.id;
 select * into c from public.conversations where id = rr.conversation_id;
 perform private.post_message(c, 'customer', 'The customer cancelled the reservation request.', 'reservation', 'system', null, rr.id, null);
 return private.remember(rr.station_id::text, 'cancel_reservation', p_request_key, payload, private.envelope(jsonb_build_object('reservation_id', rr.id, 'state', 'cancelled')));
end $$;

-- Accepting reprices current products and rechecks the normal booking window, hours, limits and distance (D6),
-- then creates one scheduled order, its snapshot and status log, a system card and notices in one transaction.
create or replace function public.respond_reservation(p_reservation_id uuid, p_decision text, p_reason text, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rr public.reservations; c public.conversations; r jsonb; q jsonb; o jsonb; why text := trim(coalesce(p_reason, ''));
 payload jsonb := jsonb_build_object('reservation_id', p_reservation_id, 'decision', p_decision, 'reason', p_reason, 'expected_version', p_expected_version);
begin
 select * into rr from public.reservations where id = p_reservation_id;
 if not found or not private.station_admin(rr.station_id) or private.has_role('rider') then raise exception 'Reservation access denied.' using errcode = '42501'; end if;
 r := private.replay(rr.station_id::text, 'respond_reservation', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(rr.station_id);
 perform 1 from public.stations where id = rr.station_id for share;
 perform 1 from public.station_availability where station_id = rr.station_id for share;
 select * into rr from public.reservations where id = p_reservation_id for update;
 if rr.state <> 'requested' or rr.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('state', rr.state), 'stale_version'); end if;
 select * into c from public.conversations where id = rr.conversation_id;
 if p_decision = 'decline' then
  if length(why) not between 3 and 500 then return private.envelope('{}', 'validation', jsonb_build_object('reason', 'Tell the customer why you are declining.')); end if;
  update public.reservations set state = 'declined', decision_reason = why, decided_by = auth.uid(), decided_at = now(), version = version + 1 where id = rr.id;
  perform private.post_message(c, 'station', 'Reservation declined: ' || why, 'reservation', 'system', null, rr.id, null);
  perform private.notify(rr.station_id, rr.customer_id, 'reservation:' || rr.id || ':declined', 'reservation_declined', null, 'Reservation declined', why);
  return private.remember(rr.station_id::text, 'respond_reservation', p_request_key, payload, private.envelope(jsonb_build_object('reservation_id', rr.id, 'state', 'declined')));
 elsif p_decision is distinct from 'accept' then
  return private.envelope('{}', 'validation', jsonb_build_object('decision', 'Choose accept or decline.'));
 end if;
 q := private.price_order(rr.station_id, rr.customer_id, jsonb_strip_nulls(jsonb_build_object('fulfillment', rr.fulfillment, 'address_id', rr.address_id, 'schedule', 'scheduled',
   'requested_at', to_char(rr.requested_at at time zone 'Asia/Manila', 'YYYY-MM-DD"T"HH24:MI:SS'), 'note', left(rr.note, 200),
   'items', (select jsonb_agg(jsonb_build_object('product_id', product_id, 'quantity', quantity)) from public.reservation_items where reservation_id = rr.id))), now());
 if not (q->>'ok')::boolean then return q; end if;
 o := private.insert_order(rr.station_id, rr.customer_id, q->'data', 'scheduled', p_request_key, null, null, 'Accepted reservation');
 update public.reservations set state = 'accepted', order_id = (o->>'order_id')::uuid, decision_reason = nullif(why, ''), decided_by = auth.uid(), decided_at = now(), version = version + 1 where id = rr.id;
 perform private.post_message(c, 'station', 'Reservation accepted. Order ' || (o->>'order_no') || ' is scheduled for ' || to_char(rr.requested_at at time zone 'Asia/Manila', 'Dy, Mon DD, HH12:MI AM') || '.',
  'reservation', 'system', (o->>'order_id')::uuid, rr.id, null);
 perform private.notify(rr.station_id, rr.customer_id, 'reservation:' || rr.id || ':accepted', 'reservation_accepted', (o->>'order_id')::uuid, 'Reservation accepted',
  'Order ' || (o->>'order_no') || ' is scheduled. Total ' || to_char((q->'data'->>'total_centavos')::bigint / 100.0, 'FM999,990.00') || ' pesos.');
 return private.remember(rr.station_id::text, 'respond_reservation', p_request_key, payload,
  private.envelope(jsonb_build_object('reservation_id', rr.id, 'state', 'accepted', 'order_id', o->'order_id', 'order_no', o->'order_no')));
end $$;

do $$declare f record; begin
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'private' and p.proname in
  ('chat_role','post_message','rate_limit','reservation_card') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
 end loop;
 for f in select p.oid::regprocedure sig from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname in
  ('start_conversation','send_message','list_inbox','get_messages','mark_conversation_read','request_reservation','cancel_reservation','respond_reservation') loop
  execute 'revoke all on function ' || f.sig || ' from public, anon, authenticated, service_role';
  execute 'grant execute on function ' || f.sig || ' to authenticated';
 end loop;
end $$;
commit;
