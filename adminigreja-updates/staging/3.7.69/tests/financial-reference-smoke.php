<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceFiscalScan.php';
require __DIR__.'/../public/lib/FinanceVisionReader.php';

function checkReference(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('REFERENCE TEST FAILED: '.$message);
}
$e2e='E12345678'.str_repeat('A',23);
checkReference(strlen($e2e)===32,'PIX E2E 32 characters');
checkReference(FinanceFiscalScan::isPixE2E($e2e),'exact E2E recognized');
checkReference(!FinanceFiscalScan::isPixE2E('E12345678ZZZ'),'partial E2E not accepted');
checkReference(FinanceFiscalScan::bankReference(strtolower($e2e),'pix_e2e')===$e2e,'case normalization');
checkReference(FinanceFiscalScan::bankReference('123.456.789-00','bank_transaction')===null,'CPF rejected as transaction ID');
checkReference(FinanceFiscalScan::bankReference('12345678000195','bank_auth')===null,'CNPJ rejected as transaction ID');
checkReference(FinanceFiscalScan::bankReference('Referência não informada')===null,'incomplete text rejected');
checkReference(FinanceFiscalScan::bankReference('A1B2C3D4E5','bank_auth')==='A1B2C3D4E5','real auth code retained');

$sha=hash('sha256','receipt-pdf-test-1');
$fileRef=FinanceFiscalScan::fileReference($sha);
checkReference((bool)$fileRef && FinanceFiscalScan::isFileReference($fileRef),'stable local PDF id');
checkReference($fileRef===FinanceFiscalScan::fileReference($sha),'same bytes keep same ID');
checkReference($fileRef!==FinanceFiscalScan::fileReference(hash('sha256','different document')),'different bytes different ID');

$pixDoc=FinanceVisionReader::normalize([
    'document_type'=>'proof',
    'bank_reference'=>$e2e,'bank_reference_type'=>'pix_e2e',
    'bank_name'=>'Banco Exemplo',
    'short_number'=>'12345',
    'payment_method'=>'PIX',
    'issue_date'=>'2026-11-15',
    'issuer'=>'Banco Exemplo',
    'total_amount'=>'158.90'
]);
checkReference($pixDoc['bank_reference']===$e2e,'Gemini bank ID is extracted');
checkReference($pixDoc['document_number']===$e2e,'bank ID preferred over short coupon number');
checkReference($pixDoc['bank_reference_type']==='pix_e2e','Pix type kept');
checkReference($pixDoc['bank_name']==='Banco Exemplo','bank name retained');
checkReference($pixDoc['amount']==='158.90','payment amount kept');
$badDoc=FinanceVisionReader::normalize([
    'document_type'=>'proof','bank_reference'=>'12345678000195',
    'bank_reference_type'=>'bank_transaction',
    'short_number'=>null,'issuer'=>null,
    'total_amount'=>'70.00'
]);
checkReference($badDoc['bank_reference']===null,'CNPJ must not become bank ID');
checkReference($badDoc['document_number']===null,'invalid bank ID not fabricated');

$receipt=FinanceVisionReader::normalize([
    'document_type'=>'receipt','bank_reference'=>'AB20261115-98765',
    'bank_reference_type'=>'receipt_id','total_amount'=>'42.00'
]);
checkReference($receipt['document_number']==='AB20261115-98765','labeled receipt ID used for repeat protection');
$withoutRef=FinanceVisionReader::normalize(['document_type'=>'proof','total_amount'=>'20.00']);
checkReference($withoutRef['document_number']===null,'do not invent bank reference without document');

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE finance_entries (id TEXT PRIMARY KEY,church_id TEXT NOT NULL,document_number TEXT,counterparty TEXT,transaction_date TEXT,amount NUMERIC,created_at TEXT)');
$db->exec('CREATE TABLE finance_documents (id TEXT PRIMARY KEY,church_id TEXT NOT NULL,entry_id TEXT,original_name TEXT,document_number TEXT,file_sha256 TEXT)');
$entry=$db->prepare('INSERT INTO finance_entries(id,church_id,document_number,counterparty,transaction_date,amount,created_at) VALUES(?,?,?,?,?,?,?)');
$doc=$db->prepare('INSERT INTO finance_documents(id,church_id,entry_id,original_name,document_number,file_sha256) VALUES(?,?,?,?,?,?)');
$entry->execute(['e1','igreja-A',$e2e,'Banco Exemplo','2026-11-15',158.90,'2026-11-15T10:00:00']);
$doc->execute(['d1','igreja-A','e1','comprovante-pix.pdf',$e2e,$sha]);

