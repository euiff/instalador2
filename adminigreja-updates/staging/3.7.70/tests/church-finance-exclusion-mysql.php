<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/MasterSingleFinanceEntry.php';
function app_uuid():string {
    $h=bin2hex(random_bytes(16));
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
}
class Auth {
    public function __construct(private string $uid,private string $cid,private string $role,
        private bool $finance=true,private bool $master=false){}
    public function isSuperAdmin():bool{return $this->master;}
    public function requireLogin():array{return ['id'=>$this->uid];}
    public function currentChurch():array{return ['id'=>$this->cid];}
    public function churchRole():string{return $this->role;}
    public function can(string $ability):bool{return $ability==='finance' && $this->finance;}
}
function ok(bool $yes,string $message):void {
    if(!$yes)throw new RuntimeException('FAILED: '.$message);
}
function denied(callable $callback,string $message):void {
    try{$callback();}catch(Throwable $e){return;}
    throw new RuntimeException('FAILED: expected rejection of '.$message);
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','mastertest',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS im_finance_exclusion_test CHARACTER SET utf8mb4');
$db->exec('USE im_finance_exclusion_test');
foreach(['finance_entries','finance_accounts','finance_documents','expense_receipts',
 'finance_period_locks','finance_closings','finance_reconciliations','platform_audit_logs'] as $table)
    $db->exec('DROP TABLE IF EXISTS '.$table);
foreach([
 'CREATE TABLE finance_accounts(id CHAR(36) PRIMARY KEY,church_id CHAR(36),name VARCHAR(190))',
 "CREATE TABLE finance_entries(id CHAR(36) PRIMARY KEY,church_id CHAR(36),account_id CHAR(36),
 direction VARCHAR(16),description VARCHAR(250),amount DECIMAL(12,2),transaction_date DATE,
 status VARCHAR(20),payable_id CHAR(36) NULL,reversed_at DATETIME NULL,
 reversal_reason TEXT NULL,reversed_by_user_id CHAR(36) NULL)",
 'CREATE TABLE finance_documents(id CHAR(36) PRIMARY KEY,church_id CHAR(36),entry_id CHAR(36),original_name VARCHAR(200))',
 'CREATE TABLE expense_receipts(id CHAR(36) PRIMARY KEY,church_id CHAR(36),entry_id CHAR(36),number VARCHAR(40))',
 'CREATE TABLE finance_period_locks(id CHAR(36) PRIMARY KEY,church_id CHAR(36),period_start DATE,period_end DATE,active TINYINT)',
 'CREATE TABLE finance_closings(id CHAR(36) PRIMARY KEY,church_id CHAR(36),period_start DATE,period_end DATE,account_id CHAR(36) NULL)',
 'CREATE TABLE finance_reconciliations(id CHAR(36) PRIMARY KEY,church_id CHAR(36),account_id CHAR(36),reference_date DATE,status VARCHAR(30))',
 'CREATE TABLE platform_audit_logs(id CHAR(36) PRIMARY KEY,user_id CHAR(36),church_id CHAR(36),action VARCHAR(110),details JSON)',
] as $sql)$db->exec($sql);
$cid=app_uuid();$other=app_uuid();$account=app_uuid();$uid=app_uuid();
$db->prepare('INSERT INTO finance_accounts VALUES(?,?,?)')->execute([$account,$cid,'Caixa da igreja']);
$root=sys_get_temp_dir().'/igreja-37670-'.bin2hex(random_bytes(6));
$service=new MasterSingleFinanceEntry($db,$root);
$ins=$db->prepare('INSERT INTO finance_entries(id,church_id,account_id,direction,description,amount,transaction_date,status,payable_id) VALUES(?,?,?,?,?,?,?,?,?)');
function add(PDOStatement $ins,string $cid,string $account,string $direction,float $amount,
  string $date='2026-11-15',string $status='posted',?string $payable=null):string{
    $id=app_uuid();$ins->execute([$id,$cid,$account,$direction,'Teste financeiro',$amount,$date,$status,$payable]);return $id;
}
function balance(PDO $db,string $cid):float {
    $q=$db->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' THEN amount ELSE -amount END),0) FROM finance_entries WHERE church_id=? AND status='posted'");
    $q->execute([$cid]);return (float)$q->fetchColumn();
}
$pastor=new Auth($uid,$cid,'pastor');
$treasurer=new Auth($uid,$cid,'treasurer');
$owner=new Auth($uid,$cid,'owner');
$master=new Auth($uid,$cid,'super_admin',true,true);
foreach(['secretary','department_leader','member','accountant','admin'] as $role){
    $a=new Auth($uid,$cid,$role);
    ok(!MasterSingleFinanceEntry::churchUserCanExclude($a,$cid),'Role denied: '.$role);
}
foreach([$pastor,$treasurer,$owner] as $a)
    ok(MasterSingleFinanceEntry::churchUserCanExclude($a,$cid),'Allowed: '.$a->churchRole());
