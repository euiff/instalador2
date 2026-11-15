<?php
declare(strict_types=1);
require dirname(__DIR__).'/public/lib/MasterFinanceImport.php';

function app_uuid(): string {
    $h=bin2hex(random_bytes(16));
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
}
function check(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('TEST FAILED: '.$message);
}
$fixture=getenv('FINANCE_FIXTURE')?:'/tmp/master-finance-test.xlsx';
$dsn='mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$db=new PDO($dsn,'root','mastertest',['PDO::ATTR_ERRMODE'=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS master_finance_test CHARACTER SET utf8mb4');
$db->exec('USE master_finance_test');
foreach ([
    'CREATE TABLE churches(id CHAR(36) PRIMARY KEY,name VARCHAR(180) NOT NULL)',
    'CREATE TABLE members(id CHAR(36) PRIMARY KEY,church_id CHAR(36),name VARCHAR(190),active TINYINT)',
    'CREATE TABLE ministry_departments(id CHAR(36) PRIMARY KEY,church_id CHAR(36),name VARCHAR(190),active TINYINT)',
    'CREATE TABLE church_registers(id CHAR(36) PRIMARY KEY,church_id CHAR(36),kind VARCHAR(40),name VARCHAR(190),details JSON,active TINYINT DEFAULT 1, UNIQUE KEY (church_id,kind,name))',
    'CREATE TABLE finance_accounts(id CHAR(36) PRIMARY KEY,church_id CHAR(36),department_id CHAR(36) NULL,name VARCHAR(190),account_type VARCHAR(16),opening_balance DECIMAL(14,2),active TINYINT, UNIQUE KEY uq_department_account(church_id,department_id))',
    'CREATE TABLE finance_period_locks(id CHAR(36) PRIMARY KEY,church_id CHAR(36),period_start DATE,period_end DATE,active TINYINT)',
    'CREATE TABLE platform_audit_logs(id CHAR(36) PRIMARY KEY,user_id CHAR(36),church_id CHAR(36),action VARCHAR(100),details JSON)',
    'CREATE TABLE finance_entries(
        id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,account_id CHAR(36),
        member_id CHAR(36) NULL,department_id CHAR(36) NULL,direction VARCHAR(16),
        category VARCHAR(140),description TEXT,amount DECIMAL(14,2),transaction_date DATE,
        status VARCHAR(20),source VARCHAR(40),created_by_user_id CHAR(36),
        counterparty VARCHAR(180),payment_method VARCHAR(60),
        document_number VARCHAR(100),notes TEXT,request_key CHAR(64),
        UNIQUE KEY church_request(church_id,request_key),INDEX church_entries(church_id)
    )',
] as $sql)$db->exec($sql);
$churchId=app_uuid();$otherChurch=app_uuid();$actor=app_uuid();
$departmentId=app_uuid();$existingDepartmentId=app_uuid();$existingAccountId=app_uuid();$otherAccount=app_uuid();$memberId=app_uuid();
$q=$db->prepare('INSERT INTO churches(id,name) VALUES(?,?)');
$q->execute([$churchId,'Igreja Destino']);$q->execute([$otherChurch,'Igreja Estranha']);
$db->prepare('INSERT INTO members(id,church_id,name,active) VALUES(?,?,?,1)')->execute([$memberId,$churchId,'Pessoa de Teste']);
$db->prepare('INSERT INTO ministry_departments(id,church_id,name,active) VALUES(?,?,?,1)')
    ->execute([$departmentId,$churchId,'Departamento Teste']);
$db->prepare('INSERT INTO ministry_departments(id,church_id,name,active) VALUES(?,?,?,1)')
    ->execute([$existingDepartmentId,$churchId,'DPTO MULHER']);
$db->prepare("INSERT INTO finance_accounts(id,church_id,department_id,name,account_type,opening_balance,active) VALUES(?,?,?,?,'cash',0,1)")
    ->execute([$existingAccountId,$churchId,$existingDepartmentId,'Caixa já existente do ministério']);
$db->prepare("INSERT INTO finance_accounts(id,church_id,department_id,name,account_type,opening_balance,active) VALUES(?,?,NULL,?,'cash',0,1)")
    ->execute([$otherAccount,$otherChurch,'Caixa de outra igreja']);
