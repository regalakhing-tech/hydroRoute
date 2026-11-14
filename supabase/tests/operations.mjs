// Phases 5-6 behaviour: priority (blueprint example), aging, SSTF, dispatch batching and capacity,
// full delivery and pickup flows, arrival fee rules (D2), failed attempts, reviews and isolation.
import {world, KM_PER_DEG, STATION_PIN} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;
const S = ids.station;
const pob = (await t.sql("select id from public.barangays where name = 'Poblacion 1'"))[0].id;
const lat = km => (STATION_PIN[0] + km / KM_PER_DEG).toFixed(7);
let n = 900;
const mk = async (o = {}) => {
    const v = {customer: ids.customer, address: t.home, status: 'ready', mode: 'asap', requested: null, queued: 'now()', load: 1, barangay: t.guintas, km: 1.5, ...o};
    return (await t.sql(`insert into public.orders(station_id, customer_id, fulfillment, address_id, status, schedule_mode, requested_at, queued_at, created_at, load_units, total_units,
        address_barangay_id, address_barangay, address_latitude, address_longitude, station_latitude, station_longitude, order_no, contact_name, total_centavos, subtotal_centavos, quoted_fee_centavos, fee_version)
        values ($1, $2, 'delivery', $3, $4, $5, ${v.requested ?? 'null'}, ${v.queued}, ${v.queued}, $6, $6, $7, (select name from public.barangays where id = $7), $8, 122, ${STATION_PIN[0]}, ${STATION_PIN[1]}, $9, 'Fixture', 2000, 1000, 1000, 1) returning id`,
        [S, v.customer, v.address, v.status, v.mode, v.load, v.barangay, lat(v.km), o.no ?? 'FIX-' + (++n)]))[0].id;
};

// Blueprint worked example at 9:00 AM with the institution bonus on (P = U + A + C).
const T = "'2026-11-16 09:00:00+08'::timestamptz";
await t.sql("update public.station_settings set institution_bonus_enabled = true where station_id = $1", [S]);
await t.sql("insert into public.customer_station_profiles(station_id, customer_id, classification) values ($1, $2, 'institution')", [S, ids.customer2]);
const fixture = {
    'HMT-104': await mk({no: 'HMT-104', customer: ids.customer2, address: t.home2, status: 'confirmed', queued: `${T} - interval '20 minutes'`, load: 10}),
    'HMT-101': await mk({no: 'HMT-101', status: 'confirmed', queued: `${T} - interval '30 minutes'`, load: 3}),
    'HMT-102': await mk({no: 'HMT-102', status: 'confirmed', mode: 'scheduled', requested: `${T} + interval '45 minutes'`, queued: `${T} - interval '10 minutes'`, load: 2}),
    'HMT-103': await mk({no: 'HMT-103', status: 'confirmed', queued: `${T} - interval '5 minutes'`, load: 4}),
    'HMT-107': await mk({no: 'HMT-107', status: 'confirmed', mode: 'scheduled', requested: `${T} + interval '4 hours'`, queued: `${T} - interval '40 minutes'`, load: 5}),
};
const queue = (await t.sql(`select private.queue_rows($1, array['confirmed'], null, ${T}) q`, [S]))[0].q;
eq(queue.map(r => [r.order_no, r.score.p]), [['HMT-104', 60], ['HMT-101', 55], ['HMT-102', 45], ['HMT-103', 40], ['HMT-107', 30]], 'Blueprint priority order and scores');
eq([queue[0].score.u, queue[0].score.a, queue[0].score.c], [40, 10, 10], 'HMT-104 components U, A, C');
const fresh = await mk({status: 'confirmed', queued: T});
const aged = [];
for (const m of [9, 10, 49, 50, 60]) aged.push((await t.sql(`select (private.score(o, c, false, ${T} + interval '${m} minutes')->>'a')::int a from public.orders o, public.station_settings c where o.id = $1 and c.station_id = o.station_id`, [fresh]))[0].a);
eq(aged, [0, 5, 20, 25, 25], 'Aging at 9/10/49/50/60 minutes gives 0/5/20/25/25');
await t.sql("update public.orders set status = 'cancelled' where station_id = $1", [S]);
await t.sql("update public.station_settings set institution_bonus_enabled = false where station_id = $1", [S]);

