<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/EngagementNotificationService.php';

$user=$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church)exit('Igreja não selecionada.');
$churchId=(string)$church['id'];
$uploader=new UploadService(__DIR__);
$notifier=new EngagementNotificationService($pdo,__DIR__);

if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    verify_csrf();
    $action=$_POST['action']??'create_clock';

    if($action==='create_clock'){
      $groupService=new GroupBroadcastService($pdo);
      $targetGroupIds=$groupService->idsFromPost($_POST['target_group_ids']??[]);
      $title=trim($_POST['title']??'');
      $date=$_POST['event_date']??'';
      if($title===''||$date==='')throw new RuntimeException('Informe título e data.');

      $clockId=app_uuid();
      $congregationId=$_POST['congregation_id']?:null;
      $endDate=$_POST['end_date']?:null;
      $startHour=(int)($_POST['start_hour']??0);
      $endHour=(int)($_POST['end_hour']??24);
      $duration=max(1,(int)($_POST['slot_duration_minutes']??60));
      $maxPerSlot=max(1,(int)($_POST['max_per_slot']??1));
      $reminder=($_POST['reminder_minutes_before']??'')!==''?(int)$_POST['reminder_minutes_before']:null;
      $image=$uploader->notificationImage($_FILES['notification_image']??[],$churchId);

      $q=$pdo->prepare('INSERT INTO prayer_clocks(id,church_id,congregation_id,title,event_date,end_date,start_hour,end_hour,slot_duration_minutes,max_per_slot,reminder_minutes_before,active,target_group_ids,notification_image_path) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $q->execute([
        $clockId,$churchId,$congregationId,$title,$date,$endDate,$startHour,$endHour,$duration,$maxPerSlot,$reminder,1,
        json_encode($targetGroupIds,JSON_UNESCAPED_UNICODE),
        $image['path']??null,
      ]);

      $q=$pdo->prepare('SELECT * FROM prayer_clocks WHERE id=? AND church_id=?');
      $q->execute([$clockId,$churchId]);$clock=$q->fetch();
      try{
        $result=$notifier->sendPrayerClock($church,$clock,false);
        if((int)$result['sent']>0){
          $pdo->prepare('UPDATE prayer_clocks SET announcement_sent_at=NOW(),last_notification_sent_at=NOW() WHERE id=?')->execute([$clockId]);
        }
        flash('success','Relógio criado.'.((int)$result['sent']>0?' Convite enviado para '.$result['sent'].' grupo(s).':''));
      }catch(Throwable $e){
        flash('warning','Relógio criado, mas o convite automático não foi enviado: '.$e->getMessage());
      }
      redirect('/prayer-clock.php');
    }

    if($action==='resend'){
      $id=(string)($_POST['id']??'');
      $q=$pdo->prepare('SELECT * FROM prayer_clocks WHERE id=? AND church_id=? AND active=1 LIMIT 1');
      $q->execute([$id,$churchId]);$clock=$q->fetch();
      if(!$clock)throw new RuntimeException('Relógio ativo não encontrado.');
      $result=$notifier->sendPrayerClock($church,$clock,true);
      if((int)$result['sent']<1)throw new RuntimeException('Nenhum grupo recebeu o lembrete.');
      $pdo->prepare('UPDATE prayer_clocks SET last_notification_sent_at=NOW(),group_reminder_sent_at=NOW() WHERE id=? AND church_id=?')->execute([$id,$churchId]);
      flash('success','Lembrete reenviado para '.$result['sent'].' grupo(s).');
      redirect('/prayer-clock.php');
    }

    if($action==='register'){
      $clock=$_POST['prayer_clock_id']??'';
      $name=trim($_POST['name']??'');
      $phone=trim($_POST['phone']??'');
      $slot=$_POST['slot_time']??'';
      if($clock===''||$name===''||$phone===''||$slot==='')throw new RuntimeException('Preencha os dados da inscrição.');
      $check=$pdo->prepare('SELECT pc.max_per_slot,COUNT(r.id) used FROM prayer_clocks pc LEFT JOIN prayer_clock_registrations r ON r.prayer_clock_id=pc.id AND r.slot_time=? WHERE pc.id=? AND pc.church_id=? GROUP BY pc.id');
      $check->execute([$slot,$clock,$churchId]);$c=$check->fetch();
      if(!$c||(int)$c['used']>=(int)$c['max_per_slot'])throw new RuntimeException('Este horário está lotado.');
      $q=$pdo->prepare('INSERT INTO prayer_clock_registrations(id,prayer_clock_id,church_id,name,phone,slot_time) VALUES(?,?,?,?,?,?)');
      $q->execute([app_uuid(),$clock,$churchId,$name,$phone,$slot]);
      flash('success','Horário reservado.');
      redirect('/prayer-clock.php');
    }

    if($action==='delete_clock'){
      $q=$pdo->prepare('DELETE FROM prayer_clocks WHERE id=? AND church_id=?');
      $q->execute([$_POST['id']??'',$churchId]);
      flash('success','Relógio removido.');
      redirect('/prayer-clock.php');
    }
  }catch(Throwable $e){
    flash('error',$e->getMessage());
    redirect('/prayer-clock.php');
  }
}

