'use strict';
(()=>{
const $=s=>document.querySelector(s), feed=$('#messageFeed'), scroller=$('#messageScroller');
let me=null,peer=null,cursor=0,items=new Map(),hasMore=false,oldest=0,replyTo=null,upload=null,record=null,recorder=null,recStream=null,recordChunks=[],recordStarted=0,recordTicker=null,polling=true,longPoll=true,failures=0,searching=false,loadingOlder=false,typingTimer=null,lastTypingAt=0,toastTimer=null,lastReadAt=0;
let csrf=$('meta[name="csrf-token"]').content;
const emojiList=['❤️','😊','😂','😍','🥹','👍','🔥','😘','😢','✨'];
const htmlDate=s=>new Date(String(s).includes('T')?String(s):String(s).replace(' ','T')+'Z');
function toast(text){const el=$('#toast');el.textContent=text;el.classList.add('visible');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('visible'),3400);}
async function api(action,body=null,params={}){
 const qs=new URLSearchParams({action,...params});const options={method:body===null?'GET':'POST',cache:'no-store',credentials:'same-origin'};
 if(body!==null){options.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};options.body=JSON.stringify(body);}
 const response=await fetch('api/index.php?'+qs,options);let data;try{data=await response.json();}catch{throw Error('Server response could not be read');}
 if(response.status===401){location.replace('index.php');throw Error('Session expired');}
 if(!data.ok)throw Error(data.error||'Request failed');return data;
}
function node(tag,cls='',text){const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;}
function openDialog(id){document.querySelectorAll('dialog[open]').forEach(d=>d.close());const d=document.getElementById(id);if(d){d.showModal(); if(id==='notesDialog')loadNotes();if(id==='galleryDialog')loadGallery();if(id==='favoritesDialog')loadFavorites();}}
function closeDialog(id){document.getElementById(id)?.close();}
document.querySelectorAll('.close-dialog').forEach(b=>b.addEventListener('click',()=>closeDialog(b.dataset.dialog)));
document.querySelectorAll('dialog').forEach(d=>d.addEventListener('click',e=>{if(e.target===d)d.close();}));
function isNearBottom(){return scroller.scrollHeight-scroller.scrollTop-scroller.clientHeight<180;}
function shortTime(s){return htmlDate(s).toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});}
function dayLabel(s){const d=htmlDate(s);const today=new Date(),y=new Date(today);y.setDate(today.getDate()-1);if(d.toDateString()===today.toDateString())return 'Today';if(d.toDateString()===y.toDateString())return 'Yesterday';return d.toLocaleDateString([],{month:'short',day:'numeric',year:'numeric'});}
function formatBytes(n){return n<1048576?Math.ceil(n/1024)+' KB':(n/1048576).toFixed(1)+' MB';}
function attachMedia(m){
 const wrap=node('div','media-card');const file=m.media;if(!file)return wrap;
 const src='media.php?id='+encodeURIComponent(file.id);
 if(file.mime.startsWith('image/')){const img=node('img','message-image');img.src=src;img.loading='lazy';img.alt=file.name;img.addEventListener('click',()=>viewImage(file.id));wrap.append(img);}
 else if(file.mime.startsWith('video/') && m.type!=='voice'){const video=node('video','message-video');video.controls=true;video.preload='metadata';video.playsInline=true;video.src=src;wrap.append(video);}
 else if(file.mime.startsWith('audio/')||m.type==='voice'){const audio=node('audio','message-audio');audio.controls=true;audio.preload='metadata';audio.src=src;wrap.append(audio);}
 else {const a=node('a','file-link','▤  '+file.name+' · '+formatBytes(file.bytes));a.href=src+'&download=1';a.download=file.name;wrap.append(a);}
 return wrap;
}
function viewImage(id){$('#lightboxImage').src='media.php?id='+id;$('#lightboxDownload').href='media.php?id='+id+'&download=1';openDialog('lightbox');}
function renderMessage(m){
 if(m.hidden)return null;
 const mine=m.sender_id===me.id;const row=node('div','message-row '+(mine?'mine':'theirs'));
 row.dataset.messageId=m.id;
 const bubble=node('div','message-bubble');
 if(m.reply_to){const target=items.get(m.reply_to);const ref=node('div','quoted','↳ '+(target?.body||'Earlier message'));ref.addEventListener('click',()=>document.querySelector(`[data-message-id="${m.reply_to}"]`)?.scrollIntoView({behavior:'smooth',block:'center'}));bubble.append(ref);}
 if(m.deleted){bubble.append(node('div','message-removed','Message removed for everyone'));}
 else {
  if(m.media)bubble.append(attachMedia(m));
  if(m.body)bubble.append(node('div','message-text',m.body));
  const reactions=node('div','message-reactions');m.reactions?.forEach(r=>{const chip=node('span','reaction-chip',r.emoji);chip.title=r.user_id===me.id?'Your reaction':'Partner reaction';reactions.append(chip);});bubble.append(reactions);
 }
 const footer=node('div','bubble-footer');if(m.favorite)footer.append(node('span','favorite-star','★'));
 if(m.edited_at)footer.append(node('span','edited-text','edited'));
 footer.append(node('time','',shortTime(m.created_at)));
 if(mine&&!m.deleted)footer.append(node('span','ticks '+(m.read?'read':''),m.read?'✓✓':m.delivered?'✓✓':'✓'));
 bubble.append(footer);
 if(!m.deleted){const more=node('button','message-more','···');more.type='button';more.title='Message options';more.setAttribute('aria-label','Message options');more.addEventListener('click',()=>showMessageMenu(m.id));row.append(bubble,more);}
 else row.append(bubble);
 return row;
}
function render(stick=false){const near=isNearBottom();const arr=[...items.values()].filter(m=>!m.hidden).sort((a,b)=>a.id-b.id);
 const fragment=document.createDocumentFragment();let lastDay='';for(const msg of arr){const day=dayLabel(msg.created_at);if(day!==lastDay){fragment.append(node('div','day-separator',day));lastDay=day;}const el=renderMessage(msg);if(el)fragment.append(el);}
 feed.replaceChildren(fragment);$('#emptyState').hidden=arr.length>0||searching;$('#loadOlder').hidden=!hasMore||searching;
 if(stick||near)requestAnimationFrame(()=>{scroller.scrollTop=scroller.scrollHeight;});
}
function mergeMessages(messages){for(const msg of messages){if(msg.hidden)items.delete(msg.id);else items.set(msg.id,msg);}oldest=Math.min(...items.keys(),Infinity);}
async function loadFirst(){const d=await api('messages');items.clear();mergeMessages(d.messages);hasMore=d.has_more;render(true);if(d.messages.some(m=>m.sender_id!==me.id))markRead();}
async function loadOlder(){if(loadingOlder||!hasMore||!Number.isFinite(oldest))return;loadingOlder=true;const height=scroller.scrollHeight;
 try{const d=await api('messages',null,{before:oldest});mergeMessages(d.messages);hasMore=d.has_more;render(false);requestAnimationFrame(()=>scroller.scrollTop+=scroller.scrollHeight-height);}catch(e){toast(e.message);}finally{loadingOlder=false;}}
