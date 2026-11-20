import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { JSDOM } from 'jsdom';

const official = JSON.parse(readFileSync('supabase/reference/hamtic-barangays.json', 'utf8'));
const productId = '11111111-1111-4111-8111-111111111111';
const closureId = '22222222-2222-4222-8222-222222222222';
const setup = {
  station: { id: '33333333-3333-4333-8333-333333333333', name: '<script>River</script>', contact_name: 'Real owner', phone: '09123456789', email: 'real@example.test', address: 'Real address', description: 'Real description', barangay_id: 'b0', latitude: null, longitude: null, profile_version: 12, location_version: 13 },
  settings: { version: 14, max_order_units: 17, max_distance_km: 9, booking_days: 6, duplicate_minutes: 22, overdue_minutes: 90, institution_bonus_enabled: false, urgency_asap: 40, urgency_soon: 25, urgency_later: 10, urgency_immediate_minutes: 60, urgency_soon_minutes: 180, aging_interval_minutes: 10, aging_points: 5, aging_cap: 25, institution_bonus: 10 },
  barangays: official.barangays.map((b, i) => ({ ...b, id: `b${i}` })),
  service_areas: [{ barangay_id: 'b0', served: true }, { barangay_id: 'b1', served: false }],
  products: [{ id: productId, name: 'Real <jug>', price_centavos: 1234, pack_text: '1 jug', description: 'Water & refill', image_key: 'water-jug', returnable: true, load_units: 1, active: true, version: 15 }],
  fee_rules: { version: 7, max_distance_km: 9, bands: [{ min_km: 0, max_km: 1, min_inclusive: true, max_inclusive: false, fee_centavos: 550 }, { min_km: 1, max_km: null, min_inclusive: true, max_inclusive: false, fee_centavos: 1234 }] },
  opening_hours: [{ weekday: 1, window_no: 1, opens_at: '08:00:00', closes_at: '12:00:00', order_cutoff: null, delivery_cutoff: '11:30:00', enabled: true }],
  closures: [{ id: closureId, starts_at: '2026-11-20T08:00:00+08:00', ends_at: '2026-11-20T12:00:00+08:00', mode: 'closed', public_notice: '<img src=x onerror=alert(1)>', is_manual: false, cancelled_at: null, version: 16 }],
  availability: { version: 18, manual_mode: null, label: 'Real availability', preview: [{ date: '2026-11-20', windows: [{ opens_at: '12:00:00', closes_at: '18:00:00', order_cutoff: null, delivery_cutoff: null }], notice: 'Real preview' }] },
};
function render(page, options = {}) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['tests/support/render-station-setup.php'], { input: JSON.stringify({ page, data: { preview: false, setup, ...options.data }, query: options.query }), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return new JSDOM(result.stdout).window.document;
}
function form(document, action, version, name) {
  const node = document.querySelector(`form[action="/station-admin/${action}"]`);
  assert.ok(node, `Missing live ${action} form`);
  assert.equal(node.method, 'post');
  assert.equal(node.querySelector('[name="_token"]').value, 'fixture-csrf-token');
  assert.match(node.querySelector('[name="request_key"]').value, /^[a-f0-9-]{36}$/);
  assert.equal(node.querySelector('[name="expected_version"]').value, String(version));
  assert.equal(node.querySelector('[name="_form"]').value, name);
  assert.equal(node.querySelector('[name="station_id"]'), null);
  assert.equal(node.hasAttribute('data-local-form'), false);
  return node;
}
let checks = 0;
const previews = {
  'settings-profile': 'ef00da0943a6511907f1966a31ff46bbdf61e232309b97a71137cf7d4d7f52c1',
  'settings-service-areas': 'a3d492b2ad87f61a4d910dce6295becb08d4eac4f3c9d2e5c0f0c482b60b1181',
  'settings-order-rules': 'a7be6a584aa6906e66cb1688a5e2dd83655c6cd20a0b021c03d01bf2d579a603',
  products: '9f79dd1fcfb96e7b33dc8f6640c130e45635ae857c0dfbe3b6061e3c2f6504e6',
  schedules: '87306da7f0413815864a42ccf15975fde9562ef037b00a1dbbe8c74b9ff539e9',
};
for (const [page, expectedHash] of Object.entries(previews)) {
  const source = readFileSync(`app/Views/station-admin/${page}.php`, 'utf8').replace(/\r\n/g, '\n');
  const original = source.slice(source.indexOf('\n') + 1);
  assert.equal(createHash('sha256').update(original).digest('hex'), expectedHash, `${page} original preview branch changed`);
  const preview = render(page, { data: { preview: true } });
  assert.ok(preview.querySelector('[data-local-form]'));
  assert.equal(preview.querySelector('form[method="post"]'), null); checks++;
}
const profile = render('settings-profile');
form(profile, 'settings/profile', 12, 'profile'); checks++;
form(profile, 'settings/location', 13, 'location'); checks++;
assert.equal(profile.querySelector('[name="name"]').value, '<script>River</script>');
assert.equal(profile.querySelector('script'), null);
assert.equal(profile.querySelector('[name="latitude"]').value, '');
assert.ok(profile.querySelector('[data-station-map]'));
assert.ok(profile.querySelector('[data-device-location]')); checks++;
const areas = render('settings-service-areas');
form(areas, 'settings/service-areas', 14, 'serviceAreas');
assert.equal(areas.querySelectorAll('[name="barangay_ids[]"]').length, 47);
assert.equal(areas.querySelectorAll('[name="barangay_ids[]"]:checked').length, 1); checks++;
const rules = render('settings-order-rules');
form(rules, 'settings/order-rules', 14, 'settings');
form(rules, 'settings/delivery-fees', 14, 'fees');
assert.equal(rules.querySelector('[name="max_order_units"]').value, '17');
assert.equal(rules.querySelector('[name="bands[0][fee]"]').value, '5.50'); checks++;
const products = render('products', { query: { product: productId } });
const editor = form(products, 'products/save', 15, 'product');
assert.equal(editor.querySelector('[name="product_id"]').value, productId);
assert.equal(editor.querySelector('[name="price"]').value, '12.34');
for (const name of ['pack_text', 'description', 'image_key', 'returnable', 'active', 'load_units']) assert.ok(editor.querySelector(`[name="${name}"]`)); checks++;
const add = render('products', { query: { product: 'new' } });
assert.equal(add.querySelector('#product-editor [name="product_id"]').value, ''); checks++;
const unknownProduct = render('products', { query: { product: 'another-station-product' } });
assert.equal(unknownProduct.querySelector('#product-editor'), null);
assert.match(unknownProduct.body.textContent, /Product unavailable/); checks++;
const schedules = render('schedules');
form(schedules, 'schedules/hours', 18, 'hours');
form(schedules, 'schedules/closure', 18, 'closure');
form(schedules, 'schedules/closure/cancel', 16, 'cancelClosure');
form(schedules, 'schedules/reopen', 18, 'reopen');
assert.deepEqual([...schedules.querySelector('[name="mode"]').options].map(option => option.value), ['closed', 'maintenance', 'no_operations']);
assert.equal(schedules.querySelector('[name="starts_at"]').required, true, 'Manual and dated closures require an explicit stable start time');
assert.equal(schedules.querySelectorAll('input[name$="[weekday]"]').length, 14);
assert.match(schedules.body.textContent, /Real preview/);
assert.equal(schedules.querySelector('img'), null); checks++;
const multipleClosures = render('schedules', { data: { setup: { ...setup, closures: [...setup.closures, { ...setup.closures[0], id: '44444444-4444-4444-8444-444444444444' }] } } });
const identifiers = [...multipleClosures.querySelectorAll('[id]')].map(node => node.id);
assert.equal(new Set(identifiers).size, identifiers.length, 'Multiple closure forms need unique labels'); checks++;
const old = render('settings-profile', { data: { setup_old: { _form: 'profile', name: 'Retry & name', request_key: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' }, setup_errors: { name: '<bad>' } } });
assert.equal(old.querySelector('[name="name"]').value, 'Retry & name');
assert.equal(old.querySelector('[name="request_key"]').value, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
assert.match(old.body.textContent, /<bad>/); checks++;
const oldProduct = render('products', { data: { setup_old: { _form: 'product', product_id: productId, name: 'Retry product', price: '0.15', image_key: 'water-bottle' } } });
assert.equal(oldProduct.querySelector('#product-editor [name="name"]').value, 'Retry product');
assert.equal(oldProduct.querySelector('#product-editor [name="price"]').value, '0.15');
assert.equal(oldProduct.querySelector('#product-editor [name="active"]').checked, false); checks++;
const retryClosure = render('schedules', { data: { setup_old: { _form: 'closure', closure_id: closureId, closure_version: '9', expected_version: '10', starts_at: '2026-11-20T08:00', ends_at: '2026-11-20T12:00', mode: 'closed', public_notice: 'Retry closure', request_key: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' } } });
assert.equal(retryClosure.querySelector('#closure-editor [name="closure_version"]').value, '9', 'A retried closure must preserve its original version and payload');
assert.equal(retryClosure.querySelector('#closure-editor [name="expected_version"]').value, '10'); checks++;
const cancelledClosure = { ...setup.closures[0], cancelled_at: '2026-11-19T12:00:00+08:00', version: 17 };
const cancellationInput = { _form: 'cancelClosure', closure_id: closureId, expected_version: '16', request_key: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', reason: 'Original cancellation & reason' };
const retryCancellation = render('schedules', { data: { setup: { ...setup, closures: [cancelledClosure] }, setup_old: cancellationInput } });
const cancellationForm = form(retryCancellation, 'schedules/closure/cancel', 16, 'cancelClosure');
assert.equal(cancellationForm.querySelector('[name="closure_id"]').value, closureId);
assert.equal(cancellationForm.querySelector('[name="request_key"]').value, cancellationInput.request_key);
assert.equal(cancellationForm.querySelector('[name="reason"]').value, cancellationInput.reason);
assert.match(cancellationForm.textContent, /Retry cancellation/); checks++;
const closureInput = { _form: 'closure', closure_id: closureId, closure_version: '16', expected_version: '18', starts_at: '2026-11-19T08:00', ends_at: '', is_manual: '1', mode: 'maintenance', public_notice: 'Original manual notice', request_key: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' };
for (const savedClosure of [{ ...setup.closures[0], is_manual: true, version: 17 }, cancelledClosure]) {
  const retryChangedClosure = render('schedules', { data: { setup: { ...setup, closures: [savedClosure] }, setup_old: closureInput } });
  const closureForm = form(retryChangedClosure, 'schedules/closure', 18, 'closure');
  for (const name of ['closure_id', 'closure_version', 'request_key', 'starts_at', 'ends_at', 'mode', 'public_notice']) assert.equal(closureForm.querySelector(`[name="${name}"]`).value, closureInput[name]);
  assert.equal(closureForm.querySelector('[name="is_manual"]').checked, true);
  assert.match(closureForm.textContent, /Retry closure save/); checks++;
}
const cleanCancelled = render('schedules', { query: { closure: closureId }, data: { setup: { ...setup, closures: [cancelledClosure] } } });
assert.equal(cleanCancelled.querySelector('form[action="/station-admin/schedules/closure/cancel"]'), null);
assert.equal(cleanCancelled.querySelector('#closure-editor [name="closure_id"]'), null); checks++;
const cleanManual = render('schedules', { query: { closure: closureId }, data: { setup: { ...setup, closures: [{ ...setup.closures[0], is_manual: true }] } } });
assert.equal(cleanManual.querySelector('#closure-editor [name="closure_id"]'), null); checks++;
const unrelatedRetry = render('schedules', { data: { setup: { ...setup, closures: [cancelledClosure] }, setup_old: { ...cancellationInput, closure_id: '44444444-4444-4444-8444-444444444444' } } });
assert.equal(unrelatedRetry.querySelector('form[action="/station-admin/schedules/closure/cancel"]'), null); checks++;
for (const page of ['settings-profile', 'settings-service-areas', 'settings-order-rules', 'products', 'schedules']) {
  const failure = render(page, { data: { setup: null, setup_error: 'Unavailable <safe>' } });
  assert.equal(failure.querySelector('form'), null);
  assert.match(failure.body.textContent, /Unavailable <safe>/);
  assert.doesNotMatch(failure.body.textContent, /Elena|Guintas|November 2|Sample configuration/); checks++;
}
for (const page of ['settings-service-areas', 'products', 'schedules']) {
  const empty = render(page, { data: { setup: { ...setup, barangays: [], service_areas: [], products: [], opening_hours: [], closures: [], availability: { version: 18, preview: [] } } } });
  assert.doesNotMatch(empty.body.textContent, /Elena|Guintas|Nov 4, 2026/); checks++;
}
assert.ok(existsSync('public/assets/js/station-setup.js'), 'Missing station setup progressive enhancement');
const script = readFileSync('public/assets/js/station-setup.js', 'utf8');
// No browser storage; the only network calls are the barangay outline file and the station reverse lookup named by the form.
assert.doesNotMatch(script, /localStorage|fetch\((?!locationForm\.dataset\.(boundaries|reverse))/);
const enhanced = new JSDOM(profile.documentElement.outerHTML, { runScripts: 'outside-only' });
const { window } = enhanced;
const events = {};
let tiles;
let markerCount = 0;
window.L = {
  map: () => ({ setView() { return this; }, on(name, handler) { events[name] = handler; return this; }, panTo() {} }),
  tileLayer: (url, options) => { tiles = { url, options }; return { addTo() {} }; },
  circleMarker: () => { markerCount++; return { addTo() { return this; }, setLatLng() {} }; },
};
window.eval(script);
assert.equal(tiles.url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
assert.match(tiles.options.attribution, /OpenStreetMap/);
assert.equal(markerCount, 0, 'Missing station coordinates must not invent a saved pin');
events.click({ latlng: { lat: 10.65, lng: 122.01 } });
assert.equal(window.document.querySelector('[name="latitude"]').value, '10.650000');
assert.equal(markerCount, 1);
events.click({ latlng: { lat: 0, lng: 0 } });
assert.match(window.document.querySelector('[data-location-status]').textContent, /within/); checks++;
const noMap = new JSDOM(profile.documentElement.outerHTML, { runScripts: 'outside-only' });
noMap.window.eval(script);
assert.match(noMap.window.document.querySelector('[data-location-status]').textContent, /map is unavailable/);
assert.equal(noMap.window.document.querySelector('[name="latitude"]').disabled, false);
assert.equal(noMap.window.document.querySelector('[data-device-location]').disabled, true); checks++;
const deniedGPS = new JSDOM(profile.documentElement.outerHTML, { runScripts: 'outside-only' });
Object.defineProperty(deniedGPS.window.navigator, 'geolocation', { value: { watchPosition(success, error) { error({ code: 1 }); return 1; }, clearWatch() {} } });
deniedGPS.window.eval(readFileSync('public/assets/js/locate.js', 'utf8'));
deniedGPS.window.eval(script);
deniedGPS.window.document.querySelector('[data-device-location]').click();
assert.match(deniedGPS.window.document.querySelector('[data-location-status]').textContent, /permission is off/);
// Barangay detection: a map pin fills the profile's barangay from the OpenStreetMap name (ALAB-style matching).
{
  const detect = new JSDOM(profile.documentElement.outerHTML, { runScripts: 'outside-only' });
  const w = detect.window, handlers = {};
  w.L = { map: () => ({ setView() { return this; }, on(name, handler) { handlers[name] = handler; return this; }, panTo() {} }),
    tileLayer: () => ({ addTo() {} }), circleMarker: () => ({ addTo() { return this; }, setLatLng() {} }), circle: () => ({ addTo() { return this; }, remove() {} }) };
  w.fetch = async url => ({ json: async () => String(url).includes('barangays') ? { features: [] } : { ok: true, data: { street: 'Rizal Street', areas: ['Barangay Guintas', 'Hamtic'] } } });
  w.eval(readFileSync('public/assets/js/locate.js', 'utf8'));
  w.eval(script);
  const select = w.document.querySelector('form[action="/station-admin/settings/profile"] [name="barangay_id"]');
  const guintas = [...select.options].find(o => o.textContent.trim() === 'Guintas');
  select.value = '';
  handlers.click({ latlng: { lat: 10.65, lng: 122.01 } });
  await new Promise(r => setTimeout(r, 20));
  assert.equal(select.value, guintas.value, 'Pin fills the profile barangay');
  assert.match(w.document.querySelector('[data-barangay-status]').textContent, /Barangay Guintas detected from OpenStreetMap\. Save profile/); checks++;
}
assert.equal(deniedGPS.window.document.querySelector('[data-device-location]').disabled, false); checks++;
const feeDOM = new JSDOM(rules.documentElement.outerHTML, { runScripts: 'outside-only' });
feeDOM.window.eval(script);
feeDOM.window.document.querySelector('[data-add-fee-band]').click();
assert.equal(feeDOM.window.document.querySelectorAll('[data-fee-band]').length, 3);
assert.ok(feeDOM.window.document.querySelector('[name="bands[2][fee]"]'));
feeDOM.window.document.querySelector('[data-remove-fee-band]').click();
assert.equal(feeDOM.window.document.querySelectorAll('[data-fee-band]').length, 2); checks++;
console.log(`station setup DOM: ${checks} checks passed`);
