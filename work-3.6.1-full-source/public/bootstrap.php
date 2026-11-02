<?php

declare(strict_types=1);

if(session_status()!==PHP_SESSION_ACTIVE){
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/',
        'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__.'/lib/helpers.php';
require_once __DIR__.'/lib/Database.php';
require_once __DIR__.'/lib/Auth.php';
require_once __DIR__.'/lib/View.php';
require_once __DIR__.'/lib/Schema.php';

$configFile=__DIR__.'/config/database.php';
$legacyRoot=dirname(__DIR__);
$legacyConfig=$legacyRoot.'/config/database.php';
$legacyCandidate=
    is_file($legacyConfig) &&
    is_readable($legacyConfig) &&
    is_file($legacyRoot.'/app/updater.php') &&
    realpath($legacyConfig)!==realpath($configFile);

/*
 * Bridge AdminIgreja -> Igreja Master:
 * quando o aplicativo novo é instalado dentro de /public pelo updater antigo,
 * a configuração original continua na raiz em /config/database.php.
 * Copiamos essa configuração uma única vez, sem alterar o arquivo legado.
 *
 * IMPORTANTE: a existência do arquivo copiado não significa que o takeover
 * terminou. Enquanto a configuração legada da raiz existir, chamamos o
 * takeover idempotente; ele próprio registra a conclusão no banco. Assim,
 * uma falha transitória pode ser repetida com segurança no próximo acesso.
 */
if(!is_file($configFile)){
    if($legacyCandidate){
        if(!is_dir(dirname($configFile))&&!mkdir(dirname($configFile),0750,true)&&!is_dir(dirname($configFile))){
            throw new RuntimeException('Não foi possível criar a pasta de configuração do novo sistema.');
        }
        if(!copy($legacyConfig,$configFile)){
            throw new RuntimeException('Não foi possível importar a configuração do banco do sistema anterior.');
        }
        @chmod($configFile,0644);
    }
}

if(!is_file($configFile)){
    http_response_code(503);
    exit('Sistema ainda não configurado. Execute o instalador para informar os dados MySQL.');
}

$loaded=require $configFile;
if(!is_array($loaded)){
    $loaded=[
        'host'=>defined('DB_HOST')?constant('DB_HOST'):(getenv('DB_HOST')?:'localhost'),
        'port'=>defined('DB_PORT')?constant('DB_PORT'):(getenv('DB_PORT')?:3306),
        'database'=>defined('DB_NAME')?constant('DB_NAME'):(getenv('DB_NAME')?:''),
        'username'=>defined('DB_USER')?constant('DB_USER'):(getenv('DB_USER')?:''),
        'password'=>defined('DB_PASS')?constant('DB_PASS'):(getenv('DB_PASS')?:''),
        'charset'=>'utf8mb4',
    ];
}

$pdo=Database::connect($loaded);

/*
 * No primeiro acesso após o bridge, o takeover roda antes que Auth consulte
 * as tabelas novas. Ele preserva as tabelas antigas como legacy_* e importa
 * os dados compatíveis.
 */
if($legacyCandidate){
    require_once __DIR__.'/updates/takeover.php';
    (new LegacyTakeover($pdo,__DIR__.'/database'))->run();
}

/*
 * Atualizações normais podem incluir novas migrations. Elas são aplicadas
 * automaticamente na primeira abertura do sistema após a troca dos arquivos.
 * O lock evita duas requisições tentando migrar o banco ao mesmo tempo.
 */
$schemaLock='igreja_master_schema_migrations';
$locked=false;
try{
    $q=$pdo->query("SELECT GET_LOCK(".$pdo->quote($schemaLock).",10)");
    $locked=(int)$q->fetchColumn()===1;
    if($locked){
        (new Schema($pdo,__DIR__.'/database'))->migrate();
    }
}finally{
    if($locked){
        try{$pdo->query("SELECT RELEASE_LOCK(".$pdo->quote($schemaLock).")");}catch(Throwable){}
    }
}

$auth=new Auth($pdo);
