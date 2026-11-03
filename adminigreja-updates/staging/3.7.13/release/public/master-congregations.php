<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MasterCongregationService.php';
$user=$auth->requireRole('super_admin');
MasterCongregationService::ensure($pdo);
$svc=new MasterCongregationService($pdo,$auth,(string)$user['id']);
$churches=$svc->churches();$error=null;
$cid=(string)($_POST['church_id']??$_GET['church']??'');
$church=null;
if($cid!=='')try{$church=$svc->church($cid);}catch(Throwable $e){$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  verify_csrf();
  if(!$church)throw new RuntimeException('Selecione uma igreja válida.');
  $action=(string)($_POST['action']??'');
  if($action==='assign'){
    $result=$svc->assign($cid,(string)($_POST['congregation_id']??''),
      (string)($_POST['selection_mode']??'selected'),
      is_array($_POST['member_ids']??null)?$_POST['member_ids']:[],
      (string)($_POST['confirm_church']??''),(string)($_POST['confirm_destination']??''));
    flash('success',$result['moved'].' membros vinculados à congregação '.$result['congregation'].
      '. O histórico do lote permite desfazer em caso de erro.');
  }elseif($action==='undo'){
    if(($_POST['confirm_undo']??'')!=='DESFAZER')throw new RuntimeException('Digite DESFAZER para reverter este lote.');
    $n=$svc->undo($cid,(string)($_POST['batch_id']??''));
    flash('success',$n.' membros restaurados à congregação anterior. Alterações feitas depois do lote foram preservadas.');
  }else throw new RuntimeException('Operação inválida.');
  redirect('/master-congregations.php?church='.rawurlencode($cid).'#bulk-history');
 }catch(Throwable $e){$error=$e instanceof PDOException?'Falha no banco; operação revertida.':$e->getMessage();}
}
$search=(string)($_GET['q']??'');
$members=$church?$svc->people($cid,$search,(int)($_GET['page']??1)):null;
$congregations=$church?$svc->congregations($cid):[];
$batches=$church?$svc->batches($cid):[];
View::header('Transferência em massa de congregações',$auth,'master-congregations.php');View::flash();
?>
<style>
.bulk-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(270px,.9fr);gap:16px}
.bulk-controls{display:grid;gap:12px}.bulk-controls label{display:grid;gap:5px}
.bulk-table{overflow-x:auto}.bulk-table table{min-width:620px;width:100%;border-collapse:collapse}
.bulk-table td,.bulk-table th{padding:10px;text-align:left;border-bottom:1px solid #e3ebef}
.bulk-pale{border:1px solid #ecd8b2;padding:12px;border-radius:12px;background:#fff9ee}
.bulk-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
@media(max-width:920px){.bulk-grid{grid-template-columns:1fr}}
</style>
<section class="module-toolbar"><div class="module-title"><h1>Congregações em massa</h1>
<p>Organize vários membros na congregação correta sem precisar editar um por um.</p></div>
<div class="actions"><a class="btn btn-light" href="/master-members.php?church=<?=rawurlencode($cid)?>">Membros no Master</a>
<a class="btn btn-light" href="/master.php">Voltar ao Master</a></div></section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="card card-pad"><form class="bulk-controls" method="get">
<label>Selecionar igreja<select class="select" name="church" required onchange="this.form.submit()">
<option value="">Escolha a igreja</option>
<?php foreach($churches as $c):?><option value="<?=e($c['id'])?>" <?=$cid===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?>
</select></label><noscript><button class="btn btn-primary">Abrir igreja</button></noscript>
</form></section>
<?php if($church):?>
<p class="bulk-pale">Destino: <strong><?=e($church['name'])?></strong>. Esta ferramenta somente altera a congregação dos membros dessa igreja. Não transfere membros para outras igrejas, nem modifica dízimos ou históricos.</p>
<div class="bulk-grid">
<section class="card card-pad">
<div class="page-head"><div><h2>Membros</h2><p><?=e((string)$members['total'])?> cadastros, página <?=e((string)$members['page'])?> de <?=e((string)$members['pages'])?>.</p></div></div>
<form method="get" class="bulk-actions">
<input type="hidden" name="church" value="<?=e($cid)?>">
<input class="input" type="search" name="q" value="<?=e($search)?>" placeholder="Buscar membro" maxlength="120">
<button class="btn btn-light">Pesquisar</button></form>
<form id="bulk-form" method="post" class="bulk-controls">
<?=csrf_field()?><input type="hidden" name="action" value="assign">
<input type="hidden" name="church_id" value="<?=e($cid)?>">
<div class="bulk-table"><table><thead><tr><th><input id="bulk-all-page" type="checkbox" aria-label="Selecionar todos desta página"></th><th>Membro</th><th>Congregação atual</th></tr></thead><tbody>
<?php foreach($members['rows'] as $m):?><tr><td>
<input class="bulk-person" type="checkbox" name="member_ids[]" value="<?=e($m['id'])?>" aria-label="Selecionar <?=e($m['name'])?>">
</td><td><?=e($m['name'])?></td><td><?=e($m['congregation_name'])?></td></tr><?php endforeach;?>
<?php if(!$members['rows']):?><tr><td colspan="3">Nenhum membro com este filtro.</td></tr><?php endif;?></tbody></table></div>
<div class="bulk-actions">
<?php if($members['page']>1):?><a class="btn btn-light" href="?<?=e(http_build_query(['church'=>$cid,'q'=>$search,'page'=>$members['page']-1]))?>">Anterior</a><?php endif;?>
<?php if($members['page']<$members['pages']):?><a class="btn btn-light" href="?<?=e(http_build_query(['church'=>$cid,'q'=>$search,'page'=>$members['page']+1]))?>">Próxima</a><?php endif;?>
</div>
<div class="bulk-pale">
<label>Quais membros deseja mudar?
<select class="select" name="selection_mode" id="bulk-mode">
<option value="selected">Somente membros marcados acima (até 100)</option>
<option value="unassigned">Todos sem congregação nesta igreja (<?=e((string)$members['unassigned'])?>)</option>
</select></label>
<p>Ao selecionar “Todos sem congregação”, a ação aplica-se a todos os cadastros sem congregação, inclusive os que não aparecem nesta página.</p>
</div>
<label>Nova congregação
<select class="select" name="congregation_id" id="bulk-destination" required>
<option value="">Escolha a congregação</option>
<?php foreach($congregations as $c)if($c['active']):?>
<option value="<?=e($c['id'])?>" data-name="<?=e($c['name'])?>"><?=e($c['name'])?></option>
<?php endif;?></select></label>
<label>Digite exatamente o nome da igreja
<input class="input" name="confirm_church" placeholder="<?=e($church['name'])?>" required></label>
<label>Digite exatamente o nome da congregação de destino
<input class="input" name="confirm_destination" id="bulk-confirm-dest" required></label>
<button class="btn btn-primary">Confirmar transferência em massa</button>
</form></section>
<aside class="card card-pad"><h2>Como funciona</h2>
<p>Você pode marcar vários membros ou vincular automaticamente todos que estão em <strong>Sede / Não informada</strong> à congregação escolhida.</p>
<p class="bulk-pale">A operação grava um histórico para que o Master possa desfazer o lote. Se alguém mudar de congregação manualmente depois, essa mudança não será sobrescrita pelo desfazer.</p>
<?php if(!$congregations):?><p>Nenhuma congregação cadastrada. Cadastre-a no painel da igreja antes de fazer a vinculação.</p><?php endif;?>
</aside></div>
<section class="card card-pad" style="margin-top:16px" id="bulk-history">
<h2>Histórico de transferências</h2>
<div class="bulk-table"><table><thead><tr><th>Data</th><th>Congregação</th><th>Alterados</th><th>Revertidos</th><th>Ação</th></tr></thead><tbody>
<?php foreach($batches as $b):?><tr><td><?=e($b['created_at'])?></td><td><?=e($b['destination_name'])?></td>
<td><?=e((string)$b['moved'])?></td><td><?=e((string)$b['reverted'])?></td><td>
<?php if((int)$b['reverted']<(int)$b['moved']):?>
<form method="post" class="bulk-actions" onsubmit="return confirm('Reverter a transferência deste lote?')">
<?=csrf_field()?><input type="hidden" name="action" value="undo"><input type="hidden" name="church_id" value="<?=e($cid)?>">
<input type="hidden" name="batch_id" value="<?=e($b['batch_id'])?>">
<input class="input" name="confirm_undo" placeholder="Digite DESFAZER" required style="width:140px">
<button class="btn btn-light">Desfazer lote</button></form>
<?php else:?>Revertido<?php endif;?></td></tr><?php endforeach;?>
<?php if(!$batches):?><tr><td colspan="5">Ainda não há transferências em massa nesta igreja.</td></tr><?php endif;?>
</tbody></table></div></section>
<?php endif;?>
<script>
document.getElementById('bulk-all-page')?.addEventListener('change',event=>{
 document.querySelectorAll('.bulk-person').forEach(x=>x.checked=event.target.checked);
});
</script>
<?php View::footer();?>
