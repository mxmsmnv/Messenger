(function () {
    'use strict';
    // The host supplies the same authenticated request, CSRF and framework adapter.
    window.ProcessWireMessengerGroups = function (host) {
        var pendingCreate = null;
        var root = host.root, request = host.request, el = host.el, button = host.button;
        function action(label, fn, danger) {
            var b = button(label, '', danger ? 'MessengerDanger' : '', danger ? 'button_danger' : 'button_secondary');
            b.addEventListener('click', fn); return b;
        }
        function dialog(title) {
            var d = el('dialog', 'MessengerDialog MessengerGroupDialog', undefined, 'dialog');
            var header = el('header', 'MessengerGroupHeader');
            header.append(el('h2', '', title), action('Close', function () { d.close(); }));
            var body = el('div', 'MessengerGroupBody'), status = el('p', 'MessengerState'); status.setAttribute('role', 'status');
            d.append(header, body, status); root.append(d);
            d.addEventListener('close', function () { d.remove(); }); d.showModal();
            return { node: d, body: body, status: status };
        }
        function run(d, work, done) {
            if(d.node.dataset.busy === 'true')return;
            d.node.dataset.busy = 'true';d.node.setAttribute('aria-busy','true');d.status.textContent = '';
            return work().then(function (value) { if(d.node.isConnected) done(value); })
                .catch(function (error) { if(d.node.isConnected)d.status.textContent = error.message; })
                .finally(function () { d.node.dataset.busy = 'false';d.node.removeAttribute('aria-busy'); });
        }
        function selection(d, selected) {
            var query = el('input', '', undefined, 'input');query.type = 'search';query.placeholder = 'Find members';query.setAttribute('aria-label','Find group members');
            var picked = el('div', 'MessengerGroupSelected'), results = el('div', 'MessengerRecipientResults'), timer, epoch = 0;
            function redraw() { picked.replaceChildren();selected.forEach(function (u) { picked.append(action((u.title || u.name) + ' ×', function () { selected.delete(u.id);redraw(); })); }); }
            query.addEventListener('input', function () {
                clearTimeout(timer);var generation = ++epoch, q = query.value.trim();results.replaceChildren();if(q.length < 2)return;
                timer = setTimeout(function () { request('recipients', {}, { q:q, limit:20 }).then(function (users) {
                    if(!d.node.isConnected || generation !== epoch)return;results.replaceChildren();users.forEach(function(u) { results.append(action(u.title || u.name, function () { selected.set(u.id, u);redraw(); })); });
                }).catch(function(e) { if(d.node.isConnected && generation === epoch)d.status.textContent=e.message; }); },300);
            });
            d.node.addEventListener('close',function(){clearTimeout(timer);epoch++;});d.body.append(query,picked,results);redraw();
        }
        function newGroup() {
            if(pendingCreate && pendingCreate.inFlight){host.say('Group creation is still in progress.');return;}
            var d = dialog('New group'), title = el('input', '', undefined, 'input'), chosen = new Map();
            title.placeholder='Group name';title.maxLength=100;title.setAttribute('aria-label','Group name');d.body.append(title);
            if(pendingCreate){title.value=pendingCreate.title;pendingCreate.users.forEach(function(u){chosen.set(u.id,u);});}
            selection(d,chosen);
            d.body.append(el('p','MessengerState','People receive an invitation and choose whether to join. New members see messages sent after they join.'));
            d.body.append(action(pendingCreate?'Retry creation':'Create group',function(){
                var name=title.value.trim(),users=Array.from(chosen.values()),ids=users.map(function(u){return u.id;}).sort(function(a,b){return a-b;});
                if(!name || !ids.length){d.status.textContent='Enter a group name and choose at least one person.';return;}
                if(pendingCreate && (pendingCreate.title!==name || JSON.stringify(pendingCreate.ids)!==JSON.stringify(ids))){d.status.textContent='Retry the unconfirmed creation with its original name and members first.';return;}
                if(!pendingCreate)pendingCreate={title:name,users:users,ids:ids,clientId:host.clientId(),inFlight:false};
                var pending=pendingCreate;pending.inFlight=true;
                run(d,function(){return request('group-create',{method:'POST',body:{title:pending.title,user_ids:pending.ids,client_id:pending.clientId}}).then(function(g){pendingCreate=null;host.loadInbox(true);return g;}).catch(function(e){if(e.status>=400&&e.status<500&&e.status!==408&&e.status!==429)pendingCreate=null;throw e;}).finally(function(){pending.inFlight=false;});},function(g){d.node.close();host.openConversation(g.id);});
            }));
        }
        function invitations() {
            var d=dialog('Group invitations');
            run(d,function(){return request('group-invitations');},function(items){
                if(!items.length)d.body.append(el('p','MessengerState','No group invitations.'));
                items.forEach(function(invite){var row=el('section','MessengerGroupMember');row.append(el('strong','',invite.title),el('p','MessengerState','Invited by '+invite.inviter.name));
                    row.append(action('Join',function(){run(d,function(){return request('group-respond',{method:'POST',body:{id:invite.id,accept:true}});},function(g){d.node.close();host.loadInbox(true);host.openConversation(g.id);});}),action('Decline',function(){run(d,function(){return request('group-respond',{method:'POST',body:{id:invite.id,accept:false}});},function(){row.remove();});}));d.body.append(row);
                });
            });
        }
        function cropPhoto(file) {
            return new Promise(function(resolve,reject){
                if(!file || file.size>8*1024*1024 || !/^image\/(jpeg|png|webp)$/.test(file.type)){reject(new Error('Choose a JPEG, PNG or WebP photo up to 8 MB.'));return;}
                var image=new Image(),url=URL.createObjectURL(file);
                image.onerror=function(){URL.revokeObjectURL(url);reject(new Error('Could not read this photo.'));};
                image.onload=function(){try{var canvas=document.createElement('canvas');canvas.width=canvas.height=512;var size=Math.min(image.naturalWidth,image.naturalHeight);if(!size)throw new Error('Invalid photo.');canvas.getContext('2d').drawImage(image,(image.naturalWidth-size)/2,(image.naturalHeight-size)/2,size,size,0,0,512,512);resolve(canvas.toDataURL('image/jpeg',0.85));}catch(e){reject(e);}finally{URL.revokeObjectURL(url);}};image.src=url;
            });
        }
        function settings(id) {
            var d=dialog('Group information');
            function mutate(actionName,body,done){return run(d,function(){return request('group-'+actionName,{method:'POST',body:Object.assign({id:id},body)});},function(g){host.loadInbox(true);if(done)done(g);else{d.node.close();host.openConversation(id);}});}
            run(d,function(){return request('group-details',{}, {id:id});},function(g){
                d.body.append(el('p','MessengerState',g.member_count+' members · '+g.role));
                var admin=g.role==='owner'||g.role==='admin',owner=g.role==='owner';
                if(admin&&host.links)d.body.append(action('Invitation link',function(){d.node.close();host.links.settings(id);}));
                if(admin){var name=el('input','',undefined,'input');name.value=g.other_user.title;name.maxLength=100;name.setAttribute('aria-label','Group name');d.body.append(name,action('Save name',function(){mutate('rename',{title:name.value});}));
                    var selected=new Map();selection(d,selected);d.body.append(action('Invite selected people',function(){if(selected.size)mutate('invite',{user_ids:Array.from(selected.keys())});}));
                    var file=el('input','',undefined,'input');file.type='file';file.accept='image/jpeg,image/png,image/webp';file.setAttribute('aria-label','Choose group photo');var preview=el('img','MessengerGroupPhoto');preview.alt='Group photo preview';preview.hidden=true;var photo=null;
                    file.addEventListener('change',function(){cropPhoto(file.files[0]).then(function(data){if(!d.node.isConnected)return;photo=data;preview.src=data;preview.hidden=false;}).catch(function(e){if(d.node.isConnected)d.status.textContent=e.message;});});
                    d.body.append(file,preview,action('Save photo',function(){if(photo)mutate('avatar',{avatar:{kind:'file',filename:'group.jpg',mime_type:'image/jpeg',data_base64:photo.split(',')[1]}});}),action('Remove photo',function(){mutate('avatar',{avatar:null});}));
                }
                g.members.forEach(function(u){var row=el('section','MessengerGroupMember');row.append(el('strong','',u.title||u.name),el('span','MessengerState',u.role));
                    if(admin&&u.role!=='owner'&&(owner||u.role==='member'))row.append(action('Remove',function(){if(window.confirm('Remove '+(u.title||u.name)+' from the group?'))mutate('remove',{user_id:u.id});},true));
                    if(owner&&u.role!=='owner')row.append(action(u.role==='admin'?'Make member':'Make admin',function(){mutate('role',{user_id:u.id,role:u.role==='admin'?'member':'admin'});}),action('Transfer ownership',function(){if(window.confirm('Transfer group ownership to '+(u.title||u.name)+'?'))mutate('transfer',{user_id:u.id});}));d.body.append(row);
                });
                d.body.append(el('p','MessengerState','New members only see messages sent after joining. Leaving removes access to group messages and files.'));
                if(!owner)d.body.append(action('Leave group',function(){if(window.confirm('Leave this group?'))mutate('leave',{},function(){d.node.close();host.closeConversation();});},true));
                if(owner)d.body.append(action('Delete group for everyone',function(){if(window.confirm('Delete this group for all members?'))mutate('delete',{},function(){d.node.close();host.closeConversation();});},true));
            });
        }
        root.querySelector('.MessengerApp-header').append(action('New group',newGroup),action('Group invitations',invitations));
        return {settings:settings};
    };
}());
