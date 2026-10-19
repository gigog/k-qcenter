'use strict';
// Thai queue announcements. Settings stay on each monitor because available
// Web Speech voices depend on that machine's operating system and browser.
window.KQQueueVoice=(()=>{
  const STORAGE_KEY='kq-queue-voice-v2';
  const LEGACY_KEY='kq-queue-voice';
  const LETTERS={A:'เอ',B:'บี',C:'ซี',D:'ดี',E:'อี',F:'เอฟ',G:'จี',H:'เอช',I:'ไอ',J:'เจ',K:'เค',L:'แอล',M:'เอ็ม',N:'เอ็น',O:'โอ',P:'พี',Q:'คิว',R:'อาร์',S:'เอส',T:'ที',U:'ยู',V:'วี',W:'ดับเบิลยู',X:'เอ็กซ์',Y:'วาย',Z:'แซด'};
  const DIGITS=['ศูนย์','หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า'];
  const GENDER_HINTS={female:[/premwadee/i,/achara/i,/female/i,/หญิง/i,/google/i],male:[/niwat/i,/pattara/i,/male/i,/ชาย/i]};
  const DEFAULTS={enabled:true,destination:'ห้องบัตร',repeat:2,gender:'female',rate:.9,voiceName:'',template:'ขอเชิญหมายเลข {queue}, ที่{destination}',dictionary:[]};
  let audioContext=null;
  let serverCsrf='';

  function normalize(raw={}){return {...DEFAULTS,...raw,repeat:Math.max(1,Math.min(3,Number(raw.repeat)||DEFAULTS.repeat)),rate:Math.max(.5,Math.min(1.5,Number(raw.rate)||DEFAULTS.rate)),dictionary:Array.isArray(raw.dictionary)?raw.dictionary:[]}}
  function settings(){try{const current=localStorage.getItem(STORAGE_KEY);if(current)return normalize(JSON.parse(current));return normalize(JSON.parse(localStorage.getItem(LEGACY_KEY)||'{}'))}catch{return normalize()}}
  function save(next){const value=normalize({...settings(),...next});try{localStorage.setItem(STORAGE_KEY,JSON.stringify(value))}catch{}return value}
  const supported=()=>'speechSynthesis' in window&&'SpeechSynthesisUtterance' in window;
  const thaiVoices=()=>supported()?speechSynthesis.getVoices().filter(voice=>/^th(-|_|$)/i.test(voice.lang)):[];
  function selectedVoice(current=settings()){const voices=thaiVoices();if(current.voiceName){const exact=voices.find(voice=>voice.name===current.voiceName);if(exact)return exact}for(const pattern of GENDER_HINTS[current.gender]||[]){const match=voices.find(voice=>pattern.test(voice.name));if(match)return match}return voices[0]||null}
  function waitForVoices(){return new Promise(resolve=>{if(!supported()||speechSynthesis.getVoices().length)return resolve();const done=()=>{speechSynthesis.removeEventListener('voiceschanged',done);resolve()};speechSynthesis.addEventListener('voiceschanged',done);setTimeout(done,1500)})}
  async function listVoices(){await waitForVoices();return thaiVoices().map(voice=>({name:voice.name,lang:voice.lang,default:voice.default}))}

  function spokenQueueNo(queueNo){return String(queueNo||'').toUpperCase().split(/[-\s]+/).filter(Boolean).map(part=>{const words=[];let thai='';for(const char of part){if(LETTERS[char]||/\d/.test(char)){if(thai){words.push(thai);thai=''}words.push(LETTERS[char]||DIGITS[Number(char)])}else thai+=char}if(thai)words.push(thai);return words.join(' ')}).join(', ')}
  function applyDictionary(text,dictionary){return (dictionary||[]).reduce((result,item)=>{const from=String(item.from||'').trim(),to=String(item.to||'').trim();return from&&to?result.split(from).join(to):result},String(text||''))}
  function phrase(queueNo,destination,override={}){const current={...settings(),...override};const text=String(current.template||DEFAULTS.template).replaceAll('{queue}',spokenQueueNo(queueNo)).replaceAll('{destination}',destination||current.destination);return applyDictionary(text,current.dictionary)}
  function chime(){return new Promise(resolve=>{try{const AudioCtx=window.AudioContext||window.webkitAudioContext;if(!AudioCtx)return resolve();audioContext=audioContext||new AudioCtx();if(audioContext.state==='suspended')audioContext.resume();const start=audioContext.currentTime+.05;[[880,0],[660,.42]].forEach(([frequency,offset])=>{const osc=audioContext.createOscillator(),gain=audioContext.createGain();osc.type='sine';osc.frequency.value=frequency;gain.gain.setValueAtTime(.0001,start+offset);gain.gain.exponentialRampToValueAtTime(.35,start+offset+.03);gain.gain.exponentialRampToValueAtTime(.0001,start+offset+.6);osc.connect(gain).connect(audioContext.destination);osc.start(start+offset);osc.stop(start+offset+.62)});setTimeout(resolve,1150)}catch{resolve()}})}
  function speak(text,voice,rate){return new Promise(resolve=>{const utterance=new SpeechSynthesisUtterance(text);utterance.lang='th-TH';if(voice)utterance.voice=voice;utterance.rate=rate;utterance.onend=resolve;utterance.onerror=resolve;speechSynthesis.speak(utterance)})}
  async function fetchServerAudio(text,current){if(!serverCsrf)return null;try{const response=await fetch('../api/index.php?action=tts',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':serverCsrf},body:JSON.stringify({text,rate:current.rate})});return response.ok?URL.createObjectURL(await response.blob()):null}catch{return null}}
  async function playServerAudio(url,text,current){if(!url)return null;try{for(let i=0;i<current.repeat;i++)await new Promise((resolve,reject)=>{const audio=new Audio(url);audio.onended=resolve;audio.onerror=reject;audio.play().catch(reject)});return {spoken:true,voice:'Piper tsync2 (Offline)',text,genderMatched:true,engine:'offline'}}catch{return null}finally{URL.revokeObjectURL(url)}}
  async function announce(queueNo,options={}){const current={...settings(),...options};if(!options.force&&!current.enabled)return {spoken:false,reason:'disabled'};const text=phrase(queueNo,options.destination,current);const audioPromise=fetchServerAudio(text,current);await chime();const offline=await playServerAudio(await audioPromise,text,current);if(offline)return offline;if(!supported())return {spoken:false,reason:'unsupported'};await waitForVoices();const voice=selectedVoice(current);if(!voice)return {spoken:false,reason:'no-thai-voice'};const genderMatched=Boolean(current.voiceName||(GENDER_HINTS[current.gender]||[]).some(pattern=>pattern.test(voice.name)));speechSynthesis.cancel();for(let i=0;i<current.repeat;i++)await speak(text,voice,current.rate);return {spoken:true,voice:voice.name,text,genderMatched,engine:'browser'}}
  function problem(result){return result.reason==='no-thai-voice'?'เครื่องนี้ไม่มีเสียงภาษาไทย แนะนำเปิดด้วย Microsoft Edge หรือติดตั้งภาษาไทยใน Windows':result.reason==='unsupported'?'เบราว์เซอร์นี้ไม่รองรับเสียงเรียกคิว':''}
  function dictionaryFromText(text){return String(text||'').split(/\r?\n/).map(line=>{const index=line.indexOf('=');return index<1?null:{from:line.slice(0,index).trim(),to:line.slice(index+1).trim()}}).filter(item=>item&&item.from&&item.to)}
  function dictionaryToText(dictionary){return (dictionary||[]).map(item=>`${item.from}=${item.to}`).join('\n')}
  return Object.freeze({announce,phrase,spokenQueueNo,problem,settings,save,listVoices,dictionaryFromText,dictionaryToText,setServerAuth:token=>{serverCsrf=String(token||'')},isEnabled:()=>settings().enabled,setEnabled:enabled=>save({enabled:!!enabled})});
})();
