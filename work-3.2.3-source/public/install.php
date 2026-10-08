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
require_once __DIR__.'/lib/Schema.php';
require_once __DIR__.'/updates/takeover.php';

$_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
$configFile=__DIR__.'/config/database.php';
$setupError=null;

function install_page_start(string $title): void {
    header('Content-Type:text/html; charset=utf-8');
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?></title><style>
    body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f7fb;padding:24px;color:#18202b}.box{max-width:820px;margin:28px auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 10px 30px #0001}.ok{color:#087a3e}.err{background:#fff0f0;color:#9b1c1c;padding:12px;border-radius:10px}.info{background:#eef6ff;color:#264a70;padding:12px;border-radius:10px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:grid;gap:6px}.field.full{grid-column:1/-1}.field input{padding:12px;border:1px solid #d9e0e9;border-radius:9px;font:inherit}.btn{border:0;border-radius:10px;padding:12px 16px;background:#e9bd50;font-weight:800;cursor:pointer;width:100%}code{background:#f4f6f8;padding:2px 5px;border-radius:5px}@media(max-width:620px){.grid{grid-template-columns:1fr}.field.full{grid-column:auto}}</style></head><body><div class="box"><?php
}
function install_page_end(): void { echo '</div></body></html>'; }

if(!is_file($configFile)){
    if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='database'){
        try{
            verify_csrf();
            $host=trim((string)($_POST['db_host']??'localhost'));
            $port=(int)($_POST['db_port']??3306);
            $database=trim((string)($_POST['db_name']??''));
            $username=trim((string)($_POST['db_user']??''));
            $password=(string)($_POST['db_password']??'');

            if($host===''||$database===''||$username==='') throw new RuntimeException('Informe host, nome do banco e usuário MySQL.');
            if($port<1||$port>65535) throw new RuntimeException('Porta MySQL inválida.');

            $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$database);
            $test=new PDO($dsn,$username,$password,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]);
            $test->query('SELECT 1');

            if(!is_dir(dirname($configFile))&&!mkdir(dirname($configFile),0750,true)&&!is_dir(dirname($configFile))){
                throw new RuntimeException('Não foi possível criar a pasta config.');
            }

            $cfg="<?php\nreturn ".var_export([
                'host'=>$host,
                'port'=>$port,
                'database'=>$database,
                'username'=>$username,
                'password'=>$password,
                'charset'=>'utf8mb4',
            ],true).";\n";

            $tmp=$configFile.'.tmp';
            if(file_put_contents($tmp,$cfg,LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar a configuração MySQL.');
            @chmod($tmp,0640);
            if(!rename($tmp,$configFile)){@unlink($tmp);throw new RuntimeException('Não foi possível ativar a configuração MySQL.');}
            @chmod($configFile,0640);

            header('Location: /install.php');
            exit;
        }catch(Throwable $e){
            $setupError=$e->getMessage();
        }
    }

    install_page_start('Igreja Master · Configuração do banco');
    ?>
    <h1>Instalação nova</h1>
    <p class="info">Use um <strong>banco MySQL vazio</strong> para uma instalação realmente nova. Não reutilize o banco antigo do AdminIgreja.</p>
    <?php if($setupError):?><p class="err"><?=e($setupError)?></p><?php endif?>
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="database">
      <div class="grid">
        <div class="field"><label>Host MySQL *</label><input name="db_host" value="<?=e($_POST['db_host']??'localhost')?>" required></div>
        <div class="field"><label>Porta</label><input name="db_port" inputmode="numeric" value="<?=e($_POST['db_port']??'3306')?>" required></div>
        <div class="field full"><label>Nome do banco *</label><input name="db_name" value="<?=e($_POST['db_name']??'')?>" placeholder="ex.: usuario_igrejamaster" required></div>
        <div class="field full"><label>Usuário MySQL *</label><input name="db_user" value="<?=e($_POST['db_user']??'')?>" required></div>
        <div class="field full"><label>Senha MySQL</label><input type="password" name="db_password" autocomplete="new-password"></div>
      </div>
      <button class="btn" style="margin-top:16px">Testar banco e continuar</button>
    </form>
    <?php
    install_page_end();
    exit;
}

