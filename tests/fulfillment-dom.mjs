import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM,VirtualConsole} from 'jsdom';
const base=process.argv[2]||'http://127.0.0.1:8001';
async function page(path,seed={}){const r=await fetch(base+path);assert.equal(r.status,200);const d=new JSDOM(await r.text(),{url:base+path,runScripts:'outside-only',virtualConsole:new VirtualConsole()});const w=d.window;const errors=[];w.addEventListener('error',e=>errors.push(e.message));w.HTMLElement.prototype.scrollIntoView=function(){};w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};w.CSS??={};w.CSS.escape??=v=>String(v);for(const [k,v]of Object.entries(seed))w.localStorage.setItem(k,v);w.eval(readFileSync('public/assets/js/app.js','utf8'));w.eval(readFileSync('public/assets/js/fulfillment.js','utf8'));return {w,d:w.document,close:()=>{assert.deepEqual(errors,[],path+' runtime errors');w.close()}};}
const p=await page('/customer/checkout?round=3');
assert.ok(p.d.querySelector('[name="fulfillment"][value="pickup"]'),'Checkout offers pickup choice');
p.close();

const event=(p,e,type)=>e.dispatchEvent(new p.w.Event(type,{bubbles:true,cancelable:true}));
const choose=(p,value)=>{const e=p.d.querySelector(`[name="fulfillment"][value="${value}"]`);e.checked=true;event(p,e,'change');};
{
 const p=await page('/customer/checkout?round=2&faucet=1&bottle350=3'); const address=p.d.querySelector('[name="address"]');
 assert.equal(address.required,true);assert.equal(p.d.querySelector('[data-checkout-total]').textContent,'PHP 730.00');address.value='work';event(p,address,'change');assert.equal(p.d.querySelector('[data-delivery-fee]').textContent,'PHP 15.00');assert.equal(p.d.querySelector('[data-checkout-total]').textContent,'PHP 735.00');address.value='home';event(p,address,'change');choose(p,'pickup');assert.equal(address.disabled,true);assert.equal(address.required,false);assert.equal(new p.w.FormData(p.d.querySelector('form')).has('address'),false);assert.equal(p.d.querySelector('[data-pickup-location]').hidden,false);assert.match(p.d.querySelector('[data-payment-title]').textContent,/Cash on pickup/);assert.equal(p.d.querySelector('[data-checkout-total]').textContent,'PHP 720.00');assert.equal(p.d.querySelector('[data-delivery-fee]').textContent,'Free (pickup)');
 const schedule=p.d.querySelector('[name="delivery"][value="scheduled"]');schedule.checked=true;event(p,schedule,'change');assert.equal(p.d.querySelector('[name="date"]').required,true);p.d.querySelector('[name="notes"]').value='Collect after lunch';event(p,p.d.querySelector('form'),'submit');const saved={};for(let i=0;i<p.w.localStorage.length;i++){const k=p.w.localStorage.key(i);saved[k]=p.w.localStorage.getItem(k)}const draft=JSON.parse(saved['hamtic-ui:form:customer-checkout-v2-guintas-2-0-1-0-3-0-0-pickup']);assert.ok(!('address' in draft));choose(p,'delivery');assert.equal(address.disabled,false);assert.equal(p.d.querySelector('[name="notes"]').value,'');p.close();
 const next=await page('/customer/checkout?round=2&faucet=1&bottle350=3&fulfillment=pickup',saved);assert.equal(next.d.querySelector('[name="notes"]').value,'Collect after lunch');assert.equal(next.d.querySelector('[name="address"]').disabled,true);next.close();
 const isolated=await page('/customer/checkout?bottle350=4&fulfillment=pickup',saved);assert.equal(isolated.d.querySelector('[name="notes"]').value,'');isolated.close();
}
for(const query of ['fulfillment[]=pickup','fulfillment=bogus']){const p=await page('/customer/checkout?round=3&'+query);assert.equal(p.d.querySelector('[name="fulfillment"]:checked').value,'delivery');p.close();}
{
const p=await page('/customer/tracking?order=HMT-098');assert.match(p.d.body.textContent,/Ready for pickup/);assert.match(p.d.body.textContent,/Picked up/);assert.doesNotMatch(p.d.querySelector('main').textContent,/Rider|Out for delivery|Contact rider/);assert.match(p.d.body.textContent,/Cash on pickup/);p.close();
}
{
const p=await page('/station-admin/deliveries?tab=ready');assert.doesNotMatch(p.d.body.textContent,/HMT-105|HMT-109/);assert.match(p.d.querySelector('#dispatch').textContent,/3 \/ 15/);p.close();
}
{
const p=await page('/station-admin/pickups');assert.equal(p.d.querySelectorAll('#pickup-orders tbody tr').length,2);const row=p.d.querySelector('#pickup-orders tbody tr');const ready=row.querySelector('[data-status="Ready for pickup"]');assert.equal(row.querySelector('[data-status="Picked up"]').disabled,true);ready.click();p.d.querySelector('#confirm-button').click();await new Promise(r=>setTimeout(r,0));assert.equal(row.dataset.filterValue,'Ready for pickup');assert.equal(row.querySelector('[data-status="Picked up"]').disabled,false);row.querySelector('[data-status="Picked up"]').click();p.d.querySelector('#confirm-button').click();await new Promise(r=>setTimeout(r,0));assert.equal(row.dataset.filterValue,'Picked up');p.close();
}
console.log('Checkout switching, conditional validation, local restore/isolation, pickup tracking, queue actions and dispatch exclusion passed.');


