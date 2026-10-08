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
$error=flash('error');
$success=flash('success');
$uploader=new UploadService(__DIR__);
$engagementNotifier=new EngagementNotificationService($pdo,__DIR__);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada.');
        $id=(string)($_POST['id']??'');
        $action=(string)($_POST['action']??'save');
        if($action==='toggle'){
            $q=$pdo->prepare('UPDATE events SET active=IF(active=1,0,1) WHERE id=? AND church_id=?');
            $q->execute([$id,$cid]);
            $success='Status do evento atualizado.';
        }elseif($action==='resend'){
            $q=$pdo->prepare('SELECT * FROM events WHERE id=? AND church_id=? AND active=1 LIMIT 1');
            $q->execute([$id,$cid]);$event=$q->fetch();
            if(!$event)throw new RuntimeException('Evento ativo não encontrado.');
            $result=$engagementNotifier->sendEvent($church,$event,true);
            if((int)$result['sent']<1)throw new RuntimeException('Nenhum grupo recebeu o lembrete.');
            $pdo->prepare('UPDATE events SET last_notification_sent_at=NOW() WHERE id=? AND church_id=?')->execute([$id,$cid]);
            $success='Convite/lembrança reenviado para '.$result['sent'].' grupo(s).';
        }else{
            $title=trim((string)($_POST['title']??''));
            $date=trim((string)($_POST['event_date']??''));
            if($title===''||$date==='')throw new InvalidArgumentException('Título e data são obrigatórios.');
            $eventDate=str_replace('T',' ',$date).(strlen($date)<=16?':00':'');
            $groupService=new GroupBroadcastService($pdo);
            $targetGroupIds=$groupService->idsFromPost($_POST['target_group_ids']??[]);
            $existingImage=null;
            if($id){
                $x=$pdo->prepare('SELECT notification_image_path FROM events WHERE id=? AND church_id=?');
                $x->execute([$id,$cid]);$existingImage=$x->fetchColumn()?:null;
            }
            $uploaded=$uploader->notificationImage($_FILES['notification_image']??[],$cid);
            $notificationImage=$uploaded['path']??$existingImage;

            $vals=[
                trim((string)($_POST['congregation_id']??''))?:null,
                trim((string)($_POST['service_type_id']??''))?:null,
                $title,
                trim((string)($_POST['description']??''))?:null,
                trim((string)($_POST['theme']??''))?:null,
                trim((string)($_POST['event_type']??''))?:null,
                $eventDate,
                trim((string)($_POST['location']??''))?:null,
                trim((string)($_POST['speaker_name']??''))?:null,
                ($_POST['capacity']??'')!==''?(int)$_POST['capacity']:null,
            ];
            if($id){
                $q=$pdo->prepare('UPDATE events SET congregation_id=?,service_type_id=?,title=?,description=?,theme=?,event_type=?,event_date=?,location=?,speaker_name=?,capacity=?,target_group_ids=?,notification_image_path=? WHERE id=? AND church_id=?');
                $q->execute([...$vals,json_encode($targetGroupIds,JSON_UNESCAPED_UNICODE),$notificationImage,$id,$cid]);
                $success='Evento atualizado.';
            }else{
                $id=app_uuid();
                $q=$pdo->prepare('INSERT INTO events(id,church_id,congregation_id,service_type_id,title,description,theme,event_type,event_date,location,speaker_name,capacity,target_group_ids,notification_image_path,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
                $q->execute([$id,$cid,...$vals,json_encode($targetGroupIds,JSON_UNESCAPED_UNICODE),$notificationImage]);
                $x=$pdo->prepare('SELECT * FROM events WHERE id=? AND church_id=?');$x->execute([$id,$cid]);$event=$x->fetch();
                $success='Evento criado com sucesso.';
                try{
                    $result=$engagementNotifier->sendEvent($church,$event,false);
                    $sent=(int)$result['sent'];
                    if($sent>0){
                        $pdo->prepare('UPDATE events SET last_notification_sent_at=NOW() WHERE id=?')->execute([$id]);
                        $success.=' Convite enviado para '.$sent.' grupo(s).';
                    }else{
                        $success.=' Nenhum grupo recebeu o convite; você pode usar “Reenviar convite” depois.';
                    }
                }catch(Throwable $notifyError){
                    $success.=' O cadastro foi salvo, mas a notificação não foi enviada: '.$notifyError->getMessage();
                }
            }
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$edit=null;
if(!empty($_GET['edit'])){
    $q=$pdo->prepare('SELECT * FROM events WHERE id=? AND church_id=?');
    $q->execute([(string)$_GET['edit'],$cid]);
    $edit=$q->fetch()?:null;
}
$filterCongregation=trim((string)($_GET['congregation_id']??''));
$sql='SELECT e.*,c.name congregation_name,s.name service_name,(SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id) registrations_count FROM events e LEFT JOIN congregations c ON c.id=e.congregation_id LEFT JOIN service_types s ON s.id=e.service_type_id WHERE e.church_id=?';
$params=[$cid];
if($filterCongregation!==''){$sql.=' AND e.congregation_id=?';$params[]=$filterCongregation;}
$sql.=' ORDER BY e.event_date DESC LIMIT 500';
$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
$q=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY sort_order,name');$q->execute([$cid]);$congs=$q->fetchAll();
$q=$pdo->prepare('SELECT id,name FROM service_types WHERE church_id=? AND active=1 ORDER BY name');$q->execute([$cid]);$types=$q->fetchAll();
$groupService=new GroupBroadcastService($pdo);
$groups=$groupService->list((string)$cid,null,null,true);
$selectedGroups=$edit?json_decode((string)($edit['target_group_ids']??'[]'),true):[];
if(!is_array($selectedGroups))$selectedGroups=[];

View::header('Eventos',$auth,'events.php');
?>
<div class="module-hero"><div><span class="eyebrow">Agenda e comunicação</span><h1>Eventos</h1><p>Cadastre programações, envie convites com cartaz, acompanhe inscrições, gere listas e reenvie lembretes aos grupos.</p></div><div class="hero-stat"><strong><?=count($rows)?></strong><span>eventos encontrados</span></div></div>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?>
<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?>
<div class="grid two-col">
<section class="card card-pad">
  <div class="page-head"><div><h1 style="font-size:16px">Agenda de eventos</h1><p><?=count($rows)?> evento(s)</p></div><a class="btn btn-primary" href="events.php#form">+ Novo evento</a></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Evento</th><th>Data</th><th>Local</th><th>Inscrições</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><strong><?=e($r['title'])?></strong><div class="muted" style="font-size:10px"><?=e($r['congregation_name']?:$r['service_name']?:'Geral')?></div></td>
      <td><?=date('d/m/Y H:i',strtotime($r['event_date']))?></td>
      <td><?=e($r['location']?:'—')?></td>
      <td><?=$r['registrations_count']?></td>
      <td><span class="badge <?=$r['active']?'ok':'off'?>"><?=$r['active']?'Ativo':'Inativo'?></span></td>
      <td style="white-space:nowrap">
        <a class="btn btn-light" style="padding:7px 9px" href="?edit=<?=e($r['id'])?>#form">Editar</a>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="action" value="resend"><button class="btn btn-light" style="padding:7px 9px">Reenviar convite</button></form>
        <a class="btn btn-light" style="padding:7px 9px" target="_blank" href="/report.php?type=event&id=<?=e($r['id'])?>&format=print">Imprimir lista</a>
        <a class="btn btn-light" style="padding:7px 9px" href="/report.php?type=event&id=<?=e($r['id'])?>&format=pdf">PDF lista</a>
        <a class="btn btn-light" style="padding:7px 9px" href="api/generate-event-poster.php?id=<?=e($r['id'])?>" onclick="return confirm('Gerar um novo cartaz por IA para este evento?')">Gerar cartaz IA</a>
        <?php if(!empty($r['notification_image_path'])):?><a class="btn btn-light" style="padding:7px 9px" target="_blank" href="/<?=e($r['notification_image_path'])?>">Ver folder</a><?php elseif(!empty($r['poster_url'])):?><a class="btn btn-light" style="padding:7px 9px" target="_blank" href="<?=e($r['poster_url'])?>">Ver cartaz</a><?php endif;?>
      </td>
    </tr>
  <?php endforeach;?>
  </tbody></table></div>
</section>
<section class="card card-pad form-card" id="form">
  <div class="page-head"><div><h1 style="font-size:16px"><?=$edit?'Editar evento':'Novo evento'?></h1><p>Informações da programação</p></div></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><input type="hidden" name="action" value="save">
  <div class="form-grid">
    <div class="field full"><label>Título *</label><input class="input" name="title" required value="<?=e($edit['title']??'')?>"></div>
    <div class="field"><label>Data e hora *</label><input class="input" type="datetime-local" name="event_date" required value="<?=e($edit?date('Y-m-d\TH:i',strtotime($edit['event_date'])):'')?>"></div>
    <div class="field"><label>Capacidade</label><input class="input" type="number" min="0" name="capacity" value="<?=e((string)($edit['capacity']??''))?>"></div>
    <div class="field full"><label>Congregação</label><select class="select" name="congregation_id"><option value="">Geral/Sede</option><?php foreach($congs as $c):?><option value="<?=e($c['id'])?>" <?=((string)($edit['congregation_id']??$filterCongregation))===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div>
    <div class="field full"><label>Tipo de culto</label><select class="select" name="service_type_id"><option value="">Nenhum</option><?php foreach($types as $t):?><option value="<?=e($t['id'])?>" <?=($edit['service_type_id']??'')===$t['id']?'selected':''?>><?=e($t['name'])?></option><?php endforeach;?></select></div>
    <div class="field"><label>Tema</label><input class="input" name="theme" value="<?=e($edit['theme']??'')?>"></div>
    <div class="field"><label>Tipo</label><input class="input" name="event_type" value="<?=e($edit['event_type']??'')?>"></div>
    <div class="field full"><label>Local</label><input class="input" name="location" value="<?=e($edit['location']??'')?>"></div>
    <div class="field full"><label>Preletor / convidado</label><input class="input" name="speaker_name" value="<?=e($edit['speaker_name']??'')?>"></div>
    <div class="field full"><label>Descrição</label><textarea class="textarea" name="description"><?=e($edit['description']??'')?></textarea></div>
    <div class="field full"><label>Cartaz / folder para enviar junto</label><input class="input" type="file" name="notification_image" accept="image/jpeg,image/png,image/webp"><small class="help-text">JPG, PNG ou WEBP até 8 MB. <?=!empty($edit['notification_image_path'])?'Já existe um folder salvo; envie outro somente se quiser substituir.':''?></small></div>
    <div class="field full">
      <label>Grupos que receberão convite e lembrete</label>
      <div class="target-grid">
        <?php if(!$groups):?><div class="target-empty">Nenhum grupo cadastrado. <a href="/groups.php">Cadastrar grupos</a></div><?php endif?>
        <?php foreach($groups as $g):?>
          <label class="target-option"><input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>" <?=in_array($g['id'],$selectedGroups,true)?'checked':''?>><span><strong><?=e($g['name'])?></strong><small><?=e($g['congregation_name']?:'Igreja geral / Matriz')?></small></span></label>
        <?php endforeach?>
      </div>
      <small class="help-text">Se nenhum grupo for marcado, o sistema usa os grupos configurados para “Eventos e convites”.</small>
    </div>
  </div><button class="btn btn-primary" style="width:100%;margin-top:14px"><?=$edit?'Salvar alterações':'Criar evento'?></button></form>
  <?php if($edit):?><form method="post" style="margin-top:9px"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id'])?>"><input type="hidden" name="action" value="toggle"><button class="btn <?=$edit['active']?'btn-danger':'btn-light'?>" style="width:100%"><?=$edit['active']?'Desativar evento':'Reativar evento'?></button></form><?php endif;?>
</section>
</div>
<?php View::footer();?>