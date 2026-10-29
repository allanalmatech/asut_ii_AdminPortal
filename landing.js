"use strict";
const navigation=document.getElementById('site-nav'),toggle=document.getElementById('nav-toggle');
function closeNavigation(){navigation.classList.remove('open');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Open navigation');}
toggle.addEventListener('click',()=>{const open=navigation.classList.toggle('open');toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'Close navigation':'Open navigation');});
navigation.addEventListener('click',event=>{if(event.target.closest('a'))closeNavigation();});
document.addEventListener('keydown',event=>{if(event.key==='Escape'){closeNavigation();toggle.focus();}});
const search=document.getElementById('device-search'),rows=[...document.querySelectorAll('#pricing-body tr')],filters=[...document.querySelectorAll('[data-family].filter')];let family='all';
function filterDevices(){
 const query=search.value.trim().toLowerCase();let visible=0;
 rows.forEach(row=>{row.hidden=!((family==='all'||row.dataset.family===family)&&row.dataset.search.includes(query));if(!row.hidden)visible++;});
 document.getElementById('no-devices').hidden=visible!==0;
}
filters.forEach(button=>button.addEventListener('click',()=>{family=button.dataset.family;filters.forEach(filter=>{const active=filter===button;filter.classList.toggle('active',active);filter.setAttribute('aria-pressed',String(active));});filterDevices();}));
search.addEventListener('input',filterDevices);
