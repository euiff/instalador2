<?php
require dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/EvolutionClient.php';
require_once dirname(__DIR__).'/lib/AiClient.php';
require_once dirname(__DIR__).'/lib/BotRegistrationFlow.php';
require_once dirname(__DIR__).'/lib/BotEditFlow.php';
require_once dirname(__DIR__).'/lib/SpiritualFlow.php';
require_once dirname(__DIR__).'/lib/BotBibleFlow.php';
require_once dirname(__DIR__).'/lib/BotEventFlow.php';
require_once dirname(__DIR__).'/lib/BotDocumentFlow.php';
require_once dirname(__DIR__).'/lib/MemberDocumentService.php';
require_once dirname(__DIR__).'/lib/GroupMessageHandler.php';
require_once dirname(__DIR__).'/lib/BotWebhookHandler.php';

header('Content-Type: application/json; charset=utf-8');
$raw=file_get_contents('php://input') ?: '{}';
$payload=json_decode($raw,true);
if(!is_array($payload)){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}

$churchId=null;
$logId=null;
try{
    $event=(string)($payload['event']??'');
    $instanceRaw=$payload['instance']??'';
    $instance=is_string($instanceRaw)?$instanceRaw:(is_array($instanceRaw)?(string)($instanceRaw['instanceName']??$instanceRaw['name']??''):'');
    $q=$pdo->prepare('SELECT id,evolution_webhook_token FROM churches WHERE evolution_instance_name=? AND active=1 LIMIT 1');
    $q->execute([$instance]);
    $church=$q->fetch();
    $churchId=$church['id']??null;

    if($churchId){
        $expectedToken=trim((string)($church['evolution_webhook_token']??''));
        $receivedToken=trim((string)($_GET['token']??''));
        if($expectedToken!==''&&!hash_equals($expectedToken,$receivedToken)){
            try{
                $pdo->prepare('INSERT INTO webhook_logs(id,church_id,source,event_type,payload,status,processed_at) VALUES(?,?,?,?,?,"rejected_token",NOW())')
                    ->execute([app_uuid(),$churchId,'evolution',$event,json_encode(['instance'=>$instance,'reason'=>'invalid_webhook_token'],JSON_UNESCAPED_UNICODE)]);
            }catch(Throwable){}
            http_response_code(401);
            echo json_encode(['ok'=>false,'error'=>'invalid_webhook_token']);
            exit;
        }
    }
    $logId=app_uuid();
    $pdo->prepare('INSERT INTO webhook_logs(id,church_id,source,event_type,payload,status,processed_at) VALUES(?,?,?,?,?,"received",NOW())')
        ->execute([$logId,$churchId,'evolution',$event,$raw]);

    $result=(new BotWebhookHandler($pdo))->handle($payload);
    $status=!empty($result['group'])
        ?(!empty($result['handled'])
            ?'group_handled'
            :(!empty($result['reason'])?'group_unmatched':'group_received'))
        :'processed';
    $pdo->prepare('UPDATE webhook_logs SET status=?,processed_at=NOW() WHERE id=?')->execute([$status,$logId]);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    try{
        if($logId){
            $pdo->prepare('UPDATE webhook_logs SET status="error",payload=?,processed_at=NOW() WHERE id=?')
                ->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$logId]);
        }else{
            $pdo->prepare('INSERT INTO webhook_logs(id,church_id,source,event_type,payload,status,processed_at) VALUES(?,?,"evolution","handler_error",?,"error",NOW())')
                ->execute([app_uuid(),$churchId,json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE)]);
        }
    }catch(Throwable){}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'handler_error']);
}
