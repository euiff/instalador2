<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/PortalService.php';
$user=$auth->requireLogin();
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');
if(!$auth->can('member_portal')&&!$auth->can('department_portal')){http_response_code(403);exit('Acesso não disponível.');}
$portal=new PortalService($pdo,$auth,(string)$church['id']);
$error=null;
$departments=$auth->can('department_portal')?$portal->departments():[];
$departmentId=trim((string)($_GET['department']??''));
$view=(string)($_GET['view']??($auth->churchRole()==='department_leader'?'department':'personal'));
if(!in_array($view,['department','personal'],true))$view='personal';
if($view==='department'&&!$auth->can('department_portal')){http_response_code(403);exit('Acesso não disponível.');}
if($view==='personal'&&!$auth->can('member_portal')){http_response_code(403);exit('Vincule seu cadastro pessoal em Usuários e permissões.');}
if($view==='department'&&$departmentId===''&&$departments)$departmentId=$departments[0]['id'];
$department=null;$rows=[];$balance='0';
$from=(string)($_GET['from']??date('Y-01-01'));$to=(string)($_GET['to']??date('Y-m-d'));
try {
    PortalService::date($from);PortalService::date($to);
    if($from>$to)throw new InvalidArgumentException('A data inicial deve ser anterior à final.');
    if($view==='department'&&$departmentId!=='')$department=$portal->department($departmentId);
}catch(Throwable $e){http_response_code(400);$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try {
        verify_csrf();
        if($error||!$department||$view!=='department')throw new RuntimeException('Selecione um departamento autorizado.');
        $action=(string)($_POST['action']??'');
        if($action==='entry')$portal->createEntry($departmentId,$_POST);
        elseif($action==='reverse')$portal->reverse($departmentId,(string)($_POST['entry_id']??''),(string)($_POST['reason']??''));
        elseif($action==='enable_cash')$portal->enableCash($departmentId);
        else throw new RuntimeException('Ação inválida.');
        flash('success','Operação registrada com sucesso.');
        redirect('/portal.php?view=department&department='.rawurlencode($departmentId).'&from='.rawurlencode($from).'&to='.rawurlencode($to));
    }catch(Throwable $e){$error=$e instanceof PDOException?'Não foi possível registrar a operação. Confira os dados e tente novamente.':$e->getMessage();}
}
$member=$portal->member();
if(!$error){
    if($view==='personal')$rows=$portal->ledger(null,$from,$to);
    elseif($department){$rows=$portal->ledger($departmentId,$from,$to);$balance=$portal->balance($departmentId);}
}
$income=0;$expense=0;
foreach($rows as $r)if($r['status']==='posted'){
    $cents=(int)str_replace('.','',$r['amount']);
    if($r['direction']==='income')$income+=$cents;else $expense+=$cents;
}
function portal_money(int|float|string $amount): string{return 'R$ '.number_format((float)$amount,2,',','.');}
$title=$view==='department'?'Caixa do departamento':'Minhas contribuições';
View::header($title,$auth,'portal.php');View::flash();
?>
<section class="module-toolbar"><div class="module-title"><h1><?=e($title)?></h1><p><?=e($view==='department'?'Entradas, despesas e prestação de contas do seu ministério.':'Seu histórico de dízimos, ofertas e outras contribuições à igreja.')?></p></div><button type="button" class="btn btn-light no-print" onclick="window.print()">Imprimir / salvar PDF</button></section>
<nav class="portal-tabs no-print" aria-label="Áreas do portal">
<a href="/portal-home.php">Início</a>
<?php if($auth->can('member_portal')):?><a href="/my-profile.php">Meu Cadastro</a><?php endif?>
<?php if($auth->can('member_portal')):?><a href="/portal.php?view=personal" <?=$view==='personal'?'aria-current="page"':''?>>Minhas contribuições</a><?php endif?>
<?php if($auth->can('department_portal')):?><a href="/portal.php?view=department" <?=$view==='department'?'aria-current="page"':''?>>Caixa dos departamentos</a><?php endif?>
<?php if($auth->can('member_board')):?><a href="/board.php">Mural</a><?php endif?>
<?php if($auth->can('media_portal')):?><a href="/media-library.php">Biblioteca</a><a href="/albums.php">Fotos e Vídeos</a><?php endif?>
<?php if($auth->can('study_portal')):?><a href="/studies.php">Estudos</a><?php endif?>
<?php if(!empty($church['donation_enabled'])&&!empty($church['donation_public_token'])):?><a target="_blank" rel="noopener noreferrer" href="/donate.php?t=<?=e($church['donation_public_token'])?>">Contribuir / PIX</a><?php endif?>
</nav>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif?>
<?php if($view==='personal'&&!$member):?><section class="card card-pad empty-state"><h2>Seu acesso está criado</h2><p>A secretaria precisa vincular seu login ao seu cadastro de membro. Assim você poderá consultar somente suas próprias contribuições.</p></section><?php endif?>
<section class="card card-pad no-print"><form method="get" class="portal-filter"><input type="hidden" name="view" value="<?=e($view)?>">
<?php if($view==='department'):?><label>Departamento<select class="select" name="department"><option value="">Selecione</option><?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=$departmentId===$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach?></select></label><?php endif?>
<label>De<input class="input" type="date" name="from" value="<?=e($from)?>" required></label><label>Até<input class="input" type="date" name="to" value="<?=e($to)?>" required></label><button class="btn btn-primary">Aplicar filtros</button></form></section>
<div class="portal-print-heading"><h2><?=e($church['name'])?></h2><p><?=e($department['name']??$member['name']??'Meu acesso')?> · <?=e($from)?> a <?=e($to)?></p></div>
<?php if(!$error&&($member&&$view==='personal'||$department)):?>
<div class="grid stats portal-stats"><div class="card stat"><div class="stat-label"><?=$view==='personal'?'Contribuído no período':'Entradas no período'?></div><div class="stat-value"><?=portal_money($income/100)?></div></div>
<?php if($view==='department'):?><div class="card stat"><div class="stat-label">Despesas no período</div><div class="stat-value"><?=portal_money($expense/100)?></div></div><div class="card stat"><div class="stat-label">Saldo atual do caixa</div><div class="stat-value"><?=portal_money($balance)?></div><small>Saldo inicial e todas as movimentações ativas.</small></div><?php endif?></div>
<section class="card card-pad"><div class="page-head"><h2><?=e($department['name']??'Meu histórico')?></h2><?php if($department&&!empty($department['account_id'])):?><button type="button" class="btn btn-primary no-print" data-open-dialog="department-entry">＋ Novo lançamento</button><?php endif?></div>
<?php if(!$rows):?><div class="empty">Nenhuma movimentação neste período.</div><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Categoria</th><th>Descrição</th><th>Tipo</th><th>Valor</th><th>Situação</th><?php if($department):?><th class="no-print">Ações</th><?php endif?></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=date('d/m/Y',strtotime($r['transaction_date']))?></td><td><?=e($r['category'])?></td><td><?=e($r['description'])?></td><td><?=$r['direction']==='income'?'Entrada':'Saída'?></td><td><?=portal_money($r['amount'])?></td><td><?=$r['status']==='posted'?'Registrado':'Estornado'?></td><?php if($department):?><td class="no-print"><?php if($r['status']==='posted'):?><details><summary>Estornar</summary><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reverse"><input type="hidden" name="entry_id" value="<?=e($r['id'])?>"><label>Motivo<input class="input" name="reason" required maxlength="1000"></label><button class="btn btn-danger">Confirmar estorno</button></form></details><?php else:?><?=e($r['reversal_reason'])?><?php endif?></td><?php endif?></tr><?php endforeach?></tbody></table></div><?php endif?>
</section>
<?php endif?>
<?php if($view==='department'&&!$departments):?><section class="card card-pad empty-state"><?php if($auth->can('finance')):?><h2>Nenhum departamento cadastrado</h2><p>Cadastre os ministérios da igreja para organizar seus caixas e responsáveis.</p><?php if($auth->can('secretary')):?><a class="btn btn-primary" href="/departments.php?new=1">Cadastrar departamento</a><?php endif?><?php else:?><h2>Nenhum departamento autorizado</h2><p>O administrador precisa selecionar seus departamentos em Usuários e permissões.</p><?php endif?></section><?php endif?>
<?php if($department&&empty($department['account_id'])):?><section class="card card-pad"><h2>Caixa ainda não ativado</h2><p>A tesouraria ativa uma conta exclusiva para este departamento. Os lançamentos entrarão também no financeiro geral.</p><?php if($auth->can('finance')):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="enable_cash"><button class="btn btn-primary">Ativar caixa de <?=e($department['name'])?></button></form><?php endif?></section><?php endif?>
<?php if($department&&!empty($department['account_id'])):?>
<dialog id="department-entry" class="app-dialog" aria-labelledby="department-entry-title"><div class="dialog-shell"><header class="dialog-heading"><div><h2 id="department-entry-title">Novo lançamento</h2><p>Caixa de <?=e($department['name'])?></p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button></header><div class="dialog-body"><form method="post">
<?=csrf_field()?><input type="hidden" name="action" value="entry"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(32))?>">
<div class="form-grid"><label>Tipo<select class="select" name="direction"><option value="income">Entrada</option><option value="expense">Saída</option></select></label><label>Data<input class="input" type="date" name="transaction_date" value="<?=date('Y-m-d')?>" required></label><label>Categoria *<input class="input" name="category" required maxlength="160" placeholder="Ex.: Oferta do departamento"></label><label>Valor *<input class="input" name="amount" inputmode="decimal" placeholder="0,00" required></label><label class="full">Descrição *<input class="input" name="description" required maxlength="255"></label></div><p class="muted">O lançamento será registrado uma única vez no caixa do departamento e no financeiro da igreja.</p><button class="btn btn-primary">Registrar movimentação</button></form></div></div></dialog>
<?php endif?>
<?php View::footer();
