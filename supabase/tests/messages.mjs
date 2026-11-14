// Phase 7 behaviour: inbox, messages, read cursors, rate limit, and reservations that become scheduled orders.
import {world} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;
const S = ids.station;

await denied(ids.rider, 'start_conversation', S, t.key());
await denied(ids.superadmin, 'start_conversation', S, t.key());
const conv = (await rpc(ids.customer, 'start_conversation', S, t.key())).data.conversation_id;
eq((await rpc(ids.customer, 'start_conversation', S, t.key())).data.conversation_id, conv, 'One conversation per station and customer');

const k1 = t.key();
const m1 = await rpc(ids.customer, 'send_message', conv, 'Hello, is the station open?', 'general', null, k1);
eq(m1.ok, true, 'Customer sends a message');
eq((await rpc(ids.customer, 'send_message', conv, 'Hello, is the station open?', 'general', null, k1)).data.message_id, m1.data.message_id, 'Retry with the same key sends once');
for (const [body, label] of [['   ', 'Blank message rejected'], ['x'.repeat(2001), 'Over 2,000 characters rejected']]) eq((await rpc(ids.customer, 'send_message', conv, body, 'general', null, t.key())).error_code, 'validation', label);
eq((await rpc(ids.customer, 'send_message', conv, 'Hi', 'gossip', null, t.key())).error_code, 'validation', 'Unknown topic rejected');
await denied(ids.admin2, 'send_message', conv, 'Not my station', 'general', null, t.key());
await denied(ids.rider, 'send_message', conv, 'Rider cannot chat', 'general', null, t.key());
await denied(ids.superadmin, 'get_messages', conv, {});
await denied(ids.customer2, 'get_messages', conv, {});
const order = await rpc(ids.customer, 'submit_order', S, t.order({round: 1}, {fulfillment: 'pickup', address_id: undefined}), t.key());
const other = await rpc(ids.customer2, 'submit_order', S, {...t.order({round: 1}), address_id: t.home2}, t.key());
eq((await rpc(ids.admin, 'send_message', conv, 'About this order', 'order', other.data.order_id, t.key())).error_code, 'validation', "Another customer's order cannot be linked");
const reply = await rpc(ids.admin, 'send_message', conv, 'Yes, open until 6 PM. Your order is next.', 'order', order.data.order_id, t.key());
eq(reply.ok, true, 'Station replies with an order link');

let inbox = await rpc(ids.customer, 'list_inbox', null, {}, {});
eq([inbox.data.conversations.length, inbox.data.conversations[0].unread, inbox.data.conversations[0].name], [1, 1, 'Live Station One'], 'Customer inbox shows the unread reply');
let staffInbox = await rpc(ids.admin, 'list_inbox', S, {}, {});
eq([staffInbox.data.conversations[0].name, staffInbox.data.conversations[0].unread], ['Maria Live', 0], 'Station inbox names the customer; own reply is read');
eq((await rpc(ids.admin, 'list_inbox', S, {topic: 'reservation'}, {})).data.conversations.length, 0, 'Topic filter');
eq((await rpc(ids.admin, 'list_inbox', S, {search: 'open until'}, {})).data.conversations.length, 1, 'Search finds message text');
eq((await rpc(ids.admin2, 'list_inbox', ids.station2, {}, {})).data.conversations.length, 0, 'Another station sees no conversations');
await denied(ids.admin2, 'list_inbox', S, {}, {});
eq((await rpc(ids.customer, 'get_navigation_counts', null)).data.messages, 1, 'Navigation badge counts the unread message');
let thread = await rpc(ids.customer, 'get_messages', conv, {});
eq([thread.data.messages.length, thread.data.messages[1].order_no, thread.data.messages[1].side, thread.data.messages[0].mine], [2, 'HMT-0001', 'station', true], 'Thread with order link and sides');
eq((await rpc(ids.customer, 'get_messages', conv, {created_at: thread.data.messages[0].created_at, id: thread.data.messages[0].id})).data.messages.map(m => m.id), [reply.data.message_id], 'Cursor returns only newer messages');
eq((await rpc(ids.customer, 'mark_conversation_read', conv, other.data.order_id, t.key())).error_code, 'validation', 'Read cursor must be a message in this conversation');
await rpc(ids.customer, 'mark_conversation_read', conv, reply.data.message_id, t.key());
eq((await rpc(ids.customer, 'list_inbox', null, {}, {})).data.conversations[0].unread, 0, 'Marked read up to the seen message');
eq((await rpc(ids.customer, 'list_inbox', null, {unread: true}, {})).data.conversations.length, 0, 'Unread filter');

