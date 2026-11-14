import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {JSDOM, VirtualConsole} from 'jsdom';
const base=process.argv[2]||'http://127.0.0.1:8001';
const script=['app','messages','fulfillment'].map(name=>readFileSync('public/assets/js/'+name+'.js','utf8')).join('\n');
const routes=JSON.parse(execFileSync('php',['-r',"require 'bootstrap.php'; echo json_encode(array_keys(App\\UI\\Pages::routes()));"],{encoding:'utf8'}));
const pages=['/','/login','/signup','/forgot-password','/register-station',...['customer','rider','station-admin','super-admin'].map(r=>'/login/'+r),...routes];
let checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;};
async function page(path,seed={},width=1440){
    const response=await fetch(base+path);
    assert.equal(response.status,200,path);
    const html=await response.text(), errors=[];
    const dom=new JSDOM(html,{url:base+path,runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:new VirtualConsole()});
    const w=dom.window;
    Object.defineProperty(w,'innerWidth',{value:width,writable:true});
    w.HTMLElement.prototype.scrollIntoView=function(){};
    w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
    w.HTMLDialogElement.prototype.close=function(){this.open=false;};
    w.CSS??={};w.CSS.escape??=value=>String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
    w.addEventListener('error',event=>errors.push(event.error?.message||event.message));
    for(const [key,value]of Object.entries(seed))w.localStorage.setItem(key,value);
    w.eval(script);
    const d=w.document;
    return {w,d,dom,errors,close:()=>{assert.deepEqual(errors,[],path+' runtime errors');dom.window.close();}};
}
function event(p,element,type){element.dispatchEvent(new p.w.Event(type,{bubbles:true,cancelable:true}));}
function submit(p,form){event(p,form,'submit');}
for(const path of pages){
    const p=await page(path);
    check(p.d.querySelectorAll('h1').length===1,path+' needs one h1');
    const ids=[...p.d.querySelectorAll('[id]')].map(e=>e.id);
    check(new Set(ids).size===ids.length,path+' duplicate IDs');
    for(const label of p.d.querySelectorAll('label[for]'))check(p.d.getElementById(label.htmlFor),path+' missing field for '+label.htmlFor);
    p.close();
}
{
    const p=await page('/super-admin/drivers'),select=p.d.querySelector('#driver-status');
    select.value='Active';event(p,select,'change');
    const rows=[...p.d.querySelectorAll('#driver-registry [data-search-row]')].filter(row=>!row.hidden);
    check(rows.length>0&&rows.every(row=>row.dataset.filterValue==='Active'),'Active filter must exclude Inactive');
    p.close();
}
for(const [path,id]of [['/customer/orders','order_search'],['/customer/stations','station_search'],['/super-admin/applications','application-search'],['/super-admin/stations','station-search'],['/super-admin/drivers','driver-search']]){
    const p=await page(path),input=p.d.getElementById(id);input.value='nomatchzzzz';event(p,input,'input');
    check([...p.d.querySelectorAll('[data-empty]')].some(el=>!el.hidden&&!el.classList.contains('hidden')),path+' empty state visible');p.close();
}
{
    const p=await page('/customer/station?station=guintas');
    p.d.querySelector('[data-counter="round-quantity"][data-step="1"]').click();
    check(p.d.querySelector('#round-quantity').value==='4','quantity stepper');
    check(p.d.querySelector('[data-order-total]').textContent==='PHP 80.00','live order total');
    p.d.querySelector('#round-quantity').value='2';
    p.d.querySelector('#faucet-quantity').value='1';
    p.d.querySelector('#bottle350-quantity').value='3';
    event(p,p.d.querySelector('#bottle350-quantity'),'input');
    check(p.d.querySelector('[data-order-total]').textContent==='PHP 720.00','mixed live total');
    p.close();
}
{
    const p=await page('/customer/checkout?station=guintas&round=2&faucet=1&bottle350=3');
    check(p.d.querySelector('[data-checkout-total]').textContent==='PHP 730.00','mixed checkout total with PHP 10 delivery fee');
    check(p.d.querySelector('#return-quantity').value==='3','mixed checkout returnable count');
    for(const image of ['water-jug.webp','faucet-container.webp','water-bottle.webp'])check(p.d.querySelector(`aside img[src$="${image}"]`),'item thumbnail '+image);
    check(p.d.body.textContent.includes('3 boxes')&&p.d.body.textContent.includes('1 container '),'box and singular units');p.close();
    const bottles=await page('/customer/checkout?bottle350=4');
    check(bottles.d.querySelector('#return-quantity').value==='0','bottle-only order needs no returns');bottles.close();
    for(const query of ['round=0&faucet=0&bottle350=0','round[]=3&faucet=no&bottle350=1.5']){
        const empty=await page('/customer/checkout?'+query);
        check(empty.d.querySelector('h1').textContent==='Your basket is empty','invalid or zero basket safe');
        check(!empty.d.querySelector('form[data-local-form]'),'empty basket cannot save charged draft');empty.close();
    }
    const product=await page('/station-admin/products?product=bottle350',{'hamtic-ui:form:station-product':JSON.stringify({price:'30'})});
    check(product.d.querySelector('[name="price"]').value==='220','old product price draft ignored');
    check(product.d.querySelector('[data-persist]').dataset.persist==='station-product-v2-bottle350','product form scoped and versioned');product.close();
    const missing=await page('/station-admin/products?product[]=bottle350');
    check(missing.d.querySelector('h1').textContent==='Product not found','array product reference rejected');missing.close();
}
{
    const p=await page('/customer/checkout?station=guintas&round=5&faucet=0&bottle350=0',{'hamtic-ui:form:customer-checkout-v2-guintas-5-0-0-0-0-0-0':JSON.stringify({quantity:'3',station:'poblacion',notes:'Saved note'})});
    check(p.d.querySelector('[name="quantity"]').value==='5','do not restore stale immutable quantity');
    check(p.d.querySelector('[name="station"]').value==='guintas','do not restore stale station');
    check(p.d.querySelector('[name="notes"]').value==='Saved note','restore editable draft note');p.close();
}
{
    const refill=await page('/customer/checkout?round=3&faucet=0&bottle350=0');
    refill.d.querySelector('[name="notes"]').value='Please call at the gate.';
    refill.d.querySelector('#return-quantity').value='2';
    submit(refill,refill.d.querySelector('form[data-local-form]'));
    const saved={};for(let i=0;i<refill.w.localStorage.length;i++){const key=refill.w.localStorage.key(i);saved[key]=refill.w.localStorage.getItem(key);}
    check(Object.keys(saved).includes('hamtic-ui:form:customer-checkout-v2-guintas-3-0-0-0-0-0-0'),'refill draft saved under canonical basket scope');refill.close();
    const bottles=await page('/customer/checkout?bottle350=4',saved);
    check(bottles.d.querySelector('#return-quantity').value==='0','refill draft cannot restore empties into bottle basket');
    check(bottles.d.querySelector('[name="notes"]').value==='','different basket keeps separate notes');bottles.close();
    const same=await page('/customer/checkout?faucet=0&round=3&bottle350=0',saved);
    check(same.d.querySelector('#return-quantity').value==='2','same basket restores custom returns');
    check(same.d.querySelector('[name="notes"]').value==='Please call at the gate.','same basket restores notes regardless of query order');same.close();
}
{
    const p=await page('/rider'),cash=p.d.querySelector('#cash-received');
    cash.value='150';event(p,cash,'input');check(p.d.querySelector('#cash-change').textContent==='80.00','rider cash change');
    cash.value='20';event(p,cash,'input');check(p.d.querySelector('[data-status="Delivered"]').disabled,'insufficient cash disables completion');
    p.close();
}
{
    const p=await page('/super-admin/application?id=REG-026'),form=p.d.querySelector('form[action="/super-admin/application/decision"]');
    check(form&&form.method==='post'&&/^[0-9a-f]{64}$/.test(form.querySelector('[name=_token]').value)&&form.querySelector('[name=id]').value==='REG-026','decision form posts with CSRF token and application id');
    check(form.querySelector('button[name=decision][value=approve]')&&form.querySelector('button[name=decision][value=reject]')&&form.querySelector('[name=reason]'),'approve, reject and reason controls');p.close();
    const add=await page('/station-admin/drivers/add'),driver=add.d.querySelector('form[action="/station-admin/drivers"]');
    check(driver&&driver.method==='post'&&driver.querySelector('[name=email]').required&&driver.querySelector('[name=password]').required&&driver.querySelector('[name=confirm_password]')&&driver.querySelector('[name=_token]'),'add driver sets the login password directly');add.close();
    const reg=await page('/register-station'),apply=reg.d.querySelector('form[action="/register-station"]');
    check(apply&&apply.method==='post'&&apply.querySelector('[name=administrator_email]')&&apply.querySelector('[name=_token]'),'station registration posts to the server');reg.close();
}
{
    const p=await page('/signup'),form=p.d.querySelector('form[action="/signup"]');
    check(form&&form.method==='post'&&/^[0-9a-f]{64}$/.test(form.querySelector('[name=_token]')?.value||''),'signup posts to the server with a CSRF token');
    check(!form.querySelector('[type=password]')&&form.querySelector('[name=email]')&&form.querySelector('[name=consent]'),'signup asks for details first; the password comes with the code');
    const login=await page('/login/customer'),loginForm=login.d.querySelector('form[data-login-role="customer"]');
    check(loginForm.method==='post'&&loginForm.getAttribute('action')==='/login/customer'&&!loginForm.hasAttribute('data-auth-form'),'customer login posts to the server');login.close();
    // The code-entry page relies on the shared password confirmation guard.
    const guard=p.d.createElement('form');guard.innerHTML='<input name="password" value="abcdefgh"><input name="confirm_password" value="xxxxxxxx">';p.d.body.append(guard);
    const confirmation=guard.querySelector('[name=confirm_password]'),blocked=new p.w.Event('submit',{bubbles:true,cancelable:true});guard.dispatchEvent(blocked);
    check(blocked.defaultPrevented&&!confirmation.validity.valid,'password mismatch blocks submit');
    guard.querySelector('[name=password]').value='xxxxxxxx';const allowed=new p.w.Event('submit',{bubbles:true,cancelable:true});guard.dispatchEvent(allowed);
    check(!allowed.defaultPrevented&&confirmation.validity.valid,'matching passwords submit to the server');p.close();
}
{
    const p=await page('/station-admin/schedules'),form=p.d.querySelector('[data-persist="station-closure"]'),start=form.querySelector('[name=starts]'),end=form.querySelector('[name=ends]');
    start.value='2026-11-05T13:00';end.value='2026-11-04T16:00';submit(p,form);check(!end.validity.valid,'invalid closure dates rejected');
    start.value='2026-11-04T13:00';event(p,start,'input');check(end.validity.valid,'editing start clears end error');
    submit(p,form);check(form.querySelector('[data-form-feedback]'),'local schedule saved feedback');p.close();
}
{
    const p=await page('/station-admin',{},375),sidebar=p.d.querySelector('[data-sidebar]'),toggle=p.d.querySelector('[data-sidebar-toggle]');
    check(sidebar.inert===true,'mobile hidden sidebar not focusable');toggle.click();check(sidebar.dataset.open==='true'&&!sidebar.inert,'mobile menu opens');
    p.d.dispatchEvent(new p.w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));check(sidebar.dataset.open==='false'&&sidebar.inert,'Escape closes mobile menu');p.close();
}
{
    const p=await page('/customer/notifications',{},375),tabs=p.d.querySelector('.mobile-tabbar');
    check(tabs.querySelector('a[href="/customer/notifications"][aria-current="page"]'),'customer notifications tab replaces More and shows active');
    check(!tabs.querySelector('[data-sidebar-toggle]'),'customer tab bar has no More button');
    check(p.d.querySelector('.mobile-header a[href="/customer/account"]'),'customer avatar opens account');p.close();
}
console.log(pages.length+' page DOM checks passed; '+checks+' assertions passed, including interactions, record isolation, local forms and mobile navigation. Layout screenshots could not be verified without a browser host.');

