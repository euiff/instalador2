<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';

$user=$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$service=new GroupBroadcastService($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='cancel'){
            $id=(string)($_POST['id']??'');
            $q=$pdo->prepare('UPDATE communication_campaigns SET status="cancelled" WHERE id=? AND church_id=? AND status="scheduled"');
            $q->execute([$id,$cid]);
            flash('success','Agendamento cancelado.');
            redirect('/campaigns.php');
        }

        $title=trim((string)($_POST['title']??''));
        $message=trim((string)($_POST['message']??''));
        $type=trim((string)($_POST['campaign_type']??'announcement'));
        $groupIds=$service->idsFromPost($_POST['target_group_ids']??[]);
        if($title===''||$message==='')throw new RuntimeException('Informe título e mensagem.');

        $id=app_uuid();
        if($action==='schedule'){
            $scheduled=trim((string)($_POST['scheduled_at']??''));
            if($scheduled==='')throw new RuntimeException('Informe a data e hora do agendamento.');
            $scheduled=str_replace('T',' ',$scheduled).(strlen($scheduled)<=16?':00':'');
            if(strtotime($scheduled)<=time())throw new RuntimeException('O agendamento precisa estar no futuro.');

            $q=$pdo->prepare('INSERT INTO communication_campaigns(id,church_id,title,message,campaign_type,target_group_ids,scheduled_at,status,created_by) VALUES(?,?,?,?,?,?,?,"scheduled",?)');
            $q->execute([$id,$cid,$title,$message,$type,json_encode($groupIds,JSON_UNESCAPED_UNICODE),$scheduled,$user['id']]);
            flash('success','Comunicado agendado para '.date('d/m/Y H:i',strtotime($scheduled)).'.');
            redirect('/campaigns.php');
        }

        if($action==='send'){
            $q=$pdo->prepare('INSERT INTO communication_campaigns(id,church_id,title,message,campaign_type,target_group_ids,status,created_by) VALUES(?,?,?,?,?,?,"sending",?)');
            $q->execute([$id,$cid,$title,$message,$type,json_encode($groupIds,JSON_UNESCAPED_UNICODE),$user['id']]);

            $result=$groupIds
                ?$service->sendIds($church,$groupIds,$message,$id)
                :$service->sendPurpose($church,'announcements',$message,null,$id);

            $status=(int)$result['sent']>0?'sent':'error';
            $pdo->prepare('UPDATE communication_campaigns SET status=?,sent_at=IF(?="sent",NOW(),sent_at) WHERE id=?')->execute([$status,$status,$id]);
            if((int)$result['sent']<1){
                $err=$result['errors'][0]['error']??'Nenhum grupo recebeu a mensagem.';
                throw new RuntimeException($err);
            }
            flash('success','Comunicado enviado para '.$result['sent'].' grupo(s).');
            redirect('/campaigns.php');
        }

        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect('/campaigns.php');
    }
}

