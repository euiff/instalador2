<?php
declare(strict_types=1);
/** Cumulative tests + accounting origin separation, all fictional accounts. */
require __DIR__.'/../../3.7.21/tests/integration.php';
require_once $dir.'/public/lib/GeneralTreasuryAccountScope.php';
require_once $dir.'/public/lib/FinanceCsvImport.php';
require_once $dir.'/public/lib/FinanceOperations.php';
require_once $dir.'/public/lib/DepartmentTreasuryService.php';
require_once $dir.'/public/lib/DepartmentReportService.php';

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$financeAuth=new Auth($pdo);
$check($financeAuth->can('finance'),'authorized general cashier fixture');
$departmentCash=$pdo->prepare('SELECT id,name FROM finance_accounts WHERE church_id=? AND department_id=? AND active=1 LIMIT 1');
$departmentCash->execute([$churchA,$deptId]);
$deptAccount=$departmentCash->fetch(PDO::FETCH_ASSOC);
$check(is_array($deptAccount),'real ministry account fixture exists');
$generalAccountId=app_uuid();
$pdo->prepare('INSERT INTO finance_accounts(id,church_id,name,account_type,opening_balance,active)
               VALUES(?,?,?,\'cash\',0,1)')->execute([$generalAccountId,$churchA,'Caixa Geral de Testes 3722']);
$generalAccount=GeneralTreasuryAccountScope::assertGeneral($pdo,$churchA,$generalAccountId);
$check($generalAccount['id']===$generalAccountId,'general account belongs to church general');
$check($generalAccount['department_id']===null,'general account has no ministry');
$check(GeneralTreasuryAccountScope::assertGeneral($pdo,$churchA,null)===null,
  'general cashier can record transaction without choosing account');
$check(in_array($generalAccountId,array_column(GeneralTreasuryAccountScope::list($pdo,$churchA),'id'),true),
  'general account appears in posting choices');
$check(!in_array($deptAccount['id'],array_column(GeneralTreasuryAccountScope::list($pdo,$churchA),'id'),true),
  'department cashbox not presented in general posting choices');
try{
  GeneralTreasuryAccountScope::assertGeneral($pdo,$churchA,$deptAccount['id']);
  throw new RuntimeException('department account incorrectly accepted by general cashier');
}catch(InvalidArgumentException $e){
  $check(str_contains($e->getMessage(),'departamento'),'server rejects forged department account');
}
try{
  GeneralTreasuryAccountScope::assertGeneral($pdo,$churchB,$generalAccountId);
  throw new RuntimeException('account from another church accepted');
}catch(InvalidArgumentException $e){
  $check(str_contains($e->getMessage(),'igreja'),'cross-church account denied');
}

$financeOps=new FinanceOperations($pdo,$financeAuth,$churchA);
$check($financeOps->generalAccount($generalAccountId)['id']===$generalAccountId,
 'general receivables use same account guard');
try{
  $financeOps->createReceivables([
    'payer_name'=>'Cliente de teste','category'=>'Ofertas','description'=>'Recebimento previsto',
    'amount'=>'40.00','due_date'=>'2026-11-04','account_id'=>$deptAccount['id']
  ]);
  throw new RuntimeException('department cashbox allowed in general receivables');
}catch(InvalidArgumentException $e){
  $check(str_contains($e->getMessage(),'departamento'),'new general receivable cannot bind a ministry account');
}

// The contributor belongs to the department. His relationship must never
// move a cashier's general donation into the youth or worship account.
$memberRows=$pdo->prepare('SELECT name FROM members WHERE church_id=? AND id=? LIMIT 1');
$memberRows->execute([$churchA,$memberA]);$memberName=(string)$memberRows->fetchColumn();
$check($memberName!=='','member contribution fixture exists');
$memberRoles=new MemberDepartmentService($pdo,$financeAuth,$churchA,$admin);
$memberRoles->assign($memberA,$deptId,'participant');

$date='2026-11-04';$from='2026-11-01';$to='2026-11-30';
$departmentService=new DepartmentTreasuryService($pdo,$financeAuth,$churchA,$admin);
$summaryOf=static function(array $rows,string $id):int{
  foreach($rows as $row)if((string)$row['id']===$id)return (int)$row['income_cents'];
  throw new RuntimeException('Department cash summary missing');
};
$ministryBefore=$summaryOf($departmentService->summary($from,$to),$deptId);
$generalBefore=(string)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM finance_entries
 WHERE church_id='".$churchA."' AND direction='income' AND status='posted'
 AND transaction_date BETWEEN '".$from."' AND '".$to."'")->fetchColumn();

// Older financial fixture omits newer general-ledger metadata. Add only
// missing columns in this disposable MariaDB instance, not in production.
foreach(['member_id'=>'CHAR(36) NULL','cost_center_id'=>'CHAR(36) NULL',
         'contact_id'=>'CHAR(36) NULL','request_key'=>'CHAR(64) NULL'] as $col=>$sqlType){
  $meta=$pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
                      AND TABLE_NAME='finance_entries' AND COLUMN_NAME=?");
  $meta->execute([$col]);
  if(!$meta->fetchColumn())$pdo->exec('ALTER TABLE finance_entries ADD COLUMN '.$col.' '.$sqlType);
}
// Prior feature-regression fixtures do not load optional financial import batches.
// Apply the platform migration on this isolated test database before testing CSV.
$batchMigration=file_get_contents($dir.'/public/database/030_finance_import_batches.sql');
$check(is_string($batchMigration)&&trim($batchMigration)!=='','release contains the official CSV batch schema');
foreach(explode(';',(string)$batchMigration) as $statement){
  $statement=trim($statement);
  if($statement!=='')$pdo->exec($statement);
}
$csv=new FinanceCsvImport($pdo,$financeAuth,$churchA);
$head="Data;Tipo;Categoria;Descrição;Valor;Membro;Conta;Centro de custos\n";
$line="$date;Entrada;Dízimos;Dízimo geral de membro do ministério;100,00;$memberName;Caixa Geral de Testes 3722;\n";
$preview=$csv->preview($head.$line);
$check(count($preview['rows'])===1,'import creates one general-income draft');
$check($preview['rows'][0]['member_id']===$memberA,'donor linked to member as expected');
$check($preview['rows'][0]['account_id']===$generalAccountId,'general cashier scope selected');
$csv->commit($preview);
$query=$pdo->prepare("SELECT id,church_id,account_id,member_id,department_id,direction,amount,source
 FROM finance_entries WHERE church_id=? AND description=? ORDER BY created_at DESC LIMIT 1");
$query->execute([$churchA,'Dízimo geral de membro do ministério']);$generalEntry=$query->fetch(PDO::FETCH_ASSOC);
$check($generalEntry['member_id']===$memberA,'dízimo remains visible in personal member history');
$check($generalEntry['account_id']===$generalAccountId,'cash received by general cashier remains general');
$check($generalEntry['department_id']===null,'member affiliation does not assign ministry department');
$check($generalEntry['source']==='csv_import','general imports have identifiable source');
$check($summaryOf($departmentService->summary($from,$to),$deptId)===$ministryBefore,
  'dízimo of ministry member does not raise ministry treasury totals');
$deptReport=(new DepartmentReportService($pdo,$financeAuth,$churchA))->report($from,$to,$deptId);
$reportIncome=array_sum(array_map(static fn($x)=>(int)$x['income_cents'],$deptReport));
$deptBefore=$reportIncome;
$check($reportIncome<FinanceOperations::cents($generalBefore)+10000 ||
       $summaryOf($departmentService->summary($from,$to),$deptId)===$ministryBefore,
  'department report is not fed by member affiliation');

// The same member may contribute directly to the department cashbox, but
// this requires an explicit authorized department-treasury posting.
$deptNew=$departmentService->createEntry($deptId,[
  'account_id'=>$deptAccount['id'],'direction'=>'income','category'=>'Ofertas',
  'description'=>'Oferta recebida no caixa do ministério','amount'=>'50.00',
  'transaction_date'=>$date,'counterparty'=>'Contribuição ao ministério'
],['error'=>UPLOAD_ERR_NO_FILE]);
$q=$pdo->prepare('SELECT id,source,department_id,account_id,amount FROM finance_entries WHERE church_id=? AND id=?');
$q->execute([$churchA,$deptNew]);$deptMovement=$q->fetch(PDO::FETCH_ASSOC);
$check($deptMovement['source']==='department','only explicit ministry flow has department source');
$check($deptMovement['department_id']===$deptId,'department entry belongs to selected ministry');
$check($deptMovement['account_id']===$deptAccount['id'],'department post has own cashbox');
$check($summaryOf($departmentService->summary($from,$to),$deptId)===$ministryBefore+5000,
  'ministry income increases only by explicit ministry posting');
$deptReportAfter=(new DepartmentReportService($pdo,$financeAuth,$churchA))->report($from,$to,$deptId);
$deptAfter=array_sum(array_map(static fn($x)=>(int)$x['income_cents'],$deptReportAfter));
$check($deptAfter===$deptBefore+5000,'department financial report increases only 50 reais');

$generalAfter=(string)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM finance_entries
 WHERE church_id='".$churchA."' AND direction='income' AND status='posted'
 AND transaction_date BETWEEN '".$from."' AND '".$to."'")->fetchColumn();
$check(FinanceOperations::cents($generalAfter)===FinanceOperations::cents($generalBefore)+15000,
 'consolidated church ledger adds general 100 plus department 50 once');

// Prevent forged CSV data despite correct preview, plus wrong department names.
$forge=$preview;$forge['hash']=hash('sha256','another-fixture-csv-3722');
$forge['rows'][0]['account_id']=$deptAccount['id'];
try{
  $csv->commit($forge);
  throw new RuntimeException('forged import posted to ministry');
}catch(InvalidArgumentException $e){
  $check(str_contains($e->getMessage(),'departamento'),'tampered CSV cannot post into department');
}
$badCsv="$head$date;Entrada;Dízimos;Falha esperada;15,00;$memberName;".$deptAccount['name'].";\n";
try{$csv->preview($badCsv);throw new RuntimeException('department CSV allowed in general import');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'Linha'),'CSV department cashbox excluded in general import');}

$code=file_get_contents($dir.'/public/finance.php');
$check(str_contains($code,'$departmentId=null'),'general UI explicitly stores NULL department');
$check(str_contains($code,'$generalAccounts'),'general UI filters department accounts');
$check(substr_count($code,'GeneralTreasuryAccountScope::assertGeneral')>=4,
  'general manual, receipt, payable and settlement paths guarded');
$check(str_contains($code,'Não transfere o valor para o departamento'),
  'member selector explains contributions do not go to member ministry');
$accountingCode=file_get_contents($dir.'/public/lib/AccountingService.php');
$check(substr_count($accountingCode,'GeneralTreasuryAccountScope::assertGeneral')===2,
  'accounting-created expenses also restricted to general cashbox');
$check(str_contains(file_get_contents($dir.'/public/finance-control.php'),'department_id IS NULL'),
  'receivable form displays only general accounts');
$check(str_contains(file_get_contents($dir.'/public/finance-import.php'),'pertencem ao Geral'),
  'finance CSV import page clearly labels general source');
echo "PASS 3.7.22 donor ministry not used for fund destination; general income 100, ministry income 50, consolidated 150 once, CSV/receivables guards, tenant checks\n";
