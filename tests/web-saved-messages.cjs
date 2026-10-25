'use strict';
const {JSDOM}=require('./web-dom/node_modules/jsdom'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const tick=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate);};let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
async function fixture(){
 const dom=new JSDOM('<section data-messenger-app data-api="/messenger-api/v1/"><button data-messenger-new>New</button><button data-scope="inbox">Chats</button><button data-scope="requests">Requests</button><button data-scope="archived">Archive</button><div data-conversation-list></div><section data-thread></section><form data-message-search><input name=q></form><div data-messenger-status></div></section>',{url:'https://example.test/messages',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,root=w.document.querySelector('section'),calls=[];let created=false,gate=null,forged=false;
 const chat={id:700,type:'saved',other_user:{id:11,name:'owner',title:'Saved messages'},request_state:'accepted',requester_id:11,recipient_id:11,can_send:true,can_attach:true,blocked:false};
 w.setInterval=()=>1;w.clearInterval=()=>{};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};w.HTMLElement.prototype.scrollIntoView=function(){};
 w.fetch=async(url,options={})=>{const u=new URL(url,w.location.href),resource=u.pathname.split('/').filter(Boolean).at(-1),body=options.body?JSON.parse(options.body):null;calls.push({resource,options,body,query:u.searchParams});let result;
  if(resource==='session')result={can_use:true,user_id:11,saved_messages_supported:true,csrf:{name:'csrf',value:'token'}};
  else if(resource==='saved-messages'){assert.equal(body.csrf,'token');if(gate)await gate;created=true;result=forged?{...chat,recipient_id:22}:chat;}
  else if(resource==='inbox')result={items:created&&u.searchParams.get('include_saved')==='1'?[chat]:[],pending_request_count:0,next_cursor:null};
  else if(resource==='conversation')result=chat;
  else if(resource==='messages')result=[{id:7001,conversation_id:700,sender_user_id:11,body:'Private weekend notes',created_at:'2026-10-25 12:00:00'}];
  else if(resource==='read')result={};else throw Error('Unexpected '+resource);
  return{ok:true,status:200,json:async()=>({ok:true,result})};
 };
 for(const file of ['messenger-tools.js','messenger.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets/'+file),'utf8'));await tick();
 const q=s=>root.querySelector(s),tap=text=>{const scope=q('dialog')||root,b=[...scope.querySelectorAll('button')].find(x=>x.textContent===text);assert(b,'Missing '+text);b.click();};
 return{dom,w,root,q,tap,calls,set gate(v){gate=v;},set forged(v){forged=v;}};
}
(async()=>{
 {const f=await fixture();check(!!f.q('.MessengerHeaderActions'),'Saved shortcut fits existing header actions');f.tap('Saved messages');await tick();check(f.q('[data-message-id="7001"]')&&f.root.textContent.includes('Private weekend notes'),'explicit Saved opening loads ordinary private history');const call=f.calls.find(c=>c.resource==='saved-messages');check(call.options.method==='POST'&&call.body.csrf==='token'&&call.options.headers['X-Messenger-Actor']==='11','Saved web write retains CSRF and actor');check(![...f.q('[data-thread]').querySelectorAll('button')].some(b=>b.textContent==='Block'),'Saved has no block-yourself action');check(f.calls.filter(c=>c.resource==='inbox').every(c=>c.query.get('include_saved')==='1'),'Saved kind uses explicit inbox opt-in');f.tap('Saved messages');await tick();check(f.q('[data-message-id="7001"]'),'reopening same utility keeps history');f.dom.window.close();}
 {const f=await fixture();let release;f.gate=new Promise(r=>release=r);f.tap('Saved messages');await tick();f.tap('Close');release();await tick();check(!f.q('[data-message-id="7001"]'),'closing creation ignores a late acknowledgement');check(!f.calls.some(c=>c.resource==='conversation'),'late Saved acknowledgement does not navigate');f.dom.window.close();}
 {const f=await fixture();f.forged=true;f.tap('Saved messages');await tick();check(f.q('dialog').textContent.includes('Saved messages unavailable.'),'forged owner response rejected visibly');check(!f.calls.some(c=>c.resource==='conversation'),'forged Saved data never opens a conversation');f.dom.window.close();}
 console.log('Web Saved message checks: '+checks);
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
