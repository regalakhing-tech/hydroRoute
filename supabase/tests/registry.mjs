// Station registration, approval, driver provisioning and RLS, run against the real migrations and seed in PGlite.
// Run: node supabase/tests/registry.mjs
import {PGlite} from '@electric-sql/pglite';
import {readFile, readdir} from 'node:fs/promises';
import assert from 'node:assert/strict';

const db = await PGlite.create();
let checks = 0;
const eq = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
const uuid = n => '00000000-0000-0000-0000-' + String(n).padStart(12, '0');
const q = v => v === null ? 'null' : "'" + String(v).replaceAll("'", "''") + "'";
const [customer, superadmin, owner, owner2, riderUser, stranger] = [1, 2, 3, 4, 5, 6].map(uuid);

// Test-only Supabase stand-ins (auth schema, roles, auth.uid()).
await db.exec("create role anon; create role authenticated; create role service_role; create schema auth; create table auth.users(id uuid primary key, raw_user_meta_data jsonb); create function auth.uid() returns uuid language sql stable as $$select nullif(current_setting('request.jwt.claim.sub', true), '')::uuid$$; grant usage on schema auth to anon, authenticated, service_role; grant execute on function auth.uid() to anon, authenticated, service_role; grant usage on schema public to anon, authenticated, service_role;");
for (const name of (await readdir('supabase/migrations')).filter(f => f.endsWith('.sql')).sort()) await db.exec(await readFile('supabase/migrations/' + name, 'utf8'));
await db.exec(await readFile('supabase/seed.sql', 'utf8'));
await db.exec(await readFile('supabase/seed.sql', 'utf8')); // seed is idempotent
// Mirror Supabase: the secret-key role reads every public table and bypasses RLS.
await db.exec('grant select on all tables in schema public to service_role; alter role service_role bypassrls;');
await db.exec('insert into auth.users(id) values ' + [customer, superadmin, owner, owner2, riderUser, stranger].map(id => '(' + q(id) + ')').join(','));
await db.exec(`insert into private.user_roles(user_id, role) values (${q(superadmin)}, 'super_admin')`);

const as = async (user, role = 'authenticated') => db.exec(`reset role; set role ${role}; select set_config('request.jwt.claim.sub', ${q(user || '')}, false)`);
const asOwner = () => db.exec('reset role');
const one = async sql => (await db.query(sql)).rows[0];
const count = async (table, where = 'true') => Number((await one(`select count(*) n from ${table} where ${where}`)).n);
const rejects = async (sql, code, label) => { await assert.rejects(db.exec(sql), e => e.code === code, label + ' (expected ' + code + ')'); checks++; };
const submit = (name, email) => `select public.submit_station_registration(${[name, 'Bia-an', 'Purok 1, Bia-an', '+639171112233', '', 'Elena Ramos', email, '+639181112233', 'Owner'].map(q).join(',')}) as id`;

// Seeded pilot stations are public.
await as(null, 'anon');
eq((await db.query('select slug from public.stations order by slug')).rows.map(r => r.slug), ['guintas', 'poblacion', 'riverside'], 'three seeded stations visible to visitors');
eq((await one("select closed_notice from public.stations where slug = 'riverside'")).closed_notice, 'Under maintenance', 'maintenance notice is public');

// Anyone can apply; nobody but Super Admin can read applications.
const {id: application} = await one(submit('Bia-an Fresh Water', 'Elena@Example.com'));
eq(typeof application, 'string', 'visitor submits a registration');
await rejects(submit('Bia-an Fresh Water 2', 'elena@example.com'), '23505', 'second pending registration with the same email refused');
await rejects(`select public.submit_station_registration('X', 'Bia-an', 'Purok 1', '0917', '', 'E', 'bad', '+639181112233', 'Owner')`, '23514', 'invalid phone and email refused by checks');
await rejects('select * from public.station_registrations', '42501', 'visitors cannot read applications');
await as(customer);
eq(await count('public.station_registrations'), 0, 'customers cannot read applications');
await rejects(`select public.review_station_registration(${q(application)}, 'approve')`, '42501', 'customers cannot approve');
await rejects(`select public.provision_station_member(${q(customer)}, (select id from public.stations where slug = 'guintas'), 'station_admin')`, '42501', 'customers cannot provision themselves');
await rejects(`select public.provision_platform_admin(${q(customer)})`, '42501', 'customers cannot become Super Admin');
eq((await one('select public.my_access() as a')).a, {roles: ['customer'], stations: []}, 'my_access reports only the caller (signup gives everyone customer)');

