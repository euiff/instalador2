/* Igreja Master 3.7.54 — fiscal receipt AI reading, mobile-first.
 * The scanned image is sent to our own authenticated PHP endpoint, which calls
 * Gemini server-side. No API key reaches the browser. No posting during scan.
 */
(()=>{
 'use strict';
 const form=document.querySelector('form[data-finance-attachment][data-fiscal-autofill]');
 if(!form)return;
 const input=form.querySelector('input[name="document_file"]');
 const field=name=>form.querySelector('[name="'+name+'"]');
 const status=form.querySelector('[data-fiscal-status]');
 const list=form.querySelector('[data-fiscal-files]');
 const duplicateBox=form.querySelector('[data-fiscal-duplicates]');
 const review=form.querySelector('[data-fiscal-review]');
 const precise=form.querySelector('[data-fiscal-precise]');
 if(!input||!status||!list||!duplicateBox||!review)return;
 let pending=null,blocked=false,scanCounter=0,lastHash='',lastFile=null,abort=null;
 const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function say(message,error=false){status.textContent=message;status.style.color=error?'#a02424':'';}
 const normalize=value=>String(value??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR').trim();
 function setField(name,value){
   if(value===undefined||value===null||value==='')return;
   const el=field(name);if(!el)return;
   if(el instanceof HTMLSelectElement){
     const matching=[...el.options].find(option=>option.value&&!option.disabled&&normalize(option.value)===normalize(value));
     if(!matching)return;
     el.value=matching.value;
   }else{
     el.value=String(value);
   }
   el.dispatchEvent(new Event('input',{bubbles:true}));
   el.dispatchEvent(new Event('change',{bubbles:true}));
 }
 const realBRL=value=>{
   const n=Number(String(value).replace(',','.'));
   return Number.isFinite(n)&&n>0?n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}):'';
 };
 function fillForm(data){
   if(!data)return;
   const fiscal=data.document_type==='invoice'||data.document_type==='fiscal_coupon';
   if(fiscal)setField('direction','expense');
   if(data.document_type)setField('document_type',data.document_type);
   if(data.date)setField('transaction_date',data.date);
   if(data.amount)setField('amount',realBRL(data.amount));
   if(data.issuer)setField('counterparty',data.issuer);
   if(data.document_number)setField('document_number',data.document_number);
   if(data.payment_method)setField('payment_method',data.payment_method);
   if(data.category)setField('category',data.category);
   // Fill description last: assistant may regenerate it while other fields change.
   if(data.description)setField('description',data.description);

   const details=[
     ['Estabelecimento',data.issuer],
     ['CNPJ do emitente',data.issuer_tax_id],
     ['Número / chave fiscal',data.document_number],
     ['Emissão',data.date],
     ['Total da nota',data.amount?'R$ '+realBRL(data.amount):null],
     ['Forma de pagamento',data.payment_method],
     ['Categoria sugerida',data.category]
   ].filter(([,v])=>Boolean(v));
   const missing=[
     !data.amount?'valor total':null,
     !data.date?'data':null,
     !data.document_number?'número da nota/cupom':null,
     !data.category?'categoria contábil':null
   ].filter(Boolean);
   const warnings=Array.isArray(data.warnings)?data.warnings:[];
   review.innerHTML='<strong>Dados extraídos pela IA — confira antes de salvar:</strong>'+
     '<div class="fiscal-scan-grid">'+details.map(([label,value])=>
       '<div><span>'+escape(label)+'</span><strong>'+escape(value)+'</strong></div>').join('')+'</div>'+
     (missing.length?'<p class="muted"><strong>Preencher/conferir:</strong> '+escape(missing.join(', '))+'</p>':'')+
     (warnings.length?'<p class="muted"><strong>Atenção:</strong> '+escape(warnings.join(' · '))+'</p>':'');
   review.hidden=false;
 }
 function displayDuplicates(result){
   const items=Array.isArray(result?.duplicates)?result.duplicates:[];
   blocked=Boolean(result?.blocking);
   duplicateBox.hidden=items.length===0;
   if(!items.length){duplicateBox.textContent='';return;}
   duplicateBox.className='fiscal-duplicate-alert'+(blocked?' fiscal-block':'');
   duplicateBox.innerHTML='<strong>'+(blocked?'Possível duplicação: registro bloqueado.':'Atenção: referência semelhante já existe.')+'</strong><ul>'+
       items.map(item=>'<li>'+escape(item.reason||'Documento encontrado')+
         (item.entry_id?' — <a target="_blank" rel="noopener" href="/finance-entry-edit.php?id='+encodeURIComponent(item.entry_id)+'">Ver lançamento</a>':'')+
         '</li>').join('')+'</ul><small>Números curtos podem repetir em estabelecimentos diferentes. A proteção definitiva também ocorre ao salvar.</small>';
 }
 function token(data){
   const csrf=form.querySelector('input[name="_csrf"]');
   if(csrf)data.append(csrf.name,csrf.value);
 }
 async function manualCheck(){
   const data=new FormData();token(data);
   data.append('action','duplicates');
   if(lastHash)data.append('file_hash',lastHash);
   for(const [key,name] of [['document_number','document_number'],['amount','amount'],
                             ['date','transaction_date'],['issuer','counterparty']]){
     if(field(name)?.value)data.append(key,field(name).value);
   }
   const r=await fetch('/finance-scan.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store',
     headers:{'X-Requested-With':'XMLHttpRequest'}});
   const res=await r.json().catch(()=>null);
   if(r.ok&&res?.ok)displayDuplicates(res);
 }
 async function optimizeForVision(file){
   // Compress only the copy sent to AI. Keep the original for the financial attachment.
   if(file.size<2*1024*1024||!/^image\/(jpeg|png|webp)$/i.test(file.type)||!window.createImageBitmap)
     return file;
   try{
     const bitmap=await createImageBitmap(file);
     const ratio=Math.min(1,3000/Math.max(bitmap.width,bitmap.height));
     if(ratio===1&&file.size<4*1024*1024){bitmap.close();return file;}
     const canvas=document.createElement('canvas');
     canvas.width=Math.max(1,Math.round(bitmap.width*ratio));
     canvas.height=Math.max(1,Math.round(bitmap.height*ratio));
     canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);
     bitmap.close();
     const optimized=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.9));
     canvas.width=0;canvas.height=0;
     if(optimized&&optimized.size<file.size)
       return new File([optimized],file.name.replace(/\.[^.]+$/,'.jpg'),{type:'image/jpeg',lastModified:Date.now()});
   }catch(_e){/* Keep original on older Android browsers. */}
   return file;
 }
 async function read(file,mode,sequence){
   blocked=false;duplicateBox.hidden=true;
   review.hidden=true;
   precise?.setAttribute('disabled','');
   say(mode==='precise'?'Analisando o documento com maior precisão...':'Lendo foto/PDF com inteligência artificial...');
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — analisando…</li>';
   const aiFile=await optimizeForVision(file);
   if(aiFile.size>10*1024*1024)throw new Error('A leitura por IA aceita até 10 MB. Compacte a foto ou escolha um PDF menor.');
   // Snapshot only the optimized AI copy. The upload code keeps the original file.
   const bytes=await aiFile.arrayBuffer();
   if(sequence!==scanCounter)return;
   const snapshot=new File([bytes],aiFile.name,{type:aiFile.type||'application/octet-stream',lastModified:Date.now()});
   const data=new FormData();token(data);
   data.append('action','scan_file');data.append('mode',mode);
   data.append('document_file',snapshot,snapshot.name);
   abort?.abort();abort=new AbortController();
   let response;
   try{
     response=await fetch('/finance-scan.php',{
       method:'POST',body:data,credentials:'same-origin',
       headers:{'X-Requested-With':'XMLHttpRequest'},
       cache:'no-store',signal:abort.signal
     });
   }finally{abort=null;}
   const payload=await response.json().catch(()=>null);
   if(sequence!==scanCounter)return;
   if(!response.ok||!payload?.ok)
     throw new Error(payload?.message || 'A IA não confirmou a leitura. Verifique sua conexão ou fale com o Master.');
   lastHash=payload.file_hash||'';
   fillForm(payload.fields);
   // Do a fresh comparison after fields have been populated by the AI.
   await manualCheck().catch(()=>displayDuplicates(payload));
   if(sequence!==scanCounter)return;
   const incomplete=['amount','date','document_number','category'].some(key=>!payload.fields?.[key]);
   say(blocked?'Documento duplicado identificado. Abra o lançamento anterior.':
     incomplete?'Leitura concluída. Confira os campos destacados antes de salvar.':
      'Leitura concluída. Os campos foram preenchidos; confira e clique em Registrar lançamento.',blocked);
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — '+(blocked?'duplicidade':'leitura concluída')+'</li>';
   if(precise)precise.hidden=false;
 }
 function start(mode){
   const file=lastFile;
   if(!file)return;
   const sequence=++scanCounter;
   pending=read(file,mode,sequence).catch(error=>{
     if(sequence!==scanCounter)return;
     if(error?.name==='AbortError')return;
     say('Não foi possível ler automaticamente: '+(error?.message||'erro desconhecido')+
         '. Você pode preencher os dados manualmente.',true);
     list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — preencher manualmente</li>';
   }).finally(()=>{
     if(sequence!==scanCounter)return;
     pending=null;
     if(precise)precise.disabled=false;
   });
 }
 input.addEventListener('change',()=>{
   lastFile=input.files?.[0]||null;
   ++scanCounter;
   abort?.abort();abort=null;
   blocked=false;lastHash='';
   if(precise)precise.hidden=true;
   if(!lastFile){
     say('Selecione um arquivo para análise com IA ou preencha manualmente.');
     list.innerHTML='<li>Nenhum arquivo selecionado.</li>';review.hidden=true;duplicateBox.hidden=true;
     return;
   }
   start('fast');
 });
 precise?.addEventListener('click',()=>start('precise'));
 ['document_number','counterparty','amount','transaction_date'].forEach(name=>{
   field(name)?.addEventListener('blur',()=>{if(!pending && lastFile)manualCheck().catch(()=>{});});
 });
 form.addEventListener('submit',event=>{
   if(pending){
     event.preventDefault();event.stopImmediatePropagation();
     say('A leitura ainda está em andamento. Confira o resultado antes de registrar.',true);
   }else if(blocked){
     event.preventDefault();event.stopImmediatePropagation();
     duplicateBox.scrollIntoView({block:'center'});
     say('Possível duplicidade: confira o documento já registrado.',true);
   }
 },true);
})();
