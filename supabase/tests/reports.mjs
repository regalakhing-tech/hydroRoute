// Phase 8 behaviour: station-private reports with separate measures, and the idempotent station job.
import {world, KM_PER_DEG, STATION_PIN} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;
const S = ids.station;
const today = new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);

// One completed delivery, one completed pickup, one cancelled order.
const d = await rpc(ids.customer, 'submit_order', S, t.order({round: 2}), t.key());
await rpc(ids.admin, 'start_preparation', d.data.order_id, 1, t.key());
await rpc(ids.admin, 'mark_order_ready', d.data.order_id, 2, t.key());
await t.sql('update public.riders set enabled = false where user_id = $1', [ids.rider3]);
const pv = await rpc(ids.admin, 'preview_trip', S);
const trip = await rpc(ids.admin, 'dispatch_trip', S, pv.data.proposal.rider_id, [d.data.order_id], t.key(), {});
let tr = await rpc(ids.rider, 'get_trip', null);
const stop = tr.data.stops[0];
await rpc(ids.rider, 'mark_arrived', stop.id, (STATION_PIN[0] + 1.5 / KM_PER_DEG).toFixed(7), STATION_PIN[1], 10, t.key(), null);
tr = await rpc(ids.rider, 'get_trip', null);
await rpc(ids.rider, 'record_delivery', {stop_id: stop.id, version: tr.data.stops[0].version, result: 'delivered', tendered_centavos: 6000, empties_returned: 2}, t.key());
tr = await rpc(ids.rider, 'get_trip', null);
await rpc(ids.rider, 'close_trip', trip.data.trip_id, tr.data.trip.version, '', t.key());
let dq = await rpc(ids.admin, 'get_dispatch_queue', S);
await rpc(ids.admin, 'verify_payment', dq.data.payments[0].id, {}, dq.data.payments[0].version, t.key());
let o = (await rpc(ids.admin, 'get_station_order', d.data.order_id)).data.order;
eq((await rpc(ids.admin, 'complete_order', d.data.order_id, o.version, t.key())).data.status, 'completed', 'Delivery completed for the report');
const p = await rpc(ids.customer, 'submit_order', S, t.order({faucet: 1}, {fulfillment: 'pickup', address_id: undefined}), t.key());
await rpc(ids.admin, 'start_preparation', p.data.order_id, 1, t.key());
await rpc(ids.admin, 'mark_order_ready', p.data.order_id, 2, t.key());
const coll = await rpc(ids.admin, 'record_pickup_collection', p.data.order_id, {tendered_centavos: '2000', empties_returned: '1'}, 3, t.key());
const unverifiedPickup = coll.data.payment_id;
eq((await t.sql('select state from public.payments where id = $1', [unverifiedPickup]))[0].state, 'verified', 'Pickup cash is verified at the counter');
// Stand-in for rider cash still waiting for handover, so the sales report keeps its three separate measures.
await t.sql("update public.payments set state = 'unverified', verified_by = null, verified_at = null where id = $1", [unverifiedPickup]);
const c = await rpc(ids.customer, 'submit_order', S, t.order({round: 1}, {fulfillment: 'pickup', address_id: undefined, note: 'x'}), t.key());
await rpc(ids.customer, 'cancel_order', c.data.order_id, 1, 'Changed plans', t.key());

