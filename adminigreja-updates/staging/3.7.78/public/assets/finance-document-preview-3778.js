/* Igreja Master 3.7.78 — view an attached document without leaving the
 * mobile ledger. All actual bytes stay protected by finance-document.php,
 * which must independently verify the user's church and finance permission.
 */
(()=>{
  'use strict';
  const modal=document.getElementById('im-finance-document-modal');
  if(!modal)return;
  const frame=modal.querySelector('[data-finance-preview-frame]');
  const openLink=modal.querySelector('[data-finance-preview-open]');
  const title=modal.querySelector('#im-finance-document-modal-title');
  const validDocumentUrl=/^\/finance-document\.php\?id=[a-f0-9-]{36}$/i;
  if(!frame||!openLink||!title)return;

  function clearPreview(){
    frame.removeAttribute('src');
    openLink.removeAttribute('href');
    title.textContent='Visualizar documento';
  }
  function closePreview(){
    if(typeof modal.close==='function'&&modal.open)modal.close();
    clearPreview();
  }

  document.addEventListener('click',event=>{
    const close=event.target.closest('[data-finance-preview-close]');
    if(close && modal.contains(close)){
      event.preventDefault();
      closePreview();
      return;
    }
    const button=event.target.closest('[data-finance-preview]');
    if(!button)return;
    const url=button.getAttribute('data-finance-preview')||'';
    if(!validDocumentUrl.test(url))return;
    event.preventDefault();
    if(typeof modal.showModal!=='function'){
      // Older WebViews may not support <dialog>. Fall back to opening the
      // protected document through a regular user-initiated navigation.
      window.open(url,'_blank','noopener,noreferrer');
      return;
    }
    clearPreview();
    const name=String(button.getAttribute('data-finance-preview-name')||'').trim();
    title.textContent=name||'Documento do lançamento';
    openLink.setAttribute('href',url);
    frame.setAttribute('src',url);
    modal.showModal();
  });
  modal.addEventListener('close',clearPreview);
  modal.addEventListener('click',event=>{
    // Click on the dark backdrop, outside the dialog content, to close.
    if(event.target===modal)closePreview();
  });
})();