// Super Admin approves: the station is created and becomes public.
await as(superadmin);
eq(await count('public.station_registrations', "status = 'pending'"), 1, 'Super Admin sees the pending application');
await rejects(`select public.review_station_registration(${q(application)}, 'reject', '  ')`, '22023', 'rejecting needs a reason');
const approved = (await one(`select public.review_station_registration(${q(application)}, 'approve') as r`)).r;
eq([approved.status, approved.owner_email, approved.owner_name], ['approved', 'elena@example.com', 'Elena Ramos'], 'approval returns the owner to invite');
await rejects(`select public.review_station_registration(${q(application)}, 'approve')`, '55000', 'an application is reviewed only once');
eq((await one(`select slug, registration_status, barangay from public.stations where id = ${q(approved.station_id)}`)), {slug: 'bia-an-fresh-water', registration_status: 'approved', barangay: 'Bia-an'}, 'station row created from the application');
const {id: second} = await one(submit('Bia-an Fresh Water', 'other@example.com'));
const sameName = (await one(`select public.review_station_registration(${q(second)}, 'approve') as r`)).r;
eq((await one(`select slug from public.stations where id = ${q(sameName.station_id)}`)).slug.startsWith('bia-an-fresh-water-'), true, 'duplicate station names get a unique slug');
const {id: third} = await one(submit('Spam Water', 'spam@example.com'));
eq((await one(`select public.review_station_registration(${q(third)}, 'reject', 'Outside Hamtic') as r`)).r, {status: 'rejected'}, 'rejection recorded');
await as(null, 'anon');
eq(await count('public.stations', `id = ${q(approved.station_id)}`), 1, 'approved station appears for visitors');
eq(await count('public.stations', "name = 'Spam Water'"), 0, 'rejected application creates no station');

// The server (secret key) links the invited owner to the new station.
await as(null, 'service_role');
await db.exec(`select public.provision_station_member(${q(owner)}, ${q(approved.station_id)}, 'station_admin')`);
await db.exec(`select public.provision_station_member(${q(owner2)}, (select id from public.stations where slug = 'guintas'), 'station_admin')`);
await rejects(`select public.provision_station_member(${q(superadmin)}, ${q(approved.station_id)}, 'station_admin')`, '42501', 'Super Admin cannot join a station');
await rejects(`select public.provision_station_member(${q(stranger)}, ${q(approved.station_id)}, 'super_admin')`, '22023', 'only station roles can be provisioned');
await as(owner);
eq((await one('select public.my_access() as a')).a, {roles: ['customer', 'station_admin'], stations: [{id: approved.station_id, name: 'Bia-an Fresh Water', slug: 'bia-an-fresh-water', status: 'approved'}]}, 'owner logs in to their own station');

// Drivers: only the station's admin may add them, checked inside the database.
await as(null, 'service_role');
const driver = (user, station, by, name = 'Juan Reyes') => `select public.register_driver(${q(user)}, ${station}, ${q(name)}, '+639175550101', 'juan@example.com', 'Tricycle', 'abc 123', 15, ${q(by)})`;
await rejects(driver(riderUser, q(approved.station_id), owner2), '42501', 'an admin of another station cannot add drivers here');
await rejects(driver(riderUser, q(approved.station_id), customer), '42501', 'a customer cannot add drivers');
await db.exec(driver(riderUser, q(approved.station_id), owner));
await rejects(driver(riderUser, q(approved.station_id), owner), '23505', 'the same person cannot be added twice');
await asOwner();
eq((await one(`select plate_number, email from public.drivers where user_id = ${q(riderUser)}`)), {plate_number: 'ABC 123', email: 'juan@example.com'}, 'plate and email normalized');
await as(riderUser);
eq((await one('select public.my_access() as a')).a.roles, ['customer', 'rider'], 'driver account has the rider role');
eq(await count('public.drivers'), 1, 'driver sees own record');
await as(owner);
eq(await count('public.drivers'), 1, 'owner sees their station drivers');
await as(owner2);
eq(await count('public.drivers'), 0, 'other station admin sees none of them');
await as(customer);
eq(await count('public.drivers'), 0, 'customers see no drivers');
await as(superadmin);
eq(await count('public.drivers'), 0, 'Super Admin sees no full driver rows');
eq((await one("select public.get_driver_registration_counts('{}'::jsonb) r")).r.data.unique_driver_count, 1, 'Super Admin receives directory registration counts');
await as(null, 'anon');
await rejects('select * from public.drivers', '42501', 'visitors cannot read drivers');
await as(owner);
await rejects(driver(stranger, q(approved.station_id), owner), '42501', 'register_driver is server-only');
await rejects(`insert into public.drivers(user_id, station_id, full_name, phone, email, vehicle, plate_number, capacity) values (${q(stranger)}, ${q(approved.station_id)}, 'X Y', '+639175550101', 'x@example.com', 'Tricycle', 'AB1', 5)`, '42501', 'drivers cannot be inserted directly');

console.log(checks + ' registry PostgreSQL/RLS assertions passed.');
await db.close();
