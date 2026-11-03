<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MemberDepartmentService.php';
$user=$auth->requireAbility('secretary');
$church=$auth->currentChurch();if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];
MemberDepartmentService::ensure($pdo);
$svc=new MemberDepartmentService($pdo,$auth,$cid,(string)$user['id']);
$error=null;$notice=null;
$departmentId=trim((string)($_GET['department']??$_POST['department_id']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
    verify_csrf();
    $action=(string)($_POST['action']??'assign');
    $memberId=(string)($_POST['member_id']??'');
    if($action==='assign'){
        $status=$svc->assign($memberId,$departmentId,(string)($_POST['role_name']??'participant'));
        flash('success',$status==='pending_login'
          ?'Líder vinculado ao departamento. Falta criar/vincular o login de líder para ativar o portal e o caixa.'
          :'Participação ou liderança vinculada com sucesso.');
    }elseif($action==='remove'){
        $svc->remove($memberId,$departmentId);
        flash('success','Vínculo removido deste departamento.');
    }else throw new InvalidArgumentException('Operação inválida.');
    redirect('/department-people.php?department='.rawurlencode($departmentId));
 }catch(Throwable $e){
    $error=$e instanceof PDOException?'Não foi possível salvar o vínculo. Confira a configuração do departamento.':$e->getMessage();
 }
}
$departments=$svc->departments();
if(!$departmentId&&$departments)$departmentId=(string)$departments[0]['id'];
$people=$svc->members();
$selected=null;
if($departmentId!=='')try{$selected=$svc->department($departmentId);}catch(Throwable $e){$error=$e->getMessage();}
$assignments=$selected?$svc->assignments($departmentId):[];
View::header('Pessoas e Líderes dos Departamentos',$auth,'department-people.php');
View::flash();
?>
<style>
.dp-grid{display:grid;grid-template-columns:minmax(270px,1fr) minmax(0,1.4fr);gap:16px}
.dp-form{display:grid;gap:12px}.dp-form label{display:grid;gap:5px}
.dp-table{overflow:auto}.dp-table table{width:100%;min-width:600px;border-collapse:collapse}
.dp-table td,.dp-table th{text-align:left;padding:12px;border-bottom:1px solid #e2e8f0}
.dp-tag{display:inline-block;border-radius:999px;background:#e9f5f8;padding:4px 9px;font-size:11px;color:#096b87}
.dp-alert{padding:12px;border-radius:10px;background:#fff8e8;border:1px solid #efd79a;font-size:12px}
@media(max-width:920px){.dp-grid{grid-template-columns:1fr}}
</style>
<section class="module-toolbar">
<div class="module-title"><h1>Pessoas e líderes dos departamentos</h1><p>Vincule os membros aos ministérios cadastrados na igreja e indique quem lidera cada um.</p></div>
<div class="actions"><a class="btn btn-light" href="/members.php">Membros</a>
<a class="btn btn-light" href="/users.php">Usuários e permissões</a>
<a class="btn btn-light" href="/department-treasury.php">Tesouraria dos departamentos</a></div></section>
<?php if($error):?><div role="alert" class="alert alert-error"><?=e($error)?></div><?php endif;?>
<section class="card card-pad" style="margin-bottom:15px">
<form method="get" class="dp-form" style="max-width:530px">
<label>Departamento cadastrado
<select class="select" name="department" required>
<?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=$departmentId===$d['id']?'selected':''?>>
<?=e($d['name'])?><?=empty($d['active'])?' (inativo)':''?></option><?php endforeach;?>
</select></label><button class="btn btn-primary" style="justify-self:start">Abrir departamento</button></form>
</section>
<?php if($selected):?>
<div class="dp-grid">
<section class="card card-pad">
<h2>+ Vincular pessoa</h2>
<p>Escolha o membro e marque se é participante ou líder deste departamento.</p>
<form class="dp-form" method="post">
<?=csrf_field()?><input type="hidden" name="action" value="assign">
<input type="hidden" name="department_id" value="<?=e($departmentId)?>">
<label>Membro da igreja
<select class="select" name="member_id" required><option value="">Selecione um membro</option>
<?php foreach($people as $p):?><option value="<?=e($p['id'])?>"><?=e($p['name'])?></option><?php endforeach;?>
</select></label>
<label>Função no departamento
<select class="select" name="role_name">
<option value="participant">Participante / voluntário</option>
<option value="leader">Líder do departamento</option>
</select></label>
<div class="dp-alert">A indicação como líder não cria senha automaticamente. Para entrar no caixa e nos módulos de liderança, a pessoa precisa de um acesso com o perfil <strong>Líder de departamento</strong> em Usuários e Permissões.</div>
<button class="btn btn-primary">Salvar vínculo</button></form>
</section>
<section class="card card-pad">
<div class="page-head"><h2><?=e($selected['name'])?></h2><span class="muted"><?=count($assignments)?> vínculo(s)</span></div>
<div class="dp-table"><table><thead><tr><th>Membro</th><th>Papel</th><th>Portal do líder</th><th>Remover</th></tr></thead><tbody>
<?php foreach($assignments as $row):?>
<tr><td><?=e($row['member_name'])?></td><td><span class="dp-tag"><?=e($row['role_name']==='leader'?'Líder':'Participante')?></span></td>
<td><?php if($row['role_name']==='leader'):?>
<?=($row['user_id']&&$row['church_role']==='department_leader')?'Login de líder vinculado':'Pendente de login de líder'?>
<?php else:?>—<?php endif;?></td>
<td><form method="post" onsubmit="return confirm('Remover este vínculo com o departamento?')">
<?=csrf_field()?><input type="hidden" name="action" value="remove">
<input type="hidden" name="department_id" value="<?=e($departmentId)?>">
<input type="hidden" name="member_id" value="<?=e($row['member_id'])?>">
<button class="btn btn-light" type="submit">Remover</button>
</form></td></tr><?php endforeach;?>
<?php if(!$assignments):?><tr><td colspan="4">Ainda não existem membros vinculados a este departamento.</td></tr><?php endif;?>
</tbody></table></div></section></div>
<?php else:?>
<section class="card card-pad"><h2>Nenhum departamento encontrado</h2><p>Cadastre o ministério primeiro e depois associe os membros.</p></section>
<?php endif;?>
<?php View::footer();?>
