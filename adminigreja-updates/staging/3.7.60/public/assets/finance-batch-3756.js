/* Igreja Master 3.7.56 — fiscal batch, one verified entry per file. */
(()=>{
'use strict';
const root=document.querySelector('[data-finance-batch]');
if(!root)return;
const select=s=>root.querySelector(s),gallery=select('[data-batch-gallery]'),
 camera=select('[data-batch-camera]'),list=select('[data-batch-list]'),
 submit=select('[data-batch-submit]'),statusAll=select('[data-batch-global-status]'),
 accountDefault=select('[data-batch-default-account]'),
 token=select('input[name="_csrf"]')?.value||'',
 catTemplate=select('[data-batch-category-template] select'),
 accountTemplate=select('[data-batch-account-template] select');
let rows=[],nextId=0,busy=false,scanPools=[Promise.resolve(),Promise.resolve()],scanIndex=0,scans=0;
const max=Number(root.dataset.maxReceipts)||8;
const maxBytes=Number(root.dataset.maxFileBytes)||12*1024*1024;
const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const normalize=s=>String(s??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
const currency=s=>{const v=Number(s);return Number.isFinite(v)?v.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}):'';};
const randomKey=()=>{const b=new Uint8Array(32);crypto.getRandomValues(b);return [...b].map(v=>v.toString(16).padStart(2,'0')).join('');};
async function hashOriginal(buffer){
 if(!crypto?.subtle)return '';
 try{
   const digest=await crypto.subtle.digest('SHA-256',buffer.slice(0));
   return [...new Uint8Array(digest)].map(v=>v.toString(16).padStart(2,'0')).join('');
 }catch(_e){return '';}
}
const field=(r,name)=>r.card.querySelector('[data-field="'+name+'"]');
const read=(r,name)=>String(field(r,name)?.value??'').trim();
const fields=r=>Object.fromEntries(['direction','account_id','category','amount','transaction_date','counterparty',
  'payment_method','document_number','description','document_type','notes'].map(k=>[k,read(r,k)]));
const set=(r,name,value)=>{
 if(value===undefined||value===null||value==='')return;
 const el=field(r,name);if(!el)return;
 if(el instanceof HTMLSelectElement){
   const opt=[...el.options].find(o=>o.value&&!o.disabled&&normalize(o.value)===normalize(value));
   if(!opt)return;
   el.value=opt.value;
 }else el.value=String(value);
 el.dispatchEvent(new Event('input',{bubbles:true}));
};
function summary(){
 select('[data-batch-count]').textContent=rows.length+' cupons';
 select('[data-batch-ready]').textContent=rows.filter(r=>['ready','manual','error','uncertain'].includes(r.state)).length+' para conferir';
 select('[data-batch-saved]').textContent=rows.filter(r=>r.state==='saved').length+' registrados';
 submit.disabled=busy||!rows.some(r=>!['saved','reading','saving','duplicate','queued'].includes(r.state));
 submit.textContent=rows.some(r=>r.state==='saved')?'Registrar cupons restantes separadamente':'Conferir e registrar cupons separadamente';
}
function setStatus(r,state,message){
 r.state=state;
 const el=r.card.querySelector('[data-status]');
 el.textContent=message;
 el.className='batch-status'+(state==='saved'||state==='ready'?' batch-success':state==='duplicate'||state==='error'||state==='uncertain'?' batch-error':' batch-pending');
 summary();
}
function build(r){
 const card=document.createElement('article');
 card.className='batch-receipt';
 card.setAttribute('role','listitem');
 const preview=/^image\/(jpeg|png|webp)$/i.test(r.file.type)
   ?'<img class="batch-preview" data-preview alt="Foto do cupom">'
   :'<span style="font-size:32px" aria-hidden="true">📄</span>';
 card.innerHTML=[
 '<div class="batch-head"><div style="display:flex;gap:10px;align-items:center;min-width:0">',preview,
 '<div style="min-width:0"><strong>Cupom ',r.id,'</strong><div class="batch-document-note">',
 escape(r.file.name),' (',(r.file.size/1048576).toFixed(2),' MB)</div></div></div>',
 '<div class="batch-actions"><button type="button" class="batch-secondary" data-rescan>Reanalisar</button>',
 '<button type="button" class="batch-secondary" data-remove>Remover da lista</button></div></div>',
 '<p class="batch-status batch-pending" data-status>Na fila de leitura...</p>',
 '<div class="batch-duplicates" data-duplicates hidden></div>',
 '<div class="batch-grid">',
 '<div class="batch-field"><label>Tipo do movimento</label><select data-field="direction">',
 '<option value="expense">Saída / despesa</option><option value="income">Entrada</option></select></div>',
 '<div class="batch-field"><label>Data do cupom *</label><input type="date" data-field="transaction_date"></div>',
 '<div class="batch-field"><label>Conta / caixa</label><div data-account-slot></div></div>',
 '<div class="batch-field"><label>Lançamento Contábil *</label><div data-category-slot></div></div>',
 '<div class="batch-field"><label>Valor total (R$) *</label><input data-field="amount" inputmode="decimal" placeholder="0,00"></div>',
 '<div class="batch-field"><label>Fornecedor / favorecido</label><input data-field="counterparty" maxlength="190"></div>',
 '<div class="batch-field"><label>Número da nota / chave fiscal</label><input data-field="document_number" maxlength="190"></div>',
 '<div class="batch-field"><label>Forma de pagamento</label><input data-field="payment_method" maxlength="50" value="PIX manual"></div>',
 '<div class="batch-field"><label>Tipo do documento</label><select data-field="document_type">',
 '<option value="fiscal_coupon">Cupom fiscal</option><option value="invoice">Nota fiscal</option>',
 '<option value="receipt">Recibo</option><option value="proof">Comprovante</option></select></div>',
 '<div class="batch-field"><label>Observações</label><input data-field="notes" maxlength="2000"></div>',
 '<div class="batch-field" style="grid-column:1/-1"><label>Descrição *</label>',
 '<textarea data-field="description" maxlength="255" placeholder="Descrição do pagamento"></textarea></div></div>',
 '<p data-warnings class="batch-document-note"></p><p data-result></p>'
 ].join('');
 r.card=card;
 const img=card.querySelector('[data-preview]');
 if(img){r.objectUrl=URL.createObjectURL(r.file);img.src=r.objectUrl;}
 const account=accountTemplate.cloneNode(true);
 account.dataset.field='account_id';
 account.value=accountDefault.value;
 account.addEventListener('change',()=>r.accountTouched=true);
 card.querySelector('[data-account-slot]').append(account);
 const category=catTemplate.cloneNode(true);
 category.dataset.field='category';
 card.querySelector('[data-category-slot]').append(category);
 // Blank until the date is actually read from the receipt or reviewed by user.
 field(r,'transaction_date').value='';
 function directionChanged(){
   const direction=read(r,'direction');
   for(const option of category.options){
     if(!option.value)continue;
     const rule=option.dataset.direction||'both';
     option.disabled=rule!=='both'&&rule!==direction;
     option.hidden=option.disabled;
   }
   if(category.selectedOptions[0]?.disabled)category.value='';
 }
 field(r,'direction').addEventListener('change',directionChanged);
 directionChanged();
 card.querySelector('[data-rescan]').addEventListener('click',()=>{if(!busy&&r.state!=='saved')queueScan(r,true);});
 card.querySelector('[data-remove]').addEventListener('click',()=>{
   if(busy||r.state==='saved')return;
   r.removed=true;
   if(r.objectUrl)URL.revokeObjectURL(r.objectUrl);
   rows=rows.filter(item=>item!==r);
   card.remove();
   if(!rows.length)list.innerHTML='<div class="batch-empty">Selecione os cupons para começar.</div>';
   dedupe();summary();
 });
 return card;
}
function showDuplicates(r){
 const box=r.card.querySelector('[data-duplicates]'),hits=r.matches||[];
 box.hidden=!hits.length;
 box.innerHTML=hits.length?'<strong>'+(r.blocked?'Cupom duplicado — não registrar.':'Referência semelhante encontrada.')+
 '</strong><ul>'+hits.map(h=>'<li>'+escape(h.reason||'Documento cadastrado')+
 (h.entry_id?' <a target="_blank" rel="noopener" href="/finance-entry-edit.php?id='+encodeURIComponent(h.entry_id)+'">Ver anterior</a>':'')+
 '</li>').join('')+'</ul>':'';
}
function dedupe(){
 for(const r of rows){
   if(r.state==='saved')continue;
   const local=rows.some(other=>other!==r&&!other.removed &&
     ((r.hash&&r.hash===other.hash)||(r.key&&r.key===other.key)));
   r.blocked=Boolean(r.serverBlocked||local);
   r.matches=[...(r.serverMatches||[])];
   if(local)r.matches.push({reason:'Este documento aparece mais de uma vez neste lote.'});
   showDuplicates(r);
   if(r.blocked&&!['reading','saving'].includes(r.state))
     setStatus(r,'duplicate','Duplicidade identificada. Remova a cópia ou confira o lançamento anterior.');
   else if(!r.blocked&&r.state==='duplicate')setStatus(r,'ready','Revise os dados antes de registrar.');
 }
}
function fill(r,f){
 if(!f)return;
 for(const [name,value] of Object.entries({
   document_type:f.document_type,transaction_date:f.date,
   amount:f.amount?currency(f.amount):null,
   counterparty:f.issuer,document_number:f.document_number,
   payment_method:f.payment_method,category:f.category,description:f.description
 })){set(r,name,value);}
 r.key=String(f.access_key||'');
 const missing=['amount','date','category'].filter(k=>!f[k]);
 const warnings=Array.isArray(f.warnings)?f.warnings:[];
 r.card.querySelector('[data-warnings]').textContent=(missing.length?'Conferir/preencher: '+missing.join(', ')+'. ':'')+warnings.join(' · ');
}
async function optimizeForVision(file){
  // Send a smaller image to the IA without changing the original used as proof.
  if(!/^image\/(jpeg|png|webp)$/i.test(file.type) || file.size<1500000 || !window.createImageBitmap)
    return file;
  try{
    const bitmap=await createImageBitmap(file);
    const ratio=Math.min(1,2800/Math.max(bitmap.width,bitmap.height));
    if(ratio===1&&file.size<3500000){bitmap.close();return file;}
    const canvas=document.createElement('canvas');
    canvas.width=Math.max(1,Math.round(bitmap.width*ratio));
    canvas.height=Math.max(1,Math.round(bitmap.height*ratio));
    canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);
    bitmap.close();
    const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.9));
    canvas.width=0;canvas.height=0;
    if(blob && blob.size<file.size)
      return new File([blob],file.name.replace(/\.[^.]+$/,'.jpg'),{type:'image/jpeg',lastModified:Date.now()});
  }catch(_error){/* Older Android: fall back to untouched original. */}
  return file;
}
async function scan(r,mode){
 if(r.removed||r.state==='saved')return;
 scans++;
 setStatus(r,'reading',mode==='precise'?'IA reanalisando com maior precisão...':'IA lendo este cupom...');
 const body=new FormData();
 body.append('_csrf',token);body.append('action','scan_file');body.append('mode',mode);
 const aiFile=await optimizeForVision(r.file);
 if(aiFile.size>10*1024*1024){
   setStatus(r,'manual','Arquivo grande para IA. Comprima a foto ou preencha manualmente.');
   scans--;return;
 }
 body.append('document_file',aiFile,aiFile.name);
 try{
   const response=await fetch('/finance-scan.php',{method:'POST',body,credentials:'same-origin',
       headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const data=await response.json().catch(()=>null);
   if(r.removed)return;
   if(!response.ok||!data?.ok){
     setStatus(r,'manual','Não foi possível ler pela IA: '+String(data?.message||'servidor não respondeu')+'. Preencha manualmente.');
     return;
   }
   // The stored proof is the ORIGINAL. Duplicates must use its hash,
   // not the possibly compressed photo sent to the AI.
   r.hash=r.originalHash||data.file_hash||'';
   r.serverMatches=Array.isArray(data.duplicates)?data.duplicates:[];
   r.serverBlocked=Boolean(data.blocking);
   fill(r,data.fields||{});
   dedupe();
   if(!r.blocked)setStatus(r,'ready','Leitura concluída. Confira os dados.');
 }catch(_e){
   if(!r.removed)setStatus(r,'manual','Conexão com a IA falhou. Use Reanalisar ou preencha manualmente.');
 }finally{scans--;summary();}
}
function queueScan(r,precise=false){
 const job=()=>scan(r,precise?'precise':'fast');
 // Two independent workers: faster than serial reading, avoids sending
 // all eight images to the provider at the same time.
 const slot=scanIndex++%scanPools.length;
 const running=scanPools[slot].then(job);
 scanPools[slot]=running.catch(()=>{});
}
function fileAllowed(f){
 const ext=f.name.toLowerCase().split('.').pop();
 return ['jpg','jpeg','png','webp','pdf','heic','heif'].includes(ext)&&f.size>0&&f.size<=maxBytes;
}
async function addFiles(files){
 let added=0,rejected=0;
 for(const file of Array.from(files||[])){
   if(rows.length>=max||!fileAllowed(file)){rejected++;continue;}
   try{
     // Own a stable copy, not the Android file-picker URI.
     const buffer=await file.arrayBuffer();
     const digest=await hashOriginal(buffer);
     const stable=new File([buffer],file.name,{type:file.type,lastModified:Date.now()});
     const r={id:++nextId,file:stable,hash:digest,originalHash:digest,key:'',requestKey:randomKey(),
       state:'queued',serverBlocked:false,blocked:false,serverMatches:[],
       matches:[],removed:false,accountTouched:false};
     if(!rows.length)list.innerHTML='';
     rows.push(r);list.append(build(r));queueScan(r);added++;
   }catch(_e){rejected++;}
 }
 statusAll.textContent=added+' cupom(ns) adicionado(s).'+(rejected?' '+rejected+' não incluído(s) por limite de 8, formato ou tamanho da hospedagem.':'')+
   ' Confira cada comprovante e registre quando estiver pronto.';
 dedupe();summary();
}
gallery.addEventListener('change',()=>{addFiles(gallery.files);gallery.value='';});
camera.addEventListener('change',()=>{addFiles(camera.files);camera.value='';});
accountDefault.addEventListener('change',()=>{
 for(const r of rows){
   if(r.state==='saved'||r.accountTouched)continue;
   field(r,'account_id').value=accountDefault.value;
 }
});
function errorOf(r){
 const f=fields(r);
 if(r.blocked)return 'Cupom duplicado.';
 if(!/^\d{4}-\d{2}-\d{2}$/.test(f.transaction_date))return 'Confira a data.';
 const value=f.amount.includes(',')?f.amount.replace(/\./g,'').replace(',','.'):f.amount;
 if(!/^\d+(\.\d{1,2})?$/.test(value)||!(Number(value)>0)||Number(value)>999999999.99)return 'Informe o valor correto.';
 if(!f.category)return 'Selecione a categoria contábil cadastrada pela igreja.';
 if(!f.description)return 'Preencha a descrição.';
 return '';
}
async function serverDedupe(r){
 const f=fields(r),data=new FormData();
 data.append('_csrf',token);data.append('action','duplicates');
 for(const [key,value] of Object.entries({
   file_hash:r.hash,document_number:f.document_number,issuer:f.counterparty,amount:f.amount,date:f.transaction_date
 }))data.append(key,value||'');
 const response=await fetch('/finance-scan.php',{method:'POST',body:data,credentials:'same-origin',
     cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
 const result=await response.json().catch(()=>null);
 if(!response.ok||!result?.ok)throw new Error(result?.message||'Verificação não respondeu.');
 r.serverMatches=Array.isArray(result.duplicates)?result.duplicates:[];
 r.serverBlocked=Boolean(result.blocking);
 dedupe();
}
async function save(r){
 const err=errorOf(r);
 if(err){setStatus(r,'error',err);return false;}
 try{await serverDedupe(r);}
 catch(e){setStatus(r,'error','Não foi possível verificar duplicidades: '+(e.message||'erro')+'. Nenhum valor registrado.');return false;}
 if(r.blocked){setStatus(r,'duplicate','Cupom duplicado: não enviado.');return false;}
 const data=new FormData();
 data.append('_csrf',token);data.append('action','entry');
 data.append('request_key',r.requestKey);data.append('document_expected','1');
 data.append('proof_method','upload');
 for(const [name,value] of Object.entries(fields(r)))data.append(name,value);
 data.append('document_file',r.file,r.file.name);
 setStatus(r,'saving','Salvando cupom e lançamento...');
 let response,result;
 try{
   response=await fetch('/finance.php',{method:'POST',body:data,credentials:'same-origin',
     headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   result=await response.json().catch(()=>null);
 }catch(_e){
   setStatus(r,'uncertain','Resposta não recebida. Confira a Tesouraria; a mesma chave será usada em nova tentativa.');
   return false;
 }
 if(!response.ok||!result?.ok){
   setStatus(r,'error',String(result?.message||'Servidor não confirmou o registro (HTTP '+response.status+').'));
   return false;
 }
 r.entryId=result.entry_id;
 const linked=Boolean(result.document_attached);
 setStatus(r,'saved',linked?'Lançamento e anexo registrados.':'Lançamento registrado. Confira o anexo nos detalhes.');
 r.card.querySelector('[data-rescan]').disabled=true;
 r.card.querySelector('[data-remove]').disabled=true;
 r.card.querySelectorAll('[data-field]').forEach(el=>el.disabled=true);
 const a=document.createElement('a');
 a.href='/finance-entry-edit.php?id='+encodeURIComponent(String(r.entryId||''));
 a.target='_blank';a.rel='noopener';
 a.textContent=linked?'Ver lançamento e anexo':'Abrir lançamento para conferir anexo';
 r.card.querySelector('[data-result]').append(a);
 return true;
}
submit.addEventListener('click',async()=>{
 if(busy)return;
 if(scans||rows.some(r=>['queued','reading'].includes(r.state))){
   statusAll.textContent='Aguarde a leitura de todos os cupons antes de registrar.';
   return;
 }
 const unsaved=rows.filter(r=>r.state!=='saved'&&!r.removed);
 const valid=[];
 let failures=0;
 for(const r of unsaved){
   const err=errorOf(r);
   if(err){failures++;setStatus(r,r.blocked?'duplicate':'error',err);}
   else valid.push(r);
 }
 if(!valid.length){statusAll.textContent='Corrija os campos pendentes para registrar os cupons.';return;}
 busy=true;summary();let successes=0;
 for(let i=0;i<valid.length;i++){
   statusAll.textContent='Registrando cupom '+(i+1)+' de '+valid.length+'...';
   if(await save(valid[i]))successes++;else failures++;
 }
 busy=false;summary();
 statusAll.textContent=successes+' cupom(ns) registrados separadamente; '+failures+
  ' pendente(s). Os registros confirmados não são enviados novamente.';
});
summary();
})();