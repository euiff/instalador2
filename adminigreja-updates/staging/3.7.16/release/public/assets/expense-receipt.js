/* Igreja Master 3.7.16 — secure expense voucher capture (no remote calls). */
(()=>{
'use strict';
const targetForms='form[data-expense-receipt-form],form[data-expense-sign-form]';

function prepareSignature(form) {
 const canvas=form.querySelector('[data-receipt-canvas]');
 if(!canvas || canvas.dataset.initialized==='1')return;
 canvas.dataset.initialized='1';
 const ctx=canvas.getContext('2d');
 if(!ctx)return;
 let stroke=false,hasInk=false;
 const clear=()=>{
   ctx.fillStyle='#fff';
   ctx.fillRect(0,0,canvas.width,canvas.height);
   ctx.strokeStyle='#173b54';
   ctx.lineWidth=3.4;
   ctx.lineJoin='round';
   ctx.lineCap='round';
   hasInk=false;
   const out=form.querySelector('[data-signature-output]');
   if(out)out.value='';
 };
 clear();
 const point=(event)=>{
   const b=canvas.getBoundingClientRect();
   return [(event.clientX-b.left)*(canvas.width/Math.max(1,b.width)),
           (event.clientY-b.top)*(canvas.height/Math.max(1,b.height))];
 };
 canvas.addEventListener('pointerdown',event=>{
   if(event.button!==0 && event.pointerType!=='touch')return;
   event.preventDefault();stroke=true;
   canvas.setPointerCapture?.(event.pointerId);
   ctx.beginPath();const [x,y]=point(event);ctx.moveTo(x,y);
 });
 canvas.addEventListener('pointermove',event=>{
   if(!stroke)return;event.preventDefault();
   const [x,y]=point(event);ctx.lineTo(x,y);ctx.stroke();hasInk=true;
 });
 for(const event of ['pointerup','pointercancel','lostpointercapture']){
   canvas.addEventListener(event,()=>{stroke=false;});
 }
 form.querySelectorAll('[data-signature-clear]').forEach(btn=>btn.addEventListener('click',clear));
 return ()=>hasInk;
}

function activate(form) {
 if(form.dataset.receiptInit==='1')return;
 form.dataset.receiptInit='1';
 const draw=prepareSignature(form);
 const method=form.querySelector('[data-receipt-method]');
 const signBox=form.querySelector('[data-receipt-signature]');
 const isEntry=form.hasAttribute('data-expense-receipt-form');
 const dir=form.querySelector('[name="direction"]');
 const proof=form.querySelector('[data-receipt-proof-choice]');
 const upload=form.querySelector('[data-receipt-upload]');
 const recipient=form.querySelector('[name="recipient_name"]');
 const details=form.querySelector('[data-receipt-details]');
 const consent=form.querySelector('[data-signature-consent]');
 function update(){
   const expense=!isEntry||dir?.value==='expense';
   if(proof)proof.hidden=!expense;
   const mode=method?.value||'receipt_screen';
   const generated=expense&&mode!=='upload';
   if(upload)upload.hidden=generated;
   if(details)details.hidden=!generated;
   const wantsSign=generated&&mode==='receipt_screen';
   if(signBox)signBox.hidden=!wantsSign;
   if(recipient)recipient.required=generated;
   if(consent){consent.required=wantsSign;if(!wantsSign)consent.checked=false;}
   const file=form.querySelector('[name="document_file"]');
   if(file){file.required=false;if(generated)file.value='';}
   const signature=form.querySelector('[data-signature-output]');
   if(signature&&!wantsSign)signature.value='';
 }
 method?.addEventListener('change',update);
 dir?.addEventListener('change',update);
 update();
 form.addEventListener('submit',event=>{
   const expense=!isEntry||dir?.value==='expense';
   const wantSign=expense && (method?method.value==='receipt_screen':true);
   if(!wantSign)return;
   const out=form.querySelector('[data-signature-output]');
   const canvas=form.querySelector('[data-receipt-canvas]');
   const ink=draw&&draw();
   if(!canvas||!out||!ink){
     event.preventDefault();
     window.alert('Peça à pessoa que recebeu o pagamento para assinar no quadro. Ou escolha imprimir para assinar no papel.');
     return;
   }
   if(consent&&!consent.checked){
     event.preventDefault();
     window.alert('O recebedor precisa confirmar a assinatura.');
     return;
   }
   out.value=canvas.toDataURL('image/jpeg',0.86);
 });
}

function scan(root){
 if(root.matches?.(targetForms))activate(root);
 root.querySelectorAll?.(targetForms).forEach(activate);
}
function init(){
 scan(document);
 if('MutationObserver' in window){
  const observer=new MutationObserver(changes=>{
   for(const change of changes)for(const node of change.addedNodes)if(node.nodeType===1)scan(node);
  });
  observer.observe(document.body,{childList:true,subtree:true});
 }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
else init();
})();
