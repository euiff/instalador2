<?php
require __DIR__.'/bootstrap.php';
$user=$auth->requireAbility('settings'); $church=$auth->currentChurch(); if(!$church) exit('Igreja não selecionada.'); $churchId=$church['id'];
$source=trim($_GET['source']??''); $status=trim($_GET['status']??'');
$params=[$churchId]; $where='(wl.church_id=? OR wl.church_id IS NULL)';
if($source!==''){ $where.=' AND wl.source=?'; $params[]=$source; }
if($status!==''){ $where.=' AND wl.status=?'; $params[]=$status; }
$q=$pdo->prepare("SELECT wl.* FROM webhook_logs wl WHERE $where ORDER BY wl.created_at DESC LIMIT 300"); $q->execute($params); $webhooks=$q->fetchAll();
$m=$pdo->prepare('SELECT * FROM moderation_logs WHERE church_id=? ORDER BY created_at DESC LIMIT 300'); $m->execute([$churchId]); $moderation=$m->fetchAll();
View::header('Logs','logs',$auth,$church);
?>
<div class="page-head"><div><h1>Logs</h1><p>Acompanhe webhooks, integrações e ações de moderação.</p></div></div>
<section class="card"><form method="get" class="inline-form"><input name="source" value="<?=e($source)?>" placeholder="Origem"><input name="status" value="<?=e($status)?>" placeholder="Status"><button class="btn">Filtrar</button></form></section>
<section class="card"><h2>Webhooks e integrações</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Origem</th><th>Evento</th><th>Status</th><th>Payload</th></tr></thead><tbody><?php foreach($webhooks as $r): ?><tr><td><?=e(date('d/m/Y H:i:s',strtotime($r['created_at'])))?></td><td><?=e($r['source'])?></td><td><?=e($r['event_type']?:'—')?></td><td><?=e($r['status'])?></td><td><details><summary>Ver</summary><pre><?=e($r['payload']?:'{}')?></pre></details></td></tr><?php endforeach?></tbody></table></div></section>
<section class="card"><h2>Moderação</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Remetente</th><th>Grupo</th><th>Motivo</th><th>Ação</th><th>Mensagem</th></tr></thead><tbody><?php foreach($moderation as $r): ?><tr><td><?=e(date('d/m/Y H:i:s',strtotime($r['created_at'])))?></td><td><?=e(($r['sender_name']?:'').' '.$r['sender_phone'])?></td><td><?=e($r['group_id'])?></td><td><?=e($r['reason'])?></td><td><?=e($r['action_taken']?:'—')?></td><td><details><summary>Ver</summary><div><?=nl2br(e($r['original_message']))?></div><?php if($r['ai_analysis']):?><hr><small><?=nl2br(e($r['ai_analysis']))?></small><?php endif?></details></td></tr><?php endforeach?></tbody></table></div></section>
<?php View::footer(); ?>