// Background refresh (live.js): when the poll reports a new version, the page content is swapped in place without a
// reload; filters stay chosen, new rows get phone labels, and nothing is swapped while someone is typing.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM, VirtualConsole} from 'jsdom';

const page = (version, rows) => `<main id="main-content"><div data-poll-url="/station-admin/orders/updates" data-poll-version="${version}" data-poll-seconds="0.05" hidden></div>
 <input id="order-search" data-search="station-orders" value=""><select id="status" data-filter-target="station-orders"><option value="all">All</option><option>Ready</option><option>Preparing</option></select>
 <form method="post" action="/x"><textarea name="reason"></textarea></form>
 <table id="station-orders" class="data-table"><thead><tr><th>Order</th><th>Status</th></tr></thead><tbody>
 ${rows.map(([no, st]) => `<tr data-search-row data-filter-value="${st}"><td>${no}</td><td>${st}</td></tr>`).join('')}</tbody></table><p data-empty hidden></p></main>`;

let reloads = 0;
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', e => { if (/navigation/i.test(e.message)) reloads++; });
const d = new JSDOM(page('v1', [['HMT-1', 'Ready']]), {url: 'http://localhost/station-admin/orders', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole});
const w = d.window, doc = w.document;
let version = 'v1', rows = [['HMT-1', 'Ready']], fetched = 0;
w.fetch = async url => {
    if (String(url).includes('/updates')) return {status: 200, json: async () => ({ok: true, data: {version}})};
    fetched++;
    return {ok: true, redirected: false, status: 200, text: async () => `<!doctype html><html><body>${page(version, rows)}</body></html>`};
};
w.CSS ??= {}; w.CSS.escape ??= v => String(v);
w.eval(readFileSync('public/assets/js/app.js', 'utf8'));
w.eval(readFileSync('public/assets/js/live.js', 'utf8'));
const wait = ms => new Promise(r => setTimeout(r, ms));
const shown = () => [...doc.querySelectorAll('#station-orders tbody tr')].filter(r => !r.hidden).map(r => r.cells[0].textContent);

// Choose a filter, then a new order arrives.
const status = doc.querySelector('#status');
status.value = 'Ready'; status.dispatchEvent(new w.Event('change', {bubbles: true}));
version = 'v2'; rows = [['HMT-1', 'Ready'], ['HMT-2', 'Preparing'], ['HMT-3', 'Ready']];
await wait(250);
assert.equal(fetched, 1, 'The page was fetched once in the background');
assert.equal(reloads, 0, 'No full page reload');
assert.equal(doc.querySelector('[data-poll-url]').dataset.pollVersion, 'v2', 'The new version replaced the old one');
assert.equal(doc.querySelector('#status').value, 'Ready', 'The chosen filter is kept');
assert.deepEqual(shown(), ['HMT-1', 'HMT-3'], 'New rows arrive already filtered');
assert.equal(doc.querySelector('#station-orders tbody tr:last-child td').dataset.label, 'Order', 'New rows get phone card labels');

// Someone is typing a reason: no swap until they stop.
const reason = doc.querySelector('[name="reason"]');
reason.focus(); reason.value = 'Out of containers'; reason.dispatchEvent(new w.Event('input', {bubbles: true}));
version = 'v3'; rows = [['HMT-4', 'Ready']];
await wait(250);
assert.equal(fetched, 1, 'Nothing is fetched or swapped while typing');
assert.equal(doc.querySelector('[name="reason"]').value, 'Out of containers', 'Typed text is never lost');
w.close();
console.log('Background refresh DOM: 9 checks passed.');

// Waits under an open SweetAlert confirmation; never runs two checks at once; refills GPS fields after a swap.
{
    const vc = new VirtualConsole();
    const html = (version, geo = '') => `<main id="main-content"><div data-poll-url="/rider/trips/current" data-poll-version="${version}" data-poll-seconds="0.05" hidden></div>
     <div data-rider-track="trip-9" data-token="t" hidden></div><span data-location-status></span><span data-travelled></span>
     <form method="post" action="/rider/trips/replan"><input type="hidden" name="latitude" data-geo="latitude" value="${geo}"></form></main>`;
    const dd = new JSDOM(html('a'), {url: 'http://localhost/rider', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc});
    const ww = dd.window;
    let v = 'a', polls = 0, pages = 0, slow = 0, swal = true;
    const fixes = [];
    Object.defineProperty(ww.navigator, 'geolocation', {value: {watchPosition(ok) { fixes.push(ok); return 1; }, clearWatch() {}}});
    ww.Swal = {isVisible: () => swal};
    ww.CSS ??= {}; ww.CSS.escape ??= s => String(s);
    ww.fetch = async url => {
        if (String(url).includes('/rider/trips/current')) { polls++; if (slow) await new Promise(r => setTimeout(r, slow)); return {status: 200, json: async () => ({ok: true, data: {version: v}})}; }
        if (String(url).includes('/rider/trips/location')) return {status: 200, json: async () => ({ok: true})};
        pages++; return {ok: true, redirected: false, status: 200, text: async () => `<html><body>${html(v)}</body></html>`};
    };
    ww.eval(readFileSync('public/assets/js/app.js', 'utf8'));
    ww.eval(readFileSync('public/assets/js/live.js', 'utf8'));
    fixes[0]({coords: {latitude: 10.61, longitude: 122.01, accuracy: 8}});
    v = 'b';
    await wait(250);
    assert.equal(pages, 0, 'No swap while a confirmation popup is open');
    swal = false;
    await wait(250);
    assert.equal(pages, 1, 'Swaps once the popup closes');
    assert.equal(ww.document.querySelector('[data-geo="latitude"]').value, '10.610000', 'Last GPS fix refills the new form');
    slow = 300; const before = polls; v = 'c';
    ww.document.dispatchEvent(new ww.Event('visibilitychange'));
    ww.document.dispatchEvent(new ww.Event('visibilitychange'));
    await wait(120);
    assert.equal(polls - before, 1, 'Only one check runs at a time');
    ww.close();
    console.log('Background refresh guards: 4 checks passed.');
}
