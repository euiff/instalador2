/* Igreja Master 3.7.26 — telas de entrada e saída.
 * Formata reais; categoriza; sugere histórico; valida campos condicionais.
 * Não transmite dados financeiros fora do formulário e não grava sem confirmação.
 */
(() => {
 'use strict';
 const brl=new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
 const parseAmount = raw => {
   let str=String(raw??'').trim().replace(/\s|R\$/g,'');
   if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(str)) str=str.replaceAll('.','').replace(',','.');
   else if (/^\d{1,10}(,\d{1,2})?$/.test(str)) str=str.replace(',','.');
   if (!/^\d{1,10}(\.\d{1,2})?$/.test(str)) return null;
   const value=Number(str);
   return Number.isFinite(value)&&value>0&&value<=999999999.99?value:null;
 };
 const dateString=iso=>{
   const m=String(iso??'').match(/^(\d{4})-(\d{2})-(\d{2})$/);
   return m ? m[3]+'/'+m[2]+'/'+m[1] : '';
 };
 const init=form=>{
   if(!form.querySelector('input[name="action"][value="entry"]'))return;
   const amount=form.querySelector('input[name="amount"]');
   const category=form.querySelector('input[name="category"]');
   const direction=form.querySelector('select[name="direction"]');
   const description=form.querySelector('input[name="description"]');
   const date=form.querySelector('input[name="transaction_date"]');
   const member=form.querySelector('select[name="member_id"]');
   const memberBox=form.querySelector('[data-finance-member-field]');
   const tithe=form.querySelector('input[name="tithe_identified"]');
   const titheBox=form.querySelector('[data-finance-tithe-choice]');
   const payment=form.querySelector('select[name="payment_method"]');
   const reference=form.querySelector('input[name="document_number"]');
   const referenceHint=form.querySelector('[data-finance-bank-reference-hint]');
   const submit=form.querySelector('button[type="submit"]');
   if(!amount||!category||!direction)return;

   amount.addEventListener('input',()=>{amount.setCustomValidity('');amount.removeAttribute('aria-invalid');});
   amount.addEventListener('blur',()=>{const value=parseAmount(amount.value);if(value!==null)amount.value=brl.format(value);});
   // Uma única caixa de categoria. A lista de sugestões do próprio input
   // substitui o segundo seletor que era criado dinamicamente e duplicava a UI.
   category.setAttribute('autocomplete','off');
   category.setAttribute('aria-label','Categoria do lançamento; digite ou escolha na lista');

   // Botões distintos de Entrada/Saída tornam o fluxo mais evidente no celular.
   const directionHost=direction.closest('.field');
   if(directionHost){
     const tabs=document.createElement('div');
     tabs.className='finance-direction-tabs';
     tabs.setAttribute('role','group');
     tabs.setAttribute('aria-label','Tipo de lançamento');
     const buttons={};
     for(const [key,text,icon] of [['income','Entrada','＋'],['expense','Saída','−']]){
       const b=document.createElement('button');
       b.type='button';b.className='finance-direction-tab';
       b.dataset.direction=key;b.textContent=icon+' '+text;
       b.addEventListener('click',()=>{direction.value=key;direction.dispatchEvent(new Event('change',{bubbles:true}));});
       tabs.append(b);buttons[key]=b;
     }
     directionHost.prepend(tabs);
     direction.hidden=true;
     directionHost.querySelector('label')?.classList.add('finance-direction-label');
     direction.dataset.syncTabs='1';
     const updateTabs=()=>{
       Object.entries(buttons).forEach(([key,b])=>{
         const on=key===direction.value;
         b.classList.toggle('active',on);b.setAttribute('aria-pressed',String(on));
       });
     };
     direction.addEventListener('change',updateTabs);updateTabs();
   }
   let lastAuto='';
   let userEdited=false;
   description?.addEventListener('input',()=>{
     if(description.value.trim()!==lastAuto)userEdited=true;
     if(!description.value.trim())userEdited=false;
   });
   const isTithe=()=>/d[ií]zimo/iu.test(category.value);
   const needsReference=()=>direction.value==='income'&&/pix|transfer[êe]ncia/iu.test(payment?.value||'');
   const update=()=>{
     const expense=direction.value==='expense';
     category.placeholder=expense?'Ex.: Material de limpeza':'Ex.: Dízimos, Ofertas, Missões';
     if(submit && form.dataset.financeSubmitting!=='1') submit.textContent=expense?'Registrar saída':'Registrar entrada';
     if(memberBox)memberBox.hidden=expense;
     if(titheBox)titheBox.hidden=expense||!isTithe();
     if(tithe&&(expense||!isTithe()))tithe.checked=false;
     if(member)member.required=!!(tithe?.checked&&!expense&&isTithe());
     if(reference){reference.required=needsReference();reference.placeholder=needsReference()?'ID Pix ou autenticação obrigatória':'Número do documento (opcional)';}
     if(referenceHint)referenceHint.hidden=!needsReference();
     if(description){
       const type=category.value.trim();
       if(!expense&&type&&(!userEdited||description.value.trim()==='')){
         const label=isTithe()&&tithe?.checked?'Dízimo identificado':type.replace(/s$/,'');
         const method=payment?.value.trim()||'forma não informada';
         const when=dateString(date?.value);
         const generated=label+' recebido via '+method+(when?' em '+when:'');
         description.value=generated;lastAuto=generated;userEdited=false;
       } else if(expense && description.value===lastAuto){
         description.value='';lastAuto='';userEdited=false;
       }
       if(!description.value)description.placeholder=expense?'Descreva o pagamento':'Descrição da contribuição';
     }
   };
   [direction,category,payment,date,member,tithe].filter(Boolean).forEach(el=>el.addEventListener('change',update));
   category.addEventListener('input',update);
   update();

   form.addEventListener('submit',event=>{
     if(form.dataset.financeSubmitting==='1'){event.preventDefault();return;}
     const n=parseAmount(amount.value);
     if(n===null){event.preventDefault();amount.setCustomValidity('Informe um valor positivo válido, em reais.');amount.setAttribute('aria-invalid','true');amount.reportValidity();return;}
     if(needsReference() && !reference?.value.trim()){event.preventDefault();reference?.setCustomValidity('Informe a referência Pix ou transferência.');reference?.reportValidity();return;}
     if(tithe?.checked && isTithe() && !member?.value){event.preventDefault();member?.setCustomValidity('Selecione o membro para dízimo identificado.');member?.reportValidity();return;}
     if(reference)reference.setCustomValidity('');
     if(member)member.setCustomValidity('');
     amount.value=brl.format(n);
     form.dataset.financeSubmitting='1';
     window.requestAnimationFrame(()=>{
       if(event.defaultPrevented){delete form.dataset.financeSubmitting;return;}
       if(submit){submit.disabled=true;submit.textContent='Registrando…';}
     });
   });
 };
 document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('form').forEach(init);});
})();
