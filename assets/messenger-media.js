(function(){
    'use strict';
    var MAX_FILE=8*1024*1024,MAX_BATCH=32*1024*1024;
    function dimensions(b,mime){
        var view=new DataView(b.buffer,b.byteOffset,b.byteLength),w=0,h=0;
        if(mime==='image/png'&&b.length>=24&&view.getUint32(0)===0x89504e47&&view.getUint32(12)===0x49484452){w=view.getUint32(16);h=view.getUint32(20);}
        else if(mime==='image/gif'&&b.length>=10&&String.fromCharCode.apply(null,b.slice(0,6)).match(/^GIF8[79]a$/)){w=view.getUint16(6,true);h=view.getUint16(8,true);}
        else if(mime==='image/jpeg'&&b[0]===255&&b[1]===216){
            var p=2;while(p+4<b.length){if(b[p++]!==255)break;while(p<b.length&&b[p]===255)p++;if(p+3>=b.length)break;var marker=b[p++];if(marker===217||marker===218)break;var n=view.getUint16(p);if(n<2||p+n>b.length)break;
                if([192,193,194,195,197,198,199,201,202,203,205,206,207].includes(marker)&&n>=7){h=view.getUint16(p+3);w=view.getUint16(p+5);break;}p+=n;
            }
        }else if(mime==='image/webp'&&b.length>=30&&view.getUint32(0)===0x52494646&&view.getUint32(8)===0x57454250){
            var kind=String.fromCharCode.apply(null,b.slice(12,16));
            if(kind==='VP8X'){w=1+b[24]+(b[25]<<8)+(b[26]<<16);h=1+b[27]+(b[28]<<8)+(b[29]<<16);}
            else if(kind==='VP8L'&&b[20]===47){var bits=view.getUint32(21,true);w=(bits&16383)+1;h=((bits>>>14)&16383)+1;}
            else if(kind==='VP8 '&&b[23]===157&&b[24]===1&&b[25]===42){w=view.getUint16(26,true)&16383;h=view.getUint16(28,true)&16383;}
        }
        return w>0&&h>0&&w<=8000&&h<=8000&&w*h<=16000000?{width:w,height:h}:null;
    }
    function randomId(){var b=new Uint8Array(32);window.crypto.getRandomValues(b);return btoa(String.fromCharCode.apply(null,b)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
    function encodedFile(file){return new Promise(function(resolve,reject){var r=new FileReader();r.onerror=function(){reject(new Error('Could not read this file.'));};r.onload=function(){resolve(String(r.result).split(',')[1]);};r.readAsDataURL(file);});}
    window.ProcessWireMessengerMedia={dimensions:dimensions,create:function(host){
        var el=host.el,button=host.button,request=host.request,pending=null,recording=null,recordGeneration=0;
        function action(text,fn){var b=button(text,'');b.addEventListener('click',fn);return b;}
        function sheet(title){var d=el('dialog','MessengerDialog MessengerMediaDialog',undefined,'dialog'),header=el('header','MessengerGroupHeader'),body=el('div','MessengerMediaBody'),status=el('p','MessengerState');status.setAttribute('role','status');
            header.append(el('h2','',title),action('Close',function(){if(d.dataset.busy!=='true')d.close();}));d.append(header,body,status);host.root.append(d);var urls=[];
            d.addEventListener('cancel',function(e){if(d.dataset.busy==='true')e.preventDefault();});d.addEventListener('close',function(){urls.forEach(function(u){URL.revokeObjectURL(u);});d.querySelectorAll('audio,video').forEach(function(m){m.pause();m.removeAttribute('src');m.load();});d.remove();});d.showModal();
            return{node:d,body:body,status:status,url:function(blob){var u=URL.createObjectURL(blob);urls.push(u);return u;}};
        }
        async function preview(d,file){
            var row=el('div','MessengerMediaItem');row.append(el('span','',file.name));d.body.append(row);
            if(/^image\/(jpeg|png|gif|webp)$/.test(file.type)){
                var bytes=new Uint8Array(await file.arrayBuffer());if(!d.node.isConnected)return;var size=dimensions(bytes,file.type);
                if(size&&typeof createImageBitmap==='function'){
                    var scale=Math.min(1,320/Math.max(size.width,size.height)),bitmap;
                    try{bitmap=await createImageBitmap(file,{resizeWidth:Math.max(1,Math.round(size.width*scale)),resizeHeight:Math.max(1,Math.round(size.height*scale))});var canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(size.width*scale));canvas.height=Math.max(1,Math.round(size.height*scale));canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);var thumb=await new Promise(function(resolve){canvas.toBlob(resolve,'image/jpeg',0.8);});if(thumb&&d.node.isConnected){var image=el('img','MessengerMediaThumb');image.alt=file.name;image.src=d.url(thumb);row.prepend(image);}}catch(e){/* Keep the filename when the browser cannot decode this bounded photo. */}finally{if(bitmap)bitmap.close();}
                }
            }
        }
        async function sendPreview(chat,files,kind,duration){
            if(!files.length||files.length>10||files.some(function(f){return !f.name||f.name.length>180||/[\x00-\x1f\x7f\\/]/.test(f.name)||f.size<1||f.size>(kind==='voice'?4*1024*1024:MAX_FILE);})||files.reduce(function(n,f){return n+f.size;},0)>MAX_BATCH){host.say('Choose up to 10 files, 8 MB each and 32 MB total. Voice: up to 4 MB.',true);return;}
            var quiet=el('input');quiet.type='checkbox';quiet.checked=!!(pending&&pending.items[0].silent);quiet.disabled=!!pending;
            var d=sheet('Send attachments'),caption=el('textarea','',undefined,'textarea');caption.placeholder='Optional caption';caption.rows=2;caption.maxLength=Number(host.root.dataset.maxLength)||5000;caption.setAttribute('aria-label','Attachment caption');
            if(pending&&pending.id===chat.id){caption.value=pending.items[0].body;caption.disabled=true;d.status.textContent='Unconfirmed upload. Retry keeps its original files and caption.';}
            d.body.append(caption);for(var i=0;i<files.length&&d.node.isConnected;i++)try{await preview(d,files[i]);}catch(e){d.status.textContent='Photo preview unavailable; the original file can still be sent.';}if(!d.node.isConnected)return;
            if((host.supportsSilent&&host.supportsSilent())||quiet.checked){var choice=el('label','MessengerMediaSilent');choice.append(quiet,el('span','','Send without notification'));d.body.append(choice);}
            var send=action('Send',async function(){
                if(d.node.dataset.busy==='true')return;d.node.dataset.busy='true';send.disabled=true;d.status.textContent='Sending…';
                var selectedSilent=pending?pending.items[0].silent===true:quiet.checked;quiet.disabled=true;
                try{
                    var account=await request('session');if(selectedSilent&&account.silent_messages_supported!==true)throw new Error('Sending without notification is unavailable on this website.');if(account.user_id!==host.memberId())throw new Error('Your account changed. Reload Messenger before sending.');
                    if(!pending){var album=files.length>1&&files.every(function(f){return /^image\/(jpeg|png|gif|webp)$/.test(f.type);})?randomId():null;
                        var immutable=[];for(var index=0;index<files.length;index++){var file=files[index];immutable.push({client_id:album?'album_'+album+'_'+index:host.clientId(),kind:kind||'file',filename:file.name,mime_type:file.type||'application/octet-stream',data_base64:await encodedFile(file),body:index===0?caption.value.trim():'',duration_ms:kind==='voice'?duration:null,silent:selectedSilent,done:false});}
                        pending={id:chat.id,files:files,kind:kind,duration:duration,items:immutable};
                    }
                    if(pending.id!==chat.id)throw new Error('Retry the unconfirmed attachments in their original conversation first.');caption.disabled=true;
                    for(var item of pending.items){if(item.done)continue;var payload=Object.assign({id:chat.id},item);delete payload.done;if(payload.duration_ms===null)delete payload.duration_ms;var ack=await request('send-attachment',{method:'POST',body:payload});if(!ack||!Number.isSafeInteger(ack.id)||ack.id<1||ack.conversation_id!==chat.id||ack.sender_user_id!==host.memberId()||!ack.attachment||ack.attachment.filename!==item.filename||(ack.silent===true)!==(item.silent===true))throw new Error('Attachment acknowledgement unavailable. Retry the original upload.');item.done=true;item.data_base64=null;}
                    pending=null;d.node.dataset.busy='false';d.node.close();host.refresh(chat.id);host.say('Attachments sent.');
                }catch(e){d.status.textContent=e.message;if(e.status>=400&&e.status<500&&e.status!==408&&e.status!==425&&e.status!==429)pending=null;}
                finally{d.node.dataset.busy='false';send.disabled=false;quiet.disabled=!!pending;caption.disabled=!!pending;}
            });d.body.append(send);if(pending)d.body.append(action('Cancel remaining',function(){if(d.node.dataset.busy!=='true'&&window.confirm('Cancel remaining uploads? Accepted files stay in the conversation; an unconfirmed file may already have been sent.')){pending=null;d.node.close();}}));
        }
        function choose(chat){
            if(pending){if(pending.id===chat.id)sendPreview(chat,pending.files,pending.kind,pending.duration);else host.say('Retry the unconfirmed attachments in their original conversation first.',true);return;}
            var d=sheet('Attach');[['Gallery','image/*,video/*'],['Files',''],['Camera','image/*']].forEach(function(entry){var input=el('input');input.type='file';input.accept=entry[1];input.multiple=entry[0]!=='Camera';input.hidden=true;if(entry[0]==='Camera')input.setAttribute('capture','environment');input.addEventListener('change',function(){if(!d.node.isConnected)return;var files=Array.from(input.files||[]);d.node.close();if(files.length)sendPreview(chat,files);});d.body.append(action(entry[0],function(){input.click();}),input);});
        }
        async function record(chat){
            if(recording)return;if(!window.MediaRecorder||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){host.say('Voice recording is unavailable in this browser. Choose an audio file instead.',true);return;}
            var d=sheet('Voice message'),stream,recorder,timer,chunks=[],size=0,cancelled=false,started=0,tooLarge=false,generation=++recordGeneration;recording={generation:generation,state:'pending'};
            function release(){clearTimeout(timer);if(stream)stream.getTracks().forEach(function(track){track.stop();});if(recording&&recording.generation===generation){recording=null;if(host.activityRecording)host.activityRecording(chat.id,false);}}
            d.node.addEventListener('close',function(){cancelled=true;if(recorder&&recorder.state==='recording')recorder.stop();release();});
            try{stream=await navigator.mediaDevices.getUserMedia({audio:true});if(!d.node.isConnected){release();return;}
                var type=['audio/mp4','audio/webm;codecs=opus','audio/ogg;codecs=opus'].find(function(t){return MediaRecorder.isTypeSupported(t);});if(!type)throw new Error('Voice recording format unavailable. Choose an audio file instead.');
                recorder=new MediaRecorder(stream,{mimeType:type});recorder.generation=generation;recording=recorder;started=Date.now();d.status.textContent='Recording · up to 2 minutes';
                recorder.ondataavailable=function(e){if(e.data.size){size+=e.data.size;if(size>4*1024*1024){tooLarge=true;if(recorder.state==='recording')recorder.stop();}else chunks.push(e.data);}};
                recorder.onerror=function(){cancelled=true;release();d.status.textContent='Recording failed. Please try again.';};
                recorder.onstop=function(){var ms=Math.min(120000,Math.max(1,Date.now()-started)),mime=type.split(';')[0],file=new File(chunks,['voice',mime==='audio/mp4'?'m4a':mime==='audio/ogg'?'ogg':'webm'].join('.'),{type:mime});release();if(cancelled)return;if(tooLarge){d.status.textContent='Voice message is too large. Please record a shorter message.';return;}d.node.close();sendPreview(chat,[file],'voice',ms);};
                var stop=action('Stop',function(){if(recorder.state==='recording'){stop.disabled=true;recorder.stop();}});d.body.append(stop,action('Cancel',function(){d.node.close();}));timer=setTimeout(function(){if(recorder.state==='recording')recorder.stop();},120000);recorder.start(250);if(host.activityRecording)host.activityRecording(chat.id,true);
            }catch(e){release();d.status.textContent=e.message;}
        }
        function attach(form,chat){if(!chat.can_attach)return;form.append(action('Attach',function(){choose(chat);}),action('Voice message',function(){record(chat);}));}
        function attachment(bubble,item){var a=item.attachment;if(!a||item.deleted_at)return;var kind=a.kind==='voice'?'Voice message':/^image\//.test(a.mime_type)?'View photo':/^video\//.test(a.mime_type)?'View video':'Open file';
            var open=action(kind,async function(){open.disabled=true;try{
                var data=await request('attachment',{}, {id:a.id});if(!open.isConnected)return;if(data.id!==a.id||typeof data.data_base64!=='string'||data.data_base64.length>11184812||typeof data.filename!=='string'||data.filename.length>180||/[\x00-\x1f\x7f\\/]/.test(data.filename))throw new Error('Attachment unavailable.');
                var raw=atob(data.data_base64);if(raw.length<1||raw.length>MAX_FILE||raw.length!==data.size)throw new Error('Attachment unavailable.');var bytes=Uint8Array.from(raw,function(c){return c.charCodeAt(0);}),blob=new Blob([bytes],{type:data.mime_type||'application/octet-stream'}),d=sheet(data.filename),url=d.url(blob),node;
                if(dimensions(bytes,data.mime_type)){node=el('img','MessengerMediaFull');node.alt=data.filename;}
                else if(['video/mp4','video/webm'].includes(data.mime_type)){node=el('video','MessengerMediaFull');node.controls=true;node.preload='metadata';}
                else if(a.kind==='voice'&&['audio/mp4','audio/mpeg','audio/aac','audio/ogg','audio/wav','audio/x-wav','audio/webm','audio/3gpp'].includes(data.mime_type)){node=el('audio');node.controls=true;node.preload='metadata';}
                if(node){node.src=url;d.body.append(node);}var download=el('a','','Download file');download.href=url;download.download=data.filename;d.body.append(download);
            }catch(e){host.say(e.message,true);}finally{open.disabled=false;}});bubble.append(open);
        }
        window.addEventListener('pagehide',function(){if(recording&&recording.state==='recording')recording.stop();host.root.querySelectorAll('.MessengerMediaDialog').forEach(function(d){d.close();});pending=null;});
        var photoCache=new Map(),photoQueue=Promise.resolve();
        function groupPhoto(node,chat){
            if(chat.type!=='group'||!chat.has_avatar)return;
            var key=host.memberId()+':'+chat.id+':'+chat.avatar_version;
            function display(url){if(node.isConnected){var image=el('img','MessengerGroupPhoto');image.alt='';image.src=url;node.textContent='';node.append(image);}}
            if(photoCache.has(key)){display(photoCache.get(key));return;}
            photoQueue=photoQueue.catch(function(){}).then(async function(){
                if(!node.isConnected)return;if(photoCache.has(key)){display(photoCache.get(key));return;}
                var result=await request('group-avatar',{}, {id:chat.id}),a=result.avatar;
                if(!node.isConnected||!a||typeof a.data_base64!=='string'||a.data_base64.length>699052||!['image/png','image/jpeg','image/webp'].includes(a.mime_type))return;
                var raw=atob(a.data_base64);if(raw.length>524288)return;var bytes=Uint8Array.from(raw,function(c){return c.charCodeAt(0);});if(!dimensions(bytes,a.mime_type))return;
                var url=URL.createObjectURL(new Blob([bytes],{type:a.mime_type}));photoCache.set(key,url);display(url);
                while(photoCache.size>16){var oldest=photoCache.keys().next().value;URL.revokeObjectURL(photoCache.get(oldest));photoCache.delete(oldest);}
            }).catch(function(){/* Initials remain when private photo access is unavailable. */});
        }
        window.addEventListener('pagehide',function(){photoCache.forEach(function(url){URL.revokeObjectURL(url);});photoCache.clear();});
        return{attach:attach,attachment:attachment,groupPhoto:groupPhoto};
    }};
}());
