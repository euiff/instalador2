<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/SmallGroupService.php';

$user=$auth->requireLogin();
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

if(!$auth->can('group_portal')){
    http_response_code(403);
    exit('Você não possui acesso aos Grupos e Células.');
}

$cid=(string)$church['id'];
$service=new SmallGroupService($pdo,$auth,$cid);
$isManager=$service->isManager();
$error=flash('error');
$success=flash('success');

$view=(string)($_GET['view']??'groups');
if(!in_array($view,['groups','reports'],true))$view='groups';

$selectedId=trim((string)($_GET['group']??''));
$selected=null;
try{
    if($selectedId!=='')$selected=$service->group($selectedId);
}catch(Throwable $e){
    $error=$e->getMessage();
    $selectedId='';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='save_group'){
            $id=$service->saveGroup($_POST);
            flash('success','Grupo/célula salvo com sucesso.');
            redirect('/small-groups.php?group='.rawurlencode($id));
        }

        if($action==='save_leaders'){
            $groupId=(string)($_POST['group_id']??'');
            $service->saveLeaders($groupId,(array)($_POST['leader_ids']??[]));
            flash('success','Liderança atualizada.');
            redirect('/small-groups.php?group='.rawurlencode($groupId).'#leaders');
        }

        if($action==='enroll'){
            $groupId=(string)($_POST['group_id']??'');
            $service->enroll($groupId,(string)($_POST['member_id']??''));
            flash('success','Participante vinculado ao grupo.');
            redirect('/small-groups.php?group='.rawurlencode($groupId).'#members');
        }

        if($action==='remove'){
            $groupId=(string)($_POST['group_id']??'');
            $service->remove($groupId,(string)($_POST['member_id']??''));
            flash('success','Participante removido do grupo.');
            redirect('/small-groups.php?group='.rawurlencode($groupId).'#members');
        }

        if($action==='meeting'){
            $groupId=(string)($_POST['group_id']??'');
            $service->saveMeeting($groupId,$_POST);
            flash('success','Relatório de frequência salvo.');
            redirect('/small-groups.php?group='.rawurlencode($groupId).'#meetings');
        }

        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof PDOException
            ?'Não foi possível salvar. Revise os dados e tente novamente.'
            :$e->getMessage();
    }
}

$groups=$service->groups($isManager);

$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);
$memberOptions=$q->fetchAll();

$q=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);
$congregations=$q->fetchAll();

$leaders=[];
$members=[];
$meetings=[];
if($selected){
    $leaders=$service->leaders($selectedId);
    $members=$service->members($selectedId);
    $meetings=$service->meetings($selectedId,60);
}

$from=(string)($_GET['from']??date('Y-01-01'));
$to=(string)($_GET['to']??date('Y-m-d'));
$report=[];
if($view==='reports'){
    try{$report=$service->report($from,$to);}
    catch(Throwable $e){$error=$e->getMessage();}
}

$typeLabels=[
    'cell'=>'Célula',
    'small_group'=>'Pequeno grupo',
    'discipleship'=>'Discipulado',
    'home_group'=>'Grupo nos lares',
    'other'=>'Outro',
];

