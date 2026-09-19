(function () {
	'use strict';
	var root = document.querySelector('[data-messenger-app]');
	if (!root) return;
	var api = root.dataset.api.replace(/\/$/, '') + '/';
	var ui = {};
	try { ui = JSON.parse(root.dataset.ui || '{}'); } catch (error) { ui = {}; }
	var listNode = root.querySelector('[data-conversation-list]');
	var threadNode = root.querySelector('[data-thread]');
	var statusNode = root.querySelector('[data-messenger-status]');
	var requestScopeButton = root.querySelector('[data-scope="requests"]');
	var session = null, scope = 'inbox', active = null, newestId = 0, threadTimer = null, inboxTimer = null;

	function endpoint(resource, query) {
		var url = api + resource + '/';
		if (query) url += '?' + new URLSearchParams(query).toString();
		return url;
	}
	function request(resource, options, query) {
		options = options || {};
		options.headers = Object.assign({'Accept': 'application/json'}, options.headers || {});
		if (options.method === 'POST') {
			options.headers['Content-Type'] = 'application/json';
			var body = options.body || {};
			if (session && session.csrf) body[session.csrf.name] = session.csrf.value;
			options.body = JSON.stringify(body);
		}
		return fetch(endpoint(resource, query), options).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok || !payload.ok) throw new Error(payload.error || 'Messenger request failed.');
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
	function formatDate(value) { if (!value) return ''; var date = new Date(value.replace(' ', 'T') + 'Z'); return isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(date); }
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
		if (!silent) listNode.setAttribute('aria-busy', 'true');
		return request('inbox', {}, {scope: scope, limit: 50}).then(function (result) {
			updateRequestBadge(result.pending_request_count);
			empty(listNode);
			if (!result.items.length) {
				var state = el('div', 'MessengerEmpty', undefined, 'empty'); state.append(el('h2', '', scope === 'requests' ? 'No message requests' : 'No conversations'), el('p', '', scope === 'requests' ? 'New requests from members will appear here.' : 'Start a private conversation with another member.')); listNode.appendChild(state);
			} else result.items.forEach(renderConversation);
			listNode.removeAttribute('aria-busy');
		}).catch(function (error) { listNode.removeAttribute('aria-busy'); if (!silent) say(error.message, true); });
	}
	function renderConversation(item) {
		var row = button('', 'open', 'MessengerConversation', 'conversation'); row.dataset.id = item.id; row.dataset.active = active && active.id === item.id ? 'true' : 'false';
		var avatar = el('span', 'MessengerAvatar', (item.other_user.title || '?').slice(0,1).toUpperCase(), 'avatar');
		var body = el('span', 'MessengerConversation-body'); var top = el('span', 'MessengerConversation-top'); top.append(el('strong','',item.other_user.title || item.other_user.name || 'Member'), el('time','',formatDate(item.sort_at)));
		var preview = item.last_message ? (item.last_message.deleted_at ? 'Message removed' : item.last_message.body) : (item.request_state === 'pending' ? 'Message request' : 'Conversation started');
		var bottom = el('span','MessengerConversation-bottom'); bottom.append(el('span','MessengerConversation-preview',preview)); if (item.unread_count) bottom.append(el('span','MessengerUnread',String(item.unread_count), 'badge'));
		body.append(top,bottom); row.append(avatar,body); listNode.appendChild(row);
	}

	function openConversation(id) {
		return request('conversation', {}, {id:id}).then(function (conversation) {
			active = conversation; root.dataset.threadOpen = 'true'; newestId = 0; renderThreadShell(); return loadMessages(false);
		}).then(function () { loadInbox(true); scheduleThread(); }).catch(function (error) { say(error.message, true); });
	}
	function renderThreadShell() {
		empty(threadNode); threadNode.dataset.id = active.id;
		var header = el('header','MessengerThread-header',undefined,'thread_header'); var identity = el('div','MessengerIdentity'); identity.append(button('Back','back','MessengerBack'), el('span','MessengerAvatar',(active.other_user.title || '?').slice(0,1).toUpperCase(),'avatar'), el('h2','',active.other_user.title || active.other_user.name || 'Member'));
		var actions = el('div','MessengerThread-actions',undefined,'actions'); actions.append(button(active.archived_at ? 'Restore' : 'Archive','archive'), button(active.muted_until ? 'Unmute' : 'Mute','mute')); if(active.blocked_by_actor) actions.append(button('Unblock','unblock','MessengerDanger','button_danger')); else if(!active.blocked_by_other) actions.append(button('Block','block','MessengerDanger','button_danger')); header.append(identity,actions);
		var messages = el('div','MessengerMessages',undefined,'message_list'); messages.dataset.messages = '';
		threadNode.append(header);
		if (active.request_state === 'pending' && active.recipient_id === session.user_id) {
			var requestBar = el('section','MessengerRequest',undefined,'request');
			var requestActions = el('div','MessengerRequest-actions',undefined,'actions');
			requestActions.append(button('Decline','decline'), button('Accept','accept','MessengerPrimary','button_primary'));
			requestBar.append(el('div','MessengerRequest-copy', 'This member wants to message you.'), requestActions);
			threadNode.append(requestBar);
		}
		if(active.blocked){var blocked=el('section','MessengerRequest',undefined,'request');blocked.append(el('div','', active.blocked_by_other ? 'This member has blocked messaging for this conversation.' : 'You blocked messaging for this conversation.'));threadNode.append(blocked);}
		threadNode.append(messages);
		if (!active.blocked && (active.request_state === 'accepted' || active.requester_id === session.user_id)) {
			var form = el('form','MessengerComposer',undefined,'composer'); form.dataset.composer=''; var area = el('textarea','',undefined,'textarea'); area.name='body'; area.rows=1; area.maxLength=5000; area.required=true; area.placeholder=active.request_state === 'pending' ? 'Waiting for this request to be accepted' : 'Write a message…';
			if(active.request_state === 'pending') area.disabled=true;
			var send = el('button','MessengerPrimary','Send','button_primary'); send.type='submit'; if(active.request_state === 'pending') send.disabled=true; form.append(area,send); threadNode.append(form);
		}
	}
	function loadMessages(incremental) {
		if (!active) return Promise.resolve();
		var query = {id:active.id,limit:100}; if (incremental && newestId) query.after = newestId;
		return request('messages', {}, query).then(function (items) {
			var node=threadNode.querySelector('[data-messages]'); if(!node)return;
			if(!incremental) empty(node);
			items.forEach(function(item){ if(node.querySelector('[data-message-id="'+item.id+'"]'))return; node.appendChild(renderMessage(item)); newestId=Math.max(newestId,item.id); });
			if(!incremental && !items.length) node.appendChild(el('p','MessengerState','No messages yet.'));
			if(items.length){ node.scrollTop=node.scrollHeight; request('read',{method:'POST',body:{id:active.id,message_id:newestId}}).catch(function(){}); }
		});
	}
	function renderMessage(item) {
		var own=item.sender_user_id===session.user_id; var article=el('article','MessengerMessage ' + (own?'is-own':'is-other'),undefined,'message'); article.dataset.messageId=item.id;
		var bubble=el('div','MessengerBubble',undefined,'bubble'); bubble.append(el('p','',item.deleted_at?'Message removed':item.body),el('time','',formatDate(item.created_at)));
		if(!item.deleted_at) {
			var controls=el('span','MessengerMessage-controls');
			if(own){controls.append(button('Edit','edit','MessengerMessage-action','button_tertiary'),button('Delete','delete','MessengerMessage-action','button_tertiary'));}else controls.append(button('Report','report','MessengerMessage-action','button_tertiary'));
			controls.append(button('Hide','hide','MessengerMessage-action','button_tertiary'));bubble.append(controls);
		}
		article.appendChild(bubble); return article;
	}

	function submitMessage(form) {
		var area=form.querySelector('textarea'), body=area.value.trim(); if(!body)return;
		var optimistic={id:'pending-'+Date.now(),sender_user_id:session.user_id,body:body,created_at:new Date().toISOString().slice(0,19).replace('T',' ')}; var node=renderMessage(optimistic); node.classList.add('is-sending'); threadNode.querySelector('[data-messages]').appendChild(node); area.value='';
		request('send',{method:'POST',body:{id:active.id,body:body,client_id:clientId()}}).then(function(message){node.replaceWith(renderMessage(message));newestId=Math.max(newestId,message.id);loadInbox(true);say('Message sent.');}).catch(function(error){node.classList.remove('is-sending');node.classList.add('is-failed');node.appendChild(el('span','MessengerFailure',error.message));area.value=body;say(error.message,true);});
	}
	function postAction(resource, body, done) { return request(resource,{method:'POST',body:body}).then(function(result){say(done||'Updated.');return result;}).catch(function(error){say(error.message,true);throw error;}); }
	function newMessageDialog() {
		var dialog=applyUi(document.createElement('dialog'),'dialog');dialog.className=['MessengerDialog',dialog.className].filter(Boolean).join(' ');var form=el('form','');form.method='dialog';form.append(el('h2','','New message'));
		var user=el('input','',undefined,'input');user.type='search';user.minLength=2;user.autocomplete='off';user.placeholder='Search by username';user.setAttribute('aria-label','Find a member');
		var results=el('div','MessengerRecipientResults');results.setAttribute('aria-live','polite');var selected=null,searchTimer=null;
		var body=el('textarea','',undefined,'textarea');body.required=true;body.placeholder='Write a message…';var controls=el('div','MessengerDialog-actions',undefined,'dialog_actions');var create=button('Send request','create','MessengerPrimary','button_primary');create.disabled=true;controls.append(button('Cancel','cancel'),create);form.append(user,results,body,controls);dialog.append(form);root.append(dialog);dialog.showModal();user.focus();
		user.addEventListener('input',function(){selected=null;create.disabled=true;clearTimeout(searchTimer);empty(results);var query=user.value.trim();if(query.length<2)return;results.appendChild(el('p','MessengerState','Searching…'));searchTimer=setTimeout(function(){request('recipients',{}, {q:query,limit:10}).then(function(items){empty(results);if(!items.length){results.appendChild(el('p','MessengerState','No members found.'));return;}items.forEach(function(item){var row=button('', 'select-recipient','MessengerRecipient','conversation');row.dataset.userId=item.id;row.append(el('span','MessengerAvatar',(item.title||item.name||'?').slice(0,1).toUpperCase(),'avatar'),el('span','',item.title||item.name));results.appendChild(row);});}).catch(function(error){empty(results);results.appendChild(el('p','MessengerState',error.message));});},250);});
		dialog.addEventListener('click',function(event){var actionNode=event.target.closest('[data-action]');if(!actionNode)return;var action=actionNode.dataset.action;if(action==='cancel'){dialog.close();dialog.remove();}if(action==='select-recipient'){selected=Number(actionNode.dataset.userId);results.querySelectorAll('[data-action="select-recipient"]').forEach(function(node){node.dataset.selected=node===actionNode?'true':'false';});create.disabled=false;return;}if(action==='create'){event.preventDefault();if(!selected)return;request('start',{method:'POST',body:{recipient_id:selected,body:body.value,client_id:clientId()}}).then(function(result){dialog.close();dialog.remove();scope='inbox';openConversation(result.conversation.id);}).catch(function(error){say(error.message,true);});}});
	}

	root.addEventListener('click', function (event) {
		var scopeButton=event.target.closest('[data-scope]'); if(scopeButton){scope=scopeButton.dataset.scope;root.querySelectorAll('[data-scope]').forEach(function(node){node.toggleAttribute('aria-current',node===scopeButton);});loadInbox();return;}
		if(event.target.closest('[data-messenger-new]')){newMessageDialog();return;}
		var actionNode=event.target.closest('[data-action]');if(!actionNode)return;var action=actionNode.dataset.action;
		if(action==='back'){delete root.dataset.threadOpen;active=null;clearInterval(threadTimer);return;}
		if(action==='open'){openConversation(Number(actionNode.dataset.id));return;} if(!active)return;
		if(action==='accept')postAction('accept',{id:active.id},'Request accepted.').then(function(){openConversation(active.id);});
		if(action==='decline'&&window.confirm('Decline this message request?'))postAction('decline',{id:active.id},'Request declined.').then(function(){active=null;threadNode.innerHTML='<div class="MessengerEmpty"><h2>Request declined</h2></div>';loadInbox();});
		if(action==='archive'){var archiveState=!active.archived_at;postAction('archive',{id:active.id,archived:archiveState},archiveState?'Conversation archived.':'Conversation restored.').then(function(){active=null;delete root.dataset.threadOpen;loadInbox();});}
		if(action==='mute'){var until=active.muted_until?null:new Date(Date.now()+86400000).toISOString().slice(0,19).replace('T',' ');postAction('mute',{id:active.id,until:until},until?'Muted for 24 hours.':'Notifications unmuted.').then(function(){openConversation(active.id);});}
		if(action==='block'&&window.confirm('Block this member? They will no longer be able to message you.'))postAction('block',{user_id:active.other_user.id},'Member blocked.').then(function(){openConversation(active.id);});
		if(action==='unblock'&&window.confirm('Unblock this member?'))postAction('unblock',{user_id:active.other_user.id},'Member unblocked.').then(function(){openConversation(active.id);});
		if(action==='report'){var article=actionNode.closest('[data-message-id]');var category=window.prompt('Report category: spam, harassment, hate, sexual, fraud, privacy, other','harassment');if(category)postAction('report',{message_id:Number(article.dataset.messageId),category:category},'Report submitted.');}
		if(action==='edit'){var editArticle=actionNode.closest('[data-message-id]'),old=editArticle.querySelector('p').textContent,next=window.prompt('Edit message',old);if(next&&next!==old)postAction('edit',{message_id:Number(editArticle.dataset.messageId),body:next},'Message edited.').then(function(){loadMessages(false);});}
		if(action==='delete'&&window.confirm('Remove this message for everyone?')){var deleteArticle=actionNode.closest('[data-message-id]');postAction('delete',{message_id:Number(deleteArticle.dataset.messageId)},'Message removed.').then(function(){loadMessages(false);});}
		if(action==='hide'){var hideArticle=actionNode.closest('[data-message-id]');postAction('hide',{message_id:Number(hideArticle.dataset.messageId)},'Message hidden.').then(function(){hideArticle.remove();});}
	});
	root.addEventListener('submit',function(event){var form=event.target.closest('[data-composer]');if(!form)return;event.preventDefault();submitMessage(form);});
	root.querySelector('[data-message-search]').addEventListener('submit',function(event){event.preventDefault();var q=new FormData(event.currentTarget).get('q');if(!q||String(q).trim().length<2)return;request('search',{}, {q:String(q).trim(),limit:30}).then(function(items){empty(listNode);if(!items.length){listNode.appendChild(el('p','MessengerState','No matching messages.'));return;}items.forEach(function(item){var row=button('', 'open','MessengerSearchResult','conversation');row.dataset.id=item.conversation_id;row.append(el('strong','',item.body),el('time','',formatDate(item.created_at)));listNode.appendChild(row);});}).catch(function(error){say(error.message,true);});});
	function scheduleThread(){clearInterval(threadTimer);threadTimer=setInterval(function(){if(!document.hidden&&active)loadMessages(true).catch(function(){});},Math.max(3,Number(root.dataset.threadPoll)||4)*1000);}
	inboxTimer=setInterval(function(){if(!document.hidden)loadInbox(true);},Math.max(10,Number(root.dataset.inboxPoll)||20)*1000);
	request('session').then(function(result){session=result;if(!session.can_use)throw new Error('Messenger is unavailable.');return loadInbox();}).catch(function(error){say(error.message,true);listNode.innerHTML='<p class="MessengerState">Messenger is unavailable.</p>';});
}());
