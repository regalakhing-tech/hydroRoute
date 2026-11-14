-- Navigation badges and new-notice toasts.
-- get_navigation_counts now also returns:
--   orders: station scope = orders waiting for the station (pending, on hold or confirmed and not started);
--           customer scope = the customer's open orders.
--   latest: the newest unread notice in the same scope (the page shows it once as a clickable toast).
-- The signature is unchanged, so existing grants (authenticated only) stay in place.
create or replace function public.get_navigation_counts(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare notes int; msgs int; open_orders int; latest jsonb;
begin
 perform private.notice_scope(p_station_id);
 select count(*) into notes from public.notifications n where n.recipient_id = auth.uid() and n.read_at is null
  and ((p_station_id is null and n.kind not like 'staff\_%') or (n.station_id = p_station_id and n.kind like 'staff\_%'));
 select count(*) into msgs from public.messages m join public.conversations c on c.id = m.conversation_id
  left join public.read_receipts rr on rr.conversation_id = c.id and rr.user_id = auth.uid()
  where m.sender_id <> auth.uid() and m.created_at > coalesce(rr.read_at, '-infinity'::timestamptz)
   and ((p_station_id is null and c.customer_id = auth.uid()) or (c.station_id = p_station_id and m.sender_id = c.customer_id));
 if p_station_id is null then
  select count(*) into open_orders from public.orders o where o.customer_id = auth.uid()
   and o.status in ('pending','on_hold','scheduled','confirmed','preparing','ready','out_for_delivery');
 else
  select count(*) into open_orders from public.orders o where o.station_id = p_station_id and o.status in ('pending','on_hold','confirmed');
 end if;
 select jsonb_build_object('id', n.id, 'kind', n.kind, 'title', n.title, 'body', n.body, 'order_id', n.order_id, 'created_at', n.created_at) into latest
  from public.notifications n where n.recipient_id = auth.uid() and n.read_at is null
   and ((p_station_id is null and n.kind not like 'staff\_%') or (n.station_id = p_station_id and n.kind like 'staff\_%'))
  order by n.created_at desc, n.id desc limit 1;
 return private.envelope(jsonb_build_object('notifications', notes, 'messages', msgs, 'orders', open_orders, 'latest', latest));
end $$;
