'use strict';
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const src=fs.readFileSync(path.join(__dirname,'../public/finance.php'),'utf8');
const preview=fs.readFileSync(path.join(__dirname,'../../3.7.78/public/assets/finance-document-preview-3778.js'),'utf8');
const css=[...src.matchAll(/<style(?:\s[^>]*)?>([\s\S]*?)<\/style>/gi)].map(m=>m[1]).join('\n');
assert(css.includes('im-finance-mobile-tools'));
const uuid='12345678-1234-1234-1234-123456789012';
const html=[
'<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8">',
'<meta name="viewport" content="width=device-width, initial-scale=1">',
'<style>*{box-sizing:border-box}body{font-family:Arial;margin:0;padding:12px;background:#f3f7fa;color:#123}',
'.grid.two-col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}',
'.card.card-pad{background:white;padding:16px;border:1px solid #d3e1eb;border-radius:15px}',
'.im-tithe-mobile{display:none}.table-wrap{overflow-x:auto}',
'.btn{border:1px solid #bccddd;border-radius:9px;padding:10px;color:#124874;background:#f4faff;cursor:pointer}',
'.btn-primary{background:#145c91;color:#fff;border-color:#145c91}</style>',
'<style>',css,'</style></head><body>',
'<main class="grid two-col im-finance-main-layout">',
'<section class="card card-pad im-finance-movements" id="finance-movements"><h2>Movimentações</h2>',
'<div class="im-tithe-mobile im-finance-mobile-list">',
'<article class="im-tithe-item im-finance-mobile-card" data-finance-row data-finance-view="mobile">',
'<div class="im-finance-mobile-card-main">',
'<div class="im-finance-mobile-meta"><span class="im-finance-mobile-date">18/11/2026</span>',
'<span class="im-finance-mobile-kind im-finance-kind-expense">SAÍDA</span>',
'<span class="im-finance-mobile-status">Ativo</span></div>',
'<h3 class="im-finance-mobile-description">Pagamento de compra da igreja</h3>',
'<div class="im-finance-mobile-money im-finance-expense">− R$ 2.172,03</div>',
'<div class="im-finance-entry-identity im-finance-entry-expense"><strong>Tipo da saída: Material de limpeza</strong>',
'<span><strong>Favorecido:</strong> Loja da Igreja</span></div>',
'<div class="im-finance-mobile-details"><span><strong>Favorecido:</strong> Loja da Igreja</span>',
'<span><strong>Caixa/conta:</strong> Banco Geral</span></div></div>',
'<div class="im-finance-mobile-tools">',
'<a class="btn btn-primary im-finance-action im-finance-edit" href="#editar">✏️ Editar</a>',
'<button class="btn btn-light im-finance-action im-finance-view" type="button"',
' data-finance-preview="/finance-document.php?id=',uuid,'"',
' data-finance-preview-name="nota fiscal.pdf">👁 Visualizar documento</button>',
'<a class="btn btn-light im-finance-action im-finance-attach" href="#anexar">📎 Anexar arquivo</a>',
'<a class="btn btn-light im-finance-action im-finance-receipt" href="#recibo">🧾 Ver recibo</a>',
'<div class="im-finance-action im-finance-delete">',
'<details class="im-church-entry-exclude"><summary class="btn btn-danger">Excluir lançamento</summary>',
'<form><label>Motivo<textarea></textarea></label></form></details></div></div></article></div>',
'<div class="table-wrap"><table class="table" style="min-width:1400px"><thead><tr>',
'<th>Data</th><th>Categoria</th><th>Descrição</th><th>Documentos</th><th>Ações</th>',
'</tr></thead><tbody><tr><td>18/11/2026</td><td>Despesa</td><td>Compra</td>',
'<td><button>Ver</button></td><td><button>Editar</button></td></tr></tbody></table></div>',
'</section></main>',
'<dialog id="im-finance-document-modal" class="im-finance-document-modal">',
'<div class="im-finance-document-modal-head">',
'<strong id="im-finance-document-modal-title">Visualizar documento</strong>',
'<button class="btn btn-light" type="button" data-finance-preview-close>✕ Fechar</button></div>',
'<div class="im-finance-document-modal-body"><iframe data-finance-preview-frame title="Documento"></iframe></div>',
'<div class="im-finance-document-modal-foot"><a data-finance-preview-open target="_blank" rel="noopener">Abrir</a>',
'<button class="btn btn-light" data-finance-preview-close>Fechar</button></div></dialog>',
'</body></html>'
].join('');

