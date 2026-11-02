<?php
require __DIR__.'/bootstrap.php';
$user=$auth->requireAbility('communications'); $church=$auth->currentChurch(); if(!$church) exit('Igreja não selecionada.'); $churchId=$church['id'];
$phone=trim($_GET['phone']??''); $direction=trim($_GET['direction']??'');
$params=[$churchId]; $where='church_id=?'; if($phone!==''){ $where.=' AND phone LIKE ?'; $params[]='%'.$phone.'%'; } if(in_array($direction,['inbound','outbound'],true)){ $where.=' AND direction=?'; $params[]=$direction; }
$q=$pdo->prepare("SELECT * FROM bot_messages WHERE $where ORDER BY created_at DESC LIMIT 500"); $q->execute($params); $rows=$q->fetchAll();
View::header('Mensagens','messages',$auth,$church);
?>
<div class="page-head"><div><h1>Mensagens</h1><p>Histórico de conversas do bot e WhatsApp.</p></div></div>
<section class="card"><form method="get" class="inline-form"><input name="phone" value="<?=e($phone)?>" placeholder="Telefone"><select name="direction"><option value="">Todas</option><option value="inbound" <?=$direction==='inbound'?'selected':''?>>Recebidas</option><option value="outbound" <?=$direction==='outbound'?'selected':''?>>Enviadas</option></select><button class="btn">Filtrar</button></form></section>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Data</th><th>Telefone</th><th>Direção</th><th>Tipo</th><th>Status</th><th>Mensagem</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e(date('d/m/Y H:i:s',strtotime($r['created_at'])))?></td><td><?=e($r['phone'])?></td><td><?=e($r['direction']==='inbound'?'Recebida':'Enviada')?></td><td><?=e($r['message_type'])?></td><td><?=e($r['status'])?></td><td><?=nl2br(e($r['message']))?></td></tr><?php endforeach?></tbody></table></div></section>
<?php View::footer(); ?>