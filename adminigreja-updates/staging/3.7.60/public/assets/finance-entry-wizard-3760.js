/* Igreja Master 3.7.60 — minimal financial entry chooser based on V2 suggestions.
 * Progressive enhancement: without JavaScript the full simple form stays visible.
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
 // JS enhancement: initial screen contains exactly three clear choices.
 // The form remains usable for older browsers because CSS gate requires this class.
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