for(const path of ['/customer/checkout?round=3&fulfillment=pickup','/customer/tracking?order[]=HMT-098','/station-admin/orders?order[]=HMT-105','/station-admin/deliveries?tab[]=ready','/station-admin/pickups?order[]=HMT-109']){const p=await page(path);assert.equal(p.d.querySelectorAll('h1').length,1);p.close();}

{const p=await page('/customer/checkout?round=3&fulfillment=pickup',{'hamtic-ui:form:customer-checkout-v2-guintas-3-0-0-0-0-0-0-pickup':'{corrupt'});assert.equal(p.d.querySelector('[name=address]').disabled,true);p.close();}
{
const p=await page('/station-admin/orders');const method=p.d.querySelector('[data-filter-key="fulfillment"]');method.value='pickup';event(p,method,'change');let rows=[...p.d.querySelectorAll('#station-orders tbody tr')].filter(r=>!r.hidden);assert.equal(rows.length,2);assert.ok(rows.every(r=>r.dataset.fulfillment==='pickup'));const status=p.d.querySelector('[data-filter-target="station-orders"]:not([data-filter-key])');status.value='Ready';event(p,status,'change');rows=[...p.d.querySelectorAll('#station-orders tbody tr')].filter(r=>!r.hidden);assert.equal(rows.length,1);assert.match(rows[0].textContent,/HMT-109/);p.close();
}

// Live checkout slots: 5-minute times per date, cutoff by mode, other customers' slots disabled, refills left per date.
{
 const day='2099-01-05', other='2099-01-06';
 const days={[day]:[['08:00','09:00','09:00','08:30']],[other]:[['10:00','10:20','10:20','10:20']]};
 const html=`<form data-fulfillment-root data-initial-mode="delivery" data-persist="slots-test"><input type="radio" name="fulfillment" value="delivery" checked><input type="radio" name="fulfillment" value="pickup">
 <div data-delivery-address><select name="address" data-fees="{}"><option value="a">A</option></select></div><section data-pickup-location hidden></section>
 <input type="radio" name="delivery" value="asap"><input type="radio" name="delivery" value="scheduled" checked><input type="date" name="date" value="${day}">
 <select name="time" data-slot-select data-slot-days='${JSON.stringify(days)}' data-booked='["${day}T08:05"]' data-refills='{"${day}":3}'><option value="">Choose a time</option></select>
 <p><span data-refills-left></span></p><label for="notes">n</label><textarea name="notes" id="notes"></textarea><span data-checkout-total data-subtotal="40"></span></form>`;
 const d=new JSDOM(html,{url:'http://localhost/',runScripts:'outside-only'});const w=d.window;w.eval(readFileSync('public/assets/js/fulfillment.js','utf8'));
 const time=w.document.querySelector('[name="time"]'),values=()=>[...time.options].map(o=>o.value).filter(Boolean);
 assert.deepEqual(values(),['08:00','08:05','08:10','08:15','08:20','08:25','08:30'],'Delivery slots every 5 minutes up to the delivery cutoff');
 assert.equal(time.querySelector('[value="08:05"]').disabled,true);assert.match(time.querySelector('[value="08:05"]').textContent,/taken/);
 assert.equal(time.querySelector('optgroup').label,'8 AM');assert.match(w.document.querySelector('[data-refills-left]').textContent,/3 refills left/);
 time.value='08:10';const date=w.document.querySelector('[name="date"]');date.dispatchEvent(new w.Event('change',{bubbles:true}));assert.equal(time.value,'08:10','A still-valid choice is kept');const pick=w.document.querySelector('[name="fulfillment"][value="pickup"]');pick.checked=true;pick.dispatchEvent(new w.Event('change',{bubbles:true}));
 assert.equal(values().at(-1),'08:55','Pickup slots run to the order cutoff');
 date.value=other;date.dispatchEvent(new w.Event('change',{bubbles:true}));
 assert.deepEqual(values(),['10:00','10:05','10:10','10:15'],'Changing the date rebuilds its slots');assert.equal(w.document.querySelector('[data-refills-left]').textContent,'');
 w.close();console.log('Live checkout 5-minute slots passed.');
}

// Tracking "Change time" form: the same slot list, for the order's own mode, rebuilt on date change.
{
    const day = '2099-02-01';
    const d = new JSDOM(`<form data-slot-form="pickup"><input type="date" name="date" value="${day}">
     <select name="time" data-slot-select data-slot-days='{"${day}":[["08:00","08:30","08:10","08:00"]]}' data-booked='["${day}T08:05"]' data-refills='{}'></select><span data-refills-left></span></form>`, {url: 'http://localhost/', runScripts: 'outside-only'});
    d.window.eval(readFileSync('public/assets/js/fulfillment.js', 'utf8'));
    const opts = [...d.window.document.querySelectorAll('[name="time"] option')].filter(o => o.value);
    assert.deepEqual(opts.map(o => o.value), ['08:00', '08:05', '08:10'], 'Pickup slots up to the order cutoff');
    assert.equal(opts[1].disabled, true, 'Taken slot disabled');
    d.window.close();
    console.log('Reschedule slot form passed.');
}
