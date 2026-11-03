<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AccountingService.php';
$user=$auth->requireAbility('accounting_portal');
$church=$auth->currentChurch();if(!$church){http_response_code(403);exit;}
$service=new AccountingService($pdo,$auth,(string)$church['id'],(string)$user['id']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(!$service->isManager()||$_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);echo json_encode(['error'=>'Ação não permitida']);exit;
}
try{
    verify_csrf();
    $text=(string)($_POST['ocr_text']??'');
    if($text===''||strlen($text)>45000)throw new InvalidArgumentException('Texto muito grande ou vazio.');
    $parsed=ReceiptParser::parse($text,$service->categoryNames());
    echo json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    http_response_code(422);
    echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
