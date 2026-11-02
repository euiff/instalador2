<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/KidsService.php';
require_once __DIR__.'/lib/MediaLibraryService.php';

$user=$auth->requireAbility('kids_portal');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$cid=(string)$church['id'];
$service=new KidsService($pdo,$auth,$cid);
$mediaLibrary=new MediaLibraryService($pdo,$auth,$cid,__DIR__);
$isManager=$service->isManager();
$error=flash('error');$success=flash('success');

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='save_class'){
            $id=$service->saveClass($_POST);
            flash('success','Turma Kids salva.');
            redirect('/kids.php?view=classes&class='.rawurlencode($id));
        }
        if($action==='save_room'){
            $classId=(string)($_POST['class_id']??'');
            $service->saveRoom($_POST);
            flash('success','Sala salva.');
            redirect('/kids.php?view=classes&class='.rawurlencode($classId));
        }
        if($action==='save_team'){
            $classId=(string)($_POST['class_id']??'');
            $members=(array)($_POST['team_member_ids']??[]);
            $levels=(array)($_POST['team_levels']??[]);
            $rows=[];
            foreach($members as $i=>$memberId)$rows[]=['member_id'=>$memberId,'access_level'=>$levels[$i]??'worker'];
            $service->saveTeam($classId,$rows);
            flash('success','Equipe da turma atualizada.');
            redirect('/kids.php?view=classes&class='.rawurlencode($classId).'#team');
        }
        if($action==='save_child'){
            $id=$service->saveChild($_POST);
            flash('success','Cadastro da criança salvo.');
            redirect('/kids.php?view=children&child='.rawurlencode($id));
        }
        if($action==='save_guardian'){
            $childId=(string)($_POST['child_id']??'');
            $service->saveGuardian($childId,$_POST);
            flash('success','Responsável salvo.');
            redirect('/kids.php?view=children&child='.rawurlencode($childId).'#guardians');
        }
        if($action==='save_program'){
            $id=$service->saveProgram($_POST);
            flash('success','Programação Kids salva.');
            redirect('/kids.php?view=programs&program='.rawurlencode($id));
        }
        if($action==='checkin'){
            $programId=(string)($_POST['program_id']??'');
            $checkin=$service->checkin($programId,(string)($_POST['child_id']??''),trim((string)($_POST['notes']??''))?:null);
            flash('success','Check-in realizado. Código de retirada: '.$checkin['security_code']);
            redirect('/kids.php?view=programs&program='.rawurlencode($programId).'#checkins');
        }
        if($action==='checkout'){
            $programId=(string)($_POST['program_id']??'');
            $service->checkout((string)($_POST['checkin_id']??''),(string)($_POST['security_code']??''),(string)($_POST['guardian_id']??''));
            flash('success','Checkout realizado com segurança.');
            redirect('/kids.php?view=programs&program='.rawurlencode($programId).'#checkins');
        }
        if($action==='record'){
            $programId=(string)($_POST['program_id']??'');
            $service->saveRecord($programId,$_POST);
            flash('success','Registro da programação salvo.');
            redirect('/kids.php?view=programs&program='.rawurlencode($programId).'#records');
        }
        if($action==='approve_request'){
            $programId=(string)($_POST['program_id']??'');
            $checkin=$service->approveRequest((string)($_POST['request_id']??''));
            flash('success','Solicitação aprovada e check-in realizado. Código: '.$checkin['security_code']);
            redirect('/kids.php?view=programs&program='.rawurlencode($programId).'#requests');
        }
        if($action==='reject_request'){
            $programId=(string)($_POST['program_id']??'');
            $service->rejectRequest((string)($_POST['request_id']??''));
            flash('success','Solicitação recusada.');
            redirect('/kids.php?view=programs&program='.rawurlencode($programId).'#requests');
        }
        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível salvar. Revise os dados e tente novamente.':$e->getMessage();
    }
}

$view=(string)($_GET['view']??'dashboard');
if(!in_array($view,['dashboard','classes','children','programs','reports'],true))$view='dashboard';