try{
    $loaded=require $configFile;
    if(!is_array($loaded)) throw new RuntimeException('config/database.php inválido.');

    $pdo=Database::connect($loaded);

    // Idempotente e seguro: em banco vazio cria o schema; em banco legado
    // preserva tabelas antigas antes de criar a estrutura UUID do sistema novo.
    $takeover=(new LegacyTakeover($pdo,__DIR__.'/database'))->run();

    $schema=new Schema($pdo,__DIR__.'/database');
    $applied=$schema->migrate();

    $masterCount=(int)$pdo->query("SELECT COUNT(*) FROM user_roles WHERE role='super_admin'")->fetchColumn();
    $error=null;
    $created=false;

    if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='master'&&$masterCount===0){
        try{
            verify_csrf();
            $name=trim((string)($_POST['full_name']??''));
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $password=(string)($_POST['password']??'');
            $churchName=trim((string)($_POST['church_name']??''));

            if($name===''||$churchName==='') throw new RuntimeException('Informe seu nome e o nome da igreja principal.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
            if(strlen($password)<10) throw new RuntimeException('A senha Master deve ter pelo menos 10 caracteres.');

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$userId=$q->fetchColumn();
                if(!$userId){
                    $userId=app_uuid();
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')->execute([$userId,$email,password_hash($password,PASSWORD_DEFAULT),$name]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')->execute([app_uuid(),$userId,$name]);
                }else{
                    $pdo->prepare('UPDATE users SET password_hash=?,full_name=?,active=1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$name,$userId]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name)')->execute([app_uuid(),$userId,$name]);
                }

                $q=$pdo->prepare('SELECT c.id FROM churches c INNER JOIN church_members cm ON cm.church_id=c.id WHERE cm.user_id=? ORDER BY cm.is_owner DESC,c.created_at LIMIT 1');
                $q->execute([$userId]);$churchId=$q->fetchColumn();

                if(!$churchId){
                    $base=slugify($churchName);$slug=$base;$n=2;
                    while(true){$s=$pdo->prepare('SELECT 1 FROM churches WHERE slug=?');$s->execute([$slug]);if(!$s->fetchColumn())break;$slug=$base.'-'.$n++;}
                    $churchId=app_uuid();
                    $pdo->prepare("INSERT INTO churches(id,name,slug,plan,subscription_status,active,ai_enabled) VALUES(?,?,?,'enterprise','active',1,1)")->execute([$churchId,$churchName,$slug]);
                    $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role) VALUES(?,?,?,?,?)')->execute([app_uuid(),$churchId,$userId,1,'owner']);
                }

                $pdo->prepare("INSERT IGNORE INTO user_roles(id,user_id,role) VALUES(?,?,'admin')")->execute([app_uuid(),$userId]);
                $pdo->prepare("INSERT IGNORE INTO user_roles(id,user_id,role) VALUES(?,?,'super_admin')")->execute([app_uuid(),$userId]);

                $pdo->commit();
                $masterCount=1;
                $created=true;
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }

    install_page_start('Igreja Master · Instalação');
    ?>
    <h1 class="ok">Banco preparado com sucesso</h1>
    <p>Migrations executadas nesta abertura: <strong><?=count($applied)?></strong></p>
    <?php if($applied):?><pre><?=e(implode("\n",$applied))?></pre><?php endif?>
    <?php if($created):?><p class="ok"><strong>Conta Master criada com sucesso.</strong></p><?php endif?>
    <?php if($error):?><p class="err"><?=e($error)?></p><?php endif?>
    <?php if($masterCount===0):?>
      <h2>Criar primeiro acesso Master</h2>
      <p>Este formulário só aparece enquanto não existe nenhum Super Admin no sistema.</p>
      <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="master">
        <div class="grid">
          <div class="field"><label>Seu nome *</label><input name="full_name" required></div>
          <div class="field"><label>E-mail Master *</label><input type="email" name="email" required></div>
          <div class="field full"><label>Igreja principal *</label><input name="church_name" required></div>
          <div class="field full"><label>Senha Master *</label><input type="password" name="password" minlength="10" required autocomplete="new-password"></div>
        </div>
        <button class="btn" style="margin-top:16px">Criar acesso Master</button>
      </form>
    <?php else:?>
      <h2>Instalação protegida</h2>
      <p>Já existe um Super Admin. A criação inicial foi bloqueada para impedir que outro visitante vire Master.</p>
      <p><a href="/login">Entrar no sistema</a> · <a href="/master.php">Painel Master</a></p>
    <?php endif?>
    <?php
    install_page_end();
}catch(Throwable $e){
    http_response_code(500);
    install_page_start('Falha na instalação');
    echo '<h1>Falha na instalação</h1><pre class="err">'.e($e->getMessage()).'</pre>';
    install_page_end();
}