$('#loadOlder').addEventListener('click',loadOlder);
scroller.addEventListener('scroll',()=>{if(scroller.scrollTop<80&&hasMore&&!searching)loadOlder();});
function setStatus(s){if(!s)return;$('#peerName').textContent=s.nickname||'Your person';$('#presenceDot').classList.toggle('active',s.online);
 $('#presenceText').textContent=s.typing?'typing…':s.online?'online':s.last_seen?'last seen '+htmlDate(s.last_seen).toLocaleString():'offline';
 $('#typingBubble').hidden=!s.typing||searching;
 document.title=s.unread>0?'('+s.unread+') Our Little Space':'Our Little Space · Just Between Us';
}
function onlineBanner(){ $('#offlineBanner').hidden=navigator.onLine&&failures<2; }
window.addEventListener('online',onlineBanner);window.addEventListener('offline',onlineBanner);
async function markRead(){if(document.hidden||searching||Date.now()-lastReadAt<2500)return;lastReadAt=Date.now();try{await api('read',{});}catch{}}
document.addEventListener('visibilitychange',()=>{if(!document.hidden)markRead();});
async function poll(){while(polling){try{
 const d=await api('sync',null,{cursor,wait:document.hidden||!longPoll?0:12});cursor=d.cursor;failures=0;onlineBanner();setStatus(d.status);
 if(d.messages.length&&!searching){const hasIncoming=d.messages.some(m=>m.sender_id!==me.id&&!m.deleted);mergeMessages(d.messages);render();if(hasIncoming)markRead();}
 }catch(e){failures++;longPoll=false;onlineBanner();if(failures===1)toast('Connection interrupted. Retrying.');if(e.message==='Session expired')return;}
 // Use low-frequency fallback after failures; long polling is used while healthy.
 await new Promise(r=>setTimeout(r,document.hidden?9000:failures?Math.min(15000,3000*failures):longPoll?700:4000));}}
