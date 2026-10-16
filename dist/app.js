document.getElementById('todayText').textContent = KQThaiDate.date(new Date(),{weekday:'long'});

const departments = [
  {name:'แผนกทะเบียน', meta:'4 ช่องบริการ · เฉลี่ย 14 นาที', used:19, total:24, color:'#2b65d9'},
  {name:'แผนกตรวจทั่วไป', meta:'3 ช่องบริการ · เฉลี่ย 18 นาที', used:14, total:18, color:'#12a06c'},
  {name:'แผนกชำระเงิน', meta:'2 ช่องบริการ · เฉลี่ย 9 นาที', used:9, total:16, color:'#e8991c'},
  {name:'แผนกรับเอกสาร', meta:'1 ช่องบริการ · เฉลี่ย 7 นาที', used:6, total:12, color:'#7658d6'}
];

document.getElementById('departmentList').innerHTML = departments.map(d => `
  <div class="department-row">
    <div class="department-name"><strong>${d.name}</strong><span>${d.meta}</span></div>
    <div class="capacity-bar"><i style="width:${Math.round(d.used/d.total*100)}%;background:${d.color}"></i></div>
    <div class="capacity-text"><strong>${d.used}/${d.total}</strong><span>ใช้ไป ${Math.round(d.used/d.total*100)}%</span></div>
  </div>`).join('');

const chartData = [[8,6],[12,9],[17,13],[20,16],[24,18],[19,17],[27,20],[22,19],[30,24],[26,23],[18,17]];
const max = Math.max(...chartData.flat());
document.getElementById('queueChart').innerHTML = chartData.map((v,i) => `
  <div class="bar-group"><i class="bar in" style="height:${v[0]/max*92}%"></i><i class="bar out" style="height:${v[1]/max*92}%"></i><span class="bar-label">${8+i}:00</span></div>`).join('');

const views = {
  queues:{title:'คิววันนี้',subtitle:'ติดตามและควบคุมคิวแบบเรียลไทม์',action:'＋ เพิ่มคิว Walk-in',type:'table'},
  capacity:{title:'Slot และกำลังบริการ',subtitle:'กำหนดความจุให้เหมาะสมกับเวลาทำการ',action:'＋ ปรับ Slot',type:'capacity'},
  branches:{title:'สาขาและแผนก',subtitle:'โครงสร้างหน่วยบริการของ K-QCENTER',action:'＋ เพิ่มสาขา',type:'branches'},
  services:{title:'ประเภทบริการ',subtitle:'ระยะเวลา เงื่อนไข และแผนกที่ให้บริการ',action:'＋ เพิ่มบริการ',type:'services'},
  calendar:{title:'ปฏิทินทำการ',subtitle:'เวลาทำการ วันหยุด และ Override รายวัน',action:'＋ เพิ่มวันพิเศษ',type:'calendar'},
  connections:{title:'การเชื่อมต่อระบบ',subtitle:'สถานะบริการภายในและช่องทาง Tailscale',action:'ทดสอบทั้งหมด',type:'connections'},
  users:{title:'ผู้ใช้และสิทธิ์',subtitle:'ควบคุมสิทธิ์ตามบทบาท สาขา และแผนก',action:'＋ เชิญผู้ใช้',type:'users'},
  audit:{title:'ประวัติการใช้งาน',subtitle:'ตรวจสอบกิจกรรมและการเปลี่ยนแปลงสำคัญ',action:'ส่งออกรายงาน',type:'audit'}
};

const queueRows = [
 ['A-042','สมชาย ใจดี','ทะเบียนผู้ป่วย','17:30 น.','กำลังรอ','green'],
 ['B-018','พรทิพย์ มีสุข','ตรวจทั่วไป','17:30 น.','เรียกแล้ว','blue'],
 ['C-011','ณัฐพงษ์ วัฒนา','ชำระเงิน','17:30 น.','กำลังบริการ','blue'],
 ['A-043','ศิริพร แสงทอง','ทะเบียนผู้ป่วย','18:00 น.','ยืนยันแล้ว','amber'],
 ['D-007','วรพล มั่นคง','รับเอกสาร','18:00 น.','ยืนยันแล้ว','amber']
];

