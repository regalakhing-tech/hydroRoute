// Phase 3 behaviour: own profile, owned addresses with Hamtic pins and one default, soft delete,
// public served barangays and the customer overview; other customers, staff and Super Admin are refused.
import {world, STATION_PIN} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;
const brgy = t.guintas;
const pin = {latitude: String(STATION_PIN[0] + 0.01), longitude: String(STATION_PIN[1]), pin_source: 'map'};

// Profile.
const prof = async user => (await t.sql('select version, display_name, phone, order_updates from public.profiles where id = $1', [user]))[0];
let v = (await prof(ids.customer)).version;
eq((await rpc(ids.customer, 'save_customer_profile', {display_name: 'M', phone: '+639171111111', order_updates: 'true', station_updates: 'true'}, v, t.key())).error_code, 'validation', 'Name too short');
eq((await rpc(ids.customer, 'save_customer_profile', {display_name: 'Maria Live', phone: '09171111111', order_updates: 'true', station_updates: 'true'}, v, t.key())).error_code, 'validation', 'Phone must be +639 format');
eq((await rpc(ids.customer, 'save_customer_profile', {display_name: 'Maria Live', phone: '+639171111111', order_updates: 'true', station_updates: 'true', role: 'super_admin'}, v, t.key())).error_code, 'validation', 'Extra keys such as role rejected');
const pkey = t.key();
const saved = await rpc(ids.customer, 'save_customer_profile', {display_name: 'Maria Live', phone: '+639179999999', order_updates: 'false', station_updates: 'true'}, v, pkey);
eq([saved.ok, (await prof(ids.customer)).phone, (await prof(ids.customer)).order_updates], [true, '+639179999999', false], 'Profile saved');
eq((await rpc(ids.customer, 'save_customer_profile', {display_name: 'Maria Live', phone: '+639179999999', order_updates: 'false', station_updates: 'true'}, v, pkey)).data.version, saved.data.version, 'Same key replays the save');
eq((await rpc(ids.customer, 'save_customer_profile', {display_name: 'Maria Live', phone: '+639179999999', order_updates: 'true', station_updates: 'true'}, v, t.key())).error_code, 'stale_version', 'Stale profile version');
await denied(ids.superadmin, 'save_customer_profile', {display_name: 'Platform', phone: '+639177777777', order_updates: 'true', station_updates: 'true'}, 1, t.key());
eq((await prof(ids.customer2)).display_name, '', 'Another profile untouched');

// Addresses (customer2 starts with one fixture address).
await t.sql('delete from public.addresses where customer_id = $1', [ids.customer2]);
const a1 = await rpc(ids.customer2, 'save_customer_address', null, {label: 'Home', barangay_id: brgy, street: 'Purok 2, near chapel', landmark: 'Blue gate', ...pin}, null, t.key());
eq(a1.ok, true, 'First address saved');
let rows = await t.sql('select id, address, is_default, pin_source from public.addresses where customer_id = $1 and deleted_at is null order by created_at, id', [ids.customer2]);
eq([rows[0].address, rows[0].is_default, rows[0].pin_source], ['Purok 2, near chapel, Guintas, Hamtic, Antique', true, 'map'], 'Address text composed; first address is the default');
const a2 = await rpc(ids.customer2, 'save_customer_address', null, {label: 'Work', barangay_id: brgy, street: 'National Road', is_default: 'true'}, null, t.key());
rows = await t.sql('select id, is_default, latitude from public.addresses where customer_id = $1 and deleted_at is null', [ids.customer2]);
eq([rows.find(r => r.id === a2.data.address_id).is_default, rows.find(r => r.id === a1.data.address_id).is_default, rows.find(r => r.id === a2.data.address_id).latitude], [true, false, null], 'New default replaces the old; a pinless address is allowed');
eq((await rpc(ids.customer2, 'save_customer_address', null, {label: 'Far', barangay_id: brgy, street: 'Outside town', latitude: '11.2', longitude: '122.0', pin_source: 'map'}, null, t.key())).error_code, 'validation', 'Pin outside Hamtic rejected');
eq((await rpc(ids.customer2, 'save_customer_address', null, {label: 'X', barangay_id: brgy, street: 'Somewhere', latitude: 'abc', longitude: '122.0'}, null, t.key())).error_code, 'validation', 'Non-numeric pin rejected');
eq((await rpc(ids.customer2, 'save_customer_address', null, {label: 'X', barangay_id: t.products.round, street: 'Somewhere'}, null, t.key())).error_code, 'validation', 'Unknown barangay rejected');
const typed = await rpc(ids.customer2, 'save_customer_address', a2.data.address_id, {label: 'Work', barangay_id: brgy, street: 'National Road', latitude: pin.latitude, longitude: pin.longitude}, a2.data.version, t.key());
eq([(await t.sql('select pin_source from public.addresses where id = $1', [a2.data.address_id]))[0].pin_source, typed.data.version], ['manual', a2.data.version + 1], 'Edit adds a typed pin as manual and bumps the version');
eq((await rpc(ids.customer2, 'save_customer_address', a2.data.address_id, {label: 'Work', barangay_id: brgy, street: 'National Road'}, a2.data.version, t.key())).error_code, 'stale_version', 'Stale address version');
await denied(ids.customer, 'save_customer_address', a2.data.address_id, {label: 'Mine now', barangay_id: brgy, street: 'Elsewhere'}, typed.data.version, t.key());
await denied(ids.customer, 'delete_customer_address', a2.data.address_id, typed.data.version, t.key());
await denied(ids.admin, 'set_default_address', a1.data.address_id, 1, t.key());