// SSTF: canonical B, A, C and a five-stop example with an exact tie broken by priority.
const A = await mk({km: 3, status: 'cancelled'}), B = await mk({km: 1, status: 'cancelled'}), C = await mk({km: 4, status: 'cancelled'});
eq((await t.sql('select order_id from private.sstf($1, $2::uuid[], null, now()) order by seq', [S, `{${A},${B},${C}}`])).map(r => r.order_id), [B, A, C], 'SSTF visits B, A, C');
const p1 = await mk({km: 1, status: 'cancelled', queued: "now() - interval '30 minutes'"}), p2 = await mk({km: -1, status: 'cancelled'});
const p3 = await mk({km: 2, status: 'cancelled'}), p4 = await mk({km: -3, status: 'cancelled'}), p5 = await mk({km: 2.5, status: 'cancelled'});
const five = await t.sql('select order_id, leg_km from private.sstf($1, $2::uuid[], null, now()) order by seq', [S, `{${p4},${p2},${p5},${p3},${p1}}`]);
eq(five.map(r => r.order_id), [p1, p3, p5, p2, p4], 'Five stops: tie at 1 km goes to the higher priority, then nearest next');
eq(five.map(r => Number(r.leg_km)), [1, 1, 0.5, 3.5, 2], 'Leg distances in km');
eq((await t.sql('select order_id from private.sstf($1, $2::uuid[], $3, now()) order by seq', [S, `{${A},${B},${C}}`, C])).map(r => r.order_id), [C, A, B], 'An overdue stop placed first, then SSTF from there');

// Dispatch batching (blueprint): two riders, capacities 15 and 5.
const g1 = await mk({load: 10, queued: "now() - interval '20 minutes'"}), g2 = await mk({load: 4, queued: 'now()', km: 2});
await t.sql('update public.orders set expected_empties = 1 where id = $1', [g1]);
const b1 = await mk({load: 3, barangay: pob, queued: "now() - interval '15 minutes'", km: 0.8});
const b2 = await mk({load: 2, barangay: pob, mode: 'scheduled', requested: "now() + interval '45 minutes'", queued: "now() - interval '5 minutes'", km: 1.2});
const b3 = await mk({load: 5, barangay: pob, mode: 'scheduled', requested: "now() + interval '4 hours'", queued: "now() - interval '40 minutes'", km: 1.1});
let pv = await rpc(ids.admin, 'preview_trip', S);
eq([pv.data.proposal.rider_name, pv.data.proposal.load, pv.data.proposal.stops.map(s => s.order_id)], ['Rider One', 14, [g1, g2]], 'Trip 1: Guintas anchor plus same-barangay order, 14 of 15');
await denied(ids.admin2, 'preview_trip', S);
await denied(ids.superadmin, 'get_dispatch_queue', S);
const riderId = pv.data.proposal.rider_id;
const dkey = t.key();
eq((await rpc(ids.admin, 'dispatch_trip', S, riderId, [g1, b1], dkey, {})).error_code, 'validation', 'Mixed barangays rejected');
eq((await rpc(ids.admin, 'dispatch_trip', S, riderId, [g1, g2], dkey, {front_order_id: g2, front_reason: 'Waiting long'})).error_code, 'validation', 'Only an overdue stop may go first');
const trip1 = await rpc(ids.admin, 'dispatch_trip', S, riderId, [g1, g2], dkey, {});
eq([trip1.ok, trip1.data.trip_no], [true, 1], 'Trip dispatched');
eq((await rpc(ids.admin, 'dispatch_trip', S, riderId, [g1, g2], dkey, {})).data.trip_id, trip1.data.trip_id, 'Dispatch retry returns the same trip');
eq((await t.sql("select count(*)::int n from public.orders where id = any($1::uuid[]) and status = 'out_for_delivery' and driver_id = $2", [`{${g1},${g2}}`, ids.rider]))[0].n, 2, 'Orders out for delivery with the rider');
pv = await rpc(ids.admin, 'preview_trip', S);
eq([pv.data.proposal.rider_name, pv.data.proposal.stops.map(s => s.order_id).sort()], ['Rider Three', [b1, b2].sort()], 'Trip 2: Poblacion orders on the 5-unit vehicle; not-yet-due order waits');
ok(!pv.data.eligible.some(e => e.order_id === b3), 'Order due in 4 hours is not eligible');
eq((await rpc(ids.admin, 'dispatch_trip', S, riderId, [b1], t.key(), {})).error_code, 'rider_busy', 'A rider on a trip cannot take another');
await t.sql("update public.orders set status = 'cancelled' where id = any($1::uuid[])", [`{${b1},${b2},${b3}}`]);
const big = await mk({load: 20});
const small = await mk({load: 2, km: 0.7});
pv = await rpc(ids.admin, 'preview_trip', S);
ok(pv.data.warnings.some(w => w.order_id === big) && pv.data.proposal.stops[0].order_id === small, 'Order larger than every free vehicle is flagged and the next feasible order is proposed');
await t.sql("update public.orders set status = 'cancelled' where id = any($1::uuid[])", [`{${big},${small}}`]);

