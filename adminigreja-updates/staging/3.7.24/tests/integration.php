<?php
declare(strict_types=1);
/** Cumulative synthetic MariaDB QA + treasury mobile/accountant upgrade. */
require __DIR__.'/../../3.7.23/tests/integration.php';
require_once $dir.'/public/lib/TreasurySmartEntry.php';

$check(TreasurySmartEntry::isTithe('Dízimos'),'category matches plural accented tithes');
$check(TreasurySmartEntry::isTithe('DIZIMO'),'category matches unaccented tithes');
$check(!TreasurySmartEntry::isTithe('Ofertas'),'offer is not a tithe');
$check(TreasurySmartEntry::isOffering('OFERTA'),'category matches singular offer');
$check(TreasurySmartEntry::normalizeDescription('income','Dízimos','')==='Dízimo',
    'tithe description automatically generated');
$check(TreasurySmartEntry::normalizeDescription('income','Ofertas','')==='Oferta',
    'offering description automatically generated');
try{TreasurySmartEntry::normalizeDescription('expense','Alimentação','');throw new RuntimeException('empty expense description');}
catch(InvalidArgumentException $e){$check(true,'expense requires description');}
try{TreasurySmartEntry::validateMember('income','Dízimos',null);throw new RuntimeException('anonymous tithe accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'membro'),'tithes require member');}
TreasurySmartEntry::validateMember('income','Ofertas',null);
TreasurySmartEntry::validateMember('income','Dízimos',$memberA);
try{TreasurySmartEntry::validateMember('expense','Alimentação',$memberA);throw new RuntimeException('expense donor accepted');}
catch(InvalidArgumentException $e){$check(true,'expenses exclude contributor');}
$check(TreasurySmartEntry::amount('R$ 1.234,56')==='1234.56','currency parser accepts Brazilian currency');
$check(TreasurySmartEntry::amount('12,34')==='12.34','currency parser accepts comma');
foreach(['0,00','-2,00','1,2345','123abc'] as $bad)
  try{TreasurySmartEntry::amount($bad);throw new RuntimeException('invalid amount accepted '.$bad);}
  catch(InvalidArgumentException $e){$check(true,'invalid money rejected');}
$catalog=TreasurySmartEntry::categories($pdo,$churchA);
$check(in_array('Dízimos',array_column($catalog,'name'),true),'general income category included');
$check(in_array('Energia',array_column($catalog,'name'),true),'expense category included');
$check(!in_array('Dízimos',TreasurySmartEntry::expenseCategoryNames($pdo,$churchA),true),
    'income-only category not offered for expense OCR');
$check(TreasurySmartEntry::validMember($pdo,$churchA,$memberA)===$memberA,'member of same church accepted');
try{TreasurySmartEntry::validMember($pdo,$churchA,$foreign);throw new RuntimeException('foreign contributor accepted');}
catch(InvalidArgumentException $e){$check(true,'tenant member boundary');}
$check(TreasurySmartEntry::reviewedItems([])===[],'manual filing needs no OCR');
try{TreasurySmartEntry::reviewedItems(['ocr_items_json'=>'[{"name":"Arroz","amount":"10,00"}]']);
 throw new RuntimeException('unconfirmed OCR accepted');}
catch(InvalidArgumentException $e){$check(true,'OCR review is mandatory');}
$items=TreasurySmartEntry::reviewedItems(['ocr_confirmed'=>'1',
 'ocr_items_json'=>'[{"name":"Arroz","amount":"10,00","category":"Alimentação e cozinha"},{"name":"Detergente","amount":"5,20","category":"Material de limpeza"}]']);
$check(count($items)===2 && $items[1]['amount']==='5.20','OCR items validated and classified');
TreasurySmartEntry::ensureSchema($pdo);
TreasurySmartEntry::ensureSchema($pdo);
$check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
 WHERE table_schema=DATABASE() AND table_name='finance_entry_items'")->fetchColumn()===1,
 'itemized receipt migration idempotent');

$docQ=$pdo->prepare('SELECT id FROM finance_documents WHERE church_id=? AND entry_id=? LIMIT 1');
$docQ->execute([$churchA,$expenseEntry]);$originalDoc=(string)$docQ->fetchColumn();
$check($originalDoc!=='','previous signed receipt has original finance document');
TreasurySmartEntry::saveItems($pdo,$churchA,$expenseEntry,$originalDoc,$admin,$items);
$q=$pdo->prepare('SELECT description,category,amount FROM finance_entry_items WHERE church_id=? AND entry_id=? ORDER BY description');
$q->execute([$churchA,$expenseEntry]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
$check(count($rows)===2,'two reviewed items attached to one ledger expense');
$check((int)$pdo->query("SELECT COUNT(*) FROM finance_entry_items WHERE church_id='".$churchB."'")->fetchColumn()===0,
 'other church does not inherit itemized purchases');
$check((int)$pdo->query("SELECT COUNT(*) FROM finance_entries WHERE id='".$expenseEntry."'")->fetchColumn()===1,
 'OCR never duplicates financial entry');

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$treasuryAuth=new Auth($pdo);
$dept=new DepartmentTreasuryService($pdo,$treasuryAuth,$churchA,$admin);
try{$dept->createEntry($deptId,[
 'account_id'=>$accountId,'direction'=>'income','category'=>'Dízimos','description'=>'',
 'amount'=>'19,50','transaction_date'=>'2026-11-04'
],['error'=>UPLOAD_ERR_NO_FILE]);throw new RuntimeException('department accepted anonymous tithe');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'membro'),'department tithe member mandatory');}
$titheEntry=$dept->createEntry($deptId,[
 'account_id'=>$accountId,'direction'=>'income','category'=>'Dízimos','description'=>'',
 'amount'=>'19,50','transaction_date'=>'2026-11-04','member_id'=>$memberA
],['error'=>UPLOAD_ERR_NO_FILE]);
$titheQ=$pdo->prepare('SELECT church_id,department_id,member_id,description FROM finance_entries WHERE id=?');
$titheQ->execute([$titheEntry]);$titheRow=$titheQ->fetch(PDO::FETCH_ASSOC);
$check($titheRow['church_id']===$churchA && $titheRow['department_id']===$deptId
 && $titheRow['member_id']===$memberA,'tithe remains in the selected department cashbook and personal donor ledger');
$check($titheRow['description']==='Dízimo','department tithe description is automatic');

foreach([
 ['public/finance.php',['data-smart-treasury="general"','data-category-search','data-money-br','account_update','TreasurySmartEntry::saveItems','data-read-ocr']],
 ['public/department-treasury.php',['data-smart-treasury="department"','data-category-search','name="member_id"','data-read-ocr']],
 ['public/accounting.php',['finance_entry_items','finance-ocr-items-csv.php','accounting-ocr-items']],
 ['public/finance-contact-create.php',['verify_csrf()','church_id','department_treasury']],
 ['public/finance-parse.php',['verify_csrf()','ReceiptParser::parse','review_required']],
 ['public/finance-ocr-items-csv.php',['accounting_portal','fputcsv','church_id']],
] as [$file,$needles]){
 $contents=file_get_contents($dir.'/'.$file);
 foreach($needles as $needle)$check(str_contains($contents,$needle),$file.' contains '.$needle);
}
echo "PASS 3.7.24 currency, conditional description, linked tithe, general/department scopes, itemized OCR and accountant export\n";
