'use strict';
const {JSDOM}=require('./web-dom/node_modules/jsdom'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const tick=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate);};let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
async function fixture(){
 const dom=new JSDOM('<section data-messenger-app data-api="/messenger-api/v1/"><button data-messenger-new>New</button><button data-scope="inbox">Chats</button><button data-scope="requests">Requests</button><button data-scope="archived">Archive</button><div data-conversation-list></div><section data-thread></section><form data-message-search><input name=q></form><div data-messenger-status></div></section>',{url:'https://example.test/messages',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,root=w.document.querySelector('section'),calls=[],scrolled=[];let pending=null,jump=null,bad=false;
 const chat={id:1,other_user:{id:22,name:'Emma'},request_state:'accepted',requester_id:11,recipient_id:22,can_send:true,can_attach:true,blocked:false};
 const message=(id,body)=>({id,conversation_id:1,sender_user_id:22,body,created_at:'2026-10-24 12:00:00'});
 w.setInterval=()=>1;w.clearInterval=()=>{};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};w.HTMLElement.prototype.scrollIntoView=function(){scrolled.push(this.dataset.messageId);};
 w.fetch=async(url,options={})=>{const u=new URL(url,w.location.href),resource=u.pathname.split('/').filter(Boolean).at(-1);calls.push({resource,options,query:u.searchParams});let result;
  if(resource==='session')result={can_use:true,user_id:11,history_search_supported:true,csrf:{name:'csrf',value:'token'}};
  else if(resource==='inbox')result={items:[chat],pending_request_count:0,next_cursor:null};
  else if(resource==='conversation')result=chat;
  else if(resource==='messages'){if(u.searchParams.get('before')==='6'){if(jump)await jump;result=[message(5,'Cabin plans from last spring')];}else result=[message(100,'Latest message')];}
  else if(resource==='read')result={};
  else if(resource==='search-history'){if(pending)await pending;result=bad?{items:[],next_before:100,scanned:500,complete:false}:u.searchParams.get('before')?{items:[message(5,'Cabin plans from last spring')],next_before:null,scanned:95,complete:true}:{items:[],next_before:100,scanned:500,complete:false};}
  else throw Error('Unexpected '+resource);
  return{ok:true,status:200,json:async()=>({ok:true,result})};
 };
 for(const file of ['messenger-tools.js','messenger.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets/'+file),'utf8'));await tick();root.querySelector('[data-conversation-list] [data-id="1"]').click();await tick();
 const q=s=>root.querySelector(s),tap=text=>{const scope=q('dialog')||root,b=[...scope.querySelectorAll('button')].find(x=>x.textContent===text);assert(b,'Missing '+text);b.click();};
 const input=value=>{q('dialog input').value=value;q('dialog input').dispatchEvent(new w.Event('input',{bubbles:true}));};
 return{dom,w,root,q,tap,input,calls,scrolled,set pending(v){pending=v;},set jump(v){jump=v;},set bad(v){bad=v;}};
}
(async()=>{
 {const f=await fixture();const composer=f.q('[data-composer] textarea');composer.value='Unsent draft';f.tap('Search chat history');f.input('spring');f.tap('Search');await tick();check(f.q('dialog').textContent.includes('Messages checked: 500'),'empty scan reports bounded progress');check(!f.q('dialog').textContent.includes('No matches'),'empty intermediate page does not claim full search exhaustion');f.tap('Search older messages');await tick();check(f.q('dialog').textContent.includes('Cabin plans from last spring'),'old matching original appears');const requests=f.calls.filter(c=>c.resource==='search-history');check(requests[1].query.get('before')==='100','exclusive search cursor preserved');check(requests[0].options.headers['X-Messenger-Actor']==='11','web read preserves actor context');f.tap('Previous results');await tick();check(f.q('dialog').textContent.includes('Messages checked: 500'),'previous page reloads without retaining unbounded result bodies');f.tap('Search older messages');await tick();f.q('.MessengerHistoryResults button').click();await tick();check(f.scrolled.includes('5')&&!f.q('dialog'),'result opens authorized original and closes dialog');check(f.q('[data-composer] textarea').value==='Unsent draft','history navigation preserves unsent composer');f.dom.window.close();}
 {const f=await fixture();f.tap('Search chat history');f.input('spring');let release;f.pending=new Promise(r=>release=r);f.tap('Search');f.tap('Cancel');release();await tick();check(f.q('dialog').textContent.includes('Search cancelled.'),'late cancelled search cannot paint results');check(!f.q('dialog').textContent.includes('Messages checked:'),'cancel invalidates late progress');f.dom.window.close();}
 {const f=await fixture();f.tap('Search chat history');f.input('spring');f.tap('Search');await tick();f.bad=true;f.tap('Search older messages');await tick();check(f.q('dialog').textContent.includes('Invalid history cursor'),'looping search cursor rejected visibly');f.dom.window.close();}
 {const f=await fixture();f.tap('Search chat history');f.input('spring');f.tap('Search');await tick();f.tap('Search older messages');await tick();let release;f.jump=new Promise(r=>release=r);f.q('.MessengerHistoryResults button').click();f.tap('Close');release();await tick();check(f.q('[data-message-id="100"]')&&!f.q('[data-message-id="5"]'),'closing search invalidates late original-window navigation');f.dom.window.close();}
 console.log('Web history search checks: '+checks);
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
