/* Igreja Master 3.7.58: optional MANUAL supporting files on one finance entry.
 * The first document retains its AI scan; secondary documents do NOT
 * trigger OCR, do NOT change entry fields, and never post a second amount.
 */
(()=>{
  'use strict';
  const form=document.querySelector('form[data-finance-attachment][data-fiscal-autofill]');
  const section=form?.querySelector('[data-extra-attachments]');
  if(!form||!section)return;
  const picker=section.querySelector('[data-extra-attachment-picker]');
  const list=section.querySelector('[data-extra-attachment-list]');
  const output=section.querySelector('[data-extra-attachment-status]');
  const tokens=form.querySelector('input[name="extra_document_tokens"]');
  if(!picker||!list||!output||!tokens)return;
  const maximum=8;
  const maxFileBytes=Number(section.dataset.maxFileBytes)||12*1024*1024;
  const items=[];
  let seq=0,stagingQueue=Promise.resolve();
  const announce=(message,warning=false)=>{
    output.textContent=message;
    output.style.color=warning?'#a02424':'';
  };
  function sync(){
    tokens.value=JSON.stringify(items.filter(x=>!x.removed&&x.state==='ready').map(x=>x.token));
    const active=items.filter(x=>!x.removed);
    if(!active.length){
      list.innerHTML='<li>Nenhum documento adicional selecionado.</li>';
      announce('Os anexos daqui são manuais. Não haverá leitura por IA nem novo valor.');
      return;
    }
    announce(active.filter(x=>x.state==='ready').length+' documento(s) adicional(is) prontos. '+
      (active.some(x=>x.state==='error')?'Existe arquivo com erro: remova ou tente reenviar. ':
       active.some(x=>x.state==='queued'||x.state==='uploading')?'Preparando os demais arquivos... ':
       'Todos serão anexados ao mesmo lançamento. '));
  }
  function createRow(item){
    if(list.children.length===1&&items.filter(x=>!x.removed).length===1)list.innerHTML='';
    const li=document.createElement('li');
    const info=document.createElement('div');
    const title=document.createElement('strong');title.textContent=item.file.name;
    const small=document.createElement('small');small.textContent=' · '+(item.file.size/1048576).toFixed(2)+' MB';
    const state=document.createElement('div');state.textContent='Aguardando envio seguro...';
    const controls=document.createElement('div');
    const retry=document.createElement('button');retry.type='button';retry.className='btn btn-light';retry.textContent='Tentar novamente';retry.hidden=true;
    const remove=document.createElement('button');remove.type='button';remove.className='btn btn-light';remove.textContent='Remover';
    controls.style.display='flex';controls.style.gap='6px';
    controls.append(retry,remove);
    info.append(title,small,state);
    li.append(info,controls);
    list.append(li);
    item.row=li;item.stateElement=state;item.retry=retry;
    retry.addEventListener('click',()=>queueStage(item));
    remove.addEventListener('click',()=>{
      item.removed=true;li.remove();sync();
    });
  }
  function mark(item,state,message){
    item.state=state;
    if(!item.removed){
      item.stateElement.textContent=message;
      item.stateElement.className=state==='ready'?'extra-ready':state==='error'?'extra-error':'';
      item.retry.hidden=state!=='error';
    }
    sync();
  }
  async function stage(item){
    if(item.removed)return;
    mark(item,'uploading','Enviando arquivo com segurança (sem leitura por IA)...');
    try{
      const data=new FormData();
      const csrf=form.querySelector('input[name="_csrf"]');
      if(csrf)data.append(csrf.name,csrf.value);
      data.append('action','stage_item_file');
      data.append('document_file',item.file,item.file.name);
      const response=await fetch('/finance-group.php',{
        method:'POST',body:data,credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'
      });
      const result=await response.json().catch(()=>null);
      if(!response.ok||!result?.ok||typeof result.token!=='string')
        throw new Error(result?.message||'O servidor não confirmou o arquivo.');
      if(item.removed)return; // Abandoned staging token will expire automatically.
      item.token=result.token;
      mark(item,'ready','Anexo manual pronto para o mesmo lançamento.');
    }catch(e){
      if(!item.removed)mark(item,'error','Falha no envio: '+(e?.message||'erro desconhecido'));
    }
  }
  function queueStage(item){
    if(item.removed)return;
    mark(item,'queued','Na fila de envio seguro...');
    const current=stagingQueue.then(()=>stage(item));
    stagingQueue=current.catch(()=>{});
  }
  async function addFiles(files){
    let refused=0;
    for(const source of files){
      if(items.filter(x=>!x.removed).length>=maximum){refused++;continue;}
      if(source.size===0||source.size>maxFileBytes){refused++;continue;}
      const name=source.name||'documento';
      const type=(source.type||'').toLowerCase();
      if(!/(\.pdf|\.png|\.jpe?g|\.webp|\.heic|\.heif)$/i.test(name)
        && !['application/pdf','image/jpeg','image/png','image/webp','image/heic','image/heif'].includes(type)){
        refused++;continue;
      }
      try{
        // A snapshot stays usable when Android revokes the gallery URI.
        const buffer=await source.arrayBuffer();
        const stable=new File([buffer],name,{type:source.type||'application/octet-stream',lastModified:Date.now()});
        const item={id:++seq,file:stable,state:'queued',token:'',removed:false};
        items.push(item);
        createRow(item);
        queueStage(item);
      }catch(e){refused++;}
    }
    sync();
    if(refused)announce(refused+' documento(s) não adicionado(s). Limite de 8, tamanho máximo configurado na hospedagem e tipos PDF/foto.',true);
  }
  picker.addEventListener('change',async()=>{
    const files=Array.from(picker.files||[]);
    await addFiles(files);
    picker.value='';
  });
  // This capture handler runs before Android snapshot uploader's bubbling
  // submit handler. Never save while an extra document is still pending.
  form.addEventListener('submit',event=>{
    const active=items.filter(x=>!x.removed);
    const waiting=active.some(x=>x.state==='queued'||x.state==='uploading');
    const failed=active.some(x=>x.state==='error');
    if(waiting||failed){
      event.preventDefault();event.stopImmediatePropagation();
      announce(waiting?'Conclua o envio dos documentos adicionais antes de registrar.':
        'Um documento adicional falhou. Tente novamente ou remova o arquivo antes de registrar.',true);
      section.scrollIntoView({block:'center',behavior:'smooth'});
      return;
    }
    sync();
  },true);
  sync();
})();