// Rider: own trip only.
let trip = await rpc(ids.rider, 'get_trip', null);
eq(trip.data.trip.trip_no, 1, 'Rider sees their active trip');
eq(trip.data.stops.map(s => s.order.id), [g1, g2], 'Stops in SSTF order');
await denied(ids.rider2, 'get_trip', trip1.data.trip_id);
await denied(ids.customer, 'get_trip', trip1.data.trip_id);
eq((await rpc(ids.rider2, 'get_trip', null)).data.trip, null, 'Another rider has no trip');
const stop1 = trip.data.stops[0], stop2 = trip.data.stops[1];
await denied(ids.rider2, 'mark_arrived', stop1.id, lat(1.5), 122, 10, t.key(), null);
eq((await rpc(ids.rider, 'record_rider_location', trip1.data.trip_id, lat(1), 122, 12, t.key())).ok, true, 'Location saved during the active trip');
await denied(ids.rider2, 'record_rider_location', trip1.data.trip_id, lat(1), 122, 12, t.key());
eq((await rpc(ids.rider, 'mark_arrived', stop1.id, null, null, null, t.key(), '')).error_code, 'validation', 'No GPS needs a reason');
let arr = await rpc(ids.rider, 'mark_arrived', stop1.id, lat(0.5), 122, 15, t.key(), null);
eq([arr.data.fee_decision, arr.data.amount_due_centavos], ['lowered', 1500], 'Lower measured fee applied automatically (2000 becomes 1500)');
eq((await t.sql('select final_fee_centavos f, total_centavos::int tot from public.orders where id = $1', [g1]))[0], {f: 500, tot: 1500}, 'Order total follows the lowered fee');
arr = await rpc(ids.rider, 'mark_arrived', stop2.id, lat(5), 122, 20, t.key(), null);
eq([arr.data.fee_decision, arr.data.amount_due_centavos], ['flagged', 2000], 'Higher measured fee keeps the quote and opens a review');
trip = await rpc(ids.rider, 'get_trip', null);
eq((await rpc(ids.rider, 'record_delivery', {stop_id: stop1.id, version: trip.data.stops[0].version, result: 'delivered', tendered_centavos: 1000, empties_returned: 1}, t.key())).error_code, 'validation', 'Underpayment rejected');
const del = await rpc(ids.rider, 'record_delivery', {stop_id: stop1.id, version: trip.data.stops[0].version, result: 'delivered', tendered_centavos: 2000, empties_returned: 0}, t.key());
eq([del.data.result, del.data.change_centavos], ['delivered', 500], 'Delivered with change');
const fail = await rpc(ids.rider, 'record_delivery', {stop_id: stop2.id, version: trip.data.stops[1].version, result: 'failed', failure_reason: 'Customer unavailable'}, t.key());
eq(fail.data.result, 'failed', 'Failed attempt recorded');
eq((await t.sql('select status, redispatch_paused p, driver_id from public.orders where id = $1', [g2]))[0], {status: 'ready', p: true, driver_id: null}, 'Failed order back to ready and paused');
ok(!(await t.sql('select order_id from private.dispatch_candidates($1, now())', [S])).some(r => r.order_id === g2), 'Paused order is not dispatched again');
trip = await rpc(ids.rider, 'get_trip', null);
const closed = await rpc(ids.rider, 'close_trip', trip1.data.trip_id, trip.data.trip.version, '', t.key());
eq(closed.data.status, 'closed', 'Rider closes the trip after every stop has a result');
eq((await t.sql('select count(*)::int n from private.rider_reservations'))[0].n, 0, 'Rider released');
ok((await rpc(ids.rider, 'list_rider_history', {})).data.stops.length === 2, 'Rider history lists both stops');

