'use strict';
(()=>{
const definitions=[
  ['kiosk','หน้า Kiosk','รับคิว Walk-in ยืนยันรายการจอง และค้นหาคิวที่ตู้'],
  ['online_booking','จองคิวออนไลน์','อนุญาตให้สร้างรายการจองใหม่จาก K-QOnline'],
  ['queue_status','ตรวจสอบสถานะคิว','เปิดหน้าติดตามสถานะสำหรับผู้รับบริการ'],
  ['smartcard_reader','เครื่องอ่านบัตรประชาชน','แสดงปุ่มและการอ่านบัตร Smart Card บน Kiosk'],
  ['ticket_printing','พิมพ์บัตรคิว','อนุญาตให้ Kiosk ส่งงานไปยัง Print Agent'],
  ['hosxp_integration','เชื่อมต่อ HOSxP','อ่าน Visit สถานะผู้รับบริการ และซิงค์วันหยุด'],
  ['queue_voice','เสียงเรียกคิว','แสดงและใช้งานระบบประกาศเรียกคิว'],
  ['qr_scanning','สแกน QR','แสดงกล้องและช่องทางสแกน QR บน Kiosk'],
];
const escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
async function render(root){
  root.innerHTML='<div class="feature-loading">กำลังโหลดการตั้งค่า…</div>';
  try{
    const result=await kqApi('feature-settings');
    window.KQSystemFeatures={...result.data};
    root.innerHTML=`<form class="feature-settings-form"><div class="settings-group-head"><div><span class="eyebrow">SYSTEM FEATURES</span><h3>การเปิดใช้งานระบบ</h3><p>เลือกส่วนที่ต้องการใช้งาน การปิดจะมีผลทั้งหน้าจอและ API</p></div></div><div class="feature-grid">${definitions.map(([key,title,description])=>`<label class="feature-card"><span><strong>${escape(title)}</strong><small>${escape(description)}</small></span><input type="checkbox" name="${key}" ${result.data[key]?'checked':''}><i aria-hidden="true"></i></label>`).join('')}</div><div class="feature-note">การตั้งค่าเครือข่าย LAN, Tailscale, Nginx และ Firewall ไม่รวมอยู่ในหน้านี้</div><div class="feature-actions"><div class="auth-error hidden" data-feature-error></div><button class="primary" type="submit">บันทึกการเปิดใช้งาน</button></div></form>`;
    root.querySelector('form').onsubmit=async event=>{
      event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type="submit"]'),error=form.querySelector('[data-feature-error]');button.disabled=true;error.classList.add('hidden');
      try{const values={};definitions.forEach(([key])=>values[key]=form.elements[key].checked);const saved=await kqApi('feature-settings',{method:'POST',body:JSON.stringify(values)});window.KQSystemFeatures={...saved.data};showToast('บันทึกการเปิดใช้งานระบบแล้ว กรุณารีเฟรชหน้าจอที่เปิดใช้งานอยู่')}
      catch(err){error.textContent=err.message;error.classList.remove('hidden')}
      finally{button.disabled=false}
    };
  }catch(error){root.innerHTML=`<div class="auth-error">${escape(error.message)}</div>`}
}
window.KQFeatureSettings={render,definitions};
})();
