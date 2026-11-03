<?php
declare(strict_types=1);
/**
 * Full cumulative regression. All fixtures are fictional, never real Enuves members.
 * Prior suites cover 3.7.9 to 3.7.13; this suite validates department role separation.
 */
require __DIR__.'/../../3.7.13/tests/integration.php';
require_once $dir.'/public/lib/DepartmentRoleAccess.php';
require_once $dir.'/public/lib/DepartmentDelegationService.php';

$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$adminAuth=new Auth($pdo);
$adminDept=new MemberDepartmentService($pdo,$adminAuth,$churchA,$admin);
$allDepartments=$adminDept->departments();
$otherId=null;
foreach($allDepartments as $d)if($d['id']!==$deptId && $d['active']){$otherId=$d['id'];break;}
$check($otherId!==null,'multiple independent departments available');
$adminDept->assign($memberA,$otherId,'participant');

$accountantGeneral=new DepartmentTreasuryService($pdo,$adminAuth,$churchA,$admin);
$accountId=$accountantGeneral->createAccount($deptId,[
    'account_name'=>'Caixa reservado a departamento 3714','account_type'=>'cash'
]);

// A regular participant has member portal only, even though two departments are assigned.
$q=$pdo->prepare('SELECT user_id FROM church_members WHERE church_id=? AND person_id=?');
$q->execute([$churchA,$memberA]);$participantUser=(string)$q->fetchColumn();
$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$participantAuth=new Auth($pdo);
$check($participantAuth->churchRole()==='member','participant retains ordinary church member login');
$check($participantAuth->can('member_portal'),'participant keeps personal giving and notices');
$check(!$participantAuth->can('department_treasury'),'ordinary participant has no departmental cash permission');
$check(!$participantAuth->can('department_portal'),'ordinary participant has no legacy department cash');
$check(!$participantAuth->can('department_team_manage'),'ordinary participant cannot appoint treasurers');
$check(!$participantAuth->can('finance'),'ordinary participant has no general treasury');
$check(!DepartmentRoleAccess::hasFinancialScope($pdo,$churchA,$participantUser,$otherId),
    'other department participation cannot grant finance');

// A member-portal login becomes a leader only through explicit dept assignment.
$_SESSION=['user_id'=>$admin,'church_id'=>$churchA];
$portalCreator=new MemberPortalAccessService($pdo,$adminAuth,$churchA,$admin);
$created=$portalCreator->activate($memberB,'leader-3714@example.org','InitialSecurePass_3714!');
$check($created['new_user']===true,'department leader gets member login, not general treasurer role');
$q->execute([$churchA,$memberB]);$leaderUser=(string)$q->fetchColumn();
$_SESSION=['user_id'=>$leaderUser,'church_id'=>$churchA];
$leaderAuth=new Auth($pdo);
$check($leaderAuth->churchRole()==='member','same personal login can be scoped leader of department');
$check($leaderAuth->can('member_portal'),'leader can still see own contributions');
$check($leaderAuth->can('department_treasury'),'only explicit department leader can see departmental cash');
$check($leaderAuth->can('department_team_manage'),'leader can designate own department treasurer');
$check(!$leaderAuth->can('finance'),'leader cannot view general church treasury');
$check(!$leaderAuth->can('accounting_portal'),'leader cannot view church accountant package');
$team=new DepartmentDelegationService($pdo,$leaderAuth,$churchA,$leaderUser);
$led=$team->departments();
$check(count($led)===1&&$led[0]['id']===$deptId,'leader only manages own assigned department');
$rows=$team->team($deptId);
$check(count($rows)>=2,'leader can see participants of own ministry without all-church member access');
try{$team->team($otherId);throw new RuntimeException('leader read other team');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'só pode'),'leader blocked from other department team');}
try{$team->setTreasurer($deptId,$foreign,true);throw new RuntimeException('other tenant delegated');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Primeiro vincule'),'leader cannot appoint outsider');}
try{$team->setTreasurer($otherId,$memberA,true);throw new RuntimeException('delegated on other department');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'só pode'),'leader cannot delegate on other department');}

$result=$team->setTreasurer($deptId,$memberA,true);
$check($result==='active','leader appoints treasurer from current department participants with login');
$role=$pdo->prepare('SELECT role_name FROM member_department_roles WHERE church_id=? AND department_id=? AND member_id=?');
$role->execute([$churchA,$deptId,$memberA]);
$check($role->fetchColumn()==='treasurer','treasurer is department-specific, not church-wide');
$role->execute([$churchA,$otherId,$memberA]);
$check($role->fetchColumn()==='participant','person remains regular participant elsewhere');

