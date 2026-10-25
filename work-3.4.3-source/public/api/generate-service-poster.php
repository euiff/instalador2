<?php
require dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/PosterGenerator.php';
$auth->requireAbility('secretary');
$church=$auth->currentChurch();
if(!$church){http_response_code(401);exit('unauthorized');}
$id=trim((string)($_GET['id']??''));
if($id===''){http_response_code(400);exit('Culto não informado.');}
$q=$pdo->prepare('SELECT * FROM service_types WHERE id=? AND church_id=? LIMIT 1');$q->execute([$id,$church['id']]);$service=$q->fetch();
if(!$service){http_response_code(404);exit('Tipo de culto não encontrado.');}
$congregation=null;
if(!empty($service['congregation_id'])){$c=$pdo->prepare('SELECT * FROM congregations WHERE id=? AND church_id=? LIMIT 1');$c->execute([$service['congregation_id'],$church['id']]);$congregation=$c->fetch()?:null;}
$generator=new PosterGenerator((string)($church['gemini_api_key']??''));
if(!$generator->configured()){flash('error','Configure a chave Gemini em Configurações antes de gerar cartazes.');redirect('../service-types.php');}
try{
    $path=$generator->servicePoster($church,$service,$congregation,dirname(__DIR__));
    if(!$path)throw new RuntimeException('Não foi possível gerar o cartaz. Verifique a chave Gemini e tente novamente.');
    $pdo->prepare('UPDATE service_types SET poster_url=? WHERE id=? AND church_id=?')->execute([$path,$id,$church['id']]);
    flash('success','Cartaz do culto gerado com sucesso.');
}catch(Throwable $e){flash('error',$e->getMessage());}
redirect('../service-types.php');
