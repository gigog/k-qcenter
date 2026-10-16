'use strict';

let adminCache = { branches: [], departments: [], services: [], calendars: [] };
const thaiShortDate = new Intl.DateTimeFormat('th-TH-u-ca-buddhist', {day:'numeric',month:'short',year:'numeric',timeZone:'Asia/Bangkok'});

function activeBadge(active) {
  return `<span class="badge ${Number(active) ? 'green' : 'amber'}">${Number(active) ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}</span>`;
}

async function loadAdminData() {
  const [branches, departments, services, calendars] = await Promise.all([
    kqApi('branches'), kqApi('departments'), kqApi('services'), kqApi('calendars')
  ]);
  adminCache = {branches: branches.data, departments: departments.data, services: services.data, calendars: calendars.data};
}

async function renderOrganizationPage() {
  try {
    await loadAdminData();
    document.getElementById('viewContent').innerHTML = `<div class="page-card">
      <div class="page-toolbar"><div><h2>สาขาและแผนก</h2><p>กำหนดเวลาทำการ ช่องบริการ ประสิทธิภาพ และโควตา</p></div><button class="primary" data-crud-add="branch">＋ เพิ่มสาขา</button></div>
      <section class="admin-section"><div class="section-title"><div><h3>สาขาทั้งหมด</h3><p>${adminCache.branches.length} สาขาในระบบ</p></div></div>
        <div class="branch-grid">${adminCache.branches.map(item => `<article class="branch-card ${Number(item.active)?'':'inactive-row'}"><div class="branch-top"><h3>${escapeHtml(item.name)}</h3>${activeBadge(item.active)}</div><p>รหัส ${escapeHtml(item.code)}</p><div class="branch-meta"><div><span>เวลาทำการ</span><strong>${item.open_time}–${item.close_time} น.</strong></div><div><span>โครงสร้าง</span><strong>${item.department_count} แผนก · ${item.counter_count} ช่อง</strong></div></div><div class="action-cell" style="margin-top:12px"><button class="record-button" data-crud-edit="branch" data-id="${item.id}">แก้ไข</button></div></article>`).join('')}</div>
      </section>
      <section class="admin-section"><div class="section-title"><div><h3>แผนก</h3><p>Capacity จะคำนวณจากจำนวนช่องและ Efficiency</p></div><button class="mini-button" data-crud-add="department">＋ เพิ่มแผนก</button></div>
        ${simpleTable(['รหัส','แผนก','สาขา','ช่อง','Efficiency','โควตา','สถานะ',''],adminCache.departments.map(item=>[escapeHtml(item.code),escapeHtml(item.name),escapeHtml(item.branch_name),item.counter_count,`${Math.round(Number(item.efficiency)*100)}%`,`${item.online_quota_percent}/${item.walkin_quota_percent}/${item.reserve_quota_percent}`,activeBadge(item.active),`<button class="record-button" data-crud-edit="department" data-id="${item.id}">แก้ไข</button>`]),adminCache.departments)}
      </section></div>`;
  } catch (error) { showToast(error.message); }
}

async function renderServicesPage() {
  try {
    await loadAdminData();
    document.getElementById('viewContent').innerHTML = `<div class="page-card"><div class="page-toolbar"><div><h2>ประเภทบริการ</h2><p>ระยะเวลาบริการมีผลต่อ Capacity ของแต่ละ Slot</p></div><button class="primary" data-crud-add="service">＋ เพิ่มบริการ</button></div>${simpleTable(['รหัส','บริการ','แผนก','เวลาเฉลี่ย','เตรียมงาน','สถานะ',''],adminCache.services.map(item=>[escapeHtml(item.code),escapeHtml(item.name),escapeHtml(item.department_name),`${item.average_minutes} นาที`,`${item.preparation_minutes} นาที`,activeBadge(item.active),`<button class="record-button" data-crud-edit="service" data-id="${item.id}">แก้ไข</button>`]),adminCache.services)}</div>`;
  } catch (error) { showToast(error.message); }
}

