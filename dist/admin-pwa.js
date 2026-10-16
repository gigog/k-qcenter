'use strict';
let adminInstallPrompt=null;
const adminInstallButton=document.getElementById('installAdminApp');
function updateAdminNetwork(){let banner=document.getElementById('adminOffline');if(navigator.onLine){banner?.remove();return}if(!banner){banner=document.createElement('div');banner.id='adminOffline';banner.className='admin-offline';banner.setAttribute('role','status');banner.textContent='K-QCENTER ต้องเชื่อมต่อเครือข่ายเพื่อเข้าสู่ระบบและจัดการคิว';document.body.appendChild(banner)}}
window.addEventListener('online',updateAdminNetwork);window.addEventListener('offline',updateAdminNetwork);updateAdminNetwork();
window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();adminInstallPrompt=event;adminInstallButton.classList.remove('hidden')});
adminInstallButton.addEventListener('click',async()=>{if(!adminInstallPrompt)return;adminInstallPrompt.prompt();await adminInstallPrompt.userChoice;adminInstallPrompt=null;adminInstallButton.classList.add('hidden')});
window.addEventListener('appinstalled',()=>adminInstallButton.classList.add('hidden'));
if('serviceWorker'in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('admin-sw.js').catch(()=>{}));
