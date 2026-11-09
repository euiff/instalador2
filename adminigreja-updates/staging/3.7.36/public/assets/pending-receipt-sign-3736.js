/* Igreja Master 3.7.36: collect handwritten signature as PNG,
 * without posting finance entries until confirmation server-side. */
(() => {
  'use strict';
  for(const form of document.querySelectorAll('[data-pending-signature-form]')){
    const canvas=form.querySelector('[data-sign-canvas]');
    const input=form.querySelector('input[name="signed_file"]');
    const method=form.querySelector('select[name="signature_method"]');
    if(!canvas||!input||!method)return;
    const ctx=canvas.getContext('2d');
    let drawing=false,hasStroke=false;
    ctx.strokeStyle='#16345e';ctx.lineWidth=3;ctx.lineCap='round';ctx.lineJoin='round';
    const point=e=>{
      const rect=canvas.getBoundingClientRect();
      return {x:(e.clientX-rect.left)*canvas.width/rect.width,
        y:(e.clientY-rect.top)*canvas.height/rect.height};
    };
    canvas.addEventListener('pointerdown',e=>{
      e.preventDefault();canvas.setPointerCapture(e.pointerId);drawing=true;
      const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(p.x+0.1,p.y);ctx.stroke();hasStroke=true;
    });
    canvas.addEventListener('pointermove',e=>{
      if(!drawing)return;e.preventDefault();const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke();
    });
    const finish=()=>{drawing=false;ctx.beginPath()};
    canvas.addEventListener('pointerup',finish);canvas.addEventListener('pointercancel',finish);
    form.querySelector('[data-sign-clear]')?.addEventListener('click',()=>{
      ctx.clearRect(0,0,canvas.width,canvas.height);hasStroke=false;input.value='';
    });
    form.querySelector('[data-sign-use]')?.addEventListener('click',()=>{
      if(!hasStroke){alert('Peça à pessoa para assinar na tela antes de salvar.');return;}
      canvas.toBlob(blob=>{
        if(!blob){alert('Não foi possível preparar a assinatura.');return;}
        const file=new File([blob],'assinatura-tela.png',{type:'image/png'});
        const transfer=new DataTransfer();transfer.items.add(file);input.files=transfer.files;
        method.value='screen';
        alert('Assinatura adicionada ao recibo. Clique em Salvar assinatura para conferência.');
      },'image/png');
    });
    input.addEventListener('change',()=>{
      if(input.files?.length&&input.files[0].name!=='assinatura-tela.png')method.value='paper';
    });
  }
})();
