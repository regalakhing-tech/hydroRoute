// Table filter (app.js): rows marked data-filter-only (cancelled orders) are hidden under "All statuses" and shown
// only when their own status is chosen; search and the method filter still apply to them.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const d = new JSDOM(`<input data-search="t"><select data-filter-target="t"><option value="all">All statuses</option><option>Scheduled</option><option>Cancelled</option></select>
 <select data-filter-target="t" data-filter-key="fulfillment"><option value="all">All</option><option value="pickup">Pickup</option><option value="delivery">Delivery</option></select>
 <div><table id="t" class="data-table"><thead><tr><th>Order</th></tr></thead><tbody>
 <tr data-search-row data-fulfillment="pickup" data-filter-value="Scheduled"><td>HMT-0001</td></tr>
 <tr data-search-row data-filter-only hidden data-fulfillment="delivery" data-filter-value="Cancelled"><td>HMT-0002</td></tr>
 <tr data-search-row data-filter-only hidden data-fulfillment="pickup" data-filter-value="Cancelled"><td>HMT-0003</td></tr>
 </tbody></table><p data-empty hidden>No matching orders.</p></div>`, {url: 'http://localhost/station-admin/orders', runScripts: 'outside-only'});
const w = d.window, doc = w.document;
w.eval(readFileSync('public/assets/js/app.js', 'utf8'));
const shown = () => [...doc.querySelectorAll('tbody tr')].filter(r => !r.hidden).map(r => r.textContent.trim());
const [search, status, method] = [doc.querySelector('[data-search]'), ...doc.querySelectorAll('select')];
const set = (el, value) => { el.value = value; el.dispatchEvent(new w.Event(el.tagName === 'INPUT' ? 'input' : 'change', {bubbles: true})); };

assert.deepEqual(shown(), ['HMT-0001'], 'All statuses hides cancelled orders');
set(status, 'Cancelled');
assert.deepEqual(shown(), ['HMT-0002', 'HMT-0003'], 'Cancelled filter shows only cancelled orders');
set(method, 'pickup');
assert.deepEqual(shown(), ['HMT-0003'], 'Method filter still applies');
set(search, '0002');
assert.deepEqual(shown(), [], 'Search still applies');
assert.equal(doc.querySelector('[data-empty]').hidden, false, 'Empty message shown');
set(search, ''); set(method, 'all'); set(status, 'all');
assert.deepEqual(shown(), ['HMT-0001'], 'Back to All hides them again');
set(search, 'HMT-0002');
assert.deepEqual(shown(), [], 'Searching under All never reveals a cancelled order');
console.log('Order filter DOM: 7 checks passed.');

// Submit-once must not lock a form whose submission another listener cancelled (password mismatch).
{
    const d = new JSDOM(`<form method="post" action="/customer/account/password" data-submit-once><input type="password" name="password" value="secret-one"><input type="password" name="confirm_password" value="secret-two"><button type="submit">Change password</button></form>`, {url: 'http://localhost/customer/account', runScripts: 'outside-only'});
    const w = d.window, form = w.document.querySelector('form'), button = form.querySelector('button');
    w.HTMLFormElement.prototype.requestSubmit ??= function () { this.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true})); };
    w.HTMLInputElement.prototype.reportValidity = function () { return false; };
    w.fetch = async () => ({status: 200, json: async () => ({ok: true})});
    w.eval(readFileSync('public/assets/js/app.js', 'utf8'));
    w.eval(readFileSync('public/assets/js/live.js', 'utf8'));
    const send = () => form.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
    assert.equal(send(), false, 'Mismatched passwords are not sent');
    await new Promise(r => setTimeout(r, 5));
    assert.equal(button.disabled, false, 'Button stays usable after a cancelled send');
    assert.notEqual(form.dataset.sent, 'yes');
    form.querySelector('[name="confirm_password"]').value = 'secret-one';
    send();
    await new Promise(r => setTimeout(r, 5));
    assert.equal(button.disabled, true, 'A real send locks the button against double submits');
    w.close();
    console.log('Submit-once after cancelled validation passed.');
}
