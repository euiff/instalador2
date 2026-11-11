/* Igreja Master 3.7.41: preserve a readable snapshot of financial attachments.
 * Chrome ERR_UPLOAD_FILE_CHANGED can happen after the file picker returns a transient
 * Android content URI. Read promptly; upload the immutable in-memory copy.
 */
(()=>{
 'use strict';
 const pendingByInput=new WeakMap();
 const relevant=(input)=>input instanceof HTMLInputElement&&input.type==='file'&&
  ['document_file','signed_file','test_file'].includes(input.name);
 function bind(input){
  if(!relevant(input)||input.dataset.imFrozenBound)return;
  input.dataset.imFrozenBound='1';
  let pending=Promise.resolve(),ok=false;
  const notice=document.createElement('small');
  notice.setAttribute('role','status');notice.style.display='block';
  notice.style.marginTop='5px';input.insertAdjacentElement('afterend',notice);
  input.addEventListener('change',()=>{
   ok=false;
   const file=input.files?.[0];
   if(!file){notice.textContent='';return;}
   if(file.size>12*1024*1024){notice.textContent='Arquivo acima de 12 MB. Escolha uma cópia menor.';input.value='';return;}
   notice.textContent='Preparando arquivo para envio seguro…';
   pending=(async()=>{
    try{
     const content=await file.arrayBuffer();
     if(!content.byteLength)throw new Error('empty');
     const copy=new File([content],file.name,{type:file.type||'application/octet-stream',lastModified:Date.now()});
     const transfer=new DataTransfer();transfer.items.add(copy);
     input.files=transfer.files;
     ok=true;notice.textContent='Arquivo pronto para anexar: '+file.name;
    }catch(e){
     ok=false;input.value='';
     notice.textContent='Não foi possível ler esta foto ou PDF. Abra o arquivo no celular, salve uma cópia na pasta Downloads e selecione novamente.';
    }
   })();
   pendingByInput.set(input,pending);
  });
  const form=input.form;if(!form||form.dataset.imAttachmentGuard)return;
  form.dataset.imAttachmentGuard='1';
  let bypass=false;
  form.addEventListener('submit',async e=>{
   if(bypass)return;
   const chosen=[...form.querySelectorAll('input[type=file]')].filter(relevant);
   if(!chosen.some(el=>el.files?.length))return;
   e.preventDefault();
   await Promise.all(chosen.map(el=>pendingByInput.get(el)||Promise.resolve()));
   const invalid=chosen.find(el=>el.files?.length===0||!el.files?.[0]);
   if(invalid){invalid.focus();return;}
   bypass=true;
   try{form.requestSubmit(e.submitter||undefined);}finally{bypass=false;}
  });
 }
 const scan=(root)=>{if(root.matches?.('input[type=file]'))bind(root);root.querySelectorAll?.('input[type=file]').forEach(bind)};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>scan(document));else scan(document);
 new MutationObserver(records=>records.forEach(r=>r.addedNodes.forEach(n=>{if(n.nodeType===1)scan(n)})))
   .observe(document.documentElement,{childList:true,subtree:true});
})();
