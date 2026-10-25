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
   function load(more){var epoch=++generation,before=more?cursor:null;status.textContent='Loading chats…';host.request('inbox',{}, {scope:folder,limit:30,include_groups:host.groups?'1':'0',before:before||''}).then(function(page){if(!d.isConnected||epoch!==generation)return;loaded=(more?loaded:[]).concat(page.items.filter(function(c){return c.state==='active'&&c.request_state==='accepted'&&!c.blocked&&c.can_send===true;}));loaded=loaded.filter(function(c,i,a){return a.findIndex(function(x){return x.id===c.id;})===i;});cursor=page.next_cursor&&page.next_cursor!==before?page.next_cursor:null;status.textContent='';render();}).catch(function(e){error(e);if(d.isConnected)body.append(button('Retry',function(){load(more);}));});}
   render();if(!pending)load(false);
  }
  return{attach:attach,reset:reset,redraw:redraw};
 };
})();
