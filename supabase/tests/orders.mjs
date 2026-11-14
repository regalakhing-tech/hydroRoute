// Phase 4 behaviour: pricing in the database, idempotent submission, duplicate holds, schedules, cancellation,
// tracking reads and notifications, with cross-customer, cross-station and Super Admin denial.
import {world} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;

// Fee bands use the unrounded distance (D1).
for (const [km, fee] of [[0.99, 500], [1.0, 1000], [3.0, 1000], [3.01, 1500]]) {
    eq((await t.sql('select private.fee_for($1, 1, $2) f', [ids.station, km]))[0].f, fee, `fee at ${km} km`);
}
eq(Number((await t.sql('select round(private.haversine_km(10.6,122,(10.6+1.5/(6371*pi()/180))::numeric,122),6) d'))[0].d), 1.5, 'Haversine along a meridian');

// Quotes: price, distance and fee come from the database.
let q = await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 2, faucet: 1}));
eq(q.ok, true, 'Delivery quote succeeds');
eq([q.data.subtotal_centavos, q.data.fee_centavos, q.data.total_centavos, q.data.total_units, q.data.expected_empties], [6000, 1000, 7000, 3, 3], 'Quote totals');
eq(Number(q.data.delivery.distance_display), 1.5, 'Displayed distance rounded to 0.1 km');
q = await rpc(ids.customer, 'quote_order', ids.station, {...t.order({round: 1}), unit_price_centavos: 1});
eq(q.error_code, 'validation', 'Supplied price key rejected');
q = await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {fulfillment: 'pickup', address_id: undefined}));
eq([q.ok, q.data.fee_centavos, q.data.delivery], [true, 0, null], 'Pickup has no fee or address');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {address_id: t.far}))).error_code, 'out_of_range', 'Beyond 10 km rejected');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {address_id: t.nopin}))).error_code, 'address_no_pin', 'Address without pin is pickup-only');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {address_id: t.home2}))).ok, false, "Another customer's address rejected");
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 21}))).error_code, 'over_limit', 'Whole-order limit of 20');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({bottle500: 1}))).error_code, 'load_units_missing', 'Boxes without load units are pickup-only (D7)');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({bottle500: 1}, {fulfillment: 'pickup', address_id: undefined}))).ok, true, 'Boxes allowed for pickup');
eq((await rpc(ids.customer, 'quote_order', ids.station, {...t.order({}), items: [{product_id: t.products2.round, quantity: 1}]})).error_code, 'product_unavailable', 'Other station product rejected');
let dq = await rpc(ids.customer, 'quote_delivery', ids.station, t.home);
eq([dq.data.eligible, dq.data.fee_centavos], [true, 1000], 'quote_delivery for the default address');
await t.owner();
await t.sql(`insert into public.station_service_areas(station_id, barangay_id) select $1, id from public.barangays where name = 'Apdo'`, [ids.station]);
eq((await rpc(ids.customer, 'quote_delivery', ids.station, t.home)).data.code, 'out_of_area', 'Unserved barangay rejected');
await t.sql('delete from public.station_service_areas where station_id = $1', [ids.station]);
await denied(ids.superadmin, 'quote_order', ids.station, t.order({round: 1}));

// Submission is idempotent and creates complete snapshots.
const key = t.key();
const s1 = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 2, faucet: 1}), key);
eq([s1.ok, s1.data.status, s1.data.order_no], [true, 'confirmed', 'HMT-0001'], 'ASAP order confirmed');
const s1again = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 2, faucet: 1}), key);
eq(s1again.data.order_id, s1.data.order_id, 'Same request key returns the same order');
eq((await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 3}), key)).error_code, 'idempotency_conflict', 'Same key, different payload conflicts');
eq(Number((await t.sql('select count(*) n from public.orders where customer_id = $1', [ids.customer]))[0].n), 1, 'Exactly one order after retries');
const row = (await t.sql('select * from public.orders where id = $1', [s1.data.order_id]))[0];
eq([Number(row.subtotal_centavos), row.quoted_fee_centavos, Number(row.total_centavos), row.fee_version, row.contact_phone, row.address_label, row.contract_version],
    [6000, 1000, 7000, 1, '+639171111111', 'Home', 2], 'Order snapshots saved');
