'use strict';
window.KQThaiDate=Object.freeze({
  date(value,options={}){if(!value)return '—';const source=value instanceof Date?value:new Date(String(value).includes('T')?value:`${value}T12:00:00+07:00`);return new Intl.DateTimeFormat('th-TH-u-ca-buddhist',{day:'numeric',month:'long',year:'numeric',timeZone:'Asia/Bangkok',...options}).format(source)},
  dateTime(value){if(!value)return '—';return new Intl.DateTimeFormat('th-TH-u-ca-buddhist',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',hourCycle:'h23',timeZone:'Asia/Bangkok'}).format(new Date(value))+' น.'},
  todayISO(){const parts=new Intl.DateTimeFormat('en',{timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());const values=Object.fromEntries(parts.filter(part=>part.type!=='literal').map(part=>[part.type,part.value]));return `${values.year}-${values.month}-${values.day}`},
  addDays(iso,days){const date=new Date(`${iso}T00:00:00Z`);date.setUTCDate(date.getUTCDate()+days);return date.toISOString().slice(0,10)},
  options(selected='',pastDays=0,futureDays=730){const today=this.todayISO();const values=[];for(let offset=-pastDays;offset<=futureDays;offset++){const iso=this.addDays(today,offset);values.push(`<option value="${iso}" ${iso===selected?'selected':''}>${this.date(iso,{weekday:'short'})}</option>`)}if(selected&&!values.some(option=>option.includes(`value="${selected}"`)))values.unshift(`<option value="${selected}" selected>${this.date(selected,{weekday:'short'})}</option>`);return values.join('')}
});

// Thai Buddhist calendar picker. Any <select> whose options are ISO dates is
// shown as a calendar; the select stays in the DOM as the value store, so
// existing .value reads, onchange handlers and form submits keep working.
(()=>{
  const ISO=/^\d{4}-\d{2}-\d{2}$/;
  const WEEKDAYS=['อา','จ','อ','พ','พฤ','ศ','ส'];
  const monthTitle=new Intl.DateTimeFormat('th-TH-u-ca-buddhist',{month:'long',year:'numeric',timeZone:'UTC'});
  const pickers=new WeakMap();
  let openPicker=null;

  const css=`.kq-cal{position:relative;margin-top:5px}
.kq-cal-select{display:none!important}
.kq-cal-trigger{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-height:44px;padding:9px 12px;border:1px solid #dce2ea;border-radius:10px;background:#fff;color:inherit;font:inherit;font-weight:500;text-align:left;cursor:pointer}
.kq-cal-trigger:focus-visible{outline:3px solid var(--kq-cal-ring,rgba(15,143,132,.3));outline-offset:1px}
.kq-cal-trigger:disabled{opacity:.6;cursor:not-allowed}
.kq-cal-trigger svg{flex:none;width:20px;height:20px;color:var(--kq-cal-accent,#0f8f84)}
.kq-cal-pop{position:absolute;z-index:1000;top:calc(100% + 6px);left:0;width:min(320px,calc(100vw - 32px));padding:12px;border:1px solid #dce2ea;border-radius:14px;background:#fff;color:#15243b;box-shadow:0 16px 40px rgba(21,36,59,.18)}
.kq-cal-pop[hidden]{display:none}
.kq-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.kq-cal-head strong{font-size:16px;font-weight:600}
.kq-cal-nav{width:40px;height:40px;border:0;border-radius:10px;background:#f1f5f9;color:inherit;font:inherit;font-size:20px;line-height:1;cursor:pointer}
.kq-cal-nav:disabled{opacity:.35;cursor:default}
.kq-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center}
.kq-cal-grid small{padding:4px 0;color:#708096;font-size:12px;font-weight:600}
.kq-cal-grid small:first-child{color:#dc2626}
.kq-cal-day{aspect-ratio:1;min-height:36px;border:0;border-radius:10px;background:transparent;color:inherit;font:inherit;font-size:15px;cursor:pointer}
.kq-cal-day:hover:not(:disabled){background:#eef6f5}
.kq-cal-day:disabled{color:#c3ccd6;cursor:default}
.kq-cal-day.is-today{box-shadow:inset 0 0 0 1.5px var(--kq-cal-accent,#0f8f84);font-weight:700}
.kq-cal-day[aria-pressed="true"]{background:var(--kq-cal-accent,#0f8f84);color:#fff;font-weight:700}
.kq-cal-day:focus-visible{outline:3px solid var(--kq-cal-ring,rgba(15,143,132,.35));outline-offset:1px}
.kq-cal-foot{display:flex;justify-content:flex-end;margin-top:8px}
.kq-cal-today{border:0;border-radius:9px;padding:8px 14px;background:#eef6f5;color:var(--kq-cal-accent,#0f8f84);font:inherit;font-weight:600;cursor:pointer}
.kq-cal-today:disabled{opacity:.4;cursor:default}
.kq-cal .kq-cal-trigger{font-size:15px;font-weight:500;color:#15243b}
.kq-cal .kq-cal-grid small{color:#708096}
.kq-cal .kq-cal-grid small:first-child{color:#dc2626}
.kq-cal .kq-cal-pop{font-size:15px;font-weight:400}
.kq-cal .kq-cal-pop button{margin:0;width:auto;min-width:0;max-width:none;box-shadow:none;text-transform:none;letter-spacing:normal}
.kq-cal .kq-cal-pop .kq-cal-nav{flex:none;width:40px;height:40px;padding:0}
.kq-cal .kq-cal-pop .kq-cal-day{width:100%;padding:0}
.kq-cal .kq-cal-head strong{flex:1;text-align:center;white-space:nowrap}
.kq-cal .kq-cal-pop .kq-cal-day.is-today{box-shadow:inset 0 0 0 1.5px var(--kq-cal-accent,#0f8f84)}`;
  const icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M8 2.5v4M16 2.5v4M3 9.5h18"/></svg>';

  const monthOf=iso=>iso.slice(0,7);
  const shiftMonth=(month,delta)=>{const date=new Date(`${month}-01T00:00:00Z`);date.setUTCMonth(date.getUTCMonth()+delta);return date.toISOString().slice(0,7)};
  const isDateSelect=select=>select.options.length>0&&Array.from(select.options).every(option=>ISO.test(option.value));
  const allowed=select=>new Set(Array.from(select.options,option=>option.value));

  function close(picker,focusTrigger=false){if(!picker||picker.pop.hidden)return;picker.pop.hidden=true;picker.trigger.setAttribute('aria-expanded','false');if(openPicker===picker)openPicker=null;if(focusTrigger)picker.trigger.focus()}

  function renderLabel(picker){const value=picker.select.value;picker.label.textContent=value?KQThaiDate.date(value,{weekday:'long'}):'เลือกวันที่';picker.trigger.disabled=picker.select.disabled}

  function render(picker,focusIso=null){
    const dates=allowed(picker.select);const sorted=[...dates].sort();const today=KQThaiDate.todayISO();const selected=picker.select.value;
    const month=picker.month;const first=new Date(`${month}-01T00:00:00Z`);const lead=first.getUTCDay();const days=new Date(Date.UTC(first.getUTCFullYear(),first.getUTCMonth()+1,0)).getUTCDate();
    const cells=WEEKDAYS.map(day=>`<small>${day}</small>`);for(let i=0;i<lead;i++)cells.push('<span></span>');
    for(let day=1;day<=days;day++){const iso=`${month}-${String(day).padStart(2,'0')}`;cells.push(`<button type="button" class="kq-cal-day${iso===today?' is-today':''}" data-iso="${iso}" aria-label="${KQThaiDate.date(iso,{weekday:'long'})}" aria-pressed="${iso===selected}" tabindex="-1"${dates.has(iso)?'':' disabled'}>${day}</button>`)}
    picker.pop.innerHTML=`<div class="kq-cal-head"><button type="button" class="kq-cal-nav" data-nav="-1" aria-label="เดือนก่อนหน้า"${monthOf(sorted[0])>=month?' disabled':''}>‹</button><strong aria-live="polite">${monthTitle.format(first)}</strong><button type="button" class="kq-cal-nav" data-nav="1" aria-label="เดือนถัดไป"${monthOf(sorted[sorted.length-1])<=month?' disabled':''}>›</button></div><div class="kq-cal-grid" role="group" aria-label="${monthTitle.format(first)}">${cells.join('')}</div><div class="kq-cal-foot"><button type="button" class="kq-cal-today"${dates.has(today)?'':' disabled'}>วันนี้</button></div>`;
    const target=picker.pop.querySelector(`[data-iso="${focusIso}"]:not(:disabled)`)||picker.pop.querySelector('.kq-cal-day[aria-pressed="true"]:not(:disabled)')||picker.pop.querySelector('.kq-cal-day:not(:disabled)');
    if(target){target.tabIndex=0;if(focusIso!==null)target.focus()}
  }

  function choose(picker,iso){if(!allowed(picker.select).has(iso))return;const changed=picker.select.value!==iso;picker.select.value=iso;renderLabel(picker);close(picker,true);if(changed)picker.select.dispatchEvent(new Event('change',{bubbles:true}))}

  function open(picker){if(picker.select.disabled)return;if(openPicker&&openPicker!==picker)close(openPicker);const value=picker.select.value||KQThaiDate.todayISO();picker.month=monthOf(value);picker.pop.hidden=false;picker.trigger.setAttribute('aria-expanded','true');openPicker=picker;render(picker,value)}

  function enhance(select){
    if(pickers.has(select))return;
    const wrap=document.createElement('div');wrap.className='kq-cal';
    const trigger=document.createElement('button');trigger.type='button';trigger.className='kq-cal-trigger';trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-expanded','false');
    const label=document.createElement('span');trigger.append(label);trigger.insertAdjacentHTML('beforeend',icon);
    const pop=document.createElement('div');pop.className='kq-cal-pop';pop.hidden=true;pop.setAttribute('role','dialog');pop.setAttribute('aria-label','เลือกวันที่ (พ.ศ.)');
    select.classList.add('kq-cal-select');select.after(wrap);wrap.append(trigger,pop);
    const picker={select,wrap,trigger,label,pop,month:monthOf(select.value||KQThaiDate.todayISO())};pickers.set(select,picker);
    const host=select.closest('label');if(host)host.addEventListener('click',event=>{if(event.target===host){event.preventDefault();trigger.focus()}});
    trigger.addEventListener('click',()=>pop.hidden?open(picker):close(picker));
    pop.addEventListener('click',event=>{const nav=event.target.closest('[data-nav]');if(nav){picker.month=shiftMonth(picker.month,Number(nav.dataset.nav));render(picker);return}const day=event.target.closest('[data-iso]');if(day&&!day.disabled){choose(picker,day.dataset.iso);return}if(event.target.closest('.kq-cal-today'))choose(picker,KQThaiDate.todayISO())});
    pop.addEventListener('keydown',event=>{
      if(event.key==='Escape'){event.preventDefault();close(picker,true);return}
      const day=event.target.closest('[data-iso]');if(!day)return;
      const steps={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7}[event.key];if(!steps)return;
      event.preventDefault();const dates=allowed(select);const sorted=[...dates].sort();let iso=day.dataset.iso;
      for(let i=0;i<400;i++){iso=KQThaiDate.addDays(iso,steps);if(dates.has(iso))break;if(iso<sorted[0]||iso>sorted[sorted.length-1])return}
      if(!dates.has(iso))return;picker.month=monthOf(iso);render(picker,iso);
    });
    select.addEventListener('change',()=>renderLabel(picker));
    new MutationObserver(()=>renderLabel(picker)).observe(select,{childList:true,attributes:true,attributeFilter:['disabled']});
    queueMicrotask(()=>renderLabel(picker));
  }

  function scan(root=document){root.querySelectorAll('select:not(.kq-cal-select)').forEach(select=>{if(isDateSelect(select))enhance(select)})}

  function start(){
    const style=document.createElement('style');style.textContent=css;document.head.append(style);
    scan();
    let pending=false;new MutationObserver(()=>{if(pending)return;pending=true;requestAnimationFrame(()=>{pending=false;scan()})}).observe(document.body,{childList:true,subtree:true});
    document.addEventListener('pointerdown',event=>{if(!openPicker)return;if(!openPicker.wrap.isConnected){openPicker=null;return}if(!openPicker.wrap.contains(event.target))close(openPicker)});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
