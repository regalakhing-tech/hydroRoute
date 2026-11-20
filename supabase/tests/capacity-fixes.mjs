// Review fixes: prepared-then-cancelled refills keep consuming stock; held repeats start their cancel window on
// confirmation and are rechecked (station, date, slot, refill limit) with envelopes instead of database errors.
import {world} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc} = t;
const today = "(now() at time zone 'Asia/Manila')::date";
const booked = async () => (await t.sql(`select private.daily_refills_booked($1, ${today}, null) n`, [ids.station]))[0].n;
const view = async id => (await rpc(ids.customer, 'get_customer_order', id)).data;

// 1. Preparation consumes stock for good.
const a = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 3}), t.key());
let o = (await view(a.data.order_id)).order;
let s = await rpc(ids.admin, 'start_preparation', a.data.order_id, o.version, t.key());
s = await rpc(ids.admin, 'mark_order_ready', a.data.order_id, s.data.version, t.key());
const before = await booked();
eq((await rpc(ids.admin, 'cancel_order', a.data.order_id, s.data.version, 'Customer not home', t.key())).ok, true, 'Owner cancels a ready order');
eq(await booked(), before, 'A prepared order keeps using the day\'s refills after cancellation');
const b = await rpc(ids.customer, 'submit_order', ids.station, t.order({faucet: 2}), t.key());
o = (await view(b.data.order_id)).order;
eq((await rpc(ids.customer, 'cancel_order', b.data.order_id, o.version, 'Changed mind', t.key())).ok, true, 'Customer cancels before preparation');
eq(await booked(), before, 'An unprepared cancellation releases its refills');

// 2. A held repeat has no cancel deadline until it is confirmed; confirming starts a full window.
const first = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 1, faucet: 1}), t.key());
const hold = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 1, faucet: 1}), t.key());
eq(hold.data.status, 'on_hold', 'Repeat is held');
eq((await t.sql('select customer_cancel_until from public.orders where id = $1', [hold.data.order_id]))[0].customer_cancel_until, null, 'Held repeat has no deadline yet');
await t.sql("update public.orders set created_at = now() - interval '40 minutes' where id = $1", [hold.data.order_id]);
let conf = await rpc(ids.customer, 'confirm_duplicate', hold.data.order_id, hold.data.version, t.key());
eq(conf.ok, true, 'Customer confirms after 40 minutes');
let v = await view(hold.data.order_id);
ok(v.can_cancel && (new Date(v.cancel_until) - Date.now()) / 60000 > 14, 'Confirmed repeat gets its full cancel window');

// 3. Overnight hold: confirmation moves it to today and checks today's refill limit with a clear message.
const hold2Base = await rpc(ids.customer, 'submit_order', ids.station, t.order({faucet: 3}), t.key());
const hold2 = await rpc(ids.customer, 'submit_order', ids.station, t.order({faucet: 3}), t.key());
eq(hold2.data.status, 'on_hold', 'Second repeat held');
await t.sql(`update public.orders set requested_at = now() - interval '1 day', service_date = ${today} - 1 where id = $1`, [hold2.data.order_id]);
await t.sql('update public.station_settings set daily_gallon_limit = $2 where station_id = $1', [ids.station, await booked()]);
const full = await rpc(ids.customer, 'confirm_duplicate', hold2.data.order_id, hold2.data.version, t.key());
eq([full.ok, full.error_code], [false, 'daily_limit'], 'Full day refused with an envelope, not a database error');
await t.sql('update public.station_settings set daily_gallon_limit = null where station_id = $1', [ids.station]);
conf = await rpc(ids.customer, 'confirm_duplicate', hold2.data.order_id, hold2.data.version, t.key());
eq(conf.ok, true, 'Confirms once there is room');
const moved = (await t.sql(`select service_date = ${today} d from public.orders where id = $1`, [hold2.data.order_id]))[0].d;
eq(moved, true, 'Confirmed overnight repeat belongs to today');

// 4. A taken slot and a suspended station are explained.
const slotAt = t.manila(1, '13:00');
const pick = {fulfillment: 'pickup', items: [{product_id: t.products.round, quantity: 1}], schedule: 'scheduled', requested_at: slotAt, note: ''};
const orig = await rpc(ids.customer, 'submit_order', ids.station, pick, t.key());
const held = await rpc(ids.customer, 'submit_order', ids.station, pick, t.key());
eq(held.data.status, 'on_hold', 'Scheduled repeat held');
o = (await view(orig.data.order_id)).order;
await rpc(ids.customer, 'cancel_order', orig.data.order_id, o.version, 'Wrong time', t.key());
eq((await rpc(ids.customer2, 'submit_order', ids.station, pick, t.key())).ok, true, 'Another customer takes the freed slot');
eq((await rpc(ids.customer, 'confirm_duplicate', held.data.order_id, held.data.version, t.key())).error_code, 'slot_taken', 'Held repeat told its slot is gone');
const hold3a = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 2, faucet: 2}), t.key());
const hold3 = await rpc(ids.customer, 'submit_order', ids.station, t.order({round: 2, faucet: 2}), t.key());
eq(hold3.data.status, 'on_hold', 'Third repeat held');
eq((await rpc(ids.superadmin, 'set_station_status', ids.station, 'suspended', 'approved', 'Inspection', t.key())).ok, true, 'Station suspended');
eq((await rpc(ids.customer, 'confirm_duplicate', hold3.data.order_id, hold3.data.version, t.key())).error_code, 'station_unavailable', 'Repeat cannot be confirmed at a suspended station');

// 5. Order queue "Cancelled" filter data: own station only, newest first, with the reason.
const cancelled = await rpc(ids.admin, 'get_cancelled_orders', ids.station);
ok(cancelled.ok && cancelled.data.orders.length >= 3 && cancelled.data.orders.every(o => o.status === 'cancelled'), 'Owner lists recent cancelled orders');
ok(cancelled.data.orders.some(o => o.cancel_reason === 'Customer not home'), 'Cancel reason included');
const times = cancelled.data.orders.map(o => o.cancelled_at);
eq(times, [...times].sort().reverse(), 'Newest cancellation first');
await t.denied(ids.admin2, 'get_cancelled_orders', ids.station);
await t.denied(ids.customer, 'get_cancelled_orders', ids.station);

console.log(`${t.count()} capacity review-fix checks passed.`);