function startTyping(){clearTimeout(typingTimer);if(Date.now()-lastTypingAt>2800){lastTypingAt=Date.now();api('typing',{typing:true}).catch(()=>{});}typingTimer=setTimeout(()=>api('typing',{typing:false}).catch(()=>{}),4000);}
$('#composerText').addEventListener('input',()=>{const ta=$('#composerText');ta.style.height='auto';ta.style.height=Math.min(130,ta.scrollHeight)+'px';startTyping();});
$('#composerText').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$('#composer').requestSubmit();}});
function resetReply(){replyTo=null;$('#replyPreview').hidden=true;}$('#replyCancel').addEventListener('click',resetReply);
function resetUpload(){upload=null;$('#attachmentInput').value='';$('#uploadPreview').hidden=true;$('#uploadPreview').querySelectorAll('img,video').forEach(e=>{URL.revokeObjectURL(e.src);e.remove();});}
$('#uploadCancel').addEventListener('click',resetUpload);
$('#attachmentInput').addEventListener('change',e=>{const f=e.target.files[0];if(!f)return;upload=f;const v=$('#uploadPreview');v.hidden=false;$('#uploadPreviewName').textContent=f.name+' · '+formatBytes(f.size);v.querySelectorAll('img,video').forEach(el=>el.remove());
 if(f.type.startsWith('image/')||f.type.startsWith('video/')){const el=node(f.type.startsWith('image/')?'img':'video','small-preview');el.src=URL.createObjectURL(f);if(el.tagName==='VIDEO'){el.controls=true;el.muted=true;}v.prepend(el);}
});
$('#attachOpen').addEventListener('click',()=>$('#attachmentInput').click());
$('#emojiOpen').addEventListener('click',()=>$('#emojiTray').hidden=!$('#emojiTray').hidden);
$('#emojiTray').querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{$('#composerText').value+=b.textContent;$('#composerText').focus();$('#emojiTray').hidden=true;}));
async function uploadViaXHR(file,body='',reply=0,voice=false){return new Promise((resolve,reject)=>{
 const xhr=new XMLHttpRequest();xhr.open('POST','api/index.php?action=upload');xhr.setRequestHeader('X-CSRF-Token',csrf);xhr.responseType='json';
 xhr.upload.onprogress=e=>{if(e.lengthComputable){$('#uploadProgress').hidden=false;$('#uploadProgress').value=Math.round(100*e.loaded/e.total);$('#uploadProgressText').textContent=$('#uploadProgress').value+'%';}};
 xhr.onload=()=>{const d=xhr.response;if(xhr.status===401){location.replace('index.php');return;}if(d?.ok)resolve(d);else reject(Error(d?.error||'Upload failed'));};xhr.onerror=()=>reject(Error('Network upload failed'));
 const form=new FormData();form.append('file',file);form.append('body',body);if(reply)form.append('reply_to',reply);if(voice)form.append('voice','1');xhr.send(form);
});}
$('#composer').addEventListener('submit',async e=>{e.preventDefault();const text=$('#composerText').value.trim();if(!text&&!upload)return;
 const button=$('#sendButton');button.disabled=true;try{const d=upload?await uploadViaXHR(upload,text,replyTo):await api('send',{body:text,reply_to:replyTo});
 items.set(d.message.id,d.message);render(true);$('#composerText').value='';$('#composerText').style.height='auto';resetReply();resetUpload();$('#uploadProgress').hidden=true;$('#uploadProgressText').textContent='';api('typing',{typing:false}).catch(()=>{});
 }catch(e){toast(e.message);}finally{button.disabled=false;}});
