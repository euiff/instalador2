<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/NotificationService.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/EngagementNotificationService.php';
$auth->requireAbility('secretary');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=$church['id'];
$_SESSION['csrf']??=bin2hex(random_bytes(24));
$error=flash('error');$success=flash('success');
$uploader=new UploadService(__DIR__);
$engagementNotifier=new EngagementNotificationService($pdo,__DIR__);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada.');
        $id=(string)($_POST['id']??'');$action=(string)($_POST['action']??'save');
        if($action==='toggle'){
            $q=$pdo->prepare('UPDATE service_types SET active=IF(active=1,0,1) WHERE id=? AND church_id=?');$q->execute([$id,$cid]);$success='Status atualizado.';
        }elseif($action==='resend'){
            $q=$pdo->prepare('SELECT * FROM service_types WHERE id=? AND church_id=? AND active=1 LIMIT 1');$q->execute([$id,$cid]);$service=$q->fetch();
            if(!$service)throw new RuntimeException('Culto/reunião ativo não encontrado.');
            $result=$engagementNotifier->sendService($church,$service,true);
            if((int)$result['sent']<1)throw new RuntimeException('Nenhum grupo recebeu o lembrete.');
            $pdo->prepare('UPDATE service_types SET last_notification_sent_at=NOW() WHERE id=? AND church_id=?')->execute([$id,$cid]);
            $success='Lembrete reenviado para '.$result['sent'].' grupo(s).';
        }else{
            $name=trim((string)($_POST['name']??''));if($name==='')throw new InvalidArgumentException('Informe o nome do culto.');
            $cong=trim((string)($_POST['congregation_id']??''))?:null;
            $dow=($_POST['day_of_week']??'')!==''?(int)$_POST['day_of_week']:null;
            $wom=($_POST['week_of_month']??'')!==''?(int)$_POST['week_of_month']:null;
            $time=trim((string)($_POST['time']??''))?:null;
            $desc=trim((string)($_POST['description']??''))?:null;
            $rec=isset($_POST['is_recurring'])?1:0;
            $groupService=new GroupBroadcastService($pdo);
            $targetGroupIds=$groupService->idsFromPost($_POST['target_group_ids']??[]);
            $existingImage=null;
            if($id){$x=$pdo->prepare('SELECT notification_image_path FROM service_types WHERE id=? AND church_id=?');$x->execute([$id,$cid]);$existingImage=$x->fetchColumn()?:null;}
            $uploaded=$uploader->notificationImage($_FILES['notification_image']??[],$cid);
            $notificationImage=$uploaded['path']??$existingImage;
            if($id){
                $q=$pdo->prepare('UPDATE service_types SET congregation_id=?,name=?,description=?,day_of_week=?,week_of_month=?,time=?,is_recurring=?,target_group_ids=?,notification_image_path=? WHERE id=? AND church_id=?');
                $q->execute([$cong,$name,$desc,$dow,$wom,$time,$rec,json_encode($targetGroupIds,JSON_UNESCAPED_UNICODE),$notificationImage,$id,$cid]);$success='Tipo de culto atualizado.';
            }else{
                $id=app_uuid();
                $q=$pdo->prepare('INSERT INTO service_types(id,church_id,congregation_id,name,description,day_of_week,week_of_month,time,is_recurring,target_group_ids,notification_image_path,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)');
                $q->execute([$id,$cid,$cong,$name,$desc,$dow,$wom,$time,$rec,json_encode($targetGroupIds,JSON_UNESCAPED_UNICODE),$notificationImage]);
                $x=$pdo->prepare('SELECT * FROM service_types WHERE id=? AND church_id=?');$x->execute([$id,$cid]);$service=$x->fetch();
                $success='Culto/reunião criado com sucesso.';
                try{
                    $result=$engagementNotifier->sendService($church,$service,false);
                    $sent=(int)$result['sent'];
                    if($sent>0){
                        $pdo->prepare('UPDATE service_types SET last_notification_sent_at=NOW() WHERE id=?')->execute([$id]);
                        $success.=' Convite enviado para '.$sent.' grupo(s).';
                    }else{
                        $success.=' Nenhum grupo recebeu o convite; você pode usar “Reenviar aviso” depois.';
                    }
                }catch(Throwable $notifyError){
                    $success.=' O cadastro foi salvo, mas a notificação não foi enviada: '.$notifyError->getMessage();
                }
            }
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$edit=null;
if(!empty($_GET['edit'])){$q=$pdo->prepare('SELECT * FROM service_types WHERE id=? AND church_id=?');$q->execute([(string)$_GET['edit'],$cid]);$edit=$q->fetch()?:null;}
$q=$pdo->prepare('SELECT s.*,c.name congregation_name FROM service_types s LEFT JOIN congregations c ON c.id=s.congregation_id WHERE s.church_id=? ORDER BY s.active DESC,s.name');$q->execute([$cid]);$rows=$q->fetchAll();
$q=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY sort_order,name');$q->execute([$cid]);$congs=$q->fetchAll();
$groups=(new GroupBroadcastService($pdo))->list((string)$cid,null,null,true);
$selectedGroups=$edit?json_decode((string)($edit['target_group_ids']??'[]'),true):[];if(!is_array($selectedGroups))$selectedGroups=[];
$days=['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
View::header('Tipos de Culto',$auth,'service-types.php');
?>
<div class="module-hero"><div><span class="eyebrow">Agenda recorrente</span><h1>Cultos e Reuniões</h1><p>Organize a programação fixa, escolha grupos, anexe folder e reenvie convites sempre que precisar lembrar a igreja.</p></div><div class="hero-stat"><strong><?=count($rows)?></strong><span>programações</span></div></div>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?>
<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?>
<div class="grid two-col">
<section class="card card-pad">
  <div class="page-head"><div><h1 style="font-size:16px">Cultos e reuniões</h1><p><?=count($rows)?> configuração(ões)</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Nome</th><th>Congregação</th><th>Dia</th><th>Horário</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><strong><?=e($r['name'])?></strong></td>
      <td><?=e($r['congregation_name']?:'Todas/Sede')?></td>
      <td><?=isset($r['day_of_week'])&&$r['day_of_week']!==null?e($days[(int)$r['day_of_week']]??'—'):'—'?></td>
      <td><?=e($r['time']?substr($r['time'],0,5):'—')?></td>
      <td><span class="badge <?=$r['active']?'ok':'off'?>"><?=$r['active']?'Ativo':'Inativo'?></span></td>
      <td style="white-space:nowrap">
        <a class="btn btn-light" style="padding:7px 9px" href="?edit=<?=e($r['id'])?>#form">Editar</a>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="action" value="resend"><button class="btn btn-light" style="padding:7px 9px">Reenviar aviso</button></form>
        <a class="btn btn-light" style="padding:7px 9px" target="_blank" href="/report.php?type=service&id=<?=e($r['id'])?>&format=print">Imprimir</a>
        <a class="btn btn-light" style="padding:7px 9px" href="/report.php?type=service&id=<?=e($r['id'])?>&format=pdf">PDF</a>
        <a class="btn btn-light" style="padding:7px 9px" href="api/generate-service-poster.php?id=<?=e($r['id'])?>" onclick="return confirm('Gerar um novo cartaz por IA para este culto?')">Gerar cartaz IA</a>
        <?php if(!empty($r['notification_image_path'])):?><a class="btn btn-light" style="padding:7px 9px" target="_blank" href="/<?=e($r['notification_image_path'])?>">Ver folder</a><?php elseif(!empty($r['poster_url'])):?><a class="btn btn-light" style="padding:7px 9px" target="_blank" href="<?=e($r['poster_url'])?>">Ver cartaz</a><?php endif;?>
      </td>
    </tr>
  <?php endforeach;?>
  </tbody></table></div>
</section>
<section class="card card-pad form-card" id="form">
  <div class="page-head"><div><h1 style="font-size:16px"><?=$edit?'Editar':'Novo tipo de culto'?></h1><p>Agenda recorrente da igreja</p></div></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><input type="hidden" name="action" value="save">
  <div class="form-grid">
    <div class="field full"><label>Nome *</label><input class="input" name="name" required value="<?=e($edit['name']??'')?>"></div>
    <div class="field full"><label>Congregação</label><select class="select" name="congregation_id"><option value="">Sede / Geral</option><?php foreach($congs as $c):?><option value="<?=e($c['id'])?>" <?=($edit['congregation_id']??'')===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div>
    <div class="field"><label>Dia da semana</label><select class="select" name="day_of_week"><option value="">—</option><?php foreach($days as $i=>$d):?><option value="<?=$i?>" <?=isset($edit['day_of_week'])&&(string)$edit['day_of_week']===(string)$i?'selected':''?>><?=e($d)?></option><?php endforeach;?></select></div>
    <div class="field"><label>Semana do mês</label><input class="input" type="number" min="1" max="5" name="week_of_month" value="<?=e((string)($edit['week_of_month']??''))?>"></div>
    <div class="field"><label>Horário</label><input class="input" type="time" name="time" value="<?=e($edit['time']?substr($edit['time'],0,5):'')?>"></div>
    <div class="field" style="display:flex;align-items:end"><label style="display:flex;gap:8px;align-items:center;margin-bottom:11px"><input type="checkbox" name="is_recurring" <?=!isset($edit['is_recurring'])||!empty($edit['is_recurring'])?'checked':''?>> Recorrente</label></div>
    <div class="field full"><label>Descrição</label><textarea class="textarea" name="description"><?=e($edit['description']??'')?></textarea></div>
    <div class="field full"><label>Cartaz / folder para a notificação</label><input class="input" type="file" name="notification_image" accept="image/jpeg,image/png,image/webp"><small class="help-text">Opcional. O cartaz será enviado junto com o convite.</small></div>
    <div class="field full"><label>Grupos para convite/lembrete</label><div class="target-grid"><?php foreach($groups as $g):?><label class="target-option"><input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>" <?=in_array($g['id'],$selectedGroups,true)?'checked':''?>><span><strong><?=e($g['name'])?></strong><small><?=e($g['congregation_name']?:'Geral / Matriz')?></small></span></label><?php endforeach?></div><small class="help-text">Se nenhum for marcado, usa os grupos configurados para Eventos e convites.</small></div>
  </div><button class="btn btn-primary" style="width:100%;margin-top:14px"><?=$edit?'Salvar alterações':'Cadastrar tipo de culto'?></button></form>
  <?php if($edit):?><form method="post" style="margin-top:9px"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id'])?>"><input type="hidden" name="action" value="toggle"><button class="btn <?=$edit['active']?'btn-danger':'btn-light'?>" style="width:100%"><?=$edit['active']?'Desativar':'Reativar'?></button></form><?php endif;?>
</section>
</div>
<?php View::footer();?>