// Rate limit: 20 messages per minute per sender.
const conv2 = (await rpc(ids.customer2, 'start_conversation', S, t.key())).data.conversation_id;
for (let i = 0; i < 20; i++) await rpc(ids.customer2, 'send_message', conv2, 'Message ' + i, 'general', null, t.key());
eq((await rpc(ids.customer2, 'send_message', conv2, 'One too many', 'general', null, t.key())).error_code, 'rate_limited', '21st message in a minute is refused');

// No direct writes any more.
await t.as(ids.customer);
for (const sql of [`insert into public.messages(conversation_id, body) values ('${conv}', 'Bypass')`, `update public.read_receipts set read_at = now()`, `select * from public.reservations`]) {
    await t.db.exec(sql).then(() => { throw new Error('Allowed: ' + sql); }, e => { if (e.code !== '42501') throw e; });
}
await t.owner();

// Reservations.
const items = [{product_id: t.products.round, quantity: 3}];
eq((await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(-1, '10:00'), fulfillment: 'pickup', items}, t.key())).error_code, 'validation', 'Past reservation rejected');
eq((await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(2, '10:00'), fulfillment: 'pickup', items: [{product_id: t.products2.round, quantity: 1}]}, t.key())).error_code, 'product_unavailable', 'Other station product rejected');
eq((await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(2, '10:00'), fulfillment: 'delivery', address_id: t.far, items}, t.key())).error_code, 'out_of_range', 'Delivery reservation still needs a reachable address');
await denied(ids.rider, 'request_reservation', S, {requested_at: t.manila(2, '10:00'), fulfillment: 'pickup', items}, t.key());
const res = await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(2, '10:00'), fulfillment: 'delivery', address_id: t.home, items, note: 'For a birthday'}, t.key());
eq(res.ok, true, 'Reservation requested');
thread = await rpc(ids.admin, 'get_messages', conv, {});
const card = thread.data.messages.at(-1).reservation;
eq([card.state, card.items[0].quantity, thread.data.messages.at(-1).kind], ['requested', 3, 'reservation'], 'Reservation card in the conversation');
await denied(ids.admin2, 'respond_reservation', res.data.reservation_id, 'accept', '', card.version, t.key());
eq((await rpc(ids.admin, 'respond_reservation', res.data.reservation_id, 'accept', '', card.version + 1, t.key())).error_code, 'stale_version', 'Stale reservation version');
const acc = await rpc(ids.admin, 'respond_reservation', res.data.reservation_id, 'accept', 'See you then', card.version, t.key());
eq(acc.data.state, 'accepted', 'Owner accepts');
const created = (await t.sql('select status, total_centavos::int tot, quoted_fee_centavos fee, customer_id from public.orders where id = $1', [acc.data.order_id]))[0];
eq(created, {status: 'scheduled', tot: 7000, fee: 1000, customer_id: ids.customer}, 'Accepting creates one scheduled order priced by the database');
eq((await rpc(ids.admin, 'respond_reservation', res.data.reservation_id, 'accept', '', card.version + 1, t.key())).error_code, 'stale_version', 'Cannot accept twice');
eq((await rpc(ids.customer, 'cancel_reservation', res.data.reservation_id, card.version + 1, t.key())).error_code, 'stale_version', 'Accepted reservation cannot be cancelled here');
ok((await rpc(ids.customer, 'list_notifications', null, {})).data.notifications.some(n => n.kind === 'reservation_accepted'), 'Customer notified of the acceptance');

const later = await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(20, '10:00'), fulfillment: 'pickup', items: [{product_id: t.products.round, quantity: 30}]}, t.key());
eq(later.ok, true, 'Enquiry for a later date and larger quantity can be requested');
eq((await rpc(ids.admin, 'respond_reservation', later.data.reservation_id, 'accept', '', 1, t.key())).error_code, 'over_limit', 'Acceptance still applies the order limits (D6)');
eq((await rpc(ids.admin, 'respond_reservation', later.data.reservation_id, 'decline', '', 1, t.key())).error_code, 'validation', 'Declining needs a reason');
eq((await rpc(ids.admin, 'respond_reservation', later.data.reservation_id, 'decline', 'We cannot carry 30 containers in one trip', 1, t.key())).data.state, 'declined', 'Owner declines with a reason');
const third = await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(3, '09:00'), fulfillment: 'pickup', items}, t.key());
await denied(ids.customer2, 'cancel_reservation', third.data.reservation_id, 1, t.key());
eq((await rpc(ids.customer, 'cancel_reservation', third.data.reservation_id, 1, t.key())).data.state, 'cancelled', 'Customer cancels a requested reservation');
eq((await t.sql("select count(*)::int n from public.orders where customer_id = $1 and status = 'scheduled'", [ids.customer]))[0].n, 1, 'Only the accepted reservation created an order');
console.log(t.count() + ' messaging checks passed.');
await t.db.close();
