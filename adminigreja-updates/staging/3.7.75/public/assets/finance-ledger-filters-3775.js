/* Igreja Master 3.7.75 — list filtering for the visual finance dashboard.
 * Client-only: never alters financial values, authorization or database.
 * Both desktop rows and mobile cards are filtered using the SAME entry id.
 */
(()=>{
  'use strict';
  const scope=document.getElementById('finance-movements');
  if(!scope)return;
  const desktop=[...scope.querySelectorAll('[data-finance-row][data-finance-view="desktop"]')];
  const mobile=[...scope.querySelectorAll('[data-finance-row][data-finance-view="mobile"]')];
  const getField=name=>scope.querySelector('[data-finance-filter="'+name+'"]');
  const search=getField('search');
  const type=getField('type');
  const category=getField('category');
  const account=getField('account');
  const status=getField('status');
  const count=scope.querySelector('[data-finance-filter-count]');
  const empty=scope.querySelector('[data-finance-filter-empty]');
  const reset=scope.querySelector('[data-finance-filter-reset]');
  if(!search||!type||!category||!account||!status||!count||!empty||!reset)return;
  const norm=str=>String(str??'').normalize('NFD')
     .replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR').trim();
  const collator=new Intl.Collator('pt-BR',{sensitivity:'base',numeric:true});
  const fillSelect=(select,name)=>{
    const names=[...new Set(desktop.map(row=>String(row.dataset[name]||'').trim()))]
      .filter(Boolean).sort(collator.compare);
    for(const name of names){
      const option=document.createElement('option');
      option.value=name;option.textContent=name;select.appendChild(option);
    }
  };
  fillSelect(category,'financeCategory');
  fillSelect(account,'financeAccount');
  const mobileMap=new Map(mobile.map(row=>[row.dataset.financeEntryId,row]));
  function refresh(){
    const tokens=norm(search.value).split(/\s+/).filter(Boolean);
    const chosenType=type.value;
    const chosenCategory=norm(category.value);
    const chosenAccount=norm(account.value);
    const chosenStatus=status.value;
    let shown=0;
    for(const row of desktop){
      const data=row.dataset;
      const query=norm(data.financeSearch);
      const visible=(!chosenType||data.financeType===chosenType)
        &&(!chosenCategory||norm(data.financeCategory)===chosenCategory)
        &&(!chosenAccount||norm(data.financeAccount)===chosenAccount)
        &&(!chosenStatus||data.financeStatus===chosenStatus)
        &&tokens.every(word=>query.includes(word));
      row.hidden=!visible;
      const card=mobileMap.get(data.financeEntryId);
      if(card)card.hidden=!visible;
      if(visible)shown++;
    }
    const total=desktop.length;
    count.textContent='Exibindo '+shown+' de '+total+' lançamento(s) carregado(s) no período.';
    empty.hidden=shown!==0;
  }
  search.addEventListener('input',refresh);
  for(const field of [type,category,account,status])
    field.addEventListener('change',refresh);
  reset.addEventListener('click',()=>{
    search.value='';
    for(const field of [type,category,account,status])field.value='';
    refresh();
    search.focus();
  });
  refresh();
})();
