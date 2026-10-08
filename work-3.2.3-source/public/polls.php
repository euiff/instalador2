<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';
$user=$auth->requireAbility('communications'); $church=$auth->currentChurch(); if(!$church) exit('Igreja não selecionada.');
$churchId=$church['id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf(); $action=$_POST['action']??'create';
  if($action==='delete'){ $q=$pdo->prepare('DELETE FROM polls WHERE id=? AND church_id=?'); $q->execute([$_POST['id']??'',$churchId]); flash('success','Enquete removida.'); redirect('/polls.php'); }
  $question=trim($_POST['question']??''); $options=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',$_POST['options']??''))));
  if($question===''||count($options)<2){ flash('error','Informe a pergunta e pelo menos duas opções.'); redirect('/polls.php'); }
  $status=$_POST['status']??'draft'; $scheduled=$_POST['scheduled_at']?:null; $expires=$_POST['expires_at']?:null;
  $targetGroups=(new GroupBroadcastService($pdo))->idsFromPost($_POST['target_group_ids']??[]);
  $q=$pdo->prepare('INSERT INTO polls(id,church_id,question,options,results,target_groups,template_name,validity,status,scheduled_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
  $q->execute([app_uuid(),$churchId,$question,json_encode($options,JSON_UNESCAPED_UNICODE),json_encode([],JSON_UNESCAPED_UNICODE),json_encode($targetGroups,JSON_UNESCAPED_UNICODE),trim($_POST['template_name']??'')?:null,trim($_POST['validity']??'')?:null,$status,$scheduled,$expires]);
  flash('success','Enquete criada.'); redirect('/polls.php');
}
$q=$pdo->prepare('SELECT * FROM polls WHERE church_id=? ORDER BY created_at DESC'); $q->execute([$churchId]); $rows=$q->fetchAll();
$groups=(new GroupBroadcastService($pdo))->list((string)$churchId,null,null,true);
View::header('Enquetes','polls',$auth,$church);
?>
<div class="page-head"><div><h1>Enquetes</h1><p>Crie pesquisas para grupos e membros.</p></div></div><?php View::flash(); ?>
<div class="grid-2"><section class="card"><h2>Nova enquete</h2><form method="post" class="form-grid"><?=csrf_field()?>
<label class="full">Pergunta<textarea name="question" required></textarea></label><label class="full">Opções (uma por linha)<textarea name="options" required></textarea></label><label>Modelo<input name="template_name"></label><label>Validade<input name="validity" placeholder="Ex.: 24h"></label><label>Status<select name="status"><option value="draft">Rascunho</option><option value="scheduled">Agendada</option><option value="sent">Enviada</option></select></label><label>Agendar para<input name="scheduled_at" type="datetime-local"></label><label>Expira em<input name="expires_at" type="datetime-local"></label>
<label class="full">Grupos destinatários
  <div class="target-grid">
    <?php if(!$groups):?><div class="target-empty">Nenhum grupo cadastrado. <a href="/groups.php">Cadastrar grupos</a></div><?php endif?>
    <?php foreach($groups as $g):?><label class="target-option"><input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>"><span><strong><?=e($g['name'])?></strong><small><?=e($g['congregation_name']?:'Igreja geral / Matriz')?></small></span></label><?php endforeach?>
  </div>
  <small class="help-text">Se nenhum for marcado, o sistema tenta os grupos com finalidade “Enquetes” e, se não houver, mantém o envio antigo para membros.</small>
</label>
<div class="full"><button class="btn primary">Criar enquete</button></div></form></section>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Pergunta</th><th>Status</th><th>Agendamento</th><th></th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e($r['question'])?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?=e($r['scheduled_at']?date('d/m/Y H:i',strtotime($r['scheduled_at'])):'—')?></td><td><form method="post" onsubmit="return confirm('Excluir enquete?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn danger small">Excluir</button></form></td></tr><?php endforeach?></tbody></table></div></section></div>
<?php View::footer(); ?>