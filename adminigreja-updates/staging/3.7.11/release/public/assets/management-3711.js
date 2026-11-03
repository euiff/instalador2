/* Igreja Master 3.7.11 — keyboard navigation and accessible Central 360 search.
   All actions are permission-filtered by PHP; this script never grants access. */
(()=>{
  'use strict';
  const editable=(node)=>Boolean(node?.closest?.('input,textarea,select,[contenteditable=true]'));
  const openDialog=(id)=>{
    const dialog=document.getElementById(id);
    if(!dialog||typeof dialog.showModal!=='function')return false;
    if(!dialog.open){
      try{dialog.showModal();}catch(_){return false;}
    }
    const field=dialog.querySelector('input:not([type=hidden]),input[type=search],select,textarea,[tabindex="0"]');
    field?.focus({preventScroll:true});
    return true;
  };

  // CTRL+K / CMD+K opens the app's existing permission-filtered search.
  document.addEventListener('keydown',(event)=>{
    const k=event.key?.toLowerCase();
    if((event.ctrlKey||event.metaKey)&&k==='k'){
      event.preventDefault();openDialog('menu-search');return;
    }
    if(!event.ctrlKey&&!event.metaKey&&!event.altKey&&k==='/'&&!editable(event.target)){
      event.preventDefault();openDialog('menu-search');
    }
  });

  document.addEventListener('click',(event)=>{
    const trigger=event.target.closest('[data-im-open]');
    if(!trigger)return;
    const id=trigger.getAttribute('data-im-open');
    if(!['menu-search','quick-create'].includes(id))return;
    event.preventDefault();
    openDialog(id);
  });

  // Filter the already-authorized links without remote queries or metadata leakage.
  const input=document.getElementById('im-module-filter');
  const grid=document.getElementById('im-app-results');
  const empty=document.querySelector('.im-no-results');
  if(input&&grid){
    const cards=Array.from(grid.querySelectorAll('.im-app-card'));
    const normalize=(str)=>String(str||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
    input.addEventListener('input',()=>{
      const text=normalize(input.value);
      let visible=0;
      cards.forEach(card=>{
        const match=normalize(card.textContent).includes(text);
        card.hidden=!match;
        if(match)visible++;
      });
      if(empty)empty.hidden=visible!==0;
    });
    input.addEventListener('keydown',(event)=>{
      if(event.key!=='Enter')return;
      const visible=cards.find(card=>!card.hidden);
      if(visible){event.preventDefault();visible.click();}
    });
  }
})();
