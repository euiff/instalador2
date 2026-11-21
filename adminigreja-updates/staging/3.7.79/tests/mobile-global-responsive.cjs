'use strict';
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const root=path.join(__dirname,'../public');
const css=fs.readFileSync(path.join(root,'assets/mobile-responsive-3779.css'),'utf8');
const js=fs.readFileSync(path.join(root,'assets/mobile-responsive-3779.js'),'utf8');
const view=fs.readFileSync(path.join(root,'lib/View.php'),'utf8');
assert(view.includes('/assets/mobile-responsive-3779.css?v=3.7.79'),'global mobile CSS registered in shared View header');
assert(view.includes('/assets/mobile-responsive-3779.js?v=3.7.79'),'global mobile JS registered in shared View header');
assert(!js.includes('fetch('),'mobile enhancement must not send user data offsite');
const html=[
'<!doctype html><html lang="pt-BR"><head><meta name="viewport" content="width=device-width, initial-scale=1">',
'<style>body{font-family:Arial;margin:0;padding:10px;background:#f3f7fa}',
'.content{width:100%;box-sizing:border-box}.card{padding:14px;background:white;border:1px solid #ccd}',
'.grid.two-col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}',
'.table-wrap{overflow-x:auto;max-width:100%}',
'.table-wrap table{min-width:980px;width:100%;border-collapse:collapse}',
'.table-wrap table td{padding:11px}.table-wrap table th{padding:9px}',
'.btn{min-width:80px;min-height:42px}.content input{min-width:270px}',
'</style><style>',css,'</style></head><body><main class="content">',
'<h1>Cadastro e permissões</h1><div class="grid two-col"><section class="card" id="card-one">',
'<h2>Membros</h2><div class="table-wrap"><table id="members-table">',
'<thead><tr><th>Nome</th><th>E-mail</th><th>Cargo</th><th>Ações</th></tr></thead>',
'<tbody><tr><td>Maria de Sousa</td><td>maria@example.com</td><td>Secretaria</td>',
'<td><button class="btn" id="save-member" onclick="window.__clicked=(window.__clicked||0)+1">Editar acesso</button>',
'<a class="btn" href="#senha">Redefinir senha</a></td></tr>',
'<tr><td>João Silva</td><td>joao.silva.longofield@example.com</td><td>Tesoureiro geral</td>',
'<td><button class="btn">Ver cadastro</button></td></tr></tbody></table></div></section>',
'<section class="card" id="card-two"><h2>Relatórios</h2>',
'<div class="table-responsive"><table id="report-table"><thead><tr>',
'<th>Data</th><th>Valor</th><th>Tipo</th></tr></thead><tbody>',
'<tr><td>20/11/2026</td><td>R$ 100,00</td><td>Entrada</td></tr>',
'</tbody></table></div></section></div>',
'<section class="card"><h2>Agenda (grade especial preservada)</h2>',
'<div class="table-wrap calendar"><table id="calendar-table" style="min-width:1200px">',
'<thead><tr><th>Segunda</th><th>Terça</th><th>Quarta</th></tr></thead>',
'<tbody><tr><td>09h</td><td>10h</td><td>11h</td></tr></tbody></table></div></section>',
'<section class="card"><h2>Tabela com colunas mescladas</h2>',
'<div class="table-wrap"><table id="complex-table">',
'<thead><tr><th>Nome</th><th>Tipo</th></tr></thead>',
'<tbody><tr><td colspan="2">Resumo com totalização e estrutura complexa que deve permanecer como tabela</td></tr>',
'<tr><td rowspan="2">Categoria</td><td>Item 1</td></tr><tr><td>Item 2</td></tr>',
'</tbody></table></div></section>',
'<section class="card"><h2>Tesouraria (layout próprio)</h2>',
'<div class="im-finance-movements"><div class="table-wrap">',
'<table id="finance-table"><thead><tr><th>Data</th><th>Ações</th></tr></thead>',
'<tbody><tr><td>18/11/2026</td><td>Editar</td></tr></tbody></table></div></div></section>',
'<section class="card"><h2>Busca dinâmica</h2><div class="table-wrap" id="dynamic-here"></div></section>',
'</main></body></html>'
].join('');

(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  for(const width of [320,390,768,1024]){
   const page=await browser.newPage({viewport:{width,height:850},deviceScaleFactor:1});
   await page.setContent(html);
   await page.addScriptTag({content:js});
   await page.waitForFunction(()=>document.querySelector('#members-table')?.classList.contains('im-mobile-card-table'));
   const members=page.locator('#members-table');
   const reports=page.locator('#report-table');
   assert(await reports.evaluate(el=>el.classList.contains('im-mobile-card-table')),
      'other section report converted into cards');
   assert(!(await page.locator('#calendar-table').evaluate(el=>el.classList.contains('im-mobile-card-table'))),
      'calendar is kept as a native grid');
   assert(!(await page.locator('#complex-table').evaluate(el=>el.classList.contains('im-mobile-card-table'))),
      'table with rowspan retained without corrupting row semantics');
   assert(!(await page.locator('#finance-table').evaluate(el=>el.classList.contains('im-mobile-card-table'))),
      'finance-specific display not converted a second time');
   const firstLabel=await members.locator('tbody>tr:first-child>td:nth-child(2)').getAttribute('data-im-mobile-label');
   assert.equal(firstLabel,'E-mail','column label generated from original table headings');
   const aria=await members.locator('tbody>tr:first-child>td:nth-child(2)').getAttribute('aria-label');
   assert.equal(aria,'E-mail','table field is accessible to assistive technology');
   if(width<=900){
    const metrics=await page.evaluate(()=>{
     const btn=document.getElementById('save-member').getBoundingClientRect();
     const grid=document.querySelector('.grid.two-col');
     return {doc:document.documentElement.scrollWidth,width:innerWidth,
       gridColumns:getComputedStyle(grid).gridTemplateColumns,
       button:{x1:btn.left,x2:btn.right},
       tableDisplay:getComputedStyle(document.querySelector('#members-table')).display,
       cardDisplay:getComputedStyle(document.querySelector('#members-table tbody>tr')).display,
       calendar:getComputedStyle(document.querySelector('#calendar-table')).display};
    });
    assert(metrics.doc<=width+1,'full PAGE must not scroll horizontally: '+JSON.stringify(metrics));
    assert(metrics.button.x1>=0&&metrics.button.x2<=width+1,
      'user should not scroll horizontally to reach edit button: '+JSON.stringify(metrics));
    assert.equal(metrics.tableDisplay,'block','regular table turned into cards');
    assert.equal(metrics.cardDisplay,'flex','table row is mobile card');
   }
   await page.locator('#save-member').click();
   assert.equal(await page.evaluate(()=>window.__clicked),1,'existing action still works after conversion');
   // AJAX content added after first render must also become mobile cards.
   await page.locator('#dynamic-here').evaluate(el=>{
      el.innerHTML='<table id="dynamic-table"><thead><tr><th>Nome</th><th>Ações</th></tr></thead>'+
       '<tbody><tr><td>Adicionado agora</td><td><button>Editar</button></td></tr></tbody></table>';
   });
   await page.waitForFunction(()=>
    document.querySelector('#dynamic-table')?.classList.contains('im-mobile-card-table'));
   assert.equal(await page.locator('#dynamic-table td:last-child').getAttribute('data-im-mobile-label'),
      'Ações','new dynamically inserted table also gains labels');
   console.log('PASS global mobile Chromium',width,'px: registrations, reports, action buttons, financial display opt-out, special tables & dynamic cards');
   await page.close();
  }
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
