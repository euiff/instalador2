<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/TreasurySmartEntry.php';
require_once __DIR__.'/lib/DepartmentTreasuryService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try{
    $user=$auth->requireLogin();
    $church=$auth->currentChurch();
    if(!$church)throw new RuntimeException('Igreja não encontrada.');
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new RuntimeException('Método inválido.');}
    verify_csrf();
    $cid=(string)$church['id'];
    if(!$auth->can('finance')){
        if(!$auth->can('department_treasury'))throw new RuntimeException('Sem permissão para ler comprovantes.');
        $dept=(string)($_POST['department_id']??'');
        $service=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);
        if(!$service->mayPost())throw new RuntimeException('Seu perfil não pode lançar movimentações.');
        $service->department($dept,true);
    }
    $text=(string)($_POST['ocr_text']??'');
    if(trim($text)===''||strlen($text)>45000)throw new InvalidArgumentException('Texto OCR vazio ou muito longo.');
    $parsed=ReceiptParser::parse($text,TreasurySmartEntry::expenseCategoryNames($pdo,$cid));
    $parsed['suggested_document_type']=preg_match('/NFC.?E|CUPOM FISCAL|EXTRATO DO CUPOM/iu',$text)
       ?'fiscal_coupon':(preg_match('/DANFE|NFE|NF-E|NOTA FISCAL/iu',$text)?'invoice':'proof');
    $parsed['review_required']=true;
    echo json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    if(http_response_code()!==405)http_response_code(422);
    echo json_encode(['error'=>$e instanceof PDOException?'Erro ao conferir o documento.':$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
