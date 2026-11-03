<?php
declare(strict_types=1);
/** Disposable CI regression: preserve the 3.7.10 accounting and treasury checks. */
require __DIR__.'/../../3.7.10/tests/department_mysql.php';
require_once $dir.'/public/lib/ManagementOverviewService.php';

$pdo->exec("CREATE TABLE members (
    id CHAR(36) PRIMARY KEY,church_id CHAR(36),name VARCHAR(160),active TINYINT DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE events (
    id CHAR(36) PRIMARY KEY,church_id CHAR(36),title VARCHAR(160),location VARCHAR(160),active TINYINT DEFAULT 1,event_date DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE prayer_requests (
    id CHAR(36) PRIMARY KEY,church_id CHAR(36),status VARCHAR(30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
foreach([[$churchA,'Membro A',1],[$churchA,'Membro B',1],[$churchA,'Inativo',0],[$churchB,'Membro B igreja 2',1]] as [$church,$name,$active]){
    $pdo->prepare("INSERT INTO members(id,church_id,name,active) VALUES(?,?,?,?)")->execute([app_uuid(),$church,$name,$active]);
}
$when=(new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s');
$pdo->prepare('INSERT INTO events(id,church_id,title,location,event_date) VALUES(?,?,?,?,?)')
    ->execute([app_uuid(),$churchA,'Culto da família','Sede',$when]);
$pdo->prepare('INSERT INTO events(id,church_id,title,location,event_date) VALUES(?,?,?,?,?)')
    ->execute([app_uuid(),$churchB,'Evento de outra igreja','Outro lugar',$when]);
$pdo->prepare("INSERT INTO prayer_requests(id,church_id,status) VALUES(?,?,'pending')")->execute([app_uuid(),$churchA]);
$pdo->prepare("INSERT INTO prayer_requests(id,church_id,status) VALUES(?,?,'pending')")->execute([app_uuid(),$churchB]);

$today=(new DateTimeImmutable())->format('Y-m-d');
$add=static function(string $church,string $actor,string $direction,float $value,string $status)use($pdo,$today):void{
    $pdo->prepare('INSERT INTO finance_entries(id,church_id,account_id,direction,category,description,amount,
        transaction_date,status,source,created_by_user_id)
        VALUES(?, ?,NULL, ?,\'Diversos\',\'Teste central 360\',?, ?, ?,\'manual\', ?)')
        ->execute([app_uuid(),$church,$direction,$value,$today,$status,$actor]);
};
$add($churchA,$treasurer,'income',80.00,'posted');
$add($churchA,$treasurer,'expense',30.00,'posted');
$add($churchA,$treasurer,'income',1000.00,'draft');
$add($churchB,$outsider,'income',999.00,'posted');

$pastor=app_uuid();
$pdo->prepare('INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)')
    ->execute([$pastor,'pastor-test@example.com','Pastor de teste']);
$pdo->prepare("INSERT INTO church_members(id,church_id,user_id,church_role) VALUES(?,?,?,'pastor')")
    ->execute([app_uuid(),$churchA,$pastor]);
$_SESSION=['user_id'=>$pastor,'church_id'=>$churchA];
$authPastor=new Auth($pdo);
$service=new ManagementOverviewService($pdo,$authPastor,$churchA);
$m=$service->metrics();
$check(($m['numbers']['members']??null)===2.0,'active members only');
$check(($m['numbers']['departments']??null)===2.0,'only local active departments');
$check(($m['numbers']['events']??null)===1.0,'only local future events');
$check(($m['numbers']['prayers']??null)===1.0,'pending prayer of this church');
$check(($m['numbers']['accounts']??null)>=2.0,'local financial accounts');
$check(($m['numbers']['unproved_expenses']??0)>=1.0,'unproved expenses visible to manager');
$check(count($service->nextEvents())===1,'next events do not leak other church');
$links=$service->quickLinks();
$check(count($links)>=8,'pastor permitted actions');
$check(count(array_filter($links,static fn($a)=>$a['href']==='/accounting.php'))===1,'accounting link for church pastor');

$finance=$service->monthlyFinance(6);
$check(count($finance)===6,'six periods in rolling chart');
$last=end($finance);
$check($last['period']===(new DateTimeImmutable())->format('Y-m'),'latest month is current');
$expected=$pdo->prepare("SELECT
    SUM(CASE WHEN direction='income' THEN amount ELSE 0 END) i,
    SUM(CASE WHEN direction='expense' THEN amount ELSE 0 END) o
    FROM finance_entries WHERE church_id=? AND status='posted' AND transaction_date>=?
        AND transaction_date<?");
$start=(new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$end=(new DateTimeImmutable('first day of next month'))->format('Y-m-d');
$expected->execute([$churchA,$start,$end]);
$expectedRow=$expected->fetch(PDO::FETCH_ASSOC);
$check(abs($last['income']-(float)$expectedRow['i'])<.01,'income matches central posted ledger exactly');
$check(abs($last['expense']-(float)$expectedRow['o'])<.01,'expense matches central posted ledger exactly');
$check($last['income']<1000.0,'other tenant and drafts excluded from month chart');

$tasks=ManagementOverviewService::tasks($m['numbers'],$m['permissions']);
$check(count($tasks)>0,'management tasks present');
$check(count(array_filter($tasks,static fn($t)=>str_contains($t['title'],'comprovant')))>0,'unproved expenses flagged');

$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$authTreas=new Auth($pdo);
$treas=new ManagementOverviewService($pdo,$authTreas,$churchA);
$t=$treas->metrics();
$check(($t['permissions']['finance']??false)===true,'finance for treasurer');
$check(($t['permissions']['people']??true)===false,'no member totals for treasurer');
$check(!array_key_exists('members',$t['numbers']),'treasurer not given member counts');
$check(count(array_filter($treas->quickLinks(),static fn($a)=>$a['href']==='/members.php'))===0,'treasurer not shown member modules');

$_SESSION=['user_id'=>$outsider,'church_id'=>$churchB];
$authOther=new Auth($pdo);
$other=new ManagementOverviewService($pdo,$authOther,$churchB);
$foreign=$other->monthlyFinance(6);
$foreignLast=end($foreign);
$check($foreignLast['income']>=999 && $foreignLast['income']<1010,'church B chart excludes church A');
$check(($other->metrics()['numbers']['accounts']??-1)===0.0,'church B financial accounts separate');

$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
$authAccountant=new Auth($pdo);
try{
    new ManagementOverviewService($pdo,$authAccountant,$churchA);
    throw new RuntimeException('accountant accessed management dashboard');
}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'negado'),'accountant restricted to own portal');}

echo "PASS Central 360 tenant isolation, role-filtered links, finance integrity, tasks and events\n";