$service=new MasterFinanceImport($db,sys_get_temp_dir());
$parsed=$service->parse($fixture,'backup-legado.xlsx');
check($parsed['count']===7567,'all rows read');
check($parsed['count_income']===5895,'income count');
check($parsed['count_expense']===1672,'expense count');
check($parsed['income_cents']===139301657,'income total after normalizing source type and sign');
check($parsed['expense_cents']===137596657,'expense total in positive cents despite negative values in Excel');
check(count($parsed['rounded_value_lines'])===182,'182 Excel IEEE-754 currency representations');
check(count($parsed['missing_description_lines'])===0,'no missing descriptions in latest workbook');
check(count($parsed['signed_expense_lines'])===1672,'all 1672 negative signed expenses normalized');
check(count($parsed['errors'])===0,'all lines accepted despite blank description or IEEE-754 noise');
check(count($parsed['accounts'])===22,'21 legacy account labels + #N/A');
check(count($parsed['categories'])===5,'five original accounting categories');
check(count($parsed['suggested_departments'])===13,'13 original 1.x and 2.x departments');
check(MasterFinanceImport::suggestedDepartment('2.2.1 - MIN. LIVE & PROJEÇÃO')==='MIN. LIVE & PROJEÇÃO','nested department codes kept');
check(MasterFinanceImport::suggestedDepartment('4.2 - CONTRUÇÃO IDB CB')===null,'project fund is not treated as a ministry');
check(count($parsed['zero_lines'])===5,'five zero-amount historical rows');
check(count($parsed['unassigned_lines'])===1,'one unidentified-account row in uploaded workbook');
check($parsed['first_date']==='2021-12-14','Excel serial date correctly decoded');
check($parsed['last_date']==='2026-10-29','latest Excel serial date correctly decoded');
check(count($parsed['errors'])===0,'no parsing errors');
$private=$service->storageDirectory().'/finance-smoke-'.$actor.'.xlsx';
check(copy($fixture,$private),'create authorized private fixture');
$hash=hash_file('sha256',$private);
$mapping=[];
foreach($parsed['accounts'] as $a)
    $mapping[$a['key']]='auto';
