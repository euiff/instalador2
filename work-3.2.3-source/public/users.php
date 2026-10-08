<?php
require __DIR__.'/bootstrap.php';
$user=$auth->requireAbility('manage_users');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$currentRole=$auth->churchRole();
$isMaster=$auth->hasRole('super_admin',(string)$user['id']);
$error=flash('error');$success=flash('success');

$roleLabels=[
    'owner'=>'Proprietário',
    'admin'=>'Administrador',
    'pastor'=>'Pastor',
    'secretary'=>'Secretaria',
    'treasurer'=>'Tesouraria',
];

function allowed_church_roles(string $currentRole,bool $isMaster): array {
    if($isMaster||$currentRole==='owner')return ['admin','pastor','secretary','treasurer'];
    return ['secretary','treasurer'];
}
function church_user_audit(PDO $pdo,string $userId,string $churchId,string $action,array $details=[]): void {
    try{
        $q=$pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)');
        $q->execute([app_uuid(),$userId,$churchId,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }catch(Throwable){}
}

$allowed=allowed_church_roles($currentRole,$isMaster);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='create'){
            $name=trim((string)($_POST['full_name']??''));
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $password=(string)($_POST['password']??'');
            $role=(string)($_POST['church_role']??'secretary');

            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe nome e e-mail válido.');
            if(!in_array($role,$allowed,true))throw new RuntimeException('Você não pode conceder esse perfil.');

            $countQ=$pdo->prepare('SELECT COUNT(*) FROM church_members WHERE church_id=?');
            $countQ->execute([$cid]);$currentUsers=(int)$countQ->fetchColumn();
            $maxUsers=isset($church['max_users'])&&$church['max_users']!==null?(int)$church['max_users']:null;
            if($maxUsers!==null&&$currentUsers>=$maxUsers)throw new RuntimeException('Esta igreja atingiu o limite de usuários do plano.');

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id,full_name FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$existing=$q->fetch();
                if($existing){
                    $targetUserId=(string)$existing['id'];
                }else{
                    if(strlen($password)<8)throw new RuntimeException('Para novo usuário, informe senha inicial com pelo menos 8 caracteres.');
                    $targetUserId=app_uuid();
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')
                        ->execute([$targetUserId,$email,password_hash($password,PASSWORD_DEFAULT),$name]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')
                        ->execute([app_uuid(),$targetUserId,$name]);
                }

                $check=$pdo->prepare('SELECT 1 FROM church_members WHERE church_id=? AND user_id=?');
                $check->execute([$cid,$targetUserId]);
                if($check->fetchColumn())throw new RuntimeException('Este usuário já possui acesso a esta igreja.');

                $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role,permissions) VALUES(?,?,?,0,?,NULL)')
                    ->execute([app_uuid(),$cid,$targetUserId,$role]);

                church_user_audit($pdo,(string)$user['id'],$cid,'church_user_created',['target_user_id'=>$targetUserId,'email'=>$email,'church_role'=>$role]);
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            flash('success','Acesso criado com sucesso.');
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

            $pdo->prepare('UPDATE church_members SET church_role=?,permissions=NULL WHERE id=? AND church_id=?')
                ->execute([$role,$membershipId,$cid]);
            church_user_audit($pdo,(string)$user['id'],$cid,'church_user_role_updated',['target_user_id'=>$target['user_id'],'email'=>$target['email'],'church_role'=>$role]);
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
            if(!$isMaster&&$currentRole!=='owner'&&!in_array($targetRole,['secretary','treasurer'],true)){
                throw new RuntimeException('Somente o proprietário ou Master pode remover esse perfil.');
            }

            $pdo->prepare('DELETE FROM church_members WHERE id=? AND church_id=?')->execute([$membershipId,$cid]);
            church_user_audit($pdo,(string)$user['id'],$cid,'church_user_removed',['target_user_id'=>$target['user_id'],'email'=>$target['email'],'church_role'=>$targetRole]);
            flash('success','Acesso removido desta igreja.');
            redirect('/users.php');
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$q=$pdo->prepare('SELECT cm.id membership_id,cm.user_id,cm.is_owner,cm.church_role,cm.permissions,cm.created_at,u.full_name,u.email,u.active
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
  <div class="page-head"><div><h1 style="font-size:17px">Usuários da igreja</h1><p><?=count($rows)?> acesso(s)<?= $maxUsers!==null?' de '.$maxUsers.' permitidos':''?></p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Usuário</th><th>Perfil</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($rows as $r):
      $role=!empty($r['is_owner'])?'owner':(string)($r['church_role']??'admin');
      $editable=empty($r['is_owner'])&&((string)$r['user_id']!==(string)$user['id']||$isMaster);
  ?><tr>
    <td><strong><?=e($r['full_name']?:$r['email'])?></strong><div class="muted" style="font-size:10px"><?=e($r['email'])?></div></td>
    <td><span class="badge ok"><?=e($roleLabels[$role]??ucfirst($role))?></span></td>
    <td><?=!empty($r['active'])?'Ativo':'Inativo'?></td>
    <td>
      <?php if($editable):?>
      <div class="actions">
        <form method="post" style="display:flex;gap:6px"><?=csrf_field()?><input type="hidden" name="action" value="role"><input type="hidden" name="membership_id" value="<?=e($r['membership_id'])?>">
          <select class="select" name="church_role" style="min-width:130px"><?php foreach($allowed as $v):?><option value="<?=e($v)?>" <?=$role===$v?'selected':''?>><?=e($roleLabels[$v])?></option><?php endforeach?></select>
          <button class="btn btn-light">Salvar</button>
        </form>
        <form method="post" onsubmit="return confirm('Remover o acesso deste usuário à igreja?')"><?=csrf_field()?><input type="hidden" name="action" value="remove"><input type="hidden" name="membership_id" value="<?=e($r['membership_id'])?>"><button class="btn btn-danger">Remover</button></form>
      </div>
      <?php else:?><span class="muted">Protegido</span><?php endif?>
    </td>
  </tr><?php endforeach?>
  </tbody></table></div>
</section>

<section class="card card-pad">
  <h2 style="font-size:17px">Novo acesso</h2>
  <p class="muted">Secretaria e Tesouraria recebem apenas os módulos da própria função.</p>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid">
      <div class="field full"><label>Nome *</label><input class="input" name="full_name" required></div>
      <div class="field full"><label>E-mail *</label><input class="input" type="email" name="email" required></div>
      <div class="field full"><label>Perfil *</label><select class="select" name="church_role"><?php foreach($allowed as $v):?><option value="<?=e($v)?>"><?=e($roleLabels[$v])?></option><?php endforeach?></select></div>
      <div class="field full"><label>Senha inicial</label><input class="input" type="password" name="password" minlength="8"><small class="muted">Obrigatória somente quando o e-mail ainda não existe na plataforma.</small></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:14px">Criar / vincular acesso</button>
  </form>
</section>
</div>
<?php View::footer();?>
