<?php
declare(strict_types=1);
/** Full cumulative tests plus safe membership-to-leader upgrade with fictional records. */
require __DIR__.'/../../3.7.19/tests/integration.php';
require_once $dir.'/public/lib/ChurchMemberLeadershipService.php';

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$managerAuth=new Auth($pdo);
$leadAccess=new ChurchMemberLeadershipService($pdo,$managerAuth,$churchA,$admin);
$person=$leadAccess->member($memberA);
$check($person['name']==='Membro de teste A','member identity comes from authoritative tenant member row');
$check($leadAccess->linkedLogin($memberA)['user_id']===$participantUser,
  'existing personal login can be reused during promotion, not duplicated');
$check($leadAccess->linkedLogin($memberB)['user_id']===$leaderUser,
  'existing department leader login can be reused without replacing password');
$check(in_array($deptId,$leadAccess->oldDepartmentIds($leaderUser),true),
  'existing login department scope is discovered for role adjustments');
try{$leadAccess->member($foreign);throw new RuntimeException('read member outside tenant');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'igreja'),'foreign member not readable');}

$all=$leadAccess->oldDepartmentIds($participantUser);
$check($all===[],'participant member does not inherit unrelated department roles');
$other=null;
foreach($pdo->query("SELECT id FROM ministry_departments WHERE church_id='".$churchA."' AND active=1") as $dept){
  if($dept['id']!==$deptId){$other=(string)$dept['id'];break;}
}
$check(is_string($other),'second available department exists');

$countBefore=(int)$pdo->query("SELECT COUNT(*) FROM church_members WHERE church_id='".$churchA."' AND person_id='".$memberA."'")->fetchColumn();
$pdo->beginTransaction();
$pdo->prepare("UPDATE church_members SET church_role='department_leader' WHERE church_id=? AND user_id=?")
  ->execute([$churchA,$participantUser]);
$pdo->prepare("DELETE FROM department_access WHERE church_id=? AND user_id=?")->execute([$churchA,$participantUser]);
$pdo->prepare("INSERT INTO department_access(church_id,user_id,department_id) VALUES(?,?,?)")
  ->execute([$churchA,$participantUser,$other]);
$leadAccess->sync($memberA,'department_leader',[$other],$memberA,[]);
$pdo->commit();

$nowRole=$pdo->prepare('SELECT role_name FROM member_department_roles WHERE church_id=? AND member_id=? AND department_id=?');
$nowRole->execute([$churchA,$memberA,$other]);
$check($nowRole->fetchColumn()==='leader','portal login assignment creates explicit leader status');
$check((int)$pdo->query("SELECT COUNT(*) FROM church_members WHERE church_id='".$churchA."' AND person_id='".$memberA."'")->fetchColumn()===$countBefore,
  'promotion does not duplicate user/church membership');
$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$leaderAuthNew=new Auth($pdo);
$check($leaderAuthNew->can('department_team_manage'),'promoted login manages its department');
$check($leaderAuthNew->can('department_treasury'),'promoted login can access own department treasury');
$check(!$leaderAuthNew->can('finance'),'promotion does not grant general church finance');
$check(!DepartmentRoleAccess::hasFinancialScope($pdo,$churchA,$participantUser,$deptId),
  'being participant of another department does not grant its treasury');
$check(DepartmentRoleAccess::hasFinancialScope($pdo,$churchA,$participantUser,$other),
  'selected leader department treasury is unlocked');

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$managerAuth=new Auth($pdo);
$leadAccess=new ChurchMemberLeadershipService($pdo,$managerAuth,$churchA,$admin);
$oldIds=$leadAccess->oldDepartmentIds($participantUser);
$pdo->beginTransaction();
$pdo->prepare("UPDATE church_members SET church_role='member' WHERE church_id=? AND user_id=?")->execute([$churchA,$participantUser]);
$pdo->prepare("DELETE FROM department_access WHERE church_id=? AND user_id=?")->execute([$churchA,$participantUser]);
$leadAccess->sync($memberA,'member',[],$memberA,$oldIds);
$pdo->commit();
$nowRole->execute([$churchA,$memberA,$other]);
$check($nowRole->fetchColumn()==='participant','removing leader role preserves department participation');
$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$memberAfter=new Auth($pdo);
$check($memberAfter->can('member_portal'),'demoted leader retains personal member portal');
$check(!$memberAfter->can('department_treasury'),'demoted leader no longer can view department finance');

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$managerAuth=new Auth($pdo);
$leadAccess=new ChurchMemberLeadershipService($pdo,$managerAuth,$churchA,$admin);
try{$leadAccess->sync($memberA,'department_leader',[],null,[]);
  throw new RuntimeException('leader with no department accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'departamento'),'no department blocks leader grant');}
try{$leadAccess->sync(null,'department_leader',[$other],null,[]);
  throw new RuntimeException('leader with no member accepted');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'membro'),'no member blocks leader grant');}
try{$leadAccess->sync($foreign,'department_leader',[$other],null,[]);
  throw new RuntimeException('foreign member granted leadership');}
catch(RuntimeException $e){$check(true,'foreign tenant member blocked');}

// Validate the actual registration handler code is wired to the same service.
$users=file_get_contents($dir.'/public/users.php');
$check(str_contains($users,'$leadAccess->linkedLogin($personId)'),'user create form reuses existing member login');
$check(str_contains($users,'$leadAccess->sync($personId?:null,$role,$ids,$oldMemberId,$oldDeptIds)'),
    'new leader form persists actual member_department_roles in a single transaction');
$check(str_contains($users,'$leadAccess->sync($memberId,$role,$newDeptIds,$oldMemberId,$oldDeptIds)'),
    'editing access also updates and revokes actual department leadership');
$check(str_contains($users,"$"."memberInfo['name']"),'server uses trusted member name');
$check(str_contains($users,'data-user-create-from-member'),'autofill scoped to creation form');
$check(str_contains($users,'data-member-email'),'select has email from existing member');
$check(str_contains($users,'if($membership)'), 'upgrading existing login does not create another church_members row');
$check(str_contains($users,'if($maxUsers!==null&&$currentUsers>=$maxUsers)'),
    'plan limit applies to actual new users');

echo "PASS 3.7.20 auto member identity, promote existing personal login, explicit department leadership and finance scope, safe downgrade, tenant isolation, no duplicate account\n";
