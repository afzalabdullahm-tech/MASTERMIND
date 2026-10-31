'use strict';
(()=>{
 const login=document.getElementById('loginDialog'),recovery=document.getElementById('recoveryDialog');
 document.getElementById('loginOpen').addEventListener('click',()=>login.showModal());
 document.getElementById('recoveryOpen').addEventListener('click',()=>{login.close();recovery.showModal();});
 document.querySelectorAll('.close-dialog').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.dialog).close()));
 document.getElementById('togglePassword').addEventListener('click',e=>{const f=document.getElementById('privatePassword');f.type=f.type==='password'?'text':'password';e.target.textContent=f.type==='password'?'Show':'Hide';});
 const csrf=document.querySelector('meta[name="csrf-token"]').content;
 async function post(action,data){const response=await fetch('api/index.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data),cache:'no-store'});return response.json();}
 document.getElementById('loginForm').addEventListener('submit',async e=>{e.preventDefault();const button=document.getElementById('loginSubmit');button.disabled=true;button.textContent='Unlocking…';document.getElementById('loginError').textContent='';try {const r=await post('login',{password:document.getElementById('privatePassword').value});if(!r.ok)throw Error(r.error);location.assign('chat.php');}catch(err){document.getElementById('loginError').textContent=err.message||'Connection error';button.disabled=false;button.textContent='Unlock space';}});
 document.getElementById('recoveryForm').addEventListener('submit',async e=>{e.preventDefault();const d=Object.fromEntries(new FormData(e.target));const out=document.getElementById('recoveryError');out.textContent='';try{const r=await post('recover',d);if(!r.ok)throw Error(r.error);out.textContent='Password updated. Return to login.';e.target.reset();}catch(err){out.textContent=err.message||'Connection error';}});
 if('serviceWorker' in navigator)navigator.serviceWorker.register('service-worker.js').catch(()=>{});
})();
