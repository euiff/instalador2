/* Igreja Master 3.7.57 — one ledger total, detailed offers/coupons & attachments. */
(()=>{
'use strict';
const form=document.querySelector('form[data-grouped-finance]');
if(!form)return;
const list=form.querySelector('[data-group-items]'), template=form.querySelector('[data-group-template]');
const status=form.querySelector('[data-group-status]'),total=form.querySelector('[data-group-total]');
const count=form.querySelector('[data-group-count]'),send=form.querySelector('[data-group-submit]');
const direction=form.querySelector('[data-group-direction]');
const $=selector=>form.querySelector(selector);
let items=[],nextId=0,working=false;
const clean=s=>String(s??'').trim();
const money=s=>{
 const raw=clean(s);
 if(!/^\d[\d.,]*$/.test(raw))return null;
 const norm=raw.includes(',')?raw.replace(/\./g,'').replace(',','.'):raw;
 if(!/^\d+(\.\d{1,2})?$/.test(norm))return null;
 const cents=Math.round(Number(norm)*100);
 return Number.isSafeInteger(cents)&&cents>0&&cents<=99999999999?cents:null;
};
const brl=cents=>(cents/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
const norm=s=>clean(s).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
const field=(row,key)=>row.element.querySelector('[data-field="'+key+'"]');
const val=(row,key)=>clean(field(row,key)?.value);
const set=(row,key,value)=>{
 if(value===undefined||value===null||value==='')return;
 const el=field(row,key);if(!el)return;
 if(el instanceof HTMLSelectElement){
   const match=[...el.options].find(x=>x.value&&!x.disabled&&norm(x.value)===norm(value));
   if(!match)return;el.value=match.value;
 }else el.value=String(value);
 el.dispatchEvent(new Event('input',{bubbles:true}));
};
function refresh(){
 items.forEach((r,i)=>r.element.querySelector('[data-number]').textContent='Item '+(i+1));
 count.textContent=items.length+' itens';
 const amounts=items.map(r=>money(val(r,'amount')));
 const sum=amounts.reduce((n,x)=>n+(x||0),0);
 total.textContent='Total do lançamento: '+brl(sum);
 send.disabled=working||items.some(r=>r.reading||r.uploading)||items.length<2;
}
function error(msg){
 status.className='gerror';status.textContent=msg;
}
function success(msg){
 status.className='gresult';status.textContent=msg;
}
function categoriesForDirection(row){
 const category=field(row,'category');
 for(const option of category.options){
   if(!option.value)continue;
   const scope=option.dataset.direction||'both';
   option.disabled=scope!=='both'&&scope!==direction.value;
   option.hidden=option.disabled;
 }
 if(category.selectedOptions[0]?.disabled)category.value='';
 const member=field(row,'member_id');
 member.disabled=direction.value!=='income';
 if(member.disabled)member.value='';
}
function add(){
 if(items.length>=12){error('Máximo de 12 itens no mesmo lançamento.');return;}
 const node=template.content.firstElementChild.cloneNode(true);
 const row={element:node,id:++nextId,reading:false,uploading:false,stageToken:'',fileVersion:0};
 items.push(row);list.append(node);
 node.querySelector('[data-remove]').addEventListener('click',()=>{
   if(working||row.reading||items.length<=2)return;
   items=items.filter(r=>r!==row);node.remove();refresh();
 });
 for(const el of node.querySelectorAll('[data-field]')){
   el.addEventListener('input',refresh);
   el.addEventListener('change',refresh);
 }
 field(row,'file').addEventListener('change',()=>{row.fileVersion++;stageFile(row);scan(row);});
 categoriesForDirection(row);
 refresh();
}
function optimize(file){
 if(!/^image\/(jpeg|png|webp)$/i.test(file.type)||file.size<1800000||!window.createImageBitmap)
   return Promise.resolve(file);
 return (async()=>{
   try{
     const bitmap=await createImageBitmap(file);
     const ratio=Math.min(1,2900/Math.max(bitmap.width,bitmap.height));
     const canvas=document.createElement('canvas');
     canvas.width=Math.max(1,Math.round(bitmap.width*ratio));
     canvas.height=Math.max(1,Math.round(bitmap.height*ratio));
     canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);
     bitmap.close();
     const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.9));
     canvas.width=0;canvas.height=0;
     if(blob&&blob.size<file.size)
       return new File([blob],file.name.replace(/\.[^.]+$/,'.jpg'),{type:'image/jpeg',lastModified:Date.now()});
   }catch(_e){}
   return file;
 })();
}
async function stageFile(row){
 const version=row.fileVersion;
 const file=field(row,'file').files?.[0];
 row.stageToken='';row.stableFile=null;
 if(!file)return;
 row.uploading=true;refresh();
 const msg=row.element.querySelector('[data-ai-message]');
 try{
   const max=Number(form.dataset.maxUpload)||12*1024*1024;
   if(file.size>max)throw new Error('Arquivo acima do limite permitido pela hospedagem.');
   // Stable in-memory copy: Android may invalidate the source URI while reading.
   const original=new File([await file.arrayBuffer()],file.name,
     {type:file.type||'application/octet-stream',lastModified:Date.now()});
   if(version!==row.fileVersion)return;
   row.stableFile=original;
   const data=new FormData();
   data.append('_csrf',$('input[name="_csrf"]').value);
   data.append('action','stage_item_file');
   data.append('document_file',original,original.name);
   const response=await fetch('/finance-group.php',{method:'POST',body:data,
     credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const result=await response.json().catch(()=>null);
   if(!response.ok||!result?.ok)throw new Error(result?.message||'O servidor recusou a imagem.');
   if(version!==row.fileVersion)return;
   row.stageToken=result.token;
   msg.textContent='Comprovante protegido no servidor. Aguardando conferência do lançamento.';
 }catch(e){
   if(version===row.fileVersion){
     msg.textContent='Arquivo não preparado: '+(e?.message||'erro')+'. Selecione novamente.';
     msg.style.color='#a02424';
   }
 }finally{if(version===row.fileVersion){row.uploading=false;refresh();}}
}
async function scan(row){
 const version=row.fileVersion;
 const file=row.stableFile||field(row,'file').files?.[0],msg=row.element.querySelector('[data-ai-message]');
 if(!file){msg.textContent='';return;}
 if(row.reading)return;
 row.reading=true;refresh();msg.textContent='Lendo documento com IA...';
 try{
   const image=await optimize(file);
   if(image.size>10*1024*1024)throw new Error('Arquivo muito grande para a IA. Compacte a foto ou preencha manualmente.');
   const body=new FormData();
   body.append('_csrf',$('input[name="_csrf"]').value);
   body.append('action','scan_file');
   body.append('mode','fast');
   body.append('document_file',image,image.name);
   const res=await fetch('/finance-scan.php',{method:'POST',body,credentials:'same-origin',
       headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const data=await res.json().catch(()=>null);
   if(version!==row.fileVersion)return;
   if(!res.ok||!data?.ok)throw new Error(data?.message||'O servidor não confirmou a leitura.');
   if(data.blocking){
     msg.textContent='Possível duplicidade. Confira o comprovante antes de registrar.';
     msg.style.color='#a02424';
     return;
   }
   const f=data.fields||{};
   if(f.amount)set(row,'amount',Number(f.amount).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}));
   set(row,'contributor',f.issuer);
   set(row,'document_number',f.document_number);
   set(row,'payment_method',f.payment_method);
   set(row,'document_type',f.document_type);
   set(row,'category',f.category);
   set(row,'description',f.description);
   msg.textContent='Leitura concluída. Confira os dados do item; a data do lançamento principal é definida acima.';
   msg.style.color='#16663b';
 }catch(e){
   if(version===row.fileVersion){
     msg.textContent='Leitura automática indisponível: '+(e?.message||'erro')+'. Preencha manualmente.';
     msg.style.color='#9a3b20';
   }
 }finally{if(version===row.fileVersion){row.reading=false;refresh();}}
}
direction.addEventListener('change',()=>{
 for(const row of items)categoriesForDirection(row);
 $('input[data-group-description]').value=direction.value==='income'?'Ofertas do dia':'Despesas agrupadas';
 refresh();
});
form.querySelectorAll('[data-add-item]').forEach(btn=>btn.addEventListener('click',add));
async function submitGroup(event){
 event.preventDefault();
 if(working||items.some(r=>r.reading||r.uploading))return;
 if(items.length<2){error('Inclua ao menos dois itens.');return;}
 const mainDescription=clean($('[data-group-description]').value);
 if(!mainDescription){error('Informe a descrição principal.');return;}
 const groupItems=[];
 const body=new FormData();
 body.append('_csrf',$('input[name="_csrf"]').value);
 body.append('action','create_group');
 body.append('request_key',$('input[name="request_key"]').value);
 body.append('direction',direction.value);
 body.append('transaction_date',$('[data-group-date]').value);
 body.append('account_id',$('[data-group-account]').value);
 body.append('description',mainDescription);
 let sum=0;
 for(let i=0;i<items.length;i++){
   const row=items[i],cents=money(val(row,'amount'));
   if(cents===null){error('Corrija o valor do item '+(i+1)+'.');return;}
   if(!val(row,'category')){error('Escolha a categoria contábil do item '+(i+1)+'.');return;}
   if(!val(row,'description')){error('Descreva o item '+(i+1)+'.');return;}
   sum+=cents;
   const file=field(row,'file').files?.[0];
   if(file&&!row.stageToken){error('O comprovante do item '+(i+1)+' ainda não foi preparado. Selecione a foto novamente.');return;}
   groupItems.push({
     category:val(row,'category'),amount:(cents/100).toFixed(2),
     description:val(row,'description'),contributor:val(row,'contributor'),
     member_id:val(row,'member_id'),document_number:val(row,'document_number'),
     payment_method:val(row,'payment_method'),document_type:val(row,'document_type'),
     staged_token:row.stageToken||''
   });
 }
 body.append('items_json',JSON.stringify(groupItems));
 if(!window.confirm('Registrar um único lançamento de '+brl(sum)+' com '+items.length+
    ' itens discriminados? Confira os valores e a conta.'))return;
 working=true;refresh();
 status.className='gitem-note';status.textContent='Salvando itens e documentos no servidor...';
 try{
   const response=await fetch(form.action,{method:'POST',body,credentials:'same-origin',
       headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const result=await response.json().catch(()=>null);
   if(!response.ok||!result?.ok){
     error(result?.message||'O servidor não confirmou a operação (HTTP '+response.status+'). Confira o financeiro antes de tentar novamente.');
     return;
   }
   const entryId=result.entry_id||'';
   const link=document.createElement('a');
   link.href='/finance-entry-edit.php?id='+encodeURIComponent(entryId);
   link.textContent='Abrir lançamento agrupado e seus comprovantes';
   link.className='btn btn-primary';
   status.className='gresult';
   status.replaceChildren(document.createTextNode(result.already_registered
     ?'Esse lançamento já tinha sido salvo; nenhum valor foi duplicado. '
     :'Lançamento registrado uma única vez. '),link);
   for(const el of form.querySelectorAll('input,select,textarea,button'))el.disabled=true;
 }catch(e){
   error('Não foi possível confirmar o salvamento. Confira a Tesouraria antes de tentar outra vez. A mesma chave impede criar um lançamento duplicado.');
 }finally{working=false;refresh();}
}
form.addEventListener('submit',submitGroup);
add();add();
})();