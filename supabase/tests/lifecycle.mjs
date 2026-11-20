// M13 rider editing and M17 station suspend/remove, with cross-station and role denial.
import {world} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;

// Drivers page data: owner only, own station only.
let list = await rpc(ids.admin, 'get_station_riders', ids.station);
eq(list.data.riders.map(r => r.full_name).sort(), ['Rider One', 'Rider Three'], 'Owner lists their own riders');
await denied(ids.admin2, 'get_station_riders', ids.station);
await denied(ids.customer, 'get_station_riders', ids.station);
await denied(ids.superadmin, 'get_station_riders', ids.station);
const one = list.data.riders.find(r => r.full_name === 'Rider One');
eq([one.enabled, one.trip_id, one.capacity, one.vehicle], [true, null, 15, 'Tricycle'], 'Rider row has status, trip, capacity and vehicle');

// Editing name, phone, vehicle and status; omitted name and phone stay.
const base = {vehicle: 'Delivery van', plate_number: 'abc 123', capacity: '12', enabled: 'true'};
let saved = await rpc(ids.admin, 'save_rider', one.rider_id, {...base, full_name: '  Rider   Uno ', phone: '+639179990000'}, one.version, t.key());
eq(saved.ok, true, 'Owner edits a rider');
let row = (await t.sql('select d.full_name, d.phone, d.vehicle, d.plate_number, d.capacity, r.capacity rc from public.drivers d join public.riders r on r.user_id = d.user_id where d.user_id = $1', [ids.rider]))[0];
eq([row.full_name, row.phone, row.vehicle, row.plate_number, row.capacity, row.rc], ['Rider Uno', '+639179990000', 'Delivery van', 'ABC 123', 12, 12], 'Name, phone and vehicle saved on both records');
saved = await rpc(ids.admin, 'save_rider', one.rider_id, base, saved.data.version, t.key());
eq((await t.sql('select full_name from public.drivers where user_id = $1', [ids.rider]))[0].full_name, 'Rider Uno', 'Omitted name is kept');
eq((await rpc(ids.admin, 'save_rider', one.rider_id, {...base, phone: '0917 123'}, saved.data.version, t.key())).error_code, 'validation', 'Bad phone refused');
eq((await rpc(ids.admin, 'save_rider', one.rider_id, {...base, full_name: 'X'}, saved.data.version, t.key())).error_code, 'validation', 'Too-short name refused');
eq((await rpc(ids.admin, 'save_rider', one.rider_id, {...base, capacity: '101'}, saved.data.version, t.key())).error_code, 'validation', 'Capacity over 100 refused');
eq((await rpc(ids.admin, 'save_rider', one.rider_id, {...base, role: 'station_admin'}, saved.data.version, t.key())).error_code, 'validation', 'Unknown keys refused');
eq((await rpc(ids.admin, 'save_rider', one.rider_id, base, saved.data.version - 1, t.key())).error_code, 'stale_version', 'Stale version refused');
await denied(ids.admin2, 'save_rider', one.rider_id, base, saved.data.version, t.key());
const key = t.key();
const off = await rpc(ids.admin, 'save_rider', one.rider_id, {...base, enabled: 'false'}, saved.data.version, key);
eq((await rpc(ids.admin, 'save_rider', one.rider_id, {...base, enabled: 'false'}, saved.data.version, key)).data, off.data, 'Same request key replays');
eq((await t.sql('select count(*)::int n from private.free_riders($1) where user_id = $2', [ids.station, ids.rider]))[0].n, 0, 'Disabled rider is not offered for dispatch');
list = await rpc(ids.admin, 'get_station_riders', ids.station);
eq(list.data.riders.find(r => r.user_id === ids.rider).enabled, false, 'Drivers page shows the rider as inactive');
await rpc(ids.admin, 'save_rider', one.rider_id, base, off.data.version, t.key());

