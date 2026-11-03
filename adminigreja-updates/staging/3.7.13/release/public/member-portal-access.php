<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MemberPortalAccessService.php';
$user=$auth->requireAbility('manage_users');
$church=$auth->currentChurch();if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];
$service=new MemberPortalAccessService($pdo,$auth,$cid,(string)$user['id']);
$id=(string)($_GET['member']??$_POST['member_id']??'');
$error=null;$message=null;$member=null;$access=null;
if($id!=='')try{$member=$service->member($id);$access=$service->access($id);}catch(Throwable $e){$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  verify_csrf();
  if(!$member)throw new InvalidArgumentException('Selecione um membro válido.');
  if((string)($_POST['confirm_member']??'')!==(string)$member['name'])
    throw new InvalidArgumentException('Confirme o nome completo do membro.');
  $result=$service->activate($id,(string)($_POST['email']??''),(string)($_POST['initial_password']??''));
  flash('success',$result['new_user']?'Login criado. Informe ao membro o e-mail e a senha inicial em canal privado.':
       'Login existente vinculado a este membro. Ele poderá usar sua senha atual.');
  redirect('/member-portal-access.php?member='.rawurlencode($id));
 }catch(Throwable $e){$error=$e instanceof PDOException?'Erro na ativação. A operação foi revertida.':$e->getMessage();}
}
if($id===''||!$member){
 $q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name LIMIT 2500');
 $q->execute([$cid]);$people=$q->fetchAll(PDO::FETCH_ASSOC);
}else $people=[];
View::header('Acesso ao Portal do Membro',$auth,'member-portal-access.php');View::flash();
?>
<style>.mpa-steps{display:grid;gap:12px}.mpa-steps label{display:grid;gap:6px}
.mpa-info{padding:14px;background:#eef7fa;border:1px solid #d4e7ef;border-radius:12px}
.mpa-warn{padding:12px;background:#fff8e8;border:1px solid #f0deb0;border-radius:12px}
</style>
<section class="module-toolbar">
<div class="module-title"><h1>Portal do Membro — Ativar acesso</h1>
<p>Vincule o login ao cadastro correto, para o membro acompanhar seus próprios dízimos, ofertas e avisos.</p></div>
<a class="btn btn-light" href="/members.php">Voltar para Membros</a>
</section>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?>
<section class="card card-pad" style="max-width:760px">
<?php if(!$member):?>
<form method="get" class="mpa-steps"><label>Selecionar membro ativo
<select class="select" name="member" required><option value="">Escolha a pessoa</option>
<?php foreach($people as $p):?><option value="<?=e($p['id'])?>"><?=e($p['name'])?></option><?php endforeach;?></select></label>
<button class="btn btn-primary" style="justify-self:start">Continuar</button></form>
<?php else:?>
<h2><?=e($member['name'])?></h2>
<p><?=e($church['name'])?> · <?=e($member['email']?:'E-mail ainda não informado')?></p>
<?php if($access):?>
<div class="mpa-info"><strong>O membro já possui um login vinculado.</strong>
<p>Perfil: <?=e($access['church_role'])?> · E-mail: <?=e($access['email'])?>.</p>
<p>Ele acessa <strong><?=e($church['name'])?></strong> pela página de login e pode abrir o Portal do Membro. Os dízimos aparecem somente quando os lançamentos estão vinculados ao cadastro dele.</p>
<a class="btn btn-primary" href="/login.php" target="_blank">Abrir página de login</a>
<a class="btn btn-light" href="/users.php">Gerenciar acesso</a></div>
<?php else:?>
<p>Esta pessoa ainda não possui acesso pessoal vinculado nesta igreja.</p>
<?php if(!$member['active']):?><div class="mpa-warn">Este cadastro está inativo. Reative-o antes de criar o login.</div><?php else:?>
<form method="post" class="mpa-steps"><?=csrf_field()?>
<input type="hidden" name="member_id" value="<?=e($member['id'])?>">
<label>E-mail pessoal do membro<input class="input" name="email" type="email" autocomplete="off" value="<?=e($member['email']??'')?>" required></label>
<label>Senha inicial (mínimo 12 caracteres; somente para conta nova)
<input class="input" name="initial_password" type="password" autocomplete="new-password" minlength="12"></label>
<label>Digite exatamente o nome completo da pessoa que receberá o login
<input class="input" name="confirm_member" autocomplete="off" required></label>
<div class="mpa-warn">Confira o e-mail com o membro antes de ativar. Não envie senhas em grupos. Se ele já possui conta no sistema, usará a senha atual. Uma nova conta recebe a senha inicial informada aqui.</div>
<button class="btn btn-primary" style="justify-self:start">Criar e vincular acesso pessoal</button></form>
<?php endif;?>
<?php endif;?>
<?php endif;?>
</section>
<section class="card card-pad" style="margin-top:16px;max-width:760px">
<h2>O que o membro pode fazer?</h2>
<p>No Portal do Membro, a pessoa pode ver seu cadastro, contribuições registradas em seu nome, avisos do pastor, estudos, cursos, eventos e informações disponibilizadas pela igreja. Ela não vê a tesouraria geral nem dados financeiros de outros membros.</p>
<p>Endereço de entrada: <a href="/login.php" target="_blank"><strong>https://<?=e($_SERVER['HTTP_HOST']??'escaladeproposito.online')?>/login.php</strong></a>.</p>
<p>O portal não inventa históricos anteriores: contribuições do Enuves precisarão ser importadas separadamente ou estar ligadas ao membro nas movimentações financeiras.</p>
</section>
<?php View::footer();?>
