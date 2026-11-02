<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';

$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');

$id=trim((string)($_GET['id']??''));
$q=$pdo->prepare('SELECT * FROM finance_documents WHERE id=? AND church_id=? LIMIT 1');
$q->execute([$id,$church['id']]);
$doc=$q->fetch();
if(!$doc){http_response_code(404);exit('Documento não encontrado.');}

try{
    $uploader=new UploadService(__DIR__);
    $path=$uploader->absolute((string)$doc['storage_path']);
    $expected='storage/private/finance/'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$church['id']).'/';
    if(!str_starts_with(str_replace('\\','/',(string)$doc['storage_path']),$expected)){
        throw new RuntimeException('Documento fora da pasta autorizada.');
    }
    if(!is_file($path)||!is_readable($path))throw new RuntimeException('Arquivo indisponível.');

    $disposition=($_GET['download']??'')==='1'?'attachment':'inline';
    $name=str_replace(['"',"\r","\n"],'',(string)$doc['original_name']);
    header('Content-Type: '.((string)$doc['mime_type']?:'application/octet-stream'));
    header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.$disposition.'; filename="'.$name.'"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}catch(Throwable $e){
    http_response_code(404);
    exit('Documento indisponível.');
}
