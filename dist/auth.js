'use strict';

const KQ_API = '../api/index.php';
let csrfToken = '';

async function kqApi(action, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const query=new URLSearchParams({action});Object.entries(options.query||{}).forEach(([key,value])=>{if(value!==''&&value!==null&&value!==undefined)query.set(key,value)});const fetchOptions={...options};delete fetchOptions.query;
  const response = await fetch(`${KQ_API}?${query.toString()}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', ...(method !== 'GET' && csrfToken ? {'X-CSRF-Token': csrfToken} : {}), ...(options.headers || {}) },
    ...fetchOptions
  });
  const body = await response.json().catch(() => ({ error: 'เซิร์ฟเวอร์ส่งข้อมูลไม่ถูกต้อง' }));
  if (body.csrf_token) csrfToken = body.csrf_token;
  if (!response.ok) {
    const error = new Error(body.error || 'ไม่สามารถเชื่อมต่อระบบได้');
    error.status = response.status;
    throw error;
  }
  return body;
}

const authGate = document.getElementById('authGate');
const authForm = document.getElementById('authForm');
let setupMode = false;
const capacityFilters={date:KQThaiDate.todayISO(),department_id:''};

function setAuthMode(setup) {
  setupMode = setup;
  document.querySelectorAll('.setup-only').forEach(node => node.classList.toggle('hidden', !setup));
  document.getElementById('authEyebrow').classList.toggle('hidden', !setup);
  document.getElementById('authTitle').classList.toggle('hidden', !setup);
  document.getElementById('authDescription').classList.toggle('hidden', !setup);
  document.getElementById('authEyebrow').textContent = setup ? 'ตั้งค่าครั้งแรก' : 'เข้าสู่ระบบ';
  document.getElementById('authTitle').textContent = setup ? 'สร้างผู้ดูแลระบบ' : 'เข้าสู่ระบบ';
  document.getElementById('authDescription').textContent = setup
    ? 'ระบบยังไม่มีบัญชีผู้ดูแล กรุณาสร้างบัญชีแรก'
    : 'กรอกบัญชีผู้ดูแลเพื่อเข้าสู่ระบบหลังบ้าน';
  document.getElementById('authSubmit').textContent = setup ? 'สร้างบัญชีและเริ่มใช้งาน' : 'เข้าสู่ระบบ';
  document.getElementById('authPassword').autocomplete = setup ? 'new-password' : 'current-password';
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);
}

async function loadDashboard() {
  try {
    const data = await kqApi('dashboard');
    const cards = document.querySelectorAll('.metric-card>strong');
    if (cards[0]) cards[0].textContent = data.summary.total;
    if (cards[1]) cards[1].textContent = data.summary.completed;
    if (cards[3]) cards[3].innerHTML = `${data.departments.reduce((sum, item) => sum + Number(item.counter_count), 0)}<span class="muted-total"> ช่อง</span>`;
    const capacity = Math.max(1, Number(data.summary.capacity));
    const density = Math.round(Number(data.summary.booked) / capacity * 100);
    document.querySelector('.hero-score strong').textContent = `${density}%`;
    document.getElementById('departmentList').innerHTML = data.departments.map(item => {
      const used = Number(item.booked);
      const total = Number(item.capacity);
      const percent = total ? Math.round(used / total * 100) : 0;
      return `<div class="department-row">
        <div class="department-name"><strong>${escapeHtml(item.name)}</strong><span>${item.counter_count} ช่องบริการ · ประสิทธิภาพ ${Math.round(Number(item.efficiency) * 100)}%</span></div>
        <div class="capacity-bar"><i style="width:${percent}%;background:#2b65d9"></i></div>
        <div class="capacity-text"><strong>${used}/${total}</strong><span>ใช้ไป ${percent}%</span></div>
      </div>`;
    }).join('');
  } catch (error) {
    if (error.status === 401) authGate.classList.remove('hidden');
    else showToast(error.message);
  }
}

function renderTable(headers, rows) {
  return `<div class="table-wrap"><table class="data-table"><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}

async function hydrateView(view) {
  try {
    let result;
    if (view === 'queues') result = await kqApi('registration-monitor');
    else if (view === 'capacity') {const responses=await Promise.all([kqApi('slots',{query:capacityFilters}),kqApi('departments')]);result=responses[0];result.departments=responses[1].data;}
    else if (['branches', 'services', 'audit'].includes(view)) result = await kqApi(view);
    else return;

    const card = document.querySelector('#viewContent .page-card');
    const existing = card?.querySelector('.table-wrap,.branch-grid');
    if (!existing) return;
    if (view === 'queues') {
      card.innerHTML = registrationMonitorHtml(result);
      const count=document.querySelector('.nav-item[data-view="queues"] .nav-count');if(count)count.textContent=String(result.departments.reduce((sum,item)=>sum+Number(item.waiting),0));
      bindRegistrationMonitor();
    }
    if (view === 'capacity') {
      let filters=card.querySelector('.capacity-filter-bar');if(!filters){filters=document.createElement('div');filters.className='capacity-filter-bar';card.querySelector('.table-wrap')?.before(filters)}filters.innerHTML=`<label>วันที่<select data-capacity-date>${KQThaiDate.options(capacityFilters.date,30,180)}</select></label><label>จุดบริการ/แผนก<select data-capacity-department><option value="">ทุกจุดบริการ</option>${result.departments.map(item=>`<option value="${item.id}" ${String(item.id)===String(capacityFilters.department_id)?'selected':''}>${escapeHtml(item.name)} · ${escapeHtml(item.branch_name)}</option>`).join('')}</select></label><button type="button" class="secondary" data-capacity-clear>ล้างตัวกรอง</button>`;
      filters.querySelector('[data-capacity-date]').onchange=event=>{capacityFilters.date=event.target.value;hydrateView('capacity')};filters.querySelector('[data-capacity-department]').onchange=event=>{capacityFilters.department_id=event.target.value;hydrateView('capacity')};filters.querySelector('[data-capacity-clear]').onclick=()=>{capacityFilters.date=KQThaiDate.todayISO();capacityFilters.department_id='';hydrateView('capacity')};
      existing.outerHTML = renderTable(['ช่วงเวลา','แผนก','บริการ','จำนวนรับ','จองแล้ว','ที่มา','สถานะ',''], result.data.map(item => {const unlimited=Number(item.unlimited_capacity)===1;return [`${item.start_time}–${item.end_time} น.`,escapeHtml(item.department_name),escapeHtml(item.service_name),unlimited?'ไม่จำกัด':item.capacity,item.booked_count,unlimited?'ไม่จำกัดคิว':item.capacity_mode==='MANUAL'?'ปรับเฉพาะช่วง':item.capacity_mode==='TODAY_OVERRIDE'?'ปรับช่วงที่เหลือวันนี้':'ค่ามาตรฐานแผนก',`<span class="badge ${!unlimited&&Number(item.booked_count)>=Number(item.capacity)?'amber':'green'}">${!unlimited&&Number(item.booked_count)>=Number(item.capacity)?'เต็ม':'เปิด'}</span>`,unlimited?'—':`<button class="record-button" data-slot-capacity="${item.id}">ปรับจำนวนรับ</button>`]}));
      document.querySelectorAll('[data-slot-capacity]').forEach(button=>button.onclick=()=>openSlotCapacityModal(result.data.find(item=>String(item.id)===button.dataset.slotCapacity)));
      const action=document.querySelector('#viewContent .page-action');if(action){const isToday=capacityFilters.date===KQThaiDate.todayISO();action.textContent=isToday?'± ปรับช่วงที่เหลือวันนี้':'เลือกวันนี้เพื่อปรับระหว่างวัน';action.disabled=!isToday;action.onclick=isToday?()=>openRemainingCapacityDialog(result.data):null;}
    }
    if (view === 'services') existing.outerHTML = renderTable(['รหัส','ชื่อบริการ','แผนก','เวลาเฉลี่ย','สถานะ'], result.data.map(item => [escapeHtml(item.code),escapeHtml(item.name),escapeHtml(item.department_name),`${item.average_minutes} นาที`,'<span class="badge green">เปิด</span>']));
    if (view === 'branches') existing.innerHTML = result.data.map(item => `<article class="branch-card"><div class="branch-top"><h3>${escapeHtml(item.name)}</h3><span class="badge green">เปิดบริการ</span></div><p>รหัส ${escapeHtml(item.code)}</p><div class="branch-meta"><div><span>เวลาทำการ</span><strong>${item.open_time}–${item.close_time} น.</strong></div><div><span>โครงสร้าง</span><strong>${item.department_count} แผนก · ${item.counter_count} ช่อง</strong></div></div></article>`).join('');
    if (view === 'audit') existing.outerHTML = renderTable(['วันเวลา','ผู้กระทำ','กิจกรรม','ประเภท','ผลลัพธ์'], result.data.map(item => [escapeHtml(KQThaiDate.dateTime(item.created_at)),escapeHtml(item.display_name || 'ระบบ'),escapeHtml(item.action),escapeHtml(item.entity_type),'<span class="badge green">สำเร็จ</span>']));
  } catch (error) {
    showToast(error.message);
  }
}

function registrationMonitorHtml(result) {
  const summaries=result.departments.map(item=>{
    const next=item.next_slot;
    const countdown=next?`เริ่ม Slot ${String(next.start_time).slice(0,5)} น. ใน ${item.minutes_to_next_slot} นาที · จอง ${next.booked_count}/${next.capacity}`:'ไม่มี Slot ถัดไปวันนี้';
    return `<article class="registration-summary"><header><div><strong>${escapeHtml(item.department_name)}</strong><span>${item.current_slot?`Slot ปัจจุบัน ${String(item.current_slot.start_time).slice(0,5)}–${String(item.current_slot.end_time).slice(0,5)} น.`:'ขณะนี้อยู่นอกช่วง Slot'}</span></div><b>${escapeHtml(countdown)}</b></header><div class="registration-metrics"><div><span>มาถึงแล้ว</span><strong>${item.arrived}</strong></div><div><span>กำลังรอ</span><strong>${item.waiting}</strong></div><div><span>กำลังเปิด Visit</span><strong>${item.in_progress}</strong></div><div><span>ส่งแผนกแล้ว</span><strong>${item.sent}</strong></div><div><span>รอเฉลี่ย</span><strong>${item.average_wait_minutes} นาที</strong></div><div><span>รอนานสุด</span><strong>${item.longest_wait_minutes} นาที</strong></div></div></article>`;
  }).join('')||'<div class="monitor-empty">วันนี้ยังไม่มี Slot ที่เปิดให้บริการ</div>';
  const statusLabel={REGISTRATION_WAITING:'รอห้องบัตร',REGISTRATION_CALLED:'เรียกแล้ว',REGISTRATION_IN_PROGRESS:'กำลังเปิด Visit',SENT_TO_DEPARTMENT:'ส่งแผนกแล้ว'};
  const rows=result.tickets.map(item=>{let actions='—';if(result.can_manage&&item.current_step==='REGISTRATION_WAITING')actions=`<button class="record-button" data-queue-action="CALL" data-ticket-id="${item.id}" data-queue-no="${escapeHtml(item.queue_no)}">🔊 เรียกคิว</button> <button class="record-button primary-inline" data-queue-action="START_REGISTRATION" data-ticket-id="${item.id}">เริ่มเปิด Visit</button>`;else if(result.can_manage&&item.current_step==='REGISTRATION_CALLED')actions=`<button class="record-button" data-queue-action="RECALL" data-ticket-id="${item.id}" data-queue-no="${escapeHtml(item.queue_no)}">🔊 เรียกซ้ำ</button> <button class="record-button primary-inline" data-queue-action="START_REGISTRATION" data-ticket-id="${item.id}">เริ่มเปิด Visit</button>`;else if(result.can_manage&&item.current_step==='REGISTRATION_IN_PROGRESS')actions=`<button class="record-button success-inline" data-queue-action="SEND_DEPARTMENT" data-ticket-id="${item.id}">เปิด Visit แล้ว · ส่งแผนก</button>`;return [`<strong>${escapeHtml(item.queue_no)}</strong><small>${item.source==='ONLINE'?'จองออนไลน์':'Walk-in'}</small>`,escapeHtml(item.customer_display),`<strong>${escapeHtml(item.department_name)}</strong><small>${escapeHtml(item.service_name||'')}</small>`,`${String(item.start_time).slice(0,5)}–${String(item.end_time).slice(0,5)} น.`,`${item.wait_minutes} นาที`,`<span class="badge ${item.current_step==='SENT_TO_DEPARTMENT'?'green':item.current_step==='REGISTRATION_IN_PROGRESS'?'blue':'amber'}">${escapeHtml(statusLabel[item.current_step]||item.current_step)}</span>`,actions];});
  return `<div class="page-toolbar"><div><h2>Monitor ห้องบัตร</h2><p>ข้อมูลจริงของวันที่ ${escapeHtml(KQThaiDate.date(result.date))} · อัปเดตอัตโนมัติทุก 30 วินาที</p></div><div class="monitor-actions"><button type="button" class="secondary" data-voice-toggle aria-pressed="${KQQueueVoice.isEnabled()}">${KQQueueVoice.isEnabled()?'🔊 เสียงเรียกคิว: เปิด':'🔇 เสียงเรียกคิว: ปิด'}</button><button type="button" class="secondary" data-voice-settings>⚙ ตั้งค่าเสียง</button><button type="button" class="secondary" data-voice-test>ทดสอบเสียง</button><button class="primary page-action" data-monitor-refresh>↻ อัปเดต</button></div></div><div class="registration-summary-grid">${summaries}</div>${renderTable(['เลขคิว','ผู้รับบริการ','แผนก/บริการ','ช่วงลงทะเบียน','รอแล้ว','สถานะ','ดำเนินการ'],rows)}`;
}

function announceQueue(queueNo,options){KQQueueVoice.announce(queueNo,options).then(result=>{const message=KQQueueVoice.problem(result);if(message)showToast(message)})}
async function openVoiceSettings(){
  const current=KQQueueVoice.settings(),voices=await KQQueueVoice.listVoices();
  const wrap=document.createElement('div');wrap.className='modal-backdrop';wrap.innerHTML=`<form class="modal voice-settings-modal"><div class="modal-head"><div><span class="eyebrow">THAI QUEUE VOICE</span><h2>ตั้งค่าเสียงเรียกคิวภาษาไทย</h2></div><button type="button" class="icon-button" data-close>×</button></div><p class="voice-help">การตั้งค่านี้ใช้เฉพาะเครื่อง Monitor เครื่องนี้ เพราะเสียงภาษาไทยขึ้นอยู่กับ Windows และเบราว์เซอร์ที่ติดตั้ง</p><div class="form-grid"><label>รูปแบบเสียง<select name="gender"><option value="female" ${current.gender==='female'?'selected':''}>เสียงหญิง</option><option value="male" ${current.gender==='male'?'selected':''}>เสียงชาย</option></select></label><label>เสียงภาษาไทยในเครื่อง<select name="voiceName"><option value="">เลือกอัตโนมัติตามเพศ</option>${voices.map(voice=>`<option value="${escapeHtml(voice.name)}" ${voice.name===current.voiceName?'selected':''}>${escapeHtml(voice.name)}</option>`).join('')}</select></label><label>ความเร็ว <output data-rate-output>${Number(current.rate).toFixed(1)} เท่า</output><input name="rate" type="range" min="0.5" max="1.5" step="0.1" value="${current.rate}"></label><label>จำนวนครั้งที่อ่าน<select name="repeat">${[1,2,3].map(value=>`<option value="${value}" ${Number(current.repeat)===value?'selected':''}>${value} ครั้ง</option>`).join('')}</select></label><label class="full">จุดที่ให้ไปติดต่อ<input name="destination" value="${escapeHtml(current.destination)}" maxlength="100" required></label><label class="full">ข้อความประกาศ<textarea name="template" rows="3" required>${escapeHtml(current.template)}</textarea><small>ใช้ {queue} แทนเลขคิว และ {destination} แทนจุดติดต่อ</small></label><label class="full">คำอ่านกำหนดเอง<textarea name="dictionary" rows="5" placeholder="ER=อีอาร์&#10;NCD=เอ็น ซี ดี">${escapeHtml(KQQueueVoice.dictionaryToText(current.dictionary))}</textarea><small>หนึ่งคำต่อหนึ่งบรรทัด รูปแบบ คำเดิม=คำที่ให้อ่าน</small></label></div><div class="voice-preview" data-voice-preview>${escapeHtml(KQQueueVoice.phrase('A-001'))}</div><div class="auth-error hidden" data-error></div><div class="modal-actions"><button type="button" class="secondary" data-test>▶ ทดลองเสียง</button><button type="button" class="secondary" data-close>ยกเลิก</button><button type="submit" class="primary">บันทึกการตั้งค่า</button></div></form>`;document.body.appendChild(wrap);
  const form=wrap.querySelector('form'),read=()=>{const data=Object.fromEntries(new FormData(form));return {gender:data.gender,voiceName:data.voiceName,rate:Number(data.rate),repeat:Number(data.repeat),destination:data.destination.trim(),template:data.template.trim(),dictionary:KQQueueVoice.dictionaryFromText(data.dictionary)}};
  const refresh=()=>{const draft=read();wrap.querySelector('[data-rate-output]').textContent=`${draft.rate.toFixed(1)} เท่า`;wrap.querySelector('[data-voice-preview]').textContent=KQQueueVoice.phrase('A-001',draft.destination,draft)};
  form.addEventListener('input',refresh);wrap.querySelectorAll('[data-close]').forEach(button=>button.onclick=()=>wrap.remove());wrap.addEventListener('click',event=>{if(event.target===wrap)wrap.remove()});wrap.querySelector('[data-test]').onclick=async()=>{const result=await KQQueueVoice.announce('A-001',{...read(),force:true});const message=KQQueueVoice.problem(result);if(message){const error=wrap.querySelector('[data-error]');error.textContent=message;error.classList.remove('hidden')}};form.onsubmit=event=>{event.preventDefault();KQQueueVoice.save(read());wrap.remove();showToast('บันทึกการตั้งค่าเสียงของเครื่องนี้แล้ว')};
}
function bindRegistrationMonitor(){document.querySelector('[data-monitor-refresh]')?.addEventListener('click',()=>hydrateView('queues'));const toggle=document.querySelector('[data-voice-toggle]');if(toggle)toggle.onclick=()=>{KQQueueVoice.setEnabled(!KQQueueVoice.isEnabled());const enabled=KQQueueVoice.isEnabled();toggle.textContent=enabled?'🔊 เสียงเรียกคิว: เปิด':'🔇 เสียงเรียกคิว: ปิด';toggle.setAttribute('aria-pressed',String(enabled))};document.querySelector('[data-voice-settings]')?.addEventListener('click',openVoiceSettings);const test=document.querySelector('[data-voice-test]');if(test)test.onclick=()=>announceQueue('A-001',{force:true});document.querySelectorAll('[data-queue-action]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;const transition=button.dataset.queueAction;try{await kqApi('queue-transition',{method:'POST',body:JSON.stringify({ticket_id:Number(button.dataset.ticketId),transition})});if(['CALL','RECALL'].includes(transition)&&button.dataset.queueNo)announceQueue(button.dataset.queueNo);showToast(transition==='RECALL'?'เรียกซ้ำแล้ว':'อัปเดตสถานะคิวแล้ว');await hydrateView('queues')}catch(error){showToast(error.message)}finally{button.disabled=false}}));}

setInterval(()=>{if(document.querySelector('.nav-item[data-view="queues"]')?.classList.contains('active')&&!authGate.classList.contains('hidden'))hydrateView('queues')},30000);

function openRemainingCapacityDialog(slots){
  const departments=[...new Map(slots.map(item=>[String(item.department_id),{id:item.department_id,name:item.department_name}])).values()];if(!departments.length){showToast('วันนี้ยังไม่มี Slot ที่ปรับได้');return}
  const wrap=document.createElement('div');wrap.className='modal-backdrop';wrap.innerHTML=`<form class="modal"><div class="modal-head"><div><span class="eyebrow">TODAY OVERRIDE</span><h2>ปรับ Slot ที่เหลือวันนี้</h2></div><button type="button" class="icon-button" data-close>×</button></div><p class="remaining-help">เพิ่มหรือลดจำนวนรับรวม ระบบจะกระจายเฉพาะ Slot ที่ยังไม่เริ่ม ไม่มีการจอง และยังไม่ถูกล็อก</p><div class="form-grid"><label>แผนก<select name="department_id" required>${departments.map(item=>`<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select></label><label>จำนวนที่ต้องการเพิ่มหรือลด<input name="adjustment" type="number" min="-10000" max="10000" placeholder="เช่น 20 หรือ -10" required></label></div><div class="auth-error hidden" data-error></div><div class="modal-actions"><button type="button" class="secondary" data-close>ยกเลิก</button><button type="submit" class="primary">กระจายจำนวนรับ</button></div></form>`;document.body.appendChild(wrap);wrap.querySelectorAll('[data-close]').forEach(button=>button.onclick=()=>wrap.remove());wrap.querySelector('form').onsubmit=async event=>{event.preventDefault();const data=Object.fromEntries(new FormData(event.currentTarget));const error=wrap.querySelector('[data-error]');try{const result=await kqApi('adjust-remaining-slots',{method:'POST',body:JSON.stringify({department_id:Number(data.department_id),adjustment:Number(data.adjustment)})});wrap.remove();showToast(`ปรับ ${result.slot_count} Slot แล้ว · รวม ${result.old_total} เป็น ${result.new_total} คิว`);await hydrateView('capacity')}catch(err){error.textContent=err.message;error.classList.remove('hidden')}};
}

function finishAuth(user) {
  authGate.classList.add('hidden');
  const profileName = document.querySelector('.profile strong');
  if (profileName) profileName.textContent = user.display_name;
  const profileAvatar = document.querySelector('.profile .avatar');
  if (profileAvatar) profileAvatar.textContent = Array.from(user.display_name.replace(/\s+/g, '')).slice(0, 2).join('');
  const roleMenus={admin:null,kiosk_admin:['dashboard','branches','services','kiosks'],kiosk_manager:['dashboard'],counter:['dashboard','queues','capacity'],viewer:['dashboard','queues','capacity','branches','services']};
  const allowed=Object.prototype.hasOwnProperty.call(roleMenus,user.role)?roleMenus[user.role]:['dashboard'];
  document.querySelectorAll('.nav-item[data-view]').forEach(node=>node.classList.toggle('hidden',Array.isArray(allowed)&&!allowed.includes(node.dataset.view)));
  const profileRole=document.querySelector('.profile-copy span');if(profileRole)profileRole.textContent=({admin:'ผู้ดูแลระบบ',kiosk_admin:'ผู้ดูแล Kiosk',kiosk_manager:'ผู้ควบคุม Kiosk',counter:'เจ้าหน้าที่เคาน์เตอร์',viewer:'ผู้ดูรายงาน'}[user.role]||user.role);
  loadDashboard();
}

const profileMenuButton = document.getElementById('profileMenuButton');
const profileMenu = document.getElementById('profileMenu');

function closeProfileMenu() {
  profileMenu.classList.add('hidden');
  profileMenuButton.setAttribute('aria-expanded', 'false');
}

profileMenuButton.addEventListener('click', event => {
  event.stopPropagation();
  const willOpen = profileMenu.classList.contains('hidden');
  profileMenu.classList.toggle('hidden', !willOpen);
  profileMenuButton.setAttribute('aria-expanded', String(willOpen));
});

document.addEventListener('click', closeProfileMenu);
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeProfileMenu();
});

document.getElementById('logoutButton').addEventListener('click', async event => {
  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = 'กำลังออกจากระบบ…';
  try {
    await kqApi('logout', { method: 'POST', body: '{}' });
    csrfToken = '';
    authForm.reset();
    setAuthMode(false);
    closeProfileMenu();
    authGate.classList.remove('hidden');
    document.getElementById('authUsername').focus();
  } catch (error) {
    showToast(error.message);
  } finally {
    button.disabled = false;
    button.innerHTML = '<span aria-hidden="true">↪</span> ออกจากระบบ';
  }
});

authForm.addEventListener('submit', async event => {
  event.preventDefault();
  const errorBox = document.getElementById('authError');
  errorBox.classList.add('hidden');
  const payload = {
    username: document.getElementById('authUsername').value,
    password: document.getElementById('authPassword').value
  };
  if (setupMode) payload.display_name = document.getElementById('displayName').value;
  try {
    if (setupMode) {
      await kqApi('setup', { method: 'POST', body: JSON.stringify(payload) });
      setAuthMode(false);
      showToast('สร้างผู้ดูแลแล้ว กรุณาเข้าสู่ระบบ');
    } else {
      const result = await kqApi('login', { method: 'POST', body: JSON.stringify(payload) });
      finishAuth(result.user);
    }
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.classList.remove('hidden');
  }
});

document.getElementById('passwordToggle').addEventListener('click', event => {
  const password = document.getElementById('authPassword');
  const reveal = password.type === 'password';
  password.type = reveal ? 'text' : 'password';
  event.currentTarget.setAttribute('aria-pressed', String(reveal));
  event.currentTarget.setAttribute('aria-label', reveal ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
});

document.querySelectorAll('.nav-item').forEach(node => {
  node.onclick = () => {
    switchView(node.dataset.view);
    if (node.dataset.view === 'dashboard') loadDashboard();
    else hydrateView(node.dataset.view);
  };
});

(async function boot() {
  try {
    const result = await kqApi('me');
    finishAuth(result.user);
  } catch (error) {
    try {
      const status = await kqApi('setup-status');
      setAuthMode(status.needs_setup);
      authGate.classList.remove('hidden');
    } catch {
      const errorBox = document.getElementById('authError');
      errorBox.textContent = 'ไม่สามารถเชื่อมต่อ K-QCENTER API';
      errorBox.classList.remove('hidden');
    }
  }
})();

