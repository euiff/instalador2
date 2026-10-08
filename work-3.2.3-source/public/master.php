<?php
require __DIR__.'/bootstrap.php';
$user=$auth->requireRole('super_admin');
$church=$auth->currentChurch();
$_SESSION['_csrf'] ??= bin2hex(random_bytes(32));

$error=flash('error');
$success=flash('success');

function master_audit(PDO $pdo,string $userId,?string $churchId,string $action,array $details=[]):void{
    $q=$pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)');
    $q->execute([app_uuid(),$userId,$churchId,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function unique_church_slug(PDO $pdo,string $name):string{
    $base=slugify($name);$slug=$base;$n=2;
    while(true){
        $q=$pdo->prepare('SELECT 1 FROM churches WHERE slug=? LIMIT 1');$q->execute([$slug]);
        if(!$q->fetchColumn()) return $slug;
        $slug=$base.'-'.$n++;
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='update_church'){
            $id=(string)($_POST['church_id']??'');
            $plan=(string)($_POST['plan']??'free');
            $status=(string)($_POST['subscription_status']??'active');
            if(!in_array($plan,['free','basic','pro','enterprise'],true)) throw new RuntimeException('Plano inválido.');
            if(!in_array($status,['trial','active','past_due','suspended','cancelled'],true)) throw new RuntimeException('Status inválido.');

            $active=isset($_POST['active'])?1:0;
            $trial=null_if_blank($_POST['trial_ends_at']??null);
            $ends=null_if_blank($_POST['subscription_ends_at']??null);
            $maxUsers=($_POST['max_users']??'')!==''?max(1,(int)$_POST['max_users']):null;
            $notes=null_if_blank($_POST['master_notes']??null);

            $q=$pdo->prepare('UPDATE churches SET plan=?,subscription_status=?,trial_ends_at=?,subscription_ends_at=?,max_users=?,master_notes=?,active=? WHERE id=?');
            $q->execute([$plan,$status,$trial,$ends,$maxUsers,$notes,$active,$id]);
            if($q->rowCount()<1){
                $check=$pdo->prepare('SELECT 1 FROM churches WHERE id=?');$check->execute([$id]);
                if(!$check->fetchColumn()) throw new RuntimeException('Igreja não encontrada.');
            }
            master_audit($pdo,(string)$user['id'],$id,'church_subscription_updated',['plan'=>$plan,'status'=>$status,'active'=>$active,'trial_ends_at'=>$trial,'subscription_ends_at'=>$ends,'max_users'=>$maxUsers]);
            flash('success','Acesso da igreja atualizado.');
            redirect('/master.php?church='.urlencode($id));
        }

        if($action==='create_church'){
            $name=trim((string)($_POST['church_name']??''));
            $ownerName=trim((string)($_POST['owner_name']??''));
            $email=mb_strtolower(trim((string)($_POST['owner_email']??'')));
            $password=(string)($_POST['owner_password']??'');
            $plan=(string)($_POST['plan']??'basic');

            if($name===''||$ownerName==='') throw new RuntimeException('Informe igreja e responsável.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail do responsável inválido.');
            if(!in_array($plan,['free','basic','pro','enterprise'],true)) throw new RuntimeException('Plano inválido.');

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$ownerId=$q->fetchColumn();
                $newUser=false;
                if(!$ownerId){
                    if(strlen($password)<8) throw new RuntimeException('Para um novo usuário, informe senha inicial com pelo menos 8 caracteres.');
                    $ownerId=app_uuid();$newUser=true;
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')->execute([$ownerId,$email,password_hash($password,PASSWORD_DEFAULT),$ownerName]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')->execute([app_uuid(),$ownerId,$ownerName]);
                }

                $churchId=app_uuid();$slug=unique_church_slug($pdo,$name);
                $pdo->prepare("INSERT INTO churches(id,name,slug,plan,subscription_status,active,ai_enabled) VALUES(?,?,?,?,'active',1,1)")->execute([$churchId,$name,$slug,$plan]);
                $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role) VALUES(?,?,?,?,?)')->execute([app_uuid(),$churchId,$ownerId,1,'owner']);
                $pdo->prepare("INSERT IGNORE INTO user_roles(id,user_id,role) VALUES(?,?,'admin')")->execute([app_uuid(),$ownerId]);

                master_audit($pdo,(string)$user['id'],$churchId,'church_created',['name'=>$name,'plan'=>$plan,'owner_email'=>$email,'new_user'=>$newUser]);
                $pdo->commit();
                flash('success','Igreja criada e acesso do responsável vinculado.');
                redirect('/master.php?church='.urlencode($churchId));
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$selected=null;
if(!empty($_GET['church'])){
    $q=$pdo->prepare('SELECT c.*,(SELECT COUNT(*) FROM church_members cm WHERE cm.church_id=c.id) user_count,(SELECT COUNT(*) FROM members m WHERE m.church_id=c.id AND m.active=1) member_count FROM churches c WHERE c.id=? LIMIT 1');
    $q->execute([(string)$_GET['church']]);$selected=$q->fetch()?:null;
}

$rows=$pdo->query("SELECT c.*,
 (SELECT COUNT(*) FROM church_members cm WHERE cm.church_id=c.id) user_count,
 (SELECT COUNT(*) FROM members m WHERE m.church_id=c.id AND m.active=1) member_count,
 (SELECT u.email FROM church_members cm2 INNER JOIN users u ON u.id=cm2.user_id WHERE cm2.church_id=c.id AND cm2.is_owner=1 ORDER BY cm2.created_at LIMIT 1) owner_email
 FROM churches c ORDER BY c.active DESC,c.created_at DESC")->fetchAll();

$audit=$pdo->query("SELECT a.*,c.name church_name,u.email user_email FROM platform_audit_logs a LEFT JOIN churches c ON c.id=a.church_id LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 30")->fetchAll();

View::header('Painel Master',$auth,'master.php');
?>
<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<div class="grid two-col">
<section class="card card-pad">
  <div class="page-head"><div><h1 style="font-size:18px">Plataforma Master</h1><p>Gerencie igrejas, planos e acessos.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Igreja</th><th>Plano</th><th>Responsável</th><th>Usuários</th><th>Membros</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($rows as $r):?><tr>
    <td><strong><?=e($r['name'])?></strong><div class="muted" style="font-size:10px"><?=e($r['slug'])?></div></td>
    <td><?=e(mb_strtoupper($r['plan']))?></td>
    <td><?=e($r['owner_email']?:'—')?></td>
    <td><?=e((string)$r['user_count'])?></td>
    <td><?=e((string)$r['member_count'])?></td>
    <td><span class="badge <?=$r['active']?'ok':'off'?>"><?=e($r['subscription_status']??($r['active']?'active':'suspended'))?></span></td>
    <td><a class="btn btn-light" style="padding:7px 9px" href="?church=<?=e($r['id'])?>#edit">Gerenciar</a></td>
  </tr><?php endforeach?>
  </tbody></table></div>
</section>

<section class="card card-pad">
  <div class="page-head"><div><h2 style="font-size:17px">Nova igreja / cliente</h2><p>Cria a igreja e vincula o responsável.</p></div></div>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create_church">
    <div class="form-grid">
      <div class="field full"><label>Nome da igreja *</label><input class="input" name="church_name" required></div>
      <div class="field full"><label>Responsável *</label><input class="input" name="owner_name" required></div>
      <div class="field full"><label>E-mail do responsável *</label><input class="input" type="email" name="owner_email" required></div>
      <div class="field full"><label>Senha inicial</label><input class="input" type="password" name="owner_password" minlength="8"><small class="muted">Necessária somente se o e-mail ainda não existir no sistema.</small></div>
      <div class="field full"><label>Plano</label><select class="select" name="plan"><option value="basic">Básico</option><option value="pro">Pro</option><option value="enterprise">Enterprise</option><option value="free">Gratuito</option></select></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:14px">Criar igreja e acesso</button>
  </form>
</section>
</div>

<?php if($selected):?>
<section class="card card-pad" id="edit" style="margin-top:18px">
  <div class="page-head"><div><h2 style="font-size:17px">Gerenciar <?=e($selected['name'])?></h2><p>Plano, licença e disponibilidade da conta.</p></div></div>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="update_church"><input type="hidden" name="church_id" value="<?=e($selected['id'])?>">
    <div class="form-grid">
      <div class="field"><label>Plano</label><select class="select" name="plan"><?php foreach(['free'=>'Gratuito','basic'=>'Básico','pro'=>'Pro','enterprise'=>'Enterprise'] as $v=>$l):?><option value="<?=$v?>" <?=$selected['plan']===$v?'selected':''?>><?=$l?></option><?php endforeach?></select></div>
      <div class="field"><label>Status da assinatura</label><select class="select" name="subscription_status"><?php foreach(['trial'=>'Teste','active'=>'Ativa','past_due'=>'Pagamento pendente','suspended'=>'Suspensa','cancelled'=>'Cancelada'] as $v=>$l):?><option value="<?=$v?>" <?=($selected['subscription_status']??'active')===$v?'selected':''?>><?=$l?></option><?php endforeach?></select></div>
      <div class="field"><label>Fim do teste</label><input class="input" type="datetime-local" name="trial_ends_at" value="<?=e(!empty($selected['trial_ends_at'])?date('Y-m-d\TH:i',strtotime($selected['trial_ends_at'])):'')?>"></div>
      <div class="field"><label>Vencimento / fim da assinatura</label><input class="input" type="datetime-local" name="subscription_ends_at" value="<?=e(!empty($selected['subscription_ends_at'])?date('Y-m-d\TH:i',strtotime($selected['subscription_ends_at'])):'')?>"></div>
      <div class="field"><label>Máximo de usuários</label><input class="input" type="number" min="1" name="max_users" value="<?=e((string)($selected['max_users']??''))?>"></div>
      <div class="field" style="display:flex;align-items:end"><label style="margin-bottom:12px"><input type="checkbox" name="active" <?=$selected['active']?'checked':''?>> Acesso liberado</label></div>
      <div class="field full"><label>Observações do Master</label><textarea class="textarea" name="master_notes"><?=e($selected['master_notes']??'')?></textarea></div>
    </div>
    <button class="btn btn-primary" style="margin-top:14px">Salvar acesso</button>
  </form>
  <form method="post" action="/switch-church.php" style="margin-top:10px">
    <?=csrf_field()?>
    <input type="hidden" name="church_id" value="<?=e($selected['id'])?>">
    <input type="hidden" name="back" value="/dashboard.php">
    <button class="btn btn-light" style="width:100%">Abrir painel desta igreja</button>
  </form>
</section>
<?php endif?>

<section class="card card-pad" style="margin-top:18px">
  <h2>Auditoria Master</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Ação</th><th>Igreja</th><th>Usuário</th></tr></thead><tbody>
  <?php foreach($audit as $a):?><tr><td><?=e(date('d/m/Y H:i',strtotime($a['created_at'])))?></td><td><?=e($a['action'])?></td><td><?=e($a['church_name']?:'—')?></td><td><?=e($a['user_email']?:'—')?></td></tr><?php endforeach?>
  </tbody></table></div>
</section>
<?php View::footer();?>