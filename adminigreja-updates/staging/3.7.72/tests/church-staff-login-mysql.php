<?php
declare(strict_types=1);
session_start();
require __DIR__.'/../public/lib/Auth.php';
require __DIR__.'/../public/lib/ChurchStaffCredentialService.php';

function app_uuid():string{
    $s=bin2hex(random_bytes(16));
    return substr($s,0,8).'-'.substr($s,8,4).'-4'.substr($s,13,3).'-a'.substr($s,17,3).'-'.substr($s,20,12);
}
function truth(bool $condition,string $msg):void{
    if(!$condition)throw new RuntimeException('LOGIN TEST FAILED: '.$msg);
}
function shouldReject(callable $fn,string $msg):void{
    try{$fn();}catch(Throwable $e){return;}
    throw new RuntimeException('LOGIN TEST FAILED: did not reject '.$msg);
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','mastertest',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
$db->exec('CREATE DATABASE IF NOT EXISTS im_staff_login_test CHARACTER SET utf8mb4');
$db->exec('USE im_staff_login_test');
foreach(['users','churches','church_members','user_roles','platform_audit_logs'] as $table)
    $db->exec('DROP TABLE IF EXISTS '.$table);
foreach([
 'CREATE TABLE users(id CHAR(36) PRIMARY KEY,email VARCHAR(190) UNIQUE,password_hash VARCHAR(255),full_name VARCHAR(200),active TINYINT)',
 'CREATE TABLE churches(id CHAR(36) PRIMARY KEY,name VARCHAR(200),slug VARCHAR(190),logo_url VARCHAR(200),active TINYINT)',
 'CREATE TABLE church_members(id CHAR(36) PRIMARY KEY,church_id CHAR(36),user_id CHAR(36),person_id CHAR(36) NULL,is_owner TINYINT DEFAULT 0,church_role VARCHAR(45),permissions TEXT)',
 'CREATE TABLE user_roles(user_id CHAR(36),role VARCHAR(80))',
 'CREATE TABLE platform_audit_logs(id CHAR(36) PRIMARY KEY,user_id CHAR(36),church_id CHAR(36),action VARCHAR(100),details JSON)'
] as $sql)$db->exec($sql);

$cid=app_uuid();$otherChurch=app_uuid();
$church=$db->prepare('INSERT INTO churches(id,name,slug,logo_url,active) VALUES(?,?,?,NULL,1)');
$church->execute([$cid,'Igreja Teste','igreja-teste']);
$church->execute([$otherChurch,'Outra igreja','outra-igreja']);
$addUser=$db->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,?)');
$addMember=$db->prepare('INSERT INTO church_members(id,church_id,user_id,person_id,is_owner,church_role,permissions) VALUES(?,?,?,?,?,?,NULL)');
function staff(PDOStatement $userStmt,PDOStatement $memberStmt,string $cid,string $email,
                string $password,string $role,bool $owner=false):array{
   $uid=app_uuid();$person=app_uuid();$membership=app_uuid();
   $userStmt->execute([$uid,mb_strtolower($email),password_hash($password,PASSWORD_DEFAULT),'Funcionario teste',1]);
   $memberStmt->execute([$membership,$cid,$uid,$person,(int)$owner,$role]);
   return [$uid,$person,$membership];
}
[$pastorId,$pastorPerson,$pastorMembership]=staff($addUser,$addMember,$cid,'pastor@teste.exemplo','SenhaPastorAtual2026!','pastor');
[$treasurerId,$treasurerPerson,$treasurerMembership]=staff($addUser,$addMember,$cid,'tesoureiro@teste.exemplo','SenhaAntiga2026!','treasurer');
[$secretaryId,$secretaryPerson,$secretaryMembership]=staff($addUser,$addMember,$cid,'secretaria@teste.exemplo','SenhaAnterior2026!','secretary');
[$accountantId,$accountantPerson,$accountantMembership]=staff($addUser,$addMember,$cid,'contadora@teste.exemplo','SenhaAnterior2026!','accountant');
[$ownerId,$ownerPerson,$ownerMembership]=staff($addUser,$addMember,$cid,'dono@teste.exemplo','SenhaOwner2026!','owner',true);
[$crossId,$crossPerson,$crossMembership]=staff($addUser,$addMember,$cid,'compartilhada@teste.exemplo','SenhaGlobal2026!','treasurer');
$addMember->execute([app_uuid(),$otherChurch,$crossId,app_uuid(),0,'treasurer']);
[$outsideId,$outsidePerson,$outsideMembership]=staff($addUser,$addMember,$otherChurch,'outro@teste.exemplo','SenhaOutro2026!','treasurer');
[$masterId,$masterPerson,$masterMembership]=staff($addUser,$addMember,$otherChurch,'master@teste.exemplo','SenhaMaster2026!','admin');
$db->prepare('INSERT INTO user_roles(user_id,role) VALUES(?,?)')->execute([$masterId,'super_admin']);
$db->prepare('UPDATE church_members SET church_id=?,person_id=? WHERE id=?')->execute([$cid,$masterPerson,$masterMembership]);

$auth=new Auth($db);
function assume(string $uid,string $churchId):void{
   $_SESSION['user_id']=$uid;
   $_SESSION['church_id']=$churchId;
   unset($_SESSION['master_support_church_id'],$_SESSION['platform_master_mode']);
}
function tryLogin(Auth $auth,string $email,string $password):bool{
    unset($_SESSION['user_id'],$_SESSION['church_id'],$_SESSION['platform_master_mode']);
    return $auth->login($email,$password);
}

