<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceManualAttachments.php';

if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('SQLite PDO is required for test.');
function app_uuid():string {return bin2hex(random_bytes(16));}
function assertCheck(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE finance_entries(id TEXT PRIMARY KEY, church_id TEXT NOT NULL)');
$db->exec('CREATE TABLE finance_documents(
 id TEXT PRIMARY KEY,church_id TEXT,entry_id TEXT,payable_id TEXT,document_type TEXT,
 document_number TEXT,issued_at TEXT,original_name TEXT,storage_path TEXT,mime_type TEXT,
 file_size INTEGER,notes TEXT,created_by_user_id TEXT,file_sha256 TEXT,fiscal_access_key TEXT)');
$church='11111111-1111-1111-1111-111111111111';
$user='22222222-2222-2222-2222-222222222222';
$entry='33333333-3333-3333-3333-333333333333';
$db->prepare('INSERT INTO finance_entries(id,church_id) VALUES(?,?)')->execute([$entry,$church]);
$root=sys_get_temp_dir().'/im-manual-'.bin2hex(random_bytes(4));
$filePath=$root.'/storage/private/finance/'.$church.'/receipt.pdf';
$stagePath=$root.'/storage/private/finance-stage/'.$church;
mkdir(dirname($filePath),0700,true);mkdir($stagePath,0700,true);
file_put_contents($filePath,"%PDF-1.7\nDummy receipt file\n");
$sha=hash_file('sha256',$filePath);
$meta=[
 'church_id'=>$church,'user_id'=>$user,
 'path'=>'storage/private/finance/'.$church.'/receipt.pdf',
 'original_name'=>'nota-do-mercado.pdf','mime'=>'application/pdf',
 'size'=>filesize($filePath),'file_sha256'=>$sha,'created_at'=>time()
];
$token=str_repeat('a',48);
file_put_contents($stagePath.'/'.$token.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
$service=new FinanceManualAttachments($db,$root);
$prepared=$service->prepare($church,$user,json_encode([$token]));
assertCheck(count($prepared)===1,'Selected staged document must resolve to this tenant.');
$db->beginTransaction();
assertCheck($service->insert($church,$entry,$user,$prepared,'invoice')===1,'Manual proof should be attached.');
$db->commit();
$service->consume($prepared);
$q=$db->prepare('SELECT entry_id,document_type,file_sha256,original_name FROM finance_documents WHERE church_id=?');
$q->execute([$church]);$doc=$q->fetch(PDO::FETCH_ASSOC);
assertCheck($doc['entry_id']===$entry,'Extra document must belong to SAME entry.');
assertCheck($doc['document_type']==='invoice','Manual document type must be preserved.');
assertCheck($doc['file_sha256']===$sha,'Original file fingerprint must be preserved.');
assertCheck(!is_file($stagePath.'/'.$token.'.json'),'Committed stage token should be consumed.');
assertCheck(is_file($filePath),'Original fiscal document must be preserved.');
assertCheck((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===1,
    'Adding proof must NOT create another financial entry.');
$token2=str_repeat('b',48);
file_put_contents($stagePath.'/'.$token2.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
$blocked=false;
try{$service->prepare($church,$user,json_encode([$token2]));}
catch(InvalidArgumentException){$blocked=true;}
assertCheck($blocked,'Existing document hash must be rejected.');
$blocked=false;
try{$service->prepare($church,'another-user',json_encode([$token2]));}
catch(RuntimeException){$blocked=true;}
assertCheck($blocked,'Other user cannot reuse staged document.');
$blocked=false;
try{$service->prepare('other-church',$user,json_encode([$token2]));}
catch(RuntimeException){$blocked=true;}
assertCheck($blocked,'Other church cannot reuse staged document.');
echo "OK manual extras: one entry, private original, exact church/user, duplicates, no double posting\n";