// Delegation changes the scoped financial view without changing the person's church_role.
$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$treasurerAuth=new Auth($pdo);
$check($treasurerAuth->churchRole()==='member','treasurer maintains ordinary member login');
$check($treasurerAuth->can('member_portal'),'department treasurer keeps personal portal');
$check($treasurerAuth->can('department_treasury'),'department treasurer can use delegated treasury');
$check($treasurerAuth->can('department_portal'),'department treasurer can read authorized department');
$check(!$treasurerAuth->can('finance'),'department treasurer has no general financial permission');
$check(!$treasurerAuth->can('reports'),'department treasurer cannot access general reports');
$check(!$treasurerAuth->can('accounting_portal'),'department treasurer has no church accounting permissions');
$check(!$treasurerAuth->can('manage_users'),'department treasurer cannot grant other accesses');
$check(!$treasurerAuth->can('department_team_manage'),'department treasurer cannot delegate');
$treasury=new DepartmentTreasuryService($pdo,$treasurerAuth,$churchA,$participantUser);
$scoped=$treasury->departments();
$check(count($scoped)===1&&$scoped[0]['id']===$deptId,'delegated treasurer only sees one department');
$check($treasury->mayPost(),'department treasurer can record income/expense in own cash');
$check(!$treasury->mayCreateAccount(),'department treasurer cannot open new church financial accounts');
$check(count($treasury->accounts($deptId))>=1,'treasurer can read own departmental cash');
try{$treasury->accounts($otherId);throw new RuntimeException('treasurer saw unrelated department');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não permite'),'treasurer cannot access other department account');}
try{$treasury->generalPostedTotals('2026-11-01','2026-11-30');throw new RuntimeException('treasurer saw church totals');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Somente'),'church-wide consolidated figures hidden from treasurer');}
try{$treasury->createEntry($deptId,[
 'account_id'=>$accountId,'direction'=>'expense','category'=>'Lanche','description'=>'Despesa sem cupom',
 'amount'=>'10,00','transaction_date'=>'2026-11-03'
]);throw new RuntimeException('treasurer posted unsupported expense');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'comprovante'),'treasurer expense requires receipt');}

// Legacy PortalService posting/reversal is denied to scoped profiles; only new receipt-aware service may post.
$legacy=new PortalService($pdo,$treasurerAuth,$churchA);
try{$legacy->createEntry($deptId,[]);throw new RuntimeException('legacy portal bypass allowed');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Tesouraria do Departamento'),'legacy no-receipt posting blocked');}
try{$legacy->reverse($deptId,'dummy','Teste de estorno');throw new RuntimeException('legacy portal reversal allowed');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'tesouraria geral'),'treasurer cannot reverse via old endpoint');}

$entry=$treasury->createEntry($deptId,[
 'account_id'=>$accountId,'direction'=>'income','category'=>'Oferta','description'=>'Oferta dos jovens',
 'amount'=>'7,25','transaction_date'=>'2026-11-03'
]);
$check(strlen($entry)===36,'department treasurer posted in same ledger without duplication');
$q=$pdo->prepare('SELECT COUNT(*) FROM finance_entries WHERE church_id=? AND id=? AND department_id=?');
$q->execute([$churchA,$entry,$deptId]);
$check((int)$q->fetchColumn()===1,'single transaction belongs to department and central ledger');

// Revocation immediately removes scoped access without revoking the personal member portal.
$_SESSION=['user_id'=>$leaderUser,'church_id'=>$churchA];
$leaderAuth=new Auth($pdo);
$team=new DepartmentDelegationService($pdo,$leaderAuth,$churchA,$leaderUser);
$check($team->setTreasurer($deptId,$memberA,false)==='revoked','leader can revoke own department treasurer');
$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$revokeAuth=new Auth($pdo);
$check($revokeAuth->can('member_portal'),'after revocation member still has personal portal');
$check(!$revokeAuth->can('department_treasury'),'treasurer revocation takes effect immediately');
$check(!$revokeAuth->can('finance'),'revoked member never gains general finance');
$check(!DepartmentRoleAccess::hasFinancialScope($pdo,$churchA,$participantUser,$otherId),
 'other department participant remains isolated');
try{new DepartmentTreasuryService($pdo,$revokeAuth,$churchA,$participantUser);
    throw new RuntimeException('revoked user has treasury');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'negado'),'constructor rejects revoked role');}
echo "PASS 3.7.14 participant personal-only portal, explicit department leaders, scoped treasurer delegation and revocation, receipt proof, no cross-church/general finance access\n";
