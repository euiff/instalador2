/* Igreja Master 3.7.74 — local OCR without external AI API.
 * PDF: PDF.js text layer first, image pages with Tesseract.js WebAssembly.
 * Images: Tesseract.js Portuguese local assets. No document is sent to an
 * external OCR API. Caller sends recognized text to its own authenticated PHP.
 */
(()=>{
 'use strict';
 const ROOT='/assets/local-ocr/';
 const LIMIT_PAGES=4;
 const MAX_SIDE=2400;
 const loaded={};
 function loadScript(src,globalName){
   if(window[globalName])return Promise.resolve(window[globalName]);
   if(!loaded[src]){
     loaded[src]=new Promise((resolve,reject)=>{
       const script=document.createElement('script');
       script.src=src;script.async=true;
       script.onload=()=>window[globalName] ? resolve(window[globalName]) :
         reject(new Error('O módulo local de leitura não iniciou ('+globalName+').'));
       script.onerror=()=>reject(new Error('Não foi possível carregar o leitor local de documentos. Instale novamente a atualização 3.7.74.'));
       document.head.appendChild(script);
     }).catch(error=>{delete loaded[src];throw error});
   }
   return loaded[src];
 }
 const status=(options,message)=>{
   try{options?.onStatus?.(message)}catch(_e){}
 };
 function looksUseful(text){
   return text.length>35 && /(?:R\$|CNPJ|VALOR|NOTA|TOTAL|PIX|RECIBO|TRANSFER|AUTENTICA|DATA|CPF|PAGAM)/i.test(text);
 }
 function isPDF(file){
   return file?.type==='application/pdf'||/\.pdf$/i.test(file?.name||'');
 }
 async function createOCR(options){
   status(options,'Carregando o reconhecedor local de imagens (sem consumir créditos)...');
   const T=await loadScript(ROOT+'tesseract.min.js','Tesseract');
   return await T.createWorker('por',1,{
     workerPath:ROOT+'worker.min.js',
     corePath:ROOT+'core',
     langPath:ROOT+'lang',
     gzip:true,
     cacheMethod:'write',
     logger:message=>{
       if(message?.status==='recognizing text'&&Number.isFinite(message.progress))
         status(options,'Reconhecendo texto no aparelho: '+Math.round(message.progress*100)+'%');
     }
   });
 }
 async function fromImage(file,options){
   const worker=await createOCR(options);
   try{
     status(options,'Lendo a foto localmente...');
     const result=await worker.recognize(file);
     return {
       text:String(result?.data?.text||''),
       source:'image_ocr',
       confidence:Number(result?.data?.confidence)||0,
       pages:1,
       warnings:[]
     };
   }finally{await worker.terminate()}
 }
 async function fromPDF(file,options){
   status(options,'Abrindo o PDF no próprio aparelho...');
   // PDF.js is an ESM build hosted by our own domain (no CDN/API).
   const pdfjs=await import(ROOT+'pdf.mjs');
   pdfjs.GlobalWorkerOptions.workerSrc=ROOT+'pdf.worker.mjs';
   const buffer=await file.arrayBuffer();
   const loading=pdfjs.getDocument({data:new Uint8Array(buffer),isEvalSupported:false});
   let pdf,ocr=null,allText=[],warnings=[];
   try{
     pdf=await loading.promise;
     const count=Math.min(pdf.numPages,LIMIT_PAGES);
     if(pdf.numPages>count)warnings.push('O documento tem '+pdf.numPages+' páginas. Foram lidas as primeiras '+count+'; confira os dados restantes.');
     for(let p=1;p<=count;p++){
       status(options,'Lendo página '+p+' de '+count+' sem IA externa...');
       const page=await pdf.getPage(p);
       const layer=await page.getTextContent();
       const digitalText=layer.items.map(item=>String(item.str||'')+(item.hasEOL?'\n':' ')).join('');
       if(looksUseful(digitalText)){
         allText.push(digitalText);
         page.cleanup();
         continue;
       }
       if(!ocr)ocr=await createOCR(options);
       const viewport=page.getViewport({scale:1});
       const scale=Math.min(2.2,MAX_SIDE/Math.max(viewport.width,viewport.height));
       const renderViewport=page.getViewport({scale:Math.max(1,scale)});
       const canvas=document.createElement('canvas');
       canvas.width=Math.ceil(renderViewport.width);
       canvas.height=Math.ceil(renderViewport.height);
       const ctx=canvas.getContext('2d',{alpha:false});
       if(!ctx)throw new Error('O navegador não conseguiu preparar a imagem do PDF.');
       await page.render({canvasContext:ctx,viewport:renderViewport}).promise;
       try{
         status(options,'Lendo a imagem da página '+p+' de '+count+'...');
         const result=await ocr.recognize(canvas);
         allText.push(String(result?.data?.text||''));
       }finally{
         canvas.width=0;canvas.height=0;
         page.cleanup();
       }
     }
     return {text:allText.join('\n'),source:ocr?'pdf_ocr':'pdf_text',
       pages:count,warnings,confidence:null};
   }finally{
     if(ocr)await ocr.terminate();
     if(pdf)await pdf.destroy();
     else await loading.destroy().catch(()=>{});
   }
 }
 async function sha256(file){
   if(!globalThis.crypto?.subtle)throw new Error('O navegador não oferece SHA-256 seguro para evitar duplicação. Abra o sistema usando HTTPS atualizado.');
   const raw=await file.arrayBuffer();
   const digest=await globalThis.crypto.subtle.digest('SHA-256',raw);
   return Array.from(new Uint8Array(digest),n=>n.toString(16).padStart(2,'0')).join('');
 }
 async function extract(file,options={}){
   if(!file || !file.size)throw new Error('Escolha uma foto ou PDF válido.');
   if(file.size>12*1024*1024)throw new Error('Arquivo acima de 12 MB. Compacte o documento antes de ler.');
   const pdf=isPDF(file);
   const image=/^image\/(jpeg|png|webp|bmp)$/i.test(file.type)||
     /\.(jpe?g|png|webp|bmp)$/i.test(file.name||'');
   if(!pdf&&!image)
     throw new Error('Leitura local aceita PDF, JPG, PNG, WebP e BMP. Para HEIC, converta para JPG no celular.');
   const result=pdf?await fromPDF(file,options):await fromImage(file,options);
   if(!String(result.text||'').trim())
     throw new Error('Não foi possível reconhecer texto neste documento. Faça uma foto mais nítida ou use o botão IA (opcional).');
   status(options,'Conferindo a leitura com o banco da sua igreja...');
   result.fileHash=await sha256(file);
   return result;
 }
 window.IgrejaLocalOCR={extract,isPDF,sha256};
})();
