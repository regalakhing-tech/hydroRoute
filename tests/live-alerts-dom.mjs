// Navigation badges and new-notice toasts in live.js (station new orders, customer updates).
// Run: node tests/live-alerts-dom.mjs
import assert from 'node:assert/strict';
import {JSDOM, VirtualConsole} from 'jsdom';
import {readFileSync} from 'node:fs';

const src = readFileSync('public/assets/js/live.js', 'utf8');
const navigations = [];
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', e => { if (/navigation/.test(e.message)) navigations.push(e); else throw e; });
const dom = new JSDOM(`<body><nav><a href="/station-admin/orders">Orders<span data-nav-count="orders" hidden></span></a></nav>
    <span data-nav-counts="/station-admin/navigation-counts" hidden></span></body>`,
    {url: 'https://x.test/station-admin', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole});
const w = dom.window;
let latest = {id: 'a', kind: 'staff_order_new', title: 'New order HMT-0001', body: 'Pickup', order_id: 'o1', created_at: '2026-11-15T01:00:00Z'};
const fired = [];
w.fetch = async () => ({status: 200, json: async () => ({ok: true, data: {orders: 3, messages: 0, notifications: 1, latest}})});
w.Swal = {fire: o => { fired.push(o); return Promise.resolve({}); }};
const timers = [];
w.setTimeout = fn => timers.push(fn);
const settle = async () => { for (let i = 0; i < 10; i++) await new Promise(r => setImmediate(r)); };

w.eval(src);
await settle();
const badge = w.document.querySelector('[data-nav-count="orders"]');
assert.equal(badge.hidden, false, 'Orders badge is shown');
assert.equal(badge.textContent, '3', 'Orders badge shows the waiting count');
assert.equal(fired.length, 0, 'First visit remembers the newest notice without a toast');

latest = {...latest, id: 'b', title: 'New order HMT-0002', order_id: 'o2', created_at: '2026-11-15T01:05:00Z'};
timers.at(-1)(); await settle();
assert.equal(fired.length, 1, 'A newer notice pops up');
assert.equal(fired[0].title, 'New order HMT-0002');
timers.at(-1)(); await settle();
assert.equal(fired.length, 1, 'The same notice pops up only once');

const toast = w.document.createElement('div');
fired[0].didOpen(toast);
toast.dispatchEvent(new w.Event('click'));
assert.equal(navigations.length, 1, 'Clicking the toast opens the order');
console.log('Live alerts: badge, first-visit silence, one toast per new notice and click-through passed.');