// Soft delete keeps orders intact and promotes another default.
const order = await rpc(ids.customer2, 'submit_order', ids.station, {fulfillment: 'delivery', items: [{product_id: t.products.round, quantity: 1}], address_id: a2.data.address_id, schedule: 'asap', note: ''}, t.key());
eq(order.data.status, 'confirmed', 'Order placed to the edited address');
const del = await rpc(ids.customer2, 'delete_customer_address', a2.data.address_id, typed.data.version, t.key());
eq(del.ok, true, 'Customer deletes an address used by an order');
eq((await t.sql('select is_default from public.addresses where id = $1', [a1.data.address_id]))[0].is_default, true, 'Remaining address becomes the default');
eq((await t.sql('select address_text from public.orders where id = $1', [order.data.order_id]))[0].address_text, 'National Road, Guintas, Hamtic, Antique', 'Order keeps its address snapshot');
await t.as(ids.customer2);
eq((await t.sql('select count(*)::int n from public.addresses'))[0].n, 1, 'Deleted addresses are hidden from the customer');
await t.db.exec(`insert into public.addresses(customer_id, label, address) values ('${ids.customer2}', 'Direct', 'Bypass')`).then(() => { throw new Error('Direct insert allowed'); }, e => { if (e.code !== '42501') throw e; });
await t.as(ids.admin);
eq((await t.sql('select count(*)::int n from public.addresses'))[0].n, 0, 'Station staff cannot read the customer address book');
await t.owner();
eq((await rpc(ids.customer2, 'quote_delivery', ids.station, a2.data.address_id)).data.eligible, false, 'Deleted address cannot be quoted');
const def = await rpc(ids.customer2, 'set_default_address', a1.data.address_id, 1, t.key());
eq(def.ok, true, 'Setting the current default again is allowed');

// Served barangays are public for approved stations.
await t.sql("insert into public.station_service_areas(station_id, barangay_id) values ($1, $2)", [ids.station, brgy]);
await t.db.exec("reset role; set role anon; select set_config('request.jwt.claim.sub', '', false)");
eq((await t.sql('select count(*)::int n from public.station_service_areas'))[0].n, 1, 'Visitors see served barangays');
await t.owner();

// Overview.
const ov = await rpc(ids.customer2, 'get_customer_overview');
eq([ov.data.active.order_no, ov.data.active.timeline.length, ov.data.recent.length, ov.data.default_address.label], ['HMT-0001', 1, 1, 'Home'], 'Overview shows the active order, timeline, recent orders and default address');
eq((await rpc(ids.customer, 'get_customer_overview')).data.active, null, 'Another customer has no active order');
await denied(ids.superadmin, 'get_customer_overview');
console.log(t.count() + ' customer checks passed.');
await t.db.close();
