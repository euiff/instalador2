/* Igreja Master 3.7.52: fiscal document first, OCR previews, duplicate warnings.
 * Image/PDF recognition runs in browser; recognized text is checked ONLY at same-origin server.
 * No financial entry is created during OCR. The user reviews and submits separately.
 */
(()=>{
 'use strict';
 const form=document.querySelector('form[data-finance-attachment][data-fiscal-autofill]');
 if(!form)return;
 const input=form.querySelector('input[name="document_file"]');
 const $=name=>form.querySelector('[name="'+name+'"]');
 const status=form.querySelector('[data-fiscal-status]');
 const list=form.querySelector('[data-fiscal-files]');
 const duplicateBox=form.querySelector('[data-fiscal-duplicates]');
 const review=form.querySelector('[data-fiscal-review]');
 if(!input||!status||!list||!duplicateBox)return;
 let pending=null,blocked=false,scanCounter=0,lastText='',lastHash='',lastFileName='';
 const say=(text,isError=false)=>{status.textContent=text;status.style.color=isError?'#a02424':'';};
 const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const loadScript=(src,check)=>new Promise((resolve,reject)=>{
   if(check()){resolve();return;}
   const tag=document.createElement('script');tag.src=src;tag.async=true;tag.referrerPolicy='no-referrer';
   tag.onload=()=>check()?resolve():reject(Error('Biblioteca de leitura não disponível.'));
   tag.onerror=()=>reject(Error('O navegador não conseguiu carregar o leitor de documentos. Verifique a internet.'));
   document.head.append(tag);
 });
 const tesseract=()=>loadScript('https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.1.1/tesseract.min.js',()=>!!window.Tesseract?.createWorker);
 const pdfjs=async()=>{
   await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',()=>!!window.pdfjsLib);
   window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
 };
 const sha256=async file=>{
   if(!window.crypto?.subtle)return '';
   const bytes=await file.arrayBuffer();
   const digest=await crypto.subtle.digest('SHA-256',bytes);
   return [...new Uint8Array(digest)].map(x=>x.toString(16).padStart(2,'0')).join('');
 };
 const readImage=async(fileOrCanvas)=>{
   await tesseract();
   say('Lendo o cupom ou nota fiscal... Esta etapa pode levar alguns segundos.');
   const worker=await window.Tesseract.createWorker('por');
   try{const result=await worker.recognize(fileOrCanvas);return result?.data?.text||'';}
   finally{await worker.terminate();}
 };
 const readPdf=async file=>{
   await pdfjs();
   say('Analisando o PDF...');
   const pdf=await window.pdfjsLib.getDocument({data:new Uint8Array(await file.arrayBuffer())}).promise;
   const chunks=[];
   for(let i=1;i<=Math.min(3,pdf.numPages);i++){
     const page=await pdf.getPage(i);
     const content=await page.getTextContent();
     const plain=(content.items||[]).map(x=>x.str||'').join(' ');
     if(plain.trim().length>60){chunks.push(plain);continue;}
     const viewport=page.getViewport({scale:1.8});
     const canvas=document.createElement('canvas');canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
     await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
     chunks.push(await readImage(canvas));
     canvas.width=0;canvas.height=0;
   }
   return chunks.join('\n');
 };
 function setField(name,val){
   if(val===undefined||val===null||val==='')return;
   const el=$(name);if(!el)return;
   if(el instanceof HTMLSelectElement){
     const norm=x=>String(x).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
     const found=[...el.options].find(o=>o.value&&norm(o.value)===norm(val));
     if(found)el.value=found.value;else return;
   }else el.value=String(val);
   el.dispatchEvent(new Event('input',{bubbles:true}));
   el.dispatchEvent(new Event('change',{bubbles:true}));
 }
 function formatBRL(value){
   const n=Number(value);
   return Number.isFinite(n)?n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}):'';
 }
 function apply(fields){
   if(!fields)return;
   if(fields.document_type==='invoice'||fields.document_type==='fiscal_coupon'){
     setField('direction','expense');
     setField('document_type',fields.document_type);
   }
   // Date from OCR is only set when day, month and year are clearly present.
   setField('transaction_date',fields.date);
   setField('amount',fields.amount?formatBRL(fields.amount):'');
   setField('counterparty',fields.issuer);
   setField('document_number',fields.document_number);
   if(fields.payment_method)setField('payment_method',fields.payment_method);
   // Never fabricate a church-specific accounting category.
   const category=$('category');
   if(category&&!category.value&&fields.issuer){
     const text=(fields.issuer+' '+lastText.slice(0,3000)).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase();
     const options=[...category.options].filter(o=>o.value&&!o.disabled);
     const hints=[
       [/SUPERMERCADO|ALIMENT|MERCEARIA|RESTAURANTE|LANCHONETE/,/ALIMENT|MERENDA|CANTINA/],
       [/GASOLINA|ETANOL|COMBUSTIVEL|POSTO DE/,/COMBUST|TRANSPORTE/],
       [/FARMACIA|MEDICAMENTO|DROGARIA/,/MEDIC|SAUDE/],
       [/CONSTRUCAO|FERRAGEM|MATERIAL/,/MATERIAL|OBRA|CONSTRU/]
     ];
     for(const [trigger,categoryWord] of hints){
       if(trigger.test(text)){
         const item=options.find(o=>categoryWord.test(o.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase()));
         if(item){setField('category',item.value);break;}
       }
     }
   }
   const desc=fields.description||((fields.short_number)?'Cupom fiscal nº '+fields.short_number:null);
   setField('description',desc);
   const details=[
     ['Estabelecimento',fields.issuer],
     ['CNPJ',fields.issuer_tax_id],
     ['Chave/numero do documento',fields.document_number],
     ['Data de emissão',fields.date],
     ['Total',fields.amount?'R$ '+formatBRL(fields.amount):null]
   ].filter(([,v])=>v);
   review.innerHTML='<strong>Dados encontrados (confira):</strong><div class="fiscal-scan-grid">'+
     details.map(([k,v])=>'<div><span>'+escape(k)+'</span><strong>'+escape(v)+'</strong></div>').join('')+'</div>';
   review.hidden=false;
 }
 function displayDuplicates(result){
   const items=result?.duplicates||[];
   blocked=Boolean(result?.blocking);
   duplicateBox.hidden=items.length===0;
   if(!items.length){duplicateBox.textContent='';return;}
   duplicateBox.className='fiscal-duplicate-alert'+(blocked?' fiscal-block':'');
   duplicateBox.innerHTML='<strong>'+(blocked?'Documento duplicado: lançamento bloqueado.':'Atenção: número semelhante já cadastrado.')+'</strong><ul>'+
      items.map(x=>'<li>'+escape(x.reason||'Documento existente')+
        (x.entry_id?' — <a target="_blank" rel="noopener" href="/finance-entry-edit.php?id='+encodeURIComponent(x.entry_id)+'">Ver lançamento anterior</a>':'')+
        '</li>').join('')+'</ul><small>Verifique antes de registrar. Números curtos podem se repetir em estabelecimentos diferentes.</small>';
 }
 async function serverCheck(text,hash,parsed=false){
   const data=new FormData();
   const csrf=form.querySelector('input[name="_csrf"],input[name="csrf_token"],input[name="csrf"]');
   if(csrf)data.append(csrf.name,csrf.value);
   data.append('action',text?'scan':'duplicates');
   if(text)data.append('ocr_text',text);
   if(hash)data.append('file_hash',hash);
   if(!parsed){
     if($('document_number')?.value)data.append('document_number',$('document_number').value);
     if($('amount')?.value)data.append('amount',$('amount').value);
     if($('transaction_date')?.value)data.append('date',$('transaction_date').value);
     if($('counterparty')?.value)data.append('issuer',$('counterparty').value);
   }
   const response=await fetch('/finance-scan.php',{method:'POST',credentials:'same-origin',body:data,
      headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const json=await response.json().catch(()=>({ok:false,message:'Servidor não retornou os dados da leitura.'}));
   if(!response.ok||!json.ok)throw new Error(json.message||'Falha ao verificar documento.');
   return json;
 }
 async function scan(file,ordinal){
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — pronto para analisar</li>';
   review.hidden=true;duplicateBox.hidden=true;blocked=false;lastText='';lastHash='';lastFileName=file.name;
   if(file.size>12*1024*1024){say('O arquivo ultrapassa 12 MB.',true);return;}
   const hash=await sha256(file).catch(()=>''),text=await (file.type==='application/pdf'||/\.pdf$/i.test(file.name)?readPdf(file):readImage(file));
   if(ordinal!==scanCounter)return;
   lastText=text;lastHash=hash;
   if(!text.trim())throw new Error('Não foi possível identificar texto. Tire outra foto com boa iluminação.');
   // First pass: parse without overriding the previous amount/reference.
   const first=await serverCheck(text,hash,true);
   if(ordinal!==scanCounter)return;
   apply(first.fields);
   // Second pass verifies fields after OCR auto-fill.
   const result=await serverCheck('',hash);
   if(ordinal!==scanCounter)return;
   displayDuplicates(result);
   say(blocked?'Duplicidade identificada. Veja o lançamento anterior.':'Leitura concluída. Confira a categoria e os dados antes de registrar.');
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — '+(blocked?'duplicado':'analisado')+'</li>';
 }
 input.addEventListener('change',()=>{
   const file=input.files?.[0];scanCounter++;const ordinal=scanCounter;blocked=false;lastText='';lastHash='';
   if(!file){list.innerHTML='<li>Nenhum arquivo selecionado.</li>';say('Você pode preencher manualmente.');return;}
   pending=scan(file,ordinal).catch(error=>{
      if(ordinal!==scanCounter)return;
      say('Leitura automática não concluída: '+(error.message||'falha no reconhecimento')+'. Confira os campos manualmente.',true);
      list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — leitura indisponível</li>';
   }).finally(()=>{if(ordinal===scanCounter)pending=null;});
 });
 const check=async()=>{
   if(!input.files?.length)return;
   const result=await serverCheck('',lastHash);
   displayDuplicates(result);
 };
 ['document_number','counterparty','amount','transaction_date'].forEach(name=>{
   $(name)?.addEventListener('blur',()=>{if(!pending)check().catch(()=>{});});
 });
 // Capture phase precedes the normal financial form and snapshot upload handlers.
 form.addEventListener('submit',ev=>{
   if(pending){ev.preventDefault();ev.stopImmediatePropagation();say('Aguarde o término da leitura antes de registrar.',true);return;}
   if(blocked){ev.preventDefault();ev.stopImmediatePropagation();duplicateBox.scrollIntoView({block:'center'});say('Documento já registrado nesta igreja. Verifique o lançamento anterior.',true);return;}
 },true);
})();
