<?php
declare(strict_types=1);
/* Entire previous 3.7.12 suite plus new features. All names/data are synthetic. */
require __DIR__.'/../../3.7.12/tests/import_mysql.php';
require_once $dir.'/public/lib/MemberDepartmentService.php';
require_once $dir.'/public/lib/MasterCongregationService.php';
require_once $dir.'/public/lib/MemberPortalAccessService.php';

$pdo->exec("CREATE TABLE ministry_members(
  id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,department_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,active TINYINT NOT NULL DEFAULT 1,
  UNIQUE KEY uk_ministry_members(church_id,member_id,department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE congregations(
  id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,name VARCHAR(160) NOT NULL,
  active TINYINT DEFAULT 1,sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("ALTER TABLE churches ADD COLUMN max_users INT NULL");
$pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL");
$pdo->exec("CREATE TABLE profiles(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,full_name VARCHAR(190))
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
MemberDepartmentService::ensure($pdo);
MasterCongregationService::ensure($pdo);

$cgA=app_uuid();$cgB=app_uuid();$cgForeign=app_uuid();
foreach([[$cgA,$churchA,'Campo Belo'],[$cgB,$churchA,'Congregação Centro'],[$cgForeign,$churchB,'Outra Congregação']] as $c)
  $pdo->prepare('INSERT INTO congregations(id,church_id,name) VALUES(?,?,?)')->execute($c);
$memberA=app_uuid();$memberB=app_uuid();$foreign=app_uuid();
foreach([[$memberA,$churchA,'Membro de teste A'],[$memberB,$churchA,'Membro de teste B'],[$foreign,$churchB,'Outra pessoa']] as $p)
  $pdo->prepare("INSERT INTO members(id,church_id,name,phone,active) VALUES(?,?,?,'',1)")
      ->execute($p);

$admin=app_uuid();
$pdo->prepare("INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)")
    ->execute([$admin,'admin-test-3713@example.org','Administrador']);
$pdo->prepare("INSERT INTO church_members(id,church_id,user_id,church_role) VALUES(?,?,?,'admin')")
    ->execute([app_uuid(),$churchA,$admin]);
$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$adminAuth=new Auth($pdo);
$dept=new MemberDepartmentService($pdo,$adminAuth,$churchA,$admin);
$check(count($dept->departments())>=3,'department dropdown reads existing departments');
$check(count($dept->members())>=2,'member selection uses active church member list');
$deptId=$dept->departments()[0]['id'];
$check($dept->assign($memberA,$deptId,'participant')==='saved','department participant linked');
$check((int)$pdo->query('SELECT COUNT(*) FROM ministry_members WHERE church_id=\''.$churchA.'\'')->fetchColumn()===1,
 'legacy department counter receives member');
$check(count($dept->forMember($memberA))===1,'member editor can retrieve existing department');
$check($dept->assign($memberB,$deptId,'leader')==='pending_login','leadership can be recorded without inventing login');
$links=$dept->assignments($deptId);
$check(count($links)>=2,'leader and participant appear on department page');

$secretary=app_uuid();
$pdo->prepare('INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)')
  ->execute([$secretary,'secretary-test-3713@example.org','Secretária']);
$pdo->prepare("INSERT INTO church_members(id,church_id,user_id,church_role) VALUES(?,?,?,'secretary')")
  ->execute([app_uuid(),$churchA,$secretary]);
$_SESSION=['user_id'=>$secretary,'church_id'=>$churchA];
$secretaryAuth=new Auth($pdo);
$secretaryDept=new MemberDepartmentService($pdo,$secretaryAuth,$churchA,$secretary);
try{$secretaryDept->assign($memberA,$deptId,'leader');throw new RuntimeException('secretary granted leadership');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Somente'),'secretary cannot grant leader access');}
$check($secretaryDept->assign($memberA,$deptId,'participant')==='saved','secretary may assign volunteer');
try{new MemberDepartmentService($pdo,$secretaryAuth,$churchB,$secretary);throw new RuntimeException('cross church');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'negado'),'secretary does not enter other church');}

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$portal=new MemberPortalAccessService($pdo,$adminAuth,$churchA,$admin);
$check($portal->access($memberA)===null,'personal portal starts unlinked');
$login=$portal->activate($memberA,'pessoa.test-3713@example.org','StrongTemporaryPass_123!');
$check($login['new_user']===true,'new user gets hashed initial password');
$access=$portal->access($memberA);
$check($access!==null&&$access['church_role']==='member','member role and person link recorded');
$check($adminAuth->can('manage_users'),'admin still has management access');
try{$portal->activate($memberA,'pessoa.test-3713@example.org','OtherTemporaryPass_123!');throw new RuntimeException('duplicate portal');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'já tem acesso'),'cannot activate a member twice');}
try{$portal->member($foreign);throw new RuntimeException('other church person opened');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'não encontrada'),'member portal tenant protected');}

$uid=$pdo->prepare("SELECT user_id FROM church_members WHERE church_id=? AND person_id=?");
$uid->execute([$churchA,$memberA]);$memberUid=$uid->fetchColumn();
$_SESSION=['user_id'=>$memberUid,'church_id'=>$churchA];
$memberAuth=new Auth($pdo);
$check($memberAuth->can('member_portal'),'linked member may see own portal');
$check(!$memberAuth->can('finance'),'member cannot open general finance');
$check(!$memberAuth->can('department_treasury'),'member cannot open department treasuries');
$check(!$memberAuth->can('manage_users'),'member cannot create accounts');

$_SESSION=['user_id'=>$master,'platform_master_mode'=>1];
$masterAuth=new Auth($pdo);
$bulk=new MasterCongregationService($pdo,$masterAuth,$master);
$check(count($bulk->congregations($churchA))===2,'Master sees only congregations of selected church');
$check($bulk->people($churchA)['unassigned']>=2,'unassigned member count');
try{$bulk->assign($churchA,$cgForeign,'selected',[$memberA],$bulk->church($churchA)['name'],'Outra Congregação');throw new RuntimeException('other congregation');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'não encontrada'),'cross-church congregation blocked');}
$name=$bulk->church($churchA)['name'];
$moved=$bulk->assign($churchA,$cgA,'selected',[$memberA,$memberB],$name,'Campo Belo');
$check($moved['moved']===2,'multiple selected members updated together');
$q=$pdo->prepare('SELECT COUNT(*) FROM members WHERE church_id=? AND congregation_id=? AND id IN (?,?)');
$q->execute([$churchA,$cgA,$memberA,$memberB]);
$check((int)$q->fetchColumn()===2,'bulk selected destination persisted');
$check($bulk->undo($churchA,$moved['batch'])===2,'bulk action undo restores old congregation');
$all=$bulk->assign($churchA,$cgA,'unassigned',[],$name,'Campo Belo');
$check($all['moved']>=2,'all unassigned members can be set in a single action');
$q=$pdo->prepare('SELECT congregation_id FROM members WHERE church_id=? AND id=?');
$q->execute([$churchB,$foreign]);
$check($q->fetchColumn()===null,'other church remains unchanged');
$check($bulk->undo($churchA,$all['batch'])===$all['moved'],'full bulk undo is recorded');
$check(count($bulk->batches($churchA))>=2,'bulk action audit history');
echo "PASS 3.7.13 member form reference data, department links, role ACL, member portal and Master bulk congregation undo\n";
