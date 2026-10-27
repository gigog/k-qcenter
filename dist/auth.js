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
  if (body.csrf_token) {csrfToken = body.csrf_token;window.KQQueueVoice?.setServerAuth(csrfToken);}
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
let adminSystemFeatures={queue_voice:true};
const adminSystemFeaturesReady=fetch(`${KQ_API}?action=public-features`,{cache:'no-store',credentials:'same-origin'}).then(response=>response.json()).then(body=>{if(body.ok){adminSystemFeatures={...adminSystemFeatures,...body.data};window.KQSystemFeatures=adminSystemFeatures}}).catch(()=>{});

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
    else if (view === 'settings') result = await kqApi('hosxp-health');
    else if (['branches', 'services', 'audit'].includes(view)) result = await kqApi(view);
    else return;

    const card = document.querySelector('#viewContent .page-card');
    const existing = card?.querySelector('.table-wrap,.branch-grid,.connection-grid,.system-settings-loading');
    if (!existing) return;
    if (view === 'queues') {
      card.innerHTML = registrationMonitorHtml(result);
      const count=document.querySelector('.nav-item[data-view="queues"] .nav-count');if(count)count.textContent=String(result.departments.reduce((sum,item)=>sum+Number(item.waiting),0));
      bindRegistrationMonitor();
    }
    if (view === 'capacity') {
      let filters=card.querySelector('.capacity-filter-bar');if(!filters){filters=document.createElement('div');filters.className='capacity-filter-bar';card.querySelector('.table-wrap')?.before(filters)}filters.innerHTML=`<label>วันที่<select data-capacity-date>${KQThaiDate.options(capacityFilters.date,30,180)}</select></label><label>จุดบริการ/แผนก<select data-capacity-department><option value="">ทุกจุดบริการ</option>${result.departments.map(item=>`<option value="${item.id}" ${String(item.id)===String(capacityFilters.department_id)?'selected':''}>${escapeHtml(item.name)} · ${escapeHtml(item.branch_name)}</option>`).join('')}</select></label><button type="button" class="secondary" data-capacity-clear>ล้างตัวกรอง</button>`;
      filters.querySelector('[data-capacity-date]').onchange=event=>{capacityFilters.date=event.target.value;hydrateView('capacity')};filters.querySelector('[data-capacity-department]').onchange=event=>{capacityFilters.department_id=event.target.value;hydrateView('capacity')};filters.querySelector('[data-capacity-clear]').onclick=()=>{capacityFilters.date=KQThaiDate.todayISO();capacityFilters.department_id='';hydrateView('capacity')};
      existing.outerHTML = renderTable(['ช่วงเวลา Slot','แผนก','บริการ','จำนวนรับ','ออกคิวแล้ว','กำหนดจาก','สถานะ',''], result.data.map(item => {const unlimited=Number(item.unlimited_capacity)===1,start=String(item.start_time||'').slice(0,5),end=String(item.end_time||'').slice(0,5);return [`${start}–${end} น.`,escapeHtml(item.department_name),escapeHtml(item.service_name),unlimited?'ไม่จำกัด':item.capacity,item.booked_count,unlimited?'กฎ Slot · ไม่จำกัด':item.capacity_mode==='MANUAL'?'ปรับเฉพาะช่วง':item.capacity_mode==='TODAY_OVERRIDE'?'ปรับช่วงที่เหลือวันนี้':item.capacity_mode==='CUSTOM_RULE'?'กฎ Slot':'ค่ามาตรฐานแผนก',`<span class="badge ${!unlimited&&Number(item.booked_count)>=Number(item.capacity)?'amber':'green'}">${!unlimited&&Number(item.booked_count)>=Number(item.capacity)?'เต็ม':'เปิด'}</span>`,unlimited?'—':`<button class="record-button" data-slot-capacity="${item.id}">ปรับจำนวนรับ</button>`]}));
      document.querySelectorAll('[data-slot-capacity]').forEach(button=>button.onclick=()=>openSlotCapacityModal(result.data.find(item=>String(item.id)===button.dataset.slotCapacity)));
      const action=document.querySelector('#viewContent .page-action');if(action){const isToday=capacityFilters.date===KQThaiDate.todayISO();action.textContent=isToday?'± ปรับช่วงที่เหลือวันนี้':'เลือกวันนี้เพื่อปรับระหว่างวัน';action.disabled=!isToday;action.onclick=isToday?()=>openRemainingCapacityDialog(result.data):null;}
    }
    if (view === 'services') existing.outerHTML = renderTable(['รหัส','ชื่อบริการ','แผนก','เวลาเฉลี่ย','สถานะ'], result.data.map(item => [escapeHtml(item.code),escapeHtml(item.name),escapeHtml(item.department_name),`${item.average_minutes} นาที`,'<span class="badge green">เปิด</span>']));
    if (view === 'branches') existing.innerHTML = result.data.map(item => `<article class="branch-card"><div class="branch-top"><h3>${escapeHtml(item.name)}</h3><span class="badge green">เปิดบริการ</span></div><p>รหัส ${escapeHtml(item.code)}</p><div class="branch-meta"><div><span>เวลาทำการ</span><strong>${item.open_time}–${item.close_time} น.</strong></div><div><span>โครงสร้าง</span><strong>${item.department_count} แผนก · ${item.counter_count} ช่อง</strong></div></div></article>`).join('');
    if (view === 'audit') existing.outerHTML = renderTable(['วันเวลา','ผู้กระทำ','กิจกรรม','ประเภท','ผลลัพธ์'], result.data.map(item => [escapeHtml(KQThaiDate.dateTime(item.created_at)),escapeHtml(item.display_name || 'ระบบ'),escapeHtml(item.action),escapeHtml(item.entity_type),'<span class="badge green">สำเร็จ</span>']));
    if (view === 'settings') {
      const hosxp=result.data||{};
      const secure=location.protocol==='https:';
      const cards=[
        {icon:'API',name:'K-QCENTER API',detail:'API กลางของระบบคิว',ok:true,status:'ตอบสนองแล้ว'},
        {icon:'DB',name:'MariaDB K-QCENTER',detail:'ฐานข้อมูลภายในเซิร์ฟเวอร์',ok:true,status:'เชื่อมต่อแล้ว'},
        {icon:'HX',name:'HOSxP v3',detail:'อ่าน CID → HN → Visit วันนี้ แบบ Read-only',ok:Boolean(hosxp.enabled&&hosxp.connected),status:!hosxp.enabled?'ยังไม่ได้ตั้งค่า':hosxp.connected?'เชื่อมต่อแล้ว · สิทธิ์อ่านอย่างเดียว':'เชื่อมต่อไม่สำเร็จ'},
        {icon:'TLS',name:'HTTPS',detail:escapeHtml(location.origin),ok:secure,status:secure?'เข้ารหัสการเชื่อมต่อแล้ว':'ไม่ได้เชื่อมต่อผ่าน HTTPS'}
      ];
      existing.outerHTML=`<div class="system-settings-page"><div class="settings-tabs" role="tablist" aria-label="หมวดการตั้งค่าระบบ"><button type="button" class="active" role="tab" aria-selected="true" data-settings-tab="voice">เสียงเรียกคิว</button><button type="button" role="tab" aria-selected="false" data-settings-tab="connections">การเชื่อมต่อ</button><button type="button" role="tab" aria-selected="false" data-settings-tab="general">การตั้งค่าทั่วไป</button></div><section class="settings-group" role="tabpanel" data-settings-panel="voice"><div class="settings-group-head"><div><span class="eyebrow">QUEUE AUDIO</span><h3>เสียงเรียกคิว</h3><p>ตั้งค่าเสียง ข้อความประกาศ คำอ่าน ความเร็ว และลำดับการอ่านของเครื่องนี้</p></div><span class="badge ${KQQueueVoice.isEnabled()?'green':'amber'}">${KQQueueVoice.isEnabled()?'เปิดเสียง':'ปิดเสียง'}</span></div><div class="settings-actions"><button type="button" class="primary" data-settings-voice>⚙ ตั้งค่าเสียงเรียกคิว</button><button type="button" class="secondary" data-settings-voice-test>▶ ทดสอบเสียง</button><button type="button" class="secondary" data-settings-voice-toggle>${KQQueueVoice.isEnabled()?'🔊 ปิดเสียง':'🔇 เปิดเสียง'}</button></div></section><section class="settings-group hidden" role="tabpanel" data-settings-panel="connections"><div class="settings-group-head"><div><span class="eyebrow">CONNECTIONS</span><h3>การเชื่อมต่อระบบ</h3><p>แสดงเฉพาะสถานะการเชื่อมต่อ โดยไม่เปิดเผยรหัสผ่านหรือข้อมูลลับ</p></div></div><div class="connection-grid">${cards.map(card=>`<article class="connection-card ${card.ok?'connection-ok':'connection-error'}"><div class="conn-icon">${card.icon}</div><div><h3>${card.name}</h3><p>${card.detail}</p><small>● ${card.status}</small></div></article>`).join('')}</div></section><section class="settings-link-grid hidden" role="tabpanel" data-settings-panel="general"><button type="button" data-settings-open="kiosks"><i>K</i><span><strong>ตั้งค่า Kiosk</strong><small>Template เมนู และจุดติดตั้ง</small></span><b>›</b></button><button type="button" data-settings-open="calendar"><i>□</i><span><strong>ปฏิทินและวันหยุด</strong><small>เวลาทำการและ Sync วันหยุด HOSxP</small></span><b>›</b></button><button type="button" data-settings-open="users"><i>♙</i><span><strong>ผู้ใช้และสิทธิ์</strong><small>บทบาท สาขา และขอบเขตการเข้าถึง</small></span><b>›</b></button><button type="button" data-settings-open="audit"><i>◷</i><span><strong>ประวัติการใช้งาน</strong><small>ตรวจสอบกิจกรรมสำคัญของระบบ</small></span><b>›</b></button></section></div>`;
      const action=document.querySelector('#viewContent .page-action');
      if(action){action.textContent='↻ ตรวจสอบสถานะ';action.onclick=()=>hydrateView('settings');}
      injectTicketSettings();injectFeatureSettings();bindSystemSettings();
    }
  } catch (error) {
    showToast(error.message);
  }
}