(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
   for(const width of [320,390,768,1024]){
     const page=await browser.newPage({viewport:{width,height:780},deviceScaleFactor:1});
     await page.route('**/finance-document.php?*',route=>route.fulfill({
       status:200,contentType:'image/png',
       body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8zwAAAgEBAGl8FDcAAAAASUVORK5CYII=','base64')
     }));
     await page.setContent(html);
     await page.addScriptTag({content:preview});
     const card=page.locator('.im-finance-mobile-card');
     const table=page.locator('#finance-movements > .table-wrap');
     if(width<=900){
       assert(await card.isVisible(),'mobile card invisible at '+width);
       assert(!(await table.isVisible()),'desktop table visible on mobile at '+width);
       const metrics=await page.evaluate(()=>{
         const buttons=[...document.querySelectorAll('.im-finance-mobile-tools .im-finance-action')];
         return {scrollWidth:document.documentElement.scrollWidth,viewport:innerWidth,
           toolWidth:document.querySelector('.im-finance-mobile-tools')?.getBoundingClientRect().width,
           toolDisplay:getComputedStyle(document.querySelector('.im-finance-mobile-tools')).display,
           cardWidth:document.querySelector('.im-finance-mobile-card')?.getBoundingClientRect().width,
           buttons:buttons.map(el=>({label:el.textContent.trim(),
             x1:el.getBoundingClientRect().left,x2:el.getBoundingClientRect().right,
             width:el.getBoundingClientRect().width,
             computedWidth:getComputedStyle(el).width,
             display:getComputedStyle(el).display}))};
       });
       console.log('MOBILE_METRICS',width,JSON.stringify(metrics));
       assert(metrics.scrollWidth<=width+1,
         'horizontal PAGE scroll at '+width+': '+JSON.stringify(metrics));
       for(const button of metrics.buttons)
         assert(button.width>60&&button.x1>=0&&button.x2<=width+1,
           'button outside first mobile screen at '+width+': '+JSON.stringify(button));
       const subtype=await page.locator('.im-finance-entry-identity>strong').innerText();
       assert(subtype.includes('Tipo da saída: Material de limpeza'),'expense subtype prominent at '+width);
       const kind=await page.locator('.im-finance-mobile-kind').innerText();
       assert(kind==='SAÍDA','category badge prominent at '+width);
       const labels=metrics.buttons.map(x=>x.label).join(' | ');
       for(const name of ['Editar','Visualizar documento','Anexar arquivo','Ver recibo','Excluir lançamento'])
         assert(labels.includes(name),'missing mobile action: '+name);
       await page.locator('[data-finance-preview]').click();
       assert(await page.locator('#im-finance-document-modal').evaluate(el=>el.open),
         'preview modal did not open at '+width);
       assert((await page.locator('[data-finance-preview-frame]').getAttribute('src'))===
         '/finance-document.php?id='+uuid,'secure document preview URL');
       await page.locator('[data-finance-preview-close]').first().click();
       assert(!(await page.locator('#im-finance-document-modal').evaluate(el=>el.open)),
         'modal did not close at '+width);
     }else{
       assert(!(await card.isVisible()),'card visible in desktop mode');
       assert(await table.isVisible(),'desktop table hidden in desktop mode');
     }
     console.log('PASS Chromium '+width+'px: no horizontal phone scroll and visible actions, protected preview');
     await page.close();
   }
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