ok(!MasterSingleFinanceEntry::churchUserCanExclude($master,$cid),'Master uses own action');
ok(!MasterSingleFinanceEntry::churchUserCanExclude(new Auth($uid,$cid,'treasurer',false),$cid),'finance disabled');
ok(!MasterSingleFinanceEntry::churchUserCanExclude(new Auth($uid,$other,'treasurer'),$cid),'tenant isolation');
$id=add($ins,$cid,$account,'income',100);
$outside=add($ins,$other,$account,'income',500);
$docId=app_uuid();$receiptId=app_uuid();
$db->prepare('INSERT INTO finance_documents VALUES(?,?,?,?)')->execute([$docId,$cid,$id,'pix.pdf']);
$db->prepare('INSERT INTO expense_receipts VALUES(?,?,?,?)')->execute([$receiptId,$cid,$id,'R-001']);
denied(fn()=>$service->excludeForChurch(new Auth($uid,$other,'treasurer'),$cid,$id,$id,'Tentativa fora da igreja',$uid),'cross tenant');
denied(fn()=>$service->excludeForChurch(new Auth($uid,$cid,'secretary'),$cid,$id,$id,'Secretaria tentando apagar',$uid),'secretary');
denied(fn()=>$service->excludeForChurch($pastor,$cid,$id,$outside,'Exclusao sem confirmar o id',$uid),'confirmation mismatch');
denied(fn()=>$service->excludeForChurch($pastor,$cid,$id,$id,'curto',$uid),'short reason');
ok(balance($db,$cid)===100.0,'denials never affect finances');
$result=$service->excludeForChurch($pastor,$cid,$id,$id,'Lancamento duplicado por engano',$uid);
ok($result['balance_changed']===true,'pastor removed income');
ok(balance($db,$cid)===0.0,'active balance updated');
$q=$db->prepare('SELECT status,reversal_reason FROM finance_entries WHERE id=?');
$q->execute([$id]);$e=$q->fetch(PDO::FETCH_ASSOC);
ok($e['status']==='reversed'&&str_starts_with($e['reversal_reason'],'[EXCLUSAO IGREJA] '),'reason and status');
$q=$db->prepare('SELECT COUNT(*) FROM finance_documents WHERE entry_id=?');$q->execute([$id]);
ok((int)$q->fetchColumn()===1,'document preserved');
$q=$db->prepare('SELECT COUNT(*) FROM expense_receipts WHERE entry_id=?');$q->execute([$id]);
ok((int)$q->fetchColumn()===1,'receipt preserved');
$q=$db->prepare("SELECT details FROM platform_audit_logs WHERE church_id=? AND action='church_finance_entry_excluded'");
$q->execute([$cid]);$details=json_decode((string)$q->fetchColumn(),true);
ok($details['entry_id']===$id && $details['balance_changed']===true,'audited exact movement');
$path=$root.'/private-finance-backups/'.$details['backup'];
ok(is_file($path) && hash_file('sha256',$path)===$details['backup_sha256'],'private backup SHA256');
$copy=json_decode((string)file_get_contents($path),true);
ok($copy['entry']['id']===$id && count($copy['related']['finance_documents'])===1,'backup includes linked document');
denied(fn()=>$service->excludeForChurch($pastor,$cid,$id,$id,'Segunda exclusao indevida',$uid),'double exclusion');
ok(balance($db,$other)===500.0,'other church remains intact');

