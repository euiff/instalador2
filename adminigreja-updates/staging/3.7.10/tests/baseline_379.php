<?php
declare(strict_types=1);
/**
 * This suite runs only against an isolated disposable MariaDB database in CI.
 * No production database credentials are accepted.
 */
$dsn=(string)getenv('TEST_ACCOUNTING_DSN');
if(!str_contains($dsn,'igreja_accounting_test'))throw new RuntimeException('TEST_ACCOUNTING_DSN must contain isolated database name igreja_accounting_test');
$dir=(string)getenv('ACCOUNTING_RELEASE_DIR');
if($dir===''||!is_file($dir.'/public/lib/Auth.php'))throw new RuntimeException('Extracted release not provided');
require $dir.'/public/lib/Auth.php';
require $dir.'/public/lib/AccountingService.php';
function app_uuid():string{
    $v=bin2hex(random_bytes(16));
    return substr($v,0,8).'-'.substr($v,8,4).'-4'.substr($v,13,3).'-a'.substr($v,17,3).'-'.substr($v,20,12);
}
$check=static function(bool $cond,string $msg):void{
    if(!$cond)throw new RuntimeException('FAIL '.$msg);
};
$pdo=new PDO($dsn,(string)getenv('TEST_ACCOUNTING_USER'),(string)getenv('TEST_ACCOUNTING_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false
]);
$pdo->exec('ALTER DATABASE igreja_accounting_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['accounting_audit','accounting_periods','accounting_requests','accounting_documents','finance_documents','finance_entries','finance_payables','finance_accounts','church_registers','church_members','user_roles','users','churches'] as $table)
    $pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE churches (id CHAR(36) PRIMARY KEY,name VARCHAR(150),active TINYINT DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE users (id CHAR(36) PRIMARY KEY,email VARCHAR(150),full_name VARCHAR(150),password_hash VARCHAR(255),active TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE user_roles (user_id CHAR(36),role VARCHAR(50)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE church_members (id CHAR(36) PRIMARY KEY,church_id CHAR(36),user_id CHAR(36),is_owner TINYINT DEFAULT 0,church_role VARCHAR(50),permissions TEXT NULL,person_id CHAR(36) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE church_registers (id CHAR(36) PRIMARY KEY,church_id CHAR(36),kind VARCHAR(60),name VARCHAR(160),active TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_accounts (id CHAR(36) PRIMARY KEY,church_id CHAR(36),name VARCHAR(150),account_type VARCHAR(20),active TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_entries (
 id CHAR(36) PRIMARY KEY,church_id CHAR(36),account_id CHAR(36) NULL,direction VARCHAR(20),category VARCHAR(160),
 description VARCHAR(255),amount DECIMAL(14,2),transaction_date DATE,status VARCHAR(20),source VARCHAR(30),
 created_by_user_id CHAR(36),counterparty VARCHAR(190) NULL,document_number VARCHAR(60) NULL,
 notes TEXT NULL,payment_method VARCHAR(40) NULL
 ) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_documents (
 id CHAR(36) PRIMARY KEY,church_id CHAR(36),entry_id CHAR(36) NULL,payable_id CHAR(36) NULL,
 document_type VARCHAR(35),document_number VARCHAR(60) NULL,issued_at DATE NULL,original_name VARCHAR(250),
 storage_path VARCHAR(480),mime_type VARCHAR(90),file_size BIGINT,notes TEXT NULL,created_by_user_id CHAR(36)
 ) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_payables (
 id CHAR(36) PRIMARY KEY,church_id CHAR(36),due_date DATE,description VARCHAR(255),
 amount DECIMAL(14,2),status VARCHAR(20),document_number VARCHAR(60)
 ) ENGINE=InnoDB");

$pdo->exec("ALTER TABLE finance_accounts ADD COLUMN department_id CHAR(36) NULL, ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE finance_entries ADD COLUMN department_id CHAR(36) NULL");
$pdo->exec("CREATE TABLE ministry_departments (
 id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,name VARCHAR(160) NOT NULL,active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE department_access (
 church_id CHAR(36),user_id CHAR(36),department_id CHAR(36),PRIMARY KEY(church_id,user_id,department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE platform_audit_logs (
 id CHAR(36) PRIMARY KEY,user_id CHAR(36),church_id CHAR(36),action VARCHAR(70),details TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE finance_period_locks (
 id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,period_start DATE NOT NULL,period_end DATE NOT NULL,
 reason VARCHAR(1000) NOT NULL,active TINYINT NOT NULL DEFAULT 1,created_by_user_id CHAR(36) NOT NULL,
 reopened_by_user_id CHAR(36) NULL,reopened_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


$churchA=app_uuid();$churchB=app_uuid();$accountant=app_uuid();$treasurer=app_uuid();$outsider=app_uuid();$bank=app_uuid();
foreach([[$churchA,'Igreja A'],[$churchB,'Igreja B']] as $c)$pdo->prepare('INSERT INTO churches(id,name) VALUES(?,?)')->execute($c);
foreach([[$accountant,'conta@example.com'],[$treasurer,'tesour@example.com'],[$outsider,'extra@example.com']] as $u)
    $pdo->prepare("INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)")->execute([$u[0],$u[1],$u[1]]);
foreach([[$churchA,$accountant,'accountant'],[$churchA,$treasurer,'treasurer'],[$churchB,$outsider,'treasurer']] as $m)
    $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,church_role) VALUES(?,?,?,?)')->execute([app_uuid(),...$m]);
$pdo->prepare("INSERT INTO church_registers(id,church_id,kind,name) VALUES(?,?,'finance_category','Material de limpeza')")->execute([app_uuid(),$churchA]);
$pdo->prepare("INSERT INTO finance_accounts(id,church_id,name,account_type) VALUES(?,?,'Conta teste','bank')")->execute([$bank,$churchA]);
AccountingService::ensureSchema($pdo);
AccountingService::ensureSchema($pdo); // idempotent

$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
$auth=new Auth($pdo);
$check($auth->churchRole()==='accountant','accountant role recognized');
$check($auth->can('accounting_portal'),'accountant can access only portal');
foreach(['finance','manage_users','reports','secretary','dashboard','member_board','pastoral'] as $ability)
    $check(!$auth->can($ability),'accountant denied '.$ability);
$acc=new AccountingService($pdo,$auth,$churchA,$accountant);
$check(!$acc->isManager()&&$acc->isAccountant(),'accountant readonly');
$check(count($acc->accounts())===1,'scoped bank account');
$check(count($acc->categoryNames())===1,'scoped expense category');
$acc->requestDocument('2026-11','Extrato da conta','Enviar extrato bancário completo');
$check(count($acc->requests('2026-11'))===1,'accountant requested document');
try{$acc->periodStatus('2026-11','review');throw new RuntimeException('review allowed before submit');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'pastor'),'review blocked before submit');}

$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$authTreasury=new Auth($pdo);
$check($authTreasury->can('finance')&&$authTreasury->can('accounting_portal'),'treasurer scope');
$check(!$authTreasury->can('manage_users'),'treasurer cannot grant accountant access');
$treasury=new AccountingService($pdo,$authTreasury,$churchA,$treasurer);
$check($treasury->isManager()&&!$treasury->isAccountant(),'manager scope');
$treasury->periodStatus('2026-11','submit');
$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
try{$acc->periodStatus('2026-11','review');throw new RuntimeException('open request ignored');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'solicitados'),'pending docs block review');}

$expenseDocId=app_uuid();
$pdo->prepare("INSERT INTO accounting_documents
(id,church_id,document_kind,reference_period,original_name,storage_path,mime_type,file_size,sha256,status,created_by_user_id)
VALUES(?,?,'fiscal_coupon','2026-11','cupom.jpg',?,'image/jpeg',120,?,'draft',?)")
->execute([$expenseDocId,$churchA,'storage/private/finance/'.$churchA.'/cupom.jpg',str_repeat('b',64),$treasurer]);
$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$treasury->confirmDocument($expenseDocId,[
    'supplier_name'=>'Papelaria Teste','document_number'=>'1020','issued_on'=>'2026-11-02',
    'total'=>'25,50','confirmed_category'=>'Material de escritório','account_id'=>$bank,
    'post_expense'=>'1'
]);
$posted=$treasury->document($expenseDocId);
$check($posted['status']==='ready' && !empty($posted['finance_entry_id']),'expense confirmed and linked');
$check(count($treasury->entries('2026-11'))===1,'expense posted exactly once');
$check(count($treasury->legacyDocuments('2026-11'))===1,'document appears in treasury');
try{$treasury->confirmDocument($expenseDocId,['total'=>'25,50']);throw new RuntimeException('duplicate posting allowed');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'já vinculado'),'duplicate financial posting blocked');}
$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
try{$acc->confirmDocument($expenseDocId,['total'=>'25,50']);throw new RuntimeException('accountant could mutate ledger');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Somente'),'accountant barred from ledger mutation');}
$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$docId=app_uuid();
$pdo->prepare("INSERT INTO accounting_documents
(id,church_id,document_kind,reference_period,original_name,storage_path,mime_type,file_size,sha256,status,created_by_user_id)
VALUES(?,?,'bank_statement','2026-11','extrato.pdf',?,'application/pdf',123,?,'ready',?)")
->execute([$docId,$churchA,'storage/private/finance/'.$churchA.'/file.pdf',str_repeat('a',64),$treasurer]);
$req=$acc->requests('2026-11')[0];
$_SESSION=['user_id'=>$treasurer,'church_id'=>$churchA];
$treasury->respondRequest($req['id'],$docId);
$check($treasury->requests('2026-11')[0]['status']==='responded','request can be resolved only with a document');
$check((int)$treasury->summary('2026-11')['expense_without_proof']===0,'posted expense has invoice linked');
$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
$acc->reviewDocument($docId,'reviewed');
$check($acc->document($docId)['status']==='reviewed','accountant reviewed uploaded statement');
$acc->periodStatus('2026-11','review');
$check($acc->summary('2026-11')['period_status']==='reviewed','reviewed month');

try{$acc->document(app_uuid());throw new RuntimeException('tenant miss allowed');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não encontrado'),'unknown doc denied');}
$_SESSION=['user_id'=>$outsider,'church_id'=>$churchB];
$authOther=new Auth($pdo);
$other=new AccountingService($pdo,$authOther,$churchB,$outsider);
$check(count($other->documents('2026-11'))===0,'other church documents invisible');
$check(count($other->requests('2026-11'))===0,'other church requests invisible');
$check($other->summary('2026-11')['period_status']==='open','other church period not leaked');
try{$other->document($docId);throw new RuntimeException('cross tenant fetch allowed');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não encontrado'),'cross tenant fetch blocked');}
echo "PASS roles, accountant readonly, cross-church isolation, month handoff, missing document requests, audit schema\n";
