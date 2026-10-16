'use strict';

const KQ_API = '../api/index.php';
let csrfToken = '';

async function kqApi(action, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const response = await fetch(`${KQ_API}?action=${encodeURIComponent(action)}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', ...(method !== 'GET' && csrfToken ? {'X-CSRF-Token': csrfToken} : {}), ...(options.headers || {}) },
    ...options
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

function setAuthMode(setup) {
  setupMode = setup;
  document.querySelectorAll('.setup-only').forEach(node => node.classList.toggle('hidden', !setup));
  document.getElementById('authEyebrow').textContent = setup ? 'ตั้งค่าครั้งแรก' : 'เข้าสู่ระบบ';
  document.getElementById('authTitle').textContent = setup ? 'สร้างผู้ดูแลระบบ' : 'ยินดีต้อนรับกลับ';
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
    if (view === 'queues') result = await kqApi('bookings');
    else if (view === 'capacity') result = await kqApi('slots');
    else if (['branches', 'services', 'audit'].includes(view)) result = await kqApi(view);
    else return;

    const card = document.querySelector('#viewContent .page-card');
    const existing = card?.querySelector('.table-wrap,.branch-grid');
    if (!existing) return;
    if (view === 'queues') existing.outerHTML = renderTable(['เลขอ้างอิง','ผู้รับบริการ','บริการ','เวลานัด','สถานะ'], result.data.map(item => [escapeHtml(item.reference_no),escapeHtml(item.customer_name),escapeHtml(item.service_name),`${item.start_time} น.`,`<span class="badge blue">${escapeHtml(item.status)}</span>`]));
    if (view === 'capacity') existing.outerHTML = renderTable(['ช่วงเวลา','แผนก','บริการ','Capacity','จองแล้ว','สถานะ'], result.data.map(item => [`${item.start_time}–${item.end_time} น.`,escapeHtml(item.department_name),escapeHtml(item.service_name),item.capacity,item.booked_count,`<span class="badge ${Number(item.booked_count)>=Number(item.capacity)?'amber':'green'}">${Number(item.booked_count)>=Number(item.capacity)?'เต็ม':'เปิด'}</span>`]));
    if (view === 'services') existing.outerHTML = renderTable(['รหัส','ชื่อบริการ','แผนก','เวลาเฉลี่ย','สถานะ'], result.data.map(item => [escapeHtml(item.code),escapeHtml(item.name),escapeHtml(item.department_name),`${item.average_minutes} นาที`,'<span class="badge green">เปิด</span>']));
    if (view === 'branches') existing.innerHTML = result.data.map(item => `<article class="branch-card"><div class="branch-top"><h3>${escapeHtml(item.name)}</h3><span class="badge green">เปิดบริการ</span></div><p>รหัส ${escapeHtml(item.code)}</p><div class="branch-meta"><div><span>เวลาทำการ</span><strong>${item.open_time}–${item.close_time} น.</strong></div><div><span>โครงสร้าง</span><strong>${item.department_count} แผนก · ${item.counter_count} ช่อง</strong></div></div></article>`).join('');
    if (view === 'audit') existing.outerHTML = renderTable(['วันเวลา','ผู้กระทำ','กิจกรรม','ประเภท','ผลลัพธ์'], result.data.map(item => [escapeHtml(KQThaiDate.dateTime(item.created_at)),escapeHtml(item.display_name || 'ระบบ'),escapeHtml(item.action),escapeHtml(item.entity_type),'<span class="badge green">สำเร็จ</span>']));
  } catch (error) {
    showToast(error.message);
  }
}

function finishAuth(user) {
  authGate.classList.add('hidden');
  const profileName = document.querySelector('.profile strong');
  if (profileName) profileName.textContent = user.display_name;
  loadDashboard();
}

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

