/* Igreja Master 3.7.9 — optional client-side OCR.
 * No upload to OCR providers. Tesseract.js is loaded only after user clicks.
 * OCR is a suggestion and never silently authorizes financial posting.
 */
(function(){
  'use strict';
  const form=document.getElementById('acc-upload-form');
  if(!form)return;
  const fileInput=form.querySelector('#acc-source');
  const button=document.getElementById('acc-read-ocr');
  const status=document.getElementById('acc-ocr-state');
  const preview=document.getElementById('acc-item-preview');
  const hidden=form.querySelector('#acc-items-json');
  const ocrField=form.querySelector('#acc-ocr-text');
  const maxChars=25000;
  const setStatus=(s)=>{status.textContent=s;};
  const field=(name,value)=>{
    const el=form.elements.namedItem(name);
    if(el&&value!==undefined&&value!==null&&String(value).trim()!==''&&!String(el.value).trim())el.value=String(value);
  };
  function itemsToJson(root){
    const values=[];
    root.querySelectorAll('.acc-item').forEach(row=>{
      const name=row.querySelector('[data-item-name]')?.value||'';
      const amount=row.querySelector('[data-item-amount]')?.value||'';
      const category=row.querySelector('[data-item-category]')?.value||'';
      if(name.trim())values.push({name:name.trim().slice(0,160),amount:amount.trim().slice(0,30),category:category.trim().slice(0,160)});
    });
    return JSON.stringify(values);
  }
  function bindItems(container,target){
    container.addEventListener('input',()=>{target.value=itemsToJson(container);});
    target.value=itemsToJson(container);
  }
  document.querySelectorAll('.acc-confirm-form').forEach(f=>{
    const container=f.querySelector('[data-accounting-items]');
    const target=f.querySelector('input[name=items_json]');
    if(container&&target)bindItems(container,target);
  });
  function loadTesseract(){
    if(window.Tesseract)return Promise.resolve();
    return new Promise((resolve,reject)=>{
      const script=document.createElement('script');
      script.src='https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
      script.crossOrigin='anonymous';
      script.onload=()=>window.Tesseract?resolve():reject(new Error('Biblioteca OCR indisponível'));
      script.onerror=()=>reject(new Error('Não foi possível baixar a biblioteca OCR. Preencha manualmente.'));
      document.head.appendChild(script);
    });
  }
  function itemPreview(items){
    preview.replaceChildren();
    if(!Array.isArray(items)||!items.length){hidden.value='[]';return;}
    const title=document.createElement('h3');title.textContent='Itens identificados — revise as categorias';preview.appendChild(title);
    const caption=document.createElement('p');caption.className='muted';caption.textContent='Confira cada item. O sistema apenas sugere a categoria; não substitui a conferência.';preview.appendChild(caption);
    items.slice(0,80).forEach(it=>{
      const row=document.createElement('div');row.className='acc-item';
      const values=[['name','Nome do item'],['amount','Valor'],['category','Categoria']];
      values.forEach(([name,label])=>{
        const input=document.createElement('input');
        input.className='input';input.setAttribute('aria-label',label);input.setAttribute('data-item-'+name,'');
        input.value=String(it[name]||'').slice(0,name==='name'?160:80);
        if(name==='category')input.setAttribute('list','accounting-categories');
        row.appendChild(input);
      });
      preview.appendChild(row);
    });
    bindItems(preview,hidden);
  }
  button.addEventListener('click',async()=>{
    const file=fileInput.files?.[0];
    if(!file){setStatus('Selecione uma foto do cupom primeiro.');return;}
    if(!/^image\/(jpeg|png|webp)$/i.test(file.type)){setStatus('Leitura automática disponível somente para foto. PDF/XML pode ser arquivado e conferido.');return;}
    if(file.size>12*1024*1024){setStatus('Foto excede 12 MB.');return;}
    button.disabled=true;
    let worker;
    try{
      setStatus('Carregando leitor de imagem…');
      await loadTesseract();
      setStatus('Lendo cupom no navegador…');
      worker=await window.Tesseract.createWorker('por');
      const result=await worker.recognize(file);
      const raw=String(result.data?.text||'').slice(0,maxChars);
      if(raw.trim().length<12)throw new Error('A foto não pôde ser lida. Tente uma imagem mais nítida.');
      ocrField.value=raw;
      setStatus('Identificando fornecedor, valores e itens…');
      const body=new FormData();
      const csrf=form.querySelector('input[type=hidden][name*=csrf]');
      if(csrf)body.append(csrf.name,csrf.value);
      body.append('ocr_text',raw);
      const resp=await fetch('/accounting-parse.php',{method:'POST',body,credentials:'same-origin',headers:{'Accept':'application/json'}});
      const parsed=await resp.json();
      if(!resp.ok)throw new Error(parsed.error||'Não foi possível interpretar a leitura.');
      field('supplier_name',parsed.supplier);
      field('supplier_cnpj',parsed.cnpj);
      field('document_number',parsed.number);
      field('issued_on',parsed.issued_on);
      field('total',parsed.total);
      field('confirmed_category',parsed.category);
      itemPreview(parsed.items);
      setStatus('Leitura concluída. Confira data, valor e categorias antes de salvar.');
      if(parsed.warnings?.length){const p=document.createElement('p');p.className='muted';p.textContent=parsed.warnings.join(' ');preview.appendChild(p);}
    }catch(e){
      setStatus(e?.message||'Falha na leitura. Os dados podem ser preenchidos manualmente.');
    }finally{
      if(worker)try{await worker.terminate();}catch(_){}
      button.disabled=false;
    }
  });
})();
