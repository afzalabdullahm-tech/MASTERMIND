/* Never intercept or cache authenticated routes, private chat HTML, API requests or media. */
'use strict';
const STATIC_CACHE='little-space-static-v1';
const STATIC_ASSETS=['./assets/css/style.css','./assets/js/game.js','./assets/js/public.js','./assets/js/chat.js','./assets/icons/icon.svg','./assets/icons/icon-192.png','./assets/icons/icon-512.png'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(STATIC_CACHE).then(cache=>cache.addAll(STATIC_ASSETS)));self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(x=>x!==STATIC_CACHE).map(x=>caches.delete(x)))));self.clients.claim();});
self.addEventListener('fetch',event=>{
 const url=new URL(event.request.url);
 if(event.request.method!=='GET'||url.origin!==self.location.origin||!STATIC_ASSETS.some(p=>url.pathname===new URL(p,self.registration.scope).pathname))return;
 event.respondWith(caches.match(event.request).then(hit=>hit||fetch(event.request)));
});