function showMessageMenu(id){const m=items.get(id);if(!m)return;const actions=$('#messageActions');actions.replaceChildren();
 const add=(label,fn)=>{const b=node('button','',label);b.addEventListener('click',()=>{closeDialog('messageMenu');fn();});actions.append(b);};
 add('↳ Reply',()=>{replyTo=m.id;$('#replyText').textContent=m.body||m.media?.name||'Media';$('#replyPreview').hidden=false;$('#composerText').focus();});
 if(m.body)add('▣ Copy text',()=>navigator.clipboard.writeText(m.body).then(()=>toast('Copied')).catch(()=>toast('Unable to copy')));
 add(m.favorite?'☆ Remove favorite':'★ Save favorite',async()=>{try{await api('favorite',{id,favorite:!m.favorite});m.favorite=!m.favorite;render();}catch(e){toast(e.message);}});
 if(m.sender_id===me.id&&m.body)add('✎ Edit message',async()=>{const v=prompt('Edit your message',m.body);if(v===null)return;try{await api('edit',{id,body:v});m.body=v;m.edited_at=new Date().toISOString();render();}catch(e){toast(e.message);}});
 add('⊖ Delete for me',async()=>{if(!confirm('Hide this message from your view?'))return;try{await api('delete',{id});items.delete(id);render();}catch(e){toast(e.message);}});
 if(m.sender_id===me.id)add('✕ Delete for both',async()=>{if(!confirm('Remove this message for both people?'))return;try{await api('delete',{id,for_all:true});m.body='';m.deleted=true;m.media=null;render();}catch(e){toast(e.message);}});
 const picker=$('#reactionPicker');picker.replaceChildren();for(const emoji of [...emojiList,'×']){const b=node('button','',emoji);b.addEventListener('click',async()=>{closeDialog('messageMenu');try{await api('react',{id,emoji:emoji==='×'?'':emoji});}catch(e){toast(e.message);}});picker.append(b);}openDialog('messageMenu');}
// MediaRecorder: request microphone only on a direct user action.
$('#recordOpen').addEventListener('click',async()=>{if(!navigator.mediaDevices?.getUserMedia||typeof MediaRecorder==='undefined'){toast('Voice recording is not supported here. Use HTTPS and a supported browser.');return;}
 try{recStream=await navigator.mediaDevices.getUserMedia({audio:true});
 const supported=typeof MediaRecorder.isTypeSupported==='function'?['audio/webm;codecs=opus','audio/mp4','audio/ogg;codecs=opus'].find(x=>MediaRecorder.isTypeSupported(x)):null;recorder=new MediaRecorder(recStream,supported?{mimeType:supported}:undefined);
 recordChunks=[];record=null;recordStarted=Date.now();$('#recordStrip').hidden=false;$('#recordStop').hidden=false;$('#recordSend').hidden=true;$('#recordPreview').hidden=true;
 recorder.ondataavailable=e=>{if(e.data?.size)recordChunks.push(e.data);};
 recorder.onstop=()=>{const mime=recorder.mimeType||'audio/webm';record=new Blob(recordChunks,{type:mime});const audio=$('#recordPreview');audio.src=URL.createObjectURL(record);audio.hidden=false;$('#recordStop').hidden=true;$('#recordSend').hidden=false;recStream?.getTracks().forEach(t=>t.stop());};
 recorder.start(300);recordTicker=setInterval(()=>{let seconds=Math.floor((Date.now()-recordStarted)/1000);$('#recordTimer').textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');if(seconds>=180)recorder.state==='recording'&&recorder.stop();},250);
 }catch(e){toast('Microphone unavailable or permission denied');recStream?.getTracks().forEach(t=>t.stop());}});
$('#recordStop').addEventListener('click',()=>{clearInterval(recordTicker);if(recorder?.state==='recording')recorder.stop();});
function clearRecord(){clearInterval(recordTicker);if(recorder?.state==='recording'){recorder.onstop=null;recorder.stop();}recStream?.getTracks().forEach(t=>t.stop());const a=$('#recordPreview');if(a.src){URL.revokeObjectURL(a.src);a.removeAttribute('src');}record=null;$('#recordStrip').hidden=true;}
$('#recordCancel').addEventListener('click',clearRecord);
$('#recordSend').addEventListener('click',async()=>{if(!record)return;const mime=record.type;const ext=mime.includes('mp4')?'m4a':mime.includes('ogg')?'ogg':'webm';try{const d=await uploadViaXHR(new File([record],'voice-note.'+ext,{type:mime}),'',0,true);items.set(d.message.id,d.message);render(true);clearRecord();toast('Voice note sent');}catch(e){toast(e.message);}});
$('#galleryOpen').addEventListener('click',()=>openDialog('galleryDialog'));$('#moreOpen').addEventListener('click',()=>openDialog('moreDialog'));
document.querySelectorAll('[data-open]').forEach(b=>b.addEventListener('click',()=>openDialog(b.dataset.open)));
$('#searchToggle').addEventListener('click',()=>{$('#searchStrip').hidden=false;$('#searchInput').focus();});
$('#searchClose').addEventListener('click',()=>{searching=false;$('#searchStrip').hidden=true;$('#searchInput').value='';loadFirst().catch(e=>toast(e.message));});
let searchTimer=null;$('#searchInput').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(async()=>{const q=$('#searchInput').value.trim();if(!q){searching=false;await loadFirst();return;}searching=true;try{const d=await api('messages',null,{q});items.clear();mergeMessages(d.messages);hasMore=false;render(true);}catch(e){toast(e.message);}},350);});
async function loadGallery(){try{const d=await api('gallery');const grid=$('#galleryGrid');grid.replaceChildren();if(!d.gallery.length)grid.append(node('p','subtle','No shared files yet.'));
 d.gallery.forEach(m=>{const tile=node('div','gallery-item');const src='media.php?id='+m.id;
 if(m.mime_type.startsWith('image/')){const img=node('img');img.src=src;img.loading='lazy';img.alt=m.original_name;img.addEventListener('click',()=>viewImage(m.id));tile.append(img);}
 else if(m.message_type==='voice'||m.mime_type.startsWith('audio/')){const a=node('audio');a.src=src;a.controls=true;a.preload='metadata';a.style.maxWidth='100%';tile.append(a);}
 else if(m.mime_type.startsWith('video/')){const v=node('video');v.src=src;v.controls=true;v.preload='metadata';tile.append(v);}
 else {const a=node('a','',m.original_name);a.href=src+'&download=1';a.download=m.original_name;tile.append(a);}
 if(Number(m.sender_id)===me.id){const remove=node('button','gallery-remove','×');remove.title='Delete shared file for both';remove.addEventListener('click',async()=>{if(!confirm('Delete this shared file for both people?'))return;try{await api('delete',{id:Number(m.message_id),for_all:true});items.delete(Number(m.message_id));render();loadGallery();}catch(e){toast(e.message);}});tile.append(remove);}grid.append(tile);});}catch(e){toast(e.message);}}
