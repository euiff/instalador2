(() => {
  'use strict';
  const desktop=matchMedia('(min-width:1100px)');
  const groups=[...document.querySelectorAll('.nav-group')];
  const closeOthers=except=>groups.forEach(group=>{if(group!==except)group.open=false;});
  const reset=()=>{if(desktop.matches)closeOthers();};
  reset();desktop.addEventListener('change',reset);
  groups.forEach(group=>{
    group.addEventListener('focusout',event=>{if(desktop.matches&&!group.contains(event.relatedTarget))group.open=false;});
    group.querySelector('summary')?.addEventListener('click',()=>{if(desktop.matches)closeOthers(group);});
    group.addEventListener('keydown',event=>{if(event.key==='Escape'&&desktop.matches){event.preventDefault();group.querySelector('summary')?.focus();group.open=false;}});
  });
  document.addEventListener('click',event=>{if(desktop.matches&&!event.target.closest('.nav-group'))closeOthers();});
  const state=JSON.parse(document.getElementById('app-ui-state')?.textContent||'{}');
  if(state.isPost&&document.querySelector('.alert-error'))document.querySelectorAll('input,select,textarea').forEach(control=>{
    const name=control.name;if(!name||/pass|senha|secret|token|csrf|api.?key/i.test(name)||['hidden','file','submit','button'].includes(control.type))return;
    const match=name.match(/^([^\[]+)\[([^\]]+)\]$/);
    const value=match?state.post?.[match[1]]?.[match[2]]:state.post?.[name.replace(/\[\]$/,'')];
    if(control.type==='checkbox'||control.type==='radio')control.checked=Array.isArray(value)?value.includes(control.value):value!==undefined&&String(value)===control.value;
    else if(control.multiple&&Array.isArray(value))[...control.options].forEach(option=>option.selected=value.includes(option.value));
    else if(value!==undefined&&!Array.isArray(value))control.value=String(value);
  });
  const auto=document.querySelector('dialog[data-auto-open]');
  if(auto&&!auto.open){auto.showModal();document.body.classList.add('dialog-open');auto.querySelector('input:not([type=hidden]),select,textarea,button')?.focus();}
})();
