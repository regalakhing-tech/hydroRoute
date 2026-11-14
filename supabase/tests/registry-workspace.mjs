// Super Admin registry read: only the fields the registry pages show, and the admin's own profile.
// Run: node supabase/tests/registry-workspace.mjs
import {world} from './support/world.mjs';
const t = await world();
const {ids, eq, ok, rpc, denied} = t;

for (const user of [ids.customer, ids.admin, ids.rider]) await denied(user, 'get_registry_workspace');
const w = (await rpc(ids.superadmin, 'get_registry_workspace')).data;
ok(w.stations.length >= 2 && w.stations.every(s => Object.keys(s).sort().join() === 'availability,id'), 'Stations carry only the id and public availability');
ok(w.drivers.length >= 1, 'Drivers are listed');
eq(Object.keys(w.drivers[0]).sort(), ['active', 'created_at', 'name', 'phone', 'station', 'vehicle'], 'Driver rows hold only the shown fields (no email, plate or account id)');
ok(w.activity.some(a => a.type === 'Driver' && a.event === 'Driver registration added'), 'Driver registrations appear as activity');
ok(!JSON.stringify(w).includes('@'), 'No email address leaks into the registry read');
eq(w.me.version, 1, 'Profile version starts at 1');

for (const user of [ids.customer, ids.admin]) await denied(user, 'save_registry_profile', {display_name: 'X Y', phone: '+639171234567'}, 1, t.key());
eq((await rpc(ids.superadmin, 'save_registry_profile', {display_name: 'A', phone: '+639171234567'}, 1, t.key())).error_code, 'validation', 'Short name rejected');
eq((await rpc(ids.superadmin, 'save_registry_profile', {display_name: 'Registry Admin', phone: '0917'}, 1, t.key())).error_code, 'validation', 'Bad phone rejected');
const key = t.key();
eq((await rpc(ids.superadmin, 'save_registry_profile', {display_name: 'Registry Admin', phone: '+639171234567'}, 1, key)).data.version, 2, 'Profile saved');
eq((await rpc(ids.superadmin, 'save_registry_profile', {display_name: 'Registry Admin', phone: '+639171234567'}, 1, key)).data.version, 2, 'Same request key replays');
eq((await rpc(ids.superadmin, 'save_registry_profile', {display_name: 'Other', phone: '+639171234567'}, 1, t.key())).error_code, 'stale_version', 'Old version rejected');
eq((await rpc(ids.superadmin, 'get_registry_workspace')).data.me, {display_name: 'Registry Admin', phone: '+639171234567', version: 2}, 'Saved profile is read back');
eq((await t.sql(`select raw_user_meta_data->>'full_name' n from auth.users where id = $1`, [ids.superadmin]))[0].n, 'Registry Admin', 'Login name follows the profile');
console.log(t.count() + ' registry workspace checks passed.');
await t.db.close();
