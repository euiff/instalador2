<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/LearningService.php';

$user=$auth->requireAbility('learning_portal');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$cid=(string)$church['id'];
$service=new LearningService($pdo,$auth,$cid);
$isManager=$service->isManager();
$error=flash('error');
$success=flash('success');

$view=(string)($_GET['view']??'classes');
if(!in_array($view,['classes','courses'],true))$view='classes';
if(!$isManager&&$view==='courses')$view='classes';

$selectedId=trim((string)($_GET['class']??''));
$selected=null;
try{
    if($selectedId!=='')$selected=$service->class($selectedId);
}catch(Throwable $e){
    $error=$e->getMessage();
    $selectedId='';
}

$courseEdit=null;
if($isManager&&!empty($_GET['course_edit'])){
    foreach($service->courses(true) as $row)if((string)$row['id']===(string)$_GET['course_edit']){$courseEdit=$row;break;}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='save_course'){
            $id=$service->saveCourse($_POST);
            flash('success','Curso/estudo salvo.');
            redirect('/learning.php?view=courses&course_edit='.rawurlencode($id));
        }
        if($action==='save_class'){
            $id=$service->saveClass($_POST);
            flash('success','Turma salva.');
            redirect('/learning.php?class='.rawurlencode($id));
        }
        if($action==='save_teachers'){
            $classId=(string)($_POST['class_id']??'');
            $service->saveTeachers($classId,(array)($_POST['teacher_ids']??[]));
            flash('success','Professores/líderes atualizados.');
            redirect('/learning.php?class='.rawurlencode($classId).'#teachers');
        }
        if($action==='enroll'){
            $classId=(string)($_POST['class_id']??'');
            $service->enroll($classId,(string)($_POST['member_id']??''));
            flash('success','Aluno matriculado.');
            redirect('/learning.php?class='.rawurlencode($classId).'#students');
        }
        if($action==='enrollment_status'){
            $classId=(string)($_POST['class_id']??'');
            $service->setEnrollmentStatus($classId,(string)($_POST['member_id']??''),(string)($_POST['status']??'enrolled'));
            flash('success','Situação da matrícula atualizada.');
            redirect('/learning.php?class='.rawurlencode($classId).'#students');
        }
        if($action==='lesson'){
            $classId=(string)($_POST['class_id']??'');
            $service->saveLesson($classId,$_POST);
            flash('success','Aula e frequência registradas.');
            redirect('/learning.php?class='.rawurlencode($classId).'#lessons');
        }

        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível salvar. Revise os dados e tente novamente.':$e->getMessage();
    }
}

$classes=$service->classes($isManager);
$courses=$service->courses($isManager);

$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$memberOptions=$q->fetchAll();

$q=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$congregations=$q->fetchAll();

$teachers=[];$students=[];$lessons=[];$myProgress=null;$canManageSelected=false;
if($selected){
    $teachers=$service->teachers($selectedId);
    $lessons=$service->lessons($selectedId);
    $canManageSelected=$service->canManageClass($selectedId);
    if($canManageSelected)$students=$service->students($selectedId);
    else $myProgress=$service->myProgress($selectedId);
}

$statusLabels=[
    'planned'=>'Planejada',
    'open'=>'Matrículas abertas',
    'in_progress'=>'Em andamento',
    'completed'=>'Concluída',
    'cancelled'=>'Cancelada',
];
$enrollmentLabels=[
    'enrolled'=>'Matriculado',
    'completed'=>'Concluído',
    'cancelled'=>'Cancelado',
];

