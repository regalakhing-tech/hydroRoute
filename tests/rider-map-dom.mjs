// Rider road map (live.js): without GPS it asks for the route from the station, shows distance, time and directions,
// and explains a missing route. Leaflet is absent here, so the map canvas hides and the text still works.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const html = `<section data-route-map data-route-url="/rider/trips/route"><span data-route-summary>Finding the road route…</span>
 <div data-route-canvas></div><ol data-route-steps></ol></section>`;
const run = async answer => {
    const d = new JSDOM(html, {url: 'http://localhost/rider', runScripts: 'outside-only'});
    const w = d.window, asked = [];
    w.fetch = async url => { asked.push(String(url)); return {status: 200, json: async () => answer}; };
    w.eval(readFileSync('public/assets/js/live.js', 'utf8'));
    await new Promise(r => setTimeout(r, 20));
    return {w, doc: w.document, asked};
};

let p = await run({ok: true, data: {distance_m: 2412, duration_s: 431, from_station: true, to_station: false, label: 'Maria', from: [10.6, 122], to: [10.61, 122.002],
    path: [[10.6, 122], [10.61, 122.002]], steps: [{text: 'Start on Rizal Street', distance_m: 120}, {text: 'Turn left onto Bonifacio Road', distance_m: 1500}, {text: 'Arrive at the delivery point', distance_m: 0}]}});
assert.deepEqual(p.asked, ['/rider/trips/route'], 'Without GPS the route is asked once, with no coordinates');
assert.equal(p.doc.querySelector('[data-route-summary]').textContent, '2.4 km · about 7 min by road from the station');
assert.deepEqual([...p.doc.querySelectorAll('[data-route-steps] li')].map(li => li.textContent),
    ['Start on Rizal Street · 120 m', 'Turn left onto Bonifacio Road · 1.5 km', 'Arrive at the delivery point']);
assert.equal(p.doc.querySelector('[data-route-canvas]').hidden, true, 'No Leaflet: the empty map box hides');
p.w.close();

p = await run({ok: false, error_code: 'no_pin'});
assert.match(p.doc.querySelector('[data-route-summary]').textContent, /no map pin/);
p.w.close();
p = await run({ok: false, error_code: 'something_new'});
assert.match(p.doc.querySelector('[data-route-summary]').textContent, /unavailable right now/);
p.w.close();
console.log('Rider road map DOM: 6 checks passed.');

// Distance travelled on the trip (plan M11): sums accurate fixes, ignores jitter and bad jumps, keeps the total per trip.
{
    const d = new JSDOM(`<div data-rider-track="trip-1" data-token="t" hidden></div><span data-location-status></span><span data-travelled></span>`, {url: 'http://localhost/rider', runScripts: 'outside-only'});
    const w = d.window, fixes = [];
    Object.defineProperty(w.navigator, 'geolocation', {value: {watchPosition(ok) { fixes.push(ok); return 1; }, clearWatch() {}}});
    w.fetch = async () => ({status: 200, json: async () => ({ok: true})});
    w.eval(readFileSync('public/assets/js/live.js', 'utf8'));
    const at = (lat, lon, accuracy = 10) => fixes[0]({coords: {latitude: lat, longitude: lon, accuracy}});
    const km = () => w.document.querySelector('[data-travelled]').textContent;
    assert.equal(km(), '0.0 km travelled');
    at(10.6000, 122.0000); at(10.6090, 122.0000);          // about 1.0 km north
    assert.equal(km(), '1.0 km travelled');
    at(10.60905, 122.0000);                                 // 5 m of jitter
    at(10.6200, 122.0000, 500);                             // inaccurate fix
    at(11.2000, 122.0000);                                  // impossible 65 km jump
    assert.equal(km(), '1.0 km travelled', 'Jitter, inaccurate fixes and jumps are ignored');
    assert.equal(JSON.parse(w.localStorage.getItem('hamtic-trip-km:trip-1')).km.toFixed(1), '1.0', 'Total kept for this trip on this phone');
    w.close();
}
console.log('Rider travelled distance DOM: 4 checks passed.');
