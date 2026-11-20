// HydroLocate (locate.js): ALAB-style barangay names and the bundled-outline fallback, against all 47 Hamtic barangays.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

globalThis.window = {};
(0, eval)(readFileSync('public/assets/js/locate.js', 'utf8'));
const H = window.HydroLocate;
const features = JSON.parse(readFileSync('public/assets/data/hamtic-barangays.json', 'utf8')).features;
const official = features.map(f => ({name: f.name, value: f.psgc}));
const name = names => H.match(names, official)?.name ?? null;

assert.equal(features.length, 47, 'All 47 Hamtic barangays are bundled');
assert.deepEqual(official.filter(o => H.match([o.name], official)?.value !== o.value), [], 'Every official name matches itself');
assert.equal(name(['Bongbongan Ⅰ-Ⅱ', 'Nalihawan', 'Hamtic']), 'Bongbongan I-II', 'Unicode roman numerals from OpenStreetMap');
assert.equal(name(['Brgy. Poblacion II']), 'Poblacion 2', 'Brgy. prefix and roman numeral');
assert.equal(name(['Pob. Tres']), 'Poblacion 3', 'Pob. and Spanish number');
assert.equal(name(['BARANGAY MAPATAG']), 'Mapatag', 'Case and Barangay word');
assert.equal(name(['Hamtic', 'Antique', 'Philippines']), null, 'Town and province are not barangays');
const road = H.match(['Mapatag Road'], official);
assert.deepEqual([road.name, road.exact], ['Mapatag', false], 'A road named after a barangay is only a hint');
// Outline fallback: a point at a barangay's own vertex is inside or on it; a far point gives nothing.
const f0 = features[0], [x0, y0] = f0.rings[0].reduce(([sx, sy], [x, y], _, all) => [sx + x / all.length, sy + y / all.length], [0, 0]);
assert.ok(H.outline(y0, x0, features), 'A point in Hamtic finds an outline');
assert.equal(H.outline(14.6, 121.0, features), null, 'Manila finds none');
assert.deepEqual([H.grade(12), H.grade(120), H.grade(900)], ['precise', 'approximate', 'low']);
console.log('Locate helpers: 12 checks passed.');
