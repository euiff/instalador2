<?php
declare(strict_types=1);
/** Reuse 3.7.9 disposable-database test, then verify departmental financial segregation. */
require __DIR__.'/../../3.7.9/tests/mysql_integration.php';
require_once $dir.'/public/lib/DepartmentTreasuryService.php';
require_once $dir.'/public/lib/AccountingService.php';

$pdo->exec('ALTER TABLE finance_accounts ADD COLUMN department_id CHAR(36) NULL, ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE finance_entries ADD COLUMN department_id CHAR(36) NULL');
$pdo->exec('CREATE TABLE ministry_departments (
    id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,name VARCHAR(160) NOT NULL,active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE department_access (church_id CHAR(36),user_id CHAR(36),department_id CHAR(36),PRIMARY KEY(church_id,user_id,department_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE platform_audit_logs (
    id CHAR(36) PRIMARY KEY,user_id CHAR(36),church_id CHAR(36),action VARCHAR(70),details TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$deptYouth=app_uuid();$deptMusic=app_uuid();$deptOther=app_uuid();$leader=app_uuid();
foreach([[$deptYouth,$churchA,'Jovens'],[$deptMusic,$churchA,'Louvor'],[$deptOther,$churchB,'Outra igreja']] as $d)
    $pdo->prepare('INSERT INTO ministry_departments(id,church_id,name) VALUES(?,?,?)')->execute($d);
$pdo->prepare("INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)")->execute([$leader,'leader@example.com','Líder Jovens']);
$pdo->prepare("INSERT INTO church_members(id,church_id,user_id,is_owner,church_role) VALUES(?,?,?,0,'department_leader')")
    ->execute([app_uuid(),$churchA,$leader]);
$pdo->prepare('INSERT INTO department_access(church_id,user_id,department_id) VALUES(?,?,?)')->execute([$churchA,$leader,$deptYouth]);

$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$authT=new Auth($pdo);
$svcGeneral=new DepartmentTreasuryService($pdo,$authT,$churchA,$treasurer);
$check($svcGeneral->mayCreateAccount(),'treasurer creates departmental cash accounts');
$accountYouth=$svcGeneral->createAccount($deptYouth,['account_name'=>'Caixa de Jovens','account_type'=>'cash']);
$accountMusic=$svcGeneral->createAccount($deptMusic,['account_name'=>'Caixa de Louvor','account_type'=>'cash']);
$check(count($svcGeneral->departments())===2,'general sees both departments in the church');

$_SESSION=['user_id'=>$leader,'church_id'=>$churchA];
$authL=new Auth($pdo);
$check($authL->can('department_treasury'),'leader allowed own treasury');
$check(!$authL->can('finance'),'leader blocked from general treasury');
$check(!$authL->can('accounting_portal'),'leader blocked from accountant documents');
$svcLeader=new DepartmentTreasuryService($pdo,$authL,$churchA,$leader);
$check(count($svcLeader->departments())===1,'leader sees only assigned department');
$check(count($svcLeader->accounts($deptYouth))===1,'leader sees only own cash');
try{$svcLeader->accounts($deptMusic);throw new RuntimeException('cross-department access allowed');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não permite'),'leader cannot access another department');}
try{$svcLeader->createAccount($deptYouth,['account_name'=>'Privado','account_type'=>'cash']);throw new RuntimeException('leader created cash');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Somente'),'leader cannot create cash');}
try{$svcLeader->createEntry($deptYouth,[
    'account_id'=>$accountMusic,'direction'=>'income','category'=>'Ofertas','description'=>'Invasão',
    'amount'=>'25,00','transaction_date'=>'2026-11-02'
]);throw new RuntimeException('cross-account posting allowed');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'caixa válido'),'cannot use another department account');}
try{$svcLeader->createEntry($deptYouth,[
    'account_id'=>$accountYouth,'direction'=>'expense','category'=>'Lanche','description'=>'Despesa sem nota',
    'amount'=>'10,00','transaction_date'=>'2026-11-02'
]);throw new RuntimeException('leader expense without proof accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'comprovante'),'leader expense requires proof');}

$id=$svcLeader->createEntry($deptYouth,[
    'account_id'=>$accountYouth,'direction'=>'income','category'=>'Ofertas','description'=>'Oferta do encontro',
    'amount'=>'15,00','transaction_date'=>'2026-11-02'
]);
$check(strlen($id)===36,'entry UUID generated');
$check(count($svcLeader->entries($deptYouth,'2026-11-01','2026-11-30'))===1,'leader sees posted entry');
$check(count($svcLeader->entries($deptYouth,'2026-10-01','2026-10-31'))===0,'date scoped');
try{$svcLeader->entries($deptMusic,'2026-11-01','2026-11-30');throw new RuntimeException('leader read other department');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não permite'),'other department statement blocked');}

$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$generalRows=$svcGeneral->summary('2026-11-01','2026-11-30');
$check(count($generalRows)===2,'general sees all local departments');
$youthRow=array_values(array_filter($generalRows,static fn($r)=>$r['id']===$deptYouth))[0];
$check($youthRow['income_cents']===1500,'income credited once to department');
$check($youthRow['expense_cents']===0,'no expense posted');
$check($youthRow['closing_cents']===1500,'department balance correct');
$general=$svcGeneral->generalPostedTotals('2026-11-01','2026-11-30');
$check((float)$general['income']===15.0,'general income includes department exactly once');
$check((float)$general['expense']===25.50,'general keeps historical nondepartment expense');
$check((int)$pdo->query("SELECT COUNT(*) FROM finance_entries WHERE source='department'")->fetchColumn()===1,'no duplicate ledger entries');

$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
$authAccountant=new Auth($pdo);
$check($authAccountant->can('department_treasury'),'accountant can read breakdown');
$svcAccountant=new DepartmentTreasuryService($pdo,$authAccountant,$churchA,$accountant);
$check(!$svcAccountant->mayPost(),'accountant cannot post');
$check(count($svcAccountant->summary('2026-11-01','2026-11-30'))===2,'accountant sees local breakdown');
$report=new AccountingService($pdo,$authAccountant,$churchA,$accountant);
$ledger=$report->entries('2026-11');
$deptEntries=array_values(array_filter($ledger,static fn($r)=>($r['department_name']??'')==='Jovens'));
$check(count($deptEntries)===1,'accountant CSV can attribute department to ledger');
try{$svcAccountant->createEntry($deptYouth,['account_id'=>$accountYouth,'transaction_date'=>'2026-11-02','amount'=>'999','description'=>'X']);throw new RuntimeException('accountant posted');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'contador'),'accountant prevented from posting');}

$_SESSION=['user_id'=>$outsider,'church_id'=>$churchB];
$authOther=new Auth($pdo);
$svcOther=new DepartmentTreasuryService($pdo,$authOther,$churchB,$outsider);
$check(count($svcOther->departments())===1,'other church sees only own dept');
try{$svcOther->department($deptYouth);throw new RuntimeException('tenant leakage');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não encontrado'),'other tenant rejected');}
$check((float)$svcOther->generalPostedTotals('2026-11-01','2026-11-30')['income']===0.0,'other tenant general has zero');
echo "PASS department ACL, expense proof, general consolidation, no duplicate postings, accountant attribution, other church isolation\n";
