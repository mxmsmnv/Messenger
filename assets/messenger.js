(function () {
	'use strict';
	var root = document.querySelector('[data-messenger-app]');
	if (!root) return;
	var historyWindow=false;
	var threadEpoch=0,inboxEpoch=0,searchEpoch=0,searchActive=false,threadDrafts={},pendingWrites={};
	var api = root.dataset.api.replace(/\/$/, '') + '/';
	var ui = {};
	try { ui = JSON.parse(root.dataset.ui || '{}'); } catch (error) { ui = {}; }
	var listNode = root.querySelector('[data-conversation-list]');
	var threadNode = root.querySelector('[data-thread]');
	var statusNode = root.querySelector('[data-messenger-status]');
	var requestScopeButton = root.querySelector('[data-scope="requests"]');
	var groups = null,media = null,tools=null;
	var session = null, scope = 'inbox', active = null, newestId = 0, threadTimer = null, inboxTimer = null;

	function endpoint(resource, query) {
		var url = api + resource + '/';
		if (query) url += '?' + new URLSearchParams(query).toString();
		return url;
	}
	function request(resource, options, query) {
		options = options || {};
		options.headers = Object.assign({'Accept': 'application/json'}, options.headers || {});
		if(session&&session.user_id)options.headers['X-Messenger-Actor']=String(session.user_id);
		if (options.method === 'POST') {
			options.headers['Content-Type'] = 'application/json';
			var body = options.body || {};
			if (session && session.csrf) body[session.csrf.name] = session.csrf.value;
			options.body = JSON.stringify(body);
		}
		return fetch(endpoint(resource, query), options).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok || !payload.ok) {var failure=new Error(payload.error || 'Messenger request failed.');failure.status=response.status;throw failure;}
				return payload.result;
			});
		});
	}
	function say(message, error) { statusNode.textContent = message || ''; statusNode.dataset.error = error ? 'true' : 'false'; }
	function empty(node) { while (node.firstChild) node.removeChild(node.firstChild); }
	function applyUi(node, role) {
		var attributes = ui[role];
		if (!attributes || typeof attributes !== 'object') return node;
		Object.keys(attributes).forEach(function (name) {
			var value = attributes[name];
			if (value === null || !['string','number','boolean'].includes(typeof value) || !/^(?:class|id|role|title|aria-[a-z0-9-]+|data-[a-z0-9-]+)$/.test(name)) return;
			if (name === 'class') node.className = [node.className, String(value)].filter(Boolean).join(' ').trim();
			else node.setAttribute(name, String(value));
		});
		return node;
	}
	function el(tag, className, text, role) { var node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = text; return role ? applyUi(node, role) : node; }
	function button(text, action, className, role) { var node = el('button', className || '', text, role || 'button_secondary'); node.type = 'button'; node.dataset.action = action; return node; }
	function formatDate(value, iso) { if (!value) return ''; if (!iso) return value; var date = new Date(iso); return isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(date); }
	function messageLimit(){return Math.min(50000,Math.max(1,Number(root.dataset.maxLength)||5000));}
	function validMessage(area){var valid=Array.from(area.value).length<=messageLimit();area.setCustomValidity(valid?'':'Message exceeds the site limit.');return valid;}
	function configureMessageInput(area){area.maxLength=messageLimit()*2;area.addEventListener('input',function(){validMessage(area);});validMessage(area);}
	function clientId() { return 'web_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 14); }
	function updateRequestBadge(count) {
		if (!requestScopeButton) return;
		count = Math.max(0, Number(count) || 0);
		var current = requestScopeButton.querySelector('.MessengerTabBadge');
		if (current) current.remove();
		if (!count) { requestScopeButton.removeAttribute('aria-label'); return; }
		var badge = el('span', 'MessengerTabBadge', count > 99 ? '99+' : String(count), 'badge');
		badge.setAttribute('aria-hidden', 'true');
		requestScopeButton.appendChild(badge);
		requestScopeButton.setAttribute('aria-label', 'Requests: ' + count + ' pending');
	}

	function loadInbox(silent) {
		var epoch=++inboxEpoch,requestedScope=scope;
		if (!silent) listNode.setAttribute('aria-busy', 'true');
		return request('inbox', {}, {scope: scope, limit: 50, include_groups:session&&session.groups_supported?'1':'0',include_saved:session&&session.saved_messages_supported?'1':'0',include_pins:session&&session.pins_supported?'1':'0'}).then(function (result) {
			if(epoch!==inboxEpoch||scope!==requestedScope)return;
			updateRequestBadge(result.pending_request_count);
            if(searchActive){listNode.removeAttribute('aria-busy');return;}
			empty(listNode);
			var combined=(result.pinned_items||[]).concat(result.items).filter(function(c,i,a){return a.findIndex(function(x){return x.id===c.id;})===i;});
			if (!combined.length) {
				var state = el('div', 'MessengerEmpty', undefined, 'empty'); state.append(el('h2', '', scope === 'requests' ? 'No message requests' : 'No conversations'), el('p', '', scope === 'requests' ? 'New requests from members will appear here.' : 'Start a private conversation with another member.')); listNode.appendChild(state);
			} else combined.forEach(renderConversation);
			listNode.removeAttribute('aria-busy');
		}).catch(function (error) { listNode.removeAttribute('aria-busy'); if (!silent) say(error.message, true); });
	}
	function renderConversation(item) {
		var row = button('', 'open', 'MessengerConversation', 'conversation'); row.dataset.id = item.id; row.dataset.active = active && active.id === item.id ? 'true' : 'false';
		var avatar = el('span', 'MessengerAvatar', (item.other_user.title || '?').slice(0,1).toUpperCase(), 'avatar');
		var body = el('span', 'MessengerConversation-body'); var top = el('span', 'MessengerConversation-top'); top.append(el('strong','',item.other_user.title || item.other_user.name || 'Member'), el('time','',formatDate(item.sort_at,item.sort_at_iso)));
		var preview = item.last_message ? (item.last_message.deleted_at ? 'Message removed' : item.last_message.body) : (item.request_state === 'pending' ? 'Message request' : 'Conversation started');
		if(item.pinned_at)top.append(el('small','MessengerPinnedLabel','Pinned'));
		var bottom = el('span','MessengerConversation-bottom'); bottom.append(el('span','MessengerConversation-preview',preview)); if (item.unread_count) bottom.append(el('span','MessengerUnread',String(item.unread_count), 'badge'));
		body.append(top,bottom); row.append(avatar,body); listNode.appendChild(row);if(media)media.groupPhoto(avatar,item);
	}

	function rememberDraft(){var area=threadNode.querySelector('[data-composer] textarea');if(active&&area)threadDrafts[active.id]=area.value;}
	function openConversation(id) {
        historyWindow=false;
		rememberDraft();var epoch=++threadEpoch;
		return request('conversation', {}, {id:id}).then(function (conversation) {
			if(epoch!==threadEpoch)return;active = conversation; root.dataset.threadOpen = 'true'; newestId = 0; renderThreadShell(); return loadMessages(false);
		}).then(function () { if(epoch!==threadEpoch)return;loadInbox(true); scheduleThread(); }).catch(function (error) { say(error.message, true); });
	}
	function renderThreadShell() {
        if(tools)tools.reset(active.id);
		if(active.client_policy){var oldInbox=root.dataset.inboxPoll;root.dataset.maxLength=active.client_policy.max_message_length;root.dataset.threadPoll=active.client_policy.poll_thread_seconds;root.dataset.inboxPoll=active.client_policy.poll_inbox_seconds;if(oldInbox!==root.dataset.inboxPoll)scheduleInbox();}
		empty(threadNode); threadNode.dataset.id = active.id;
		var header = el('header','MessengerThread-header',undefined,'thread_header'); var identity = el('div','MessengerIdentity'); identity.append(button('Back','back','MessengerBack'), el('span','MessengerAvatar',(active.other_user.title || '?').slice(0,1).toUpperCase(),'avatar'), el('h2','',active.other_user.title || active.other_user.name || 'Member'));

		var actions = el('div','MessengerThread-actions',undefined,'actions'); actions.append(button(active.archived_at ? 'Restore' : 'Archive','archive'), button(active.muted_until ? 'Unmute' : 'Mute','mute')); if(active.type==='group' && groups) {var groupButton=button('Group info','group-settings');groupButton.addEventListener('click',function(){groups.settings(active.id);});actions.append(groupButton);}else if(active.type!=='saved'&&active.blocked_by_actor) actions.append(button('Unblock','unblock','MessengerDanger','button_danger')); else if(active.type!=='group'&&active.type!=='saved' && !active.blocked_by_other) actions.append(button('Block','block','MessengerDanger','button_danger')); if(tools)tools.threadActions(active,actions);header.append(identity,actions);
		var messages = el('div','MessengerMessages',undefined,'message_list'); messages.dataset.messages = '';
		threadNode.append(header);if(media)media.groupPhoto(identity.querySelector('.MessengerAvatar'),active);
		if (active.request_state === 'pending' && active.recipient_id === session.user_id) {
			var requestBar = el('section','MessengerRequest',undefined,'request');
			var requestActions = el('div','MessengerRequest-actions',undefined,'actions');
			requestActions.append(button('Decline','decline'), button('Accept','accept','MessengerPrimary','button_primary'));
			requestBar.append(el('div','MessengerRequest-copy', 'This member wants to message you.'), requestActions);
			threadNode.append(requestBar);
		}
		if(active.blocked){var blocked=el('section','MessengerRequest',undefined,'request');blocked.append(el('div','', active.blocked_by_other ? 'This member has blocked messaging for this conversation.' : 'You blocked messaging for this conversation.'));threadNode.append(blocked);}
		threadNode.append(messages);
		if (!active.blocked && active.request_state!=='declined' && (active.request_state === 'accepted' || active.requester_id === session.user_id)) {
			var form = el('form','MessengerComposer',undefined,'composer'); form.dataset.composer=''; var area = el('textarea','',undefined,'textarea'); area.name='body'; area.rows=1; configureMessageInput(area); area.required=true; area.value=threadDrafts[active.id]||'';var composerId=active.id;area.addEventListener('input',function(){threadDrafts[composerId]=area.value;});area.placeholder=active.request_state === 'pending' && active.can_send!==true ? 'Waiting for this request to be accepted' : 'Write a message…';
			if(active.can_send===false || (active.can_send===undefined && active.request_state === 'pending')) area.disabled=true;
			var send = el('button','MessengerPrimary','Send','button_primary'); send.type='submit'; if(area.disabled) send.disabled=true; form.append(area,send);if(active.type==='group'&&media)media.attach(form,active); threadNode.append(form);
		}
        if(tools)tools.threadPins(active);
        if(historyWindow)latestWindow(active.id);
	}
	function loadMessages(incremental,preserveScroll) {
		if (!active) return Promise.resolve();
		var id=active.id,epoch=threadEpoch;
		var query = {id:id,limit:100}; if (incremental && newestId) query.after = newestId;
		return request('messages', {}, query).then(function (items) {
			if(epoch!==threadEpoch||!active||active.id!==id)return;
			var node=threadNode.querySelector('[data-messages]'); if(!node)return;var oldTop=node.scrollTop,wasAtBottom=node.scrollHeight-node.clientHeight-node.scrollTop<32;
			if(!incremental) empty(node);
			items.forEach(function(item){ if(node.querySelector('[data-message-id="'+item.id+'"]'))return; node.appendChild(renderMessage(item)); newestId=Math.max(newestId,item.id); });
			if(tools)tools.redraw();
			if(!incremental && !items.length) node.appendChild(el('p','MessengerState','No messages yet.'));
			if(items.length){ node.scrollTop=preserveScroll&&!wasAtBottom?oldTop:node.scrollHeight; request('read',{method:'POST',body:{id:active.id,message_id:newestId}}).catch(function(){}); }
		});
	}
	function renderMessage(item) {
		var own=item.sender_user_id===session.user_id; var article=el('article','MessengerMessage ' + (own?'is-own':'is-other'),undefined,'message'); article.dataset.messageId=item.id;
		var bubble=el('div','MessengerBubble',undefined,'bubble');if(item.forwarded&&!item.deleted_at)bubble.append(el('small','MessengerForwarded','Forwarded'));if(active&&active.type==='group'&&item.sender&&!own)bubble.append(el('strong','',item.sender.title||item.sender.name));var technical=media&&item.attachment&&!item.deleted_at&&((/^image\//.test(item.attachment.mime_type)&&item.body===item.attachment.filename)||(item.attachment.kind==='voice'&&item.body==='Voice message'));if(!technical)bubble.append(el('p','',item.deleted_at?'Message removed':item.body));if(media&&item.attachment)media.attachment(bubble,item);bubble.append(el('time','',formatDate(item.created_at,item.created_at_iso)));
		if(!item.deleted_at && Number.isInteger(item.id) && item.id>0) {
			var controls=el('span','MessengerMessage-controls');
			if(own){if(item.can_edit!==false)controls.append(button('Edit','edit','MessengerMessage-action','button_tertiary'));if(item.can_delete_for_everyone!==false)controls.append(button('Delete','delete','MessengerMessage-action','button_tertiary'));}else controls.append(button('Report','report','MessengerMessage-action','button_tertiary'));
			controls.append(button('Hide','hide','MessengerMessage-action','button_tertiary'));bubble.append(controls);
		}
		article.appendChild(bubble);if(tools&&active)tools.attach(article,item,active.id);return article;
	}

	function submitMessage(form) {
        if(!active)return;
        var id=active.id,area=form.querySelector('textarea'),body=area.value.trim();if(!body||!validMessage(area)){area.reportValidity();return;}
        var pending=pendingWrites[id];if(pending&&pending.inFlight)return;
        if(pending&&pending.body!==body){area.value=pending.body;threadDrafts[id]=pending.body;say('Retry the unconfirmed message first.',true);return;}
        if(!pending)pending=pendingWrites[id]={body:body,clientId:clientId()};
        pending.inFlight=true;
        if(pending.node&&pending.node.isConnected)pending.node.remove();
        var optimistic={id:'pending-'+Date.now(),sender_user_id:session.user_id,body:body,created_at:new Date().toISOString(),created_at_iso:new Date().toISOString()};
        var node=renderMessage(optimistic);pending.node=node;node.classList.add('is-sending');threadNode.querySelector('[data-messages]').appendChild(node);area.value='';threadDrafts[id]='';
        request('send',{method:'POST',body:{id:id,body:body,client_id:pending.clientId}}).then(function(message){
            delete pendingWrites[id];if(node.isConnected)node.replaceWith(renderMessage(message));else if(active&&active.id===id){var visibleMessages=threadNode.querySelector('[data-messages]');if(visibleMessages&&!visibleMessages.querySelector('[data-message-id="'+message.id+'"]'))visibleMessages.appendChild(renderMessage(message));}
            if(active&&active.id===id){newestId=Math.max(newestId,message.id);if(active.request_state==='pending')openConversation(id);}
            loadInbox(true);say('Message sent.');
        }).catch(function(error){pending.inFlight=false;if(error.status>=400&&error.status<500&&error.status!==408)delete pendingWrites[id];node.classList.remove('is-sending');node.classList.add('is-failed');node.appendChild(el('span','MessengerFailure',error.message));var retryArea=active&&active.id===id?threadNode.querySelector('[data-composer] textarea'):area;if(!threadDrafts[id]&&retryArea&&!retryArea.value){retryArea.value=body;threadDrafts[id]=body;}say(error.message,true);});
    }

	function postAction(resource, body, done) { return request(resource,{method:'POST',body:body}).then(function(result){say(done||'Updated.');return result;}).catch(function(error){say(error.message,true);throw error;}); }
	function newMessageDialog() {
		var dialog=applyUi(document.createElement('dialog'),'dialog');dialog.className=['MessengerDialog',dialog.className].filter(Boolean).join(' ');var form=el('form','');form.method='dialog';form.append(el('h2','','New message'));
		var user=el('input','',undefined,'input');user.type='search';user.minLength=2;user.autocomplete='off';user.placeholder='Search by username';user.setAttribute('aria-label','Find a member');
		var results=el('div','MessengerRecipientResults');results.setAttribute('aria-live','polite');var selected=null,searchTimer=null,startWrite=null;
		var body=el('textarea','',undefined,'textarea');body.required=true;configureMessageInput(body);body.placeholder='Write a message…';var controls=el('div','MessengerDialog-actions',undefined,'dialog_actions');var create=button('Send request','create','MessengerPrimary','button_primary');create.disabled=true;controls.append(button('Cancel','cancel'),create);form.append(user,results,body,controls);dialog.append(form);root.append(dialog);dialog.showModal();user.focus();
		user.addEventListener('input',function(){selected=null;create.disabled=true;clearTimeout(searchTimer);empty(results);var query=user.value.trim();if(query.length<2)return;results.appendChild(el('p','MessengerState','Searching…'));searchTimer=setTimeout(function(){request('recipients',{}, {q:query,limit:10}).then(function(items){empty(results);if(!items.length){results.appendChild(el('p','MessengerState','No members found.'));return;}items.forEach(function(item){var row=button('', 'select-recipient','MessengerRecipient','conversation');row.dataset.userId=item.id;row.append(el('span','MessengerAvatar',(item.title||item.name||'?').slice(0,1).toUpperCase(),'avatar'),el('span','',item.title||item.name));results.appendChild(row);});}).catch(function(error){empty(results);results.appendChild(el('p','MessengerState',error.message));});},250);});
		dialog.addEventListener('click',function(event){var actionNode=event.target.closest('[data-action]');if(!actionNode)return;var action=actionNode.dataset.action;if(action==='cancel'){dialog.close();dialog.remove();}if(action==='select-recipient'){selected=Number(actionNode.dataset.userId);results.querySelectorAll('[data-action="select-recipient"]').forEach(function(node){node.dataset.selected=node===actionNode?'true':'false';});create.disabled=true;request('recipient-context',{}, {user_id:selected}).then(function(context){if(!dialog.isConnected || !dialog.open || selected!==Number(actionNode.dataset.userId))return;if(context.conversation){dialog.close();dialog.remove();openConversation(context.conversation.id);return;}create.disabled=context.decision==='deny';create.textContent=context.decision==='request'?'Send request':'Send message';if(context.decision==='deny')say('Messaging is unavailable.',true);}).catch(function(error){say(error.message,true);});return;}if(action==='create'){event.preventDefault();if(!selected||!body.value.trim()||(startWrite&&startWrite.inFlight))return;if(!validMessage(body)){body.reportValidity();return;}if(startWrite&&(startWrite.recipient!==selected||startWrite.body!==body.value)){say('Retry the unconfirmed message first.',true);return;}if(!startWrite)startWrite={recipient:selected,body:body.value,clientId:clientId()};startWrite.inFlight=true;create.disabled=true;request('start',{method:'POST',body:{recipient_id:startWrite.recipient,body:startWrite.body,client_id:startWrite.clientId}}).then(function(result){if(!dialog.isConnected || !dialog.open)return;dialog.close();dialog.remove();scope='inbox';openConversation(result.conversation.id);}).catch(function(error){startWrite.inFlight=false;if(error.status>=400&&error.status<500&&error.status!==408)startWrite=null;create.disabled=false;say(error.message,true);});}});
	}

	root.addEventListener('click', function (event) {
		var scopeButton=event.target.closest('[data-scope]'); if(scopeButton){searchActive=false;searchEpoch++;root.querySelector('[data-message-search] input').value='';scope=scopeButton.dataset.scope;root.querySelectorAll('[data-scope]').forEach(function(node){node.toggleAttribute('aria-current',node===scopeButton);});loadInbox();return;}
		if(event.target.closest('[data-messenger-new]')){newMessageDialog();return;}
		var actionNode=event.target.closest('[data-action]');if(!actionNode)return;var action=actionNode.dataset.action;
		if(action==='back'){rememberDraft();threadEpoch++;delete root.dataset.threadOpen;active=null;clearInterval(threadTimer);return;}
		if(action==='open'){openConversation(Number(actionNode.dataset.id));return;} if(!active)return;var actionId=active.id,actionEpoch=threadEpoch;function actionStillActive(){return active&&active.id===actionId&&threadEpoch===actionEpoch;}function refreshActionThread(){if(actionStillActive())openConversation(actionId);}function closeActionThread(){if(actionStillActive()){rememberDraft();threadEpoch++;active=null;clearInterval(threadTimer);delete root.dataset.threadOpen;empty(threadNode);}loadInbox();}
		if(action==='accept')postAction('accept',{id:active.id},'Request accepted.').then(refreshActionThread);
		if(action==='decline'&&window.confirm('Decline this message request?'))postAction('decline',{id:active.id},'Request declined.').then(closeActionThread);
		if(action==='archive'){var archiveState=!active.archived_at;postAction('archive',{id:active.id,archived:archiveState},archiveState?'Conversation archived.':'Conversation restored.').then(closeActionThread);}
		if(action==='mute'){var until=active.muted_until?null:new Date(Date.now()+86400000).toISOString();postAction('mute',{id:active.id,until:until},until?'Muted for 24 hours.':'Notifications unmuted.').then(refreshActionThread);}
		if(action==='block'&&window.confirm('Block this member? They will no longer be able to message you.'))postAction('block',{user_id:active.other_user.id},'Member blocked.').then(refreshActionThread);
		if(action==='unblock'&&window.confirm('Unblock this member?'))postAction('unblock',{user_id:active.other_user.id},'Member unblocked.').then(refreshActionThread);
		if(action==='report'){var article=actionNode.closest('[data-message-id]');var category=window.prompt('Report category: spam, harassment, hate, sexual, fraud, privacy, other','harassment');if(category)postAction('report',{message_id:Number(article.dataset.messageId),category:category},'Report submitted.');}
		if(action==='edit'){var editArticle=actionNode.closest('[data-message-id]'),old=editArticle.querySelector('p').textContent,next=window.prompt('Edit message',old);if(next&&next!==old)postAction('edit',{message_id:Number(editArticle.dataset.messageId),body:next},'Message edited.').then(function(){if(actionStillActive())loadMessages(false);});}
		if(action==='delete'&&window.confirm('Remove this message for everyone?')){var deleteArticle=actionNode.closest('[data-message-id]');postAction('delete',{message_id:Number(deleteArticle.dataset.messageId)},'Message removed.').then(function(){if(actionStillActive())loadMessages(false);});}
		if(action==='hide'){var hideArticle=actionNode.closest('[data-message-id]');postAction('hide',{message_id:Number(hideArticle.dataset.messageId)},'Message hidden.').then(function(){hideArticle.remove();});}
	});
	root.addEventListener('submit',function(event){var form=event.target.closest('[data-composer]');if(!form)return;event.preventDefault();submitMessage(form);});
	root.querySelector('[data-message-search]').addEventListener('submit',function(event){
        event.preventDefault();var q=String(new FormData(event.currentTarget).get('q')||'').trim(),epoch=++searchEpoch;
        if(q.length<2){searchActive=false;loadInbox();return;}
        searchActive=true;inboxEpoch++;listNode.setAttribute('aria-busy','true');
        request('search',{}, {q:q,limit:30,include_groups:session&&session.groups_supported?'1':'0'}).then(function(items){
            if(epoch!==searchEpoch||!searchActive)return;empty(listNode);listNode.removeAttribute('aria-busy');
            if(!items.length){listNode.appendChild(el('p','MessengerState','No matching messages.'));return;}
            items.forEach(function(item){var row=button('', 'open','MessengerSearchResult','conversation');row.dataset.id=item.conversation_id;row.append(el('strong','',item.body),el('time','',formatDate(item.created_at,item.created_at_iso)));listNode.appendChild(row);});
        }).catch(function(error){if(epoch===searchEpoch){listNode.removeAttribute('aria-busy');say(error.message,true);}});
    });
	function refreshThread(force){
        if(!active||(historyWindow&&!force))return Promise.resolve();var id=active.id,epoch=threadEpoch;
        return request('conversation',{}, {id:id}).then(function(chat){
            if(epoch!==threadEpoch||!active||active.id!==id)return;
            var contentChanged=chat.message_revision!==active.message_revision;
            var changed=chat.request_state!==active.request_state||chat.blocked!==active.blocked||chat.can_send!==active.can_send||JSON.stringify(chat.client_policy)!==JSON.stringify(active.client_policy);
            if(changed){rememberDraft();var messages=threadNode.querySelector('[data-messages]');active=chat;renderThreadShell();if(messages)threadNode.querySelector('[data-messages]').replaceWith(messages);scheduleThread();}
            else active=chat;
            if(tools){tools.threadPinLabel(active);if(contentChanged&&!changed)tools.threadPins(active);}
            if(historyWindow)return;
            return loadMessages(!changed&&!contentChanged,contentChanged&&!changed);
        });
    }
    function jumpPinned(id,messageId,stillCurrent,signal){
        var epoch=threadEpoch;if(!active||active.id!==id||(stillCurrent&&!stillCurrent()))return Promise.resolve(false);
        var visible=threadNode.querySelector('[data-message-id="'+messageId+'"]');if(visible){visible.scrollIntoView({block:'center'});return Promise.resolve(true);}
        return request('messages',{signal:signal}, {id:id,before:messageId+1,limit:50}).then(function(items){
            if(epoch!==threadEpoch||!active||active.id!==id||(stillCurrent&&!stillCurrent()))return false;
            if(!items.some(function(m){return m.id===messageId&&!m.deleted_at;}))throw Error('Message unavailable.');
            var node=threadNode.querySelector('[data-messages]');empty(node);items.forEach(function(item){node.append(renderMessage(item));});newestId=Math.max.apply(null,items.map(function(m){return m.id;}));historyWindow=true;clearInterval(threadTimer);
            latestWindow(id);
            node.querySelector('[data-message-id="'+messageId+'"]').scrollIntoView({block:'center'});return true;
        });
    }
    function latestWindow(id){
        var old=threadNode.querySelector('[data-latest-window]');if(old)old.remove();var latest=button('Back to latest messages','','','button_secondary');latest.dataset.latestWindow='';latest.addEventListener('click',function(){if(active&&active.id===id)openConversation(id);});var node=threadNode.querySelector('[data-messages]');if(node)node.before(latest);
    }
    function scheduleThread(){clearInterval(threadTimer);threadTimer=setInterval(function(){if(!document.hidden&&active)refreshThread().catch(function(){});},Math.max(3,Number(root.dataset.threadPoll)||4)*1000);}
    function scheduleInbox(){clearInterval(inboxTimer);inboxTimer=setInterval(function(){if(!document.hidden)loadInbox(true);},Math.max(10,Number(root.dataset.inboxPoll)||20)*1000);}
    scheduleInbox();
	request('session').then(function(result){session=result;if(!session.can_use)throw new Error('Messenger is unavailable.');if((session.media_catalog_supported||session.saved_messages_supported||session.history_search_supported||session.forwarding_supported||session.reactions_supported||session.pins_supported)&&window.ProcessWireMessengerTools)tools=window.ProcessWireMessengerTools({root:root,thread:threadNode,request:request,el:el,button:button,clientId:clientId,say:say,memberId:function(){return session.user_id;},groups:session.groups_supported,reactions:session.reactions_supported,pins:session.pins_supported,historySearch:session.history_search_supported,catalog:session.media_catalog_supported,formatDate:formatDate,saved:session.saved_messages_supported,openConversation:openConversation,currentChat:function(){return active;},canPin:function(id){return active&&active.id===id&&active.request_state==='accepted';},jumpPinned:jumpPinned,pinRefresh:function(id){if(active&&active.id===id)refreshThread(true).catch(function(e){say(e.message,true);});loadInbox(true);},canReact:function(id){return active&&active.id===id&&active.request_state==='accepted'&&!active.blocked&&active.can_send!==false;},refresh:function(id){if(active&&active.id===id)loadMessages(false,true);loadInbox(true);}});if(window.ProcessWireMessengerMedia)media=window.ProcessWireMessengerMedia.create({root:root,request:request,el:el,button:button,clientId:clientId,say:say,memberId:function(){return session.user_id;},refresh:function(id){if(active&&active.id===id)loadMessages(false);loadInbox(true);}});if(session.groups_supported&&window.ProcessWireMessengerGroups)groups=window.ProcessWireMessengerGroups({root:root,request:request,el:el,button:button,say:say,clientId:clientId,loadInbox:loadInbox,openConversation:openConversation,closeConversation:function(){rememberDraft();threadEpoch++;active=null;root.dataset.threadOpen='false';empty(threadNode);loadInbox();}});return loadInbox();}).catch(function(error){say(error.message,true);listNode.innerHTML='<p class="MessengerState">Messenger is unavailable.</p>';});
}());