$congs=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY name');
$congs->execute([$churchId]);$congs=$congs->fetchAll();
$groupService=new GroupBroadcastService($pdo);
$groups=$groupService->list($churchId,null,null,true);

$q=$pdo->prepare('SELECT pc.*,c.name congregation_name,(SELECT COUNT(*) FROM prayer_clock_registrations r WHERE r.prayer_clock_id=pc.id) registrations FROM prayer_clocks pc LEFT JOIN congregations c ON c.id=pc.congregation_id WHERE pc.church_id=? ORDER BY pc.event_date DESC');
$q->execute([$churchId]);$clocks=$q->fetchAll();

$regs=$pdo->prepare('SELECT r.*,pc.title,pc.event_date FROM prayer_clock_registrations r INNER JOIN prayer_clocks pc ON pc.id=r.prayer_clock_id WHERE r.church_id=? ORDER BY pc.event_date DESC,r.slot_time');
$regs->execute([$churchId]);$regs=$regs->fetchAll();

View::header('Relógio de Oração','prayer-clock',$auth,$church);
?>
<div class="module-hero"><div><span class="eyebrow">Comunicação e oração</span><h1>Relógio de Oração</h1><p>Crie a campanha, escolha os grupos, envie cartaz, acompanhe os horários e reenvie lembretes sem refazer o relógio.</p></div><div class="hero-stat"><strong><?=count($clocks)?></strong><span>relógios cadastrados</span></div></div>
<?php View::flash(); ?>

<div class="grid-2">
<section class="card form-card">
  <div class="section-title"><span>◷</span><div><h2>Novo relógio</h2><p>Configure período, turnos, cartaz e grupos destinatários.</p></div></div>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?=csrf_field()?><input type="hidden" name="action" value="create_clock">
    <label class="full">Título<input name="title" required></label>
    <label>Congregação<select name="congregation_id"><option value="">Todas</option><?php foreach($congs as $c): ?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach?></select></label>
    <label>Data<input type="date" name="event_date" required></label>
    <label>Data final<input type="date" name="end_date"></label>
    <label>Hora inicial<input type="number" min="0" max="23" name="start_hour" value="0"></label>
    <label>Hora final<input type="number" min="0" max="24" name="end_hour" value="24"></label>
    <label>Duração do turno (min)<input type="number" min="1" name="slot_duration_minutes" value="60"></label>
    <label>Máx. por turno<input type="number" min="1" name="max_per_slot" value="1"></label>
    <label>Lembrete antes (min)<input type="number" min="0" name="reminder_minutes_before"></label>
    <label class="full">Cartaz / folder para enviar junto
      <input type="file" name="notification_image" accept="image/jpeg,image/png,image/webp">
      <small class="help-text">JPG, PNG ou WEBP até 8 MB. Se a Evolution não aceitar a imagem, o texto ainda será enviado.</small>
    </label>
    <label class="full">Grupos para convite e lembrete
      <div class="target-grid">
        <?php if(!$groups):?><div class="target-empty">Nenhum grupo cadastrado. <a href="/groups.php">Cadastrar grupos</a></div><?php endif?>
        <?php foreach($groups as $g):?><label class="target-option"><input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>"><span><strong><?=e($g['name'])?></strong><small><?=e($g['congregation_name']?:'Igreja geral / Matriz')?></small></span></label><?php endforeach?>
      </div>
      <small class="help-text">A notificação explica como se inscrever: “Seu Nome 14h” e como pedir a lista.</small>
    </label>
    <div class="full"><button class="btn primary">Criar e divulgar</button></div>
  </form>
