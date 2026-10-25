(function(){
 'use strict';
 window.ProcessWireMessengerTools=function(host){
  var pending=null,inFlight=false,selected=new Map(),source=0,toolbar=null,activeDialog=null;
  function button(label,fn){var b=host.button(label,'','MessengerMessage-action','button_tertiary');b.addEventListener('click',fn);return b;}
  function reset(id){if(source!==id){selected.clear();source=id;}toolbar=null;}
  function redraw(){
   if(toolbar)toolbar.remove();toolbar=null;
   if(!selected.size)return;
   toolbar=host.el('div','MessengerForwardSelection');toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Selected messages');
   toolbar.append(host.el('span','',selected.size+' selected'),button('Forward selected',function(){choose(source,Array.from(selected.values()));}),button('Clear selection',function(){selected.clear();host.thread.querySelectorAll('[data-forward-select]').forEach(function(n){n.checked=false;});redraw();}));
   var messages=host.thread.querySelector('[data-messages]');if(messages)messages.before(toolbar);
  }
  var pinBusy=new Set();
  function pinWrite(resource,payload,d,done){
   var key=resource+':'+payload.id+':'+(payload.message_id||0)+':'+(payload.scope||'');if(pinBusy.has(key)||(d&&d.node.getAttribute('aria-busy')==='true'))return;
   pinBusy.add(key);var controls=d?Array.from(d.node.querySelectorAll('input,button')).filter(function(n){return n.textContent!=='Close';}).map(function(n){return[n,n.disabled];}):[];controls.forEach(function(c){c[0].disabled=true;});if(d){d.node.setAttribute('aria-busy','true');d.status.textContent='Saving…';}
   var abort=new AbortController(),timer=setTimeout(function(){abort.abort();},30000);
   return host.request(resource,{method:'POST',body:payload,signal:abort.signal}).then(function(result){
    if(!result||result.id!==payload.id||result.pinned!==payload.pinned||(resource==='pin-message'&&(result.message_id!==payload.message_id||result.scope!==payload.scope)))throw Error('The site did not confirm the pin.');
    host.pinRefresh(payload.id);if(done&&(!d||d.node.isConnected))done();
   }).catch(function(e){if(d&&d.node.isConnected)d.status.textContent=e.message;else host.say(e.message,true);}).finally(function(){clearTimeout(timer);pinBusy.delete(key);controls.forEach(function(c){c[0].disabled=c[1];});if(d)d.node.removeAttribute('aria-busy');});
  }
  function pinPreview(item){var text=String(item.attachment?item.attachment.filename:item.body||''),points=Array.from(text);return points.length>200?points.slice(0,200).join('')+'…':text;}
  function pinDialog(title){
   var node=host.el('dialog','MessengerDialog MessengerGroupDialog',undefined,'dialog'),head=host.el('header','MessengerGroupHeader'),body=host.el('div','MessengerGroupBody'),status=host.el('p','MessengerState');status.setAttribute('role','status');head.append(host.el('h2','',title),button('Close',function(){node.close();}));node.append(head,body,status);host.root.append(node);node.addEventListener('close',function(){node.remove();});node.showModal();return{node:node,body:body,status:status};
  }
  function pinsRead(d,id,done){d.status.textContent='Loading…';return host.request('pins',{}, {id:id}).then(function(result){if(!result||!Array.isArray(result.items)||result.items.length>10)throw Error('Invalid pinned messages.');if(d.node.isConnected){d.status.textContent='';done(result);}}).catch(function(e){if(d.node.isConnected){d.status.textContent=e.message;d.body.append(button('Retry',function(){d.body.replaceChildren();pinsRead(d,id,done);}));}});}
  function pinMessage(item,id){
   var d=pinDialog('Pin message');d.body.append(host.el('p','',pinPreview(item)));
   pinsRead(d,id,function(result){
    var check=host.el('input'),label=host.el('label','MessengerForwardCheck');check.type='checkbox';label.append(check,host.el('span','','For everyone'));if(result.can_pin_shared)d.body.append(label);else check.disabled=true;
    var state=host.el('p','','Only for me'),send=button('',function(){var which=check.checked?'everyone':'personal',exists=result.items.some(function(p){return p.scope===which&&p.message.id===item.id;});pinWrite('pin-message',{id:id,message_id:item.id,scope:which,pinned:!exists},d,function(){d.node.close();});});
    function redraw(){var which=check.checked?'everyone':'personal';state.textContent=check.checked?'For everyone. With five shared pins, a new one replaces the oldest.':'Only for me';send.textContent=result.items.some(function(p){return p.scope===which&&p.message.id===item.id;})?'Unpin':'Pin';}
    check.addEventListener('change',redraw);redraw();d.body.append(state,send);
   });
  }
  function pinList(id){var d=pinDialog('Pinned messages');pinsRead(d,id,function(result){
   if(!result.items.length)d.body.append(host.el('p','','No pinned messages.'));
   result.items.forEach(function(pin){var row=host.el('section','MessengerPinRow');row.append(host.el('small','',pin.scope==='personal'?'Only for me':'For everyone'),host.el('p','',pinPreview(pin.message)),button('Go to message',function(){d.status.textContent='Loading…';host.jumpPinned(id,pin.message.id).then(function(ok){if(ok&&d.node.isConnected)d.node.close();}).catch(function(e){if(d.node.isConnected)d.status.textContent=e.message;});}));
    if(pin.scope==='personal'||result.can_pin_shared)row.append(button('Unpin',function(){pinWrite('pin-message',{id:id,message_id:pin.message.id,scope:pin.scope,pinned:false},d,function(){d.node.close();pinList(id);});}));d.body.append(row);
   });
  });}
  function openSaved(){
   var d=pinDialog('Saved messages'),busy=false,controller=null,timer=null,retry=null;
   d.body.append(host.el('p','','Personal notes, links and files on your website.'));
   d.node.addEventListener('close',function(){if(controller)controller.abort();clearTimeout(timer);});
   function load(){
    if(busy)return;busy=true;if(retry)retry.remove();d.status.textContent='Loading…';controller=new AbortController();timer=setTimeout(function(){controller.abort();},30000);
    host.request('saved-messages',{method:'POST',body:{},signal:controller.signal}).then(function(chat){
     if(!d.node.isConnected)return;
     if(!chat||chat.type!=='saved'||!Number.isSafeInteger(chat.id)||chat.id<=0||chat.requester_id!==host.memberId()||chat.recipient_id!==host.memberId()||!chat.other_user||chat.other_user.id!==host.memberId())throw Error('Saved messages unavailable.');
     d.node.close();host.openConversation(chat.id);host.pinRefresh(chat.id);
    }).catch(function(e){if(d.node.isConnected){d.status.textContent=e.name==='AbortError'?'Could not open Saved messages. Try again.':e.message;retry=button('Retry',load);d.body.append(retry);}}).finally(function(){busy=false;clearTimeout(timer);});
   }
   load();
  }
  if(host.saved){
   var existing=host.root.querySelector('[data-messenger-new]');if(existing){var actions=host.el('div','MessengerHeaderActions');existing.before(actions);actions.append(existing,button('Saved messages',openSaved));}
  }
  function historySearch(id,catalog){
   var d=pinDialog(catalog?'Chat media':'Search entire chat history'),input=host.el('input'),results=host.el('div','MessengerHistoryResults'),pager=host.el('div','MessengerGroupActions');
   input.type='search';input.maxLength=256;input.placeholder='Search this chat';input.setAttribute('aria-label','Search this chat');
   var busy=false,epoch=0,controller=null,timer=null,next=null,current=null,trail=[],sawMatches=false,category='visual',tabs=null;
   function validQuery(){var length=Array.from(input.value.trim()).length;return length<=128&&(length>=2||(catalog&&length===0));}
   var search=button('Search',function(){run(null,false,false);});search.disabled=!catalog;
   function stop(){epoch++;if(controller)controller.abort();controller=null;clearTimeout(timer);busy=false;search.disabled=!validQuery();}
   input.addEventListener('input',function(){stop();next=null;current=null;trail=[];sawMatches=false;results.replaceChildren();pager.replaceChildren();d.status.textContent='';});
   input.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();run(null,false,false);}});
   d.node.addEventListener('close',stop);
   if(catalog){tabs=host.el('div','MessengerCatalogTabs');tabs.setAttribute('role','group');tabs.setAttribute('aria-label','Media categories');[['visual','Photos and videos'],['documents','Files'],['audio','Audio'],['links','Links']].forEach(function(pair){var tab=button(pair[1],function(){stop();category=pair[0];next=null;current=null;trail=[];sawMatches=false;results.replaceChildren();tabs.querySelectorAll('button').forEach(function(b){b.setAttribute('aria-pressed',String(b===tab));});run(null,false,false);});tab.setAttribute('aria-pressed',String(pair[0]===category));tabs.append(tab);});d.body.append(tabs);}
   d.body.append(input,search,results,pager);
   function run(cursor,older,previous){
    if(busy)return;var query=input.value.trim();if(!validQuery())return;
    busy=true;search.disabled=true;var ticket=++epoch;controller=new AbortController();timer=setTimeout(function(){controller.abort();},30000);d.status.textContent='Searching…';pager.replaceChildren();
    var args={id:id,q:query,limit:30},requestedCategory=category;if(catalog)args.category=requestedCategory;if(cursor!==null)args.before=cursor;
    host.request(catalog?'media-catalog':'search-history',{signal:controller.signal},args).then(function(page){
     if(ticket!==epoch||!d.node.isConnected)return;
     if(catalog&&(!page||page.category!==requestedCategory))throw Error('Invalid media category.');
     if(!page||!Array.isArray(page.items)||page.items.length>30||!Number.isInteger(page.scanned)||page.scanned<page.items.length||page.scanned>500||typeof page.complete!=='boolean'||page.complete!==(page.next_before===null))throw Error('Invalid history page.');
     var last=cursor===null?Number.MAX_SAFE_INTEGER:cursor,seen=new Set();page.items.forEach(function(item){if(!Number.isSafeInteger(item.id)||item.id<=0||item.id>=last||item.conversation_id!==id||item.deleted_at||seen.has(item.id))throw Error('Invalid history result.');seen.add(item.id);last=item.id;});
     if(page.next_before!==null&&(!Number.isSafeInteger(page.next_before)||page.next_before<=0||page.scanned===0||(cursor!==null&&page.next_before>=cursor)||page.items.some(function(m){return m.id<page.next_before;})))throw Error('Invalid history cursor.');
     if(previous)trail.pop();else if(older)trail=trail.concat([current]).slice(-50);else trail=[];
     current=cursor;next=page.next_before;sawMatches=older||previous?sawMatches||page.items.length>0:page.items.length>0;
     results.replaceChildren();page.items.forEach(function(item){var row=button(pinPreview(item),function(){var active=host.currentChat();if(busy||!active||active.id!==id)return;busy=true;search.disabled=true;var jumpTicket=++epoch;controller=new AbortController();timer=setTimeout(function(){controller.abort();},30000);d.status.textContent='Loading…';pager.replaceChildren(button('Cancel',cancelSearch));host.jumpPinned(id,item.id,function(){return d.node.isConnected&&jumpTicket===epoch;},controller.signal).then(function(ok){if(ok)d.node.close();}).catch(function(e){if(jumpTicket===epoch&&d.node.isConnected)d.status.textContent=e.name==='AbortError'?'Search cancelled. Try again.':e.message;}).finally(function(){if(jumpTicket===epoch&&d.node.isConnected){clearTimeout(timer);busy=false;search.disabled=false;idlePager();}});});if(host.formatDate)row.append(host.el('small','',host.formatDate(item.created_at,item.created_at_iso)));results.append(row);});
     d.status.textContent='Messages checked: '+page.scanned+(page.complete?(sawMatches?' · Done':' · No matches'):'');
    }).catch(function(error){if(ticket===epoch&&d.node.isConnected)d.status.textContent=error.name==='AbortError'?'Search cancelled. Try again.':error.message;}).finally(function(){
     if(ticket!==epoch||!d.node.isConnected)return;clearTimeout(timer);busy=false;search.disabled=false;pager.replaceChildren();
     idlePager();
    });
    pager.append(button('Cancel',cancelSearch));
   }
   function idlePager(){pager.replaceChildren();if(trail.length)pager.append(button('Previous results',function(){run(trail[trail.length-1],false,true);}));if(next!==null)pager.append(button('Search older messages',function(){run(next,true,false);}));}
   function cancelSearch(){stop();d.status.textContent='Search cancelled.';idlePager();}
   if(catalog)run(null,false,false);else input.focus();
  }
  function threadActions(chat,actions){if(host.catalog)actions.append(button('Chat media',function(){historySearch(chat.id,true);}));if(host.historySearch)actions.append(button('Search chat history',function(){historySearch(chat.id);}));if(!host.pins||chat.request_state!=='accepted')return;var b=button(chat.pinned_at?'Unpin chat':'Pin chat',function(){var current=host.currentChat();if(current&&current.id===chat.id)pinWrite('pin-chat',{id:chat.id,pinned:!current.pinned_at});});b.dataset.chatPin='';actions.append(b,button('Pinned messages',function(){pinList(chat.id);}));}
  function threadPinLabel(chat){var toggle=host.thread.querySelector('[data-chat-pin]');if(toggle)toggle.textContent=chat.pinned_at?'Unpin chat':'Pin chat';}
  function threadPins(chat){
   if(!host.pins||chat.request_state!=='accepted')return;
   var old=host.thread.querySelector('[data-pin-bar]');if(old)old.remove();var bar=host.el('div','MessengerPinsBar');bar.dataset.pinBar='';var list=host.thread.querySelector('[data-messages]');if(!list)return;list.before(bar);
   threadPinLabel(chat);
   host.request('pins',{}, {id:chat.id}).then(function(result){if(!bar.isConnected||!result.items||!result.items.length)return;var first=result.items[0].message;bar.append(button('Pinned messages ('+result.items.length+'): '+(pinPreview(first)),function(){pinList(chat.id);}));}).catch(function(){/* Existing chat navigation/composer stays usable offline. */});
  }
  var reactionBusy=new Set(),emojiList=['👍','❤️','😂','😮','😢','🙏'];
  function reactionPicker(item,chatId){
   var d=host.el('dialog','MessengerDialog MessengerGroupDialog',undefined,'dialog'),head=host.el('header','MessengerGroupHeader'),body=host.el('div','MessengerGroupBody'),status=host.el('p','MessengerState');status.setAttribute('role','status');
   head.append(host.el('h2','','Reactions'),button('Close',function(){d.close();}));d.append(head,body,status);host.root.append(d);d.addEventListener('close',function(){d.remove();});d.showModal();
   var reactions=Array.isArray(item.reactions)?item.reactions.slice(0,6):[],choices=host.el('div','MessengerForwardFolders');
   emojiList.forEach(function(emoji){var own=reactions.some(function(r){return r.emoji===emoji&&r.mine;});var b=button(emoji,function(){
    if(reactionBusy.has(item.id)||(host.canReact&&!host.canReact(chatId)))return;reactionBusy.add(item.id);d.setAttribute('aria-busy','true');status.textContent='Saving…';var abort=new AbortController(),timer=setTimeout(function(){abort.abort();},30000);
    host.request('reaction',{method:'POST',body:{message_id:item.id,emoji:own?null:emoji},signal:abort.signal}).then(function(result){
     if(!result||result.id!==item.id||result.conversation_id!==chatId||!Array.isArray(result.reactions)||result.reactions.length>6)throw Error('The site did not confirm the reaction.');
     host.refresh(chatId);if(d.isConnected)d.close();
    }).catch(function(e){if(d.isConnected)status.textContent=e.message;}).finally(function(){clearTimeout(timer);reactionBusy.delete(item.id);d.removeAttribute('aria-busy');});
   });b.setAttribute('aria-pressed',String(own));b.disabled=!!host.canReact&&!host.canReact(chatId);choices.append(b);});body.append(choices);
   var authors=host.el('div','MessengerForwardTargets');reactions.forEach(function(r){(r.users||[]).slice(0,100).forEach(function(u){authors.append(host.el('p','',r.emoji+' '+(u.title||u.name)));});});body.append(authors);
  }
  function attachReactions(article,item,chatId){
   if(!host.reactions||item.deleted_at||!Number.isSafeInteger(item.id)||item.id<1)return;
   var controls=article.querySelector('.MessengerMessage-controls'),bubble=article.querySelector('.MessengerBubble');if(!controls||!bubble)return;
   if(!host.canReact||host.canReact(chatId))controls.append(button('React',function(){reactionPicker(item,chatId);}));
   var strip=host.el('div','MessengerReactionStrip');(item.reactions||[]).slice(0,6).forEach(function(r){if(!emojiList.includes(r.emoji))return;var b=button(r.emoji+' '+r.count,function(){reactionPicker(item,chatId);});b.setAttribute('aria-pressed',String(!!r.mine));strip.append(b);});if(strip.childNodes.length)bubble.append(strip);
  }
  function attach(article,item,chatId){
   attachReactions(article,item,chatId);
   if(host.pins&&(!host.canPin||host.canPin(chatId))&&!item.deleted_at&&Number.isSafeInteger(item.id)&&item.id>0){var c=article.querySelector('.MessengerMessage-controls');if(c)c.append(button('Pin',function(){pinMessage(item,chatId);}));}
   if(!Number.isSafeInteger(item.id)||item.id<1||item.deleted_at||!/^[a-f0-9]{64}$/.test(item.content_revision||''))return;
   var controls=article.querySelector('.MessengerMessage-controls');if(!controls)return;
   var label=host.el('label','MessengerForwardCheck'),check=host.el('input');check.type='checkbox';check.dataset.forwardSelect='';check.checked=selected.has(item.id);check.setAttribute('aria-label','Select message '+item.id);label.append(check,host.el('span','','Select'));
   check.addEventListener('change',function(){if(source!==chatId){selected.clear();source=chatId;}if(check.checked){if(selected.size>=10){check.checked=false;host.say('Choose up to ten messages.',true);return;}selected.set(item.id,Object.assign({},item));}else selected.delete(item.id);redraw();});
   controls.append(label,button('Forward',function(){choose(chatId,[Object.assign({},item)]);}));
  }
  function choose(from,messages){
   if(inFlight){host.say('Forwarding is still in progress.');return;}
   if(activeDialog&&activeDialog.isConnected)activeDialog.close();
   var snapshot=messages.slice().sort(function(a,b){return a.id-b.id;});if(!snapshot.length||snapshot.length>10)return;
   var d=host.el('dialog','MessengerDialog MessengerGroupDialog',undefined,'dialog'),header=host.el('header','MessengerGroupHeader'),body=host.el('div','MessengerGroupBody'),status=host.el('p','MessengerState');status.setAttribute('role','status');
   activeDialog=d;
   header.append(host.el('h2','','Forward messages'),button('Close',function(){d.close();}));d.append(header,body,status);host.root.append(d);d.addEventListener('close',function(){d.remove();});d.showModal();
   function error(e){if(d.isConnected)status.textContent=e.message||'Could not forward messages.';}
   function send(target){
    if(inFlight)return;
    var previous=!!pending;
    if(!pending){var versions={};snapshot.forEach(function(m){versions[m.id]=m.content_revision;});pending={from_id:from,to_id:target.id,message_ids:snapshot.map(function(m){return m.id;}),source_versions:versions,client_id:host.clientId(),name:target.other_user.title||target.other_user.name};}
    var draft=pending;inFlight=true;d.setAttribute('aria-busy','true');status.textContent='Sending…';
    var payload={from_id:draft.from_id,to_id:draft.to_id,message_ids:draft.message_ids,source_versions:draft.source_versions,client_id:draft.client_id};
    var abort=new AbortController(),timer=setTimeout(function(){abort.abort();},30000);
    host.request('forward',{method:'POST',body:payload,signal:abort.signal}).then(function(result){
     if(!result||!result.conversation||result.conversation.id!==draft.to_id||!Array.isArray(result.messages)||result.messages.length!==draft.message_ids.length||new Set(result.messages.map(function(m){return m.id;})).size!==draft.message_ids.length||result.messages.some(function(m){return !Number.isSafeInteger(m.id)||m.id<1||m.conversation_id!==draft.to_id||m.sender_user_id!==host.memberId()||(!m.forwarded&&!m.deleted_at);}))throw Error('The site did not confirm forwarding.');
     if(pending===draft)pending=null;selected.clear();redraw();host.refresh(draft.to_id);if(d.isConnected)d.close();
    }).catch(function(e){if(!previous&&e.status>=400&&e.status<500&&e.status!==408&&e.status!==429)pending=null;error(e);if(d.isConnected)render();}).finally(function(){clearTimeout(timer);inFlight=false;d.removeAttribute('aria-busy');});
   }
   var folder='inbox',cursor=null,loaded=[],generation=0;
   function render(){
    body.replaceChildren();
    if(pending){body.append(host.el('strong','',pending.name),host.el('p','',pending.message_ids.length+' messages · Sending is unconfirmed. Retrying avoids duplicates. Stopping retries does not delete anything already sent.'),button('Retry sending',function(){send({id:pending.to_id});}),button('Stop retrying',function(){if(inFlight)return;pending=null;d.close();}));return;}
    var tabs=host.el('div','MessengerForwardFolders'),query=host.el('input','',undefined,'input'),results=host.el('div','MessengerForwardTargets');query.type='search';query.placeholder='Find a chat';query.setAttribute('aria-label','Find a destination chat');
    ['inbox','archived'].forEach(function(f){var b=button(f==='inbox'?'Chats':'Archived',function(){if(inFlight)return;folder=f;cursor=null;loaded=[];load(false);});b.setAttribute('aria-pressed',String(f===folder));tabs.append(b);});body.append(tabs,query,results);
    function rows(){results.replaceChildren();loaded.filter(function(c){return (c.other_user.title||c.other_user.name).toLowerCase().includes(query.value.toLowerCase());}).forEach(function(c){var b=button(c.other_user.title||c.other_user.name,function(){if(inFlight)return;var confirm=host.el('div','MessengerForwardConfirm');confirm.append(host.el('strong','',c.other_user.title||c.other_user.name),host.el('p','',snapshot.length+' messages'),button('Send',function(){send(c);}),button('Cancel',function(){confirm.remove();}));results.replaceChildren(confirm);});results.append(b);});if(cursor)results.append(button('Load more chats',function(){load(true);}));if(!loaded.length)results.append(host.el('p','','No eligible chats.'));}
    query.addEventListener('input',rows);rows();
   }
   function load(more){var epoch=++generation,before=more?cursor:null;status.textContent='Loading chats…';host.request('inbox',{}, {scope:folder,limit:30,include_groups:host.groups?'1':'0',include_saved:host.saved?'1':'0',before:before||''}).then(function(page){if(!d.isConnected||epoch!==generation)return;loaded=(more?loaded:[]).concat(page.items.filter(function(c){return c.state==='active'&&c.request_state==='accepted'&&!c.blocked&&c.can_send===true;}));loaded=loaded.filter(function(c,i,a){return a.findIndex(function(x){return x.id===c.id;})===i;});cursor=page.next_cursor&&page.next_cursor!==before?page.next_cursor:null;status.textContent='';render();}).catch(function(e){error(e);if(d.isConnected)body.append(button('Retry',function(){load(more);}));});}
   render();if(!pending)load(false);
  }
  return{attach:attach,reset:reset,redraw:redraw,threadActions:threadActions,threadPins:threadPins,threadPinLabel:threadPinLabel};
 };
})();