let sales = await rpc(ids.admin, 'get_station_report', S, 'sales', today, today, {});
eq([sales.data.completed_orders, Number(sales.data.completed_total_centavos), Number(sales.data.delivery_fee_centavos)], [1, 5000, 1000], 'Completed totals and delivery fees by completion date');
eq([Number(sales.data.cash_collected_centavos), Number(sales.data.cash_verified_centavos), Number(sales.data.unverified_now_centavos)], [7000, 5000, 2000], 'Collected, verified and outstanding cash are separate measures');
eq(sales.data.daily.length, 1, 'One row per day in range');
const orders = await rpc(ids.admin, 'get_station_report', S, 'orders', today, today, {});
eq([orders.data.placed, orders.data.by_status.completed, orders.data.by_status.cancelled, orders.data.by_status.collected], [3, 1, 1, 1], 'Orders by status');
ok(orders.data.by_product.some(r => r.quantity === 2) && orders.data.cancel_reasons[0].reason === 'Changed plans', 'Products exclude cancelled orders; cancellation reasons listed');
const del = await rpc(ids.admin, 'get_station_report', S, 'deliveries', today, today, {});
eq([del.data.trips, del.data.stops, del.data.delivered, del.data.failed, del.data.by_rider[0].rider], [1, 1, 1, 0, 'Rider One'], 'Delivery report by trip and rider');
eq((await rpc(ids.admin2, 'get_station_report', ids.station2, 'sales', today, today, {})).data.completed_orders, 0, 'Another station reports only its own work');
await denied(ids.admin2, 'get_station_report', S, 'sales', today, today, {});
await denied(ids.superadmin, 'get_station_report', S, 'sales', today, today, {});
eq((await rpc(ids.admin, 'get_station_report', S, 'sales', today, '2020-01-01', {})).error_code, 'validation', 'End before start rejected');
eq((await rpc(ids.admin, 'get_station_report', S, 'payroll', today, today, {})).error_code, 'validation', 'Unknown report kind rejected');

// Station job.
const sch = await rpc(ids.customer, 'submit_order', S, t.order({round: 1}, {fulfillment: 'pickup', address_id: undefined, schedule: 'scheduled', requested_at: t.manila(1, '10:00')}), t.key());
eq(sch.data.status, 'scheduled', 'Future-day order waits');
await denied(ids.admin, 'run_station_jobs', S);
let run = (await t.sql('select public.run_station_jobs($1) r', [S]))[0].r;
eq([run.released, (await t.sql('select status from public.orders where id = $1', [sch.data.order_id]))[0].status], [0, 'scheduled'], 'Not released before its service day');
await t.sql("update public.orders set service_date = (now() at time zone 'Asia/Manila')::date where id = $1", [sch.data.order_id]);
eq((await t.sql('select public.run_station_jobs($1) r', [S]))[0].r.skipped, true, 'Same minute slot runs once');
await t.sql("delete from private.station_job_runs");
const res = await rpc(ids.customer, 'request_reservation', S, {requested_at: t.manila(2, '10:00'), fulfillment: 'pickup', items: [{product_id: t.products.round, quantity: 1}]}, t.key());
await t.sql("update public.reservations set requested_at = now() - interval '1 hour' where id = $1", [res.data.reservation_id]);
await t.sql(`insert into public.rider_locations(station_id, trip_id, rider_user_id, latitude, longitude, accuracy, recorded_at) values ($1, $2, $3, 10.6, 122, 5, now() - interval '31 days'), ($1, $2, $3, 10.6, 122, 5, now())`, [S, trip.data.trip_id, ids.rider]);
run = (await t.sql('select public.run_station_jobs($1) r', [S]))[0].r;
eq([run.released, run.expired], [1, 1], 'Releases the due scheduled order and expires the unanswered reservation');
const log = (await t.sql('select to_status, actor_id, system_job from public.order_status_logs where order_id = $1 order by order_version desc limit 1', [sch.data.order_id]))[0];
eq(log, {to_status: 'confirmed', actor_id: null, system_job: 'release_scheduled'}, 'Release logged as a station job, not a fake user');
eq((await t.sql("select state from public.reservations where id = $1", [res.data.reservation_id]))[0].state, 'expired', 'Reservation expired');
eq((await t.sql('select count(*)::int n from public.rider_locations where trip_id = $1', [trip.data.trip_id]))[0].n, 1, 'Positions older than 30 days deleted');
eq((await t.sql("select status from private.station_job_runs where station_id = $1", [S]))[0].status, 'done', 'Job run recorded');
ok((await rpc(ids.customer, 'list_notifications', null, {})).data.notifications.some(n => n.kind === 'reservation_expired'), 'Customer told the reservation expired');
eq((await t.sql('select public.run_all_station_jobs() r'))[0].r.stations, 2, 'All approved stations processed');
eq(unverifiedPickup.length, 36, 'Pickup payment left unverified on purpose');
console.log(t.count() + ' report and job checks passed.');
await t.db.close();
