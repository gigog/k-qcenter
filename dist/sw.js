'use strict';
const CACHE='k-qonline-v12';
const APP_SHELL=['./','online.html','status.html','public-queue.css?v=11','online-shell.css?v=11','ticket.css','thai-date.js?v=11','online-shell.js?v=11','online.js?v=11','status.js?v=12','qrcode.js','manifest.webmanifest','icons/app-icon.svg','icons/app-icon-192.png','icons/app-icon-512.png'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(APP_SHELL)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{const request=event.request;const url=new URL(request.url);if(request.method!=='GET'||url.pathname.includes('/api/'))return;if(url.origin!==self.location.origin)return;event.respondWith(fetch(request).then(response=>{if(response.ok){const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(request,copy))}return response}).catch(()=>caches.match(request).then(response=>response||request.mode==='navigate'&&caches.match('./'))))});
