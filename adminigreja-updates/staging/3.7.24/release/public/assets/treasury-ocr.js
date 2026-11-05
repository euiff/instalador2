/* Finance photo OCR: manual confirmation, no automatic postings. */
(function(){
'use strict';
const normalize=s=>String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
function setup(form){
 const pick=n=>form.elements.namedItem(n);
 const file=pick('document_file'),button=form.querySelector('[data-read-ocr]');
 const status=form.querySelector('[data-ocr-state]'),list=form.querySelector('[data-ocr-items-preview]');
 const json=pick('ocr_items_json'),confirmed=pick('ocr_confirmed');
 if(!file||!button)return;
 function tell(message){if(status)status.textContent=message;}
 function clear(){
   if(json)json.value='';
   if(list)list.replaceChildren();
   if(confirmed)confirmed.checked=false;
   const check=form.querySelector('[data-ocr-verify]');if(check)check.hidden=true;
 }
 function draw(items){
   if(!list||!json)return;
   list.replaceChildren();
   if(!items?.length){json.value='';return;}
   const header=document.createElement('h3');header.textContent='Confira os produtos identificados';
   list.appendChild(header);
   const section=document.createElement('div');section.className='smart-ocr-product-list';
   list.appendChild(section);
   const categories=[...pick('category').options].filter(x=>x.value).map(x=>x.value);
   function save(){
     json.value=JSON.stringify([...section.children].map(row=>({
       name:row.querySelector('[data-name]').value,
       amount:row.querySelector('[data-amount]').value,
       category:row.querySelector('[data-category]').value
     })).filter(x=>x.name.trim()));
     if(confirmed)confirmed.checked=false;
   }
   items.slice(0,80).forEach((it,i)=>{
     const row=document.createElement('div');row.className='smart-ocr-product';
     const name=document.createElement('input');name.className='input';name.dataset.name='';name.maxLength=160;name.value=it.name||'';name.setAttribute('aria-label','Nome do produto');
     const value=document.createElement('input');value.className='input';value.dataset.amount='';value.inputMode='decimal';value.value=it.amount||'';value.setAttribute('aria-label','Valor do produto');
     const cat=document.createElement('input');cat.className='input';cat.dataset.category='';cat.maxLength=160;cat.value=it.category||'';cat.setAttribute('aria-label','Categoria do produto');
     const choices=document.createElement('datalist');choices.id='ocr-items-cat-'+i;
     categories.forEach(c=>choices.appendChild(new Option('',c)));
     cat.setAttribute('list',choices.id);row.append(name,value,cat,choices);section.appendChild(row);
   });
   section.addEventListener('input',save);save();
 }
 file.addEventListener('change',clear);
 button.addEventListener('click',async()=>{
   const source=file.files?.[0];
   if(!source){tell('Escolha a foto do cupom primeiro.');return;}
   if(!/^image\/(jpeg|png|webp)$/i.test(source.type)){
     tell('A leitura é para imagens JPG, PNG e WEBP; PDF pode ser anexado normalmente.');return;
   }
   if(source.size>12*1024*1024){tell('Foto maior que 12 MB.');return;}
   button.disabled=true;let worker;
   try{
     if(typeof window.IgrejaOCRLoad!=='function')throw new Error('Leitor OCR indisponível. Preencha manualmente.');
     tell('Preparando OCR no navegador...');
     await window.IgrejaOCRLoad();
     worker=await window.Tesseract.createWorker('por');
     const result=await worker.recognize(source);
     const text=String(result.data?.text||'').slice(0,25000);
     if(text.trim().length<12)throw new Error('A imagem não está legível. Tire outra foto.');
     const body=new FormData(),csrf=form.querySelector('input[name*=csrf]');
     if(csrf)body.append(csrf.name,csrf.value);
     const dep=pick('department_id');if(dep)body.append('department_id',dep.value);
     body.append('ocr_text',text);
     tell('Sugerindo valores, fornecedor e categorias...');
     const response=await fetch('/finance-parse.php',{method:'POST',body,credentials:'same-origin'});
     const parsed=await response.json();
     if(!response.ok)throw new Error(parsed.error||'O documento não pôde ser interpretado.');
     const set=(key,value,force=false)=>{
       const el=pick(key);if(!el||!String(value||'').trim())return;
       if(force||!String(el.value||'').trim())el.value=value;
     };
     set('transaction_date',parsed.issued_on,true);
     if(parsed.total){
       const number=Number(parsed.total);
       if(Number.isFinite(number)&&number>0)
         set('amount',new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(number),true);
     }
     set('counterparty',parsed.supplier);
     set('document_number',parsed.number);
     set('document_type',parsed.suggested_document_type,true);
     const category=pick('category');
     if(category&&parsed.category){
       const found=[...category.options].find(x=>normalize(x.value)===normalize(parsed.category));
       if(found)category.value=found.value;
       else {category.add(new Option('Revisar: '+parsed.category,parsed.category));category.value=parsed.category;}
       category.dispatchEvent(new Event('change',{bubbles:true}));
     }
     const contact=pick('contact_id');
     if(contact&&parsed.supplier){
       const found=[...contact.options].find(x=>x.value&&normalize(x.textContent).trim()===normalize(parsed.supplier).trim());
       if(found)contact.value=found.value;
     }
     draw(parsed.items);
     const check=form.querySelector('[data-ocr-verify]');if(check)check.hidden=false;
     tell('Confira tudo antes de registrar. '+(parsed.warnings||[]).slice(0,2).join(' '));
   }catch(err){tell(err?.message||'Não foi possível ler. Preencha manualmente.');}
   finally{if(worker)try{await worker.terminate();}catch(_){}button.disabled=false;}
 });
}
function start(){document.querySelectorAll('form[data-smart-treasury]').forEach(setup);}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();