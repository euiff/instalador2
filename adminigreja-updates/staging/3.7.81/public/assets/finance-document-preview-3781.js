/* Igreja Master 3.7.81 — pré-visualização real, sem download automático.
 * O arquivo é buscado com a sessão ativa e renderizado em memória no aparelho.
 * Não publica documentos nem envia dados a serviços externos. PDF.js apenas
 * carrega o código JavaScript; os bytes do documento ficam no próprio navegador.
 */
(()=>{
'use strict';
const dialog=document.getElementById('im-finance-document-modal');
if(!dialog)return;
const display=dialog.querySelector('[data-im-preview-display]');
const title=dialog.querySelector('#im-finance-document-modal-title');
const status=dialog.querySelector('[data-im-preview-status]');
const download=dialog.querySelector('[data-im-preview-download]');
const urlPattern=/^\/finance-document\.php\?id=[a-f0-9-]{36}$/i;
let controller=null,objectUrl=null,serial=0,documentBytes=null,documentType='';
function notice(s){if(status){status.textContent=s;status.hidden=!s;}}
function release(){
 serial++;if(controller){controller.abort();controller=null;}
 if(objectUrl){URL.revokeObjectURL(objectUrl);objectUrl=null;}
 if(display)display.replaceChildren();
 if(download){download.removeAttribute('href');download.removeAttribute('download');download.hidden=true;}
 documentBytes=null;documentType='';notice('');
}
function close(){if(dialog.open)dialog.close();release();}
for(const btn of dialog.querySelectorAll('[data-finance-preview-close]'))btn.addEventListener('click',e=>{e.preventDefault();close();});
dialog.addEventListener('close',release);
dialog.addEventListener('click',e=>{if(e.target===dialog)close();});
function typeOfBytes(a,header){
 if(a.length>=5&&String.fromCharCode(...a.subarray(0,5))==='%PDF-')return 'application/pdf';
 if(a.length>=4&&a[0]===0x89&&a[1]===0x50&&a[2]===0x4e&&a[3]===0x47)return 'image/png';
 if(a.length>=3&&a[0]===0xff&&a[1]===0xd8&&a[2]===0xff)return 'image/jpeg';
 if(a.length>=6&&String.fromCharCode(...a.subarray(0,6)).startsWith('GIF8'))return 'image/gif';
 if(a.length>=12&&String.fromCharCode(...a.subarray(0,4))==='RIFF'&&String.fromCharCode(...a.subarray(8,12))==='WEBP')return 'image/webp';
 return '';
}
function script(src){
 return new Promise((resolve,reject)=>{
  if(window.pdfjsLib)return resolve(window.pdfjsLib);
  const el=document.createElement('script');el.src=src;el.async=true;
  el.onload=()=>window.pdfjsLib?resolve(window.pdfjsLib):reject(new Error('Leitor PDF indisponível'));
  el.onerror=()=>reject(new Error('Não foi possível carregar o leitor PDF'));
  document.head.append(el);
 });
}
async function drawPdf(bytes,token){
 const pdfjs=await script('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js');
 pdfjs.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
 if(token!==serial)return;
 const pdf=await pdfjs.getDocument({data:bytes.slice(),useSystemFonts:true}).promise;
 if(token!==serial){await pdf.destroy();return;}
 notice('PDF com '+pdf.numPages+' página(s). Role para visualizar.');
 for(let n=1;n<=pdf.numPages;n++){
  if(token!==serial)break;
  const page=await pdf.getPage(n);
  const scale=Math.min(2,Math.max(0.6,(display.clientWidth-20)/page.getViewport({scale:1}).width));
  const viewport=page.getViewport({scale});
  const canvas=document.createElement('canvas');
  canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
  canvas.style.width='100%';canvas.style.maxWidth=viewport.width+'px';canvas.style.height='auto';
  canvas.setAttribute('aria-label','Página '+n+' de '+pdf.numPages);
  display.append(canvas);
  await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
 }
 await pdf.destroy();
}
async function openPreview(url,name){
 release();const token=serial;
 title.textContent=name||'Documento do lançamento';
 dialog.showModal();notice('Carregando documento...');
 controller=new AbortController();
 try{
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Accept':'application/pdf,image/*,application/octet-stream'}});
  if(!response.ok)throw new Error('Não foi possível abrir o documento (HTTP '+response.status+').');
  const raw=await response.arrayBuffer();
  if(token!==serial)return;
  const bytes=new Uint8Array(raw);
  const mime=typeOfBytes(bytes,response.headers.get('content-type')||'');
  if(!mime)throw new Error('O servidor não retornou um PDF ou imagem válida. Verifique o arquivo anexado.');
  documentBytes=bytes;documentType=mime;
  objectUrl=URL.createObjectURL(new Blob([bytes],{type:mime}));
  if(download){download.href=objectUrl;download.download=(name&&name.includes('.')?name:'comprovante.'+(mime==='application/pdf'?'pdf':'png'));download.hidden=false;}
  display.replaceChildren();
  if(mime==='application/pdf'){
   try{await drawPdf(bytes,token);}
   catch(err){
    if(token!==serial)return;
    notice('O leitor de PDF não carregou. Tente abrir pelo Chrome atualizado ou verifique a conexão.');
    const iframe=document.createElement('iframe');iframe.title='Documento PDF';iframe.src=objectUrl;iframe.style.cssText='width:100%;height:65dvh;border:0';display.append(iframe);
   }
  }else{
   const img=document.createElement('img');img.alt=name||'Comprovante';
   img.src=objectUrl;img.style.cssText='display:block;max-width:100%;width:auto;height:auto;max-height:none;margin:auto;object-fit:contain';
   display.append(img);notice('');
  }
 }catch(err){
  if(token!==serial||err.name==='AbortError')return;
  notice(err.message||'Falha na visualização.');
 }
}
document.addEventListener('click',event=>{
 const button=event.target.closest('[data-finance-preview]');
 if(!button)return;
 const url=button.getAttribute('data-finance-preview')||'';
 if(!urlPattern.test(url))return;
 event.preventDefault();
 const name=String(button.getAttribute('data-finance-preview-name')||'').trim();
 if(typeof dialog.showModal==='function')openPreview(url,name);
 else window.location.assign('/finance-document.php?id='+encodeURIComponent(url.split('id=')[1]));
});
})();