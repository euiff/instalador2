<?php
declare(strict_types=1);
/** Cumulative 3.7.16 regression: fictional people and expenses only. */
require __DIR__.'/../../3.7.14/tests/integration.php';
require_once $dir.'/public/lib/ExpenseReceiptService.php';
require_once $dir.'/public/lib/ExpenseReceiptPdf.php';
require_once $dir.'/public/lib/UploadService.php';

ExpenseReceiptService::ensureSchema($pdo);
ExpenseReceiptService::ensureSchema($pdo);

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$staffAuth=new Auth($pdo);
$deptFinance=new DepartmentTreasuryService($pdo,$staffAuth,$churchA,$admin);
$expenseEntry=$deptFinance->createEntry($deptId,[
  'account_id'=>$accountId,'direction'=>'expense','category'=>'Material do culto',
  'description'=>'Compra de lanche para atividade ministerial','amount'=>'60.75',
  'transaction_date'=>'2026-11-04','counterparty'=>'Maria da Silva',
  'payment_method'=>'PIX','document_number'=>''
],['error'=>UPLOAD_ERR_NO_FILE]);

$service=new ExpenseReceiptService($pdo,$staffAuth,$churchA,$admin);
$record=$service->entry($expenseEntry,true);
$check($record['direction']==='expense','expense entry used');
$check($service->receipt($expenseEntry)===null,'initial expense has no receipt');
$check(ExpenseReceiptService::validateInput([
  'recipient_name'=>'Maria da Silva','proof_method'=>'receipt_print'
])['mode']==='receipt_print','paper receipt does not require uploaded proof');

