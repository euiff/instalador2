/* Igreja Master 3.7.32. Shared, safe input masks.
 * Monetary amount: digit entry in CENTAVOS (1→0,01; 100→1,00).
 * Paste of "100" means 100 reais; paste of "1,00" keeps 1,00.
 * Never reformat type=number, hidden, date, search, passwords, bank IDs.
 */
(function(){
'use strict';
const fmt = new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const moneyNames = new Set(['amount','opening_balance','statement_balance','gross_amount','fees_amount','bank_amount','planned_amount','paid_amount','total_amount','unit_price','value','price','from_amount','to_amount','budget_amount','monthly_amount']);
const numeric = v=>String(v||'').replace(/\D/g,'');
const money = digits => fmt.format(Math.min(Number(digits||'0'),999999999999)/100);
const moneyFromPaste = str=>{
 const clean=String(str).replace(/[^0-9,.-]/g,'').trim();
 if(!clean)return '';
 let result;
 if(clean.includes(',')){
   result=clean.replace(/\./g,'').replace(',','.');
 }else if(/^\d{1,3}(\.\d{3})+(\.\d{2})?$/.test(clean)){
   result=clean.replace(/\./g,'');
 }else result=clean;
 const n=Number(result);
 return Number.isFinite(n)?fmt.format(n):'';
};
const isMoney = el=>{
 if(!el.matches('input')||!['text','tel',''].includes(el.type))return false;
 if(el.dataset.noMoneyMask!==undefined)return false;
 if(el.dataset.moneyMask!==undefined)return true;
 const name=(el.name||'').toLowerCase();
 if(!moneyNames.has(name))return false;
 if(name==='value'&& !el.closest('form[action*="finance"],[data-registry-form],.finance-gov-form,.contribute-form,.contribution-form'))return false;
 return true;
};
const formatCpf = digits => digits.slice(0,11).replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/,'.$1-$2');
const formatCnpj = digits => digits.slice(0,14).replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\/\d{4})(\d)/,'$1-$2');
const formatPhone = v => {
 const s=numeric(v).slice(0,11);
 if(s.length<=2)return s;
 if(s.length<=6)return '('+s.slice(0,2)+') '+s.slice(2);
 if(s.length<=10)return '('+s.slice(0,2)+') '+s.slice(2,6)+'-'+s.slice(6);
 return '('+s.slice(0,2)+') '+s.slice(2,7)+'-'+s.slice(7);
};
const getKind = el => {
 if(!el.matches('input')||!['text','tel',''].includes(el.type))return null;
 const key=(el.name+' '+el.id).toLowerCase();
 if(/password|pin|token|bank|pix|reference|access_key|document_number|bank_document|invoice/.test(key))return null;
 if(/cpf.?cnpj|cnpj.?cpf|tax_document|payer_document|recipient_document/.test(key))return 'document';
 if(/(^|[_-])cnpj($|[_-])/.test(key))return 'cnpj';
 if(/(^|[_-])cpf($|[_-])/.test(key))return 'cpf';
 if(/phone|telefone|celular|whatsapp|mobile/.test(key)||el.type==='tel')return 'phone';
 return null;
};
function install(el){
 if(el.dataset.imMaskBound==='1')return;
 if(isMoney(el)){
   el.dataset.imMaskBound='1';el.dataset.imMoney='1';
   el.classList.add('im-currency-masked');
   el.setAttribute('inputmode','decimal');
   el.addEventListener('input',()=>{if(el.value.trim()==='')return;el.value=money(numeric(el.value));});
   el.addEventListener('paste',e=>{
      const pasted=e.clipboardData?.getData('text');if(!pasted)return;
      const converted=moneyFromPaste(pasted);
      if(converted){e.preventDefault();el.value=converted;el.dispatchEvent(new Event('change',{bubbles:true}));}
   });
   el.addEventListener('blur',()=>{if(el.value.trim())el.value=moneyFromPaste(el.value)||el.value;});
   return;
 }
 const kind=getKind(el);if(!kind)return;
 el.dataset.imMaskBound='1';
 el.setAttribute('inputmode','numeric');
 if(kind==='phone')el.setAttribute('autocomplete',el.getAttribute('autocomplete')||'tel');
 const handler=()=>{
   const digits=numeric(el.value);
   el.value=kind==='phone'?formatPhone(digits):kind==='cnpj'?formatCnpj(digits):kind==='cpf'?formatCpf(digits):digits.length>11?formatCnpj(digits):formatCpf(digits);
 };
 el.addEventListener('input',handler);
 if(el.value)handler();
}
function scan(root){root.querySelectorAll('input').forEach(install)}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>scan(document));else scan(document);
const observer=new MutationObserver(mutations=>{
 for(const m of mutations)for(const node of m.addedNodes){
  if(node.nodeType!==1)continue;
  if(node.matches?.('input'))install(node);
  node.querySelectorAll?.('input').forEach(install);
 }
});
observer.observe(document.documentElement,{childList:true,subtree:true});
})();
