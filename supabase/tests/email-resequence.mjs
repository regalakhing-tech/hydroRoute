// Batches per barangay (15 per trip, the rest in the next trip), re-planning remaining stops after a failure or on
// request (SSTF from the rider's position), and the order-update email outbox with retries.
import {world, KM_PER_DEG, STATION_PIN} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;
const S = ids.station;
const lat = km => (STATION_PIN[0] + km / KM_PER_DEG).toFixed(7);
let n = 500;
const mk = async (o = {}) => {
    const v = {load: 5, km: 1, barangay: t.guintas, ...o};
    return (await t.sql(`insert into public.orders(station_id, customer_id, fulfillment, address_id, status, schedule_mode, queued_at, created_at, load_units, total_units, expected_empties,
        address_barangay_id, address_barangay, address_latitude, address_longitude, station_latitude, station_longitude, order_no, contact_name, total_centavos, subtotal_centavos, quoted_fee_centavos, fee_version)
        values ($1, $2, 'delivery', $3, 'ready', 'asap', now() - interval '5 minutes', now(), $4, $4, $4, $5, (select name from public.barangays where id = $5), $6, 122, ${STATION_PIN[0]}, ${STATION_PIN[1]}, $7, 'Fixture', 2000, 1000, 1000, 1) returning id`,
        [S, ids.customer, t.home, v.load, v.barangay, lat(v.km), 'BAT-' + (++n)]))[0].id;
};

// Batches: one barangay per trip, at most 15 containers; what does not fit waits for the next trip.
await t.sql('update public.riders set capacity = 15 where station_id = $1', [S]);
const big1 = await mk({load: 10, km: 1}), big2 = await mk({load: 10, km: 2}), small = await mk({load: 5, km: 3});
const pob = (await t.sql("select id from public.barangays where name = 'Poblacion 1'"))[0].id;
const other = await mk({load: 2, km: 1.2, barangay: pob});
let p = (await rpc(ids.admin, 'preview_trip', S)).data.proposal;
eq([p.load, p.capacity, p.stops.length], [15, 15, 2], 'Batch 1 is filled up to 15 containers');
ok(p.stops.every(s => s.barangay === 'Guintas'), 'Batch 1 serves one barangay');
const trip1 = await rpc(ids.admin, 'dispatch_trip', S, p.rider_id, p.stops.map(s => s.order_id), t.key(), {});
eq(trip1.ok, true, 'Batch 1 dispatched');
p = (await rpc(ids.admin, 'preview_trip', S)).data.proposal;
ok(p && p.rider_id !== trip1.data.rider_id, 'Next free rider gets batch 2');
const left = (await t.sql('select count(*)::int n from private.dispatch_candidates($1, now())', [S]))[0].n;
eq(left, 2, 'The remaining orders wait for the next batch');
ok(p.load <= 15 && new Set(p.stops.map(s => s.barangay)).size === 1, 'Batch 2 also stays within 15 and one barangay');
eq((await rpc(ids.admin, 'dispatch_trip', S, p.rider_id, p.stops.map(s => s.order_id), t.key(), {})).ok, true, 'Batch 2 dispatched');
await t.sql("update public.orders set status = 'cancelled' where station_id = $1 and status in ('ready')", [S]);
await t.sql("update public.trip_orders set active = false, state = 'delivered' where station_id = $1", [S]);
await t.sql("update public.delivery_trips set status = 'closed' where station_id = $1", [S]);
await t.sql('delete from private.rider_reservations');

// Re-plan: after a failed stop the remaining stops continue nearest-first from that address.
const a = await mk({load: 2, km: 1}), b = await mk({load: 2, km: 4}), c = await mk({load: 2, km: 2.5}), d = await mk({load: 2, km: 0.5});
const rid = (await t.sql('select id from public.riders where user_id = $1', [ids.rider]))[0].id;
const trip = await rpc(ids.admin, 'dispatch_trip', S, rid, [a, b, c, d], t.key(), {});
eq(trip.ok, true, 'Four-stop trip dispatched');
const order = async () => (await t.sql('select order_id from public.trip_orders where trip_id = $1 and active and state = $2 order by sequence', [trip.data.trip_id, 'pending'])).map(r => r.order_id);
eq(await order(), [d, a, c, b], 'SSTF from the station: 0.5, 1, 2.5, 4 km');
const stopD = (await t.sql('select id from public.trip_orders where trip_id = $1 and order_id = $2', [trip.data.trip_id, d]))[0].id;
await rpc(ids.rider, 'mark_arrived', stopD, null, null, null, t.key(), 'Location unavailable');
const sv = (await t.sql('select version from public.trip_orders where id = $1', [stopD]))[0].version;
eq((await rpc(ids.rider, 'record_delivery', {stop_id: stopD, version: sv, result: 'failed', failure_reason: 'Customer unavailable'}, t.key())).ok, true, 'Rider records a failed stop');
eq(await order(), [a, c, b], 'Remaining stops still nearest-first from the failed address');
// Rider changes course: re-plan from a point beside the 4 km stop.
const re = await rpc(ids.rider, 'resequence_trip', trip.data.trip_id, Number(lat(3.9)), 122, t.key());
eq([re.ok, re.data.stops], [true, 3], 'Rider re-plans from where they are');
eq(await order(), [b, c, a], 'Order now starts with the stop beside the rider');
eq(new Set((await t.sql('select sequence from public.trip_orders where trip_id = $1', [trip.data.trip_id])).map(r => r.sequence)).size, 4, 'Stop numbers stay unique');
eq((await rpc(ids.rider, 'resequence_trip', trip.data.trip_id, null, null, t.key())).error_code, 'validation', 'Re-plan needs a location');
await denied(ids.rider3, 'resequence_trip', trip.data.trip_id, 10.6, 122, t.key());
await denied(ids.customer, 'resequence_trip', trip.data.trip_id, 10.6, 122, t.key());

