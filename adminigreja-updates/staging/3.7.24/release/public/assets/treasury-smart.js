/* Igreja Master: treasury mobile entry controls */
(()=>{
'use strict';
const clean=s=>String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
const tithe=s=>/^dizimos?$/.test(clean(s).trim());
const offering=s=>/^ofertas?$/.test(clean(s).trim());
const cents=s=>{
 const digits=String(s||'').replace(/\D/g,'').slice(0,12);
 return digits?new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(digits)/100):'';
};
const field=(form,name)=>form.elements.namedItem(name);
function formSetup(form){
 const direction=field(form,'direction'),category=field(form,'category');
 const search=form.querySelector('[data-category-search]');
 const description=field(form,'description'),member=field(form,'member_id');
 const options=category?[...category.options].filter(o=>o.value).map(o=>({name:o.textContent,value:o.value,direction:o.dataset.direction||'both'})):[];
 function filter(){
   if(!category)return;
   const selected=category.value;
   const isExpense=direction?.value==='expense';
   const value=clean(search?.value||'');
   category.replaceChildren(new Option('Selecione uma categoria',''));
   for(const opt of options){
     if(opt.direction!=='both'&&opt.direction!==(isExpense?'expense':'income'))continue;
     if(value&&!clean(opt.name).includes(value))continue;
     category.add(new Option(opt.name,opt.value));
   }
   category.value=[...category.options].some(o=>o.value===selected)?selected:'';
 }
 function update(){
   const isExpense=direction?.value==='expense';
   for(const el of form.querySelectorAll('[data-income-only]'))el.hidden=isExpense;
   for(const el of form.querySelectorAll('[data-expense-only]'))el.hidden=!isExpense;
   const auto=!isExpense&&(tithe(category?.value)||offering(category?.value));
   if(description){description.required=!auto;description.placeholder=auto?'Descrição automática: '+(tithe(category.value)?'Dízimo':'Oferta'):'Informe a finalidade';}
   if(member){member.required=!isExpense&&tithe(category?.value);if(isExpense)member.value='';}
   const label=form.querySelector('[data-member-label]');
   if(label)label.textContent=member?.required?'Membro contribuinte *':'Membro contribuinte (opcional)';
   const hint=form.querySelector('[data-dizimo-note]');
   if(hint)hint.hidden=!member?.required;
   form.querySelector('[data-receipt-method]')?.dispatchEvent(new Event('change',{bubbles:true}));
 }
 direction?.addEventListener('change',()=>{if(search)search.value='';filter();update();});
 search?.addEventListener('input',()=>{filter();update();});
 category?.addEventListener('change',update);
 filter();update();
 const modal=document.querySelector('[data-treasury-contact-dialog]');
 form.querySelector('[data-add-contact]')?.addEventListener('click',()=>{
   if(!modal)return;
   modal._owner=form;
   modal.querySelector('[name=name]').value=field(form,'counterparty')?.value||'';
   modal.querySelector('[name=email]').value='';
   modal.querySelector('[name=phone]').value='';
   modal.querySelector('[data-contact-response]').textContent='';
   modal.showModal();
 });
 form.querySelectorAll('[data-money-br]').forEach(input=>{
   input.inputMode='numeric';input.autocomplete='off';
   input.addEventListener('input',()=>{
     const current=cents(input.value);
     if(input.value!==current){input.value=current;input.setSelectionRange?.(current.length,current.length);}
   });
 });
}
function bindContact(){
 const modal=document.querySelector('[data-treasury-contact-dialog]');
 const form=modal?.querySelector('form');
 if(!form)return;
 modal.querySelector('[data-contact-close]')?.addEventListener('click',()=>modal.close());
 form.addEventListener('submit',async e=>{
   e.preventDefault();
   const source=modal._owner;if(!source)return;
   const data=new FormData(form);
   const csrf=source.querySelector('input[name*=csrf]');
   if(csrf)data.append(csrf.name,csrf.value);
   const dept=field(source,'department_id');if(dept)data.append('department_id',dept.value);
   const out=modal.querySelector('[data-contact-response]'),button=form.querySelector('button[type=submit]');
   button.disabled=true;out.textContent='Salvando...';
   try{
     const res=await fetch('/finance-contact-create.php',{method:'POST',body:data,credentials:'same-origin'});
     const json=await res.json();
     if(!res.ok)throw new Error(json.error||'Erro ao cadastrar contato.');
     const list=field(source,'contact_id');
     if(list){
       let opt=[...list.options].find(o=>o.value===json.id);
       if(!opt){opt=new Option(json.name,json.id);list.add(opt);}
       list.value=json.id;
     }
     const counterparty=field(source,'counterparty');
     if(counterparty&&!counterparty.value.trim())counterparty.value=json.name;
     modal.close();
   }catch(error){out.textContent=error?.message||'Falha ao cadastrar.';}
   finally{button.disabled=false;}
 });
}
function start(){
 document.querySelectorAll('form[data-smart-treasury]').forEach(formSetup);
 bindContact();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
else start();
})();