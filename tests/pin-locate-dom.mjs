// Customer address pin (live.js), ALAB-style detection: auto-start for a first address, keep the best fix (inside Hamtic
// first, then the smallest accuracy), stop early at 20 m, grade the result and fill the street and a nearby landmark.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const form = auto => `<form data-pin-picker data-boundaries="/b.json" data-reverse="/reverse" data-auto-locate="${auto}">
 <div data-pin-map></div><div data-pin-overlay hidden><span data-pin-overlay-label></span></div>
 <p data-pin-status></p><span data-pin-quality hidden></span>
 <button type="button" data-pin-device><span data-pin-device-label>Detect my location</span></button>
 <input name="latitude" value=""><input name="longitude" value=""><input type="hidden" name="pin_source" value="">
 <select name="barangay_id"><option value="">Choose</option><option value="b-guintas" data-psgc="1">Guintas</option><option value="b-pob2" data-psgc="2">Poblacion 2</option></select><input name="street" value=""><textarea name="landmark"></textarea></form>`;

const setup = async (auto = '0') => {
    const d = new JSDOM(form(auto), {url: 'http://localhost/customer/addresses', runScripts: 'outside-only'});
    const w = d.window, geo = {watchers: [], cleared: 0};
    Object.defineProperty(w.navigator, 'geolocation', {value: {
        watchPosition(ok, fail, options) { geo.watchers.push({ok, fail, options}); return geo.watchers.length; },
        clearWatch() { geo.cleared++; },
    }});
    w.fetch = async url => ({status: 200, json: async () => String(url).startsWith('/b.json') ? {features: []}
        : {ok: true, data: {street: 'Rizal Street', place: '', areas: ['Rizal Street', 'Brgy. Poblacion II', 'Hamtic', 'Antique'], landmark: 'Hamtic Central School'}}});
    w.eval(readFileSync('public/assets/js/locate.js', 'utf8'));
    w.eval(readFileSync('public/assets/js/live.js', 'utf8'));
    await new Promise(r => setTimeout(r, 10));
    const fix = (latitude, longitude, accuracy) => geo.watchers.at(-1).ok({coords: {latitude, longitude, accuracy}, timestamp: Date.now()});
    return {w, doc: w.document, geo, fix, q: s => w.document.querySelector(s)};
};

// Auto-start for a first address; options ask for a fresh high-accuracy fix.
let p = await setup('1');
assert.equal(p.geo.watchers.length, 1, 'First address starts detecting by itself');
assert.equal(JSON.stringify(p.geo.watchers[0].options), JSON.stringify({enableHighAccuracy: true, maximumAge: 0, timeout: 15000}));
assert.equal(p.q('[data-pin-quality]').textContent, 'Locating…');
assert.equal(p.q('[data-pin-overlay]').hidden, false);
assert.equal(p.q('[data-pin-device]').disabled, true);
// A sharper fix outside Hamtic never beats a fix inside Hamtic.
p.fix(10.69, 122.03, 80);
p.fix(14.6, 121.0, 5);
assert.match(p.q('[data-pin-status]').textContent, /±80 m/);
// A fix within 20 m inside Hamtic finishes at once and is graded precise.
p.fix(10.6912, 122.0311, 12);
await new Promise(r => setTimeout(r, 10));
assert.equal(p.geo.cleared, 1, 'Watch stopped after a 12 m fix');
assert.equal(p.q('[name="latitude"]').value, '10.6912000');
assert.equal(p.q('[name="pin_source"]').value, 'device');
assert.equal(p.q('[data-pin-quality]').textContent, 'Precise');
assert.equal(p.q('[data-pin-overlay]').hidden, true);
assert.equal(p.q('[data-pin-device-label]').textContent, 'Detect my location again');
assert.equal(p.q('[name="street"]').value, 'Rizal Street', 'Street filled from the pin');
assert.equal(p.q('[name="landmark"]').value, 'Near Hamtic Central School', 'Nearby landmark filled');
assert.equal(p.q('[name="barangay_id"]').value, 'b-pob2', '"Brgy. Poblacion II" from OpenStreetMap matches the official "Poblacion 2"');
assert.match(p.q('[data-pin-status]').textContent, /Barangay Poblacion 2/);
p.w.close();

// Not a first address: waits for the button. A rough fix at the deadline is kept and graded.
p = await setup('0');
assert.equal(p.geo.watchers.length, 0, 'Saved addresses do not start detection by themselves');
p.q('[name="landmark"]').value = 'Blue gate';
p.q('[name="landmark"]').dispatchEvent(new p.w.Event('input'));
p.q('[data-pin-device]').click();
p.fix(10.70, 122.04, 120);
p.geo.watchers[0].fail({code: 3});
await new Promise(r => setTimeout(r, 10));
assert.equal(p.q('[data-pin-quality]').textContent, 'Approximate', '120 m is approximate');
assert.match(p.q('[data-pin-status]').textContent, /drag the pin/);
assert.equal(p.q('[name="landmark"]').value, 'Blue gate', 'A typed landmark is never replaced');
p.w.close();

// Permission refused: clear message, no badge.
p = await setup('0');
p.q('[data-pin-device]').click();
p.geo.watchers[0].fail({code: 1});
assert.match(p.q('[data-pin-status]').textContent, /permission is off/);
assert.equal(p.q('[data-pin-quality]').hidden, true);
assert.equal(p.q('[data-pin-device]').disabled, false);
p.w.close();

// Only a point outside Hamtic: shown as outside, nothing saved.
p = await setup('0');
p.q('[data-pin-device]').click();
p.fix(14.6, 121.0, 30);
p.geo.watchers[0].fail({code: 3});
assert.equal(p.q('[data-pin-quality]').textContent, 'Outside Hamtic');
assert.equal(p.q('[name="latitude"]').value, '');
p.w.close();
console.log('Address pin location DOM: 23 checks passed.');