$groups=$service->list($cid,null,null,true);
$q=$pdo->prepare('
    SELECT c.*,
      (SELECT COUNT(*) FROM communication_deliveries d WHERE d.campaign_id=c.id AND d.status="sent") sent_count,
      (SELECT COUNT(*) FROM communication_deliveries d WHERE d.campaign_id=c.id AND d.status="error") error_count
    FROM communication_campaigns c
    WHERE c.church_id=?
    ORDER BY c.created_at DESC
    LIMIT 100
');
$q->execute([$cid]);
$rows=$q->fetchAll();

$scheduledCount=count(array_filter($rows,fn($r)=>$r['status']==='scheduled'));
$sentCount=count(array_filter($rows,fn($r)=>$r['status']==='sent'));
$totalDeliveries=array_sum(array_map(fn($r)=>(int)$r['sent_count'],$rows));

View::header('Comunicados','campaigns',$auth,$church);
?>
<div class="page-head groups-page-head">
  <div><h1>Comunicados</h1><p>Envie avisos para um ou vários grupos da igreja imediatamente ou deixe agendado.</p></div>
  <div class="actions"><a class="btn btn-light" href="/groups.php">☏ Gerenciar grupos</a></div>
</div>
<?php View::flash(); ?>

<div class="grid stats group-stats">
  <div class="card stat"><div class="stat-label">Grupos disponíveis</div><div class="stat-value"><?=count($groups)?></div><div class="stat-foot">Destinos ativos</div></div>
  <div class="card stat"><div class="stat-label">Comunicados enviados</div><div class="stat-value"><?=$sentCount?></div><div class="stat-foot">Campanhas concluídas</div></div>
  <div class="card stat"><div class="stat-label">Agendados</div><div class="stat-value"><?=$scheduledCount?></div><div class="stat-foot">Aguardando o cron</div></div>
  <div class="card stat"><div class="stat-label">Entregas registradas</div><div class="stat-value"><?=$totalDeliveries?></div><div class="stat-foot">Envios para grupos</div></div>
</div>

<div class="groups-layout">
<section class="card settings-section">
  <div class="settings-section-head">
    <div class="settings-icon">✉</div>
    <div><h2>Novo comunicado</h2><p>Escolha os grupos, escreva a mensagem e envie agora ou programe o disparo.</p></div>
  </div>
  <form method="post">
    <?=csrf_field()?>
    <div class="form-grid">
      <div class="field"><label>Título interno *</label><input class="input" name="title" required placeholder="Ex.: Convite culto de domingo"></div>
      <div class="field"><label>Tipo</label><select class="select" name="campaign_type"><option value="announcement">Comunicado</option><option value="event">Convite de evento</option><option value="reminder">Lembrete</option><option value="pastoral">Mensagem pastoral</option><option value="campaign">Campanha</option></select></div>
      <div class="field full"><label>Mensagem *</label><textarea class="textarea tall" name="message" required placeholder="Escreva exatamente como deseja que a mensagem apareça no WhatsApp."></textarea></div>
      <div class="field full">
        <label>Grupos destinatários</label>
        <div class="target-grid">
          <?php if(!$groups):?><div class="target-empty">Nenhum grupo ativo. <a href="/groups.php">Cadastre ou sincronize os grupos da Evolution</a>.</div><?php endif?>
          <?php foreach($groups as $g):?><label class="target-option"><input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>"><span><strong><?=e($g['name'])?></strong><small><?=e($g['congregation_name']?:'Igreja geral / Matriz')?></small></span></label><?php endforeach?>
        </div>
        <small class="help-text">Se nenhum for marcado, serão usados todos os grupos que têm a finalidade “Comunicados”.</small>
      </div>
      <div class="field full"><label>Data e hora para agendar</label><input class="input" type="datetime-local" name="scheduled_at"><small class="help-text">Preencha somente se for usar “Agendar envio”.</small></div>
    </div>
    <div class="integration-actions">
      <button class="btn btn-primary" type="submit" name="action" value="send">Enviar agora</button>
      <button class="btn btn-light" type="submit" name="action" value="schedule">Agendar envio</button>
    </div>
  </form>
</section>

<aside class="card settings-section">
  <div class="settings-section-head">
    <div class="settings-icon">i</div>
    <div><h2>Como funciona</h2><p>As mensagens usam a mesma instância da Evolution configurada para a igreja.</p></div>
  </div>
  <div class="setup-steps" style="grid-template-columns:1fr">
    <div><strong>1</strong><span>Cadastre ou sincronize os grupos em “Grupos da Igreja”.</span></div>
    <div><strong>2</strong><span>Marque as finalidades de cada grupo: eventos, rifas, oração, comunicados etc.</span></div>
    <div><strong>3</strong><span>Envie manualmente aqui ou deixe eventos/rifas/oração dispararem automaticamente.</span></div>
  </div>
  <a class="btn btn-light" style="width:100%" href="/settings.php">Revisar Evolution API</a>
</aside>
</div>

<section class="card" style="margin-top:18px">
  <div class="page-head" style="padding:18px 18px 8px"><div><h2 style="font-size:15px">Histórico de comunicados</h2><p>Últimos envios e agendamentos.</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Data</th><th>Comunicado</th><th>Tipo</th><th>Status</th><th>Entregues</th><th>Erros</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><?=e(date('d/m/Y H:i',strtotime($r['created_at'])))?></td>
      <td><strong><?=e($r['title'])?></strong><?php if($r['scheduled_at']):?><div class="muted" style="font-size:10px">Agendado: <?=e(date('d/m/Y H:i',strtotime($r['scheduled_at'])))?></div><?php endif?></td>
      <td><?=e($r['campaign_type'])?></td>
      <td><span class="badge <?=$r['status']==='sent'?'ok':($r['status']==='error'?'off':'')?>"><?=e($r['status'])?></span></td>
      <td><?=e((string)$r['sent_count'])?></td>
      <td><?=e((string)$r['error_count'])?></td>
      <td><?php if($r['status']==='scheduled'):?><form method="post" onsubmit="return confirm('Cancelar este agendamento?')"><?=csrf_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-danger">Cancelar</button></form><?php else:?><span class="muted">—</span><?php endif?></td>
    </tr>
  <?php endforeach?>
  <?php if(!$rows):?><tr><td colspan="7"><div class="empty">Nenhum comunicado enviado ainda.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>
<?php View::footer(); ?>
