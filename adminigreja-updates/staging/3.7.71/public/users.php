<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/ChurchMemberLeadershipService.php';
$user=$auth->requireAbility('manage_users');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$currentRole=$auth->churchRole();
$isMaster=$auth->hasRole('super_admin',(string)$user['id']);
// Create required role table BEFORE any transaction (MariaDB DDL autocommits).
MemberDepartmentService::ensure($pdo);
$leadAccess=new ChurchMemberLeadershipService($pdo,$auth,$cid,(string)$user['id']);
$error=flash('error');$success=flash('success');

$roleLabels=[
    'owner'=>'Proprietário',
    'admin'=>'Administrador',
    'pastor'=>'Pastor',
    'secretary'=>'Secretaria',
    'treasurer'=>'Tesoureiro Geral',
    'accountant'=>'Contador (consulta e conferência)',
    'department_leader'=>'Líder de departamento',
    'group_leader'=>'Líder de grupo/célula',
    'teacher'=>'Professor / líder de turma',
    'kids_worker'=>'Equipe Kids',
    'member'=>'Membro (somente seus dados)',
];

function allowed_church_roles(string $currentRole,bool $isMaster): array {
    if($isMaster||$currentRole==='owner')return ['admin','pastor','secretary','treasurer','accountant','department_leader','group_leader','teacher','kids_worker','member'];
    return ['secretary','treasurer','accountant','department_leader','group_leader','teacher','kids_worker','member'];
}
function church_user_audit(PDO $pdo,string $userId,string $churchId,string $action,array $details=[]): void {
    try{
        $q=$pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)');
        $q->execute([app_uuid(),$userId,$churchId,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }catch(Throwable){}
}

$allowed=allowed_church_roles($currentRole,$isMaster);
$roleDescriptions=[
    'admin'=>'Administração da igreja, pessoas, usuários, tesouraria e configurações; não dá acesso ao painel Master.',
    'pastor'=>'Acompanha e administra a igreja: membros, secretária, tesouraria geral, departamentos, relatórios e usuários.',
    'secretary'=>'Secretaria, cadastro de membros, documentos e comunicações; não pode excluir lançamentos da tesouraria.',
    'treasurer'=>'Tesouraria Geral: entradas, saídas, caixas e departamentos, comprovantes, relatórios e exclusão auditada de lançamentos incorretos.',
    'accountant'=>'Contabilidade: portal contábil, conferência e relatórios. Não pode realizar ou excluir lançamentos financeiros.',
    'department_leader'=>'Liderança e tesouraria dos departamentos escolhidos; não recebe o caixa geral.',
    'group_leader'=>'Gestão dos grupos/células associados ao seu cadastro, sem acesso à tesouraria geral.',
    'teacher'=>'Acesso às turmas e atividades de ensino sob sua responsabilidade.',
    'kids_worker'=>'Acesso às atividades infantis autorizadas.',
    'member'=>'Portal pessoal, avisos e consulta das próprias contribuições; sem acesso administrativo.',
];
$roleDetailsForUser=static function(string $role)use($roleDescriptions):string{
    return $roleDescriptions[$role]??'Acesso configurado para a função selecionada.';
};

function save_portal_scope(PDO $pdo,string $cid,string $uid,string $role,array $input): void {
    $personId=trim((string)($input['person_id']??''))?:null;
    if(in_array($role,['member','group_leader','teacher','kids_worker'],true)&&!$personId)throw new RuntimeException('Vincule este acesso a um cadastro de membro. Líderes de grupo, professores e equipe Kids precisam desse vínculo para enxergar somente os grupos, turmas ou equipes em que foram definidos.');
    if($personId){
        $q=$pdo->prepare('SELECT id FROM members WHERE church_id=? AND id=? AND active=1');$q->execute([$cid,$personId]);
        if(!$q->fetchColumn())throw new RuntimeException('Membro não encontrado nesta igreja.');
        $q=$pdo->prepare('SELECT 1 FROM church_members WHERE church_id=? AND person_id=? AND user_id<>?');$q->execute([$cid,$personId,$uid]);
        if($q->fetchColumn())throw new RuntimeException('Este membro já está vinculado a outro login.');
    }
    $ids=$input['department_ids']??[];
    if(!is_array($ids))throw new RuntimeException('Seleção de departamentos inválida.');
    $ids=array_values(array_unique(array_map('strval',$ids)));
    if($role==='department_leader'&&!$personId)throw new RuntimeException('Selecione um membro cadastrado para nomear líder do departamento.');
    if($role==='department_leader'&&!$ids)throw new RuntimeException('Selecione pelo menos um departamento para o líder.');
    if($role==='department_leader')foreach($ids as $id){
        $q=$pdo->prepare('SELECT id FROM ministry_departments WHERE church_id=? AND id=? AND active=1');$q->execute([$cid,$id]);
        if(!$q->fetchColumn())throw new RuntimeException('Departamento não encontrado nesta igreja.');
    }
    $pdo->prepare('UPDATE church_members SET person_id=? WHERE church_id=? AND user_id=?')->execute([$personId,$cid,$uid]);
    $pdo->prepare('DELETE FROM department_access WHERE church_id=? AND user_id=?')->execute([$cid,$uid]);
    if($role==='department_leader')foreach($ids as $id)$pdo->prepare('INSERT INTO department_access(church_id,user_id,department_id) VALUES(?,?,?)')->execute([$cid,$uid,$id]);
}

$q=$pdo->prepare('SELECT m.id,m.name,m.email,cm.user_id linked_user_id,u.email login_email
    FROM members m
    LEFT JOIN church_members cm ON cm.church_id=m.church_id AND cm.person_id=m.id
    LEFT JOIN users u ON u.id=cm.user_id
    WHERE m.church_id=? AND m.active=1 ORDER BY m.name');
$q->execute([$cid]);$portalPeople=$q->fetchAll();
// Only members explicitly registered AND active in this church may be
// selected by the shortcut from "Lista de membros". GET does not grant roles.
$memberFromList=(string)($_GET['member']??'');
if(!preg_match('/^[0-9a-f-]{36}$/iD',$memberFromList))$memberFromList='';
$selectedPerson=null;
foreach($portalPeople as $p){
    if($memberFromList!=='' && (string)$p['id']===$memberFromList){
        $selectedPerson=$p;break;
    }
}
$selectedRole=(string)($_GET['role']??'');
if(!in_array($selectedRole,$allowed,true))$selectedRole='';
$selectedName=$selectedPerson?(string)$selectedPerson['name']:'';
$selectedEmail=$selectedPerson?(string)(($selectedPerson['login_email']??'')?:($selectedPerson['email']??'')):'';
$q=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');$q->execute([$cid]);$portalDepartments=$q->fetchAll();
$q=$pdo->prepare("SELECT user_id,department_id FROM department_access WHERE church_id=?
        UNION
        SELECT cm.user_id,r.department_id FROM member_department_roles r
        JOIN church_members cm ON cm.church_id=r.church_id AND cm.person_id=r.member_id
        WHERE r.church_id=? AND r.role_name='leader'");
$q->execute([$cid,$cid]);$portalScopes=[];
foreach($q->fetchAll() as $scope)$portalScopes[$scope['user_id']][]=$scope['department_id'];
function portal_scope_fields(array $people,array $departments,?string $personId=null,array $ids=[]): void { ?>
<div class="field full"><label>Membro já cadastrado nesta igreja
  <select class="select" name="person_id" aria-label="Selecionar membro da lista">
    <option value="">Escolher membro da lista…</option>
    <?php foreach($people as $p):?>
    <option value="<?=e($p['id'])?>" data-member-name="<?=e($p['name'])?>" data-member-email="<?=e((string)(($p['login_email']??'')?:($p['email']??'')))?>" data-member-login="<?=!empty($p['linked_user_id'])?'1':'0'?>" <?=$personId===$p['id']?'selected':''?>><?=e($p['name'])?></option>
    <?php endforeach?>
  </select>
</label><small>Selecione a pessoa uma vez. O sistema usa o mesmo cadastro e, caso já exista login, mantém a senha.</small></div>
<div class="field full" data-user-department-field><label>Departamentos do líder
  <select class="select" name="department_ids[]" multiple size="4"><?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=in_array($d['id'],$ids,true)?'selected':''?>><?=e($d['name'])?></option><?php endforeach?></select>
</label><small>Somente para Líder de Departamento: selecione um ou mais departamentos. Não concede a Tesouraria Geral.</small></div>
<?php }

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='create'){
            $personId=trim((string)($_POST['person_id']??''));
            $name=trim((string)($_POST['full_name']??''));
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $password=(string)($_POST['password']??'');
            $role=(string)($_POST['church_role']??'secretary');
            if(!in_array($role,$allowed,true))throw new RuntimeException('Você não pode conceder esse perfil.');

            // When an existing member was selected, the trusted identity is
            // always the members row of THIS church, not retyped form fields.
            $linked=null;$memberInfo=null;
            if($personId!==''){
                $memberInfo=$leadAccess->member($personId);
                $linked=$leadAccess->linkedLogin($personId);
                $name=trim((string)$memberInfo['name']);
                $storedEmail=mb_strtolower(trim((string)($memberInfo['email']??'')));
                $email=$linked?mb_strtolower(trim((string)$linked['email'])):
                    ($storedEmail!==''?$storedEmail:$email);
            }
            if($role==='department_leader'&&$personId==='')
                throw new RuntimeException('Selecione o membro cadastrado para tornar líder.');
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))
                throw new RuntimeException('Informe nome e e-mail válido. Se o membro não tem e-mail, cadastre um endereço pessoal para criar o login.');

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id,full_name,active FROM users WHERE email=? LIMIT 1');
                $q->execute([$email]);$existing=$q->fetch(PDO::FETCH_ASSOC);
                if($linked && (!$existing||(string)$linked['user_id']!==(string)$existing['id'])){
                    throw new RuntimeException('O cadastro já possui outro login. Confira o e-mail e solicite correção antes de mudar a função.');
                }
                if($existing){
                    $targetUserId=(string)$existing['id'];
                    if(!$existing['active'])throw new RuntimeException('Este e-mail pertence a uma conta desativada.');
                    if($auth->isSuperAdmin($targetUserId))throw new RuntimeException('O Master não pode ser vinculado como usuário de igreja.');
                }else{
                    if(strlen($password)<8)throw new RuntimeException('Para um novo usuário, informe senha inicial com pelo menos 8 caracteres.');
                    $targetUserId=app_uuid();
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')
                        ->execute([$targetUserId,$email,password_hash($password,PASSWORD_DEFAULT),$name]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')
                        ->execute([app_uuid(),$targetUserId,$name]);
                }

                $check=$pdo->prepare('SELECT id,person_id,church_role,is_owner FROM church_members WHERE church_id=? AND user_id=? FOR UPDATE');
                $check->execute([$cid,$targetUserId]);$membership=$check->fetch(PDO::FETCH_ASSOC);
                $oldMemberId=null;$oldDeptIds=[];$upgraded=false;
                if($membership){
                    if($personId===''||(
                        (string)($membership['person_id']??'')!=='' &&
                        (string)$membership['person_id']!==$personId)){
                        throw new RuntimeException('Esta conta já pertence a um cadastro diferente desta igreja. Use a edição do acesso existente.');
                    }
                    if(!empty($membership['is_owner']))
                        throw new RuntimeException('Não é permitido alterar o acesso do proprietário nesta tela.');
                    if($targetUserId===(string)$user['id']&&!$isMaster)
                        throw new RuntimeException('Você não pode alterar seu próprio perfil de acesso.');
                    if(!$isMaster&&$currentRole!=='owner'&&!in_array((string)$membership['church_role'],$allowed,true))
                        throw new RuntimeException('Você não pode alterar esse perfil de acesso.');
                    $oldMemberId=(string)($membership['person_id']??'')?:null;
                    $oldDeptIds=$leadAccess->oldDepartmentIds($targetUserId);
                    $pdo->prepare('UPDATE church_members SET church_role=?,permissions=NULL WHERE id=? AND church_id=? AND is_owner=0')
                        ->execute([$role,(string)$membership['id'],$cid]);
                    $upgraded=true;
                }else{
                    $countQ=$pdo->prepare('SELECT COUNT(*) FROM church_members WHERE church_id=?');
                    $countQ->execute([$cid]);$currentUsers=(int)$countQ->fetchColumn();
                    $maxUsers=isset($church['max_users'])&&$church['max_users']!==null?(int)$church['max_users']:null;
                    if($maxUsers!==null&&$currentUsers>=$maxUsers)
                        throw new RuntimeException('Esta igreja atingiu o limite de usuários do plano.');

                    $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role,permissions) VALUES(?,?,?,0,?,NULL)')
                        ->execute([app_uuid(),$cid,$targetUserId,$role]);
                }

                $scope=$_POST;
                $scope['person_id']=$personId;
                save_portal_scope($pdo,$cid,$targetUserId,$role,$scope);
                $ids=is_array($_POST['department_ids']??null)?$_POST['department_ids']:[];
                $leadAccess->sync($personId?:null,$role,$ids,$oldMemberId,$oldDeptIds);

                if($memberInfo && trim((string)($memberInfo['email']??''))===''){
                    $pdo->prepare('UPDATE members SET email=? WHERE id=? AND church_id=? AND active=1')
                        ->execute([$email,$personId,$cid]);
                }
                church_user_audit($pdo,(string)$user['id'],$cid,$upgraded?'church_member_login_promoted':'church_user_created',[
                    'target_user_id'=>$targetUserId,'member_id'=>$personId,
                    'church_role'=>$role,'departments'=>$role==='department_leader'?$ids:[],
                    'existing_login'=>(bool)$existing
                ]);
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            flash('success',$upgraded
              ?'Acesso existente atualizado. A liderança foi vinculada ao departamento escolhido, sem criar outro login ou trocar a senha.'
              :'Acesso criado e vínculos salvos. O líder já pode entrar no departamento autorizado com este login.');
            redirect('/users.php');
        }

        if($action==='role'){
            $membershipId=(string)($_POST['membership_id']??'');
            $role=(string)($_POST['church_role']??'');
            if(!in_array($role,$allowed,true))throw new RuntimeException('Você não pode conceder esse perfil.');

            $q=$pdo->prepare('SELECT cm.*,u.email FROM church_members cm INNER JOIN users u ON u.id=cm.user_id WHERE cm.id=? AND cm.church_id=? LIMIT 1');
            $q->execute([$membershipId,$cid]);$target=$q->fetch();
            if(!$target)throw new RuntimeException('Usuário não encontrado.');
            if(!empty($target['is_owner']))throw new RuntimeException('O perfil do proprietário não pode ser alterado aqui.');
            if((string)$target['user_id']===(string)$user['id']&&!$isMaster)throw new RuntimeException('Você não pode alterar o próprio perfil.');
            if(!$isMaster&&$currentRole!=='owner'&&!in_array((string)$target['church_role'],$allowed,true))throw new RuntimeException('Você não pode alterar esse perfil.');

            $oldMemberId=trim((string)($target['person_id']??''))?:null;
            $oldDeptIds=$leadAccess->oldDepartmentIds((string)$target['user_id']);
            $pdo->beginTransaction();
            try {
            $pdo->prepare('UPDATE church_members SET church_role=?,permissions=NULL WHERE id=? AND church_id=?')
                ->execute([$role,$membershipId,$cid]);
            save_portal_scope($pdo,$cid,(string)$target['user_id'],$role,$_POST);
            $memberId=trim((string)($_POST['person_id']??''))?:null;
            $newDeptIds=is_array($_POST['department_ids']??null)?$_POST['department_ids']:[];
            $leadAccess->sync($memberId,$role,$newDeptIds,$oldMemberId,$oldDeptIds);
            church_user_audit($pdo,(string)$user['id'],$cid,'church_user_role_updated',['target_user_id'=>$target['user_id'],'email'=>$target['email'],'church_role'=>$role]);
            $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Perfil atualizado.');
            redirect('/users.php');
        }

        if($action==='remove'){
            $membershipId=(string)($_POST['membership_id']??'');
            $q=$pdo->prepare('SELECT cm.*,u.email FROM church_members cm INNER JOIN users u ON u.id=cm.user_id WHERE cm.id=? AND cm.church_id=? LIMIT 1');
            $q->execute([$membershipId,$cid]);$target=$q->fetch();
            if(!$target)throw new RuntimeException('Usuário não encontrado.');
            if(!empty($target['is_owner']))throw new RuntimeException('O proprietário não pode ser removido.');
            if((string)$target['user_id']===(string)$user['id']&&!$isMaster)throw new RuntimeException('Você não pode remover o próprio acesso.');

            $targetRole=(string)($target['church_role']??'admin');
            if(!$isMaster&&$currentRole!=='owner'&&!in_array($targetRole,$allowed,true)){
                throw new RuntimeException('Somente o proprietário ou Master pode remover esse perfil.');
            }

            $pdo->prepare('DELETE FROM church_members WHERE id=? AND church_id=?')->execute([$membershipId,$cid]);
            church_user_audit($pdo,(string)$user['id'],$cid,'church_user_removed',['target_user_id'=>$target['user_id'],'email'=>$target['email'],'church_role'=>$targetRole]);
            flash('success','Acesso removido desta igreja.');
            redirect('/users.php');
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$q=$pdo->prepare('SELECT cm.id membership_id,cm.user_id,cm.is_owner,cm.church_role,cm.permissions,cm.person_id,cm.created_at,u.full_name,u.email,u.active
FROM church_members cm INNER JOIN users u ON u.id=cm.user_id
WHERE cm.church_id=? ORDER BY cm.is_owner DESC,u.full_name,u.email');
$q->execute([$cid]);$rows=$q->fetchAll();

$maxUsers=isset($church['max_users'])&&$church['max_users']!==null?(int)$church['max_users']:null;

View::header('Usuários e permissões',$auth,'users.php');
?>
<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<div class="grid two-col">
<section class="card card-pad">
  <div class="page-head"><div><h1 style="font-size:17px">Usuários internos de <?=e($church['name'])?></h1><p><?=count($rows)?> acesso(s)<?= $maxUsers!==null?' de '.$maxUsers.' permitidos':''?> · todos vinculados somente a esta igreja</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Usuário</th><th>Perfil</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($rows as $r):
      $role=!empty($r['is_owner'])?'owner':(string)($r['church_role']??'admin');
      $editable=empty($r['is_owner'])&&((string)$r['user_id']!==(string)$user['id']||$isMaster)&&($isMaster||$currentRole==='owner'||in_array($role,$allowed,true));
  ?><tr>
    <td><strong><?=e($r['full_name']?:$r['email'])?></strong><div class="muted" style="font-size:10px"><?=e($r['email'])?></div><?php if(!empty($r['person_id'])):?><div class="muted">Vinculado ao cadastro de membro</div><?php endif?></td>
    <td><span class="badge ok"><?=e($roleLabels[$role]??ucfirst($role))?></span><div class="muted" style="font-size:10px;max-width:260px"><?=e($roleDetailsForUser($role))?></div></td>
    <td><?=!empty($r['active'])?'Ativo':'Inativo'?></td>
    <td>
      <?php if($editable):?>
      <div class="actions">
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="role"><input type="hidden" name="membership_id" value="<?=e($r['membership_id'])?>">
          <select class="select" name="church_role" style="min-width:130px"><?php foreach($allowed as $v):?><option value="<?=e($v)?>" <?=$role===$v?'selected':''?>><?=e($roleLabels[$v])?></option><?php endforeach?></select>
          <details><summary>Vínculo pessoal e departamentos</summary><?php portal_scope_fields($portalPeople,$portalDepartments,$r['person_id'],$portalScopes[$r['user_id']]??[]);?></details>
          <button class="btn btn-light">Salvar acesso</button>
        </form>
        <form method="post" onsubmit="return confirm('Remover o acesso deste usuário à igreja?')"><?=csrf_field()?><input type="hidden" name="action" value="remove"><input type="hidden" name="membership_id" value="<?=e($r['membership_id'])?>"><button class="btn btn-danger">Remover</button></form>
      </div>
      <?php else:?><span class="muted">Protegido</span><?php endif?>
    </td>
  </tr><?php endforeach?>
  </tbody></table></div>
</section>

<section data-registry-form="users" id="definir-cargo-acesso" class="card card-pad">
  <h2 style="font-size:18px">Definir cargo e acesso de um membro</h2>
  <p class="muted">Selecione alguém da lista de <strong><?=e($church['name'])?></strong> e escolha um cargo principal. Não é necessário cadastrar novamente a pessoa nem criar outra igreja.</p>
  <?php if($isMaster):?><div class="alert alert-info">Você está em modo suporte. Para cadastrar outra igreja como novo cliente, use o <a href="/master.php">Painel Master → Nova igreja / cliente</a>.</div><?php endif?>
  <?php if($memberFromList!=='' && !$selectedPerson):?><div class="alert alert-error">O membro indicado no atalho não está ativo nesta igreja. Escolha uma pessoa da lista.</div><?php endif?>
  <form method="post" data-user-create-from-member="1"><?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid">
      <?php portal_scope_fields($portalPeople,$portalDepartments,$selectedPerson?(string)$selectedPerson['id']:null); ?>
      <div class="field full"><label>Cargo principal e nível de acesso *
        <select class="select" name="church_role" required>
          <option value="">Selecione o cargo…</option>
          <?php foreach($allowed as $v):?><option value="<?=e($v)?>" data-role-description="<?=e($roleDetailsForUser($v))?>" <?=$selectedRole===$v?'selected':''?>><?=e($roleLabels[$v])?></option><?php endforeach?>
        </select>
      </label><div class="alert alert-info" data-user-role-preview role="status" aria-live="polite" style="margin:7px 0">Selecione o cargo para ver quais módulos ele poderá acessar.</div></div>
      <div class="field full"><small class="muted" data-user-member-help>Escolha o membro acima: o nome e o e-mail serão reaproveitados. Se ele já tem login, a senha não será alterada.</small></div>
      <div class="field full"><label>Nome do membro * <input class="input" name="full_name" value="<?=e($selectedName)?>" required <?=$selectedPerson?'readonly':''?>></label></div>
      <div class="field full"><label>E-mail para entrar no sistema *
        <input class="input" type="email" name="email" value="<?=e($selectedEmail)?>" required <?=($selectedPerson&&$selectedEmail!=='')?'readonly':''?>>
      </label><small class="muted">Se o membro não possui e-mail, informe um para ativar o login. Não precisa preencher o nome outra vez.</small></div>
      <div class="field full"><label>Senha inicial <input class="input" type="password" name="password" minlength="8" autocomplete="new-password"></label><small class="muted" data-user-password-help>Somente para login novo; a senha de quem já possui acesso é mantida.</small></div>
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%;margin-top:14px">Salvar cargo e liberar acesso</button>
  </form>
</section>
</div>
<script src="/assets/users-member-autofill.js?v=3.7.71" defer></script>
<?php View::footer();?>