// Genuine newly-created staff credentials still authenticate and select church.
truth(tryLogin($auth,'  TESOUREIRO@teste.exemplo ','SenhaAntiga2026!'),'login normalizes email');
truth((string)($auth->currentChurch()['id']??'')===$cid,'user enters its own church');
truth($auth->churchRole()==='treasurer','treasurer role loaded');
truth($auth->can('finance'),'treasurer can open financial panel');
truth(!$auth->can('manage_users'),'treasurer cannot reset other staff passwords');
truth(tryLogin($auth,'secretaria@teste.exemplo','SenhaAnterior2026!'),'secretary login');
truth($auth->churchRole()==='secretary' && $auth->can('secretary'),'secretary allowed in Secretaria');
truth(!$auth->can('finance'),'secretary denied treasury');
truth(tryLogin($auth,'contadora@teste.exemplo','SenhaAnterior2026!'),'accountant login');
truth($auth->churchRole()==='accountant' && $auth->can('accounting_portal'),'accountant role access');

// The old bug: an entered password was ignored when the email existed.
shouldReject(fn()=>ChurchStaffCredentialService::preventIgnoredPassword(true,'NovaSenhaNaoAplicada2026!'),
  'silently ignoring the entered password on pre-existing email');
ChurchStaffCredentialService::preventIgnoredPassword(true,'');
ChurchStaffCredentialService::preventIgnoredPassword(false,'NovaSenha2026!');

$service=new ChurchStaffCredentialService($db);
assume($pastorId,$cid);
shouldReject(fn()=>$service->reset($auth,$cid,$treasurerMembership,$pastorId,'NovaSenha2026!','outra',true),
  'wrong confirmation');
shouldReject(fn()=>$service->reset($auth,$cid,$treasurerMembership,$pastorId,'curta','curta',true),
  'short password');
shouldReject(fn()=>$service->reset($auth,$cid,$treasurerMembership,$pastorId,'NovaSenha2026!','NovaSenha2026!',false),
  'missing acknowledgment');
truth(tryLogin($auth,'tesoureiro@teste.exemplo','SenhaAntiga2026!'),
  'rejected reset must not break original password');
assume($pastorId,$cid);
$email=$service->reset($auth,$cid,$treasurerMembership,$pastorId,'NovaSenhaTesouraria2026!','NovaSenhaTesouraria2026!',true);
truth($email==='tesoureiro@teste.exemplo','reset returns correct login email');
truth(!tryLogin($auth,'tesoureiro@teste.exemplo','SenhaAntiga2026!'),'old password rejected after reset');
truth(tryLogin($auth,'TESOUREIRO@teste.exemplo','NovaSenhaTesouraria2026!'),'new password can enter');
truth($auth->churchRole()==='treasurer','reset does not downgrade role');
truth((string)($auth->currentMembership()['person_id']??'')===$treasurerPerson,
  'reset preserves member relationship');
truth($auth->can('finance'),'treasurer financial permissions unchanged');

assume($pastorId,$cid);
truth($service->reset($auth,$cid,$secretaryMembership,$pastorId,'NovaSenhaSecretaria2026!','NovaSenhaSecretaria2026!',true)
    ==='secretaria@teste.exemplo','secretary password reset by pastor');
truth(tryLogin($auth,'secretaria@teste.exemplo','NovaSenhaSecretaria2026!'),'secretary signs in with new password');
truth($auth->can('secretary'),'secretary role remains after reset');
assume($pastorId,$cid);
truth($service->reset($auth,$cid,$accountantMembership,$pastorId,'NovaSenhaContador2026!','NovaSenhaContador2026!',true)
    ==='contadora@teste.exemplo','accountant password reset');
truth(tryLogin($auth,'contadora@teste.exemplo','NovaSenhaContador2026!'),'accountant signs in after reset');
truth($auth->can('accounting_portal'),'accountant permissions remain');

assume($treasurerId,$cid);
shouldReject(fn()=>$service->reset($auth,$cid,$secretaryMembership,$treasurerId,'NovaSenhaProibida2026!','NovaSenhaProibida2026!',true),
  'treasurer cannot reset another staff account');
assume($pastorId,$cid);
shouldReject(fn()=>$service->reset($auth,$cid,$ownerMembership,$pastorId,'NovaSenhaProibida2026!','NovaSenhaProibida2026!',true),
  'owner is protected');
shouldReject(fn()=>$service->reset($auth,$cid,$pastorMembership,$pastorId,'NovaSenhaProibida2026!','NovaSenhaProibida2026!',true),
  'self password must use personal recovery');
shouldReject(fn()=>$service->reset($auth,$cid,$crossMembership,$pastorId,'NovaSenhaProibida2026!','NovaSenhaProibida2026!',true),
  'cannot reset a global login shared with another tenant');
shouldReject(fn()=>$service->reset($auth,$cid,$outsideMembership,$pastorId,'NovaSenhaProibida2026!','NovaSenhaProibida2026!',true),
  'cannot reset membership of another church');
shouldReject(fn()=>$service->reset($auth,$cid,$masterMembership,$pastorId,'NovaSenhaProibida2026!','NovaSenhaProibida2026!',true),
  'Master login must never be reset by church staff');

$query=$db->prepare("SELECT details FROM platform_audit_logs WHERE church_id=? AND action='church_staff_password_reset'");
$query->execute([$cid]);$logs=$query->fetchAll(PDO::FETCH_COLUMN);
truth(count($logs)===3,'three authorized password resets audited');
foreach($logs as $json){
    $record=json_decode((string)$json,true);
    truth(isset($record['target_user_id'])&&!isset($record['password'])&&!isset($record['password_hash']),
      'audit records actor and target but never password/hash');
}
echo "PASS: actual Auth login for treasurer, secretary and accountant, reused-login warning, authorized password reset, roles unchanged, no cross-tenant/owner/Master takeover, audit with no passwords.\n";
