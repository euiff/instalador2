<?php
require dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/PosterGenerator.php';
$auth->requireAbility('secretary');
$church=$auth->currentChurch();
if(!$church){http_response_code(401);exit('unauthorized');}
$id=trim((string)($_GET['id']??''));
if($id===''){http_response_code(400);exit('Evento não informado.');}
$q=$pdo->prepare('SELECT * FROM events WHERE id=? AND church_id=? LIMIT 1');$q->execute([$id,$church['id']]);$event=$q->fetch();
if(!$event){http_response_code(404);exit('Evento não encontrado.');}
$generator=new PosterGenerator((string)($church['gemini_api_key']??''));
if(!$generator->configured()){flash('error','Configure a chave Gemini em Configurações antes de gerar cartazes.');redirect('../events.php');}
try{
    $path=$generator->eventPoster($church,$event,dirname(__DIR__));
    if(!$path)throw new RuntimeException('Não foi possível gerar a imagem. Verifique a chave Gemini e tente novamente.');
    $pdo->prepare('UPDATE events SET poster_url=? WHERE id=? AND church_id=?')->execute([$path,$id,$church['id']]);
    flash('success','Cartaz gerado com sucesso.');
}catch(Throwable $e){flash('error',$e->getMessage());}
redirect('../events.php');
