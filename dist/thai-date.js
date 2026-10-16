'use strict';
window.KQThaiDate=Object.freeze({
  date(value,options={}){if(!value)return '—';const source=value instanceof Date?value:new Date(String(value).includes('T')?value:`${value}T12:00:00+07:00`);return new Intl.DateTimeFormat('th-TH-u-ca-buddhist',{day:'numeric',month:'long',year:'numeric',timeZone:'Asia/Bangkok',...options}).format(source)},
  dateTime(value){if(!value)return '—';return new Intl.DateTimeFormat('th-TH-u-ca-buddhist',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',hourCycle:'h23',timeZone:'Asia/Bangkok'}).format(new Date(value))+' น.'},
  todayISO(){return new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date())},
  addDays(iso,days){const date=new Date(`${iso}T00:00:00Z`);date.setUTCDate(date.getUTCDate()+days);return date.toISOString().slice(0,10)},
  options(selected='',pastDays=0,futureDays=730){const today=this.todayISO();const values=[];for(let offset=-pastDays;offset<=futureDays;offset++){const iso=this.addDays(today,offset);values.push(`<option value="${iso}" ${iso===selected?'selected':''}>${this.date(iso,{weekday:'short'})}</option>`)}if(selected&&!values.some(option=>option.includes(`value="${selected}"`)))values.unshift(`<option value="${selected}" selected>${this.date(selected,{weekday:'short'})}</option>`);return values.join('')}
});
