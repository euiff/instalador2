<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/TreasurySmartEntry.php';
$user=$auth->requireAbility('accounting_portal');
$church=$auth->currentChurch();if(!$church){http_response_code(403);exit;}
$cid=(string)$church['id'];
$period=(string)($_GET['period']??date('Y-m'));
if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period)){http_response_code(400);exit('Competência inválida.');}
$from=$period.'-01';$to=date('Y-m-t',strtotime($from));
TreasurySmartEntry::ensureSchema($pdo);
$q=$pdo->prepare("SELECT i.description item_name,i.amount item_amount,i.category item_category,
 e.transaction_date,e.description movement_description,d.name department_name,
 i.finance_document_id document_id
 FROM finance_entry_items i JOIN finance_entries e ON e.id=i.entry_id AND e.church_id=i.church_id
 LEFT JOIN ministry_departments d ON d.id=e.department_id AND d.church_id=e.church_id
 WHERE i.church_id=? AND e.transaction_date BETWEEN ? AND ? AND e.status='posted'
 ORDER BY e.transaction_date ASC,i.created_at ASC");
$q->execute([$cid,$from,$to]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="itens-cupom-'.str_replace('-','',$period).'.csv"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['Data','Departamento','Despesa','Produto','Categoria conferida','Valor (R$)','Documento vinculado'],';','"','');
$escape=static function(mixed $value):string{
    $value=(string)$value;
    return preg_match('/^[\s]*[=+@\-]/u',$value)?"'".$value:$value;
};
while($r=$q->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out,[
      $escape($r['transaction_date']),$escape($r['department_name']?:'Tesouraria Geral'),
      $escape($r['movement_description']),$escape($r['item_name']),
      $escape($r['item_category']),number_format((float)$r['item_amount'],2,',','.'),
      $escape($r['document_id'])
    ],';','"','');
}
fclose($out);