$expense=add($ins,$cid,$account,'expense',55.25);
ok(balance($db,$cid)===-55.25,'expense reduces active balance');
$service->excludeForChurch($treasurer,$cid,$expense,$expense,'Despesa duplicada por engano',$uid);
ok(balance($db,$cid)===0.0,'treasurer removes expense once');
$third=add($ins,$cid,$account,'income',42.00);
$service->excludeForChurch($owner,$cid,$third,$third,'Oferta lançada por engano',$uid);
ok(balance($db,$cid)===0.0,'owner exclusion works');
$reversed=add($ins,$cid,$account,'expense',20.00,'2026-11-15','reversed');
$x=$service->excludeForChurch($treasurer,$cid,$reversed,$reversed,'Ocultar estorno antigo auditado',$uid);
ok($x['balance_changed']===false && balance($db,$cid)===0.0,'reversed hidden without adjusting balance');

$locked=add($ins,$cid,$account,'income',33.00,'2026-10-10');
$db->prepare('INSERT INTO finance_period_locks VALUES(?,?,?,?,1)')->execute([app_uuid(),$cid,'2026-10-01','2026-10-31']);
denied(fn()=>$service->excludeForChurch($pastor,$cid,$locked,$locked,'Periodo financeiro ja bloqueado',$uid),'locked period');
$db->exec('DELETE FROM finance_period_locks');
$closed=add($ins,$cid,$account,'income',22.00,'2026-09-10');
$db->prepare('INSERT INTO finance_closings VALUES(?,?,?,?,NULL)')->execute([app_uuid(),$cid,'2026-09-01','2026-09-30']);
denied(fn()=>$service->excludeForChurch($treasurer,$cid,$closed,$closed,'Fechamento contabil protegido',$uid),'finance closing');
$db->exec('DELETE FROM finance_closings');
$reconciled=add($ins,$cid,$account,'income',10.00,'2026-08-10');
$db->prepare('INSERT INTO finance_reconciliations VALUES(?,?,?,?,?)')->execute([app_uuid(),$cid,$account,'2026-08-31','done']);
denied(fn()=>$service->excludeForChurch($treasurer,$cid,$reconciled,$reconciled,'Conciliacao bancaria protegida',$uid),'reconciled');
$db->exec('DELETE FROM finance_reconciliations');
$payable=add($ins,$cid,$account,'expense',99.00,'2026-11-15','posted',app_uuid());
denied(fn()=>$service->excludeForChurch($treasurer,$cid,$payable,$payable,'Pagamento vinculado a conta',$uid),'payable');

$masterEntry=add($ins,$cid,$account,'income',70.00);
denied(fn()=>$service->excludeForChurch($master,$cid,$masterEntry,$masterEntry,'Master tenta acao da igreja',$uid),'master denied church');
$service->exclude($master,$cid,$masterEntry,$masterEntry,'Suporte master solicitado pela igreja',$uid);
$q=$db->prepare('SELECT reversal_reason FROM finance_entries WHERE id=?');$q->execute([$masterEntry]);
ok(str_starts_with((string)$q->fetchColumn(),'[EXCLUSAO MASTER] '),'Master original exclusion still works');
ok(balance($db,$other)===500.0,'tenant isolation preserved');
echo "PASS: pastor/treasurer/owner exclusions, role denial, precise balance, backups, attached docs, auditing, closed periods, reconciliation, payable, Master isolation.\n";
