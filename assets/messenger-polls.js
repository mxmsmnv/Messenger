(function(){'use strict';
 window.ProcessWireMessengerPolls=function(host){
  var el=host.el,pending=null,busy=new Set();
  function button(label,fn){var b=host.button(label,'','MessengerMessage-action','button_secondary');b.addEventListener('click',fn);return b;}
  function valid(p,m){return p&&m&&Number.isSafeInteger(p.message_id)&&p.message_id>0&&Number.isSafeInteger(p.created_by)&&p.created_by>0&&p.message_id===m.id&&p.created_by===m.sender_user_id&&p.anonymous===true&&Array.isArray(p.options)&&p.options.length>=2&&p.options.length<=8&&p.options.every(function(o){return typeof o==='string'&&o.trim()===o&&Array.from(o).length>0&&Array.from(o).length<=100&&!/[\x00-\x1f\x7f]/.test(o);})&&new Set(p.options.map(function(o){return o.toLowerCase();})).size===p.options.length&&Array.isArray(p.counts)&&p.counts.length===p.options.length&&p.counts.every(function(n){return Number.isInteger(n)&&n>=0&&n<=100;})&&Number.isInteger(p.total)&&p.total>=0&&p.total<=100&&p.counts.reduce(function(a,b){return a+b;},0)===p.total&&(p.own_choice===null||(Number.isInteger(p.own_choice)&&p.own_choice>=0&&p.own_choice<p.options.length&&p.counts[p.own_choice]>0))&&(p.closed_at===null||(Number.isSafeInteger(p.closed_at)&&p.closed_at>0));}
  function allowed(id){var c=host.currentChat();return Number.isSafeInteger(id)&&id>0&&c&&c.id===id&&c.type==='group'&&c.request_state==='accepted'&&!c.blocked&&c.can_send!==false;}
  function dialog(title){var node=el('dialog','MessengerDialog',undefined,'dialog'),heading=el('h2','',title),status=el('p','MessengerState');node.append(heading);host.root.append(node);var close=function(){node.close();node.remove();};node.addEventListener('cancel',function(e){e.preventDefault();close();});node.showModal();return{node:node,status:status,close:close};}
  function change(message,choice,close){
   var chat=host.currentChat(),p=message.poll;if(!allowed(message.conversation_id)||!valid(p,message)||busy.has(message.id))return;
   if(close&&p.created_by!==host.memberId()&&!['owner','admin'].includes(chat.role))return;
   if(!close&&p.closed_at!==null)return;
   function write(d){
    if(!allowed(message.conversation_id)||busy.has(message.id))return;
    busy.add(message.id);if(d){d.status.textContent='Saving…';d.node.querySelectorAll('button').forEach(function(b){b.disabled=true;});}
    var payload={message_id:message.id};if(!close)payload.choice=choice;
    var abort=new AbortController(),timer=setTimeout(function(){abort.abort();},30000);
    return host.request(close?'group-poll-close':'group-poll-vote',{method:'POST',body:payload,signal:abort.signal}).then(function(result){
     if(!valid(result,message)||JSON.stringify(result.options)!==JSON.stringify(p.options)||(close?result.closed_at===null:result.own_choice!==choice))throw Error('The site did not confirm the poll action.');
     if(d&&d.node.isConnected)d.close();if(allowed(message.conversation_id))host.refresh(message.conversation_id);
    }).catch(function(e){if(d&&d.node.isConnected)d.status.textContent=e.message;else host.say(e.message,true);}).finally(function(){clearTimeout(timer);busy.delete(message.id);if(d&&d.node.isConnected)d.node.querySelectorAll('button').forEach(function(b){b.disabled=false;});});
   }
   if(close){var d=dialog('Close poll');d.node.append(el('p','','You cannot change votes after this poll closes.'),d.status,button('Cancel',d.close),button('Close poll',function(){write(d);}));}
   else return write(null);
  }
  function render(container,message){
   var p=message.poll,chat=host.currentChat();if(!chat||chat.type!=='group'||message.deleted_at||message.attachment||!valid(p,message))return false;
   var card=el('section','MessengerPoll'),question=String(message.body||'').split('\n')[0],list=el('div','MessengerPollOptions');list.setAttribute('role','radiogroup');list.setAttribute('aria-label',question);card.append(el('strong','',question),el('small','','Anonymous poll'),list);
   p.options.forEach(function(option,index){var b=button(option+' · '+p.counts[index],function(){change(message,index,false);});b.classList.add('MessengerPollOption');b.setAttribute('role','radio');b.setAttribute('aria-checked',String(p.own_choice===index));b.dataset.pollOption=String(index);b.disabled=!allowed(message.conversation_id)||p.closed_at!==null||busy.has(message.id);var bar=el('span','MessengerPollBar');bar.style.width=(p.total?p.counts[index]/p.total*100:0)+'%';bar.setAttribute('aria-hidden','true');b.append(bar);list.append(b);});
   card.append(el('small','',p.total+' votes'));
   if(p.closed_at!==null)card.append(el('small','','Poll closed'));
   else {card.append(el('small','','You can change your choice until the poll closes.'));if(p.own_choice!==null){var remove=button('Remove vote',function(){change(message,null,false);});remove.disabled=!allowed(message.conversation_id)||busy.has(message.id);card.append(remove);}if(p.created_by===host.memberId()||['owner','admin'].includes(chat.role)){var close=button('Close poll',function(){change(message,null,true);});close.disabled=!allowed(message.conversation_id)||busy.has(message.id);card.append(close);}}
   container.append(card);return true;
  }
  function stopRetry(after){
   var operation=pending;if(!operation||operation.inFlight)return;
   var d=dialog('Stop retrying');d.node.append(el('p','','This only stops local retries. An already accepted poll is not deleted.'),button('Cancel',d.close),button('Stop retrying',function(){if(pending===operation&&!operation.inFlight){pending=null;d.close();if(after)after();}else d.close();}));
  }
  function create(id){
   if(!allowed(id))return;if(pending&&pending.inFlight){host.say('Wait for the current poll request to finish.',true);return;}if(pending&&pending.id!==id){stopRetry(function(){create(id);});return;}
   var d=dialog('New poll'),question=el('input','',undefined,'input'),options=el('div','MessengerPollEditor'),add=button('Add option',function(){if(inputs.length<8&&!pending){inputs.push('');draw();}}),inputs=pending?pending.options.slice():['',''];question.placeholder='Question';question.maxLength=480;question.value=pending?pending.question:'';
   var stop=button('Stop retrying',function(){stopRetry(function(){if(d.node.isConnected)d.close();});});
   function draw(){stop.hidden=!pending;stop.disabled=!!(pending&&pending.inFlight);options.replaceChildren();inputs.forEach(function(value,index){var row=el('div','MessengerPollEditorRow'),input=el('input','',undefined,'input');input.placeholder='Option '+(index+1);input.maxLength=200;input.value=value;input.disabled=!!pending;input.addEventListener('input',function(){inputs[index]=input.value;});row.append(input);if(inputs.length>2){var remove=button('Remove option '+(index+1),function(){if(!pending){inputs.splice(index,1);draw();}});remove.disabled=!!pending;row.append(remove);}options.append(row);});add.disabled=inputs.length>=8||!!pending;question.disabled=!!pending;}
   var submit=button(pending?'Retry':'Create poll',function(){
    if(!allowed(id)||(pending&&pending.inFlight))return;
    var q=question.value.trim(),opts=inputs.map(function(o){return o.trim();});
    if(!q||Array.from(q).length>240||/[\x00-\x1f\x7f]/.test(q)||opts.length<2||opts.length>8||opts.some(function(o){return!o||Array.from(o).length>100||/[\x00-\x1f\x7f]/.test(o);})||new Set(opts.map(function(o){return o.toLowerCase();})).size!==opts.length){d.status.textContent='Enter a question and 2 to 8 different options.';return;}
    if(!pending)pending={id:id,question:q,options:opts,clientId:host.clientId(),inFlight:false};
    var operation=pending;operation.inFlight=true;draw();submit.disabled=true;d.status.textContent='Sending…';var abort=new AbortController(),timer=setTimeout(function(){abort.abort();},30000);
    host.request('group-poll-create',{method:'POST',body:{id:id,question:operation.question,options:operation.options,client_id:operation.clientId},signal:abort.signal}).then(function(result){
     var synthetic={id:result&&result.message_id,sender_user_id:host.memberId()};
     if(!result||!Number.isSafeInteger(result.message_id)||result.message_id<1||result.conversation_id!==id||result.accepted_client_id!==operation.clientId||typeof result.ack_visible!=='boolean'||(result.ack_visible?(!valid(result.poll,synthetic)||JSON.stringify(result.poll.options)!==JSON.stringify(operation.options)):result.poll!==null))throw Error('The site did not confirm the poll.');
     if(pending===operation)pending=null;if(d.node.isConnected)d.close();if(allowed(id))host.refresh(id);
    }).catch(function(e){if(e.status>=400&&e.status<500&&![408,425,429].includes(e.status)&&pending===operation)pending=null;if(d.node.isConnected){d.status.textContent=e.message;submit.textContent=pending?'Retry':'Create poll';}}).finally(function(){clearTimeout(timer);operation.inFlight=false;if(d.node.isConnected){draw();submit.disabled=false;}});
   });
   draw();submit.disabled=!!(pending&&pending.inFlight);d.node.append(question,options,add,el('p','','Anonymous voting. Choices can be changed until the poll closes.'),stop,d.status,button('Cancel',d.close),submit);question.focus();
  }
  return{render:render,attach:function(form,chat){if(chat.type==='group'&&allowed(chat.id)){var open=button('New poll',function(){create(chat.id);});open.classList.add('MessengerPollCreate');form.append(open);}},valid:valid};
 };
})();
