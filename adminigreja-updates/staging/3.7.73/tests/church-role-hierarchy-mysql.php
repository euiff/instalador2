<?php
declare(strict_types=1);
session_start();
require __DIR__.'/../public/lib/Auth.php';
require __DIR__.'/../public/lib/ChurchRoleAssignmentPolicy.php';
require __DIR__.'/../public/lib/ChurchStaffCredentialService.php';

function app_uuid():string {
    $h=bin2hex(random_bytes(16));
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
}
function ok(bool $v,string $msg):void {
    if(!$v)throw new RuntimeException('ROLE HIERARCHY FAILED: '.$msg);
}
function reject(callable $fn,string $msg):void {
    try{$fn();}catch(RuntimeException $e){return;}
    throw new RuntimeException('ROLE HIERARCHY FAILED: not rejected '.$msg);
}
$regular=ChurchRoleAssignmentPolicy::CHURCH_ROLES;
ok(count($regular)===9,'nine distinct church role types');
foreach(['owner','pastor'] as $current){
    $granted=ChurchRoleAssignmentPolicy::assignable($current,false);
    ok(in_array('pastor',$granted,true),'pastor may appoint a pastor: '.$current);
    foreach(['secretary','treasurer','accountant','department_leader','group_leader','teacher','kids_worker','member'] as $role)
        ok(in_array($role,$granted,true),'church role '.$role.' allowed');
    foreach(['admin','super_admin','owner'] as $role)
        ok(!in_array($role,$granted,true),'platform/owner role '.$role.' not delegated by '.$current);
}
foreach(['secretary','treasurer','accountant','department_leader','member','admin'] as $current)
    ok(ChurchRoleAssignmentPolicy::assignable($current,false)===[],
        $current.' cannot manage staff');
foreach(['admin','owner','super_admin'] as $role){
    reject(fn()=>ChurchRoleAssignmentPolicy::assertAssignable($role,'pastor',false),
        'pastor cannot create '.$role);
    reject(fn()=>ChurchRoleAssignmentPolicy::assertAssignable($role,'owner',false),
        'owner cannot create '.$role);
    reject(fn()=>ChurchRoleAssignmentPolicy::assertAssignable($role,'super_admin',true),
        'Master cannot assign platform admin as church role '.$role);
}
ok(in_array('pastor',ChurchRoleAssignmentPolicy::assignable('super_admin',true),true),
    'Master may appoint church pastor');
foreach(['owner','pastor'] as $actor){
    ok(ChurchRoleAssignmentPolicy::mayManageExisting($actor,false,'treasurer',false,false),
        'manager can edit lower role');
    ok(ChurchRoleAssignmentPolicy::mayManageExisting($actor,false,'pastor',false,false),
        'manager can edit another pastor');
    ok(!ChurchRoleAssignmentPolicy::mayManageExisting($actor,false,'admin',false,false),
        'legacy administrator cannot be modified by church staff');
    ok(!ChurchRoleAssignmentPolicy::mayManageExisting($actor,false,'pastor',false,true),
        'self elevation prohibited');
    ok(!ChurchRoleAssignmentPolicy::mayManageExisting($actor,false,'owner',true,false),
        'owner record protected');
}
ok(ChurchRoleAssignmentPolicy::mayManageExisting('super_admin',true,'admin',false,false),
    'Master may review legacy admin');
ok(!ChurchRoleAssignmentPolicy::mayManageExisting('super_admin',true,'owner',true,false),
    'Master does not edit owner through staff action');
$db=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','mastertest',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS im_role_hierarchy_test CHARACTER SET utf8mb4');
$db->exec('USE im_role_hierarchy_test');
foreach(['users','churches','church_members','user_roles','platform_audit_logs'] as $name)
    $db->exec('DROP TABLE IF EXISTS '.$name);
foreach([
    'CREATE TABLE users(id CHAR(36) PRIMARY KEY,email VARCHAR(190) UNIQUE,password_hash VARCHAR(255),full_name VARCHAR(200),active TINYINT)',
    'CREATE TABLE churches(id CHAR(36) PRIMARY KEY,name VARCHAR(200),slug VARCHAR(190),logo_url VARCHAR(200),active TINYINT)',
    'CREATE TABLE church_members(id CHAR(36) PRIMARY KEY,church_id CHAR(36),user_id CHAR(36),person_id CHAR(36),is_owner TINYINT,church_role VARCHAR(45),permissions TEXT)',
    'CREATE TABLE user_roles(user_id CHAR(36),role VARCHAR(60))',
    'CREATE TABLE platform_audit_logs(id CHAR(36) PRIMARY KEY,user_id CHAR(36),church_id CHAR(36),action VARCHAR(120),details JSON)'
] as $sql)$db->exec($sql);