async function renderCalendarPage() {
  try {
    await loadAdminData();
    document.getElementById('viewContent').innerHTML = `<div class="page-card"><div class="page-toolbar"><div><h2>ปฏิทินทำการ</h2><p>วันหยุดและเวลาเปิดพิเศษ แสดงวันที่ด้วยปฏิทินไทย</p></div><button class="primary" data-crud-add="calendar">＋ เพิ่มวันพิเศษ</button></div>${simpleTable(['วันที่','รายการ','สาขา','เวลาทำการ','หมายเหตุ','สถานะ',''],adminCache.calendars.map(item=>[`<div class="calendar-date">${thaiShortDate.format(new Date(item.override_date+'T12:00:00+07:00'))}<small>${item.override_date}</small></div>`,escapeHtml(item.name),escapeHtml(item.branch_name),Number(item.is_closed)?'<span class="badge amber">ปิดบริการ</span>':`${item.open_time}–${item.close_time} น.`,escapeHtml(item.note||'-'),activeBadge(item.active),`<button class="record-button" data-crud-edit="calendar" data-id="${item.id}">แก้ไข</button>`]),adminCache.calendars)}</div>`;
  } catch (error) { showToast(error.message); }
}

function simpleTable(headers, rows, records=[]) {
  return `<div class="table-wrap"><table class="data-table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map((row,index)=>`<tr class="${records[index]&&!Number(records[index].active)?'inactive-row':''}">${row.map(cell=>`<td>${cell}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}">ยังไม่มีข้อมูล</td></tr>`}</tbody></table></div>`;
}

function branchOptions(selected='') { return adminCache.branches.map(item=>`<option value="${item.id}" ${String(item.id)===String(selected)?'selected':''}>${escapeHtml(item.name)}</option>`).join(''); }
function departmentOptions(selected='') { return adminCache.departments.map(item=>`<option value="${item.id}" ${String(item.id)===String(selected)?'selected':''}>${escapeHtml(item.name)} · ${escapeHtml(item.branch_name)}</option>`).join(''); }

function openCrudDialog(type, record={}) {
  const isEdit=Boolean(record.id); let title=''; let fields='';
  if(type==='branch'){title=isEdit?'แก้ไขสาขา':'เพิ่มสาขา';fields=`<label>รหัสสาขา<input name="code" value="${escapeHtml(record.code||'')}" required pattern="[A-Za-z0-9_-]{2,20}"></label><label>ชื่อสาขา<input name="name" value="${escapeHtml(record.name||'')}" required></label><label>เวลาเปิด<input name="open_time" type="time" value="${record.open_time||'08:30'}" required></label><label>เวลาปิด<input name="close_time" type="time" value="${record.close_time||'20:00'}" required></label><label>Closing buffer (นาที)<input name="closing_buffer_minutes" type="number" min="0" max="180" value="${record.closing_buffer_minutes??30}" required></label>`;}
  if(type==='department'){title=isEdit?'แก้ไขแผนก':'เพิ่มแผนก';fields=`<label>สาขา<select name="branch_id" required>${branchOptions(record.branch_id)}</select></label><label>รหัสแผนก<input name="code" value="${escapeHtml(record.code||'')}" required pattern="[A-Za-z0-9_-]{2,20}"></label><label>ชื่อแผนก<input name="name" value="${escapeHtml(record.name||'')}" required></label><label>จำนวนช่องบริการ<input name="counter_count" type="number" min="1" max="100" value="${record.counter_count??1}" required></label><label>Efficiency<select name="efficiency">${[.7,.75,.8,.85,.9].map(v=>`<option value="${v}" ${Number(record.efficiency||.8)===v?'selected':''}>${v*100}%</option>`).join('')}</select></label><div class="quota-row"><label>Online %<input name="online_quota_percent" type="number" min="0" max="100" value="${record.online_quota_percent??70}"></label><label>Walk-in %<input name="walkin_quota_percent" type="number" min="0" max="100" value="${record.walkin_quota_percent??20}"></label><label>Reserve %<input name="reserve_quota_percent" type="number" min="0" max="100" value="${record.reserve_quota_percent??10}"></label></div>`;}
  if(type==='service'){title=isEdit?'แก้ไขบริการ':'เพิ่มบริการ';fields=`<label>แผนก<select name="department_id" required>${departmentOptions(record.department_id)}</select></label><label>รหัสบริการ<input name="code" value="${escapeHtml(record.code||'')}" required pattern="[A-Za-z0-9_-]{2,30}"></label><label>ชื่อบริการ<input name="name" value="${escapeHtml(record.name||'')}" required></label><label>เวลาเฉลี่ย (นาที)<input name="average_minutes" type="number" min="1" max="480" value="${record.average_minutes??15}" required></label><label>เวลาเตรียมงาน (นาที)<input name="preparation_minutes" type="number" min="0" max="120" value="${record.preparation_minutes??0}" required></label>`;}
  if(type==='calendar'){title=isEdit?'แก้ไขวันพิเศษ':'เพิ่มวันพิเศษ';fields=`<label>สาขา<select name="branch_id" required>${branchOptions(record.branch_id)}</select></label><label>วันที่<input name="override_date" type="date" value="${record.override_date||''}" required></label><label class="full">ชื่อรายการ<input name="name" value="${escapeHtml(record.name||'')}" required></label><label class="toggle-label"><input name="is_closed" type="checkbox" ${Number(record.is_closed)?'checked':''}> ปิดบริการทั้งวัน</label><label class="calendar-time">เวลาเปิด<input name="open_time" type="time" value="${record.open_time||'08:30'}"></label><label class="calendar-time">เวลาปิด<input name="close_time" type="time" value="${record.close_time||'20:00'}"></label><label class="full">หมายเหตุ<textarea name="note">${escapeHtml(record.note||'')}</textarea></label>`;}
  const wrapper=document.createElement('div');wrapper.className='crud-backdrop';wrapper.innerHTML=`<form class="crud-dialog" data-type="${type}"><header><div><span class="eyebrow">K-QCENTER ADMIN</span><h2>${title}</h2></div><button type="button" class="icon-button" data-dialog-close>×</button></header><input type="hidden" name="id" value="${record.id||''}"><div class="crud-form-grid">${fields}<label class="toggle-label full"><input name="active" type="checkbox" ${record.active===undefined||Number(record.active)?'checked':''}> เปิดใช้งานรายการนี้</label></div><div class="auth-error hidden" data-form-error></div><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>ยกเลิก</button><button type="submit" class="primary">บันทึกข้อมูล</button></div></form>`;document.body.appendChild(wrapper);
  const closed=wrapper.querySelector('[name="is_closed"]');if(closed){const toggle=()=>wrapper.querySelectorAll('.calendar-time').forEach(x=>x.classList.toggle('hidden',closed.checked));closed.onchange=toggle;toggle();}
  wrapper.querySelectorAll('[data-dialog-close]').forEach(button=>button.onclick=()=>wrapper.remove());wrapper.onclick=event=>{if(event.target===wrapper)wrapper.remove()};wrapper.querySelector('form').onsubmit=event=>submitCrud(event,wrapper,type);
}

async function submitCrud(event, wrapper, type) {
  event.preventDefault();const form=event.currentTarget;const values=Object.fromEntries(new FormData(form));values.active=form.elements.active.checked;values.id=values.id?Number(values.id):0;if(type==='calendar')values.is_closed=form.elements.is_closed.checked;const errorBox=wrapper.querySelector('[data-form-error]');errorBox.classList.add('hidden');
  try{await kqApi(`save-${type}`,{method:'POST',body:JSON.stringify(values)});wrapper.remove();showToast('บันทึกข้อมูลเรียบร้อย');if(type==='branch'||type==='department')await renderOrganizationPage();if(type==='service')await renderServicesPage();if(type==='calendar')await renderCalendarPage();}
  catch(error){errorBox.textContent=error.message;errorBox.classList.remove('hidden');}
}

document.addEventListener('click',event=>{const add=event.target.closest('[data-crud-add]');const edit=event.target.closest('[data-crud-edit]');if(add){openCrudDialog(add.dataset.crudAdd);return}if(edit){const type=edit.dataset.crudEdit;const lists={branch:adminCache.branches,department:adminCache.departments,service:adminCache.services,calendar:adminCache.calendars};openCrudDialog(type,(lists[type]||[]).find(item=>String(item.id)===String(edit.dataset.id))||{});}},true);

document.querySelectorAll('.nav-item').forEach(node=>{node.onclick=()=>{const view=node.dataset.view;switchView(view);if(view==='branches')renderOrganizationPage();else if(view==='services')renderServicesPage();else if(view==='calendar')renderCalendarPage();else if(view==='dashboard')loadDashboard();else hydrateView(view);};});