function registrationMonitorHtml(result) {
  const selected=Math.min(3,Math.max(1,Number(localStorage.getItem('kq_registration_counter')||1)));
  const statusLabel={REGISTRATION_WAITING:'รอเรียก',REGISTRATION_CALLED:'เรียกแล้ว',REGISTRATION_IN_PROGRESS:'กำลังเปิด Visit',SENT_TO_DEPARTMENT:'ส่งแผนกแล้ว'};
  const counterCards=(result.counters||[1,2,3].map(counter_no=>({counter_no,ticket:null}))).map(item=>{const ticket=item.ticket,active=item.counter_no===selected;let action='';if(ticket&&result.can_manage&&active){const voice=`data-queue-no="${escapeHtml(ticket.queue_no)}" data-customer-name="${escapeHtml(ticket.customer_spoken_name||'')}"`;action=ticket.current_step==='REGISTRATION_CALLED'?`<button data-queue-action="RECALL" data-ticket-id="${ticket.id}" ${voice}>เรียกซ้ำ</button><button class="primary" data-queue-action="START_REGISTRATION" data-ticket-id="${ticket.id}">เริ่มเปิด Visit</button>`:`<button class="primary" data-queue-action="SEND_DEPARTMENT" data-ticket-id="${ticket.id}">ส่งต่อแผนก</button>`;}return `<article class="counter-card ${active?'selected':''}" data-select-counter="${item.counter_no}" tabindex="0" role="button" aria-pressed="${active}"><header><span>เคาน์เตอร์</span><strong>${item.counter_no}</strong><i>${active?'เครื่องนี้':'แตะเพื่อเลือก'}</i></header>${ticket?`<div class="counter-queue"><b>${escapeHtml(ticket.queue_no)}</b><span>${escapeHtml(ticket.customer_display)}</span><small>${escapeHtml(statusLabel[ticket.current_step]||ticket.current_step)}</small></div><div class="counter-actions">${action}</div>`:`<div class="counter-available"><b>พร้อมรับคิว</b><span>ยังไม่มีผู้รับบริการ</span></div>`}</article>`;}).join('');
  const next=result.next_ticket;
  const waiting=result.tickets.filter(item=>item.current_step==='REGISTRATION_WAITING');
  const waitingRows=waiting.map((item,index)=>[`<strong>${index+1}</strong>`,`<strong>${escapeHtml(item.queue_no)}</strong><small>${item.source==='ONLINE'?'จองออนไลน์':'Walk-in'}</small>`,escapeHtml(item.customer_display),`<strong>${escapeHtml(item.department_name)}</strong><small>${escapeHtml(item.service_name||'')}</small>`,`${item.wait_minutes} นาที`]);
  const nextPanel=next?`<div class="next-queue-number">${escapeHtml(next.queue_no)}</div><div class="next-queue-info"><span>คิวถัดไปที่ควรเรียก</span><strong>${escapeHtml(next.customer_display)}</strong><small>${escapeHtml(next.department_name)} · รอ ${next.wait_minutes} นาที</small></div>${result.can_manage?`<button class="claim-next-button" data-claim-next>เรียกที่เคาน์เตอร์ ${selected}</button>`:''}`:`<div class="next-empty"><strong>ไม่มีคิวรอเรียก</strong><span>ระบบจะอัปเดตเมื่อมีผู้รับบริการเช็กอิน</span></div>`;
  return `<section class="registration-app"><div class="registration-appbar"><div><span class="eyebrow">REGISTRATION DESK</span><h2>Monitor ห้องบัตร</h2><p>${escapeHtml(KQThaiDate.date(result.date))} · อัปเดตอัตโนมัติทุก 10 วินาที</p></div><div class="monitor-actions"><button type="button" class="secondary" data-voice-toggle aria-pressed="${KQQueueVoice.isEnabled()}">${KQQueueVoice.isEnabled()?'🔊 เสียง: เปิด':'🔇 เสียง: ปิด'}</button><button class="secondary" data-monitor-refresh>↻ อัปเดต</button></div></div><div class="counter-grid">${counterCards}</div><article class="next-queue-panel">${nextPanel}</article><div class="waiting-heading"><div><h3>คิวที่กำลังรอ</h3><p>เรียงตามเวลาเช็กอินก่อน–หลัง</p></div><strong>${waiting.length} คิว</strong></div>${waitingRows.length?renderTable(['ลำดับ','เลขคิว','ผู้รับบริการ','แผนก/บริการ','รอแล้ว'],waitingRows):'<div class="monitor-empty">ขณะนี้ไม่มีคิวรอห้องบัตร</div>'}</section>`;
}

