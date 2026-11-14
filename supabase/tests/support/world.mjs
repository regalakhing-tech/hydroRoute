// Shared PGlite fixture for the Phase 4-8 SQL tests: two approved stations, two owners, two customers,
// one rider per station and a Super Admin. Test-only Supabase stand-ins; never used by the application.
import {PGlite} from '@electric-sql/pglite';
import {readFile, readdir} from 'node:fs/promises';
import assert from 'node:assert/strict';

export const uuid = n => '00000000-0000-0000-0000-' + String(n).padStart(12, '0');
export const KM_PER_DEG = 6371 * Math.PI / 180;
export const ids = {
    customer: uuid(1), customer2: uuid(2), admin: uuid(3), admin2: uuid(4), rider: uuid(5), rider2: uuid(6), superadmin: uuid(7), rider3: uuid(8),
    station: uuid(101), station2: uuid(102),
};
export const STATION_PIN = [10.6, 122.0];
export const STATION2_PIN = [10.65, 122.03];

export async function world() {
    const db = await PGlite.create();
    let checks = 0;
    const t = {db, ids};
    t.eq = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
    t.ok = (value, label) => { assert.ok(value, label); checks++; };
    t.count = () => checks;
    await db.exec("create role anon; create role authenticated; create role service_role; create schema auth; create table auth.users(id uuid primary key, raw_user_meta_data jsonb); create function auth.uid() returns uuid language sql stable as $$select nullif(current_setting('request.jwt.claim.sub',true),'')::uuid$$; grant usage on schema auth, public to anon, authenticated, service_role; grant execute on function auth.uid() to anon, authenticated, service_role;");
    for (const f of (await readdir('supabase/migrations')).filter(f => f.endsWith('.sql')).sort()) {
        try { await db.exec(await readFile('supabase/migrations/' + f, 'utf8')); }
        catch (e) { console.error(f, e.message, e.position, e.where); throw e; }
    }
    t.owner = () => db.exec("reset role; select set_config('request.jwt.claim.sub', '', false)");
    t.as = async user => db.exec("reset role; set role authenticated; select set_config('request.jwt.claim.sub', '" + user + "', false)");
    t.sql = async (sql, params = []) => (await db.query(sql, params)).rows;
    // Calls an RPC as a user and returns its JSON envelope.
    t.rpc = async (user, fn, ...args) => {
        await t.as(user);
        const row = (await db.query('select public.' + fn + '(' + args.map((_, i) => '$' + (i + 1)).join(',') + ') as r', args.map(a => Array.isArray(a) ? '{' + a.join(',') + '}' : a !== null && typeof a === 'object' ? JSON.stringify(a) : a))).rows[0];
        await t.owner();
        return row.r;
    };
    t.denied = async (user, fn, ...args) => {
        await assert.rejects(t.rpc(user, fn, ...args), e => e.code === '42501', fn + ' must be denied');
        await t.owner();
        checks++;
    };
    t.key = (() => { let n = 9000; return () => uuid(++n).replace('00000000-0000-0000-0000', '10000000-0000-4000-8000'); })();

    const meta = (name, phone) => JSON.stringify({full_name: name, phone});
    await db.query('insert into auth.users(id, raw_user_meta_data) values ' + [
        [ids.customer, 'Maria Live', '+639171111111'], [ids.customer2, 'Pedro Live', '+639172222222'], [ids.admin, 'Owner One', '+639173333333'],
        [ids.admin2, 'Owner Two', '+639174444444'], [ids.rider, 'Rider One', '+639175555555'], [ids.rider2, 'Rider Two', '+639176666666'],
        [ids.superadmin, 'Platform', '+639177777777'], [ids.rider3, 'Rider Three', '+639178888888'],
    ].map(([id, n, p]) => `('${id}', '${meta(n, p)}')`).join(','));
    await db.exec(`insert into private.user_roles(user_id, role) values ('${ids.admin}','station_admin'),('${ids.admin2}','station_admin'),('${ids.rider}','rider'),('${ids.rider2}','rider'),('${ids.rider3}','rider'),('${ids.superadmin}','super_admin');
     insert into public.stations(id, name, address, barangay, phone, registration_status, active, latitude, longitude) values
      ('${ids.station}','Live Station One','Guintas, Hamtic','Guintas','+639170000001','approved',true,${STATION_PIN[0]},${STATION_PIN[1]}),
      ('${ids.station2}','Live Station Two','Poblacion 1, Hamtic','Poblacion 1','+639170000002','approved',true,${STATION2_PIN[0]},${STATION2_PIN[1]});
     insert into private.station_memberships(user_id, station_id) values ('${ids.admin}','${ids.station}'),('${ids.admin2}','${ids.station2}'),('${ids.rider}','${ids.station}'),('${ids.rider3}','${ids.station}'),('${ids.rider2}','${ids.station2}');
     insert into private.station_membership_roles(station_id, user_id, role) values ('${ids.station}','${ids.admin}','station_admin'),('${ids.station2}','${ids.admin2}','station_admin'),('${ids.station}','${ids.rider}','rider'),('${ids.station}','${ids.rider3}','rider'),('${ids.station2}','${ids.rider2}','rider');
     insert into public.drivers(user_id, station_id, full_name, phone, email, vehicle, plate_number, capacity) values
      ('${ids.rider}','${ids.station}','Rider One','+639175555555','r1@example.test','Tricycle','ANT 101',15),
      ('${ids.rider3}','${ids.station}','Rider Three','+639178888888','r3@example.test','Tricycle','ANT 103',5),
      ('${ids.rider2}','${ids.station2}','Rider Two','+639176666666','r2@example.test','Tricycle','ANT 102',15);
     insert into public.riders(station_id, user_id, vehicle, plate_number, capacity) values
      ('${ids.station}','${ids.rider}','Tricycle','ANT 101',15),('${ids.station}','${ids.rider3}','Tricycle','ANT 103',5),('${ids.station2}','${ids.rider2}','Tricycle','ANT 102',15);
     delete from public.station_opening_hours where station_id in ('${ids.station}','${ids.station2}');
     insert into public.station_opening_hours(station_id, weekday, window_no, opens_at, closes_at) select s, d, 1, '00:00', '23:59:59' from unnest(array['${ids.station}','${ids.station2}']::uuid[]) s cross join generate_series(0,6) d;`);
    const guintas = (await t.sql("select id from public.barangays where name = 'Guintas'"))[0].id;
    const products = async sid => Object.fromEntries((await t.sql('select default_code, id from public.products where station_id = $1', [sid])).map(r => [r.default_code, r.id]));
    t.products = await products(ids.station);
    t.products2 = await products(ids.station2);
    t.guintas = guintas;
    // Addresses: home 1.5 km north of station one, far one about 15.6 km away, one without a pin.
    t.address = async (customer, label, kmNorth, barangay = guintas) => {
        const pin = kmNorth === null ? 'null, null' : `${STATION_PIN[0] + kmNorth / KM_PER_DEG}, ${STATION_PIN[1]}`;
        return (await t.sql(`insert into public.addresses(customer_id, label, address, barangay_id, latitude, longitude) values ('${customer}', '${label}', 'Purok 1, ${label}', '${barangay}', ${pin}) returning id`))[0].id;
    };
    t.home = await t.address(ids.customer, 'Home', 1.5);
    t.far = await t.address(ids.customer, 'Far', 15.5);
    t.nopin = await t.address(ids.customer, 'Nopin', null);
    t.home2 = await t.address(ids.customer2, 'Home', 0.5);
    t.order = (items, extra = {}) => ({fulfillment: 'delivery', items: Object.entries(items).map(([code, quantity]) => ({product_id: t.products[code], quantity})), address_id: t.home, schedule: 'asap', note: '', ...extra});
    t.manila = (daysAhead, hhmm) => {
        const now = new Date(Date.now() + 8 * 3600e3 + daysAhead * 86400e3);
        return now.toISOString().slice(0, 10) + 'T' + hhmm;
    };
    return t;
}