$find=static function(string $cid,array $fields)use($db):array{return FinanceFiscalScan::duplicates($db,$cid,$fields);};
$hits=$find('igreja-A',['document_number'=>$e2e,'amount'=>'99.90','date'=>'2026-11-16','issuer'=>'Outro banco']);
checkReference(count($hits)>=1 && !empty($hits[0]['blocking']),'same E2E always blocked even different screenshot/amount');
checkReference(count($find('igreja-B',['document_number'=>$e2e,'amount'=>'158.90']))===0,'different church is isolated');

$hits=$find('igreja-A',['file_hash'=>$sha,'amount'=>'158.90']);
checkReference(count($hits)>=1 && !empty($hits[0]['blocking']),'same PDF bytes strong duplicate');
$hits=$find('igreja-A',['document_number'=>$fileRef,'amount'=>'158.90']);
checkReference(count($hits)===0,'file ID cannot falsely collide with bank E2E');
$entry->execute(['e2','igreja-A',$fileRef,'','2026-11-15',10.00,'2026-11-15T10:10:00']);
$hits=$find('igreja-A',['document_number'=>$fileRef,'amount'=>'10.00']);
checkReference(count($hits)>=1 && !empty($hits[0]['blocking']),'reused file-derived ID always blocked');

$entry->execute(['e3','igreja-A','AUTH98765432','Banco Azul','2026-11-15',65.00,'2026-11-15T10:20:00']);
$hits=$find('igreja-A',['document_number'=>'AUTH98765432','amount'=>'65.00','date'=>'2026-11-15','issuer'=>'Banco Azul','reference_type'=>'bank_auth']);
checkReference(count($hits)>=1 && !empty($hits[0]['blocking']),'same bank authentication and context blocked');
$hits=$find('igreja-A',['document_number'=>'AUTH98765432','amount'=>'65.00','date'=>'2026-11-15','issuer'=>'Banco Vermelho','reference_type'=>'bank_auth']);
checkReference(count($hits)>=1 && empty($hits[0]['blocking']),'different named bank warning, not false hard block');
$hits=$find('igreja-A',['document_number'=>'AUTH98765432','amount'=>'75.00','date'=>'2026-11-15','issuer'=>'Banco Azul','reference_type'=>'bank_auth']);
checkReference(count($hits)>=1 && empty($hits[0]['blocking']),'different amount warning only');

$entry->execute(['e4','igreja-A','12345','Comércio 1','2026-11-15',10.00,'2026-11-15T10:30:00']);
$hits=$find('igreja-A',['document_number'=>'12345','amount'=>'20.00','date'=>'2026-11-15','issuer'=>'Comércio 2']);
checkReference(count($hits)>=1 && empty($hits[0]['blocking']),'short coupon number reused without false blocking');
$hits=$find('igreja-A',['document_number'=>'12345','amount'=>'10.00','date'=>'2026-11-15','issuer'=>'Comércio 1']);
checkReference(count($hits)>=1 && !empty($hits[0]['blocking']),'same short coupon number and full context blocked');

echo "PASS: Pix E2E, bank IDs, receipt IDs, generated deterministic PDF identifiers, no fake references, church isolation, strong and contextual duplicate checks.\n";
