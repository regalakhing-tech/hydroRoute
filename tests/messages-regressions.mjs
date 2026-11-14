import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const base=process.argv[2]||'http://127.0.0.1:8001';
const source=readFileSync('public/assets/js/messages.js','utf8');
const html=await(await fetch(base+'/customer/messages?thread=guintas-maria')).text();
function setup({failStorage=false,width=1200}={}){
 const dom=new JSDOM(html,{url:base+'/customer/messages?thread=guintas-maria',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window;Object.defineProperty(w,'innerWidth',{value:width,writable:true});
 if(failStorage)w.Storage.prototype.setItem=function(){throw new Error('storage unavailable');};
 w.eval(source);
 return {w,d:w.document,close:()=>w.close()};
}
function send(p,text){p.d.querySelector('#chat-body').value=text;p.d.querySelector('[data-chat-form]').dispatchEvent(new p.w.Event('submit',{bubbles:true,cancelable:true}));}
let checks=0;function check(value,message){assert.ok(value,message);checks++;}
{
 const p=setup({failStorage:true});send(p,'Session message one');send(p,'Session message two');
 const text=p.d.querySelector('[data-chat-messages]').textContent;
 check(text.includes('Session message one')&&text.includes('Session message two'),'Failed storage must retain both messages for the session');
 check([...p.d.querySelectorAll('[data-message-id]')].slice(-2).every(row=>row.textContent.includes('This session only')),'Both unsaved messages need honest status');p.close();
}
{
 const p=setup();send(p,'First seen message');
 const first=p.d.querySelector('[data-chat-messages]').lastElementChild.dataset.messageId;
 const threadKey='hamtic-chat:v1:thread:guintas-maria',readKey='hamtic-chat:v1:read:station:guintas:guintas-maria';
 p.w.localStorage.setItem(readKey,first);p.w.dispatchEvent(new p.w.StorageEvent('storage',{key:readKey,newValue:first}));
 check([...p.d.querySelectorAll('[data-message-id]')].find(row=>row.dataset.messageId===first).textContent.includes('Seen in preview'),'First message marked seen');
 const messages=JSON.parse(p.w.localStorage.getItem(threadKey));messages.push({id:'station-later',sender:'station:guintas',body:'A later reply',at:new Date().toISOString()});
 p.w.localStorage.setItem(threadKey,JSON.stringify(messages));p.w.localStorage.setItem(readKey,'station-later');p.w.dispatchEvent(new p.w.StorageEvent('storage',{key:readKey,newValue:'station-later'}));
 check([...p.d.querySelectorAll('[data-message-id]')].find(row=>row.dataset.messageId===first).textContent.includes('Seen in preview'),'Earlier seen message must not regress after later read marker');
 const focused=p.d.querySelector('[data-chat-thread]');focused.focus();p.w.dispatchEvent(new p.w.Event('resize'));
 check(p.d.activeElement?.dataset.chatThread===focused.dataset.chatThread,'Thread focus survives list refresh');p.close();
}
{
 const p=setup({width:375});p.d.querySelector('[data-chat-back]').click();
 const thread=p.d.querySelector('[data-chat-thread]');thread.focus();thread.click();
 check(p.d.activeElement===p.d.querySelector('#chat-body'),'Opening a mobile thread focuses the composer');p.close();
}
console.log('Messaging persistence/read/focus regressions: '+checks+' assertions passed.');