</section>

<section class="card form-card">
  <div class="section-title"><span>✍</span><div><h2>Reserva manual</h2><p>Use quando a secretaria precisar incluir alguém diretamente.</p></div></div>
  <form method="post" class="form-grid">
    <?=csrf_field()?><input type="hidden" name="action" value="register">
    <label class="full">Relógio<select name="prayer_clock_id" required><option value="">Selecione</option><?php foreach($clocks as $c): ?><option value="<?=e($c['id'])?>"><?=e($c['title'])?> — <?=e(date('d/m/Y',strtotime($c['event_date'])))?></option><?php endforeach?></select></label>
    <label>Nome<input name="name" required></label><label>Telefone<input name="phone" required></label>
    <label>Horário<input type="time" name="slot_time" required></label>
    <div class="full"><button class="btn primary">Reservar</button></div>
  </form>
</section>
</div>

<section class="card">
<h2>Relógios</h2>
<div class="table-wrap"><table><thead><tr><th>Título</th><th>Data</th><th>Congregação</th><th>Inscritos</th><th>Último aviso</th><th>Ações</th></tr></thead><tbody>
<?php foreach($clocks as $c): ?>
<tr>
  <td><strong><?=e($c['title'])?></strong><?php if(!empty($c['notification_image_path'])):?><div class="muted">📎 Com cartaz</div><?php endif?></td>
  <td><?=e(date('d/m/Y',strtotime($c['event_date'])))?></td>
  <td><?=e($c['congregation_name']?:'Geral / Matriz')?></td>
  <td><?=e((string)$c['registrations'])?></td>
  <td><?=!empty($c['last_notification_sent_at'])?e(date('d/m/Y H:i',strtotime($c['last_notification_sent_at']))):'—'?></td>
  <td style="white-space:nowrap">
    <a class="btn small" target="_blank" href="/report.php?type=prayer_clock&id=<?=e($c['id'])?>&format=print">Imprimir</a>
    <a class="btn small" href="/report.php?type=prayer_clock&id=<?=e($c['id'])?>&format=pdf">PDF</a>
    <?php if(!empty($c['active'])):?>
    <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn small">Reenviar aviso</button></form>
    <?php endif?>
    <form method="post" style="display:inline" onsubmit="return confirm('Excluir relógio e inscrições?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_clock"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn danger small">Excluir</button></form>
  </td>
</tr>
<?php endforeach?>
</tbody></table></div>
</section>

<section class="card"><h2>Inscrições</h2><div class="table-wrap"><table><thead><tr><th>Nome</th><th>Relógio</th><th>Data</th><th>Horário</th><th>Telefone</th></tr></thead><tbody><?php foreach($regs as $r): ?><tr><td><?=e($r['name'])?></td><td><?=e($r['title'])?></td><td><?=e(date('d/m/Y',strtotime($r['event_date'])))?></td><td><?=e(substr($r['slot_time'],0,5))?></td><td><?=e($r['phone'])?></td></tr><?php endforeach?></tbody></table></div></section>
<?php View::footer(); ?>
