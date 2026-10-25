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
  if(resource==='session')result={can_use:true,user_id:11,history_search_supported:true,media_catalog_supported:true,activity_supported:true,csrf:{name:'csrf',value:'token'}};
  else if(resource==='inbox')result={items:[chat],pending_request_count:0,next_cursor:null};
  else if(resource==='conversation')result=chat;
  else if(resource==='messages'){if(u.searchParams.get('before')==='6'){if(jump)await jump;result=[message(5,'Cabin plans from last spring')];}else result=[message(100,'Latest message')];}
  else if(resource==='read')result={};
  else if(resource==='send')result={...message(200,JSON.parse(options.body).body),sender_user_id:11};
  else if(resource==='activity')result=options.method==='POST'?{updated:true}:{items:[{user_id:22,name:'emma',title:'Emma',kind:'typing',expires_in:8}]};
  else if(resource==='media-catalog'){
   if(pending)await pending;
   const category=u.searchParams.get('category'),file=(id,name,mime,kind='file')=>({...message(id,name),attachment:{id:100+id,filename:name,mime_type:mime,size:20,kind}});
   result={category:bad?'invalid':category,items:category==='visual'?(u.searchParams.get('before')?[file(5,'Lake.png','image/png')]:[]):category==='documents'?[file(6,'Weekend notes.pdf','application/pdf')]:category==='audio'?[file(7,'Voice.wav','audio/wav','voice')]:[message(8,'Cabin https://example.test/plan')],next_before:category==='visual'&&!u.searchParams.get('before')?100:null,scanned:category==='visual'&&!u.searchParams.get('before')?500:25,complete:category!=='visual'||!!u.searchParams.get('before')};
  }
  else if(resource==='search-history'){if(pending)await pending;result=bad?{items:[],next_before:100,scanned:500,complete:false}:u.searchParams.get('before')?{items:[message(5,'Cabin plans from last spring')],next_before:null,scanned:95,complete:true}:{items:[],next_before:100,scanned:500,complete:false};}
  else throw Error('Unexpected '+resource);
  return{ok:true,status:200,json:async()=>({ok:true,result})};
 };
 for(const file of ['messenger-tools.js','messenger-activity.js','messenger.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets/'+file),'utf8'));await tick();root.querySelector('[data-conversation-list] [data-id="1"]').click();await tick();
 const q=s=>root.querySelector(s),tap=text=>{const scope=q('dialog')||root,b=[...scope.querySelectorAll('button')].find(x=>x.textContent===text);assert(b,'Missing '+text);b.click();};
 const input=value=>{q('dialog input').value=value;q('dialog input').dispatchEvent(new w.Event('input',{bubbles:true}));};
 return{dom,w,root,q,tap,input,calls,scrolled,set pending(v){pending=v;},set jump(v){jump=v;},set bad(v){bad=v;}};
}
(async()=>{
 const f=await fixture();check(f.q('.MessengerActivityLine').textContent==='Typing…','real frontend binds peer status');const area=f.q('[data-composer] textarea');area.value='A private draft';area.dispatchEvent(new f.w.Event('input',{bubbles:true}));await tick();const write=f.calls.filter(c=>c.resource==='activity'&&c.options.method==='POST').at(-1);const body=JSON.parse(write.options.body);check(body.kind==='typing'&&body.id===1&&!write.options.body.includes('A private draft'),'real composer sends metadata without draft');check(write.options.headers['X-Messenger-Actor']==='11'&&body.csrf==='token','normal actor and CSRF wrapper retained');
 f.q('[data-composer]').dispatchEvent(new f.w.Event('submit',{bubbles:true,cancelable:true}));await tick();check(JSON.parse(f.calls.filter(c=>c.resource==='activity'&&c.options.method==='POST').at(-1).options.body).kind==='none','real Send clears typing');check(f.q('[data-message-id=\"200\"]'),'real send acknowledgement remains usable');
 const box=f.q('.MessengerActivityPreference input');box.checked=false;box.dispatchEvent(new f.w.Event('change'));await tick();check(JSON.parse(f.calls.filter(c=>c.resource==='activity'&&c.options.method==='POST').at(-1).options.body).kind==='none','real privacy control clears state');f.q('[data-action="back"]').click();await tick();check(f.q('.MessengerActivityLine').textContent==='','back clears status');f.dom.window.dispatchEvent(new f.w.Event('pagehide'));f.dom.window.close();console.log('Web activity integration checks: '+checks);
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
