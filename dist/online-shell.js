'use strict';
if(window.visualViewport){const updateViewport=()=>{const keyboardOpen=window.visualViewport.height<window.innerHeight*.76;document.body.classList.toggle('keyboard-open',keyboardOpen)};window.visualViewport.addEventListener('resize',updateViewport);window.visualViewport.addEventListener('scroll',updateViewport);updateViewport()}
document.addEventListener('focusin',event=>{if(event.target.matches('input,select,textarea'))setTimeout(()=>event.target.scrollIntoView({behavior:'smooth',block:'center'}),180)});
