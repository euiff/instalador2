/* Igreja Master 3.7.74 — OCR gratuito/local é padrão.
 * Fotos e PDFs são lidos dentro do próprio navegador, usando recursos estáticos
 * hospedados no Igreja Master. Só o texto reconhecido vai ao PHP da igreja.
 * Gemini/API externa só é chamada se a pessoa clicar no botão opcional.
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
 let pending=null,blocked=false,scanCounter=0,lastHash='',lastFile=null,abort=null,preparing=false;
 let lastAutoReference='';
 const referenceHint=form.querySelector('[data-finance-reference-hint]');
 const fileHelp=form.querySelector('[data-fiscal-file-help]');
 const reselect=form.querySelector('[data-fiscal-reselect]');
 const localFileAdvice='O Android não liberou o conteúdo deste PDF. Abra o documento no WhatsApp ou no gerenciador de arquivos, salve uma cópia em Downloads e selecione essa cópia aqui. No Chrome, prefira Arquivos → Downloads. O lançamento manual continua disponível.';
 const isUnavailable=error=>error?.name==='NotFoundError'||error?.name==='NotReadableError'||
   /requested file or directory|could not be found|not readable|file or directory was not found|cannot read properties.*file/i.test(String(error?.message||''));
 function help(message){
   if(fileHelp){fileHelp.hidden=!message;fileHelp.textContent=message;}
   if(reselect)reselect.hidden=!message;
 }
 const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function say(message,error=false){status.textContent=message;status.style.color=error?'#a02424':'';}
 const normalize=value=>String(value??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR').trim();
 function setField(name,value){
   if(value===undefined||value===null||value==='')return;
   const el=field(name);if(!el)return;
   if(el instanceof HTMLSelectElement){
     const normalized=normalize(value);
     let matching=[...el.options].find(option=>option.value&&!option.disabled&&normalize(option.value)===normalized);
     // Gemini returns "PIX" whereas this church's default is "PIX manual".
     // Keep the declared payment method rather than silently discarding it.
     if(!matching && name==='payment_method'){
       if(normalized==='pix')matching=[...el.options].find(option=>normalize(option.value)==='pix manual');
       if(normalized.startsWith('cartao'))matching=[...el.options].find(option=>normalize(option.value)==='cartao');
     }
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
 function fillForm(data,engine='local_ocr'){
   if(!data)return;
   const fiscal=data.document_type==='invoice'||data.document_type==='fiscal_coupon';
   if(data.direction==='income'||data.direction==='expense')setField('direction',data.direction);
   else if(fiscal)setField('direction','expense');
   if(data.document_type)setField('document_type',data.document_type);
   if(data.date)setField('transaction_date',data.date);
   if(data.amount)setField('amount',realBRL(data.amount));
   if(data.issuer)setField('counterparty',data.issuer);
   if(data.document_number){
     setField('document_number',data.document_number);
     lastAutoReference=data.document_number;
   }
   const referenceType=data.bank_reference_type||'';
   const referenceTypeInput=field('bank_reference_type');
   if(referenceTypeInput)referenceTypeInput.value=referenceType;
   if(referenceHint){
     referenceHint.textContent=referenceType==='pix_e2e'?'EndToEnd ID Pix reconhecido no comprovante. Será usado para impedir outra entrada com o mesmo ID.':
       referenceType==='file_fingerprint'?'Identificador interno do arquivo (não é referência do banco). Impede registrar este mesmo PDF/foto novamente.':
       data.bank_reference?'Referência impressa no banco/recibo. Confira os caracteres, pois códigos curtos podem repetir.':
       data.access_key?'Chave de acesso fiscal reconhecida. O sistema verifica duplicação.':
       'Número de referência reconhecido. Confira antes de gravar.';
   }
   if(data.payment_method)setField('payment_method',data.payment_method);
   if(data.category)setField('category',data.category);
   // Fill description last: assistant may regenerate it while other fields change.
   if(data.description)setField('description',data.description);

   const details=[
     [data.document_type==='proof'?'Pagador/recebedor':'Estabelecimento',data.issuer],
     ['Pagador do Pix',data.payer],
     ['Recebedor do Pix',data.receiver],
     ['CNPJ do emitente',data.issuer_tax_id],
     [data.bank_reference?'Referência bancária / operação':
       referenceType==='file_fingerprint'?'ID interno do arquivo':
       'Número / chave fiscal',data.document_number],
     ['Banco / instituição',data.bank_name],
     [data.document_type==='proof'?'Data da operação':'Emissão',data.date],
     ['Hora da operação',data.payment_time],
     [data.document_type==='proof'?'Valor transferido':'Total da nota',data.amount?'R$ '+realBRL(data.amount):null],
     ['Forma de pagamento',data.payment_method],
     ['Categoria sugerida',data.category]
   ].filter(([,v])=>Boolean(v));
   const missing=[
     !data.amount?'valor total':null,
     !data.date?'data':null,
     !data.document_number?'referência do comprovante (ou ID interno do arquivo)':null,
     !data.category?'categoria contábil':null
   ].filter(Boolean);
   const warnings=Array.isArray(data.warnings)?data.warnings:[];
   const readerCaption=engine==='local_ocr'
     ?'Dados extraídos pelo leitor próprio (sem créditos de IA) — confira antes de salvar:'
     :'Dados extraídos pela IA externa (opcional) — confira antes de salvar:';
   review.innerHTML='<strong>'+readerCaption+'</strong>'+
     '<div class="fiscal-scan-grid">'+details.map(([label,value])=>
       '<div><span>'+escape(label)+'</span><strong>'+escape(value)+'</strong></div>').join('')+'</div>'+
     (missing.length?'<p class="muted"><strong>Preencher/conferir:</strong> '+escape(missing.join(', '))+'</p>':'')+
     (warnings.length?'<p class="muted"><strong>Atenção:</strong> '+escape(warnings.join(' · '))+'</p>':'')+
     '<p class="muted">Um identificador interno de arquivo não comprova transação bancária. Se houver ID Pix ou autenticação legível, confira o código acima antes de salvar.</p>';
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
   if(field('bank_reference_type')?.value)
     data.append('reference_type',field('bank_reference_type').value);
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
 async function stabilizePickedFile(source,sequence){
   // Android content:// providers can revoke a temporary WhatsApp or
   // document-picker URI. Read bytes immediately while the chooser still
   // grants access and replace the input's FileList with an in-memory File.
   // This stable copy is used for BOTH the AI scan and final finance upload.
   // Do not silently send an unreadable/expired original file on save.
   if(!source||source.size===0)
     throw new Error('O arquivo está vazio ou não está disponível no dispositivo.');
   const buffer=await source.arrayBuffer();
   if(sequence!==scanCounter)return null;
   if(buffer.byteLength!==source.size)
     throw new Error('Não foi possível copiar o documento completo. Selecione-o novamente.');
   const stable=new File([buffer],source.name||'documento.pdf',{
     type:source.type||'application/octet-stream',lastModified:Date.now()
   });
   try{
     if(typeof DataTransfer!=='function')
       throw new Error('Navegador não oferece substituição segura de arquivos.');
     const dt=new DataTransfer();
     dt.items.add(stable);
     input.files=dt.files;
     if(!input.files?.length || input.files[0].size!==stable.size)
       throw new Error('O navegador não confirmou a cópia segura.');
   }catch(error){
     throw new Error('Este navegador não conseguiu manter o documento disponível para anexar. Salve o PDF em Downloads e abra esta página no Chrome.',{cause:error});
   }
   return stable;
 }
 async function readViaAI(file,mode,sequence){
   blocked=false;duplicateBox.hidden=true;
   review.hidden=true;
   precise?.setAttribute('disabled','');
   say(mode==='precise'?'Analisando o documento com maior precisão...':'Lendo foto/PDF com inteligência artificial...');
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — analisando…</li>';
   const aiFile=await optimizeForVision(file);
   if(aiFile.size>10*1024*1024)throw new Error('A leitura por IA aceita até 10 MB. Compacte a foto ou escolha um PDF menor.');
   // Read a new in-memory AI copy; the financial attachment input now also
   // holds the stable original bytes, not an ephemeral Android URI.
   let bytes;
   try{bytes=await aiFile.arrayBuffer();}
   catch(e){
     if(isUnavailable(e))throw new Error(localFileAdvice);
     throw e;
   }
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
   fillForm(payload.fields,'external_ai');
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
 async function readLocally(file,sequence){
   if(!window.IgrejaLocalOCR?.extract)
     throw new Error('Leitor próprio não instalado. Atualize novamente a versão 3.7.74 no Painel Master.');
   blocked=false;duplicateBox.hidden=true;review.hidden=true;
   say('Iniciando leitura própria, sem chamar a IA e sem gastar créditos...');
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — lendo no seu aparelho…</li>';
   const ocr=await window.IgrejaLocalOCR.extract(file,{
     onStatus:message=>{if(sequence===scanCounter)say(message)}
   });
   if(sequence!==scanCounter)return;
   const scan=new FormData();token(scan);
   scan.append('action','scan');
   scan.append('ocr_text',String(ocr.text||'').slice(0,145000));
   scan.append('file_hash',ocr.fileHash);
   abort?.abort();abort=new AbortController();
   let response;
   try{
     response=await fetch('/finance-scan.php',{
       method:'POST',body:scan,credentials:'same-origin',cache:'no-store',
       headers:{'X-Requested-With':'XMLHttpRequest'},
       signal:abort.signal
     });
   }finally{abort=null}
   const payload=await response.json().catch(()=>null);
   if(sequence!==scanCounter)return;
   if(!response.ok||!payload?.ok)
     throw new Error(payload?.message||'A leitura local não foi reconhecida. Faça uma foto mais nítida ou preencha manualmente.');
   const extracted=payload.fields||{};
   extracted.warnings=Array.isArray(extracted.warnings)?extracted.warnings:[];
   if(Array.isArray(ocr.warnings))extracted.warnings.push(...ocr.warnings);
   if(ocr.confidence!==null&&Number(ocr.confidence)>0&&Number(ocr.confidence)<65)
     extracted.warnings.push('Foto com reconhecimento parcialmente incerto: confira cuidadosamente os campos.');
   lastHash=payload.file_hash||ocr.fileHash||'';
   fillForm(extracted,'local_ocr');
   await manualCheck().catch(()=>displayDuplicates(payload));
   if(sequence!==scanCounter)return;
   const incomplete=['amount','date','document_number','category'].some(k=>!extracted[k]);
   say(blocked?'Documento duplicado identificado — abra o lançamento anterior.':
      incomplete?'Leitura própria concluída sem gastar créditos. Confira e preencha os campos pendentes.':
      'Leitura própria concluída sem gastar créditos! Confira os dados antes de registrar.',blocked);
   list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — leitura própria concluída ('+
     (ocr.source==='pdf_text'?'texto do PDF':ocr.source==='pdf_ocr'?'PDF digitalizado':'foto OCR')+')</li>';
   if(precise){precise.hidden=false;precise.disabled=false}
 }
 function start(mode){
   const file=lastFile;
   if(!file)return;
   const sequence=++scanCounter;
   pending=(mode==='precise'?readViaAI(file,mode,sequence):readLocally(file,sequence)).catch(error=>{
     if(sequence!==scanCounter)return;
     if(error?.name==='AbortError')return;
     const message=isUnavailable(error)?localFileAdvice:
       (error?.message||'Não foi possível concluir a leitura do arquivo.');
     say((mode==='precise'?'A IA externa não respondeu. ':'A leitura própria não concluiu. ')+message+
         ' Você pode preencher manualmente ou tentar a IA opcional.',true);
     if(precise)precise.hidden=false;
     if(isUnavailable(error))help(localFileAdvice);
     list.innerHTML='<li><strong>'+escape(file.name)+'</strong> — preencher manualmente</li>';
   }).finally(()=>{
     if(sequence!==scanCounter)return;
     pending=null;
     if(precise)precise.disabled=false;
   });
 }
 input.addEventListener('change',async()=>{
   const picked=input.files?.[0]||null;
   const sequence=++scanCounter;
   abort?.abort();abort=null;
   blocked=false;lastHash='';lastFile=null;preparing=false;
   if(lastAutoReference && field('document_number')?.value===lastAutoReference){
     setField('document_number','');
     // setField deliberately ignores empty strings; clear the old reference
     // directly so reselecting another PDF never reuses a prior bank ID.
     const ref=field('document_number');if(ref)ref.value='';
   }
   lastAutoReference='';
   if(field('bank_reference_type'))field('bank_reference_type').value='';
   if(referenceHint)referenceHint.textContent='Aguardando leitura do novo arquivo: a referência bancária só será preenchida se estiver legível no comprovante.';
   if(precise)precise.hidden=true;
   help('');
   review.hidden=true;duplicateBox.hidden=true;
   if(!picked){
     say('Escolha uma foto ou PDF para leitura própria gratuita, ou preencha manualmente.');
     list.innerHTML='<li>Nenhum arquivo selecionado.</li>';
     return;
   }
   if(picked.size>12*1024*1024){
     say('O documento tem mais de 12 MB. Escolha um PDF ou imagem menor.',true);
     list.innerHTML='<li>Arquivo grande demais para anexar.</li>';
     input.value='';return;
   }
   preparing=true;
   say('Preparando uma cópia segura do arquivo no celular...');
   list.innerHTML='<li><strong>'+escape(picked.name)+'</strong> — preparando documento…</li>';
   try{
     const stable=await stabilizePickedFile(picked,sequence);
     if(sequence!==scanCounter||!stable)return;
     lastFile=stable;
     help('');
     preparing=false;
     if(precise)precise.hidden=false;
     start('local');
   }catch(error){
     if(sequence!==scanCounter)return;
     preparing=false;lastFile=null;lastHash='';
     // Keep the finance form usable for manual entry; never submit a broken
     // FileList that the PHP application will reject later.
     input.value='';
     const problem=isUnavailable(error)?localFileAdvice:(error?.message||localFileAdvice);
     help(problem);
     say('O PDF não pôde ser acessado para leitura e anexo. '+problem,true);
     list.innerHTML='<li><strong>'+escape(picked.name)+'</strong> — não foi possível acessar os bytes. Selecione a cópia salva.</li>';
   }
 });
 reselect?.addEventListener('click',()=>input.click());
 precise?.addEventListener('click',()=>start('precise'));
 ['document_number','counterparty','amount','transaction_date'].forEach(name=>{
   field(name)?.addEventListener('blur',()=>{if(!pending && lastFile)manualCheck().catch(()=>{});});
 });
 form.addEventListener('submit',event=>{
   if(preparing||pending){
     event.preventDefault();event.stopImmediatePropagation();
     say(preparing?'O PDF ainda está sendo preparado. Confira o arquivo antes de registrar.':
       'A leitura ainda está em andamento. Confira o resultado antes de registrar.',true);
   }else if(blocked){
     event.preventDefault();event.stopImmediatePropagation();
     duplicateBox.scrollIntoView({block:'center'});
     say('Possível duplicidade: confira o documento já registrado.',true);
   }
 },true);
})();
