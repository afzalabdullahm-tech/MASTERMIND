'use strict';
document.querySelectorAll('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{
 const target=document.getElementById(button.dataset.copy); if(!target)return;
 try {await navigator.clipboard.writeText(target.textContent); button.textContent='Copied';}
 catch {const r=document.createRange();r.selectNodeContents(target);const s=window.getSelection();s.removeAllRanges();s.addRange(r);button.textContent='Select & copy';}
}));
document.getElementById('downloadRecovery')?.addEventListener('click',()=>{
 const text='Our Little Space — Recovery Codes\nKeep private. Each code can only be used once.\n\n'+document.getElementById('recoveryCodes').textContent;
 const url=URL.createObjectURL(new Blob([text],{type:'text/plain'}));const a=document.createElement('a');a.href=url;a.download='our-little-space-recovery-codes.txt';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
});