// Station lifecycle: Super Admin only, reason required, optimistic status check.
const set = (status, expected, reason = 'Closed for repairs', k = t.key()) => rpc(ids.superadmin, 'set_station_status', ids.station2, status, expected, reason, k);
await denied(ids.admin2, 'set_station_status', ids.station2, 'suspended', 'approved', 'Owner tries', t.key());
await denied(ids.customer, 'set_station_status', ids.station2, 'suspended', 'approved', 'Customer tries', t.key());
eq((await set('suspended', 'approved', 'x')).error_code, 'validation', 'Reason required');
eq((await set('deleted', 'approved')).error_code, 'validation', 'Unknown status refused');
eq((await set('suspended', 'suspended')).error_code, 'stale_version', 'Stale expected status refused');

// An open order at station two, then suspend: hidden from new orders, owner keeps the workspace.
const q2 = {fulfillment: 'pickup', items: [{product_id: t.products2.round, quantity: 1}], schedule: 'asap', note: ''};
const open = await rpc(ids.customer, 'submit_order', ids.station2, q2, t.key());
eq(open.ok, true, 'Customer orders from station two');
const sk = t.key();
const sus = await set('suspended', 'approved', 'Unpaid permit', sk);
eq([sus.ok, sus.data.status], [true, 'suspended'], 'Super Admin suspends a station');
eq((await set('suspended', 'approved', 'Unpaid permit', sk)).data, sus.data, 'Suspension replays with the same key');
eq((await rpc(ids.customer, 'quote_order', ids.station2, q2)).error_code, 'station_unavailable', 'Suspended station takes no new orders');
let access = (await t.sql("select set_config('request.jwt.claim.sub', $1, false), public.my_access() a", [ids.admin2]))[0].a;
await t.owner();
eq(access.stations.map(s => [s.id, s.status]), [[ids.station2, 'suspended']], 'Owner still reaches a suspended station, with its status');
eq((await rpc(ids.admin2, 'get_station_overview', ids.station2)).ok, true, 'Owner can still work on open orders');
eq((await t.sql('select status_reason, status_changed_by from public.stations where id = $1', [ids.station2]))[0].status_reason, 'Unpaid permit', 'Reason stored on the station');
eq((await t.sql("select count(*)::int n from public.station_audit_events where station_id = $1 and action = 'set_station_status'", [ids.station2]))[0].n, 1, 'Status change audited once');

// Removal waits for open work, then ends staff access and disables riders; history stays.
const busy = await set('archived', 'suspended', 'Business closed');
eq(busy.error_code, 'station_busy', 'Removal refused while an order is open');
const ov = (await rpc(ids.customer, 'get_customer_order', open.data.order_id)).data.order;
eq((await rpc(ids.admin2, 'cancel_order', open.data.order_id, ov.version, 'Station closing', t.key())).ok, true, 'Owner cancels the last open order');
const gone = await set('archived', 'suspended', 'Business closed');
eq([gone.ok, gone.data.status], [true, 'archived'], 'Super Admin removes the station');
eq((await t.sql('select active, registration_status from public.stations where id = $1', [ids.station2]))[0], {active: false, registration_status: 'archived'}, 'Station hidden and archived');
eq((await t.sql('select bool_or(active) a from private.station_memberships where station_id = $1', [ids.station2]))[0].a, false, 'Staff memberships ended');
eq((await t.sql('select bool_or(enabled) e from public.riders where station_id = $1', [ids.station2]))[0].e, false, 'Riders disabled');
await denied(ids.admin2, 'get_station_overview', ids.station2);
access = (await t.sql("select set_config('request.jwt.claim.sub', $1, false), public.my_access() a", [ids.admin2]))[0].a;
await t.owner();
eq(access.stations, [], 'Removed station leaves the owner workspace');
eq(Number((await t.sql('select count(*) n from public.orders where station_id = $1', [ids.station2]))[0].n), 1, 'Order history kept');
eq((await set('approved', 'archived', 'Changed mind')).error_code, 'invalid_transition', 'Removal is final');
eq((await rpc(ids.admin, 'get_station_overview', ids.station)).ok, true, 'Other stations are unaffected');

console.log(`${t.count()} rider and station lifecycle checks passed.`);
