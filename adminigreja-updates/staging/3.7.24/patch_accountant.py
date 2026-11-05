from patch_finance_core import replace_once

ACCOUNTANT=r'''<section class="card card-pad acc-section" id="accounting-ocr-items">
 <div class="page-head"><div>
   <h2>Produtos e categorias dos cupons</h2>
   <p>Itens extraídos de fotos e conferidos pelo tesoureiro. Use a nota original para validação contábil.</p>
 </div><a class="btn btn-light" href="/finance-ocr-items-csv.php?period=<?=rawurlencode($period)?>">Exportar produtos CSV</a></div>
 <div class="acc-table"><table><thead><tr>
 <th>Data</th><th>Origem</th><th>Produto</th><th>Categoria</th><th>Valor</th><th>Comprovante</th>
 </tr></thead><tbody>
 <?php foreach($ocrItems as $item):?><tr>
 <td><?=e(date('d/m/Y',strtotime($item['transaction_date'])))?></td>
 <td><?=e($item['department_name']?:'Tesouraria Geral')?></td>
 <td><?=e($item['description'])?></td>
 <td><?=e($item['category'])?></td>
 <td><?=number_format((float)$item['amount'],2,',','.')?></td>
 <td><a class="btn btn-light" href="/accounting-file.php?kind=legacy&amp;id=<?=rawurlencode($item['finance_document_id'])?>">Original</a></td>
 </tr><?php endforeach;?>
 <?php if(!$ocrItems):?><tr><td colspan="6">Nenhum produto extraído e conferido nesta competência.</td></tr><?php endif;?>
 </tbody></table></div>
 <p class="muted">OCR não confirma autenticidade fiscal. Contador deve conferir dados e notas originais.</p>
</section>'''

def patch_accountant(source):
 t=source.decode('utf8')
 t=replace_once(t,"require_once __DIR__.'/lib/AccountingService.php';",
  "require_once __DIR__.'/lib/AccountingService.php';\nrequire_once __DIR__.'/lib/TreasurySmartEntry.php';",
  'accounting smart reader')
 needle="$summary=$service->summary($period);"
 injection="""TreasurySmartEntry::ensureSchema($pdo);
$ocrFrom=$period.'-01';$ocrTo=date('Y-m-t',strtotime($ocrFrom));
$q=$pdo->prepare("SELECT i.description,i.amount,i.category,i.finance_document_id,
 e.transaction_date,d.name department_name FROM finance_entry_items i
 JOIN finance_entries e ON e.church_id=i.church_id AND e.id=i.entry_id
 LEFT JOIN ministry_departments d ON d.church_id=e.church_id AND d.id=e.department_id
 WHERE i.church_id=? AND e.status='posted' AND e.transaction_date BETWEEN ? AND ?
 ORDER BY e.transaction_date DESC,i.created_at DESC LIMIT 500");
$q->execute([$cid,$ocrFrom,$ocrTo]);$ocrItems=$q->fetchAll(PDO::FETCH_ASSOC);
"""
 t=replace_once(t,needle,injection+needle,'accountant OCR listing')
 needle='<section class="card card-pad acc-section" id="accounting-requests">'
 t=replace_once(t,needle,ACCOUNTANT+'\n'+needle,'accountant category table')
 return t.encode('utf8')
