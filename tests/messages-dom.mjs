import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {JSDOM} from 'jsdom';
const base=process.argv[2]||'http://127.0.0.1:8001';
const scripts=['app','messages'].map(name=>readFileSync(`public/assets/js/${name}.js`,'utf8'));
let checks=0;
const check=(v,m)=>{assert.ok(v,m);checks++;};
async function page(path,storage={}) {
 let html;
 if(process.argv.includes('--render')) {
  const role=path.startsWith('/customer/')?'customer':'station-admin';
  html=execFileSync('php',['-r',`require 'bootstrap.php'; $chat=App\\UI\\MessagingData::forRole('${role}'); require 'app/Views/shared/messages.php';`],{encoding:'utf8'});
 } else {const response=await fetch(base+path); assert.equal(response.status,200,path);html=await response.text();}
 const dom=new JSDOM(html,{url:base+path,runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document,errors=[];
 w.HTMLElement.prototype.scrollIntoView=function(){};
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 w.CSS??={};w.CSS.escape??=s=>s;
 w.addEventListener('error',e=>errors.push(e.error?.message||e.message));
 Object.entries(storage).forEach(([k,v])=>w.localStorage.setItem(k,v));
 scripts.forEach(s=>w.eval(s));
 return {w,d,close(){assert.deepEqual(errors,[]);dom.window.close();}};
}
const key='hamtic-chat:v1:thread:guintas-maria';
const isolation=execFileSync('php',['-r',"require 'bootstrap.php'; foreach (['rider','super-admin'] as $role) { try { App\\UI\\MessagingData::forRole($role); exit(1); } catch (InvalidArgumentException $e) {} } echo 'isolated';"],{encoding:'utf8'});
check(isolation==='isolated','rider and super admin data rejected');
{
 const p=await page('/customer/messages?thread=guintas-maria',{[key]:'{broken'});
 check(p.d.querySelectorAll('h1').length===1,'one heading');
 const ids=[...p.d.querySelectorAll('[id]')].map(n=>n.id);check(new Set(ids).size===ids.length,'unique ids');
 check(p.d.querySelectorAll('[data-chat-thread]').length===3,'customer sees three station conversations');
 check(!p.d.body.textContent.includes('Luis Reyes')&&!p.d.body.textContent.includes('Emilia Ramos'),'customer cannot see another customer');
 const input=p.d.querySelector('#chat-body'),form=p.d.querySelector('[data-chat-form]');
 const submit=()=>form.dispatchEvent(new p.w.Event('submit',{bubbles:true,cancelable:true}));
 input.value='   ';submit();check(p.w.localStorage.getItem(key)==='{broken','blank not stored');
 input.value='x'.repeat(2001);submit();check(p.w.localStorage.getItem(key)==='{broken','overlong rejected');
 input.value='<img src=x onerror=alert(1)> Hello';submit();
 check(p.d.querySelector('[data-chat-messages]').textContent.includes('<img src=x'),'body rendered literally');
 check(!p.d.querySelector('[data-chat-messages] img'),'no HTML injection');
 check(JSON.parse(p.w.localStorage.getItem(key)).length===1,'one saved local message');
 const stored=p.w.localStorage.getItem(key);
 const reload=await page('/customer/messages?thread=guintas-maria',{[key]:stored});check(reload.d.querySelector('[data-chat-messages]').textContent.includes('<img src=x'),'reload restores local text');reload.close();
 p.d.querySelector('[data-chat-emoji-toggle]').click();check(!p.d.querySelector('#chat-emojis').hidden,'emoji opens');
 p.d.querySelector('[data-chat-emoji]').click();check(input.value==='😊','emoji inserts');
 input.value='Newline';input.dispatchEvent(new p.w.KeyboardEvent('keydown',{key:'Enter',shiftKey:true,bubbles:true,cancelable:true}));check(input.value==='Newline','shift enter does not send');
 input.value='Enter sends';input.dispatchEvent(new p.w.KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));check(input.value==='','enter sends');
 p.d.querySelector('#chat-search').value='not-a-station';p.d.querySelector('#chat-search').dispatchEvent(new p.w.Event('input'));check(!p.d.querySelector('[data-chat-no-results]').hidden,'search empty state');
 p.d.querySelector('[data-chat-back]').click();check(!p.d.querySelector('[data-chat-list-pane]').classList.contains('hidden'),'mobile back');
 const station=await page('/station-admin/messages?customer=maria',{[key]:stored});
 check(station.d.querySelector('[data-chat-messages]').textContent.includes('<img src=x'),'same browser role shared message');
 check(station.d.querySelectorAll('[data-chat-thread]').length===6,'station sees six own customer conversations');
 check(!station.d.body.textContent.includes('Poblacion Water Station'),'station does not see other station');
 const updated=JSON.parse(stored);updated.push({id:'external',sender:'station:guintas',body:'Cross tab update',at:new Date().toISOString()});
 p.w.localStorage.setItem(key,JSON.stringify(updated));p.w.dispatchEvent(new p.w.StorageEvent('storage',{key,newValue:JSON.stringify(updated)}));
 check(p.d.querySelector('[data-chat-messages]').textContent.includes('Cross tab update'),'storage sync');
 station.close();p.close();
}
for (const query of ['thread=guintas-luis','thread[]=guintas-maria','station=unknown']) {
 const p=await page('/customer/messages?'+query);check(!p.d.querySelector('[data-chat-invalid]').hidden,'invalid reference safe: '+query);p.close();
}
for (const query of ['station=guintas','thread=guintas-maria']) {const p=await page('/customer/messages?'+query);check(p.d.querySelector('[data-chat-name]').textContent==='Guintas Water Station','valid deep link');p.close();}
{
 const bad=[{id:'bad',sender:'customer:luis',body:'UNAUTHORIZED CONTENT',at:new Date().toISOString()},{id:'bad2',sender:'station:guintas',body:'x'.repeat(2001),at:new Date().toISOString()}];
 const p=await page('/customer/messages?thread=guintas-maria',{[key]:JSON.stringify(bad)});check(!p.d.querySelector('[data-chat-messages]').textContent.includes('UNAUTHORIZED CONTENT'),'invalid stored sender rejected');check(p.d.querySelectorAll('[data-message-id]').length===2,'overlong stored message rejected');p.close();
}
console.log(`Messaging DOM: ${checks} checks passed.`);
