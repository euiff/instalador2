<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MemberDocumentService.php';

$auth->requireAbility('secretary');
$user=$auth->user();
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');

$id=trim((string)($_GET['id']??''));
$type=trim((string)($_GET['type']??''));
$service=new MemberDocumentService($pdo,__DIR__);
$member=$service->member((string)$church['id'],$id);
if(!$member){http_response_code(404);exit('Membro não encontrado.');}

$options=[
    'period_start'=>$_GET['period_start']??null,
    'period_end'=>$_GET['period_end']??null,
    'purpose'=>$_GET['purpose']??null,
];

try{
    $issued=$service->issue($church,$member,$type,$user['id']??null,$options);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$issued['file_name']).'"');
    header('Content-Length: '.strlen((string)$issued['bytes']));
    header('X-Content-Type-Options: nosniff');
    echo $issued['bytes'];
}catch(Throwable $e){
    http_response_code(400);
    exit($e->getMessage());
}