View::header('Grupos e Células',$auth,'small-groups.php');
View::flash();
?>
<style>
.sg-toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}
.sg-toolbar h1{margin:0 0 5px}.sg-toolbar p{margin:0;color:var(--muted)}
.sg-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.sg-tabs a{padding:9px 13px;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:inherit;background:var(--card)}.sg-tabs a[aria-current="page"]{background:#edf5ff;border-color:#9bc4ef;color:#174e88;font-weight:800}
.sg-grid{display:grid;grid-template-columns:minmax(290px,.85fr) minmax(0,1.65fr);gap:16px;align-items:start}
.sg-list{display:grid;gap:10px}.sg-card{display:block;padding:14px;border:1px solid var(--line);border-radius:13px;text-decoration:none;color:inherit;background:var(--card)}.sg-card:hover{border-color:#9bb9da}.sg-card.active{border-color:#3178b7;box-shadow:0 0 0 1px #3178b7}.sg-card-top{display:flex;justify-content:space-between;gap:10px}.sg-card small{display:block;color:var(--muted);margin-top:5px}
.sg-badge{font-size:10px;padding:4px 7px;border-radius:999px;background:#eef3f8;color:#526277;white-space:nowrap}
.sg-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:12px 0 18px}.sg-summary .card{padding:12px}.sg-summary small{display:block;color:var(--muted);font-size:10px}.sg-summary strong{display:block;margin-top:4px;font-size:20px}
.sg-section{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}
.sg-leaders{display:flex;gap:8px;flex-wrap:wrap}.sg-person-pill{padding:7px 10px;border-radius:999px;background:#f2f6fa;font-size:11px}
.sg-check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:340px;overflow:auto;padding:4px}.sg-check{display:flex;gap:9px;align-items:center;padding:9px 10px;border:1px solid var(--line);border-radius:10px}.sg-check input{margin:0}
.sg-report-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.sg-report-card{padding:15px}.sg-report-card h3{margin:0 0 8px;font-size:15px}.sg-report-metric{display:flex;justify-content:space-between;font-size:11px;padding:5px 0;border-top:1px solid #edf1f5}.sg-report-metric:first-of-type{border-top:0}
@media(max-width:980px){.sg-grid{grid-template-columns:1fr}.sg-summary,.sg-report-grid{grid-template-columns:1fr 1fr}}
@media(max-width:620px){.sg-summary,.sg-report-grid,.sg-check-grid{grid-template-columns:1fr}.sg-toolbar{flex-direction:column}}
</style>

<div class="sg-toolbar">
  <div>
    <h1>Grupos e Células</h1>
    <p><?=$isManager?'Organize líderes, participantes, reuniões e frequência.':'Acompanhe somente os grupos em que você foi definido como líder.'?></p>
  </div>
  <?php if($isManager):?><button class="btn btn-primary" type="button" data-open-dialog="sg-editor">＋ Novo grupo</button><?php endif?>
</div>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<nav class="sg-tabs">
  <a href="/small-groups.php" <?=$view==='groups'?'aria-current="page"':''?>>Grupos</a>
  <a href="/small-groups.php?view=reports" <?=$view==='reports'?'aria-current="page"':''?>>Relatórios de frequência</a>
</nav>

<?php if($view==='reports'):?>
<section class="card card-pad no-print">
  <form method="get" class="portal-filter">
    <input type="hidden" name="view" value="reports">
    <label>De<input class="input" type="date" name="from" value="<?=e($from)?>" required></label>
    <label>Até<input class="input" type="date" name="to" value="<?=e($to)?>" required></label>
    <button class="btn btn-primary">Consultar</button>
    <button class="btn btn-light" type="button" onclick="window.print()">Imprimir / PDF</button>
  </form>
</section>

<div class="sg-report-grid">
<?php foreach($report as $row):
  $meetings=(int)$row['meetings'];
  $presence=(int)$row['total_presence'];
  $avg=$meetings>0?$presence/$meetings:0;
?>
  <section class="card sg-report-card">
    <h3><?=e($row['name'])?></h3>
    <div class="sg-report-metric"><span>Reuniões</span><strong><?=$meetings?></strong></div>
    <div class="sg-report-metric"><span>Presenças</span><strong><?=$presence?></strong></div>
    <div class="sg-report-metric"><span>Média por reunião</span><strong><?=number_format($avg,1,',','.')?></strong></div>
    <div class="sg-report-metric"><span>Visitantes</span><strong><?=(int)$row['visitors']?></strong></div>
  </section>
<?php endforeach?>
<?php if(!$report):?><section class="card card-pad"><div class="empty">Nenhuma reunião encontrada neste período.</div></section><?php endif?>
</div>

<?php else:?>
<div class="sg-grid">
  <section class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:16px">Seus grupos</h2><p><?=count($groups)?> grupo(s)</p></div></div>
    <div class="sg-list">
      <?php foreach($groups as $group):
        $groupLeaders=$service->leaders((string)$group['id']);
      ?>
        <a class="sg-card <?=$selectedId===$group['id']?'active':''?>" href="/small-groups.php?group=<?=e($group['id'])?>">
          <div class="sg-card-top"><strong><?=e($group['name'])?></strong><span class="sg-badge"><?=e($typeLabels[$group['group_type']]??'Grupo')?></span></div>
          <small><?=e($group['meeting_day']?:'Dia não definido')?><?=$group['meeting_time']?' · '.substr((string)$group['meeting_time'],0,5):''?></small>
          <small><?=e(implode(', ',array_column($groupLeaders,'name'))?:'Sem líder definido')?></small>
          <small><?=(int)$group['member_count']?> participantes · <?=(int)$group['meeting_count']?> reuniões</small>
        </a>
      <?php endforeach?>
      <?php if(!$groups):?><div class="empty"><?=$isManager?'Nenhum grupo cadastrado.':'Você ainda não foi definido como líder de nenhum grupo.'?></div><?php endif?>
    </div>
  </section>

  <section>
  <?php if(!$selected):?>
    <div class="card card-pad empty-state">
      <h2>Selecione um grupo</h2>
      <p>Abra um grupo à esquerda para consultar participantes, líderes e frequências.</p>
    </div>
  <?php else:
    $presentTotal=0;$visitorTotal=0;
    foreach($meetings as $meeting){$presentTotal+=(int)$meeting['present_count'];$visitorTotal+=(int)$meeting['visitor_count'];}
  ?>
    <section class="card card-pad">
      <div class="page-head">
        <div>
          <h2 style="margin-bottom:4px"><?=e($selected['name'])?></h2>
          <p><?=e($typeLabels[$selected['group_type']]??'Grupo')?> · <?=e($selected['congregation_name']?:'Igreja geral')?></p>
        </div>
        <?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="sg-editor">Editar grupo</button><?php endif?>
      </div>

      <div class="sg-summary">
        <div class="card"><small>Participantes</small><strong><?=count($members)?></strong></div>
        <div class="card"><small>Reuniões registradas</small><strong><?=count($meetings)?></strong></div>
        <div class="card"><small>Presenças registradas</small><strong><?=$presentTotal?></strong></div>
        <div class="card"><small>Visitantes</small><strong><?=$visitorTotal?></strong></div>
      </div>

      <div class="form-grid">
        <div><strong>Reunião</strong><div class="muted"><?=e($selected['meeting_day']?:'Não definido')?><?=$selected['meeting_time']?' às '.substr((string)$selected['meeting_time'],0,5):''?></div></div>
        <div><strong>Local</strong><div class="muted"><?=e($selected['location_name']?:'Não informado')?></div></div>
        <div class="full"><strong>Endereço</strong><div class="muted"><?=e($selected['address']?:'Não informado')?></div></div>
      </div>

      <div class="sg-section" id="leaders">
        <div class="page-head"><div><h3 style="margin:0">Liderança</h3><p>Até quatro líderes por grupo.</p></div><?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="sg-leaders">Editar líderes</button><?php endif?></div>
        <div class="sg-leaders">
          <?php foreach($leaders as $leader):?><span class="sg-person-pill"><?=e($leader['name'])?></span><?php endforeach?>
          <?php if(!$leaders):?><span class="muted">Nenhum líder definido.</span><?php endif?>
        </div>
      </div>

      <div class="sg-section" id="members">
        <div class="page-head">
          <div><h3 style="margin:0">Participantes</h3><p><?=count($members)?> pessoa(s) vinculada(s).</p></div>
          <button class="btn btn-light" type="button" data-open-dialog="sg-member-add">Adicionar participante</button>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Nome</th><th>Telefone</th><th>Desde</th><th class="no-print"></th></tr></thead><tbody>
        <?php foreach($members as $member):?><tr>
          <td><a href="/member-profile.php?id=<?=e($member['id'])?>"><?=e($member['name'])?></a></td>
          <td><?=e($member['phone'])?></td>
          <td><?=e($member['joined_on']?date('d/m/Y',strtotime($member['joined_on'])):'—')?></td>
          <td class="no-print"><form method="post" onsubmit="return confirm('Remover este participante do grupo?')"><?=csrf_field()?><input type="hidden" name="action" value="remove"><input type="hidden" name="group_id" value="<?=e($selectedId)?>"><input type="hidden" name="member_id" value="<?=e($member['id'])?>"><button class="btn btn-light">Remover</button></form></td>
        </tr><?php endforeach?>
        <?php if(!$members):?><tr><td colspan="4"><div class="empty">Nenhum participante vinculado.</div></td></tr><?php endif?>
        </tbody></table></div>
      </div>

      <div class="sg-section" id="meetings">
        <div class="page-head">
          <div><h3 style="margin:0">Reuniões e frequências</h3><p>Registre presença, visitantes, tema e observações.</p></div>
          <button class="btn btn-primary" type="button" data-open-dialog="sg-meeting">＋ Registrar reunião</button>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Tema</th><th>Presentes</th><th>Visitantes</th><th>Observações</th></tr></thead><tbody>
        <?php foreach($meetings as $meeting):?><tr>
          <td><?=date('d/m/Y',strtotime($meeting['meeting_date']))?></td>
          <td><?=e($meeting['theme']?:'—')?></td>
          <td><?=(int)$meeting['present_count']?></td>
          <td><?=(int)$meeting['visitor_count']?></td>
          <td><?=e($meeting['notes']?:'—')?></td>
        </tr><?php endforeach?>
        <?php if(!$meetings):?><tr><td colspan="5"><div class="empty">Nenhuma reunião registrada.</div></td></tr><?php endif?>
        </tbody></table></div>
      </div>
    </section>
  <?php endif?>
  </section>
</div>
<?php endif?>

<?php if($isManager):?>
<dialog class="app-dialog" id="sg-editor" aria-labelledby="sg-editor-title" <?=isset($_GET['new'])?'open':''?>>
  <div class="dialog-shell">
    <header class="dialog-heading"><div><h2 id="sg-editor-title"><?=$selected?'Editar grupo/célula':'Novo grupo/célula'?></h2><p>Dados gerais, local e frequência de encontro.</p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button></header>
    <div class="dialog-body">
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_group"><input type="hidden" name="id" value="<?=e($selected['id']??'')?>">
        <div class="form-grid">
          <label class="full">Nome *<input class="input" name="name" value="<?=e($selected['name']??'')?>" required maxlength="190"></label>
          <label>Tipo<select class="select" name="group_type"><?php foreach($typeLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($selected['group_type']??'cell')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
          <label>Congregação<select class="select" name="congregation_id"><option value="">Igreja geral</option><?php foreach($congregations as $c):?><option value="<?=e($c['id'])?>" <?=($selected['congregation_id']??'')===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></label>
          <label>Dia da reunião<input class="input" name="meeting_day" value="<?=e($selected['meeting_day']??'')?>" placeholder="Ex.: Quinta-feira"></label>
          <label>Horário<input class="input" type="time" name="meeting_time" value="<?=e(!empty($selected['meeting_time'])?substr((string)$selected['meeting_time'],0,5):'')?>"></label>
          <label>Local<input class="input" name="location_name" value="<?=e($selected['location_name']??'')?>" placeholder="Ex.: Casa do irmão João"></label>
          <label>Endereço<input class="input" name="address" value="<?=e($selected['address']??'')?>"></label>
          <label class="full">Observações<textarea class="textarea" name="notes" maxlength="5000"><?=e($selected['notes']??'')?></textarea></label>
          <label class="full"><input type="checkbox" name="active" value="1" <?=!$selected||!empty($selected['active'])?'checked':''?>> Grupo ativo</label>
        </div>
        <button class="btn btn-primary">Salvar grupo</button>
      </form>
    </div>
  </div>
</dialog>
<?php endif?>

<?php if($selected):?>
<dialog class="app-dialog" id="sg-member-add" aria-labelledby="sg-member-add-title">
  <div class="dialog-shell">
    <header class="dialog-heading"><div><h2 id="sg-member-add-title">Adicionar participante</h2><p><?=e($selected['name'])?></p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button></header>
    <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="enroll"><input type="hidden" name="group_id" value="<?=e($selectedId)?>">
      <label>Membro<select class="select" name="member_id" required><option value="">Selecione...</option><?php foreach($memberOptions as $member):?><option value="<?=e($member['id'])?>"><?=e($member['name'])?></option><?php endforeach?></select></label>
      <button class="btn btn-primary" style="margin-top:12px">Vincular participante</button>
    </form></div>
  </div>
</dialog>

<dialog class="app-dialog" id="sg-meeting" aria-labelledby="sg-meeting-title">
  <div class="dialog-shell">
    <header class="dialog-heading"><div><h2 id="sg-meeting-title">Registrar reunião</h2><p>Marque os participantes presentes.</p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button></header>
    <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="meeting"><input type="hidden" name="group_id" value="<?=e($selectedId)?>">
      <div class="form-grid">
        <label>Data *<input class="input" type="date" name="meeting_date" value="<?=date('Y-m-d')?>" required></label>
        <label>Visitantes<input class="input" type="number" name="visitor_count" min="0" max="999" value="0"></label>
        <label class="full">Tema da reunião<input class="input" name="theme" maxlength="255"></label>
        <label class="full">Observações<textarea class="textarea" name="notes" maxlength="5000"></textarea></label>
      </div>
      <h3>Presença dos participantes</h3>
      <div class="sg-check-grid">
        <?php foreach($members as $member):?><label class="sg-check"><input type="checkbox" name="present_member_ids[]" value="<?=e($member['id'])?>"><span><?=e($member['name'])?></span></label><?php endforeach?>
        <?php if(!$members):?><div class="muted">Adicione participantes antes de registrar presença.</div><?php endif?>
      </div>
      <button class="btn btn-primary" style="margin-top:14px">Salvar frequência</button>
    </form></div>
  </div>
</dialog>

<?php if($isManager):?>
<dialog class="app-dialog" id="sg-leaders" aria-labelledby="sg-leaders-title">
  <div class="dialog-shell">
    <header class="dialog-heading"><div><h2 id="sg-leaders-title">Líderes do grupo</h2><p>Escolha até quatro pessoas.</p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button></header>
    <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_leaders"><input type="hidden" name="group_id" value="<?=e($selectedId)?>">
      <?php $selectedLeaderIds=array_column($leaders,'id'); for($i=0;$i<4;$i++):?>
        <label>Líder <?=$i+1?><select class="select" name="leader_ids[]"><option value="">Não definido</option><?php foreach($memberOptions as $member):?><option value="<?=e($member['id'])?>" <?=($selectedLeaderIds[$i]??'')===$member['id']?'selected':''?>><?=e($member['name'])?></option><?php endforeach?></select></label>
      <?php endfor?>
      <button class="btn btn-primary" style="margin-top:12px">Salvar líderes</button>
    </form></div>
  </div>
</dialog>
<?php endif?>
<?php endif?>

<?php View::footer(); ?>
