'use strict';
const ADMIN_CACHE='k-qcenter-admin-v25';
const ADMIN_ASSETS=['styles.css','auth.css','admin-crud.css','admin-kiosk.css','admin-users.css','schedule.css','responsive.css','thai-date.js','queue-voice.js','app.js','auth.js','admin-crud.js','admin-kiosk.js','admin-users.js','admin-pwa.js','admin-manifest.webmanifest','icons/admin-icon.svg','icons/admin-icon-192.png','icons/admin-icon-512.png'];
self.addEventListener('install',event=>event.waitUntil(caches.open(ADMIN_CACHE).then(cache=>cache.addAll(ADMIN_ASSETS)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('k-qcenter-admin-')&&key!==ADMIN_CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{const request=event.request;const url=new URL(request.url);if(request.method!=='GET'||url.pathname.includes('/api/'))return;if(request.mode==='navigate'){event.respondWith(fetch(request));return}if(url.origin!==self.location.origin)return;event.respondWith(fetch(request).catch(()=>caches.match(request)))});
