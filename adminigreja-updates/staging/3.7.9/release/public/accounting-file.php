<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AccountingService.php';
$user=$auth->requireAbility('accounting_portal');
$church=$auth->currentChurch();
if(!$church){http_response_code(403);exit('Acesso negado.');}
$service=new AccountingService($pdo,$auth,(string)$church['id'],(string)$user['id']);
$id=trim((string)($_GET['id']??''));
$kind=(string)($_GET['kind']??'accounting');
if(!preg_match('/^[0-9a-fA-F-]{36}$/D',$id)||!in_array($kind,['accounting','legacy'],true)){
    http_response_code(400);exit('Arquivo inválido.');
}
try{
    $doc=$kind==='legacy'?$service->legacyDocument($id):$service->document($id);
    $file=$service->storedFile((string)$doc['storage_path']);
    $name=basename(str_replace('\\','/',(string)$doc['original_name']));
    $name=preg_replace('/[\x00-\x1F\x7F"\\]/','_',mb_substr($name,0,180))?:'documento';
    $mime=(string)($doc['mime_type']??'application/octet-stream');
    if(!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf','application/xml','text/xml'],true))$mime='application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename="documento"; filename*=UTF-8\'\''.rawurlencode($name));
    header('Content-Length: '.filesize($file));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($file);exit;
}catch(Throwable $e){
    error_log('accounting download: '.$e->getMessage());
    http_response_code(404);exit('Documento não encontrado para seu acesso.');
}