$classes=$service->classes($isManager);
$programs=$service->programs(false);
$todayPrograms=array_values(array_filter($programs,fn($p)=>$p['program_date']===date('Y-m-d')));

$classId=trim((string)($_GET['class']??''));
$selectedClass=null;$rooms=[];$team=[];
if($classId!==''){
    try{
        $selectedClass=$service->class($classId);
        $rooms=$service->rooms($classId);
        $team=$service->team($classId);
    }catch(Throwable $e){$error=$e->getMessage();}
}

$childSearch=trim((string)($_GET['q']??''));
$children=$service->children($childSearch);
$childId=trim((string)($_GET['child']??''));
$selectedChild=null;$guardians=[];
if($childId!==''){
    try{$selectedChild=$service->child($childId);$guardians=$service->guardians($childId);}
    catch(Throwable $e){$error=$e->getMessage();}
}

$programId=trim((string)($_GET['program']??''));
$selectedProgram=null;$checkins=[];$requests=[];$records=[];
if($programId!==''){
    try{
        $selectedProgram=$service->program($programId);
        $checkins=$service->currentCheckins($programId);
        $requests=$service->pendingRequests($programId);
        $records=$service->records($programId);
    }catch(Throwable $e){$error=$e->getMessage();}
}

$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$memberOptions=$q->fetchAll();

$allRooms=[];
foreach($classes as $class){
    try{foreach($service->rooms((string)$class['id']) as $room)$allRooms[]=$room;}catch(Throwable){}
}

$mediaItems=$mediaLibrary->visibleForCurrentUser();
$publicBase=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'');
$statusLabels=['draft'=>'Rascunho','open'=>'Aberta','closed'=>'Encerrada'];
$levelLabels=['manager'=>'Gestor','leader'=>'Líder','worker'=>'Voluntário'];

$activeCheckins=0;
foreach($programs as $p)$activeCheckins+=(int)$p['checked_in_count'];
$pendingTotal=0;
foreach($programs as $p)$pendingTotal+=(int)$p['pending_requests'];

