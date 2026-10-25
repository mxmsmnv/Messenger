(function(){
 'use strict';
 window.ProcessWireMessengerActivity=function(host){
  var chat=null,epoch=0,typingUntil=0,recording=false,entries=[],nextRead=0,reading=false,readAbort=null,lastKind='none',lastWrite=0,writing=false,pending=null,line=null,dead=false;
  var share=false,key='MessengerActivity|'+host.root.dataset.api+'|'+host.memberId();
  try{var saved=localStorage.getItem(key);share=saved===null||saved==='1';}catch(e){share=false;}
  var label=host.el('label','MessengerActivityPreference'),check=host.el('input');check.type='checkbox';check.checked=share;label.append(check,host.el('span','','Share typing activity'));
  (host.root.querySelector('.MessengerApp-header')||host.root).append(label);
  check.addEventListener('change',function(){share=check.checked;try{localStorage.setItem(key,share?'1':'0');}catch(e){share=false;check.checked=false;}tick();});
  function eligible(){return chat&&chat.type!=='saved'&&chat.request_state==='accepted'&&!chat.blocked&&chat.can_send!==false;}
  function ensureLine(){var name=host.thread.querySelector('.MessengerIdentity h2');if(!name)return;if(!line||!line.isConnected){var wrap=host.el('div','MessengerActivityName');name.replaceWith(wrap);wrap.append(name);line=host.el('small','MessengerActivityLine');line.setAttribute('aria-live','polite');wrap.append(line);}}
  function paint(){entries=entries.filter(function(row){return row.until>Date.now();});if(!line||!line.isConnected)return;var first=entries[0],text='';if(first){var kind=first.kind==='recording'?'Recording voice…':'Typing…';text=chat&&chat.type==='group'?(first.title||first.name)+' · '+kind+(entries.length>1?' +'+(entries.length-1):''):kind;}if(line.textContent!==text)line.textContent=text;}
  function publish(id,kind){pending={id:id,kind:kind,epoch:epoch};drain();}
  function drain(){
   if(writing||!pending)return;var item=pending;pending=null;
   if(item.kind!=='none'&&(dead||item.epoch!==epoch||!eligible()||document.hidden||!share||chat.id!==item.id))return;
   writing=true;var abort=new AbortController(),timer=setTimeout(function(){abort.abort();},2000);
   host.request('activity',{method:'POST',body:{id:item.id,kind:item.kind},signal:abort.signal}).catch(function(){}).finally(function(){clearTimeout(timer);writing=false;drain();});
  }
  function stop(){epoch++;if(readAbort)readAbort.abort();readAbort=null;reading=false;entries=[];typingUntil=0;recording=false;nextRead=0;if(chat&&lastKind!=='none')publish(chat.id,'none');lastKind='none';lastWrite=0;paint();}
  function bind(value){
   var changed=(chat&&chat.id)!==(value&&value.id)||!!(chat&&chat.blocked)!==!!(value&&value.blocked)||(chat&&chat.request_state)!==(value&&value.request_state)||(chat&&chat.can_send)!==(value&&value.can_send);
   if(changed){stop();chat=value||null;}else chat=value||null;
   ensureLine();tick();
  }
  function valid(page,id){
   if(!page||!Array.isArray(page.items)||page.items.length>20)throw Error('Invalid activity.');var seen=new Set();
   return page.items.map(function(row){if(!Number.isSafeInteger(row.user_id)||row.user_id<=0||row.user_id===host.memberId()||seen.has(row.user_id)||typeof row.name!=='string'||!row.name||Array.from(row.name).length>120||typeof row.title!=='string'||Array.from(row.title).length>120||!['typing','recording'].includes(row.kind)||!Number.isInteger(row.expires_in)||row.expires_in<1||row.expires_in>8||(chat.type!=='group'&&row.user_id!==chat.other_user.id))throw Error('Invalid activity.');seen.add(row.user_id);return Object.assign({},row,{until:Date.now()+row.expires_in*1000});});
  }
  function tick(){
   if(dead)return;if(!host.root.isConnected){destroy();return;}
   paint();if(!eligible()||document.hidden){entries=[];paint();if(chat&&lastKind!=='none'){publish(chat.id,'none');lastKind='none';}return;}
   var now=Date.now(),kind=!share?'none':recording?'recording':now<typingUntil?'typing':'none';
   if(kind!==lastKind||(kind!=='none'&&now-lastWrite>=3000)){lastKind=kind;lastWrite=now;publish(chat.id,kind);}
   if(reading||now<nextRead)return;reading=true;nextRead=now+2500;var ticket=epoch,id=chat.id,abort=new AbortController();readAbort=abort;var timer=setTimeout(function(){abort.abort();},2000);
   host.request('activity',{signal:abort.signal},{id:id}).then(function(page){if(dead||ticket!==epoch||!chat||chat.id!==id||document.hidden)return;entries=valid(page,id);paint();}).catch(function(){}).finally(function(){clearTimeout(timer);if(ticket===epoch){reading=false;readAbort=null;}});
  }
  function input(event){if(event.target.matches('[data-composer] textarea')&&eligible()&&share){typingUntil=event.target.value.trim()?Date.now()+4000:0;tick();}}
  function visible(){if(document.hidden){var wasRecording=recording;stop();recording=wasRecording;}else tick();}
  function pagehide(event){if(event.persisted)stop();else destroy();}
  function pageshow(){tick();}
  function destroy(){if(dead)return;stop();dead=true;clearInterval(timer);host.root.removeEventListener('input',input);document.removeEventListener('visibilitychange',visible);window.removeEventListener('pagehide',pagehide);window.removeEventListener('pageshow',pageshow);label.remove();}
  host.root.addEventListener('input',input);document.addEventListener('visibilitychange',visible);window.addEventListener('pagehide',pagehide);window.addEventListener('pageshow',pageshow);var timer=setInterval(tick,250);
  return{bind:bind,stopTyping:function(id){if(chat&&chat.id===id){typingUntil=0;tick();}},recording:function(id,value){if(chat&&chat.id===id){recording=!!value;if(recording)typingUntil=0;tick();}},destroy:destroy};
 };
})();
