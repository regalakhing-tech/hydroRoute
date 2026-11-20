// M14-M16: 15-container trips, 5-minute single-customer slots, daily refill limits and the cancellation window.
import {world} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;
const pickup = {fulfillment: 'pickup', address_id: undefined};
const slot = (hhmm, extra = {}) => t.order({round: 1}, {...pickup, schedule: 'scheduled', requested_at: t.manila(1, hhmm), ...extra});

// One trip carries at most 15 containers; pickup is not a trip.
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 16}))).error_code, 'over_batch', 'Delivery over 15 containers rejected');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 15}))).ok, true, 'Exactly 15 containers fit one trip');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 16}, pickup))).ok, true, 'Pickup is not limited by the batch');
eq((await t.sql('select capacity from private.free_riders($1) order by capacity desc limit 1', [ids.station]))[0].capacity, 15, 'Rider capacity capped at 15');
await t.sql('update public.station_settings set batch_capacity_load_units = 10 where station_id = $1', [ids.station]);
eq((await t.sql('select max(capacity) c from private.free_riders($1)', [ids.station]))[0].c, 10, 'Station batch lowers every rider');
eq((await t.sql('select min(capacity) c from private.free_riders($1)', [ids.station]))[0].c, 5, 'A smaller vehicle keeps its own limit');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 11}))).error_code, 'over_batch', 'Booking follows the station batch');
await t.sql('update public.station_settings set batch_capacity_load_units = 15 where station_id = $1', [ids.station]);

// 5-minute slots: one customer each; the same customer may reuse their own slot.
const first = await rpc(ids.customer, 'submit_order', ids.station, slot('09:05'), t.key());
eq([first.ok, first.data.status], [true, 'scheduled'], 'Customer books 9:05 tomorrow');
eq((await rpc(ids.customer2, 'quote_order', ids.station, slot('09:05'))).error_code, 'slot_taken', 'Another customer cannot book a taken slot');
eq((await rpc(ids.customer2, 'quote_order', ids.station, slot('09:07'))).error_code, 'slot_taken', 'A time inside the same 5 minutes is taken too');
eq((await rpc(ids.customer2, 'quote_order', ids.station, slot('09:10'))).ok, true, 'The next slot is free');
eq((await rpc(ids.customer, 'quote_order', ids.station, slot('09:05'))).ok, true, 'The same customer may add an order to their own slot');
eq((await rpc(ids.customer2, 'quote_order', ids.station2, t.order({round: 1}, {...pickup, schedule: 'scheduled', requested_at: t.manila(1, '09:05'), items: [{product_id: t.products2.round, quantity: 1}]}))).ok, true,
    'Slots are per station');
const seen = await rpc(ids.customer2, 'get_booked_slots', ids.station);
ok(seen.ok && seen.data.slots.includes(t.manila(1, '09:05')), 'Booked slot listed for other customers');
ok(!(await rpc(ids.customer, 'get_booked_slots', ids.station)).data.slots.includes(t.manila(1, '09:05')), 'A customer never sees their own slot as taken');
eq(Object.keys(seen.data).sort(), ['refills_left', 'rules', 'slots'], 'Slot list carries no customer details');
eq([seen.data.rules.cancel_minutes, seen.data.rules.trip_containers, seen.data.rules.booking_days, seen.data.rules.max_items], [15, 15, 7, 20], 'Customer-facing rules for the checkout box');
await denied(ids.superadmin, 'get_booked_slots', ids.station);

// Race backstop: an insert that slipped past the quote is refused by the trigger.
const q2 = await rpc(ids.customer2, 'quote_order', ids.station, slot('09:15'));
await t.owner();
await t.sql("select set_config('request.jwt.claim.sub', $1, false)", [ids.customer2]);
let raced = '';
try { await t.sql("select private.insert_order($1, $2, $3::jsonb, 'scheduled', $4, null, null, null)", [ids.station, ids.customer2, JSON.stringify({...q2.data, requested_at: first.data && (await t.sql('select requested_at from public.orders where id = $1', [first.data.order_id]))[0].requested_at}), t.key()]); }
catch (e) { raced = e.message; }
await t.owner();
ok(raced.includes('already booked'), 'Trigger refuses a second customer in a slot');

// Rescheduling into another customer's slot is refused; into a free slot it works.
const other = await rpc(ids.customer2, 'submit_order', ids.station, slot('10:00'), t.key());
eq(other.ok, true, 'Second customer books 10:00');
const toTaken = await rpc(ids.customer2, 'reschedule_order', other.data.order_id, new Date((await t.sql('select requested_at from public.orders where id = $1', [first.data.order_id]))[0].requested_at).toISOString(), other.data.version, t.key());
eq(toTaken.error_code, 'slot_taken', 'Reschedule into a taken slot refused');
const cancelFirst = await rpc(ids.customer, 'cancel_order', first.data.order_id, first.data.version, 'Changed plans', t.key());
eq(cancelFirst.ok, true, 'Customer cancels within the window');
eq((await rpc(ids.customer2, 'quote_order', ids.station, slot('09:05'))).ok, true, 'A cancelled order frees its slot');
ok(!(await rpc(ids.customer2, 'get_booked_slots', ids.station)).data.slots.includes(t.manila(1, '09:05')), 'After a cancel the time is no longer shown as taken');
eq((await rpc(ids.customer2, 'submit_order', ids.station, slot('09:05'), t.key())).ok, true, 'Another customer can book the freed time');
ok((await rpc(ids.customer, 'get_booked_slots', ids.station)).data.slots.includes(t.manila(1, '09:05')), 'And it shows as taken again for everyone else');

