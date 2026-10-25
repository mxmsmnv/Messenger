'use strict';
const {JSDOM}=require('./web-dom/node_modules/jsdom');
const fs=require('node:fs'),assert=require('node:assert/strict');
const tick=async()=>{for(let i=0;i<8;i++)await new Promise(setImmediate);};
let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
function fixture(){
 const dom=new JSDOM('<section><div id="thread"><div data-messages></div></div></section>',{url:'https://example.test',runScripts:'outside-only'}),w=dom.window,root=w.document.querySelector('section'),thread=root.querySelector('#thread');
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 const el=(tag,cls,text)=>{const n=w.document.createElement(tag);n.className=cls||'';if(text!==undefined)n.textContent=text;return n;};const button=text=>{const b=el('button','',text);b.type='button';return b;};
 const target={id:2,state:'active',request_state:'accepted',can_send:true,blocked:false,other_user:{name:'Carol <img src=x>'}},calls=[],refreshed=[];let fault=null,defer=null;
 const request=async(resource,options={},query={})=>{calls.push({resource,body:options.body&&JSON.parse(JSON.stringify(options.body)),query});if(resource==='inbox')return{items:[target,{...target,id:3,request_state:'pending'},{...target,id:4,blocked:true}],next_cursor:null};if(defer)await defer;if(fault)throw fault;return{conversation:{id:options.body.to_id},messages:options.body.message_ids.map((id,i)=>({id:10+i,conversation_id:options.body.to_id,sender_user_id:11,forwarded:true}))};};
 w.eval(fs.readFileSync(require('node:path').join(__dirname,'../assets/messenger-tools.js'),'utf8'));
 const api=w.ProcessWireMessengerTools({root,thread,request,el,button,clientId:()=> 'immutable-'+calls.length,memberId:()=>11,refresh:id=>refreshed.push(id),say:()=>{}});api.reset(1);
 function message(id=5,revision='b'.repeat(64)){const a=el('article');a.append(el('span','MessengerMessage-controls'));thread.querySelector('[data-messages]').append(a);api.attach(a,{id,body:'Original',content_revision:revision},1);return a;}
 const click=text=>{const n=[...root.querySelectorAll('button')].find(n=>n.textContent===text);assert(n,'Missing '+text);n.click();};
 return{dom,w,root,api,calls,refreshed,message,click,target,set fault(e){fault=e;},set defer(v){defer=v;}};
}
(async()=>{
 {const f=fixture();f.message();f.click('Forward');await tick();check(f.root.querySelectorAll('.MessengerForwardTargets>button').length===1,'only accepted unblocked destination offered');check(!f.root.querySelector('img'),'destination name remains plain text');f.click('Carol <img src=x>');check(!f.calls.some(c=>c.resource==='forward'),'explicit destination confirmation before write');f.click('Send');await tick();const p=f.calls.find(c=>c.resource==='forward').body;check(p.from_id===1&&p.to_id===2&&p.source_versions['5']==='b'.repeat(64),'displayed source identity and revision bound to write');check(f.refreshed[0]===2&&!f.root.querySelector('dialog'),'ack refreshes target without navigating');f.dom.window.close();}
 {const f=fixture();f.message();f.click('Forward');await tick();f.click('Carol <img src=x>');f.fault=Object.assign(Error('network lost'),{status:503});f.click('Send');await tick();check(f.root.textContent.includes('Sending is unconfirmed'),'ambiguous response has explicit retry UI');const p=f.calls.find(c=>c.resource==='forward').body;f.click('Close');f.message(6,'c'.repeat(64));[...f.root.querySelectorAll('article')].at(-1).querySelector('button').click();await tick();check(f.root.textContent.includes('1 messages'),'pending selection remains identified');f.fault=null;f.click('Retry sending');await tick();assert.deepEqual(f.calls.filter(c=>c.resource==='forward')[1].body,p);checks++;f.dom.window.close();}
 {const f=fixture();f.message();f.click('Forward');await tick();f.click('Carol <img src=x>');let release;f.defer=new Promise(r=>release=r);f.click('Send');f.click('Send');await tick();check(f.calls.filter(c=>c.resource==='forward').length===1,'double tap has one write');f.click('Close');release();await tick();check(!f.root.querySelector('dialog'),'late success never reopens dismissed chooser');f.dom.window.close();}
 {const f=fixture();f.message();f.message(6);f.root.querySelectorAll('input[type=checkbox]').forEach(c=>c.click());check(f.root.textContent.includes('2 selected'),'multi-selection is visible');f.click('Forward selected');await tick();f.click('Carol <img src=x>');f.click('Send');await tick();check(f.calls.find(c=>c.resource==='forward').body.message_ids.length===2,'multi-selection posts one atomic operation');f.dom.window.close();}
 {const f=fixture();const a=f.message(5,'');check(!a.querySelector('button')&&!a.querySelector('input'),'legacy message without revision has no unsafe forward action');f.dom.window.close();}
 {const f=fixture();f.message();f.click('Forward');await tick();f.click('Forward');await tick();check(f.root.querySelectorAll('dialog').length===1,'opening another chooser closes the old one');f.dom.window.close();}
 console.log('Web forwarding checks: '+checks);
})().catch(e=>{console.error(e);process.exitCode=1;});