async function announceQueue(queueNo,options){try{await adminSystemFeaturesReady;if(!adminSystemFeatures.queue_voice){showToast('ระบบเสียงเรียกคิวถูกปิดจากการตั้งค่าระบบ');return {spoken:false,reason:'disabled-by-admin'}}const result=await KQQueueVoice.announce(queueNo,options);const message=KQQueueVoice.problem(result);if(message)showToast(message);return result}catch(error){showToast(`ไม่สามารถเล่นเสียงเรียกคิว: ${error.message||'เกิดข้อผิดพลาด'}`);return {spoken:false,reason:'error'}}}
async function openVoiceSettings(){
  const current=KQQueueVoice.settings(),voices=await KQQueueVoice.listVoices();
  const wrap=document.createElement('div');wrap.className='modal-backdrop';wrap.innerHTML=`<form class="modal voice-settings-modal"><div class="modal-head"><div><span class="eyebrow">THAI QUEUE VOICE</span><h2>ตั้งค่าเสียงเรียกคิวภาษาไทย</h2></div><button type="button" class="icon-button" data-close>×</button></div><p class="voice-help">ระบบใช้เสียงหญิงไทย Piper จาก Server แบบ Offline เป็นหลัก และใช้เสียงในเครื่องนี้เป็นเสียงสำรองอัตโนมัติ</p><div class="form-grid"><label>รูปแบบเสียง<select name="gender"><option value="female" ${current.gender==='female'?'selected':''}>เสียงหญิง</option><option value="male" ${current.gender==='male'?'selected':''}>เสียงชาย</option></select></label><label>เสียงภาษาไทยในเครื่อง<select name="voiceName"><option value="">เลือกอัตโนมัติตามเพศ</option>${voices.map(voice=>`<option value="${escapeHtml(voice.name)}" ${voice.name===current.voiceName?'selected':''}>${escapeHtml(voice.name)}</option>`).join('')}</select></label><label>ความเร็ว <output data-rate-output>${Number(current.rate).toFixed(1)} เท่า</output><input name="rate" type="range" min="0.5" max="1.5" step="0.1" value="${current.rate}"></label><label>จำนวนครั้งที่อ่าน<select name="repeat">${[1,2,3].map(value=>`<option value="${value}" ${Number(current.repeat)===value?'selected':''}>${value} ครั้ง</option>`).join('')}</select></label><label class="full">จุดที่ให้ไปติดต่อ<input name="destination" value="${escapeHtml(current.destination)}" maxlength="100" required></label><label class="full">ข้อความประกาศ<textarea name="template" rows="3" required>${escapeHtml(current.template)}</textarea><span class="voice-token-toolbar"><button type="button" data-insert-token="{queue}">เลขคิว</button><button type="button" data-insert-token="{name}">ชื่อ</button><button type="button" data-insert-token="{destination}">จุดติดต่อ</button><button type="button" data-insert-token="{pause-short}">เว้นสั้น</button><button type="button" data-insert-token="{pause-medium}">เว้นกลาง</button><button type="button" data-insert-token="{pause-long}">เว้นยาว</button></span><small>วางเคอร์เซอร์ในข้อความ แล้วกดตัวแปรหรือช่วงเว้นที่ต้องการ</small></label><label class="full">คำอ่านกำหนดเอง<textarea name="dictionary" rows="5" placeholder="ER=อีอาร์&#10;NCD=เอ็น ซี ดี">${escapeHtml(KQQueueVoice.dictionaryToText(current.dictionary))}</textarea><small>หนึ่งคำต่อหนึ่งบรรทัด รูปแบบ คำเดิม=คำที่ให้อ่าน</small></label></div><div class="voice-preview" data-voice-preview>${escapeHtml(KQQueueVoice.phrase('A-001'))}</div><div class="auth-error hidden" data-error></div><div class="modal-actions"><button type="button" class="secondary" data-test>▶ ทดลองเสียง</button><button type="button" class="secondary" data-close>ยกเลิก</button><button type="submit" class="primary">บันทึกการตั้งค่า</button></div></form>`;document.body.appendChild(wrap);
  const form=wrap.querySelector('form'),read=()=>{const data=Object.fromEntries(new FormData(form));return {gender:data.gender,voiceName:data.voiceName,rate:Number(data.rate),repeat:Number(data.repeat),queueMode:data.queueMode||current.queueMode,preventPendingDuplicate:data.preventPendingDuplicate!=='allow',destination:data.destination.trim(),template:data.template.trim(),dictionary:KQQueueVoice.dictionaryFromText(data.dictionary)}};
  const repeatField=form.elements.repeat.closest('label');repeatField.insertAdjacentHTML('afterend',`<label>ลำดับการอ่านเสียง<select name="queueMode"><option value="sequential" ${current.queueMode==='sequential'?'selected':''}>รออ่านจบทีละคิว (แนะนำ)</option><option value="overlap" ${current.queueMode==='overlap'?'selected':''}>อนุญาตให้อ่านพร้อมกัน</option></select><small>ใช้กับปุ่มเรียกคิว เรียกซ้ำ และทดสอบเสียงทุกกรณีในเครื่องนี้</small></label>`);
  form.elements.queueMode.closest('label').insertAdjacentHTML('afterend',`<label>การกดคิวเดิมซ้ำ<select name="preventPendingDuplicate"><option value="block" ${current.preventPendingDuplicate?'selected':''}>ห้ามกดซ้ำจนกว่าอ่านจบ (แนะนำ)</option><option value="allow" ${!current.preventPendingDuplicate?'selected':''}>อนุญาตให้กดซ้ำได้</option></select><small>ป้องกันคิวเดิมถูกเพิ่มซ้ำขณะรอหรือกำลังประกาศ</small></label>`);
  const tokenToolbar=wrap.querySelector('.voice-token-toolbar'),templateInput=form.elements.template;tokenToolbar.innerHTML=`<span class="voice-builder-group"><b>คำพูด</b><button type="button" data-insert-text="ขอเชิญ">ขอเชิญ</button><button type="button" data-insert-text="หมายเลข">หมายเลข</button><button type="button" data-insert-text="ที่">ที่</button><button type="button" data-insert-text="ค่ะ">ค่ะ</button><button type="button" data-insert-text="ครับ">ครับ</button></span><span class="voice-builder-group"><b>ข้อมูลคิว</b><button type="button" data-insert-token="{queue}">เลขคิว</button><button type="button" data-insert-token="{name}">ชื่อผู้รับบริการ</button><button type="button" data-insert-token="{destination}">จุดติดต่อ</button></span><span class="voice-builder-group pause"><b>เว้นเสียง</b><button type="button" data-insert-token="{pause-short}">เว้นสั้น</button><button type="button" data-insert-token="{pause-medium}">เว้นกลาง</button><button type="button" data-insert-token="{pause-long}">เว้นยาว</button></span><span class="voice-builder-group tools"><b>จัดการ</b><button type="button" data-template-clear>ล้างข้อความ</button><button type="button" data-template-example>ใช้ตัวอย่าง</button></span>`;tokenToolbar.nextElementSibling.innerHTML='คลิกคำหรือข้อมูลตามลำดับที่ต้องการ ระบบจะนำไปต่อ ณ ตำแหน่งเคอร์เซอร์ และยังพิมพ์แก้ไขเองได้';
  const insertTemplatePart=(value,isWord=false)=>{const start=templateInput.selectionStart??templateInput.value.length,end=templateInput.selectionEnd??start,before=templateInput.value.slice(0,start),after=templateInput.value.slice(end);let part=value;if(isWord&&before&&!/[\s{]$/.test(before))part=' '+part;if(isWord&&after&&!/^[\s}.,]/.test(after))part+=' ';templateInput.setRangeText(part,start,end,'end');templateInput.focus();templateInput.dispatchEvent(new Event('input',{bubbles:true}))};tokenToolbar.querySelectorAll('[data-insert-token]').forEach(button=>button.onclick=()=>insertTemplatePart(button.dataset.insertToken));tokenToolbar.querySelectorAll('[data-insert-text]').forEach(button=>button.onclick=()=>insertTemplatePart(button.dataset.insertText,true));tokenToolbar.querySelector('[data-template-clear]').onclick=()=>{templateInput.value='';templateInput.focus();templateInput.dispatchEvent(new Event('input',{bubbles:true}))};tokenToolbar.querySelector('[data-template-example]').onclick=()=>{templateInput.value='ขอเชิญ {name}{pause-medium}หมายเลข {queue}{pause-long}ที่ {destination} ค่ะ';templateInput.focus();templateInput.dispatchEvent(new Event('input',{bubbles:true}))};
  const refresh=()=>{const draft=read();wrap.querySelector('[data-rate-output]').textContent=`${draft.rate.toFixed(1)} เท่า`;wrap.querySelector('[data-voice-preview]').textContent=KQQueueVoice.phrase('A-001',draft.destination,draft)};
  form.elements.gender.addEventListener('change',()=>{form.elements.voiceName.value='';refresh()});form.addEventListener('input',refresh);wrap.querySelectorAll('[data-close]').forEach(button=>button.onclick=()=>wrap.remove());let backdropPress=false;wrap.addEventListener('pointerdown',event=>{backdropPress=event.target===wrap});wrap.addEventListener('pointerup',event=>{if(backdropPress&&event.target===wrap)wrap.remove();backdropPress=false});wrap.addEventListener('pointercancel',()=>{backdropPress=false});wrap.querySelector('[data-test]').onclick=async()=>{const error=wrap.querySelector('[data-error]');error.classList.add('hidden');const draft=read(),result=await KQQueueVoice.announce('A-001',{...draft,force:true});const problem=KQQueueVoice.problem(result);if(problem){error.textContent=problem;error.classList.remove('hidden');return}error.textContent=result.genderMatched?`กำลังใช้เสียง: ${result.voice}`:`เครื่องนี้ไม่พบเสียง${draft.gender==='male'?'ชาย':'หญิง'}ภาษาไทย จึงใช้เสียงที่มีอยู่: ${result.voice}`;error.classList.remove('hidden')};form.onsubmit=event=>{event.preventDefault();KQQueueVoice.save(read());wrap.remove();showToast('บันทึกการตั้งค่าเสียงของเครื่องนี้แล้ว')};
}
function injectTicketSettings(){const tabs=document.querySelector('.settings-tabs'),before=tabs?.querySelector('[data-settings-tab="connections"]');if(!tabs||!before)return;const tab=document.createElement('button');tab.type='button';tab.setAttribute('role','tab');tab.setAttribute('aria-selected','false');tab.dataset.settingsTab='ticket';tab.textContent='รูปแบบบัตรคิว';tabs.insertBefore(tab,before);const panel=document.createElement('section');panel.className='hidden';panel.setAttribute('role','tabpanel');panel.dataset.settingsPanel='ticket';panel.id='ticketTemplateSettings';tabs.parentElement.appendChild(panel)}
function injectFeatureSettings(){const tabs=document.querySelector('.settings-tabs'),before=tabs?.querySelector('[data-settings-tab="ticket"]');if(!tabs||!before)return;const tab=document.createElement('button');tab.type='button';tab.setAttribute('role','tab');tab.setAttribute('aria-selected','false');tab.dataset.settingsTab='features';tab.textContent='การเปิดใช้งาน';tabs.insertBefore(tab,before);const panel=document.createElement('section');panel.className='hidden';panel.setAttribute('role','tabpanel');panel.dataset.settingsPanel='features';panel.id='featureSettings';tabs.parentElement.appendChild(panel)}
function bindSystemSettings(){const selectTab=name=>{document.querySelectorAll('[data-settings-tab]').forEach(tab=>{const active=tab.dataset.settingsTab===name;tab.classList.toggle('active',active);tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1});document.querySelectorAll('[data-settings-panel]').forEach(panel=>panel.classList.toggle('hidden',panel.dataset.settingsPanel!==name));if(name==='ticket')window.KQTicketTemplateSettings?.render(document.querySelector('#ticketTemplateSettings'));if(name==='features')window.KQFeatureSettings?.render(document.querySelector('#featureSettings'))};document.querySelectorAll('[data-settings-tab]').forEach(tab=>{tab.addEventListener('click',()=>selectTab(tab.dataset.settingsTab));tab.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight'].includes(event.key))return;const tabs=[...document.querySelectorAll('[data-settings-tab]')],index=tabs.indexOf(tab),next=tabs[(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length];selectTab(next.dataset.settingsTab);next.focus()})});document.querySelector('[data-settings-voice]')?.addEventListener('click',openVoiceSettings);document.querySelector('[data-settings-voice-test]')?.addEventListener('click',()=>{KQQueueVoice.unlock();announceQueue('A-001',{force:true,customerName:'คุณทดสอบ ระบบ',dedupeKey:'settings-voice-test'})});document.querySelector('[data-settings-voice-toggle]')?.addEventListener('click',()=>{KQQueueVoice.setEnabled(!KQQueueVoice.isEnabled());hydrateView('settings')});document.querySelectorAll('[data-settings-open]').forEach(button=>button.addEventListener('click',()=>document.querySelector(`.nav-item[data-view="${button.dataset.settingsOpen}"]`)?.click()))}
function bindRegistrationMonitor(){const selected=()=>Math.min(3,Math.max(1,Number(localStorage.getItem('kq_registration_counter')||1)));document.querySelector('[data-monitor-refresh]')?.addEventListener('click',()=>hydrateView('queues'));document.querySelectorAll('[data-select-counter]').forEach(card=>{const choose=event=>{if(event.target.closest('button'))return;localStorage.setItem('kq_registration_counter',card.dataset.selectCounter);hydrateView('queues')};card.addEventListener('click',choose);card.addEventListener('keydown',event=>{if(['Enter',' '].includes(event.key)){event.preventDefault();choose(event)}})});const toggle=document.querySelector('[data-voice-toggle]');if(toggle)toggle.onclick=()=>{KQQueueVoice.setEnabled(!KQQueueVoice.isEnabled());hydrateView('queues')};document.querySelector('[data-claim-next]')?.addEventListener('click',async event=>{KQQueueVoice.unlock();const button=event.currentTarget,counterNo=selected();button.disabled=true;try{const result=await kqApi('claim-next-registration',{method:'POST',body:JSON.stringify({counter_no:counterNo})}),ticket=result.ticket,voiceKey=`queue-${ticket.id}`;const voice=await announceQueue(ticket.queue_no,{force:true,customerName:ticket.customer_spoken_name,destination:`เคาน์เตอร์ ${counterNo}`,dedupeKey:voiceKey});showToast(voice.spoken?`เรียกคิว ${ticket.queue_no} ที่เคาน์เตอร์ ${counterNo} แล้ว`:(KQQueueVoice.problem(voice)||'เรียกคิวแล้ว แต่ไม่สามารถเล่นเสียงได้'));await hydrateView('queues')}catch(error){showToast(error.message)}finally{button.disabled=false}});document.querySelectorAll('[data-queue-action]').forEach(button=>button.addEventListener('click',async()=>{KQQueueVoice.unlock();button.disabled=true;const transition=button.dataset.queueAction,counterNo=selected(),voiceKey=`queue-${button.dataset.ticketId}`;try{if(['CALL','RECALL'].includes(transition)&&KQQueueVoice.isPending(voiceKey))throw new Error('คิวนี้อยู่ระหว่างประกาศ กรุณารอให้เสียงอ่านจบก่อน');await kqApi('queue-transition',{method:'POST',body:JSON.stringify({ticket_id:Number(button.dataset.ticketId),transition,counter_no:counterNo})});if(['CALL','RECALL'].includes(transition)&&button.dataset.queueNo)await announceQueue(button.dataset.queueNo,{force:true,customerName:button.dataset.customerName,destination:`เคาน์เตอร์ ${counterNo}`,dedupeKey:voiceKey});showToast('อัปเดตสถานะคิวแล้ว');await hydrateView('queues')}catch(error){showToast(error.message)}finally{button.disabled=false}}));}

setInterval(()=>{if(document.querySelector('.nav-item[data-view="queues"]')?.classList.contains('active')&&!authGate.classList.contains('hidden'))hydrateView('queues')},10000);

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

