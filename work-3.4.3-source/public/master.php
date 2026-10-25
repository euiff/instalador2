<?php
require __DIR__.'/bootstrap.php';

$user=$auth->requireRole('super_admin');
$_SESSION['_csrf'] ??= bin2hex(random_bytes(32));

$error=flash('error');
$success=flash('success');

function master_audit(PDO $pdo,string $userId,?string $churchId,string $action,array $details=[]): void
{
    $q=$pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)');
    $q->execute([
        app_uuid(),$userId,$churchId,$action,
        json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
}

function unique_church_slug(PDO $pdo,string $name): string
{
    $base=slugify($name);
    if($base==='')$base='igreja';
    $slug=$base;$n=2;
    while(true){
        $q=$pdo->prepare('SELECT 1 FROM churches WHERE slug=? LIMIT 1');
        $q->execute([$slug]);
        if(!$q->fetchColumn())return $slug;
        $slug=$base.'-'.$n++;
    }
}

function master_user_is_super(PDO $pdo,string $userId): bool
{
    $q=$pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role='super_admin' LIMIT 1");
    $q->execute([$userId]);
    return (bool)$q->fetchColumn();
}

function master_detach_from_church(PDO $pdo,string $churchId): int
{
    $q=$pdo->prepare("DELETE cm FROM church_members cm
        INNER JOIN user_roles ur ON ur.user_id=cm.user_id AND ur.role='super_admin'
        WHERE cm.church_id=?");
    $q->execute([$churchId]);
    return $q->rowCount();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='create_church'){
            $name=trim((string)($_POST['church_name']??''));
            $pastorName=trim((string)($_POST['pastor_name']??''));
            $churchPhone=null_if_blank($_POST['church_phone']??null);
            $churchEmail=mb_strtolower(trim((string)($_POST['church_email']??'')));
            $churchEmail=$churchEmail!==''?$churchEmail:null;
            $address=null_if_blank($_POST['church_address']??null);

            $ownerName=trim((string)($_POST['owner_name']??''));
            $ownerEmail=mb_strtolower(trim((string)($_POST['owner_email']??'')));
            $password=(string)($_POST['owner_password']??'');
            $plan=(string)($_POST['plan']??'basic');

            if($name===''||$ownerName==='')throw new RuntimeException('Informe o nome da igreja e o responsável.');
            if(!filter_var($ownerEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail do responsável inválido.');
            if($churchEmail!==null&&!filter_var($churchEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail da igreja inválido.');
            if(!in_array($plan,['free','basic','pro','enterprise'],true))throw new RuntimeException('Plano inválido.');

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                $q->execute([$ownerEmail]);
                $ownerId=$q->fetchColumn();
                $newUser=false;

                if($ownerId&&master_user_is_super($pdo,(string)$ownerId)){
                    throw new RuntimeException('O usuário Master não pode ser proprietário de igreja. Use o e-mail do pastor/administrador cliente.');
                }

                if(!$ownerId){
                    if(strlen($password)<8)throw new RuntimeException('Informe senha inicial com pelo menos 8 caracteres para o novo responsável.');
                    $ownerId=app_uuid();$newUser=true;
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')
                        ->execute([$ownerId,$ownerEmail,password_hash($password,PASSWORD_DEFAULT),$ownerName]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')
                        ->execute([app_uuid(),$ownerId,$ownerName]);
                }

                $churchId=app_uuid();
                $slug=unique_church_slug($pdo,$name);
                $pdo->prepare("INSERT INTO churches(
                    id,name,slug,phone,email,address,pastor_name,plan,subscription_status,active,ai_enabled
                ) VALUES(?,?,?,?,?,?,?,?,'active',1,1)")
                    ->execute([
                        $churchId,$name,$slug,$churchPhone,$churchEmail,$address,
                        $pastorName!==''?$pastorName:$ownerName,$plan
                    ]);

                $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role,permissions) VALUES(?,?,?,?,?,NULL)')
                    ->execute([app_uuid(),$churchId,$ownerId,1,'owner']);

                master_audit($pdo,(string)$user['id'],$churchId,'church_created',[
                    'name'=>$name,'plan'=>$plan,'owner_email'=>$ownerEmail,'new_user'=>$newUser
                ]);

                $pdo->commit();
                flash('success','Igreja-cliente criada separadamente e responsável definido.');
                redirect('/master.php?church='.urlencode($churchId).'#edit');
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
        }

        if($action==='update_church'){
            $id=(string)($_POST['church_id']??'');
            $name=trim((string)($_POST['church_name']??''));
            $pastorName=null_if_blank($_POST['pastor_name']??null);
            $phone=null_if_blank($_POST['phone']??null);
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $email=$email!==''?$email:null;
            $address=null_if_blank($_POST['address']??null);
            $plan=(string)($_POST['plan']??'free');
            $status=(string)($_POST['subscription_status']??'active');

            if($id===''||$name==='')throw new RuntimeException('Informe o nome da igreja.');
            if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail da igreja inválido.');
            if(!in_array($plan,['free','basic','pro','enterprise'],true))throw new RuntimeException('Plano inválido.');
            if(!in_array($status,['trial','active','past_due','suspended','cancelled'],true))throw new RuntimeException('Status inválido.');

            $active=isset($_POST['active'])?1:0;
            $trial=null_if_blank($_POST['trial_ends_at']??null);
            $ends=null_if_blank($_POST['subscription_ends_at']??null);
            $maxUsers=($_POST['max_users']??'')!==''?max(1,(int)$_POST['max_users']):null;
            $monthlyFee=($_POST['monthly_fee']??'')!==''?max(0,(float)str_replace(',','.',(string)$_POST['monthly_fee'])):0;
            $notes=null_if_blank($_POST['master_notes']??null);

            $q=$pdo->prepare('UPDATE churches SET
                name=?,pastor_name=?,phone=?,email=?,address=?,
                plan=?,subscription_status=?,trial_ends_at=?,subscription_ends_at=?,
                max_users=?,monthly_fee=?,master_notes=?,active=?
                WHERE id=?');
            $q->execute([
                $name,$pastorName,$phone,$email,$address,
                $plan,$status,$trial,$ends,$maxUsers,$monthlyFee,$notes,$active,$id
            ]);

            $check=$pdo->prepare('SELECT 1 FROM churches WHERE id=?');
            $check->execute([$id]);
            if(!$check->fetchColumn())throw new RuntimeException('Igreja não encontrada.');

            master_audit($pdo,(string)$user['id'],$id,'church_updated',[
                'name'=>$name,'plan'=>$plan,'status'=>$status,'active'=>$active
            ]);
            flash('success','Dados da igreja atualizados.');
            redirect('/master.php?church='.urlencode($id).'#edit');
        }

        if($action==='set_owner'){
            $churchId=(string)($_POST['church_id']??'');
            $membershipId=(string)($_POST['membership_id']??'');
            if($churchId===''||$membershipId==='')throw new RuntimeException('Selecione o novo proprietário.');

            $q=$pdo->prepare('SELECT cm.*,u.email,u.full_name FROM church_members cm
                INNER JOIN users u ON u.id=cm.user_id
                WHERE cm.id=? AND cm.church_id=? LIMIT 1');
            $q->execute([$membershipId,$churchId]);
            $target=$q->fetch();
            if(!$target)throw new RuntimeException('Usuário não pertence a esta igreja.');
            if(master_user_is_super($pdo,(string)$target['user_id']))throw new RuntimeException('O Master não pode ser proprietário da igreja-cliente.');

            $pdo->beginTransaction();
            try{
                $pdo->prepare("UPDATE church_members
                    SET is_owner=0,church_role=CASE WHEN church_role='owner' THEN 'admin' ELSE church_role END
                    WHERE church_id=?")->execute([$churchId]);

                $pdo->prepare("UPDATE church_members
                    SET is_owner=1,church_role='owner',permissions=NULL
                    WHERE id=? AND church_id=?")->execute([$membershipId,$churchId]);

                $detached=master_detach_from_church($pdo,$churchId);

                master_audit($pdo,(string)$user['id'],$churchId,'church_owner_transferred',[
                    'new_owner_user_id'=>$target['user_id'],
                    'new_owner_email'=>$target['email'],
                    'master_memberships_removed'=>$detached,
                ]);
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            flash('success','Proprietário definido e Master desvinculado da equipe interna desta igreja.');
            redirect('/master.php?church='.urlencode($churchId).'#owner');
        }

        if($action==='create_owner'){
            $churchId=(string)($_POST['church_id']??'');
            $name=trim((string)($_POST['owner_name']??''));
            $email=mb_strtolower(trim((string)($_POST['owner_email']??'')));
            $password=(string)($_POST['owner_password']??'');

            if($churchId===''||$name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){
                throw new RuntimeException('Informe nome e e-mail válidos do responsável.');
            }

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                $q->execute([$email]);
                $ownerId=$q->fetchColumn();

                if($ownerId&&master_user_is_super($pdo,(string)$ownerId)){
                    throw new RuntimeException('O e-mail do Master não pode ser usado como proprietário.');
                }

                if(!$ownerId){
                    if(strlen($password)<8)throw new RuntimeException('Informe senha inicial com pelo menos 8 caracteres.');
                    $ownerId=app_uuid();
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')
                        ->execute([$ownerId,$email,password_hash($password,PASSWORD_DEFAULT),$name]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')
                        ->execute([app_uuid(),$ownerId,$name]);
                }

                $membership=$pdo->prepare('SELECT id FROM church_members WHERE church_id=? AND user_id=? LIMIT 1');
                $membership->execute([$churchId,$ownerId]);
                $membershipId=$membership->fetchColumn();

                if(!$membershipId){
                    $membershipId=app_uuid();
                    $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role,permissions) VALUES(?,?,?,0,\'admin\',NULL)')
                        ->execute([$membershipId,$churchId,$ownerId]);
                }

                $pdo->prepare("UPDATE church_members
                    SET is_owner=0,church_role=CASE WHEN church_role='owner' THEN 'admin' ELSE church_role END
                    WHERE church_id=?")->execute([$churchId]);
                $pdo->prepare("UPDATE church_members SET is_owner=1,church_role='owner',permissions=NULL WHERE id=?")
                    ->execute([$membershipId]);

                $detached=master_detach_from_church($pdo,$churchId);

                master_audit($pdo,(string)$user['id'],$churchId,'church_owner_created_or_linked',[
                    'owner_email'=>$email,'master_memberships_removed'=>$detached
                ]);
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            flash('success','Responsável definido. A igreja agora está desvinculada do Master.');
            redirect('/master.php?church='.urlencode($churchId).'#owner');
        }

        if($action==='detach_master'){
            $churchId=(string)($_POST['church_id']??'');
            if($churchId==='')throw new RuntimeException('Igreja inválida.');

            $owner=$pdo->prepare("SELECT cm.user_id FROM church_members cm
                WHERE cm.church_id=? AND cm.is_owner=1
                  AND NOT EXISTS(
                    SELECT 1 FROM user_roles ur
                    WHERE ur.user_id=cm.user_id AND ur.role='super_admin'
                  )
                LIMIT 1");
            $owner->execute([$churchId]);
            if(!$owner->fetchColumn()){
                throw new RuntimeException('Antes de desvincular o Master, defina um pastor/administrador cliente como proprietário da igreja.');
            }

            $removed=master_detach_from_church($pdo,$churchId);
            master_audit($pdo,(string)$user['id'],$churchId,'master_detached_from_church',['removed'=>$removed]);
            flash('success',$removed>0?'Master desvinculado desta igreja. Nenhum dado da igreja foi apagado.':'Esta igreja já estava desvinculada do Master.');
            redirect('/master.php?church='.urlencode($churchId).'#owner');
        }

    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$rows=$pdo->query("SELECT c.*,
    (SELECT COUNT(*) FROM church_members cm WHERE cm.church_id=c.id) user_count,
    (SELECT COUNT(*) FROM members m WHERE m.church_id=c.id AND m.active=1) member_count,
    (SELECT u.email FROM church_members cm2
       INNER JOIN users u ON u.id=cm2.user_id
       WHERE cm2.church_id=c.id AND cm2.is_owner=1
       ORDER BY cm2.created_at LIMIT 1) owner_email,
    (SELECT u.full_name FROM church_members cm2
       INNER JOIN users u ON u.id=cm2.user_id
       WHERE cm2.church_id=c.id AND cm2.is_owner=1
       ORDER BY cm2.created_at LIMIT 1) owner_name,
    (SELECT COUNT(*) FROM church_members cm3
       INNER JOIN user_roles ur3 ON ur3.user_id=cm3.user_id AND ur3.role='super_admin'
       WHERE cm3.church_id=c.id) master_links
    FROM churches c
    ORDER BY c.active DESC,c.created_at DESC")->fetchAll();

$totalChurches=count($rows);
$activeChurches=count(array_filter($rows,fn($r)=>!empty($r['active'])));
$totalUsers=array_sum(array_map(fn($r)=>(int)$r['user_count'],$rows));
$totalMembers=array_sum(array_map(fn($r)=>(int)$r['member_count'],$rows));

$selected=null;
$churchUsers=[];
if(!empty($_GET['church'])){
    $q=$pdo->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM church_members cm WHERE cm.church_id=c.id) user_count,
        (SELECT COUNT(*) FROM members m WHERE m.church_id=c.id AND m.active=1) member_count,
        (SELECT COUNT(*) FROM church_members cm3
           INNER JOIN user_roles ur3 ON ur3.user_id=cm3.user_id AND ur3.role='super_admin'
           WHERE cm3.church_id=c.id) master_links
        FROM churches c WHERE c.id=? LIMIT 1");
    $q->execute([(string)$_GET['church']]);
    $selected=$q->fetch()?:null;

    if($selected){
        $uq=$pdo->prepare("SELECT cm.id membership_id,cm.user_id,cm.is_owner,cm.church_role,u.full_name,u.email,u.active,
          EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role='super_admin') is_master
          FROM church_members cm
          INNER JOIN users u ON u.id=cm.user_id
          WHERE cm.church_id=?
          ORDER BY cm.is_owner DESC,u.full_name,u.email");
        $uq->execute([$selected['id']]);
        $churchUsers=$uq->fetchAll();
    }
}

$audit=$pdo->query("SELECT a.*,c.name church_name,u.email user_email
    FROM platform_audit_logs a
    LEFT JOIN churches c ON c.id=a.church_id
    LEFT JOIN users u ON u.id=a.user_id
    ORDER BY a.created_at DESC LIMIT 40")->fetchAll();

View::header('Painel Master',$auth,'master.php');
?>
<style>
.master-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:18px}
.master-hero h1{margin:0 0 4px;font-size:22px}.master-hero p{margin:0;color:var(--muted)}
.master-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.master-stat{padding:16px 18px}.master-stat small{display:block;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}.master-stat strong{display:block;font-size:25px;margin-top:6px}
.master-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.master-search{max-width:360px;flex:1}
.master-table .client-name{font-weight:800}.master-table .client-sub{font-size:10px;color:var(--muted);margin-top:3px}
.master-actions{display:flex;gap:7px;flex-wrap:wrap}
.master-link-warning{font-size:10px;color:#9a6700;background:#fff7db;padding:4px 7px;border-radius:999px;display:inline-block;margin-top:4px}
.master-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.master-edit-grid .full{grid-column:1/-1}
.master-section{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}
.master-danger{border:1px solid #f1c7c7;background:#fff8f8;border-radius:14px;padding:15px}
.master-owner-list{display:grid;gap:8px;margin-bottom:12px}.master-owner-user{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:10px}
.fab-new-church{position:fixed;right:28px;bottom:28px;z-index:60;border-radius:999px;box-shadow:0 10px 26px rgba(21,74,139,.25)}
@media(max-width:900px){.master-stats{grid-template-columns:1fr 1fr}.master-edit-grid{grid-template-columns:1fr}.master-edit-grid .full{grid-column:auto}.master-hero{align-items:flex-start;flex-direction:column}}
</style>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<div class="master-hero">
  <div>
    <h1>Plataforma Master</h1>
    <p>Gerencie igrejas-clientes, proprietários, planos e suporte. O Master administra a plataforma e não é membro das igrejas.</p>
  </div>
  <button class="btn btn-primary" type="button" data-open-dialog="master-new-church">+ Nova igreja</button>
</div>

<div class="master-stats">
  <section class="card master-stat"><small>Igrejas cadastradas</small><strong><?=$totalChurches?></strong></section>
  <section class="card master-stat"><small>Igrejas ativas</small><strong><?=$activeChurches?></strong></section>
  <section class="card master-stat"><small>Usuários dos clientes</small><strong><?=$totalUsers?></strong></section>
  <section class="card master-stat"><small>Membros cadastrados</small><strong><?=$totalMembers?></strong></section>
</div>

<section class="card card-pad">
  <div class="master-toolbar">
    <div>
      <h2 style="margin:0;font-size:17px">Igrejas-clientes</h2>
      <p class="muted" style="margin:4px 0 0">Cada linha representa um ambiente separado.</p>
    </div>
    <input class="input master-search" id="master-church-search" type="search" placeholder="Buscar igreja, pastor ou e-mail">
  </div>

  <div class="table-wrap">
    <table class="table master-table">
      <thead><tr><th>Igreja</th><th>Proprietário</th><th>Plano</th><th>Usuários</th><th>Membros</th><th>Status</th><th>Ações</th></tr></thead>
      <tbody id="master-church-rows">
      <?php foreach($rows as $r):?>
        <tr data-search="<?=e(mb_strtolower(($r['name']??'').' '.($r['owner_name']??'').' '.($r['owner_email']??'')))?>">
          <td>
            <div class="client-name"><?=e($r['name'])?></div>
            <div class="client-sub"><?=e($r['slug'])?></div>
            <?php if((int)$r['master_links']>0):?><span class="master-link-warning">Vínculo legado com Master</span><?php endif?>
          </td>
          <td><strong><?=e($r['owner_name']?:'Sem proprietário')?></strong><div class="client-sub"><?=e($r['owner_email']?:'—')?></div></td>
          <td><?=e(mb_strtoupper((string)$r['plan']))?></td>
          <td><?=e((string)$r['user_count'])?></td>
          <td><?=e((string)$r['member_count'])?></td>
          <td><span class="badge <?=$r['active']?'ok':'off'?>"><?=e($r['subscription_status']??($r['active']?'active':'suspended'))?></span></td>
          <td>
            <div class="master-actions">
              <a class="btn btn-light" href="/master.php?church=<?=e($r['id'])?>#edit">Editar</a>
              <form method="post" action="/switch-church.php">
                <?=csrf_field()?>
                <input type="hidden" name="church_id" value="<?=e($r['id'])?>">
                <input type="hidden" name="back" value="/dashboard.php">
                <button class="btn btn-light" type="submit">Suporte</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach?>
      <?php if(!$rows):?><tr><td colspan="7" class="muted">Nenhuma igreja-cliente cadastrada.</td></tr><?php endif?>
      </tbody>
    </table>
  </div>
</section>

<?php if($selected):?>
<section class="card card-pad" id="edit" style="margin-top:18px">
  <div class="page-head">
    <div>
      <h2 style="font-size:18px;margin-bottom:4px">Editar <?=e($selected['name'])?></h2>
      <p>Dados da igreja, assinatura e limites do cliente.</p>
    </div>
    <a class="btn btn-light" href="/master.php">Fechar edição</a>
  </div>

  <form method="post">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="update_church">
    <input type="hidden" name="church_id" value="<?=e($selected['id'])?>">

    <div class="master-edit-grid">
      <div class="field"><label>Nome da igreja *</label><input class="input" name="church_name" required value="<?=e($selected['name'])?>"></div>
      <div class="field"><label>Pastor / responsável</label><input class="input" name="pastor_name" value="<?=e($selected['pastor_name']??'')?>"></div>
      <div class="field"><label>Telefone</label><input class="input" name="phone" value="<?=e($selected['phone']??'')?>"></div>
      <div class="field"><label>E-mail da igreja</label><input class="input" type="email" name="email" value="<?=e($selected['email']??'')?>"></div>
      <div class="field full"><label>Endereço</label><input class="input" name="address" value="<?=e($selected['address']??'')?>"></div>

      <div class="field"><label>Plano</label><select class="select" name="plan"><?php foreach(['free'=>'Gratuito','basic'=>'Básico','pro'=>'Pro','enterprise'=>'Enterprise'] as $v=>$l):?><option value="<?=$v?>" <?=$selected['plan']===$v?'selected':''?>><?=$l?></option><?php endforeach?></select></div>
      <div class="field"><label>Status da assinatura</label><select class="select" name="subscription_status"><?php foreach(['trial'=>'Teste','active'=>'Ativa','past_due'=>'Pagamento pendente','suspended'=>'Suspensa','cancelled'=>'Cancelada'] as $v=>$l):?><option value="<?=$v?>" <?=($selected['subscription_status']??'active')===$v?'selected':''?>><?=$l?></option><?php endforeach?></select></div>

      <div class="field"><label>Fim do teste</label><input class="input" type="datetime-local" name="trial_ends_at" value="<?=e(!empty($selected['trial_ends_at'])?date('Y-m-d\TH:i',strtotime($selected['trial_ends_at'])):'')?>"></div>
      <div class="field"><label>Vencimento / fim</label><input class="input" type="datetime-local" name="subscription_ends_at" value="<?=e(!empty($selected['subscription_ends_at'])?date('Y-m-d\TH:i',strtotime($selected['subscription_ends_at'])):'')?>"></div>

      <div class="field"><label>Máximo de usuários</label><input class="input" type="number" min="1" name="max_users" value="<?=e((string)($selected['max_users']??''))?>"></div>
      <div class="field"><label>Mensalidade</label><input class="input" type="number" min="0" step="0.01" name="monthly_fee" value="<?=e((string)($selected['monthly_fee']??'0'))?>"></div>

      <div class="field full"><label>Observações internas do Master</label><textarea class="textarea" name="master_notes"><?=e($selected['master_notes']??'')?></textarea></div>
      <div class="field full"><label><input type="checkbox" name="active" <?=$selected['active']?'checked':''?>> Igreja com acesso liberado</label></div>
    </div>

    <div class="actions" style="margin-top:14px">
      <button class="btn btn-primary" type="submit">Salvar alterações</button>
      <form></form>
    </div>
  </form>

  <div class="master-section" id="owner">
    <h3 style="margin:0 0 5px;font-size:16px">Proprietário e acessos</h3>
    <p class="muted">O proprietário é o pastor/administrador cliente. O Master nunca deve ser proprietário desta igreja.</p>

    <div class="master-owner-list">
      <?php foreach($churchUsers as $cu):?>
        <div class="master-owner-user">
          <div>
            <strong><?=e($cu['full_name']?:$cu['email'])?></strong>
            <div class="muted" style="font-size:10px"><?=e($cu['email'])?> · <?=e(!empty($cu['is_owner'])?'Proprietário':($cu['church_role']?:'admin'))?></div>
          </div>
          <div>
            <?php if(!empty($cu['is_master'])):?><span class="badge off">Master · vínculo legado</span>
            <?php elseif(!empty($cu['is_owner'])):?><span class="badge ok">Proprietário atual</span>
            <?php endif?>
          </div>
        </div>
      <?php endforeach?>
    </div>

    <?php
      $nonMasterUsers=array_values(array_filter($churchUsers,fn($cu)=>empty($cu['is_master'])));
    ?>
    <?php if($nonMasterUsers):?>
      <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="set_owner">
        <input type="hidden" name="church_id" value="<?=e($selected['id'])?>">
        <div class="field" style="flex:1;min-width:260px">
          <label>Definir usuário existente como proprietário</label>
          <select class="select" name="membership_id" required>
            <option value="">Selecione...</option>
            <?php foreach($nonMasterUsers as $cu):?>
              <option value="<?=e($cu['membership_id'])?>" <?=!empty($cu['is_owner'])?'selected':''?>><?=e($cu['full_name']?:$cu['email'])?> · <?=e($cu['email'])?></option>
            <?php endforeach?>
          </select>
        </div>
        <button class="btn btn-primary" type="submit">Definir proprietário</button>
      </form>
    <?php endif?>

    <details class="card" style="padding:14px;margin-top:10px">
      <summary style="cursor:pointer;font-weight:800">Criar ou vincular outro pastor/administrador como proprietário</summary>
      <form method="post" style="margin-top:14px">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="create_owner">
        <input type="hidden" name="church_id" value="<?=e($selected['id'])?>">
        <div class="master-edit-grid">
          <div class="field"><label>Nome *</label><input class="input" name="owner_name" required></div>
          <div class="field"><label>E-mail *</label><input class="input" type="email" name="owner_email" required></div>
          <div class="field full"><label>Senha inicial</label><input class="input" type="password" name="owner_password" minlength="8"><small class="muted">Obrigatória apenas se o e-mail ainda não existir.</small></div>
        </div>
        <button class="btn btn-primary" style="margin-top:12px">Definir novo proprietário</button>
      </form>
    </details>
  </div>

  <div class="master-section master-danger">
    <h3 style="margin:0 0 5px;font-size:16px">Separação do Master</h3>
    <?php if((int)$selected['master_links']>0):?>
      <p style="margin:0 0 12px">Esta igreja ainda possui <strong><?=e((string)$selected['master_links'])?> vínculo(s) legado(s)</strong> com usuário Master. Desvincular remove somente o acesso interno do Master; membros, financeiro, WhatsApp, documentos e demais dados da igreja permanecem intactos.</p>
      <form method="post" onsubmit="return confirm('Desvincular o Master desta igreja? Nenhum dado da igreja será apagado.')">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="detach_master">
        <input type="hidden" name="church_id" value="<?=e($selected['id'])?>">
        <button class="btn btn-danger" type="submit">Desvincular Master desta igreja</button>
      </form>
    <?php else:?>
      <p style="margin:0"><strong>Separação correta.</strong> O Master não faz parte da equipe interna desta igreja.</p>
    <?php endif?>
  </div>

  <div class="master-section">
    <form method="post" action="/switch-church.php">
      <?=csrf_field()?>
      <input type="hidden" name="church_id" value="<?=e($selected['id'])?>">
      <input type="hidden" name="back" value="/dashboard.php">
      <button class="btn btn-light" style="width:100%" type="submit">Abrir painel desta igreja em modo suporte</button>
    </form>
  </div>
</section>
<?php endif?>

<section class="card card-pad" style="margin-top:18px">
  <div class="page-head"><div><h2 style="font-size:17px">Auditoria Master</h2><p>Últimas ações administrativas da plataforma.</p></div></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Data</th><th>Ação</th><th>Igreja</th><th>Usuário</th></tr></thead>
      <tbody>
      <?php foreach($audit as $a):?>
        <tr><td><?=e(date('d/m/Y H:i',strtotime($a['created_at'])))?></td><td><?=e($a['action'])?></td><td><?=e($a['church_name']?:'—')?></td><td><?=e($a['user_email']?:'—')?></td></tr>
      <?php endforeach?>
      </tbody>
    </table>
  </div>
</section>

<button class="btn btn-primary fab-new-church" type="button" data-open-dialog="master-new-church">+ Nova igreja</button>

<dialog class="app-dialog" id="master-new-church" aria-labelledby="master-new-church-title">
  <div class="dialog-shell">
    <header class="dialog-heading">
      <div>
        <span class="eyebrow">Novo cliente</span>
        <h2 id="master-new-church-title">Cadastrar nova igreja</h2>
        <p>Cria um ambiente separado e já define o pastor/administrador proprietário.</p>
      </div>
      <button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button>
    </header>
    <div class="dialog-body">
      <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="create_church">
        <div class="master-edit-grid">
          <div class="field full"><label>Nome da igreja *</label><input class="input" name="church_name" required></div>
          <div class="field"><label>Pastor da igreja</label><input class="input" name="pastor_name"></div>
          <div class="field"><label>Telefone da igreja</label><input class="input" name="church_phone"></div>
          <div class="field full"><label>E-mail da igreja</label><input class="input" type="email" name="church_email"></div>
          <div class="field full"><label>Endereço</label><input class="input" name="church_address"></div>

          <div class="field full" style="margin-top:6px"><strong>Acesso do proprietário</strong><small class="muted" style="display:block">Este usuário será o dono da igreja-cliente. Nunca use o e-mail do Master.</small></div>
          <div class="field"><label>Nome do responsável *</label><input class="input" name="owner_name" required></div>
          <div class="field"><label>E-mail de login *</label><input class="input" type="email" name="owner_email" required></div>
          <div class="field"><label>Senha inicial</label><input class="input" type="password" name="owner_password" minlength="8"></div>
          <div class="field"><label>Plano</label><select class="select" name="plan"><option value="basic">Básico</option><option value="pro">Pro</option><option value="enterprise">Enterprise</option><option value="free">Gratuito</option></select></div>
        </div>
        <button class="btn btn-primary" style="width:100%;margin-top:16px">Criar igreja-cliente</button>
      </form>
    </div>
  </div>
</dialog>

<script>
(function(){
  const input=document.getElementById('master-church-search');
  const rows=[...document.querySelectorAll('#master-church-rows tr[data-search]')];
  input?.addEventListener('input',function(){
    const q=this.value.toLowerCase().trim();
    rows.forEach(row=>row.style.display=!q||row.dataset.search.includes(q)?'':'none');
  });
})();
</script>
<?php View::footer(); ?>