try{ExpenseReceiptService::validateInput(['recipient_name'=>'','proof_method'=>'receipt_print']);
    throw new RuntimeException('empty recipient accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'nome'),'recipient name required');}
try{ExpenseReceiptService::validateInput([
  'recipient_name'=>'Maria da Silva','proof_method'=>'receipt_screen','signature_consent'=>1,
  'signature_data'=>'data:image/jpeg;base64,not-a-jpeg']);
  throw new RuntimeException('invalid signature accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'Assinatura')||str_contains($e->getMessage(),'assinar'),'invalid signature rejected');}

$created=$service->create($expenseEntry,[
  'recipient_name'=>'Maria da Silva','proof_method'=>'receipt_print'
]);
$check($created['status']==='pending_signature','print mode stays pending');
$pending=$service->receipt($expenseEntry);
$check($pending && $pending['status']==='pending_signature','receipt persisted in correct expense');
$check((int)$pdo->query("SELECT COUNT(*) FROM finance_documents WHERE entry_id='".$expenseEntry."'")->fetchColumn()===0,
  'unsigned printed receipt is not an accounting proof');
$pdf=$service->pdf($expenseEntry);
$check(str_starts_with($pdf,'%PDF-1.4')&&str_contains($pdf,'%%EOF'),'print receipt valid PDF structure');
$check(str_contains($pdf,'AGUARDANDO ASSINATURA'),'unsigned PDF explicitly pending');
$check(str_contains($pdf,'Maria da Silva'),'PDF identifies payee');
$check(str_contains($pdf,'60,75'),'PDF shows amount');
$check(str_contains($pdf,'Compra de lanche'),'PDF includes expense description');

try{$service->create($expenseEntry,['recipient_name'=>'Outra','proof_method'=>'receipt_print']);
    throw new RuntimeException('duplicate receipt accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'já possui'),'one receipt per expense');}

if(!function_exists('imagecreatetruecolor'))throw new RuntimeException('CI needs GD to prepare synthetic signature fixture.');
$image=imagecreatetruecolor(800,230);
$white=imagecolorallocate($image,255,255,255);$black=imagecolorallocate($image,19,52,75);
imagefill($image,0,0,$white);
imagesetthickness($image,5);
imageline($image,180,120,300,74,$black);
imageline($image,300,74,388,154,$black);
imageline($image,388,154,486,75,$black);
ob_start();imagejpeg($image,null,88);$signature=ob_get_clean();imagedestroy($image);
$check(strlen($signature)>800,'synthetic JPEG signature fixture generated');
$signatureData='data:image/jpeg;base64,'.base64_encode($signature);

try{$service->signOnScreen($expenseEntry,[
  'signature_consent'=>'1','confirm_recipient_name'=>'Outro Nome','signature_data'=>$signatureData
]);throw new RuntimeException('wrong signatory accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'nome'),'wrong signatory blocked');}
$check($service->receipt($expenseEntry)['status']==='pending_signature','wrong name leaves receipt pending');

$service->signOnScreen($expenseEntry,[
  'signature_consent'=>'1','confirm_recipient_name'=>'Maria da Silva','signature_data'=>$signatureData
]);
$signed=$service->receipt($expenseEntry);
$check($signed['status']==='signed_screen'&&!empty($signed['finance_document_id']),
  'on-screen signature stored and linked');
$doc=$service->signedDocument($expenseEntry);
$check($doc!==null&&$doc['mime_type']==='application/pdf','signed expense appears in finance_documents');
$check((int)$pdo->query("SELECT COUNT(*) FROM finance_documents WHERE entry_id='".$expenseEntry."'")->fetchColumn()===1,
  'one signed receipt document, no duplicate ledger entry');
$signedPdf=$service->pdf($expenseEntry);
$check(str_contains($signedPdf,'/Subtype /Image')&&str_contains($signedPdf,'/DCTDecode'),
  'signed PDF embeds original JPEG signature');
$check(!str_contains($signedPdf,'AGUARDANDO ASSINATURA'),'signed PDF has no pending watermark');
$check($signedPdf!==$pdf,'signed and unsigned documents different');
$temp=tempnam(sys_get_temp_dir(),'receipt_pdf_');
file_put_contents($temp,$signedPdf);
if(trim((string)shell_exec('command -v pdfinfo 2>/dev/null'))!==''){
  $output=shell_exec('pdfinfo '.escapeshellarg($temp).' 2>&1');
  $check(is_string($output)&&str_contains($output,'Pages:')&&!str_contains($output,'Syntax Error'),
    'generated signed PDF passes pdfinfo');
}
@unlink($temp);

try{$service->signOnScreen($expenseEntry,[
  'signature_consent'=>'1','confirm_recipient_name'=>'Maria da Silva','signature_data'=>$signatureData
]);throw new RuntimeException('signed receipt overwritten');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'já assinado'),'signature cannot be silently replaced');}

$accountantAuth=new Auth($pdo);
$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
$accountantAuth=new Auth($pdo);
$view=new ExpenseReceiptService($pdo,$accountantAuth,$churchA,$accountant);
$check(strlen($view->pdf($expenseEntry))>1000,'accountant sees signed receipt for authorized church');
$check(!$view->canEdit($expenseEntry),'accountant remains read-only');
try{$view->create($expenseEntry,['recipient_name'=>'Teste','proof_method'=>'receipt_print']);
  throw new RuntimeException('accountant created receipt');}
catch(RuntimeException $e){$check(true,'accountant cannot create receipts');}
try{$view->entry($expenseEntry);$check(true,'accountant can view');}
catch(Throwable){$check(false,'accountant view failed');}

// An appointed leader may generate receipts only for their own departments.
$_SESSION=['user_id'=>$leaderUser,'church_id'=>$churchA];
$leaderAuth=new Auth($pdo);
$leaderReceipt=new ExpenseReceiptService($pdo,$leaderAuth,$churchA,$leaderUser);
$check($leaderReceipt->entry($expenseEntry)['id']===$expenseEntry,'leader sees own department receipt');
$check($leaderReceipt->canEdit($expenseEntry),'leader can complete own expense documents');
try{new ExpenseReceiptService($pdo,$leaderAuth,$churchB,$leaderUser);
  throw new RuntimeException('cross church service allowed');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Igreja'),'cross-church isolation');}

$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$memberAuth=new Auth($pdo);
try{(new ExpenseReceiptService($pdo,$memberAuth,$churchA,$participantUser))->entry($expenseEntry);
  throw new RuntimeException('participant accessed expense receipt');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'só pode'),'member without finance role cannot see expense receipts');}

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$staffAuth=new Auth($pdo);
$departmentFinance=new DepartmentTreasuryService($pdo,$staffAuth,$churchA,$admin);
$entry2=$departmentFinance->createEntry($deptId,[
  'account_id'=>$accountId,'direction'=>'expense','category'=>'Alimentação',
  'description'=>'Lanche oferecido em culto','amount'=>'35,00','transaction_date'=>'2026-11-04',
  'counterparty'=>'Fulano de teste','proof_method'=>'receipt_print','recipient_name'=>'Fulano de teste'
],['error'=>UPLOAD_ERR_NO_FILE]);
$receipt2=(new ExpenseReceiptService($pdo,$staffAuth,$churchA,$admin))->receipt($entry2);
$check($receipt2['status']==='pending_signature','department expense can generate unsigned print receipt');
$check((int)$pdo->query("SELECT COUNT(*) FROM finance_documents WHERE entry_id='".$entry2."'")->fetchColumn()===0,
  'department unsigned receipt remains missing proof for accountant');
$existingCount=(int)$pdo->query("SELECT COUNT(*) FROM finance_entries WHERE id='".$entry2."'")->fetchColumn();
$check($existingCount===1,'department receipt does not duplicate financial movement');
echo "PASS 3.7.16 signed PDF with embedded image, unsigned pending proof, accountant view-only, department isolation, linking and no duplicate transactions\n";
