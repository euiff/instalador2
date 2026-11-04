<?php
declare(strict_types=1);
/**
 * Validate a real-world legacy ministry_members schema with joined_on
 * NOT NULL and no default under strict MariaDB.
 * No production records are used or altered.
 */
require __DIR__.'/../../3.7.20/tests/integration.php';

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$authJoinedOn=new Auth($pdo);
$departmentsSvc=new MemberDepartmentService($pdo,$authJoinedOn,$churchA,$admin);
$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("ALTER TABLE ministry_members ADD COLUMN joined_on DATE NULL");
$pdo->exec("UPDATE ministry_members SET joined_on=CURRENT_DATE() WHERE joined_on IS NULL");
$pdo->exec("ALTER TABLE ministry_members MODIFY COLUMN joined_on DATE NOT NULL");

$joinedInfo=$pdo->query("SELECT COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ministry_members' AND COLUMN_NAME='joined_on'")
 ->fetch(PDO::FETCH_ASSOC);
$check($joinedInfo['COLUMN_DEFAULT']===null&&$joinedInfo['IS_NULLABLE']==='NO'&&$joinedInfo['DATA_TYPE']==='date',
  'live-style joined_on DATE is mandatory and has no default');

$memberJoinedDate=app_uuid();
$pdo->prepare("INSERT INTO members(id,church_id,name,phone,active) VALUES(?,?,?,'',1)")
    ->execute([$memberJoinedDate,$churchA,'Cadastro Fictício com Data Obrigatória']);
$status=$departmentsSvc->assign($memberJoinedDate,$deptId,'leader');
$check($status==='pending_login','leader assignment safely creates department participation before login exists');
$row=$pdo->prepare('SELECT joined_on FROM ministry_members WHERE church_id=? AND member_id=? AND department_id=?');
$row->execute([$churchA,$memberJoinedDate,$deptId]);
$val=$row->fetchColumn();
$check($val===date('Y-m-d'),'joined_on DATE always filled on legacy membership insert');
$leaderRole=$pdo->prepare('SELECT role_name FROM member_department_roles WHERE church_id=? AND member_id=? AND department_id=?');
$leaderRole->execute([$churchA,$memberJoinedDate,$deptId]);
$check($leaderRole->fetchColumn()==='leader','leader role commits after mandatory joined_on saved');
$count=$pdo->prepare('SELECT COUNT(*) FROM ministry_members WHERE church_id=? AND member_id=? AND department_id=?');
$count->execute([$churchA,$memberJoinedDate,$deptId]);
$check((int)$count->fetchColumn()===1,'first department link has one membership row only');
$departmentsSvc->assign($memberJoinedDate,$deptId,'leader');
$count->execute([$churchA,$memberJoinedDate,$deptId]);
$check((int)$count->fetchColumn()===1,'saving again does not insert duplicate joined_on row');

// Another live schema uses DATETIME instead of DATE. The same service must adapt.
$pdo->exec("ALTER TABLE ministry_members MODIFY COLUMN joined_on DATETIME NOT NULL");
$joinedInfo=$pdo->query("SELECT COLUMN_DEFAULT,DATA_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ministry_members' AND COLUMN_NAME='joined_on'")
 ->fetch(PDO::FETCH_ASSOC);
$check($joinedInfo['COLUMN_DEFAULT']===null&&$joinedInfo['DATA_TYPE']==='datetime',
 'strict DATETIME joined_on must also be supported');

$memberJoinedTime=app_uuid();
$pdo->prepare("INSERT INTO members(id,church_id,name,phone,active) VALUES(?,?,?,'',1)")
  ->execute([$memberJoinedTime,$churchA,'Membro Fictício Data e Hora']);
$departmentsSvc->assign($memberJoinedTime,$deptId,'leader');
$row->execute([$churchA,$memberJoinedTime,$deptId]);
$stamp=$row->fetchColumn();
$check(is_string($stamp)&&preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/',$stamp)===1,
 'joined_on DATETIME is always filled with date and time');
$leaderRole->execute([$churchA,$memberJoinedTime,$deptId]);
$check($leaderRole->fetchColumn()==='leader','DATETIME schema persists real leadership');

// Verify tenant scoping: foreign member cannot be added to this church through the new fix.
try{
  $departmentsSvc->assign($foreign,$deptId,'leader');
  throw new RuntimeException('Other church member incorrectly accepted');
}catch(InvalidArgumentException $e){
  $check(str_contains($e->getMessage(),'Membro'),'foreign member blocked despite joined_on fix');
}
$check(!$authJoinedOn->can('finance')===false,'manager ability remains unchanged by joined_on fix');

echo "PASS 3.7.21 strict joined_on DATE and DATETIME, real leadership, repeat-safe membership insert, cross-church rejection and no destructive migration\\n";