// Email outbox: customer order notices are queued once, retried with backoff, then given up after five tries.
await t.sql('alter table auth.users add column if not exists email text');
await t.sql("update auth.users set email = 'maria@example.test' where id = $1", [ids.customer]);
const queued = await t.sql("select o.subject, o.link, o.status from private.email_outbox o join public.orders x on x.id = o.order_id where x.id = $1 order by o.created_at", [d]);
ok(queued.length >= 1 && queued.every(q => q.status === 'pending' && q.link === '/customer/tracking?order=' + d), 'Failed-delivery notice queued for the customer with a tracking link');
eq((await t.sql("select count(*)::int n from private.email_outbox o join public.notifications nt on nt.id = o.notification_id where nt.kind like 'staff\\_%'"))[0].n, 0, 'Station notices are not emailed');
await denied(ids.customer, 'claim_email_batch', 5);
const claim = async () => (await t.sql('select public.claim_email_batch(20) r'))[0].r.emails;
let batch = await claim();
ok(batch.length >= 1 && batch[0].email === 'maria@example.test' && batch[0].attempts === 1, 'Sender claims due emails with the address');
eq((await claim()).length, 0, 'Claimed emails are locked against a second sender');
const id = batch[0].id;
await t.sql("select public.finish_email($1, false, 'SMTP timeout')", [id]);
let row = (await t.sql('select status, attempts, next_attempt_at > now() + interval \'90 seconds\' later from private.email_outbox where id = $1', [id]))[0];
eq([row.status, row.later], ['pending', true], 'A failure waits before retrying');
for (let i = 0; i < 4; i++) {
    await t.sql("update private.email_outbox set next_attempt_at = now() - interval '1 second' where id = $1", [id]);
    batch = await claim();
    await t.sql("select public.finish_email($1, false, 'SMTP down')", [id]);
}
eq((await t.sql('select status, attempts from private.email_outbox where id = $1', [id]))[0], {status: 'failed', attempts: 5}, 'Gives up after five attempts');
const next = (await claim())[0];
if (next) { await t.sql('select public.finish_email($1, true, null)', [next.id]); eq((await t.sql('select status from private.email_outbox where id = $1', [next.id]))[0].status, 'sent', 'A success is marked sent'); }
// Opting out of order updates stops new emails.
await t.sql('update public.profiles set order_updates = false where id = $1', [ids.customer]);
const before = (await t.sql('select count(*)::int n from private.email_outbox'))[0].n;
await t.sql("select private.notify($1, $2, 'test:optout', 'order_confirmed', null, 'Order confirmed', 'Test')", [S, ids.customer]);
eq((await t.sql('select count(*)::int n from private.email_outbox'))[0].n, before, 'No email when order updates are off');

// Review fixes: a queued email is dropped if the customer opts out before it is sent; an expired fifth try is not retried.
await t.sql('update public.profiles set order_updates = true where id = $1', [ids.customer]);
await t.sql("select private.notify($1, $2, 'test:late-optout', 'order_ready', null, 'Order ready', 'Test')", [S, ids.customer]);
const late = (await t.sql("select o.id from private.email_outbox o join public.notifications nt on nt.id = o.notification_id where nt.event_key = 'test:late-optout'"))[0].id;
await t.sql('update public.profiles set order_updates = false where id = $1', [ids.customer]);
ok(!(await claim()).some(e => e.id === late), 'Opted-out customer: queued email is not sent');
eq((await t.sql('select status from private.email_outbox where id = $1', [late]))[0].status, 'failed', 'Dropped email is closed, not left pending');
await t.sql('update public.profiles set order_updates = true where id = $1', [ids.customer]);
await t.sql("select private.notify($1, $2, 'test:exhausted', 'order_ready', null, 'Order ready', 'Test')", [S, ids.customer]);
const tired = (await t.sql("select o.id from private.email_outbox o join public.notifications nt on nt.id = o.notification_id where nt.event_key = 'test:exhausted'"))[0].id;
await t.sql("update private.email_outbox set status = 'sending', attempts = 5, locked_until = now() - interval '1 minute' where id = $1", [tired]);
ok(!(await claim()).some(e => e.id === tired), 'A sender that died on the fifth try does not get a sixth');
eq((await t.sql('select status, attempts from private.email_outbox where id = $1', [tired]))[0], {status: 'failed', attempts: 5}, 'Exhausted email is marked failed');

console.log(`${t.count()} batch, re-plan and email checks passed.`);