eq((await t.sql('select count(*)::int n from public.order_status_logs where order_id = $1', [s1.data.order_id]))[0].n, 1, 'Submission logged');
await t.sql('update public.products set price_centavos = 9900 where id = $1', [t.products.round]);
eq(Number((await t.sql('select sum(line_total_centavos) s from public.order_items where order_id = $1', [s1.data.order_id]))[0].s), 6000, 'Later price changes leave the order alone');
await t.sql('update public.products set price_centavos = 2000 where id = $1', [t.products.round]);

// Duplicate within 30 minutes is held; only the customer confirms it (D5).
const dup = await rpc(ids.customer, 'submit_order', ids.station, t.order({faucet: 1, round: 2}), t.key());
eq([dup.data.status, dup.data.duplicate_order_no], ['on_hold', 'HMT-0001'], 'Possible duplicate held');
await denied(ids.admin, 'confirm_duplicate', dup.data.order_id, dup.data.version, t.key());
eq((await rpc(ids.customer, 'confirm_duplicate', dup.data.order_id, dup.data.version + 5, t.key())).error_code, 'stale_version', 'Stale version rejected');
const conf = await rpc(ids.customer, 'confirm_duplicate', dup.data.order_id, dup.data.version, t.key());
eq(conf.data.status, 'confirmed', 'Customer confirmed repeat');

// Scheduling.
const tomorrow = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 1}, {fulfillment: 'pickup', address_id: undefined, schedule: 'scheduled', requested_at: t.manila(1, '10:00')}), t.key());
eq(tomorrow.data.status, 'scheduled', 'Future-day order is scheduled (D4)');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {schedule: 'scheduled', requested_at: t.manila(9, '10:00')}))).error_code, 'outside_window', 'More than 7 days ahead rejected');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {schedule: 'scheduled', requested_at: t.manila(-1, '10:00')}))).error_code, 'validation', 'Past time rejected');
await t.sql(`update public.station_opening_hours set opens_at = '08:00', closes_at = '17:00' where station_id = $1`, [ids.station]);
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}, {schedule: 'scheduled', requested_at: t.manila(2, '19:00')}))).error_code, 'outside_hours', 'Outside hours rejected');
await t.sql(`update public.station_opening_hours set opens_at = '00:00', closes_at = '23:59:59' where station_id = $1`, [ids.station]);
const resch = await rpc(ids.customer, 'reschedule_order', tomorrow.data.order_id, new Date(Date.now() + 2 * 86400e3).toISOString(), tomorrow.data.version, t.key());
eq([resch.ok, resch.data.status], [true, 'scheduled'], 'Customer reschedules a scheduled order');

// Closed station: ASAP refused, scheduling still possible.
await t.sql(`update public.station_availability set manual_mode = 'closed', begins_at = now() - interval '1 minute', expires_at = now() + interval '1 hour' where station_id = $1`, [ids.station]);
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 1}))).error_code, 'station_closed', 'ASAP refused while closed');
await t.sql(`update public.station_availability set manual_mode = null, begins_at = null, expires_at = null where station_id = $1`, [ids.station]);