// Owner: reviews, cash, completion, reattempt.
let dq = await rpc(ids.admin, 'get_dispatch_queue', S);
const pay = dq.data.payments.find(p => p.order_id === g1);
eq([pay.applied_centavos, pay.tendered_centavos, pay.change_centavos], [1500, 2000, 500], 'Unverified cash waits for the owner');
let o1 = (await rpc(ids.admin, 'get_station_order', g1)).data.order;
eq((await rpc(ids.admin, 'complete_order', g1, o1.version, t.key())).error_code, 'payment_unverified', 'Completion needs verified cash');
await denied(ids.admin2, 'verify_payment', pay.id, {}, pay.version, t.key());
eq((await rpc(ids.admin, 'verify_payment', pay.id, {note: 'Counted'}, pay.version, t.key())).data.state, 'verified', 'Owner verifies cash');
eq((await rpc(ids.admin, 'complete_order', g1, o1.version, t.key())).error_code, 'review_open', 'Empties mismatch blocks completion');
const reviews = (await rpc(ids.admin, 'get_preparation_queue', S)).data.reviews;
const empt = reviews.find(r => r.order_id === g1 && r.kind === 'empties_mismatch');
eq((await rpc(ids.admin, 'resolve_order_review', empt.id, 'acknowledge', '', empt.version, t.key())).error_code, 'validation', 'Resolving needs a reason');
await rpc(ids.admin, 'resolve_order_review', empt.id, 'acknowledge', 'Customer keeps one container, agreed by phone', empt.version, t.key());
eq((await rpc(ids.admin, 'complete_order', g1, o1.version, t.key())).data.status, 'completed', 'Delivery flow completes');
const failRev = reviews.find(r => r.order_id === g2 && r.kind === 'failed_attempt');
ok(failRev && reviews.some(r => r.order_id === g2 && r.kind === 'fee_mismatch'), 'Failed-attempt and fee reviews listed');
let o2 = (await rpc(ids.admin, 'get_station_order', g2)).data.order;
eq((await rpc(ids.admin, 'approve_reattempt', g2, o2.version, 'x', t.key())).error_code, 'validation', 'Reattempt needs a reason');
eq((await rpc(ids.admin, 'approve_reattempt', g2, o2.version, 'Customer is home after 3 PM', t.key())).ok, true, 'Reattempt approved');
ok((await t.sql('select order_id from private.dispatch_candidates($1, now())', [S])).some(r => r.order_id === g2), 'Approved order is eligible again');
o2 = (await rpc(ids.admin, 'get_station_order', g2)).data.order;
eq((await rpc(ids.admin, 'review_delivery_fee', g2, 'keep_quote', 'Pin is correct; rider parked far', o2.version, t.key())).ok, true, 'Fee review recorded without a surcharge');
eq((await rpc(ids.admin, 'cancel_order', g2, o2.version, 'Customer moved away', t.key())).data.status, 'cancelled', 'Owner may cancel a ready order with no trip or payment (D9)');