View::header('Kids',$auth,'kids.php');
View::flash();
?>
<style>
.kids-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.kids-head h1{margin:0 0 5px}.kids-head p{margin:0;color:var(--muted)}
.kids-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:16px}.kids-tabs a{padding:9px 12px;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:inherit;background:var(--card)}.kids-tabs a[aria-current="page"]{background:#edf5ff;border-color:#9bc4ef;color:#174e88;font-weight:800}
.kids-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}.kids-stats .card{padding:14px}.kids-stats small{display:block;color:var(--muted);font-size:10px}.kids-stats strong{display:block;font-size:22px;margin-top:4px}
.kids-grid{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(0,1.5fr);gap:16px;align-items:start}.kids-list{display:grid;gap:8px}.kids-list-card{display:block;padding:12px;border:1px solid var(--line);border-radius:11px;text-decoration:none;color:inherit}.kids-list-card.active{border-color:#3178b7;background:#fbfdff}.kids-list-card small{display:block;color:var(--muted);margin-top:3px}
.kids-section{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}.kids-pill{display:inline-block;padding:6px 9px;background:#eef3f7;border-radius:999px;font-size:10px;margin:2px}
.checkin-card{padding:13px;margin-bottom:9px;border-left:4px solid #2c7}.checkin-card.out{border-left-color:#9aa7b4;opacity:.8}.checkin-code{font-size:19px;font-weight:900;letter-spacing:.12em}
.request-card{padding:12px;border:1px solid #f0d38a;background:#fffaf0;border-radius:11px;margin-bottom:8px}.kids-danger{color:#9a1e1e;font-weight:700}
.kids-public-link{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.kids-public-link input{flex:1;min-width:260px}
@media(max-width:900px){.kids-grid{grid-template-columns:1fr}.kids-stats{grid-template-columns:1fr 1fr}}@media(max-width:620px){.kids-stats{grid-template-columns:1fr}.kids-head{flex-direction:column}}
</style>

<div class="kids-head">
  <div><h1>Kids</h1><p><?=$isManager?'Turmas, salas, equipe, crianças, responsáveis e check-in seguro.':'Acesse somente as turmas e programações Kids em que você faz parte da equipe.'?></p></div>
  <?php if($isManager):?><div class="actions"><button class="btn btn-light" type="button" data-open-dialog="kids-child-editor">＋ Criança</button><button class="btn btn-primary" type="button" data-open-dialog="kids-program-editor">＋ Programação</button></div><?php endif?>
</div>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<nav class="kids-tabs">
  <a href="/kids.php" <?=$view==='dashboard'?'aria-current="page"':''?>>Visão geral</a>
  <a href="/kids.php?view=classes" <?=$view==='classes'?'aria-current="page"':''?>>Turmas e Salas</a>
  <a href="/kids.php?view=children" <?=$view==='children'?'aria-current="page"':''?>>Crianças</a>
  <a href="/kids.php?view=programs" <?=$view==='programs'?'aria-current="page"':''?>>Programações / Check-in</a>
  <a href="/kids.php?view=reports" <?=$view==='reports'?'aria-current="page"':''?>>Relatórios</a>
</nav>

<?php if($view==='dashboard'):?>
<div class="kids-stats">
  <div class="card"><small>Turmas acessíveis</small><strong><?=count($classes)?></strong></div>
  <div class="card"><small>Programações hoje</small><strong><?=count($todayPrograms)?></strong></div>
  <div class="card"><small>Crianças em sala</small><strong><?=$activeCheckins?></strong></div>
  <div class="card"><small>Solicitações pendentes</small><strong><?=$pendingTotal?></strong></div>
</div>

<div class="grid two-col">
  <section class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:16px">Programações de hoje</h2></div></div>
    <?php foreach($todayPrograms as $p):?><div class="list-row"><div><strong><?=e($p['title'])?></strong><small><?=e($p['class_name'])?> · <?=e($p['room_name'])?> · <?=e($statusLabels[$p['status']]??$p['status'])?></small></div><a class="btn btn-light" href="/kids.php?view=programs&program=<?=e($p['id'])?>">Abrir</a></div><?php endforeach?>
    <?php if(!$todayPrograms):?><div class="empty">Nenhuma programação Kids hoje.</div><?php endif?>
  </section>
  <section class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:16px">Acesso da equipe</h2><p>O voluntário vê apenas as turmas em que foi vinculado.</p></div></div>
    <?php foreach($classes as $c):?><div class="list-row"><div><strong><?=e($c['name'])?></strong><small><?=(int)$c['room_count']?> sala(s) · <?=(int)$c['team_count']?> pessoa(s) na equipe</small></div></div><?php endforeach?>
  </section>
</div>

<?php elseif($view==='classes'):?>
<div class="kids-grid">
  <section class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:16px">Turmas</h2></div><?php if($isManager):?><button class="btn btn-primary" type="button" data-open-dialog="kids-class-editor">＋ Turma</button><?php endif?></div>
    <div class="kids-list">
      <?php foreach($classes as $c):?><a class="kids-list-card <?=$classId===$c['id']?'active':''?>" href="/kids.php?view=classes&class=<?=e($c['id'])?>"><strong><?=e($c['name'])?></strong><small><?=(int)$c['room_count']?> sala(s) · <?=(int)$c['team_count']?> na equipe</small></a><?php endforeach?>
      <?php if(!$classes):?><div class="empty">Nenhuma turma Kids disponível.</div><?php endif?>
    </div>
  </section>
  <section>
  <?php if(!$selectedClass):?><div class="card card-pad empty-state"><h2>Selecione uma turma</h2><p>Abra uma turma para ver salas e equipe.</p></div>
  <?php else:?>
    <section class="card card-pad">
      <div class="page-head"><div><h2><?=e($selectedClass['name'])?></h2><p><?=e($selectedClass['description']?:'')?></p></div><?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="kids-class-editor">Editar turma</button><?php endif?></div>
      <div class="kids-section">
        <div class="page-head"><div><h3 style="margin:0">Salas</h3></div><?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="kids-room-editor">＋ Sala</button><?php endif?></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Sala</th><th>Local</th><th>Capacidade</th><th>Status</th></tr></thead><tbody>
          <?php foreach($rooms as $room):?><tr><td><?=e($room['name'])?></td><td><?=e($room['location_name']?:'—')?></td><td><?=e($room['capacity']?:'—')?></td><td><span class="badge <?=$room['active']?'ok':'off'?>"><?=$room['active']?'Ativa':'Inativa'?></span></td></tr><?php endforeach?>
          <?php if(!$rooms):?><tr><td colspan="4"><div class="empty">Nenhuma sala cadastrada.</div></td></tr><?php endif?>
        </tbody></table></div>
      </div>
      <div class="kids-section" id="team">
        <div class="page-head"><div><h3 style="margin:0">Equipe</h3><p>Voluntários e níveis de acesso desta turma.</p></div><?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="kids-team-editor">Editar equipe</button><?php endif?></div>
        <?php foreach($team as $t):?><span class="kids-pill"><?=e($t['name'])?> · <?=e($levelLabels[$t['access_level']]??$t['access_level'])?></span><?php endforeach?>
        <?php if(!$team):?><span class="muted">Nenhuma pessoa vinculada à equipe.</span><?php endif?>
      </div>
    </section>
  <?php endif?>
  </section>
</div>

<?php elseif($view==='children'):?>
<div class="kids-grid">
  <section class="card card-pad">
    <form method="get" class="actions" style="margin-bottom:12px"><input type="hidden" name="view" value="children"><input class="input" name="q" value="<?=e($childSearch)?>" placeholder="Buscar criança ou responsável"><button class="btn btn-light">Buscar</button></form>
    <div class="kids-list">
      <?php foreach($children as $c):?><a class="kids-list-card <?=$childId===$c['id']?'active':''?>" href="/kids.php?view=children&child=<?=e($c['id'])?>"><strong><?=e($c['full_name'])?></strong><small><?=e($c['guardian_name']?:'Sem responsável')?> · <?=e($c['guardian_phone']?:'')?></small></a><?php endforeach?>
      <?php if(!$children):?><div class="empty">Nenhuma criança encontrada.</div><?php endif?>
    </div>
  </section>
  <section>
    <?php if(!$selectedChild):?><div class="card card-pad empty-state"><h2>Selecione uma criança</h2><p>Veja responsáveis, informações importantes e histórico.</p></div>
    <?php else:?>
      <section class="card card-pad">
        <div class="page-head"><div><h2><?=e($selectedChild['full_name'])?></h2><p><?=e($selectedChild['birth_date']?date('d/m/Y',strtotime($selectedChild['birth_date'])):'Data de nascimento não informada')?></p></div><button class="btn btn-light" type="button" data-open-dialog="kids-child-editor">Editar</button></div>
        <div class="form-grid">
          <div><strong>Alergias / restrições</strong><div class="muted"><?=e($selectedChild['allergies']?:'Nenhuma informada')?></div></div>
          <div><strong>Necessidades especiais</strong><div class="muted"><?=e($selectedChild['special_needs']?:'Nenhuma informada')?></div></div>
          <div><strong>Autorização de imagem</strong><div class="muted"><?=$selectedChild['image_authorized']?'Sim':'Não'?></div></div>
          <div class="full"><strong>Observações</strong><div class="muted"><?=e($selectedChild['notes']?:'—')?></div></div>
        </div>
        <div class="kids-section" id="guardians">
          <div class="page-head"><div><h3 style="margin:0">Responsáveis</h3></div><button class="btn btn-light" type="button" data-open-dialog="kids-guardian-editor">＋ Responsável</button></div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Nome</th><th>Telefone</th><th>Vínculo</th><th>Retirada</th></tr></thead><tbody>
          <?php foreach($guardians as $g):?><tr><td><?=e($g['full_name'])?> <?=$g['primary_contact']?'<span class="badge ok">Principal</span>':''?></td><td><?=e($g['phone'])?></td><td><?=e($g['relationship_name']?:'—')?></td><td><?=$g['authorized_pickup']?'Autorizado':'Não autorizado'?></td></tr><?php endforeach?>
          <?php if(!$guardians):?><tr><td colspan="4"><div class="empty">Cadastre pelo menos um responsável.</div></td></tr><?php endif?>
          </tbody></table></div>
        </div>
      </section>
    <?php endif?>
  </section>
</div>

<?php elseif($view==='programs'):?>
<div class="kids-grid">
  <section class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:16px">Programações</h2></div><?php if($isManager):?><button class="btn btn-primary" type="button" data-open-dialog="kids-program-editor">＋ Programação</button><?php endif?></div>
    <div class="kids-list">
      <?php foreach($programs as $p):?><a class="kids-list-card <?=$programId===$p['id']?'active':''?>" href="/kids.php?view=programs&program=<?=e($p['id'])?>"><strong><?=e($p['title'])?></strong><small><?=date('d/m/Y',strtotime($p['program_date']))?> · <?=e($p['class_name'])?> · <?=e($p['room_name'])?></small><small><?=(int)$p['checked_in_count']?> em sala · <?=(int)$p['pending_requests']?> solicitação(ões)</small></a><?php endforeach?>
      <?php if(!$programs):?><div class="empty">Nenhuma programação disponível.</div><?php endif?>
    </div>
  </section>
  <section>
    <?php if(!$selectedProgram):?><div class="card card-pad empty-state"><h2>Selecione uma programação</h2><p>Abra uma programação para realizar check-in e acompanhar a sala.</p></div>
    <?php else:
      $publicUrl=$publicBase.'/kids-checkin.php?t='.rawurlencode($selectedProgram['public_token']);
      $qrUrl='https://quickchart.io/qr?size=300&text='.rawurlencode($publicUrl);
    ?>
      <section class="card card-pad">
        <div class="page-head"><div><h2><?=e($selectedProgram['title'])?></h2><p><?=date('d/m/Y',strtotime($selectedProgram['program_date']))?> · <?=e($selectedProgram['class_name'])?> · <?=e($selectedProgram['room_name'])?></p></div><span class="badge <?=$selectedProgram['status']==='open'?'ok':'off'?>"><?=e($statusLabels[$selectedProgram['status']]??$selectedProgram['status'])?></span></div>

        <div class="kids-public-link">
          <input class="input" readonly value="<?=e($publicUrl)?>" onclick="this.select()">
          <a class="btn btn-light" target="_blank" rel="noopener noreferrer" href="<?=e($qrUrl)?>">Abrir QR</a>
          <a class="btn btn-light" target="_blank" href="<?=e($publicUrl)?>">Testar link público</a>
        </div>

        <div class="kids-section" id="requests">
          <div class="page-head"><div><h3 style="margin:0">Solicitações públicas</h3><p>O responsável solicita e a equipe aprova.</p></div></div>
          <?php foreach($requests as $req):?><div class="request-card"><strong><?=e($req['child_name'])?></strong> · <?=e($req['guardian_name'])?> · <?=e($req['guardian_phone'])?><div class="muted"><?=e($req['birth_date']?date('d/m/Y',strtotime($req['birth_date'])):'Sem nascimento')?> · <?=e($req['request_type']==='existing'?'Cadastro localizado':'Visitante')?></div><div class="actions" style="margin-top:8px"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="approve_request"><input type="hidden" name="program_id" value="<?=e($programId)?>"><input type="hidden" name="request_id" value="<?=e($req['id'])?>"><button class="btn btn-primary">Aprovar e fazer check-in</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reject_request"><input type="hidden" name="program_id" value="<?=e($programId)?>"><input type="hidden" name="request_id" value="<?=e($req['id'])?>"><button class="btn btn-light">Recusar</button></form></div></div><?php endforeach?>
          <?php if(!$requests):?><div class="muted">Nenhuma solicitação pendente.</div><?php endif?>
        </div>

        <div class="kids-section" id="checkins">
          <div class="page-head"><div><h3 style="margin:0">Crianças na programação</h3><p><?=count(array_filter($checkins,fn($c)=>$c['status']==='checked_in'))?> atualmente em sala.</p></div><?php if($selectedProgram['status']==='open'):?><button class="btn btn-primary" type="button" data-open-dialog="kids-checkin-editor">＋ Check-in</button><?php endif?></div>
          <?php foreach($checkins as $ci):$gs=$service->guardians((string)$ci['child_id']);?>
            <div class="card checkin-card <?=$ci['status']==='checked_out'?'out':''?>">
              <div class="page-head"><div><strong><?=e($ci['child_name'])?></strong><div class="muted">Entrada: <?=date('H:i',strtotime($ci['checkin_at']))?><?=$ci['checkout_at']?' · Saída: '.date('H:i',strtotime($ci['checkout_at'])):''?></div></div><div class="checkin-code"><?=e($ci['security_code'])?></div></div>
              <?php if($ci['status']==='checked_in'):?><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="checkout"><input type="hidden" name="program_id" value="<?=e($programId)?>"><input type="hidden" name="checkin_id" value="<?=e($ci['id'])?>"><label>Código recebido pelo responsável<input class="input" name="security_code" required inputmode="numeric" maxlength="12"></label><label>Responsável autorizado<select class="select" name="guardian_id" required><option value="">Selecione...</option><?php foreach($gs as $g):if(!$g['authorized_pickup'])continue;?><option value="<?=e($g['id'])?>"><?=e($g['full_name'])?> · <?=e($g['relationship_name']?:'Responsável')?></option><?php endforeach?></select></label><div class="full"><button class="btn btn-light">Realizar checkout</button></div></form><?php endif?>
            </div>
          <?php endforeach?>
          <?php if(!$checkins):?><div class="empty">Nenhum check-in nesta programação.</div><?php endif?>
        </div>

        <div class="kids-section" id="records">
          <div class="page-head"><div><h3 style="margin:0">Registros da aula</h3><p>Observações gerais, pedagógicas, comportamentais ou incidentes.</p></div><button class="btn btn-light" type="button" data-open-dialog="kids-record-editor">＋ Registro</button></div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Criança</th><th>Registro</th><th>Família</th></tr></thead><tbody><?php foreach($records as $rec):?><tr><td><?=date('d/m H:i',strtotime($rec['created_at']))?></td><td><?=e($rec['record_type'])?></td><td><?=e($rec['child_name']?:'Geral')?></td><td><?=e($rec['content'])?></td><td><?=$rec['visible_to_guardian']?'Visível':'Interno'?></td></tr><?php endforeach?><?php if(!$records):?><tr><td colspan="5"><div class="empty">Nenhum registro.</div></td></tr><?php endif?></tbody></table></div>
        </div>
      </section>
    <?php endif?>
  </section>
</div>

<?php elseif($view==='reports'):?>
<section class="card card-pad">
  <div class="page-head"><div><h2>Relatório Kids</h2><p>Histórico das programações disponíveis ao seu acesso.</p></div><button class="btn btn-light" onclick="window.print()">Imprimir / PDF</button></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Programação</th><th>Turma / Sala</th><th>Status</th><th>Em sala</th><th>Solicitações</th></tr></thead><tbody>
    <?php foreach($programs as $p):?><tr><td><?=date('d/m/Y',strtotime($p['program_date']))?></td><td><?=e($p['title'])?></td><td><?=e($p['class_name'])?> · <?=e($p['room_name'])?></td><td><?=e($statusLabels[$p['status']]??$p['status'])?></td><td><?=(int)$p['checked_in_count']?></td><td><?=(int)$p['pending_requests']?></td></tr><?php endforeach?>
  </tbody></table></div>
</section>
<?php endif?>

<?php if($isManager):?>
<dialog class="app-dialog" id="kids-class-editor" aria-labelledby="kids-class-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-class-title"><?=$selectedClass?'Editar turma':'Nova turma Kids'?></h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_class"><input type="hidden" name="id" value="<?=e($selectedClass['id']??'')?>"><div class="form-grid"><label class="full">Nome *<input class="input" name="name" required value="<?=e($selectedClass['name']??'')?>"></label><label>Idade mínima (meses)<input class="input" type="number" min="0" name="min_age_months" value="<?=e((string)($selectedClass['min_age_months']??''))?>"></label><label>Idade máxima (meses)<input class="input" type="number" min="0" name="max_age_months" value="<?=e((string)($selectedClass['max_age_months']??''))?>"></label><label class="full">Descrição<input class="input" name="description" value="<?=e($selectedClass['description']??'')?>"></label><label><input type="checkbox" name="active" value="1" <?=!$selectedClass||!empty($selectedClass['active'])?'checked':''?>> Ativa</label></div><button class="btn btn-primary">Salvar turma</button></form></div></div>
</dialog>

<?php if($selectedClass):?>
<dialog class="app-dialog" id="kids-room-editor" aria-labelledby="kids-room-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-room-title">Nova sala</h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_room"><input type="hidden" name="class_id" value="<?=e($selectedClass['id'])?>"><div class="form-grid"><label class="full">Nome *<input class="input" name="name" required></label><label>Local<input class="input" name="location_name"></label><label>Capacidade<input class="input" type="number" min="1" name="capacity"></label><label><input type="checkbox" name="active" value="1" checked> Ativa</label></div><button class="btn btn-primary">Salvar sala</button></form></div></div>
</dialog>

<dialog class="app-dialog" id="kids-team-editor" aria-labelledby="kids-team-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-team-title">Equipe da turma</h2><p>Até oito pessoas por edição; salve novamente para alterar.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_team"><input type="hidden" name="class_id" value="<?=e($selectedClass['id'])?>">
    <?php for($i=0;$i<8;$i++):$t=$team[$i]??null;?><div class="form-grid" style="margin-bottom:8px"><label>Pessoa<select class="select" name="team_member_ids[]"><option value="">Não definido</option><?php foreach($memberOptions as $m):?><option value="<?=e($m['id'])?>" <?=($t['id']??'')===$m['id']?'selected':''?>><?=e($m['name'])?></option><?php endforeach?></select></label><label>Nível<select class="select" name="team_levels[]"><?php foreach($levelLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($t['access_level']??'worker')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label></div><?php endfor?>
    <button class="btn btn-primary">Salvar equipe</button>
  </form></div></div>
</dialog>
<?php endif?>

<dialog class="app-dialog" id="kids-program-editor" aria-labelledby="kids-program-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-program-title">Nova programação Kids</h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_program"><div class="form-grid"><label>Turma *<select class="select" name="class_id" id="kids-program-class" required><option value="">Selecione...</option><?php foreach($classes as $c):?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach?></select></label><label>Sala *<select class="select" name="room_id" id="kids-program-room" required><option value="">Selecione a turma</option><?php foreach($allRooms as $room):if(!$room['active'])continue;?><option value="<?=e($room['id'])?>" data-class="<?=e($room['class_id'])?>"><?=e($room['name'])?></option><?php endforeach?></select></label><label class="full">Título *<input class="input" name="title" required placeholder="Ex.: Culto da Família · Kids"></label><label>Data *<input class="input" type="date" name="program_date" value="<?=date('Y-m-d')?>" required></label><label>Status<select class="select" name="status"><?php foreach($statusLabels as $key=>$label):?><option value="<?=e($key)?>" <?=$key==='open'?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label><label>Início<input class="input" type="time" name="start_time"></label><label>Término<input class="input" type="time" name="end_time"></label><label class="full">Observações<textarea class="textarea" name="notes"></textarea></label></div><button class="btn btn-primary">Criar programação</button></form></div></div>
</dialog>
<?php endif?>

<dialog class="app-dialog" id="kids-child-editor" aria-labelledby="kids-child-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-child-title"><?=$selectedChild?'Editar criança':'Nova criança'?></h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_child"><input type="hidden" name="id" value="<?=e($selectedChild['id']??'')?>"><div class="form-grid"><label class="full">Nome *<input class="input" name="full_name" required value="<?=e($selectedChild['full_name']??'')?>"></label><label>Nascimento<input class="input" type="date" name="birth_date" value="<?=e($selectedChild['birth_date']??'')?>"></label><label>Sexo / gênero<input class="input" name="gender" value="<?=e($selectedChild['gender']??'')?>"></label><label class="full">Alergias / restrições alimentares<textarea class="textarea" name="allergies"><?=e($selectedChild['allergies']??'')?></textarea></label><label class="full">Necessidades especiais / cuidados<textarea class="textarea" name="special_needs"><?=e($selectedChild['special_needs']??'')?></textarea></label><label class="full">Observações<textarea class="textarea" name="notes"><?=e($selectedChild['notes']??'')?></textarea></label><label><input type="checkbox" name="image_authorized" value="1" <?=!empty($selectedChild['image_authorized'])?'checked':''?>> Autorização de imagem</label></div><button class="btn btn-primary">Salvar criança</button></form></div></div>
</dialog>

<?php if($selectedChild):?>
<dialog class="app-dialog" id="kids-guardian-editor" aria-labelledby="kids-guardian-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-guardian-title">Novo responsável</h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_guardian"><input type="hidden" name="child_id" value="<?=e($selectedChild['id'])?>"><div class="form-grid"><label>Nome *<input class="input" name="full_name" required></label><label>Telefone *<input class="input" name="phone" required></label><label>Vínculo<input class="input" name="relationship_name" placeholder="Ex.: Mãe, pai, avó"></label><label><input type="checkbox" name="primary_contact" value="1"> Contato principal</label><label><input type="checkbox" name="authorized_pickup" value="1" checked> Autorizado para retirada</label></div><button class="btn btn-primary">Salvar responsável</button></form></div></div>
</dialog>
<?php endif?>

<?php if($selectedProgram):?>
<dialog class="app-dialog" id="kids-checkin-editor" aria-labelledby="kids-checkin-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-checkin-title">Check-in assistido</h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="checkin"><input type="hidden" name="program_id" value="<?=e($programId)?>"><label>Criança<select class="select" name="child_id" required><option value="">Selecione...</option><?php foreach($children as $c):?><option value="<?=e($c['id'])?>"><?=e($c['full_name'])?><?=!empty($c['birth_date'])?' · '.date('d/m/Y',strtotime($c['birth_date'])):''?></option><?php endforeach?></select></label><label>Observação<input class="input" name="notes"></label><button class="btn btn-primary" style="margin-top:12px">Realizar check-in</button></form></div></div>
</dialog>

<dialog class="app-dialog" id="kids-record-editor" aria-labelledby="kids-record-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="kids-record-title">Registro da aula</h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header><div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="record"><input type="hidden" name="program_id" value="<?=e($programId)?>"><div class="form-grid"><label>Tipo<select class="select" name="record_type"><option value="general">Geral</option><option value="pedagogical">Pedagógico</option><option value="behavior">Comportamental</option><option value="incident">Incidente</option></select></label><label>Criança<select class="select" name="child_id"><option value="">Registro geral da turma</option><?php foreach($checkins as $ci):?><option value="<?=e($ci['child_id'])?>"><?=e($ci['child_name'])?></option><?php endforeach?></select></label><label class="full">Registro *<textarea class="textarea" name="content" required></textarea></label><label class="full">Mídia da Biblioteca<select class="select" name="media_item_id"><option value="">Nenhuma</option><?php foreach($mediaItems as $m):?><option value="<?=e($m['id'])?>"><?=e($m['title'])?> · <?=e($m['media_type'])?></option><?php endforeach?></select></label><label><input type="checkbox" name="visible_to_guardian" value="1"> Pode ser mostrado à família</label></div><button class="btn btn-primary">Salvar registro</button></form></div></div>
</dialog>
<?php endif?>

<script>
(function(){
  const cls=document.getElementById('kids-program-class');
  const room=document.getElementById('kids-program-room');
  function filterRooms(){
    if(!cls||!room)return;
    const value=cls.value;
    [...room.options].forEach((o,i)=>{if(i===0)return;o.hidden=o.dataset.class!==value;});
    if(room.selectedOptions[0]?.hidden)room.value='';
  }
  cls?.addEventListener('change',filterRooms);filterRooms();
})();
</script>

<?php View::footer(); ?>
