/* Igreja Master 3.7.42 — robust attachment uploads on Android WebView.
 * Snapshot File into memory when selected. Submit via fetch FormData with snapshot
 * instead of Chrome native multipart uploader, avoiding ERR_UPLOAD_FILE_CHANGED.
 * Browser and session cookies remain same-origin; backend CSRF and validations remain.
 */
(()=>{
 'use strict';
 const names=new Set(['document_file','signed_file','test_file']);
 const snapshots=new WeakMap();
 const pending=new WeakMap();
 const failed=new WeakSet();
 const isFileInput=el=>el instanceof HTMLInputElement&&el.type==='file'&&names.has(el.name);
 const max=12*1024*1024;
 const relevant=form=>[...form.querySelectorAll('input[type="file"]')].filter(isFileInput);
 function prepare(input){
   if(input.dataset.uploadSnapshotBound==='1')return;
   input.dataset.uploadSnapshotBound='1';
   const state=document.createElement('small');state.setAttribute('role','status');state.className='muted';
   state.style.display='block';state.style.marginTop='5px';input.insertAdjacentElement('afterend',state);
   input.addEventListener('change',()=>{
      failed.delete(input);snapshots.delete(input);
      const file=input.files?.[0];
      if(!file){state.textContent='';return;}
      state.textContent='Preparando nota ou recibo…';
      const task=(async()=>{
        try{
          if(file.size>max)throw new Error('large');
          const bytes=await file.arrayBuffer();
          if(!bytes.byteLength)throw new Error('empty');
          const frozen=new File([bytes],file.name,{type:file.type||'application/octet-stream',lastModified:Date.now()});
          snapshots.set(input,frozen);
          state.textContent='Arquivo pronto: '+file.name;
        }catch(err){
          failed.add(input);snapshots.delete(input);
          state.textContent=err?.message==='large'?'Arquivo acima de 12 MB. Escolha uma cópia menor.':'Não foi possível ler o arquivo. Salve uma cópia na pasta Downloads e selecione novamente.';
        }
      })();
      pending.set(input,task);
   });
 }
 async function sendForm(form,submitter){
   const files=relevant(form);
   if(!files.some(el=>el.files?.length||failed.has(el)))return false;
   await Promise.all(files.map(el=>pending.get(el)||Promise.resolve()));
   const invalid=files.find(el=>failed.has(el)||(el.required&&!snapshots.has(el)));
   if(invalid){invalid.focus();return true;}
   // IMPORTANT: new FormData(form) tries to open the ORIGINAL Android content
   // URI and itself raises ERR_UPLOAD_FILE_CHANGED before our snapshot is used.
   // Exclude native file controls while serializing ordinary fields.
   const disabled=files.map(el=>[el,el.disabled]);
   let payload;
   try{
     for(const [el] of disabled)el.disabled=true;
     payload=new FormData(form);
   }finally{
     for(const [el,wasDisabled] of disabled)el.disabled=wasDisabled;
   }
   for(const input of files){
     const file=snapshots.get(input);
     if(file)payload.append(input.name,file,file.name);
   }
   if(submitter?.name)payload.append(submitter.name,submitter.value||'');
   const button=submitter instanceof HTMLElement?submitter:form.querySelector('button[type="submit"]');
   const oldText=button?.textContent;
   const status=form.querySelector('[data-upload-result]');
   if(status)status.textContent='Enviando arquivo…';
   if(button){button.disabled=true;button.textContent='Enviando documento…';}
   let done=false;
   try{
     const response=await fetch(form.action||location.href,{
       method:'POST',body:payload,credentials:'same-origin',redirect:'follow',
       cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}
     });
     const contentType=response.headers.get('content-type')||'';
     if(contentType.includes('application/json')){
       const data=await response.json();
       if(!response.ok||data.ok!==true)throw new Error(data.message||'Servidor recusou o documento.');
       done=true;
       if(status)status.textContent='Documento anexado com sucesso. Atualizando a lista…';
       window.location.reload();
     }else if(response.redirected&&response.url.includes('/login')){
       throw new Error('Sua sessão expirou. Entre novamente para enviar.');
     }else{
       throw new Error('O servidor não confirmou o anexo (HTTP '+response.status+'). Confira o diagnóstico de upload.');
     }
   }catch(err){
     const message=err?.message||'Falha na comunicação com o servidor.';
     if(status){status.textContent='Falha no envio: '+message;status.style.color='#9a2424';}
     else alert('Falha no envio: '+message);
   }finally{
     if(!done&&button){button.disabled=false;button.textContent=oldText;}
   }
   return true;
 }
 const forms=new WeakSet();
 function scan(root){
   const inputs=[];
   if(root instanceof HTMLInputElement&&isFileInput(root))inputs.push(root);
   root.querySelectorAll?.('input[type=file]').forEach(el=>{if(isFileInput(el))inputs.push(el)});
   for(const input of inputs){
     prepare(input);
     const form=input.form;
     if(!form||forms.has(form))continue;
     forms.add(form);
     form.addEventListener('submit',ev=>{
       if(form.dataset.imUploadPending==='1'){ev.preventDefault();return;}
       const inputs=relevant(form);
       if(!inputs.some(el=>el.files?.length||failed.has(el)))return;
       ev.preventDefault();
       form.dataset.imUploadPending='1';
       sendForm(form,ev.submitter).finally(()=>{delete form.dataset.imUploadPending});
     },false);
   }
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>scan(document));else scan(document);
 new MutationObserver(records=>{
  for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)scan(node);
 }).observe(document.documentElement,{childList:true,subtree:true});
})();
