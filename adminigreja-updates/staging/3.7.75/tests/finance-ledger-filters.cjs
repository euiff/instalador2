'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const assert=require('node:assert/strict');
const src=fs.readFileSync(path.join(__dirname,'../public/assets/finance-ledger-filters-3775.js'),'utf8');
function control(value=''){
  return {value,options:[],listeners:{},appendChild(option){this.options.push(option)},
    addEventListener(kind,handler){this.listeners[kind]=handler},
    focus(){this.focused=true}};
}
const filters={
  search:control(''),type:control(''),category:control(''),account:control(''),
  status:control('')
};
const reset=control('');
const count={textContent:''};
const empty={hidden:true};
const records=[
  {id:'e1',financeType:'income',financeStatus:'posted',financeCategory:'DÍZIMOS',
    financeAccount:'Banco Geral',financeSearch:'Dízimo 15/11 contribuinte José SANTOS Banco Geral'},
  {id:'e2',financeType:'income',financeStatus:'reversed',financeCategory:'OFERTAS',
    financeAccount:'Caixa Geral',financeSearch:'Oferta da família Ana Maria'},
  {id:'e3',financeType:'expense',financeStatus:'posted',financeCategory:'DESPESAS',
    financeAccount:'Banco Geral',financeSearch:'Compra de materiais fornecedor loja central'}
];
const desktop=records.map(x=>({dataset:{...x,financeEntryId:x.id},hidden:false}));
const mobile=records.map(x=>({dataset:{financeEntryId:x.id},hidden:false}));
const scope={
  querySelectorAll(selector){
    return selector.includes('desktop')?desktop:selector.includes('mobile')?mobile:[];
  },
  querySelector(selector){
    const name=selector.match(/data-finance-filter="([^"]+)"/)?.[1];
    if(name)return filters[name];
    if(selector==='[data-finance-filter-count]')return count;
    if(selector==='[data-finance-filter-empty]')return empty;
    if(selector==='[data-finance-filter-reset]')return reset;
    return null;
  }
};
const context=vm.createContext({
  document:{getElementById:id=>id==='finance-movements'?scope:null,
    createElement:tag=>{assert.equal(tag,'option');return {value:'',textContent:''}}},
  Intl,Map,Set
});
vm.runInContext(src,context);
assert.match(count.textContent,/3 de 3/,'all finance rows are visible initially');
assert.equal(empty.hidden,true);
assert.equal(filters.category.options.length,3,'category options are filled only from current rows');
assert.equal(filters.account.options.length,2,'account options are deduplicated');
filters.search.value='jose santos';
filters.search.listeners.input();
assert.match(count.textContent,/1 de 3/,'accent-insensitive contributor search');
assert.deepEqual(desktop.map(x=>x.hidden),[false,true,true]);
assert.deepEqual(mobile.map(x=>x.hidden),[false,true,true],'mobile cards remain synchronized');
filters.search.value='';
filters.search.listeners.input();
filters.type.value='expense';
filters.type.listeners.change();
assert.deepEqual(desktop.map(x=>x.hidden),[true,true,false],'expense filter');
filters.type.value='';
filters.type.listeners.change();
filters.account.value='Banco Geral';
filters.account.listeners.change();
assert.deepEqual(desktop.map(x=>x.hidden),[false,true,false],'account filter');
filters.status.value='posted';
filters.status.listeners.change();
assert.deepEqual(desktop.map(x=>x.hidden),[false,true,false],'status filter');
filters.category.value='DÍZIMOS';
filters.category.listeners.change();
assert.deepEqual(desktop.map(x=>x.hidden),[false,true,true],'category filter');
filters.search.value='nao existe';
filters.search.listeners.input();
assert.match(count.textContent,/0 de 3/,'no matches count');
assert.equal(empty.hidden,false,'empty explanation visible');
reset.listeners.click();
assert.equal(filters.search.value,'');
for(const name of ['type','category','account','status'])assert.equal(filters[name].value,'');
assert.deepEqual(desktop.map(x=>x.hidden),[false,false,false]);
assert.deepEqual(mobile.map(x=>x.hidden),[false,false,false]);
assert.equal(filters.search.focused,true);
console.log('PASS finance filters: accent-insensitive search, account, status, category, type, empty results and synchronized mobile/desktop cards.');
