(() => {
  'use strict';
  const page = document.body.dataset.appPage;
  const main = document.querySelector('main.content');
  if (!main) return;
  const state = JSON.parse(document.getElementById('app-ui-state')?.textContent || '{}');
  const query = new URLSearchParams(location.search);
  const hasError = !!main.querySelector('.alert-error, .alert.error');
  const postAction = String(state.post?.action || 'save');
  const svg = name => ({
    plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg>'
  }[name]);
  const definitions = {
    'members.php': [{source:'#cadastro',id:'member-editor',title:'Novo membro',description:'Dados pessoais, contato e vínculo com a igreja.',actions:['save']}],
    'congregations.php': [{source:'#editor',id:'congregation-editor',title:'Nova congregação',description:'Dados da unidade, responsável e identidade visual.',actions:['save']}],
    'events.php': [{source:'#form',id:'event-editor',title:'Novo evento',description:'Programação, inscrições e divulgação.',actions:['save']}],
    'service-types.php': [{source:'#form',id:'service-editor',title:'Novo culto ou reunião',description:'Organize os encontros recorrentes da igreja.',actions:['save']}],
    'users.php': [{source:'[data-registry-form="users"]',id:'user-editor',title:'Novo acesso',description:'Defina quem pode usar o sistema e o perfil de acesso.',actions:['create']}],
    'needs.php': [{source:'[data-registry-form="needs"]',id:'need-editor',title:'Nova necessidade',description:'Registre o que a igreja precisa e sua prioridade.',actions:['save','create']}],
    'visitors.php': [{source:'#visitor-form',id:'visitor-editor',title:'Novo visitante',description:'Acolha a pessoa e combine o próximo contato.',actions:['save']}],
    'pastoral-care.php': [{source:'[data-registry-form="pastoral"]',id:'pastoral-editor',title:'Novo acompanhamento',description:'Registre o contato pastoral e o próximo retorno.',actions:['save']}],
    'departments.php': [{source:'[data-registry-form="departments"]',id:'department-editor',title:'Novo departamento',description:'Organize o ministério, a liderança e os encontros.',actions:['save']}],
    'assets.php': [
      {source:'[data-registry-form="assets"]',id:'asset-editor',title:'Novo bem patrimonial',description:'Identifique o bem, a localização e o responsável.',actions:['save']},
      {source:'[data-registry-form="asset-maintenance"]',id:'maintenance-editor',title:'Registrar manutenção',description:'Guarde o serviço realizado e a próxima revisão.',actions:['maintenance'],secondary:true,inline:true}
    ],
    'groups.php': [{source:'#group-form',id:'group-editor',title:'Novo grupo',description:'Vincule o grupo à igreja e escolha o que ele recebe.',actions:['save']}],
    'campaigns.php': [{source:'[data-registry-form="campaigns"]',id:'campaign-editor',title:'Novo comunicado',description:'Escreva a mensagem, escolha os grupos e confirme o envio.',actions:['send','schedule']}],
    'polls.php': [{source:'[data-registry-form="polls"]',id:'poll-editor',title:'Nova enquete',description:'Defina a pergunta, as opções e os grupos de votação.',actions:['create','save']}],
    'raffles.php': [
      {source:'[data-registry-form="raffles"]',id:'raffle-editor',title:'Nova rifa',description:'Cadastre a campanha, o prêmio e a divulgação.',actions:['create']},
      {source:'[data-registry-form="raffle-reservation"]',id:'raffle-reservation',title:'Reserva manual',description:'Inclua uma reserva feita pela secretaria.',actions:['reserve'],secondary:true}
    ],
    'prayer-clock.php': [
      {source:'[data-registry-form="clock"]',id:'clock-editor',title:'Novo relógio de oração',description:'Configure o período, os turnos e a divulgação.',actions:['create_clock']},
      {source:'[data-registry-form="clock-reservation"]',id:'clock-reservation',title:'Reservar horário',description:'Inclua um participante no horário escolhido.',actions:['register','reserve'],secondary:true}
    ],
    'event-registrations.php': [{source:'[data-registry-form="registrations"]',id:'registration-editor',title:'Nova inscrição',description:'Escolha o evento e preencha os dados do participante.',actions:['save','create']}],
    'prayers.php': [{source:'#resposta',id:'prayer-reply',title:'Novo pedido de oração',description:'Acompanhe o pedido e registre a resposta.',actions:['new','response','status']}],
    'finance.php': [
      {source:'[data-registry-form="finance-entry"]',id:'finance-entry',title:'Novo lançamento',description:'Registre a entrada ou saída e o documento correspondente.',actions:['entry']},
      {source:'[data-registry-form="finance-document"]',id:'finance-document',title:'Arquivar comprovante',description:'Guarde o documento fiscal ou comprovante da movimentação.',actions:['attach_document'],secondary:true,inline:true},
      {source:'[data-registry-form="finance-receipt"]',id:'finance-receipt',title:'Emitir recibo',description:'Confira os dados e emita o recibo numerado.',actions:['receipt'],secondary:true,inline:true},
      {source:'[data-registry-form="finance-payable"]',id:'finance-payable',title:'Nova conta a pagar',description:'Cadastre o fornecedor, o valor e o vencimento.',actions:['payable'],secondary:true,inline:true},
      {source:'[data-registry-form="finance-account"]',id:'finance-account',title:'Nova conta financeira',description:'Cadastre um caixa ou uma conta bancária.',actions:['account'],secondary:true,inline:true},
      {source:'[data-registry-form="finance-budget"]',id:'finance-budget',title:'Novo orçamento',description:'Defina o valor planejado para uma categoria.',actions:['budget'],secondary:true,inline:true},
      {source:'[data-registry-form="finance-reconcile"]',id:'finance-reconcile',title:'Conferir saldo',description:'Compare o saldo do sistema com o extrato.',actions:['reconcile'],secondary:true,inline:true},
      {source:'[data-registry-form="finance-closing"]',id:'finance-closing',title:'Fechar caixa',description:'Confira o período e registre o fechamento.',actions:['close_period'],secondary:true,inline:true}
    ]
  };

  function button(text,id,primary=false) {
    const b=document.createElement('button'); b.type='button'; b.className='btn '+(primary?'btn-primary':'btn-light');
    b.dataset.openDialog=id; b.innerHTML=svg('plus'); b.append(document.createTextNode(text)); return b;
  }

  function restoreValues(root) {
    if (!state.isPost || !hasError) return;
    root.querySelectorAll('input,select,textarea').forEach(input => {
      const key=input.name?.replace(/\[\]$/,'');
      if (!key || /pass|senha|secret|token|csrf|api.?key/i.test(key) || ['hidden','file','submit','button'].includes(input.type)) return;
      const value=state.post?.[key];
      if (input.type==='checkbox' || input.type==='radio') {
        input.checked = Array.isArray(value) ? value.map(String).includes(input.value) : value!==undefined && String(value)===input.value;
      } else if (value!==undefined && !Array.isArray(value)) input.value=String(value);
      else if(input.multiple && Array.isArray(value)) [...input.options].forEach(o=>o.selected=value.includes(o.value));
    });
  }

  function openDialog(dialog) {
    if (!dialog || dialog.open) return;
    dialog._returnFocus=document.activeElement;
    dialog.showModal(); document.body.classList.add('dialog-open');
    requestAnimationFrame(()=>{const first=dialog.querySelector('input:not([type="hidden"]):not([disabled]),select:not([disabled]),textarea:not([disabled])')||dialog.querySelector('[data-close-dialog]');first?.focus({preventScroll:true});});
  }
  function closeDialog(dialog) {
    if (!dialog) return;
    if (dialog.dataset.dirty==='true' && !window.confirm('Você tem alterações não salvas. Deseja fechar o formulário e descartá-las?')) return;
    if (dialog.dataset.dirty==='true') dialog._resetValues?.();
    dialog.close();
  }

  const configs=definitions[page] || [];
  const created=[];
  const actions=document.createElement('div'); actions.className='module-toolbar-actions';
  for(const config of configs) {
    const source=main.querySelector(config.source);
    if(!source || !(source.matches('form') || source.querySelector('form'))) continue;
    const parent=source.parentElement;
    const isForm=source.matches('form');
    const oldHeading=isForm ? source.querySelector('h2,h3') : source.querySelector('.page-head h1,.page-head h2,.settings-section-head h2,.section-title h2,h2');
    const editId=source.querySelector('input[name="id"]')?.value;
    const isEditing=!!editId && !config.secondary;
    const originalTitle=oldHeading?.textContent.trim();
    const title=isEditing ? (/^(Editar|Responder)/.test(originalTitle||'') ? originalTitle : config.title.replace(/^Nov[oa] /,'Editar ')) : config.title;
    const dialog=document.createElement('dialog'); dialog.id=config.id; dialog.className='app-dialog editor-dialog'; dialog.setAttribute('aria-labelledby',config.id+'-title');
    const shell=document.createElement('div'); shell.className='dialog-shell';
    const header=document.createElement('header'); header.className='dialog-heading';
    const titles=document.createElement('div');
    const kicker=document.createElement('span'); kicker.className='eyebrow'; kicker.textContent=isEditing?'Atualizar cadastro':'Preencher cadastro';
    const h=document.createElement('h2'); h.id=config.id+'-title'; h.textContent=title;
    const p=document.createElement('p'); p.textContent=config.description;
    titles.append(kicker,h,p);
    const close=document.createElement('button'); close.type='button'; close.className='icon-button'; close.dataset.closeDialog=''; close.setAttribute('aria-label','Fechar formulário'); close.innerHTML=svg('close');
    header.append(titles,close);
    const body=document.createElement('div'); body.className='dialog-body';
    const foot=document.createElement('div'); foot.className='dialog-footer';
    const cancel=document.createElement('button'); cancel.type='button'; cancel.className='btn btn-light'; cancel.dataset.closeDialog=''; cancel.textContent='Fechar';
    const hint=document.createElement('span'); hint.textContent='Campos com * são obrigatórios.'; foot.append(hint,cancel);
    if(config.inline) source.before(button(config.title,config.id));
    source.classList.remove('is-hidden');
    const oldHeader=!isForm && source.querySelector(':scope > .page-head,:scope > .settings-section-head,:scope > .section-title');
    if(oldHeader) oldHeader.remove(); else oldHeading?.remove();
    source.classList.add('dialog-form-content');
    body.append(source); shell.append(header,body,foot); dialog.append(shell); document.body.append(dialog);
    if(parent.matches('.two-col,.grid-2,.groups-layout,.poll-page')) parent.classList.add('registry-layout');
    if(!parent.children.length) parent.hidden=true;
    const forms=isForm?[source]:[...source.querySelectorAll('form')];
    if(page==='members.php') {
      const grid=source.querySelector('.form-grid');const keep=new Set(['name','phone','email','birth_date','congregation_id']);
      const extras=grid?[...grid.children].filter(field=>{const input=field.querySelector('[name]');return input&&!keep.has(input.name);}):[];
      if(extras.length){const details=document.createElement('details');details.className='form-details field full';const summary=document.createElement('summary');summary.textContent='Informações complementares';const help=document.createElement('small');help.textContent='Documentos, endereço e vida na igreja';summary.append(help);const extraGrid=document.createElement('div');extraGrid.className='form-grid';extraGrid.append(...extras);details.append(summary,extraGrid);grid.append(details);}
    }
    const footActions=document.createElement('div');footActions.className='dialog-footer-actions';footActions.append(cancel);
    const mainForm=forms[0];
    if(mainForm){const formId=mainForm.getAttribute('id')||config.id+'-form';mainForm.setAttribute('id',formId);for(const submit of [...mainForm.querySelectorAll('button')].filter(b=>b.type==='submit')){submit.setAttribute('form',formId);footActions.append(submit);}}
    foot.append(footActions);

    for(const form of forms) {
      form.dataset.dialogForm=config.id;
      if(hasError && (config.actions.includes(postAction) || (configs.length===1 && !['toggle','remove','resend','sync','test','close'].includes(postAction)))) restoreValues(form);
    }
    if(hasError && state.isPost && config.actions.includes(postAction)) {
      const alert=main.querySelector('.alert-error,.alert.error')?.cloneNode(true);
      if(alert) {alert.setAttribute('role','alert'); body.prepend(alert);}
      dialog.dataset.dirty='true';
    }
    const snapshots=[...source.querySelectorAll('input,select,textarea')].map(el=>({el,value:el.value,checked:el.checked,selected:el.options?[...el.options].map(o=>o.selected):null}));
    dialog._resetValues=()=>{for(const s of snapshots){if(s.el.type!=='file')s.el.value=s.value;else s.el.value='';if('checked' in s.el)s.el.checked=s.checked;if(s.selected)[...s.el.options].forEach((o,i)=>o.selected=s.selected[i]);}dialog.dataset.dirty='false';};
    if(!config.reply && !config.inline) actions.append(button(config.secondary?config.title:'Novo cadastro',config.id,!config.secondary));
    if(isEditing) {
      const newButton=actions.querySelector(`[data-open-dialog="${config.id}"]`);
      if(newButton) {newButton.dataset.newPage='true';newButton.dataset.openDialog='';}
      if(['assets.php','departments.php'].includes(page)) actions.append(button('Editar cadastro',config.id));
    }
    created.push({config,dialog,isEditing});
  }

  if(created.length) {
    document.body.classList.add('registry-page');
    const toolbar=document.createElement('div'); toolbar.className='module-toolbar';
    const heading=document.createElement('div'); heading.className='module-title';
    const h=document.createElement('h1'); h.textContent=document.querySelector('.breadcrumb strong')?.textContent || 'Cadastros';
    const subtitle=document.createElement('p'); subtitle.textContent=page==='finance.php'?'Acompanhe o caixa e escolha a área que deseja consultar.':'Consulte os registros abaixo ou abra um formulário para cadastrar.';
    heading.append(h,subtitle); toolbar.append(heading,actions);main.prepend(toolbar);
    main.querySelectorAll(':scope > .module-hero,:scope > .groups-page-head').forEach(old=>{
      const existingActions=old.querySelector('.actions');
      if(existingActions) {
        for(const child of [...existingActions.children]) {
          const href=child.getAttribute('href')||'';
          if(href.includes('new=1') || (href.includes('#form') && !href.includes('edit='))) continue;
          actions.append(child);
        }
      }
      old.remove();
    });
    main.querySelectorAll('h1').forEach(old=>{if(old===h)return;const heading=document.createElement('h2');for(const a of old.attributes)heading.setAttribute(a.name,a.value);heading.innerHTML=old.innerHTML;old.replaceWith(heading);});
    main.querySelectorAll('a').forEach(link=>{
      const url=new URL(link.href,location.href);
      if(url.pathname===location.pathname && !url.searchParams.has('edit') && (url.searchParams.has('new') || ['#cadastro','#form','#editor','#group-form'].includes(url.hash))) {
        if(link.closest('.page-head,.actions')) link.remove();
        else {link.dataset.openDialog=created[0].dialog.id;link.removeAttribute('href');link.setAttribute('role','button');link.tabIndex=0;}
      }
    });
  }

  // Finance areas are tabs; the server-side forms and calculations stay unchanged.
  if(page==='finance.php') {
    const grids=[...main.querySelectorAll(':scope > .grid.two-col')];
    const tabs=[['cash','Movimentações'],['documents','Comprovantes'],['receipts','Recibos'],['payables','Contas a pagar'],['accounts','Contas e orçamento'],['closing','Conferência e fechamento']];
    const nav=document.createElement('div');nav.className='module-tabs';nav.setAttribute('role','tablist');nav.setAttribute('aria-label','Áreas da tesouraria');
    const pairs=[];
    grids.forEach((panel,i)=>{
      if(!tabs[i])return;
      const [key,label]=tabs[i];panel.id='finance-panel-'+key;panel.setAttribute('role','tabpanel');
      const b=document.createElement('button');b.type='button';b.id='finance-tab-'+key;b.className='module-tab';b.textContent=label;b.setAttribute('role','tab');b.setAttribute('aria-controls',panel.id);panel.setAttribute('aria-labelledby',b.id);b.dataset.financeTab=key;nav.append(b);pairs.push({key,b,panel});
    });
    if(pairs.length) {
      const select=key=>{for(const pair of pairs){const active=pair.key===key;pair.b.setAttribute('aria-selected',String(active));pair.b.tabIndex=active?0:-1;pair.panel.hidden=!active;}history.replaceState(null,'','#'+key);};
      nav.addEventListener('click',e=>{const b=e.target.closest('[data-finance-tab]');if(b)select(b.dataset.financeTab);});
      nav.addEventListener('keydown',e=>{if(!['ArrowRight','ArrowLeft','Home','End'].includes(e.key))return;e.preventDefault();let i=pairs.findIndex(p=>p.b===document.activeElement);i=e.key==='Home'?0:e.key==='End'?pairs.length-1:(i+(e.key==='ArrowLeft'?-1:1)+pairs.length)%pairs.length;select(pairs[i].key);pairs[i].b.focus();});
      grids[0].before(nav);const requested=state.post?._ui_tab||location.hash.slice(1);select(pairs.some(p=>p.key===requested)?requested:'cash');
    }
  }

  // Connect labels to the original controls; no duplicate form or duplicate IDs.
  let labelNumber=0;
  document.querySelectorAll('.field,.form-grid>label').forEach(field=>{
    const label=field.matches('label')?field:field.querySelector('label');
    const control=field.querySelector('input:not([type="hidden"]),select,textarea');
    if(!label||!control)return;
    if(!control.id)control.id='ui-control-'+(++labelNumber);
    if(!label.htmlFor)label.htmlFor=control.id;
    const help=field.querySelector('.help-text');if(help){if(!help.id)help.id=control.id+'-help';control.setAttribute('aria-describedby',help.id);}
  });
  document.querySelectorAll('dialog').forEach(dialog=>{
    dialog.addEventListener('cancel',e=>{e.preventDefault();closeDialog(dialog);});
    dialog.addEventListener('click',e=>{if(e.target!==dialog)return;const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)closeDialog(dialog);});
    dialog.addEventListener('input',e=>{if(e.target.closest('form'))dialog.dataset.dirty='true';});
    dialog.addEventListener('change',e=>{if(e.target.closest('form'))dialog.dataset.dirty='true';});
    dialog.addEventListener('close',()=>{if(!document.querySelector('dialog[open]'))document.body.classList.remove('dialog-open');dialog._returnFocus?.focus?.({preventScroll:true});});
  });
  document.addEventListener('click',e=>{
    const create=e.target.closest('[data-new-page]');if(create){location.href=location.pathname+'?new=1';return;}
    const opener=e.target.closest('[data-open-dialog]');if(opener?.dataset.openDialog){e.preventDefault();openDialog(document.getElementById(opener.dataset.openDialog));}
    const closer=e.target.closest('[data-close-dialog]');if(closer)closeDialog(closer.closest('dialog'));
  });
  document.addEventListener('keydown',e=>{
    if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey&&!e.target.closest('input,textarea,select,[contenteditable="true"]')&&!document.querySelector('dialog[open]')){e.preventDefault();openDialog(document.getElementById('menu-search'));}
    if(e.key==='Escape'){const active=[...document.querySelectorAll('dialog[open]')].at(-1);if(active){e.preventDefault();closeDialog(active);}else closeSidebar();}
  });
  const normalize=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  document.getElementById('global-menu-query')?.addEventListener('input',e=>{
    const value=normalize(e.target.value);let count=0;
    document.querySelectorAll('[data-menu-result]').forEach(link=>{const show=normalize(link.textContent).includes(value);link.hidden=!show;if(show)count++;});
    document.getElementById('menu-no-results').hidden=count>0;
  });
  const sidebar=document.getElementById('sidebar');const overlay=document.querySelector('.sidebar-overlay');const menuButton=document.querySelector('[data-sidebar-open]');
  function closeSidebar(){const wasOpen=sidebar?.classList.contains('open');sidebar?.classList.remove('open');overlay?.classList.remove('show');document.body.classList.remove('no-scroll');menuButton?.setAttribute('aria-expanded','false');sidebar?.removeAttribute('aria-modal');sidebar?.removeAttribute('role');if(wasOpen)menuButton?.focus();}
  document.querySelectorAll('[data-sidebar-open]').forEach(b=>b.addEventListener('click',()=>{sidebar?.classList.add('open');overlay?.classList.add('show');document.body.classList.add('no-scroll');menuButton?.setAttribute('aria-expanded','true');sidebar?.querySelector('a,button')?.focus();}));
  document.querySelectorAll('[data-sidebar-close]').forEach(b=>b.addEventListener('click',closeSidebar));
  sidebar?.addEventListener('keydown',e=>{if(e.key!=='Tab'||!sidebar.classList.contains('open'))return;const visible=[...sidebar.querySelectorAll('a[href],button,select,summary')].filter(el=>el.getClientRects().length);if(e.shiftKey&&document.activeElement===visible[0]){e.preventDefault();visible.at(-1).focus();}else if(!e.shiftKey&&document.activeElement===visible.at(-1)){e.preventDefault();visible[0].focus();}});
  document.addEventListener('submit',e=>{
    if(e.defaultPrevented)return;
    const form=e.target;if(page==='finance.php'&&form.method?.toLowerCase()==='get'){form.action=location.pathname+location.hash;return;}
    if(form.method?.toLowerCase()!=='post')return;
    if(form.dataset.submitting==='true'){e.preventDefault();return;}
    form.dataset.submitting='true';
    if(page==='finance.php'&&!form.querySelector('[name="_ui_tab"]')){const input=document.createElement('input');input.type='hidden';input.name='_ui_tab';input.value=location.hash.slice(1);form.append(input);}
    if(e.submitter){e.submitter.dataset.originalText=e.submitter.innerHTML;e.submitter.setAttribute('aria-disabled','true');e.submitter.classList.add('is-submitting');}
    // Keep successful submit controls enabled: their name/value selects the backend action.
  });
  window.addEventListener('pageshow',()=>{document.querySelectorAll('[data-submitting]').forEach(f=>delete f.dataset.submitting);document.querySelectorAll('.is-submitting').forEach(b=>{b.removeAttribute('aria-disabled');b.classList.remove('is-submitting');});});
  let auto=null;
  if(hasError&&state.isPost)auto=created.find(({config})=>config.actions.includes(postAction));
  if(!auto&&!state.isPost&&query.has('new'))auto=created.find(({config})=>!config.secondary&&!config.reply);
  if(!auto&&!state.isPost&&query.has('edit'))auto=created.find(({config})=>!config.secondary);
  if(auto)openDialog(auto.dialog);
})();
