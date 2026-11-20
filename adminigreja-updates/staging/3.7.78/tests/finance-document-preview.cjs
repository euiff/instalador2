'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const source=fs.readFileSync(path.join(__dirname,'../public/assets/finance-document-preview-3778.js'),'utf8');
const php=fs.readFileSync(path.join(__dirname,'../public/finance.php'),'utf8');
function ensurePart(needle,description){assert(php.includes(needle),description)}
[
  '$ledgerDocuments=[]',
  'WHERE church_id=? AND entry_id IN ($holders)',
  'array_merge([$cid],$entryIds)',
  'finance_documents',
  'im-finance-mobile-card',
  'im-finance-mobile-list',
  'im-finance-mobile-tools',
  'im-finance-action im-finance-edit',
  'im-finance-action im-finance-view',
  'im-finance-action im-finance-attach',
  'im-finance-action im-finance-receipt',
  'im-finance-action im-finance-delete',
  'im-finance-mobile-document-list',
  'data-finance-preview="/finance-document.php?id=',
  'data-finance-preview-name=',
  '<iframe title="Documento financeiro protegido"',
  '/assets/finance-document-preview-3778.js?v=3.7.78',
  '.im-finance-mobile-tools{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))',
  '#finance-movements>.table-wrap{display:none!important}',
  'display:grid!important;grid-template-columns:minmax(0,1fr)!important',
  '#finance-movements .im-finance-mobile-tools .im-finance-delete',
  'ChurchFinanceExclusionUi::render($r)',
  'MasterSupportFinanceUi::exclusionForm($r)',
  "href=\"/finance-entry-edit.php?id=",
  "href=\"/expense-receipt.php?entry=",
  'data-finance-row data-finance-view="desktop"',
  'data-finance-row data-finance-view="mobile"',
  'data-finance-filter="search"',
  'data-extra-attachments',
  'finance-local-ocr-3774.js?v=3.7.76',
  'finance-ocr-3752.js?v=3.7.77'
].forEach(needle=>ensurePart(needle,'Missing original/current feature: '+needle));

assert(!php.includes('class="im-finance-mobile-actions"'),
   'Mobile per-entry actions must not be hidden behind old Details menu');
assert(php.indexOf('Visualizar documento</button>')>php.indexOf('im-finance-mobile-card'),
   'Preview action must appear inside each mobile card');
const mobileStart=php.indexOf('<article class="im-tithe-item im-finance-mobile-card"');
const mobileEnd=php.indexOf('    </article>',mobileStart);
assert(mobileStart>0 && mobileEnd>mobileStart,'mobile card markup found');
const card=php.slice(mobileStart,mobileEnd);
assert(card.indexOf('Visualizar documento</button>')>0,'preview on first screen');
assert(card.indexOf('✏️ Editar')>0,'edit on first screen');
assert(card.indexOf('📎 Anexar arquivo')>0,'upload on first screen');
assert(card.indexOf('🧾 Ver recibo')>0,'receipt accessible');
assert(!card.includes('Ações do lançamento</summary>'),'no hidden action menu');
assert(php.includes('count($entryDocs)>1'),'multiple docs have one button each');
assert(php.includes('if($entryDocs)'), 'no broken preview link for empty entries');
assert(php.includes('Sem documento'), 'empty docs clearly displayed');
assert(php.includes("ORDER BY created_at DESC,id DESC"),'most recent doc preview selected');

const docListeners={};const modalListeners={};const modalAttributes={};
const frame={attributes:{},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}};
const link={attributes:{},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}};
const title={textContent:''};
const modal={
  open:false,
  showModal(){this.open=true},
  close(){this.open=false;modalListeners.close?.()},
  contains(el){return el===this||el?.inside===true},
  querySelector(selector){return {
    '[data-finance-preview-frame]':frame,
    '[data-finance-preview-open]':link,
    '#im-finance-document-modal-title':title
  }[selector]||null;},
  addEventListener(name,callback){modalListeners[name]=callback}
};
const document={
  getElementById(id){return id==='im-finance-document-modal'?modal:null;},
  addEventListener(event,callback){docListeners[event]=callback}
};
const windows=[];
vm.runInNewContext(source,{document,window:{open:(url,target,features)=>windows.push({url,target,features})}});
const realUrl='/finance-document.php?id=12345678-1234-1234-1234-123456789012';
const preview={
  getAttribute(name){return name==='data-finance-preview'?realUrl:'nota fiscal (1).pdf';}
};
function fire(clicked,more={}){
  let prevented=false;
  docListeners.click({
    target:{closest(selector){return selector==='[data-finance-preview]'&&clicked===preview?preview:
      (selector==='[data-finance-preview-close]'&&clicked==='close'?{inside:true}:null);}},
    preventDefault(){prevented=true},
    ...more
  });
  return prevented;
}
assert.equal(fire(preview),true,'preview should handle click');
assert.equal(modal.open,true,'modal opens in current screen');
assert.equal(frame.attributes.src,realUrl,'iframe uses existing protected document endpoint');
assert.equal(link.attributes.href,realUrl,'open-in-new-tab fallback is same secure route');
assert.equal(title.textContent,'nota fiscal (1).pdf','filename shown as text (not HTML)');
assert.equal(fire('close'),true,'close should respond to button');
assert.equal(modal.open,false,'modal closes');
assert.equal(frame.attributes.src,undefined,'iframe is unloaded after closing to release PDF memory');
assert.equal(link.attributes.href,undefined,'no stale document in fallback');

const malicious={getAttribute:name=>
  name==='data-finance-preview'?'https://attacker.example/steal':'Malicious'};
docListeners.click({target:{closest:selector=>selector==='[data-finance-preview]'?malicious:null},preventDefault(){}});
assert.equal(modal.open,false,'malicious URL never opens a preview');
assert.equal(windows.length,0,'only same-origin document URLs allowed');
const malformed={getAttribute:name=>
  name==='data-finance-preview'?'/finance-document.php?id=javascript:alert(1)':'Malicious'};
docListeners.click({target:{closest:selector=>selector==='[data-finance-preview]'?malformed:null},preventDefault(){}});
assert.equal(modal.open,false,'malformed UUID never opens a preview');

console.log('PASS 3.7.78: visible mobile edit, view, attach, receipt and protected delete; secure per-church document indexing, multiple attachments, same-page modal and URL validation; no horizontal desktop table on mobile.');
