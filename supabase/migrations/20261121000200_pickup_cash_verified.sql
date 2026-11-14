-- Pickup cash is verified when it is received.
-- A pickup is paid to the owner at the station counter. There is no rider handover to check, so
-- record_pickup_collection now stores the payment as verified (verified_by = the owner who took the cash)
-- and writes the same audit event as verify_payment. Delivery cash collected by riders still needs verification.
create or replace function public.record_pickup_collection(p_order_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare o public.orders; r jsonb; payload jsonb := jsonb_build_object('order_id', p_order_id, 'payload', p_payload, 'expected_version', p_expected_version);
 tendered bigint; empties int; v int; pid uuid;
begin
 select * into o from public.orders where id = p_order_id;
 if not found or not private.station_admin(o.station_id) then raise exception 'Order access denied.' using errcode = '42501'; end if;
 r := private.replay(o.station_id::text, 'record_pickup_collection', p_request_key, payload);
 if r is not null then return r; end if;
 perform private.owner_guard(o.station_id);
 select * into o from public.orders where id = p_order_id for update;
 if o.version is distinct from p_expected_version then return private.envelope(jsonb_build_object('version', o.version), 'stale_version'); end if;
 if o.fulfillment <> 'pickup' or o.status <> 'ready' then return private.envelope('{}', 'invalid_transition', jsonb_build_object('order', 'Only a ready pickup order can be collected.')); end if;
 if coalesce(p_payload->>'tendered_centavos', '') !~ '^[0-9]{1,9}$' or coalesce(p_payload->>'empties_returned', '') !~ '^[0-9]{1,4}$' then
  return private.envelope('{}', 'validation', jsonb_build_object('tendered_centavos', 'Enter the cash received and the empty containers returned.'));
 end if;
 tendered := (p_payload->>'tendered_centavos')::bigint; empties := (p_payload->>'empties_returned')::int;
 if tendered < o.total_centavos then return private.envelope('{}', 'validation', jsonb_build_object('tendered_centavos', 'The customer must pay the full amount of ' || to_char(o.total_centavos / 100.0, 'FM999,990.00') || ' pesos.')); end if;
 -- The owner takes the cash at the counter, so there is no rider handover: the payment is verified on the spot.
 insert into public.payments(station_id, order_id, applied_centavos, tendered_centavos, change_centavos, expected_empties, empties_returned, collected_by, request_key,
  state, verified_by, verified_at, verify_note)
 values (o.station_id, o.id, o.total_centavos, tendered, tendered - o.total_centavos, coalesce(o.expected_empties, 0), empties, auth.uid(), p_request_key,
  'verified', auth.uid(), now(), 'Received at the station counter') returning id into pid;
 insert into public.station_audit_events(station_id, actor_id, action, entity_id, request_key, after_values)
 values (o.station_id, auth.uid(), 'verify_payment', pid, p_request_key, jsonb_build_object('applied_centavos', o.total_centavos, 'empties_returned', empties, 'source', 'pickup_counter'));
 v := private.transition(o.id, 'collected', null, p_request_key);
 if empties <> coalesce(o.expected_empties, 0) then
  insert into public.order_reviews(station_id, order_id, kind, detail) values (o.station_id, o.id, 'empties_mismatch', format('Expected %s empty containers, received %s.', coalesce(o.expected_empties, 0), empties)) on conflict do nothing;
 end if;
 return private.remember(o.station_id::text, 'record_pickup_collection', p_request_key, payload, private.envelope(jsonb_build_object('order_id', o.id, 'payment_id', pid, 'status', 'collected', 'version', v, 'change_centavos', tendered - o.total_centavos, 'payment_state', 'verified')));
end $$;

-- Earlier pickup payments that are still waiting: the person who took the cash at the counter verified it then.
update public.payments p set state = 'verified', verified_by = p.collected_by, verified_at = p.collected_at,
 verify_note = 'Received at the station counter', version = p.version + 1
from public.orders o
where o.id = p.order_id and o.fulfillment = 'pickup' and p.attempt_id is null and p.state = 'unverified';
