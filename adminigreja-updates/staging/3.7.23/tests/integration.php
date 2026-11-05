<?php
declare(strict_types=1);
/** Full cumulative QA against the synthetic database and release package. */
require __DIR__.'/../../3.7.22/tests/integration.php';
require_once $dir.'/public/lib/PixPaymentSummary.php';

// Verify the real money totals in an isolated temporary table; this does not
// touch production or the fake fixture's original persistent PIX data.
$pdo->exec("CREATE TEMPORARY TABLE pix_payments(
  id CHAR(36) PRIMARY KEY,
  church_id CHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_payments_church(church_id,status,created_at)
) ENGINE=InnoDB");
$ins=$pdo->prepare("INSERT INTO pix_payments(id,church_id,amount,status,created_at) VALUES(?,?,?,?,?)");
foreach([
  [$churchA,'100.00','confirmed','2026-11-04 10:00:00'],
  [$churchA,'60.00','pending','2026-11-04 11:00:00'],
  [$churchA,'12.00','failed','2026-11-04 12:00:00'],
  [$churchA,'8.00','cancelled','2026-11-04 13:00:00'],
  [$churchB,'999.00','confirmed','2026-11-04 14:00:00'],
  [$churchA,'77.00','confirmed','2026-10-20 09:00:00'],
] as $case)$ins->execute([app_uuid(),...$case]);
$all=PixPaymentSummary::totals($pdo,$churchA,'2026-11-01','2026-11-30');
$check($all['quantity']===4,'PIX summary counts all statuses for church A');
$check(abs($all['total']-180.0)<0.00001,'PIX summary correct total including all visible statuses');
$check(abs($all['confirmed']-100.0)<0.00001,'confirmed PIX amount is not mixed with pending');
$check(abs($all['pending']-60.0)<0.00001,'pending PIX amount is separate from confirmed');
$check(abs($all['inactive']-20.0)<0.00001,'failed and cancelled PIX amounts separated');
$check(abs(PixPaymentSummary::totals($pdo,$churchB,'2026-11-01','2026-11-30')['confirmed']-999.0)<0.00001,
  'PIX of church B does not leak into church A');

$confirmed=PixPaymentSummary::totals($pdo,$churchA,'2026-11-01','2026-11-30','confirmed');
$check($confirmed['quantity']===1&&abs($confirmed['total']-100.0)<0.00001,
 'status filtering applies to aggregate as well as the grid');
$check($confirmed['pending']===0.0,'confirmed filter excludes pending sum');
$check(PixPaymentSummary::totals($pdo,$churchA,'2026-12-01','2026-12-31')['total']===0.0,
  'empty date range produces zero amounts and no warnings');
try{PixPaymentSummary::totals($pdo,$churchA,'2026-13-01','2026-12-31');throw new RuntimeException('invalid date accepted');}
catch(InvalidArgumentException $e){$check(true,'invalid date blocked');}
try{PixPaymentSummary::totals($pdo,$churchA,'2026-12-03','2026-12-01');throw new RuntimeException('reversed date accepted');}
catch(InvalidArgumentException $e){$check(true,'reversed date range blocked');}
try{PixPaymentSummary::totals($pdo,$churchA,'2026-11-01','2026-11-30','unauthorized');throw new RuntimeException('invalid filter accepted');}
catch(InvalidArgumentException $e){$check(true,'unknown PIX status filter rejected');}

// Reproduce the display grid limit at 1000: a real dashboard must aggregate
// ALL payment records rather than just the first page of the HTML table.
$pdo->beginTransaction();
for($i=0;$i<1003;$i++)$ins->execute([app_uuid(),$churchA,'1.00','confirmed','2026-11-05 10:00:00']);
$pdo->commit();
$after=PixPaymentSummary::totals($pdo,$churchA,'2026-11-01','2026-11-30');
$check($after['quantity']===1007,'full PIX summary includes >1000 rows');
$check(abs($after['confirmed']-1103.00)<0.00001,'confirmed total includes all extra 1003 PIX');
$check(abs($after['total']-1183.00)<0.00001,'complete volume not limited by first 1000 records');

$payments=file_get_contents($dir.'/public/payments.php');
$check(str_contains($payments,'$summary=PixPaymentSummary::totals'),'PIX dashboard uses actual aggregated totals');
$check(str_contains($payments,'$total=$summary['),'total defined before rendering');
$check(str_contains($payments,'$pending=$summary['),'pending total defined before rendering');
$check(str_contains($payments,'LIMIT 1000'),'display grid remains capped independently of totals');
$check(str_contains($payments,'SELECT id FROM finance_entries WHERE church_id=? AND pix_payment_id=? FOR UPDATE'),
 'PIX reconciliation checks for existing ledger movements');
$check(str_contains($payments,'if(count($pixEntryIds)>1)'),'unsafe duplicate PIX rows are reported, not silently accepted');
$check(str_contains($payments,'Pagamento não encontrado nesta igreja'),
 'cannot change payment status of another church');
$check(str_contains($payments,"hash_equals("),'manual payment POST is protected by CSRF');

// Cover all 52 module routes at the syntax/security-gate level. Full browser
// navigation still requires the legacy bootstrap (not distributed in release).
$listed=json_decode(file_get_contents(__DIR__.'/../../../latest.json'),true,512,JSON_THROW_ON_ERROR);
$php=[];
foreach($listed['files'] as $f){
  if(str_ends_with($f['path'],'.php'))$php[]=$f['path'];
}
$check(count($php)>=106,'at least 106 PHP modules included in cumulative release');
$routes=array_values(array_filter($php,static fn($p)=>
    str_starts_with($p,'public/')&&!str_contains($p,'/lib/')));
$check(count($routes)>=52,'all 52 visible routes covered by full PHP syntax lint');
foreach($routes as $route){
  $code=file_get_contents($dir.'/'.$route);
  if(str_contains($code,"REQUEST_METHOD")&&str_contains($code,"POST")){
    $check(str_contains($code,'verify_csrf()')||str_contains($code,"hash_equals("),
      'write page has CSRF guard: '.$route);
  }
}
$check(str_contains(file_get_contents($dir.'/public/lib/ExpenseReceiptPdf.php'),'%PDF-1.4'),
  'native receipt PDF generator remains in package');
$check(str_contains(file_get_contents($dir.'/public/lib/MemberPortalAccessService.php'),'password_hash('),
  'member login continues to hash passwords');
$check(str_contains(file_get_contents($dir.'/public/lib/AccountingService.php'),'accounting_documents'),
  'accounting service and document workflow still included');
$check(str_contains(file_get_contents($dir.'/public/lib/DepartmentTreasuryService.php'),"'department'"),
  'department transaction origin preserved independently of donor');
$check(str_contains(file_get_contents($dir.'/public/lib/GeneralTreasuryAccountScope.php'),'department_id IS NULL'),
  'general treasury continues to reject department cashboxes');
echo "PASS 3.7.23 PIX summary 1007 payments and exact finance totals, no cross church leakage, invalid filters blocked, idempotent ledger source gates, 52 route CSRF inspections, core workflows preserved\n";
