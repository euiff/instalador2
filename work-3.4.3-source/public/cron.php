<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AutomationRunner.php';

if(PHP_SAPI!=='cli'){
    $expected=(string)(getenv('CRON_KEY')?:'');
    $received=(string)($_GET['key']??'');
    if($expected===''||!hash_equals($expected,$received)){
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cron protegido</title><style>body{font-family:Arial,sans-serif;background:#fffaf0;color:#172033;margin:0;padding:40px}.box{max-width:680px;margin:8vh auto;background:white;border:1px solid #eadfca;border-radius:18px;padding:28px;box-shadow:0 12px 32px rgba(0,0,0,.06)}h1{font-size:24px;margin:0 0 10px}p{line-height:1.6;color:#596579}a{display:inline-block;margin-top:12px;padding:11px 16px;border-radius:10px;background:#d99500;color:white;text-decoration:none;font-weight:700}</style></head><body><div class="box"><h1>🔒 Cron protegido</h1><p>Está correto: o arquivo <strong>cron.php</strong> não pode ser executado diretamente pelo navegador sem uma chave segura. Para testar ou configurar as automações, use a página <strong>Automações</strong> dentro do sistema.</p><a href="/automations.php">Abrir Automações</a></div></body></html>';
        exit;
    }
}

try{
    $result=(new AutomationRunner($pdo))->run(PHP_SAPI==='cli'?'cron':'http_cron');
    if(PHP_SAPI==='cli'){
        echo json_encode(['ok'=>true,'ran_at'=>date(DATE_ATOM)]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
    }else{
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'ran_at'=>date(DATE_ATOM)]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}catch(Throwable $e){
    if(PHP_SAPI!=='cli')header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).(PHP_SAPI==='cli'?PHP_EOL:'');
    exit(1);
}