async function loadFavorites(){try{const d=await api('favorites');const wrap=$('#favoritesList');wrap.replaceChildren();if(!d.messages.length)wrap.append(node('p','subtle','You haven’t pinned anything yet.'));
 d.messages.forEach(m=>{const item=node('div','list-entry');item.append(node('small','',shortTime(m.created_at)),node('p','',m.body||m.media?.name||'Shared media'));wrap.append(item);});}catch(e){toast(e.message);}}
async function loadNotes(){try{const d=await api('notes');const list=$('#notesList');list.replaceChildren();if(!d.notes.length)list.append(node('p','subtle','Write a note for both of you.'));
 d.notes.forEach(n=>{const item=node('div','note-card');item.append(node('p','',n.body),node('small','',htmlDate(n.created_at).toLocaleDateString()));
 if(Number(n.author_id)===me.id){const edit=node('button','text-button','Edit'),del=node('button','text-button danger-text','Delete');edit.addEventListener('click',()=>{$('#noteEditId').value=n.id;$('#noteText').value=n.body;$('#noteText').focus();});del.addEventListener('click',async()=>{if(!confirm('Delete note?'))return;await api('note_delete',{id:n.id});loadNotes();});item.append(edit,del);}list.append(item);});}catch(e){toast(e.message);}}