// Full RPC flows from customer submission: delivery preparation steps and pickup collection.
const sub = await rpc(ids.customer, 'submit_order', S, t.order({round: 1}, {fulfillment: 'pickup', address_id: undefined}), t.key());
const pid = sub.data.order_id;
await denied(ids.admin2, 'start_preparation', pid, 1, t.key());
eq((await rpc(ids.admin, 'mark_order_ready', pid, 1, t.key())).error_code, 'invalid_transition', 'Cannot skip preparation');
eq((await rpc(ids.admin, 'start_preparation', pid, 1, t.key())).data.status, 'preparing', 'Start preparation');
eq((await rpc(ids.customer, 'cancel_order', pid, 2, 'Too late', t.key())).error_code, 'invalid_transition', 'Customer cannot cancel while preparing');
eq((await rpc(ids.admin, 'mark_order_ready', pid, 2, t.key())).data.status, 'ready', 'Mark ready');
eq((await rpc(ids.admin, 'record_pickup_collection', pid, {tendered_centavos: '1000', empties_returned: '1'}, 3, t.key())).error_code, 'validation', 'Pickup underpayment rejected');
const coll = await rpc(ids.admin, 'record_pickup_collection', pid, {tendered_centavos: '5000', empties_returned: '1'}, 3, t.key());
eq([coll.data.status, coll.data.change_centavos], ['collected', 3000], 'Pickup collected with change');
eq(coll.data.payment_state, 'verified', 'Pickup cash taken at the counter is verified at once');
eq((await t.sql('select state, verified_by from public.payments where id = $1', [coll.data.payment_id]))[0], {state: 'verified', verified_by: ids.admin}, 'Verified by the owner who took the cash');
eq((await rpc(ids.admin, 'complete_order', pid, coll.data.version, t.key())).data.status, 'completed', 'Pickup flow completes without a separate verification');
const flow = (await t.sql('select to_status from public.order_status_logs where order_id = $1 order by order_version', [pid])).map(r => r.to_status);
eq(flow, ['confirmed', 'preparing', 'ready', 'collected', 'completed'], 'Pickup status log');
const dflow = (await t.sql('select to_status from public.order_status_logs where order_id = $1 order by order_version', [g1])).map(r => r.to_status);
eq(dflow, ['out_for_delivery', 'delivered', 'completed'], 'Delivery status log for the fixture order');

// Overdue alert and customers.
const late = await mk({status: 'confirmed', queued: "now() - interval '130 minutes'"});
const prep = await rpc(ids.admin, 'get_preparation_queue', S);
ok(prep.data.reviews.some(r => r.order_id === late && r.kind === 'overdue') && prep.data.queue.find(r => r.id === late).overdue, 'Overdue alert raised after 120 minutes');
const ov = await rpc(ids.admin, 'get_station_overview', S);
ok(ov.data.overdue >= 1 && ov.data.counts.new >= 1 && ov.data.riders_free === 2, 'Overview counts today for this station');
await denied(ids.admin2, 'get_station_overview', S);
await denied(ids.superadmin, 'get_station_overview', S);
const cust = await rpc(ids.admin, 'get_station_customers', S, {}, {});
ok(cust.data.customers.some(c => c.customer_id === ids.customer), 'Customers who ordered here');
eq((await rpc(ids.admin2, 'get_station_customers', ids.station2, {}, {})).data.customers.length, 0, 'Second station sees none of them');
eq((await rpc(ids.admin, 'set_customer_classification', S, ids.customer, 'institution', null, t.key())).data.classification, 'institution', 'Owner classifies a customer');
await denied(ids.admin, 'set_customer_classification', S, ids.rider2, 'institution', null, t.key());

// Rider settings sync the registration row.
const r3 = (await t.sql('select id, version from public.riders where user_id = $1', [ids.rider3]))[0];
eq((await rpc(ids.admin, 'save_rider', r3.id, {vehicle: 'Delivery van', plate_number: 'abc 999', capacity: '40', enabled: 'true'}, r3.version, t.key())).ok, true, 'Owner saves rider vehicle');
eq((await t.sql('select capacity, plate_number from public.drivers where user_id = $1', [ids.rider3]))[0], {capacity: 40, plate_number: 'ABC 999'}, 'Driver record synchronized');
await denied(ids.admin2, 'save_rider', r3.id, {vehicle: 'Tricycle', plate_number: 'X1', capacity: '5', enabled: 'true'}, r3.version + 1, t.key());

// No direct reads or writes of workflow tables.
await t.as(ids.admin);
for (const sql of ['select * from public.payments', 'select * from public.delivery_trips', "update public.trip_orders set state = 'delivered'", 'select * from public.rider_locations', 'select * from private.rider_reservations']) {
    await t.db.exec(sql).then(() => { throw new Error('Allowed: ' + sql); }, e => { if (e.code !== '42501') throw e; });
}
await t.owner();
console.log(t.count() + ' operations checks passed.');
await t.db.close();
