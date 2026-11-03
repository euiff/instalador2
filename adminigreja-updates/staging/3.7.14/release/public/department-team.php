<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/DepartmentDelegationService.php';
$user=$auth->requireAbility('department_team_manage');
$church=$auth->currentChurch();
if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];
$service=new DepartmentDelegationService($pdo,$auth,$cid,(string)$user['id']);
$departments=$service->departments();
$selectedId=trim((string)($_POST['department_id']??$_GET['department']??($departments[0]['id']??'')));
$error=null;$selected=null;$team=[];
if($selectedId!=='')try{
    foreach($departments as $d)if($d['id']===$selectedId){$selected=$d;break;}
    if(!$selected)throw new RuntimeException('Você não lidera este departamento ou não tem permissão para gerenciá-lo.');
}catch(Throwable $e){$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        if(!$selected)throw new RuntimeException('Departamento não autorizado.');
        $action=(string)($_POST['action']??'');
        if(!in_array($action,['appoint','revoke'],true))
            throw new InvalidArgumentException('Ação inválida.');
        $result=$service->setTreasurer($selectedId,(string)($_POST['member_id']??''),$action==='appoint');
        $message=match($result){
            'active'=>'Tesoureiro departamental definido. O login pessoal já tem acesso ao caixa deste departamento.',
            'pending_login'=>'Tesoureiro definido. Peça ao pastor ou à secretaria para criar/vincular o Portal do Membro.',
            default=>'Função de tesoureiro removida. O membro continua participante do departamento.'
        };
        flash('success',$message);
        redirect('/department-team.php?department='.rawurlencode($selectedId));
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível alterar a função. Confira os vínculos da pessoa.':$e->getMessage();
    }
}
if($selected)try{$team=$service->team($selectedId);}catch(Throwable $e){$error=$e->getMessage();}
View::header('Equipe e Tesoureiro do Departamento',$auth,'department-team.php');View::flash();
?>
<style>
.dept-role-filter{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
.dept-role-filter label{display:grid;gap:6px;min-width:245px}
.dept-role-table{overflow-x:auto}
.dept-role-table table{width:100%;min-width:640px;border-collapse:collapse}
.dept-role-table td,.dept-role-table th{padding:11px;border-bottom:1px solid #e1e8ed;text-align:left}
.dept-role-tag{display:inline-block;border-radius:999px;padding:5px 9px;background:#e9f3f7;color:#1a637e;font-size:11px}
.dept-role-warn{background:#fff8eb;border:1px solid #f0dcaf;padding:15px;border-radius:12px}
@media(max-width:640px){.dept-role-filter label{width:100%}}
</style>
<section class="module-toolbar">
  <div class="module-title"><h1>Equipe e tesoureiro do departamento</h1>
    <p>O líder pode indicar quem cuidará das finanças <strong>somente do seu próprio departamento</strong>.</p></div>
  <div class="actions"><a href="/department-treasury.php" class="btn btn-light">Abrir caixa do departamento</a>
    <a href="/portal-home.php" class="btn btn-light">Meu portal</a></div>
</section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="card card-pad" style="margin-bottom:15px">
<form method="get" class="dept-role-filter">
<label>Departamento que você administra
<select class="select" name="department" required>
<?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=$selectedId===$d['id']?'selected':''?>>
<?=e($d['name'])?></option><?php endforeach;?>
</select></label>
<button type="submit" class="btn btn-primary">Abrir equipe</button>
</form></section>
<?php if($selected):?>
<section class="card card-pad">
<div class="page-head"><div><h2><?=e($selected['name'])?></h2>
<p><?=count($team)?> pessoa(s) vinculada(s) a este departamento.</p></div></div>
<div class="dept-role-warn">
<strong>Três funções diferentes:</strong>
<p><strong>Participante:</strong> acompanha seu Portal do Membro, mas não vê o caixa.
<strong>Líder:</strong> administra o próprio ministério e pode indicar o tesoureiro.
<strong>Tesoureiro departamental:</strong> registra entradas, despesas e comprovantes apenas neste caixa — não recebe acesso à Tesouraria Geral.</p>
</div>
<div class="dept-role-table" style="margin-top:15px">
<table><thead><tr><th>Pessoa</th><th>Função no departamento</th><th>Acesso ao caixa</th><th>Ação</th></tr></thead><tbody>
<?php foreach($team as $m):?>
<tr>
<td><strong><?=e($m['name'])?></strong></td>
<td><span class="dept-role-tag"><?=e(match($m['role_name']){
 'leader'=>'Líder','treasurer'=>'Tesoureiro departamental',default=>'Participante'
})?></span></td>
<td><?=e(in_array($m['role_name'],['leader','treasurer'],true)
   ?(!empty($m['login_active'])?'Login vinculado — autorizado':'Pendente de login pessoal')
   :'Sem permissão financeira')?></td>
<td>
<?php if($m['role_name']==='participant'||$m['role_name']==='treasurer'):?>
<form method="post" onsubmit="return confirm('<?=e($m['role_name']==='treasurer'?'Remover a função de tesoureiro deste departamento?':'Designar como tesoureiro somente deste departamento?')?>')">
<?=csrf_field()?>
<input type="hidden" name="department_id" value="<?=e($selectedId)?>">
<input type="hidden" name="member_id" value="<?=e($m['member_id'])?>">
<input type="hidden" name="action" value="<?=e($m['role_name']==='treasurer'?'revoke':'appoint')?>">
<button type="submit" class="btn <?=$m['role_name']==='treasurer'?'btn-light':'btn-primary'?>">
<?=e($m['role_name']==='treasurer'?'Remover tesouraria':'Indicar tesoureiro')?></button>
</form>
<?php else:?>Liderança da igreja<?php endif;?>
</td>
</tr>
<?php endforeach;?>
<?php if(!$team):?><tr><td colspan="4">Ainda não há participantes neste departamento. Peça à secretaria para vincular os membros primeiro.</td></tr><?php endif;?>
</tbody></table></div>
</section>
<?php else:?><section class="card card-pad"><p>Você ainda não lidera um departamento ou não possui permissão para gerenciar a equipe. Peça ao pastor para verificar o vínculo do seu cadastro.</p></section><?php endif;?>
<?php View::footer();?>
