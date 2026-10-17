'use strict';
// Thai queue call announcements: a two-tone chime followed by speech from the
// browser's Thai voice. Settings are a per-device convenience in localStorage.
window.KQQueueVoice=(()=>{
  const STORAGE_KEY='kq-queue-voice';
  const LETTERS={A:'เอ',B:'บี',C:'ซี',D:'ดี',E:'อี',F:'เอฟ',G:'จี',H:'เอช',I:'ไอ',J:'เจ',K:'เค',L:'แอล',M:'เอ็ม',N:'เอ็น',O:'โอ',P:'พี',Q:'คิว',R:'อาร์',S:'เอส',T:'ที',U:'ยู',V:'วี',W:'ดับเบิลยู',X:'เอ็กซ์',Y:'วาย',Z:'แซด'};
  const DIGITS=['ศูนย์','หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า'];
  const PREFERRED=[/premwadee/i,/niwat/i,/achara/i,/google/i,/pattara/i];
  let audioContext=null;

  function settings(){try{return {enabled:true,destination:'ห้องบัตร',repeat:2,...JSON.parse(localStorage.getItem(STORAGE_KEY)||'{}')}}catch{return {enabled:true,destination:'ห้องบัตร',repeat:2}}}
  function save(next){try{localStorage.setItem(STORAGE_KEY,JSON.stringify({...settings(),...next}))}catch{}}
  const supported=()=>'speechSynthesis' in window&&'SpeechSynthesisUtterance' in window;

  function thaiVoice(){
    if(!supported())return null;
    const voices=speechSynthesis.getVoices().filter(voice=>/^th(-|_|$)/i.test(voice.lang));
    for(const pattern of PREFERRED){const match=voices.find(voice=>pattern.test(voice.name));if(match)return match}
    return voices[0]||null;
  }
  function waitForVoices(){return new Promise(resolve=>{if(!supported()||speechSynthesis.getVoices().length)return resolve();const done=()=>{speechSynthesis.removeEventListener('voiceschanged',done);resolve()};speechSynthesis.addEventListener('voiceschanged',done);setTimeout(done,1500)})}

  // "NCD-001" -> "เอ็น ซี ดี, ศูนย์ ศูนย์ หนึ่ง". Thai prefixes are spoken as written.
  function spokenQueueNo(queueNo){
    return String(queueNo||'').toUpperCase().split(/[-\s]+/).filter(Boolean).map(part=>{
      const words=[];let thai='';
      for(const char of part){
        if(LETTERS[char]||/\d/.test(char)){if(thai){words.push(thai);thai=''}words.push(LETTERS[char]||DIGITS[Number(char)])}
        else thai+=char;
      }
      if(thai)words.push(thai);
      return words.join(' ');
    }).join(', ');
  }
  function phrase(queueNo,destination){return `ขอเชิญหมายเลข ${spokenQueueNo(queueNo)}, ที่${destination||settings().destination}`}

  function chime(){
    return new Promise(resolve=>{
      try{
        const AudioCtx=window.AudioContext||window.webkitAudioContext;if(!AudioCtx)return resolve();
        audioContext=audioContext||new AudioCtx();if(audioContext.state==='suspended')audioContext.resume();
        const start=audioContext.currentTime+.05;
        [[880,0],[660,.42]].forEach(([frequency,offset])=>{const osc=audioContext.createOscillator(),gain=audioContext.createGain();osc.type='sine';osc.frequency.value=frequency;gain.gain.setValueAtTime(.0001,start+offset);gain.gain.exponentialRampToValueAtTime(.35,start+offset+.03);gain.gain.exponentialRampToValueAtTime(.0001,start+offset+.6);osc.connect(gain).connect(audioContext.destination);osc.start(start+offset);osc.stop(start+offset+.62)});
        setTimeout(resolve,1150);
      }catch{resolve()}
    });
  }
  function speak(text,voice){return new Promise(resolve=>{const utterance=new SpeechSynthesisUtterance(text);utterance.lang='th-TH';if(voice)utterance.voice=voice;utterance.rate=.9;utterance.onend=resolve;utterance.onerror=resolve;speechSynthesis.speak(utterance)})}

  async function announce(queueNo,options={}){
    const current=settings();
    if(!options.force&&!current.enabled)return {spoken:false,reason:'disabled'};
    if(!supported())return {spoken:false,reason:'unsupported'};
    await waitForVoices();
    const voice=thaiVoice();
    if(!voice)return {spoken:false,reason:'no-thai-voice'};
    speechSynthesis.cancel();
    await chime();
    const text=phrase(queueNo,options.destination);
    for(let i=0;i<Math.max(1,Number(current.repeat)||1);i++)await speak(text,voice);
    return {spoken:true,voice:voice.name};
  }
  function problem(result){return result.reason==='no-thai-voice'?'เครื่องนี้ไม่มีเสียงภาษาไทย แนะนำเปิดด้วย Microsoft Edge หรือติดตั้งภาษาไทยใน Windows':result.reason==='unsupported'?'เบราว์เซอร์นี้ไม่รองรับเสียงเรียกคิว':''}

  return Object.freeze({announce,phrase,spokenQueueNo,problem,isEnabled:()=>settings().enabled,setEnabled:enabled=>save({enabled:!!enabled})});
})();