View::header('Ensino e Turmas',$auth,'learning.php');
View::flash();
?>
<style>
.learn-toolbar{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.learn-toolbar h1{margin:0 0 5px}.learn-toolbar p{margin:0;color:var(--muted)}
.learn-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.learn-tabs a{padding:9px 13px;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:inherit;background:var(--card)}.learn-tabs a[aria-current="page"]{background:#edf5ff;border-color:#9bc4ef;color:#174e88;font-weight:800}
.learn-grid{display:grid;grid-template-columns:minmax(290px,.85fr) minmax(0,1.65fr);gap:16px;align-items:start}.learn-list{display:grid;gap:10px}.learn-card{display:block;padding:14px;border:1px solid var(--line);border-radius:13px;text-decoration:none;color:inherit;background:var(--card)}.learn-card.active{border-color:#3178b7;box-shadow:0 0 0 1px #3178b7}.learn-card small{display:block;color:var(--muted);margin-top:4px}
.learn-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0 18px}.learn-summary .card{padding:12px}.learn-summary small{display:block;color:var(--muted);font-size:10px}.learn-summary strong{display:block;font-size:20px;margin-top:3px}
.learn-section{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}.learn-pill{display:inline-block;padding:6px 9px;border-radius:999px;background:#f1f5f9;font-size:11px;margin:2px}
.learn-check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:340px;overflow:auto}.learn-check{display:flex;gap:8px;align-items:center;padding:9px;border:1px solid var(--line);border-radius:9px}.learn-check input{margin:0}
.learn-progress{height:9px;border-radius:999px;background:#e8edf2;overflow:hidden;margin-top:8px}.learn-progress span{display:block;height:100%;background:#3178b7}
@media(max-width:980px){.learn-grid{grid-template-columns:1fr}.learn-summary{grid-template-columns:1fr 1fr}}@media(max-width:620px){.learn-summary,.learn-check-grid{grid-template-columns:1fr}.learn-toolbar{flex-direction:column}}
</style>

<div class="learn-toolbar">
  <div>
    <h1>Ensino, Estudos e Turmas</h1>
    <p><?=$isManager?'Organize cursos, discipulados, professores, alunos, aulas e presença.':($auth->churchRole()==='teacher'?'Suas turmas, alunos, materiais e frequências.':'Seus cursos, aulas, materiais e progresso.')?></p>
  </div>
  <?php if($isManager):?><div class="actions"><button class="btn btn-light" type="button" data-open-dialog="course-editor">＋ Curso/Estudo</button><button class="btn btn-primary" type="button" data-open-dialog="class-editor">＋ Nova turma</button></div><?php endif?>
</div>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<nav class="learn-tabs">
  <a href="/learning.php" <?=$view==='classes'?'aria-current="page"':''?>>Turmas</a>
  <?php if($isManager):?><a href="/learning.php?view=courses" <?=$view==='courses'?'aria-current="page"':''?>>Cursos e Estudos</a><?php endif?>
</nav>

<?php if($view==='courses'&&$isManager):?>
<section class="card card-pad">
  <div class="page-head"><div><h2 style="font-size:16px">Cursos e Estudos</h2><p>Catálogo usado para criar as turmas.</p></div><button class="btn btn-primary" type="button" data-open-dialog="course-editor">＋ Novo curso</button></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Nome</th><th>Categoria</th><th>Carga horária</th><th>Situação</th><th></th></tr></thead><tbody>
  <?php foreach($courses as $course):?><tr>
    <td><strong><?=e($course['title'])?></strong><div class="muted"><?=e(mb_strimwidth((string)($course['description']??''),0,120,'…'))?></div></td>
    <td><?=e($course['category']?:'—')?></td>
    <td><?=e($course['duration_hours']!==null?number_format((float)$course['duration_hours'],1,',','.').' h':'—')?></td>
    <td><span class="badge <?=$course['active']?'ok':'off'?>"><?=$course['active']?'Ativo':'Inativo'?></span></td>
    <td><a class="btn btn-light" href="/learning.php?view=courses&course_edit=<?=e($course['id'])?>">Editar</a></td>
  </tr><?php endforeach?>
  <?php if(!$courses):?><tr><td colspan="5"><div class="empty">Cadastre o primeiro curso ou estudo.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>

<?php else:?>
<div class="learn-grid">
  <section class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:16px">Turmas</h2><p><?=count($classes)?> turma(s) disponível(is)</p></div></div>
    <div class="learn-list">
    <?php foreach($classes as $class):?>
      <a class="learn-card <?=$selectedId===$class['id']?'active':''?>" href="/learning.php?class=<?=e($class['id'])?>">
        <strong><?=e($class['course_title'])?></strong>
        <div><?=e($class['name'])?></div>
        <small><?=e($statusLabels[$class['status']]??$class['status'])?> · <?=(int)$class['student_count']?> aluno(s)</small>
        <small><?=e($class['meeting_info']?:'Encontro não informado')?></small>
      </a>
    <?php endforeach?>
    <?php if(!$classes):?><div class="empty"><?=$isManager?'Nenhuma turma criada ainda.':'Nenhuma turma disponível para o seu acesso.'?></div><?php endif?>
    </div>
  </section>

  <section>
  <?php if(!$selected):?>
    <div class="card card-pad empty-state"><h2>Selecione uma turma</h2><p>Abra uma turma à esquerda para consultar professores, alunos, aulas e progresso.</p></div>
  <?php else:
    $lessonCount=count($lessons);
    $studentCount=(int)$selected['student_count'];
  ?>
    <section class="card card-pad">
      <div class="page-head">
        <div><h2 style="margin-bottom:4px"><?=e($selected['course_title'])?></h2><p><?=e($selected['name'])?> · <?=e($statusLabels[$selected['status']]??$selected['status'])?></p></div>
        <?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="class-editor">Editar turma</button><?php endif?>
      </div>

      <div class="learn-summary">
        <div class="card"><small>Alunos</small><strong><?=$studentCount?></strong></div>
        <div class="card"><small>Aulas registradas</small><strong><?=$lessonCount?></strong></div>
        <div class="card"><small>Início</small><strong style="font-size:14px"><?=e($selected['start_date']?date('d/m/Y',strtotime($selected['start_date'])):'—')?></strong></div>
        <div class="card"><small>Término</small><strong style="font-size:14px"><?=e($selected['end_date']?date('d/m/Y',strtotime($selected['end_date'])):'—')?></strong></div>
      </div>

      <div class="form-grid">
        <div><strong>Congregação</strong><div class="muted"><?=e($selected['congregation_name']?:'Igreja geral')?></div></div>
        <div><strong>Encontros</strong><div class="muted"><?=e($selected['meeting_info']?:'Não informado')?></div></div>
      </div>

      <div class="learn-section" id="teachers">
        <div class="page-head"><div><h3 style="margin:0">Professores / líderes</h3></div><?php if($isManager):?><button class="btn btn-light" type="button" data-open-dialog="teacher-editor">Editar</button><?php endif?></div>
        <?php foreach($teachers as $teacher):?><span class="learn-pill"><?=e($teacher['name'])?></span><?php endforeach?>
        <?php if(!$teachers):?><span class="muted">Nenhum professor definido.</span><?php endif?>
      </div>

      <?php if($canManageSelected):?>
      <div class="learn-section" id="students">
        <div class="page-head"><div><h3 style="margin:0">Alunos</h3><p><?=count(array_filter($students,fn($s)=>$s['status']!=='cancelled'))?> matrícula(s) ativa(s)</p></div><button class="btn btn-light" type="button" data-open-dialog="student-editor">Matricular aluno</button></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Aluno</th><th>Situação</th><th>Frequência</th><th></th></tr></thead><tbody>
        <?php foreach($students as $student):
          $lc=(int)$student['lesson_count'];$pc=(int)$student['present_count'];$pct=$lc>0?round(($pc/$lc)*100):0;
        ?><tr>
          <td><a href="/member-profile.php?id=<?=e($student['id'])?>"><?=e($student['name'])?></a></td>
          <td><?=e($enrollmentLabels[$student['status']]??$student['status'])?></td>
          <td><?=$pc?>/<?=$lc?> · <?=$pct?>%</td>
          <td><form method="post" class="actions"><?=csrf_field()?><input type="hidden" name="action" value="enrollment_status"><input type="hidden" name="class_id" value="<?=e($selectedId)?>"><input type="hidden" name="member_id" value="<?=e($student['id'])?>"><select class="select" name="status"><?php foreach($enrollmentLabels as $key=>$label):?><option value="<?=e($key)?>" <?=$student['status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><button class="btn btn-light">Salvar</button></form></td>
        </tr><?php endforeach?>
        <?php if(!$students):?><tr><td colspan="4"><div class="empty">Nenhum aluno matriculado.</div></td></tr><?php endif?>
        </tbody></table></div>
      </div>
      <?php elseif($myProgress):?>
      <div class="learn-section">
        <h3 style="margin-bottom:6px">Meu progresso</h3>
        <div><strong><?=e($enrollmentLabels[$myProgress['enrollment']['status']]??$myProgress['enrollment']['status'])?></strong> · <?=$myProgress['present_count']?> de <?=$myProgress['lesson_count']?> aula(s) presente</div>
        <div class="learn-progress"><span style="width:<?=min(100,max(0,(float)$myProgress['percentage']))?>%"></span></div>
        <div class="muted" style="margin-top:5px"><?=number_format((float)$myProgress['percentage'],1,',','.')?>% de frequência</div>
      </div>
      <?php endif?>

      <div class="learn-section" id="lessons">
        <div class="page-head"><div><h3 style="margin:0">Aulas e materiais</h3><p>Conteúdo, datas e presença registrada.</p></div><?php if($canManageSelected):?><button class="btn btn-primary" type="button" data-open-dialog="lesson-editor">＋ Registrar aula</button><?php endif?></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Aula</th><th>Material</th><?php if($canManageSelected):?><th>Presentes</th><?php elseif($myProgress):?><th>Minha presença</th><?php endif?></tr></thead><tbody>
        <?php foreach($lessons as $lesson):?><tr>
          <td><?=date('d/m/Y',strtotime($lesson['lesson_date']))?></td>
          <td><strong><?=e($lesson['title'])?></strong><div class="muted"><?=e($lesson['description']?:'')?></div></td>
          <td><?=$lesson['material_url']?'<a target="_blank" rel="noopener noreferrer" href="'.e($lesson['material_url']).'">Abrir material</a>':'—'?></td>
          <?php if($canManageSelected):?><td><?=(int)$lesson['present_count']?></td>
          <?php elseif($myProgress):$att=$myProgress['attendance'][$lesson['id']]??null;?><td><?=$att&&$att['present']!==null?((int)$att['present']===1?'Presente':'Ausente'):'—'?></td><?php endif?>
        </tr><?php endforeach?>
        <?php if(!$lessons):?><tr><td colspan="4"><div class="empty">Nenhuma aula registrada.</div></td></tr><?php endif?>
        </tbody></table></div>
      </div>
    </section>
  <?php endif?>
  </section>
</div>
<?php endif?>

<?php if($isManager):?>
<dialog class="app-dialog" id="course-editor" aria-labelledby="course-title" <?=$courseEdit?'open':''?>>
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="course-title"><?=$courseEdit?'Editar curso/estudo':'Novo curso/estudo'?></h2><p>Catálogo de ensino da igreja.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_course"><input type="hidden" name="id" value="<?=e($courseEdit['id']??'')?>">
    <div class="form-grid">
      <label class="full">Nome *<input class="input" name="title" required maxlength="190" value="<?=e($courseEdit['title']??'')?>"></label>
      <label>Categoria<input class="input" name="category" maxlength="120" value="<?=e($courseEdit['category']??'')?>" placeholder="Ex.: Discipulado"></label>
      <label>Carga horária<input class="input" name="duration_hours" inputmode="decimal" value="<?=e($courseEdit['duration_hours']??'')?>"></label>
      <label class="full">Descrição<textarea class="textarea" name="description" maxlength="10000"><?=e($courseEdit['description']??'')?></textarea></label>
      <label><input type="checkbox" name="active" value="1" <?=!$courseEdit||!empty($courseEdit['active'])?'checked':''?>> Curso ativo</label>
    </div><button class="btn btn-primary">Salvar curso</button>
  </form></div></div>
</dialog>

<dialog class="app-dialog" id="class-editor" aria-labelledby="class-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="class-title"><?=$selected?'Editar turma':'Nova turma'?></h2><p>Datas, local, capacidade e situação.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_class"><input type="hidden" name="id" value="<?=e($selected['id']??'')?>">
    <div class="form-grid">
      <label class="full">Curso/Estudo *<select class="select" name="course_id" required><option value="">Selecione...</option><?php foreach($courses as $course):if(!$course['active']&&($selected['course_id']??'')!==$course['id'])continue;?><option value="<?=e($course['id'])?>" <?=($selected['course_id']??'')===$course['id']?'selected':''?>><?=e($course['title'])?></option><?php endforeach?></select></label>
      <label class="full">Nome da turma *<input class="input" name="name" required value="<?=e($selected['name']??'')?>" placeholder="Ex.: Turma 01 · 2026"></label>
      <label>Congregação<select class="select" name="congregation_id"><option value="">Igreja geral</option><?php foreach($congregations as $c):?><option value="<?=e($c['id'])?>" <?=($selected['congregation_id']??'')===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></label>
      <label>Situação<select class="select" name="status"><?php foreach($statusLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($selected['status']??'open')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
      <label>Data inicial<input class="input" type="date" name="start_date" value="<?=e($selected['start_date']??'')?>"></label>
      <label>Data final<input class="input" type="date" name="end_date" value="<?=e($selected['end_date']??'')?>"></label>
      <label class="full">Dia, horário e local<input class="input" name="meeting_info" maxlength="255" value="<?=e($selected['meeting_info']??'')?>" placeholder="Ex.: Terça, 19h30 · Sala 2"></label>
      <label>Capacidade<input class="input" type="number" min="1" name="capacity" value="<?=e((string)($selected['capacity']??''))?>"></label>
      <label class="full">Observações<textarea class="textarea" name="notes" maxlength="5000"><?=e($selected['notes']??'')?></textarea></label>
    </div><button class="btn btn-primary">Salvar turma</button>
  </form></div></div>
</dialog>
<?php endif?>

<?php if($selected&&$isManager):?>
<dialog class="app-dialog" id="teacher-editor" aria-labelledby="teacher-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="teacher-title">Professores / líderes</h2><p>Escolha até quatro pessoas.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_teachers"><input type="hidden" name="class_id" value="<?=e($selectedId)?>">
    <?php $teacherIds=array_column($teachers,'id');for($i=0;$i<4;$i++):?><label>Professor <?=$i+1?><select class="select" name="teacher_ids[]"><option value="">Não definido</option><?php foreach($memberOptions as $member):?><option value="<?=e($member['id'])?>" <?=($teacherIds[$i]??'')===$member['id']?'selected':''?>><?=e($member['name'])?></option><?php endforeach?></select></label><?php endfor?>
    <button class="btn btn-primary" style="margin-top:12px">Salvar professores</button>
  </form></div></div>
</dialog>
<?php endif?>

<?php if($selected&&$canManageSelected):?>
<dialog class="app-dialog" id="student-editor" aria-labelledby="student-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="student-title">Matricular aluno</h2><p><?=e($selected['name'])?></p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="enroll"><input type="hidden" name="class_id" value="<?=e($selectedId)?>">
    <label>Membro<select class="select" name="member_id" required><option value="">Selecione...</option><?php foreach($memberOptions as $member):?><option value="<?=e($member['id'])?>"><?=e($member['name'])?></option><?php endforeach?></select></label>
    <button class="btn btn-primary" style="margin-top:12px">Matricular</button>
  </form></div></div>
</dialog>

<dialog class="app-dialog" id="lesson-editor" aria-labelledby="lesson-title">
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="lesson-title">Registrar aula</h2><p>Conteúdo, material e presença dos alunos.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="lesson"><input type="hidden" name="class_id" value="<?=e($selectedId)?>">
    <div class="form-grid">
      <label>Data *<input class="input" type="date" name="lesson_date" value="<?=date('Y-m-d')?>" required></label>
      <label>Título *<input class="input" name="title" required maxlength="190"></label>
      <label class="full">Descrição<textarea class="textarea" name="description" maxlength="10000"></textarea></label>
      <label class="full">Link do material<input class="input" type="url" name="material_url" placeholder="https://..."></label>
    </div>
    <h3>Presença</h3>
    <div class="learn-check-grid"><?php foreach($students as $student):if($student['status']==='cancelled')continue;?><label class="learn-check"><input type="checkbox" name="present_member_ids[]" value="<?=e($student['id'])?>"><span><?=e($student['name'])?></span></label><?php endforeach?><?php if(!$students):?><div class="muted">Matricule alunos antes de registrar a frequência.</div><?php endif?></div>
    <button class="btn btn-primary" style="margin-top:14px">Salvar aula e presença</button>
  </form></div></div>
</dialog>
<?php endif?>

<?php View::footer(); ?>
