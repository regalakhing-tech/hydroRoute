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
