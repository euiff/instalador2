<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/PastoralAgendaService.php';

$user=$auth->requireAbility('pastoral');
$church=$auth->currentChurch();
if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];$uid=(string)$user['id'];
$error=null;$selected=null;$history=[];$listing=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
$service=new PastoralAgendaService($pdo,$auth,$cid,$uid);
try {PastoralAgendaService::ensureSchema($pdo);}
catch(Throwable $e){error_log('pastoral migration: '.$e->getMessage());http_response_code(503);View::header('Agenda pastoral',$auth,'pastoral-agenda.php');echo '<section class="card card-pad"><h1>Agenda pastoral</h1><p>Não foi possível preparar o banco de dados para este módulo. O administrador deve conferir a migração 040.</p></section>';View::footer();exit;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        $id=trim((string)($_POST['case_id']??''));
        if($action==='create')$id=$service->create($_POST);
        elseif($action==='contact')$service->saveEvent($id,$_POST);
        elseif($action==='assign')$service->assign($id,$_POST['assigned_user_id']??null);
        elseif($action==='status')$service->status($id,(string)($_POST['status']??''));
        else throw new InvalidArgumentException('Operação inválida.');
        flash('success','Acompanhamento atualizado.');
        redirect('/pastoral-agenda.php?case='.rawurlencode($id).'#pastoral-detail');
    }catch(Throwable $e){
        error_log('pastoral save: '.get_class($e).' '.$e->getMessage());
        $error=$e instanceof PDOException?'Não foi possível salvar. Confira os dados e tente novamente.':$e->getMessage();
    }
}
$caseId=trim((string)($_GET['case']??($_POST['case_id']??'')));
try{
    if($caseId!==''){$selected=$service->find($caseId);$history=$service->history($caseId);}
    $listing=$service->listing($_GET,(int)($_GET['page']??1),20);
    $summary=$service->summary();
    $assignees=$service->assignees();
}catch(Throwable $e){
    $error=$e instanceof PDOException?'Não foi possível consultar os acompanhamentos.':$e->getMessage();
    $summary=['total'=>0,'active'=>0,'overdue'=>0,'closed'=>0];$assignees=[];
}
$statuses=['open'=>'Aberto','scheduled'=>'Agendado','in_progress'=>'Em andamento','closed'=>'Concluído','cancelled'=>'Cancelado'];
$priorities=['low'=>'Baixa','normal'=>'Normal','high'=>'Alta'];
$contactKinds=['visit'=>'Visita','call'=>'Telefonema','message'=>'Mensagem','note'=>'Anotação'];
View::header('Agenda pastoral',$auth,'pastoral-agenda.php');View::flash();
?>
<style>
.pastoral-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:20px 0}
.pastoral-summary .card{padding:17px}.pastoral-summary strong{display:block;font-size:27px;line-height:1.2}
.pastoral-summary small{color:var(--muted,#64748b)}
.pastoral-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end}
.pastoral-filters label,.pastoral-fields label{display:grid;gap:5px;min-width:160px}
.pastoral-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:14px 0}
.pastoral-fields .full{grid-column:1/-1}
.pastoral-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.pastoral-list{overflow:auto}.pastoral-list table{width:100%;border-collapse:collapse;min-width:680px}
.pastoral-list th,.pastoral-list td{padding:12px 10px;text-align:left;border-bottom:1px solid var(--border,#e2e8f0)}
.pastoral-list thead th{font-size:13px;color:var(--muted,#64748b)}
.pastoral-history{margin:0;padding:0;list-style:none}
.pastoral-history li{padding:12px 0;border-bottom:1px solid var(--border,#e2e8f0)}
.pastoral-dialog{width:min(590px,calc(100vw - 24px));max-height:92vh;overflow:auto;border:0;border-radius:18px;padding:20px;box-shadow:0 22px 70px #0004}
.pastoral-dialog::backdrop{background:#0f172a99}
.pastoral-pager{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap}
</style>
<section class="module-toolbar">
 <div class="module-title"><h1>Agenda pastoral</h1><p>Organize visitas, contatos, acompanhamentos e retornos desta igreja.</p></div>
 <button class="btn btn-primary" type="button" onclick="document.getElementById('pastoral-new-dialog').showModal()">+ Novo acompanhamento</button>
</section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<div class="pastoral-summary">
<?php foreach(['total'=>'Total','active'=>'Em acompanhamento','overdue'=>'Retornos atrasados','closed'=>'Concluídos'] as $key=>$label):?>
<div class="card"><strong><?=e((string)($summary[$key]??0))?></strong><small><?=e($label)?></small></div>
<?php endforeach;?>
</div>
<section class="card card-pad">
 <form method="get" action="/pastoral-agenda.php" class="pastoral-filters">
  <label>Buscar por assunto<input class="input" name="q" value="<?=e((string)($_GET['q']??''))?>" maxlength="180" placeholder="Ex.: visita familiar"></label>
  <label>Situação<select class="input" name="status"><option value="">Todas</option><?php foreach($statuses as $k=>$v):?><option value="<?=e($k)?>" <?=($_GET['status']??'')===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
  <label>Responsável<select class="input" name="assigned"><option value="">Todos</option><?php foreach($assignees as $a):?><option value="<?=e($a['id'])?>" <?=($_GET['assigned']??'')===$a['id']?'selected':''?>><?=e($a['full_name'])?></option><?php endforeach;?></select></label>
  <button class="btn btn-light">Filtrar</button><a class="btn btn-light" href="/pastoral-agenda.php">Limpar</a>
 </form>
</section>
<section class="card card-pad" style="margin-top:15px">
 <div class="page-head"><div><h2>Acompanhamentos</h2><p><?=e((string)$listing['total'])?> registros encontrados</p></div></div>
 <div class="pastoral-list"><table><thead><tr><th>Assunto</th><th>Situação</th><th>Responsável</th><th>Próximo retorno</th><th></th></tr></thead><tbody>
 <?php foreach($listing['rows'] as $row):?><tr>
  <td><strong><?=e($row['subject'])?></strong><br><small><?=e($priorities[$row['priority']]??$row['priority'])?></small></td>
  <td><?=e($statuses[$row['status']]??$row['status'])?></td>
  <td><?=e($row['assigned_name']??'Não definido')?></td>
  <td><?=e($row['next_contact_on']?date('d/m/Y',strtotime($row['next_contact_on'])):'Não agendado')?></td>
  <td><a class="btn btn-light" href="/pastoral-agenda.php?case=<?=rawurlencode($row['id'])?>#pastoral-detail">Abrir</a></td>
 </tr><?php endforeach;?>
 <?php if(!$listing['rows']):?><tr><td colspan="5"><div class="empty">Nenhum acompanhamento encontrado neste filtro.</div></td></tr><?php endif;?>
 </tbody></table></div>
 <div class="pastoral-pager"><span>Página <?=e((string)$listing['page'])?> de <?=e((string)$listing['pages'])?></span><div class="pastoral-actions">
 <?php if($listing['page']>1):?><a class="btn btn-light" href="?<?=e(http_build_query(array_merge($_GET,['page'=>$listing['page']-1,'case'=>null])))?>">Anterior</a><?php endif;?>
 <?php if($listing['page']<$listing['pages']):?><a class="btn btn-light" href="?<?=e(http_build_query(array_merge($_GET,['page'=>$listing['page']+1,'case'=>null])))?>">Próxima</a><?php endif;?>
 </div></div>
</section>
<?php if($selected):?>
<section class="card card-pad" style="margin-top:16px" id="pastoral-detail">
 <div class="page-head"><div><h2><?=e($selected['subject'])?></h2><p><?=e($statuses[$selected['status']]??$selected['status'])?> · <?=e($selected['assigned_name']??'Sem responsável')?></p></div><a class="btn btn-light" href="/pastoral-agenda.php">Fechar detalhes</a></div>
 <div class="grid two-col">
 <div><h3>Histórico de contatos</h3><?php if(!$history):?><p class="muted">Ainda não há registros.</p><?php endif;?>
 <ul class="pastoral-history"><?php foreach($history as $entry):?><li><strong><?=e($contactKinds[$entry['kind']]??$entry['kind'])?> · <?=e(date('d/m/Y',strtotime($entry['happened_on'])))?></strong><p><?=nl2br(e((string)($entry['summary']??'')))?></p></li><?php endforeach;?></ul></div>
 <div>
 <?php if(in_array($selected['status'],['open','scheduled','in_progress'],true)):?>
 <h3>Registrar contato</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="contact"><input type="hidden" name="case_id" value="<?=e($selected['id'])?>">
 <div class="pastoral-fields"><label>Tipo<select class="input" name="kind"><?php foreach($contactKinds as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?></select></label><label>Data<input class="input" type="date" name="happened_on" value="<?=date('Y-m-d')?>" required></label>
 <label>Próximo retorno<input class="input" type="date" name="next_contact_on" value="<?=e($selected['next_contact_on']??'')?>"></label><label class="full">Observações confidenciais<textarea class="input" name="summary" rows="4" maxlength="5000" placeholder="Anotações de acesso restrito"></textarea></label></div>
 <button class="btn btn-primary">Salvar contato</button></form>
 <?php endif;?>
 <h3 style="margin-top:22px">Responsável</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="assign"><input type="hidden" name="case_id" value="<?=e($selected['id'])?>">
 <div class="pastoral-fields"><label>Encaminhar para<select class="input" name="assigned_user_id"><option value="">Sem responsável</option><?php foreach($assignees as $a):?><option value="<?=e($a['id'])?>" <?=$a['id']===$selected['assigned_user_id']?'selected':''?>><?=e($a['full_name'])?></option><?php endforeach;?></select></label></div><button class="btn btn-light">Atualizar responsável</button></form>
 <h3 style="margin-top:22px">Situação</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="status"><input type="hidden" name="case_id" value="<?=e($selected['id'])?>">
 <div class="pastoral-actions"><?php foreach(['open'=>'Reabrir','closed'=>'Concluir','cancelled'=>'Cancelar'] as $s=>$label):?><button class="btn btn-light" type="submit" name="status" value="<?=e($s)?>"><?=e($label)?></button><?php endforeach;?></div></form>
 </div></div>
</section>
<?php endif;?>
<dialog class="pastoral-dialog" id="pastoral-new-dialog" aria-labelledby="pastoral-new-heading">
 <h2 id="pastoral-new-heading">Novo acompanhamento</h2><p class="muted">O registro ficará disponível somente para usuários autorizados desta igreja.</p>
 <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
 <div class="pastoral-fields">
  <label class="full">Assunto<input class="input" name="subject" maxlength="180" placeholder="Ex.: visita à família" required></label>
  <label>Prioridade<select class="input" name="priority"><?php foreach($priorities as $k=>$v):?><option value="<?=e($k)?>" <?=$k==='normal'?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
  <label>Responsável<select class="input" name="assigned_user_id"><option value="">Definir depois</option><?php foreach($assignees as $a):?><option value="<?=e($a['id'])?>"><?=e($a['full_name'])?></option><?php endforeach;?></select></label>
  <label>Próximo retorno<input class="input" type="date" name="next_contact_on"></label>
  <input type="hidden" name="visitor_id" value="<?=e((string)($_GET['visitor']??''))?>">
  <input type="hidden" name="member_id" value="<?=e((string)($_GET['member']??''))?>">
 </div>
 <div class="pastoral-actions"><button type="button" class="btn btn-light" onclick="document.getElementById('pastoral-new-dialog').close()">Cancelar</button><button class="btn btn-primary">Salvar acompanhamento</button></div>
 </form>
</dialog>
<?php if(($_GET['new']??'')==='1'):?><script>document.getElementById('pastoral-new-dialog').showModal()</script><?php endif;?>
<?php View::footer(); ?>