$('#noteForm').addEventListener('submit',async e=>{e.preventDefault();try{await api('note_save',{id:$('#noteEditId').value,body:$('#noteText').value});e.target.reset();$('#noteEditId').value='';loadNotes();}catch(e){toast(e.message);}});
$('#profileForm').addEventListener('submit',async e=>{e.preventDefault();try{await api('profile',{nickname:$('#nicknameInput').value});toast('Nickname saved');me.nickname=$('#nicknameInput').value;}catch(e){toast(e.message);}});
$('#avatarInput').addEventListener('change',async e=>{const file=e.target.files[0];if(!file)return;try{
 const xhr=new XMLHttpRequest();const promise=new Promise((resolve,reject)=>{xhr.open('POST','api/index.php?action=avatar');xhr.setRequestHeader('X-CSRF-Token',csrf);xhr.responseType='json';xhr.onload=()=>xhr.response?.ok?resolve():reject(Error(xhr.response?.error||'Upload failed'));xhr.onerror=()=>reject(Error('Upload failed'));const fd=new FormData();fd.append('file',file);xhr.send(fd);});await promise;toast('Photo updated');const avatar=node('img');avatar.src='media.php?avatar='+me.id+'&v='+Date.now();$('#myAvatarLarge').replaceChildren(avatar);}catch(e){toast(e.message);}});
$('#avatarRemove').addEventListener('click',async()=>{try{await api('avatar_remove',{});$('#myAvatarLarge').replaceChildren(node('span','','♥'));toast('Profile photo removed');}catch(e){toast(e.message);}});
$('#passwordForm').addEventListener('submit',async e=>{e.preventDefault();try{await api('password_change',Object.fromEntries(new FormData(e.target)));location.replace('index.php');}catch(e){toast(e.message);}});
$('#logoutButton').addEventListener('click',async()=>{await api('logout',{});location.replace('index.php');});
$('#logoutAllButton').addEventListener('click',async()=>{if(!confirm('Log out of all devices?'))return;await api('logout_all',{});location.replace('index.php');});
function updateDays(date){if(!date){$('#daysCounter').textContent='Choose a day you want to remember.';return;}const n=Math.floor((Date.now()-new Date(date+'T00:00:00').getTime())/86400000);$('#daysCounter').textContent=n===0?'Our special day ♡':n>0?n+' days since our special day ♡':Math.abs(n)+' days until our special day ♡';}
$('#dateForm').addEventListener('submit',async e=>{e.preventDefault();try{await api('special_date',{date:$('#specialDate').value});updateDays($('#specialDate').value);toast('Date saved for both of you');}catch(e){toast(e.message);}});
document.querySelectorAll('[data-wallpaper]').forEach(b=>b.addEventListener('click',async()=>{try{await api('wallpaper',{wallpaper:b.dataset.wallpaper});document.body.dataset.wallpaper=b.dataset.wallpaper;closeDialog('wallpaperDialog');toast('Wallpaper updated');}catch(e){toast(e.message);}}));
$('#wallpaperInput').addEventListener('change',async e=>{const file=e.target.files[0];if(!file)return;try{const xhr=new XMLHttpRequest();await new Promise((resolve,reject)=>{xhr.open('POST','api/index.php?action=wallpaper_upload');xhr.setRequestHeader('X-CSRF-Token',csrf);xhr.responseType='json';xhr.onload=()=>xhr.response?.ok?resolve():reject(Error(xhr.response?.error||'Upload failed'));xhr.onerror=()=>reject(Error('Network error'));const fd=new FormData();fd.append('file',file);xhr.send(fd);});document.body.dataset.wallpaper='charcoal';requestAnimationFrame(()=>document.body.dataset.wallpaper='custom');closeDialog('wallpaperDialog');toast('Wallpaper uploaded');}catch(err){toast(err.message);}});
async function init(){try{const d=await api('me');me=d.me;peer=d.peer;cursor=d.cursor;csrf=d.csrf;
 $('#peerName').textContent=peer.nickname;$('#nicknameInput').value=me.nickname;$('#myRole').textContent=me.role==='owner'?'Your private account':'Partner account';
 if(peer.avatar){$('#partnerAvatar').replaceChildren(node('img'));$('#partnerAvatar img').src='media.php?avatar='+peer.id;}
 if(me.avatar){$('#myAvatarLarge').replaceChildren(node('img'));$('#myAvatarLarge img').src='media.php?avatar='+me.id;}
 const settings=Object.fromEntries(d.settings.map(x=>[x.setting_key,x.setting_value]));document.body.dataset.wallpaper=settings.wallpaper||'charcoal';$('#specialDate').value=settings.special_date||'';updateDays(settings.special_date);
 setStatus(d.status);await loadFirst();poll();markRead();if('serviceWorker'in navigator)navigator.serviceWorker.register('service-worker.js').catch(()=>{});
 }catch(e){toast(e.message);}}
init();
})();
