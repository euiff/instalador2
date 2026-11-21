/* Igreja Master 3.7.79 — make ordinary application tables readable
 * as cards on narrow phones. No financial mutation, no network calls.
 * Conservatively skips tables that are calendars, matrices, or use spans.
 */
(()=>{
 'use strict';
 const selector='.content .table-wrap table, .content .table-responsive table';
 const skip='[data-im-keep-table], .im-finance-movements, #finance-movements, .calendar, .calendar-grid, .schedule-grid, .matrix, .grade-grid';
 const scoped=table=>!table.closest(skip);
 const clean=str=>String(str||'').replace(/\s+/g,' ').trim();

 function transform(table){
   if(!table || table.dataset.imMobileProcessed==='1')return;
   table.dataset.imMobileProcessed='1';
   if(!scoped(table))return;
   if(table.querySelector('table'))return; // nested, hierarchical structures
   const heads=[...table.querySelectorAll(':scope > thead > tr')];
   if(heads.length!==1)return;
   const columns=[...heads[0].children].filter(c=>c.matches('th,td'));
   if(columns.length<2||columns.length>18)return;
   if(columns.some(el=>(el.colSpan||1)!==1||(el.rowSpan||1)!==1))return;
   const tbody=table.tBodies?.[0];
   if(!tbody || table.tBodies.length!==1)return;
   const rows=[...tbody.rows];
   if(!rows.length)return;
   // Complex merged cells don't map reliably to labelled field cards.
   if(rows.some(tr=>[...tr.cells].some(c=>(c.rowSpan||1)>1 ||
     ((c.colSpan||1)>1 && !(tr.cells.length===1&&clean(c.textContent).length<280)))))return;
   if(rows.some(tr=>tr.cells.length!==columns.length &&
      !(tr.cells.length===1 && (tr.cells[0].colSpan||1)>1)))return;
   const headers=columns.map((cell,index)=>
     clean(cell.textContent||cell.getAttribute('aria-label'))||('Campo '+(index+1)));
   rows.forEach(row=>{
     if(row.cells.length===1 && row.cells[0].colSpan>1){
       row.cells[0].setAttribute('data-im-mobile-label','');
       return;
     }
     [...row.cells].forEach((cell,index)=>{
       cell.setAttribute('data-im-mobile-label',headers[index]);
       // When visually hidden, the column heading still must be available
       // to assistive technologies as part of each field.
       if(!cell.hasAttribute('aria-label')&&headers[index])
         cell.setAttribute('aria-label',headers[index]);
     });
   });
   table.classList.add('im-mobile-card-table');
   const wrap=table.closest('.table-wrap,.table-responsive');
   wrap?.classList.add('im-mobile-card-wrap');
 }
 function scan(root){
   if(!root?.querySelectorAll)return;
   if(root.matches?.(selector))transform(root);
   root.querySelectorAll(selector).forEach(transform);
 }
 const start=()=>{
   scan(document);
   // Dynamic tables added by AJAX also become phone cards. This observer
   // checks only new elements; it doesn't re-render or change user data.
   if(typeof MutationObserver==='function'){
     const root=document.querySelector('.content');
     if(root){
       const observer=new MutationObserver(records=>{
         for(const record of records)
           for(const child of record.addedNodes)
             if(child.nodeType===1)scan(child);
       });
       observer.observe(root,{childList:true,subtree:true});
     }
   }
 };
 if(document.readyState==='loading')
   document.addEventListener('DOMContentLoaded',start,{once:true});
 else start();
})();