function table(headers, rows){return `<div class="table-wrap"><table class="data-table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${r.map((c,i)=>i===r.length-2&&r.length>4?`<td><span class="badge ${r[r.length-1]}">${c}</span></td>`:i===r.length-1&&['green','blue','amber'].includes(c)?'':`<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`}

function contentFor(view){
 const v=views[view];
 if(view==='queues') return table(['เลขคิว','ผู้รับบริการ','บริการ','เวลานัด','สถานะ'],queueRows);
 if(view==='capacity') return table(['ช่วงเวลา','แผนก','ช่องบริการ','Capacity','จองแล้ว','สถานะ'],[
  ['17:00–17:30 น.','ทะเบียน','4','6','5','เปิด','green'],['17:30–18:00 น.','ทะเบียน','4','6','6','เต็ม','amber'],['18:00–18:30 น.','ตรวจทั่วไป','3','4','2','เปิด','green'],['18:30–19:00 น.','ชำระเงิน','2','5','3','เปิด','green']]);
 if(view==='branches') return `<div class="branch-grid">${[['สาขาหลัก','08:30–20:00 น.','4 แผนก','12 ช่อง'],['ศูนย์บริการอาคาร B','09:00–17:00 น.','2 แผนก','5 ช่อง'],['หน่วยบริการด่วน','10:00–18:00 น.','1 แผนก','3 ช่อง']].map(b=>`<article class="branch-card"><div class="branch-top"><h3>${b[0]}</h3><span class="badge green">เปิดบริการ</span></div><p>ตั้งค่าเวลาทำการและกำลังบริการแยกตามวัน</p><div class="branch-meta"><div><span>เวลาทำการ</span><strong>${b[1]}</strong></div><div><span>โครงสร้าง</span><strong>${b[2]} · ${b[3]}</strong></div></div></article>`).join('')}</div>`;
 if(view==='connections') return `<div class="connection-grid">${[['TS','Tailscale Funnel','https://k-qcenter.ts.net','ออนไลน์'],['SV','K-QCENTER API','127.0.0.1:3001','ตอบสนอง 38 ms'],['DB','PostgreSQL','Internal connection','เชื่อมต่อแล้ว'],['RD','Redis','Capacity lock service','เชื่อมต่อแล้ว']].map(c=>`<article class="connection-card"><div class="conn-icon">${c[0]}</div><div><h3>${c[1]}</h3><p>${c[2]}</p><small>● ${c[3]}</small></div><button class="text-button test-connection">ทดสอบ</button></article>`).join('')}</div>`;
 const rows={services:[['REG-01','ลงทะเบียนผู้รับบริการ','ทะเบียน','15 นาที','เปิด','green'],['GEN-01','ตรวจทั่วไป','ตรวจทั่วไป','20 นาที','เปิด','green'],['PAY-01','ชำระเงิน','ชำระเงิน','10 นาที','เปิด','green']],calendar:[['จ.–ศ.','วันทำการปกติ','08:30–20:00 น.','พัก 12:00–13:00 น.','ใช้งาน','green'],['23 ต.ค. 2569','วันปิยมหาราช','ปิดบริการ','วันหยุด','กำหนดแล้ว','amber']],users:[['กิตติศักดิ์ สมบูรณ์','ผู้ดูแลระบบ','ทุกสาขา','ใช้งานล่าสุดวันนี้','ใช้งาน','green'],['อรทัย พรหมมา','หัวหน้าแผนก','สาขาหลัก · ทะเบียน','วันนี้ 16:48 น.','ใช้งาน','green'],['ภาณุพงศ์ ดีพร้อม','เจ้าหน้าที่เคาน์เตอร์','สาขาหลัก · ตรวจทั่วไป','วันนี้ 16:32 น.','ใช้งาน','green']],audit:[['วันนี้ 17:35 น.','อรทัย พรหมมา','ปรับ Capacity','Slot 18:00 จาก 5 เป็น 4','สำเร็จ','green'],['วันนี้ 16:12 น.','กิตติศักดิ์ สมบูรณ์','ทดสอบการเชื่อมต่อ','Tailscale Funnel','สำเร็จ','green'],['14 ต.ค. 2569 15:40 น.','ระบบ','ปิดรับคิวอัตโนมัติ','คาดการณ์เกินเวลาปิด','สำเร็จ','blue']]}[view];
 return table(view==='services'?['รหัส','ชื่อบริการ','แผนก','เวลาเฉลี่ย','สถานะ']:view==='calendar'?['วันที่','ประเภท','เวลาทำการ','หมายเหตุ','สถานะ']:view==='users'?['ชื่อ','บทบาท','ขอบเขต','กิจกรรมล่าสุด','สถานะ']:['วันเวลา','ผู้กระทำ','กิจกรรม','รายละเอียด','ผลลัพธ์'],rows);
}

function switchView(view){
 document.querySelectorAll('.nav-item').forEach(n=>n.classList.toggle('active',n.dataset.view===view));
 const dashboard=document.getElementById('dashboardView'), generic=document.getElementById('genericView');
 if(view==='dashboard'){dashboard.classList.remove('hidden');generic.classList.add('hidden');document.getElementById('pageTitle').textContent='ภาพรวมระบบ';return}
 dashboard.classList.add('hidden');generic.classList.remove('hidden');const v=views[view];document.getElementById('pageTitle').textContent=v.title;
 document.getElementById('viewContent').innerHTML=`<div class="page-card"><div class="page-toolbar"><div><h2>${v.title}</h2><p>${v.subtitle}</p></div><button class="primary page-action">${v.action}</button></div>${contentFor(view)}</div>`;
 document.querySelector('.page-action').onclick=()=> view==='capacity'?openModal():showToast('บันทึกคำสั่งแล้ว — หน้าฟอร์มจะเชื่อม API ในระยะถัดไป');
 document.querySelectorAll('.test-connection').forEach(b=>b.onclick=()=>showToast('ทดสอบสำเร็จ · ตอบสนองภายใน 42 ms'));
 document.getElementById('sidebar').classList.remove('open');
}

document.querySelectorAll('.nav-item').forEach(n=>n.onclick=()=>switchView(n.dataset.view));
document.querySelectorAll('[data-jump]').forEach(n=>n.onclick=()=>switchView(n.dataset.jump));
document.getElementById('menuButton').onclick=()=>document.getElementById('sidebar').classList.toggle('open');

const modal=document.getElementById('modalBackdrop');
function openModal(){modal.classList.remove('hidden');calculateCapacity()}
function closeModal(){modal.classList.add('hidden')}
document.getElementById('closeModal').onclick=closeModal;document.getElementById('cancelModal').onclick=closeModal;
['counterInput','durationInput','efficiencyInput'].forEach(id=>document.getElementById(id).addEventListener('input',calculateCapacity));
function calculateCapacity(){const c=+document.getElementById('counterInput').value||0,d=+document.getElementById('durationInput').value||1,e=+document.getElementById('efficiencyInput').value;document.getElementById('capacityResult').textContent=`${Math.floor(30*c*e/d)} คิว`}
document.getElementById('slotForm').onsubmit=e=>{e.preventDefault();closeModal();showToast('บันทึก Slot และคำนวณ Capacity ใหม่แล้ว')};
function showToast(message){const t=document.getElementById('toast');t.textContent='✓  '+message;t.classList.add('show');clearTimeout(window.toastTimer);window.toastTimer=setTimeout(()=>t.classList.remove('show'),2800)}