// Reads are private.
eq((await rpc(ids.customer, 'get_customer_order', s1.data.order_id)).data.items.length, 2, 'Customer reads own order items');
eq((await rpc(ids.customer2, 'get_customer_order', s1.data.order_id)).error_code, 'not_found', 'Other customer cannot read it');
await denied(ids.superadmin, 'get_customer_order', s1.data.order_id);
await denied(ids.superadmin, 'list_customer_orders', {}, {});
eq((await rpc(ids.customer, 'list_customer_orders', {group: 'active'}, {})).data.orders.length, 3, 'Active order list');
eq((await rpc(ids.customer2, 'list_customer_orders', {}, {})).data.orders.length, 0, 'Second customer sees none');
await t.as(ids.admin2);
eq((await t.sql('select count(*)::int n from public.orders')).length && (await t.sql('select count(*)::int n from public.orders'))[0].n, 0, 'Other station owner reads no orders');
await t.as(ids.superadmin);
eq((await t.sql('select count(*)::int n from public.orders'))[0].n, 0, 'Super Admin reads no orders');
await t.owner();

// Cancellation rules.
await denied(ids.customer2, 'cancel_order', s1.data.order_id, 1, 'Not mine', t.key());
await denied(ids.admin2, 'cancel_order', s1.data.order_id, 1, 'Other station', t.key());
eq((await rpc(ids.customer, 'cancel_order', s1.data.order_id, 1, '  ', t.key())).error_code, 'validation', 'Reason required');
await t.sql(`update public.orders set status = 'preparing', version = version + 1 where id = $1`, [s1.data.order_id]);
eq((await rpc(ids.customer, 'cancel_order', s1.data.order_id, 2, 'Changed my mind', t.key())).error_code, 'invalid_transition', 'Customer cannot cancel once preparing');
const ownerCancel = await rpc(ids.admin, 'cancel_order', s1.data.order_id, 2, 'Out of containers', t.key());
eq(ownerCancel.data.status, 'cancelled', 'Owner cancels a preparing order with a reason');
const logs = await t.sql('select from_status, to_status, actor_id, reason from public.order_status_logs where order_id = $1 order by order_version', [s1.data.order_id]);
eq(logs.at(-1), {from_status: 'preparing', to_status: 'cancelled', actor_id: ids.admin, reason: 'Out of containers'}, 'Cancellation logged with actor and reason');

// Notifications and badges.
const notes = await rpc(ids.customer, 'list_notifications', null, {});
ok(notes.data.notifications.some(n => n.kind === 'order_cancelled'), 'Customer notified of the owner cancellation');
const staff = await rpc(ids.admin, 'list_notifications', ids.station, {});
ok(staff.data.notifications.some(n => n.kind === 'staff_order_new') && staff.data.notifications.every(n => n.kind.startsWith('staff_')), 'Owner sees staff notices only');
await denied(ids.admin2, 'list_notifications', ids.station, {});
await denied(ids.superadmin, 'get_navigation_counts', null);
const before = (await rpc(ids.customer, 'get_navigation_counts', null)).data.notifications;
ok(before > 0, 'Unread badge counts customer notices');
await rpc(ids.customer, 'mark_notification_read', notes.data.notifications[0].id, t.key());
eq((await rpc(ids.customer, 'get_navigation_counts', null)).data.notifications, before - 1, 'Mark one read');
await rpc(ids.customer2, 'mark_all_notifications_read', null, t.key());
eq((await rpc(ids.customer, 'get_navigation_counts', null)).data.notifications, before - 1, "Another customer cannot mark mine");
await rpc(ids.customer, 'mark_all_notifications_read', null, t.key());
eq((await rpc(ids.customer, 'get_navigation_counts', null)).data.notifications, 0, 'Mark all read');

// No direct writes.
await t.as(ids.customer);
for (const sql of [`update public.orders set status = 'completed'`, `insert into public.order_status_logs(station_id, order_id, to_status, order_version, actor_id) values ('${ids.station}', '${s1.data.order_id}', 'completed', 99, '${ids.customer}')`, `select * from public.order_status_logs`, `update public.notifications set read_at = now()`]) {
    await t.db.exec(sql).then(() => { throw new Error('Allowed: ' + sql); }, e => { if (e.code !== '42501') throw e; });
}
await t.owner();
console.log(t.count() + ' order checks passed.');
await t.db.close();
