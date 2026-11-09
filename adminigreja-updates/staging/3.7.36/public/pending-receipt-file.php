<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/PendingFinanceReceipt.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch();if(!$church)redirect('/logout.php');
PendingFinanceReceipt::schema($pdo);
$draft=(new PendingFinanceReceipt($pdo,(string)$church['id'],(string)$auth->requireLogin()['id']))
    ->find(trim((string)($_GET['id']??'')));
if(empty($draft['signed_path'])){http_response_code(404);exit('Este recibo ainda não está assinado.');}
try{
    $prefix='storage/private/finance/'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$church['id']).'/';
    $relative=str_replace('\\','/',(string)$draft['signed_path']);
    if(!str_starts_with($relative,$prefix))throw new RuntimeException('Arquivo fora da igreja.');
    $file=(new UploadService(__DIR__))->absolute($relative);
    if(!is_file($file)||!is_readable($file))throw new RuntimeException('Comprovante ausente.');
    $filename=str_replace(['"',chr(13),chr(10)],'',(string)$draft['signed_name']);
    header('Content-Type: '.((string)$draft['signed_mime']?:'application/octet-stream'));
    header('Content-Disposition: inline; filename="'.$filename.'"');
    header('Content-Length: '.filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    readfile($file);exit;
}catch(Throwable){http_response_code(404);exit('Comprovante indisponível.');}