// Cancellation window: snapshot per order; afterwards only the station can cancel.
const asap = await rpc(ids.customer, 'submit_order', ids.station, t.order({faucet: 1}), t.key());
let view = await rpc(ids.customer, 'get_customer_order', asap.data.order_id);
ok(view.data.can_cancel && view.data.cancel_until, 'New order shows a cancel deadline');
const minutes = (new Date(view.data.cancel_until) - new Date(view.data.order.created_at)) / 60000;
ok(minutes > 14.9 && minutes < 15.1, 'Default window is 15 minutes');
await t.sql("update public.orders set customer_cancel_until = now() - interval '1 minute' where id = $1", [asap.data.order_id]);
view = await rpc(ids.customer, 'get_customer_order', asap.data.order_id);
eq(view.data.can_cancel, false, 'Expired window hides cancel');
eq((await rpc(ids.customer, 'cancel_order', asap.data.order_id, view.data.order.version, 'Too late', t.key())).error_code, 'cancel_window_closed', 'Customer cancel after the window refused');
eq((await rpc(ids.admin, 'cancel_order', asap.data.order_id, view.data.order.version, 'Customer called', t.key())).ok, true, 'Station can still cancel');
await t.sql('update public.station_settings set customer_cancel_minutes = 30 where station_id = $1', [ids.station]);
const later = await rpc(ids.customer, 'submit_order', ids.station, t.order({faucet: 2}), t.key());
const lv = await rpc(ids.customer, 'get_customer_order', later.data.order_id);
ok((new Date(lv.data.cancel_until) - new Date(lv.data.order.created_at)) / 60000 > 29.9, 'Window follows the station setting at order time');
await t.sql('update public.station_settings set customer_cancel_minutes = 15 where station_id = $1', [ids.station]);

// Daily refill limit with per-date overrides; on_hold and cancelled orders do not count.
const booked = (await t.sql("select private.daily_refills_booked($1, (now() at time zone 'Asia/Manila')::date, null) n", [ids.station]))[0].n;
await t.sql('update public.station_settings set daily_gallon_limit = $2 where station_id = $1', [ids.station, booked + 2]);
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 3}))).error_code, 'daily_limit', 'Order beyond the day limit refused');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({round: 2}))).ok, true, 'Order that fits the remaining refills accepted');
eq((await rpc(ids.customer, 'quote_order', ids.station, t.order({bottle500: 5}, pickup))).ok, true, 'Non-refill products do not use refill stock');
const left = (await rpc(ids.customer, 'get_booked_slots', ids.station)).data.refills_left;
eq(Object.values(left)[0], 2, 'Customers see refills left for today');
await denied(ids.customer, 'save_capacity_day', ids.station, t.manila(1, '00:00').slice(0, 10), 0, t.key());
const zero = await rpc(ids.admin, 'save_capacity_day', ids.station, t.manila(1, '00:00').slice(0, 10), 0, t.key());
eq(zero.ok, true, 'Owner sets a zero limit for tomorrow');
const full = await rpc(ids.customer2, 'quote_order', ids.station, slot('11:00'));
eq([full.error_code, /fully booked/.test(full.field_errors.requested_at)], ['daily_limit', true], 'Fully booked date refused');
const days = await rpc(ids.admin, 'get_capacity_days', ids.station);
eq([days.data.days[1].limit, days.data.days[1].override, days.data.days.length], [0, true, 14], 'Owner sees the override');
eq((await rpc(ids.admin, 'save_capacity_day', ids.station, t.manila(1, '00:00').slice(0, 10), null, t.key())).ok, true, 'Override cleared');
eq((await rpc(ids.customer2, 'quote_order', ids.station, slot('11:00'))).ok, true, 'Default limit applies again');
eq((await rpc(ids.admin, 'save_capacity_day', ids.station, t.manila(-1, '00:00').slice(0, 10), 5, t.key())).error_code, 'validation', 'Past date refused');
await denied(ids.admin2, 'get_capacity_days', ids.station);
await t.sql('update public.station_settings set daily_gallon_limit = null where station_id = $1', [ids.station]);

// Settings save accepts the new keys and keeps them when an older client omits them.
const cfg = (await t.sql('select * from public.station_settings where station_id = $1', [ids.station]))[0];
const base = Object.fromEntries(['max_order_units','max_distance_km','booking_days','duplicate_minutes','overdue_minutes','institution_bonus_enabled','urgency_asap','urgency_soon','urgency_later',
    'urgency_immediate_minutes','urgency_soon_minutes','aging_interval_minutes','aging_points','aging_cap','institution_bonus'].map(k => [k, k === 'max_distance_km' ? Number(cfg[k]) : cfg[k]]));
let saved = await rpc(ids.admin, 'save_station_settings', ids.station, {...base, batch_capacity_load_units: 12, daily_gallon_limit: 80, customer_cancel_minutes: 20}, cfg.version, t.key());
eq(saved.ok, true, 'New order rules saved');
saved = await rpc(ids.admin, 'save_station_settings', ids.station, base, saved.data.version, t.key());
const after = (await t.sql('select batch_capacity_load_units b, daily_gallon_limit d, customer_cancel_minutes c from public.station_settings where station_id = $1', [ids.station]))[0];
eq([saved.ok, after.b, after.d, after.c], [true, 12, 80, 20], 'Omitted keys keep their values');
eq((await rpc(ids.admin, 'save_station_settings', ids.station, {...base, batch_capacity_load_units: 0}, saved.data.version, t.key())).error_code, 'invalid_input', 'Batch below 1 refused');

console.log(`${t.count()} capacity checks passed.`);