$key=$parsed['accounts'][0]['key'];
$wrong=$mapping;
$wrong[$key]='existing:'.$otherAccount;
try{
    $service->import($churchId,$actor,$private,'backup-legado.xlsx',$hash,$wrong,'Igreja Destino');
    throw new RuntimeException('TEST FAILED: cross-church mapping was accepted');
}catch(RuntimeException $e){
    check(str_contains($e->getMessage(),'Conta escolhida não pertence'),'cross-church mapping rejected');
}
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===0,'rollback after rejected mapping');
check((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE church_id='$churchId'")->fetchColumn()===1,'failed import keeps pre-existing account, does not create boxes');
check((int)$db->query("SELECT COUNT(*) FROM ministry_departments WHERE church_id='$churchId'")->fetchColumn()===2,'failed import keeps two pre-existing departments');
check((int)$db->query("SELECT COUNT(*) FROM church_registers WHERE church_id='$churchId'")->fetchColumn()===0,'failed import rolls back categories');
$stats=$service->import($churchId,$actor,$private,'backup-legado.xlsx',$hash,$mapping,'Igreja Destino');
check($stats['entries']===7567,'all entries committed');
check($stats['categories_created']===5,'all five accounting categories created');
check($stats['departments_created']===12,'12 new source departments, existing DPTO MULHER reused');
check($stats['accounts_created']===22,'all 21 exact cashboxes plus 1 quarantined account created');
check($stats['income_cents']===139301657 && $stats['expense_cents']===137596657,'totals consistent after import');
check($stats['members_linked']===1,'known donor linked exactly once');
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===7567,'row count in DB');
check((int)$db->query("SELECT COUNT(*) FROM finance_entries WHERE amount=0")->fetchColumn()===5,'zero records kept');
check((int)$db->query("SELECT COUNT(*) FROM finance_entries WHERE description LIKE 'SEM DESCRIÇÃO NO BACKUP%'")->fetchColumn()===0,'no blank description invented');
check(abs((float)$db->query("SELECT SUM(amount) FROM finance_entries WHERE direction='income'")->fetchColumn()-1393016.57)<0.001,'MySQL income sum correct');
check(abs((float)$db->query("SELECT SUM(amount) FROM finance_entries WHERE direction='expense'")->fetchColumn()-1375966.57)<0.001,'MySQL expense sum correct');
check((int)$db->query("SELECT COUNT(*) FROM finance_entries WHERE direction='expense' AND amount<0")->fetchColumn()===0,'no negative amount stored for expenses');
check(abs((float)$db->query("SELECT SUM(CASE WHEN direction='income' THEN amount ELSE -amount END) FROM finance_entries")->fetchColumn()-17050.00)<0.001,'historical net balance preserved');
check((int)$db->query("SELECT COUNT(*) FROM finance_entries WHERE account_id=(SELECT id FROM finance_accounts WHERE church_id='$churchId' AND name='LEGADO - CAIXA NÃO IDENTIFICADO (CONFERIR)')")->fetchColumn()===1,'one unidentified row quarantined in general account');
check((int)$db->query("SELECT COUNT(*) FROM ministry_departments WHERE church_id='$churchId'")->fetchColumn()===14,'two existing departments plus 12 new from backup');
check((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE church_id='$churchId' AND department_id IS NOT NULL")->fetchColumn()===14,'13 imported ministry boxes plus original account preserved');
check((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE church_id='$churchId' AND department_id='$existingDepartmentId'")->fetchColumn()===2,'two distinct boxes in same existing ministry supported');
check((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE church_id='$churchId' AND name='Caixa já existente do ministério'")->fetchColumn()===1,'original account name unchanged');
$idx=$db->query("SHOW INDEX FROM finance_accounts WHERE Key_name='uq_department_account'")->fetchAll(PDO::FETCH_ASSOC);
usort($idx,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
check(array_column($idx,'Column_name')===['church_id','department_id','name'],'legacy index upgraded to unique department+name');
check((int)$db->query("SELECT COUNT(*) FROM church_registers WHERE church_id='$churchId' AND kind='finance_category' AND active=1")->fetchColumn()===5,'five accounting categories visible in app');
check((int)$db->query("SELECT COUNT(*) FROM church_registers WHERE church_id='$churchId' AND name='DÍZIMOS' AND JSON_UNQUOTE(JSON_EXTRACT(details,'$.direction'))='both'")->fetchColumn()===1,'DÍZIMOS registered for entrances and exits');
check((int)$db->query("SELECT COUNT(*) FROM church_registers WHERE church_id='$churchId' AND name='ÁGUA' AND JSON_UNQUOTE(JSON_EXTRACT(details,'$.direction'))='income'")->fetchColumn()===1,'ÁGUA registered as income exactly as source classifies it');
check((int)$db->query("SELECT COUNT(*) FROM finance_entries WHERE church_id='$otherChurch'")->fetchColumn()===0,'other church untouched');
check((int)$db->query("SELECT COUNT(*) FROM platform_audit_logs WHERE action='master_finance_legacy_import'")->fetchColumn()===1,'audited');
check((int)$db->query("SELECT COUNT(*) FROM finance_entries WHERE member_id IS NOT NULL")->fetchColumn()===1,'member identity scoped to tenant');
check((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE church_id='$churchId'")->fetchColumn()===23,'21 legacy boxes + 1 quarantine + 1 original preserved');
check((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE church_id='$churchId' AND name='3.2 - DÍZIMOS - CX IGREJA'")->fetchColumn()===1,'preserve original cashbox name and numbering');
try{
    $service->import($churchId,$actor,$private,'backup-legado.xlsx',$hash,$mapping,'Igreja Destino');
    throw new RuntimeException('TEST FAILED: duplicate import accepted');
}catch(RuntimeException $e){
    check(str_contains($e->getMessage(),'possui lançamentos'),'repeat import safely blocked');
}
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===7567,'no duplicate rows');
@unlink($private);
echo "PASS: 7567 actual-style Excel rows, Entradas/Saídas plural, 1672 signed expenses, R$ 17050 net, index guard and tenant isolation.\n";
