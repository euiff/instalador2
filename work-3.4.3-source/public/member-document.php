<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UnifiedMemberDocumentService.php';

$auth->requireAbility('secretary');
$user=$auth->user();
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');

$id=trim((string)($_GET['id']??''));
$type=trim((string)($_GET['type']??''));

$options=[
    'period_start'=>$_GET['period_start']??null,
    'period_end'=>$_GET['period_end']??null,
    'purpose'=>$_GET['purpose']??null,
];

$service=new UnifiedMemberDocumentService($pdo,__DIR__);
$member=$service->member((string)$church['id'],$id);

if(!$member){
    http_response_code(404);
    exit('Membro não encontrado.');
}

try{
    $doc=$service->renderHtml($church,$member,$type,$user['id']??null,$options);
    $download='/member-document-pdf.php?id='.rawurlencode($id).'&type='.rawurlencode($type);
    foreach(['period_start','period_end','purpose'] as $key){
        if(!empty($options[$key]))$download.='&'.$key.'='.rawurlencode((string)$options[$key]);
    }

    $toolbar='<style>
    .unified-doc-toolbar{position:sticky;top:0;z-index:9999;display:flex;justify-content:flex-end;gap:10px;padding:12px 18px;background:#eef2f7;border-bottom:1px solid #dbe2ea;font-family:Arial,sans-serif}
    .unified-doc-toolbar a,.unified-doc-toolbar button{border:0;border-radius:10px;padding:10px 15px;font-weight:800;text-decoration:none;cursor:pointer;font-size:13px}
    .unified-doc-toolbar .light{background:#fff;color:#263246;border:1px solid #d7dee8}.unified-doc-toolbar .primary{background:#0c4f91;color:#fff}
    @media print{.unified-doc-toolbar{display:none!important}}
    </style>
    <div class="unified-doc-toolbar">
      <button class="light" type="button" onclick="history.back()">Voltar</button>
      <button class="light" type="button" onclick="window.print()">Imprimir</button>
      <a class="primary" href="'.e($download).'">Baixar PDF</a>
    </div>';

    $html=(string)$doc['html'];
    $html=str_replace('<body>','<body>'.$toolbar,$html);
    echo $html;
}catch(Throwable $e){
    http_response_code(400);
    exit($e->getMessage());
}
