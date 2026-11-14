/* Igreja Master 3.7.61 — financial entry chooser with always-visible attachments.
 * Never hide either file upload input behind a wizard.
 */
(()=>{
'use strict';
const init=()=>{
 const chooser=document.querySelector('[data-finance-launch-choice]');
 const formSection=document.querySelector('section[data-registry-form="finance-entry"]');
 if(!chooser||!formSection)return;
 const button=chooser.querySelector('[data-finance-launch-simple]');
 const back=formSection.querySelector('[data-finance-back-choice]');
 if(!button||!back)return;
 const openSimple=({scroll=true}={})=>{
   formSection.classList.add('finance-simple-open');
   formSection.classList.add('finance-wizard-ready');
   formSection.hidden=false;
   chooser.setAttribute('aria-label','Escolha outra modalidade ou continue no lançamento simples');
   if(scroll)formSection.scrollIntoView({behavior:'smooth',block:'start'});
 };
 const closeSimple=()=>{
   formSection.classList.remove('finance-simple-open');
   chooser.scrollIntoView({behavior:'smooth',block:'start'});
 };
 // The simple form is always visible; choosing it scrolls to its inputs.
 // The three cards are shortcuts, not a gate that hides attachments.
 formSection.classList.add('finance-wizard-ready');
 button.addEventListener('click',()=>openSimple());
 back.addEventListener('click',closeSimple);
 const hash=location.hash.toLowerCase();
 if(['#finance-simple-form','#novo-lancamento','#novo_lancamento','#finance-entry'].includes(hash)
   ||new URLSearchParams(location.search).get('novo')==='simples'){
   openSimple({scroll:false});
 }
 // Help icons are keyboard-accessible <details>. Only one help panel at once.
 const helps=[...document.querySelectorAll('.im-finance-help')];
 helps.forEach(item=>{
   item.addEventListener('toggle',()=>{
     if(item.open)helps.forEach(other=>{if(other!==item)other.open=false;});
   });
 });
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();