$cid=app_uuid();$other=app_uuid();
$insChurch=$db->prepare("INSERT INTO churches(id,name,slug,logo_url,active) VALUES(?,?,?,NULL,1)");
$insChurch->execute([$cid,'Igreja teste','teste']);
$insChurch->execute([$other,'Outra igreja teste','outro']);
$insUser=$db->prepare("INSERT INTO users VALUES(?,?,?,?,1)");
$insMember=$db->prepare('INSERT INTO church_members VALUES(?,?,?,?,?,?,NULL)');
function addUser(PDOStatement $user,PDOStatement $member,string $cid,string $role):array{
    $uid=app_uuid();$mid=app_uuid();
    $user->execute([$uid,$uid.'@teste.exemplo',password_hash('SenhaTemporaria2026!',PASSWORD_DEFAULT),ucfirst($role)]);
    $member->execute([$mid,$cid,$uid,app_uuid(),$role==='owner'?1:0,$role]);
    return [$uid,$mid];
}
[$pastorId,$pastorMid]=addUser($insUser,$insMember,$cid,'pastor');
[$ownerId,$ownerMid]=addUser($insUser,$insMember,$cid,'owner');
[$adminId,$adminMid]=addUser($insUser,$insMember,$cid,'admin');
[$treasurerId,$treasurerMid]=addUser($insUser,$insMember,$cid,'treasurer');
[$masterId,$masterMid]=addUser($insUser,$insMember,$other,'super_admin');
$db->prepare('INSERT INTO user_roles VALUES(?,?)')->execute([$masterId,'super_admin']);
$auth=new Auth($db);
function assume(string $user,string $cid,bool $master=false):void{
    $_SESSION['user_id']=$user;$_SESSION['church_id']=$cid;
    if($master)$_SESSION['master_support_church_id']=$cid;
    else unset($_SESSION['master_support_church_id']);
}
assume($pastorId,$cid);
ok($auth->churchRole()==='pastor','pastor recognized');
ok($auth->can('manage_users'),'pastor can delegate Pastor and below');
assume($ownerId,$cid);
ok($auth->churchRole()==='owner','owner recognized');
ok($auth->can('manage_users'),'owner can manage church users');
assume($adminId,$cid);
ok($auth->churchRole()==='admin','pre-existing admin retained for master review');
ok(!$auth->can('manage_users'),'legacy admin may not create or change church roles');
ok($auth->can('dashboard'),'legacy account retains operational dashboard until review');
assume($treasurerId,$cid);
ok(!$auth->can('manage_users') && $auth->can('finance'),'treasurer cannot delegate users');
assume($masterId,$cid,true);
ok($auth->isSuperAdmin(),'platform super admin recognized');
ok($auth->can('manage_users'),'Master in support mode can review users');

$credentials=new ChurchStaffCredentialService($db);
assume($ownerId,$cid);
reject(fn()=>$credentials->reset($auth,$cid,$adminMid,$ownerId,
    'NovaSenhaBloqueada2026!','NovaSenhaBloqueada2026!',true),
    'owner cannot reset legacy administrator password');
assume($pastorId,$cid);
reject(fn()=>$credentials->reset($auth,$cid,$adminMid,$pastorId,
    'NovaSenhaBloqueada2026!','NovaSenhaBloqueada2026!',true),
    'pastor cannot reset legacy administrator password');
assume($masterId,$cid,true);
$login=$credentials->reset($auth,$cid,$adminMid,$masterId,
    'NovaSenhaMasterRevisao2026!','NovaSenhaMasterRevisao2026!',true);
ok($login===$adminId.'@teste.exemplo','Master may secure legacy admin for review');
$q=$db->prepare('SELECT password_hash FROM users WHERE id=?');
$q->execute([$adminId]);ok(password_verify('NovaSenhaMasterRevisao2026!',(string)$q->fetchColumn()),
    'Master reset password hash verified');
$q=$db->prepare('SELECT COUNT(*) FROM church_members WHERE user_id=? AND church_role=?');
$q->execute([$adminId,'admin']);
ok((int)$q->fetchColumn()===1,'legacy account retained; no silent demotion');
$q=$db->prepare('SELECT COUNT(*) FROM platform_audit_logs WHERE church_id=?');
$q->execute([$cid]);ok((int)$q->fetchColumn()===1,'legacy security change audited');
echo "PASS: church hierarchy Pastor down, Master reserved, admin role not grantable, legacy account protected, old admin cannot delegate, Master audit and tenant scope.\n";
