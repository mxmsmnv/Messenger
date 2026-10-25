'use strict';
const {JSDOM}=require('./web-dom/node_modules/jsdom');const fs=require('node:fs'),assert=require('node:assert/strict');
const tick=async()=>{for(let i=0;i<8;i++)await new Promise(setImmediate);};let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
function fixture(mine=false){
 const dom=new JSDOM('<section><div id="thread"><div data-messages></div></div></section>',{url:'https://example.test',runScripts:'outside-only'}),w=dom.window,root=w.document.querySelector('section'),thread=root.querySelector('#thread');
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 const el=(tag,cls,text)=>{const n=w.document.createElement(tag);n.className=cls||'';if(text!==undefined)n.textContent=text;return n;},button=text=>{const b=el('button','',text);b.type='button';return b;};
 const calls=[],refresh=[];let deferred=null,fail=false,allowed=true;const request=async(resource,options)=>{calls.push({resource,body:JSON.parse(JSON.stringify(options.body))});if(deferred)await deferred;if(fail)throw Error('Temporary failure');return{id:5,conversation_id:1,reactions:[]};};
 w.eval(fs.readFileSync(require('node:path').join(__dirname,'../assets/messenger-tools.js'),'utf8'));const api=w.ProcessWireMessengerTools({root,thread,el,button,request,reactions:true,canReact:()=>allowed,memberId:()=>11,clientId:()=> 'test',say:()=>{},refresh:id=>refresh.push(id)});api.reset(1);
 const item={id:5,conversation_id:1,body:'Original',reactions:[{emoji:'👍',count:1,mine,users:[{id:mine?11:22,name:'<img src=x>'}]}]},article=el('article');const bubble=el('div','MessengerBubble'),controls=el('span','MessengerMessage-controls');bubble.append(controls);article.append(bubble);thread.querySelector('[data-messages]').append(article);api.attach(article,item,1);
 const click=text=>{const b=[...root.querySelectorAll('button')].find(b=>b.textContent===text);assert(b,'Missing '+text);b.click();};
 return{dom,w,root,click,calls,refresh,item,api,article,set deferred(v){deferred=v;},set fail(v){fail=v;},set allowed(v){allowed=v;}};
}
(async()=>{
 {const f=fixture();f.click('React');check(f.root.textContent.includes('<img src=x>')&&!f.root.querySelector('img'),'reactor names are text, never markup');f.click('❤️');await tick();check(f.calls[0].resource==='reaction'&&f.calls[0].body.emoji==='❤️'&&f.calls[0].body.message_id===5,'picker sends exact desired emoji and message');check(Object.keys(f.calls[0].body).length===2,'body contains no user authority or message content');check(f.refresh[0]===1&&!f.root.querySelector('dialog'),'ack refreshes actual source and closes picker');f.dom.window.close();}
 {const f=fixture(true);f.click('👍 1');check(f.root.querySelector('dialog button[aria-pressed=true]').textContent==='👍','current own reaction has explicit selected semantics');f.click('👍');await tick();check(f.calls[0].body.emoji===null,'choosing own emoji explicitly clears it, rather than server toggle');f.dom.window.close();}
 {const f=fixture();let resolve;f.deferred=new Promise(r=>resolve=r);f.click('React');f.click('😂');f.click('😂');check(f.calls.length===1,'double click never queues another reaction');f.click('Close');resolve();await tick();check(!f.root.querySelector('dialog'),'late success cannot reopen dismissed picker');f.dom.window.close();}
 {const f=fixture();f.fail=true;f.click('React');f.click('❤️');await tick();check(f.root.textContent.includes('Temporary failure'),'failure stays visible and never reports success');f.fail=false;f.click('❤️');await tick();assert.deepEqual(f.calls[0].body,f.calls[1].body);checks++;f.dom.window.close();}
 {const f=fixture();f.allowed=false;f.click('👍 1');check([...f.root.querySelectorAll('dialog button[aria-pressed]')].every(b=>b.disabled),'blocked/read-only view never enables reaction mutation');f.dom.window.close();}
 console.log('Web reaction checks: '+checks);
})().catch(e=>{console.error(e);process.exitCode=1;});
