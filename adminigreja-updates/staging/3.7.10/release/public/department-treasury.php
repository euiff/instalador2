<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/DepartmentTreasuryService.php';

$user=$auth->requireAbility('department_treasury');
$church=$auth->currentChurch();if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];
$service=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);
$error=null;
$from=(string)($_GET['from']??$_POST['from']??date('Y-m-01'));
$to=(string)($_GET['to']??$_POST['to']??date('Y-m-t'));
try{
 PortalService::date($from);PortalService::date($to);
 if($to<$from)throw new InvalidArgumentException('Corrija o intervalo de datas.');
}catch(Throwable){$from=date('Y-m-01');$to=date('Y-m-t');}
$departments=$service->departments();
$selectedId=(string)($_GET['department']??$_POST['department_id']??($departments[0]['id']??''));
$selected=null;$accounts=[];$entries=[];$summary=[];$general=null;
if($selectedId!=='')try{$selected=$service->department($selectedId);}catch(Throwable $e){$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if(!$selected)throw new RuntimeException('Escolha um departamento válido.');
        if($action==='new_account'){
            $service->createAccount($selectedId,$_POST);
            flash('success','Caixa criado e vinculado ao departamento.');
        }elseif($action==='entry'){
            $service->createEntry($selectedId,$_POST,$_FILES['document_file']??[]);
            flash('success','Movimentação registrada na tesouraria do departamento e na geral, sem duplicidade.');
        }else throw new InvalidArgumentException('Ação desconhecida.');
        redirect('/department-treasury.php?department='.rawurlencode($selectedId).'&from='.rawurlencode($from).'&to='.rawurlencode($to));
    }catch(Throwable $e){
        error_log('department treasury: '.get_class($e).' '.$e->getMessage());
        $error=$e instanceof PDOException?'Não foi possível gravar. Confirme o caixa e os dados do lançamento.':$e->getMessage();
    }
}
try{
    $summary=$service->summary($from,$to);
    if($selected){
        $accounts=$service->accounts($selectedId);
        $entries=$service->entries($selectedId,$from,$to,2500);
    }
    if($auth->can('finance')||$auth->churchRole()==='accountant')$general=$service->generalPostedTotals($from,$to);
}catch(Throwable $e){
    $error=$e instanceof PDOException?'Não foi possível carregar os valores dos departamentos.':$e->getMessage();
}
$selectedSummary=null;
foreach($summary as $s)if($s['id']===$selectedId)$selectedSummary=$s;
$money=static fn(int $cents):string=>'R$ '.number_format($cents/100,2,',','.');
$fmt=static fn(mixed $value):string=>'R$ '.number_format((float)$value,2,',','.');
$categories=['Ofertas','Dízimos','Doações','Eventos','Cantina','Material de limpeza','Material de escritório','Transporte','Equipamentos','Manutenção','Ajuda social','Despesas diversas'];
$kinds=['fiscal_coupon'=>'Cupom fiscal','invoice'=>'Nota fiscal','receipt'=>'Recibo','proof'=>'Comprovante'];
if(($_GET['export']??'')==='csv'&&$selected&&!$error){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="caixa-departamento-'.date('Ymd').'.csv"');
    $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");
    fputcsv($f,['Departamento','Data','Tipo','Conta','Categoria','Descrição','Contraparte','Valor','Situação','Comprovante'],';','"','');
    foreach($entries as $entry){
        $values=[$selected['name'],$entry['transaction_date'],$entry['direction']==='income'?'Entrada':'Saída',
            $entry['account_name']??'',$entry['category'],$entry['description'],$entry['counterparty']??'',$entry['amount'],
            $entry['status'],$entry['has_proof']?'Sim':'Pendente'];
        foreach($values as $i=>$v)if($i!==7&&preg_match('/^\s*[=+@\-]/u',(string)$v))$values[$i]="'".$v;
        fputcsv($f,$values,';','"','');
    }
    fclose($f);exit;
}
View::header('Tesourarias dos Departamentos',$auth,'department-treasury.php');View::flash();
?>
<style>
.dept-money-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:15px 0}
.dept-money-grid .card{padding:16px}.dept-money-grid strong{display:block;font-size:1.55rem}.dept-money-grid small{opacity:.74}
.dept-treasury-controls,.dept-action-buttons{display:flex;align-items:end;flex-wrap:wrap;gap:10px}
.dept-treasury-controls label,.dept-fields label{display:grid;gap:6px}
.dept-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:12px}
.dept-fields .wide{grid-column:1/-1}
.dept-table{overflow-x:auto}.dept-table table{width:100%;min-width:660px;border-collapse:collapse}
.dept-table th,.dept-table td{padding:12px;border-bottom:1px solid var(--line,#e2e8f0);text-align:left;vertical-align:middle}
.dept-dialog{width:min(640px,calc(100vw - 20px));border:0;border-radius:16px;padding:22px;max-height:90vh;overflow:auto}
.dept-dialog::backdrop{background:#0f172a9c}
.dept-actions{margin-top:16px;display:flex;gap:8px;flex-wrap:wrap}
</style>
<section class="module-toolbar">
<div class="module-title"><h1>Tesourarias dos Departamentos</h1>
<p>Caixas individuais, comprovantes e saldos integrados à tesouraria geral da igreja.</p></div>
<div class="actions">
<?php if($service->mayPost()&&$selected):?><button class="btn btn-primary" onclick="document.getElementById('dept-new-entry').showModal()" type="button">+ Novo lançamento</button><?php endif;?>
<?php if($service->mayCreateAccount()&&$selected):?><button class="btn btn-light" onclick="document.getElementById('dept-new-account').showModal()" type="button">+ Criar caixa</button><?php endif;?>
<?php if($auth->can('accounting_portal')):?><a href="/accounting.php" class="btn btn-light">Portal do Contador</a><?php endif;?>
</div></section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="card card-pad"><form class="dept-treasury-controls" method="get" action="/department-treasury.php">
<label>Departamento<select class="select" name="department">
<?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=$selectedId===$d['id']?'selected':''?>><?=e($d['name'])?><?=empty($d['active'])?' (inativo)':''?></option><?php endforeach;?>
</select></label>
<label>Data inicial<input class="input" type="date" name="from" value="<?=e($from)?>" required></label>
<label>Data final<input class="input" type="date" name="to" value="<?=e($to)?>" required></label>
<button class="btn btn-light" type="submit">Filtrar</button>
<?php if($selected):?><a class="btn btn-light" href="?<?=e(http_build_query(['department'=>$selectedId,'from'=>$from,'to'=>$to,'export'=>'csv']))?>">Exportar CSV</a><?php endif;?>
</form></section>
<?php if(!$departments):?>
<section class="card card-pad"><h2>Nenhum departamento vinculado</h2>
<p>O pastor ou a secretaria deve cadastrar o departamento e atribuir os responsáveis. Depois a tesouraria geral pode criar um caixa para ele.</p></section>
<?php endif;?>
<?php if($selected):?>
<div class="dept-money-grid">
<?php foreach([
'opening_cents'=>'Saldo anterior','income_cents'=>'Entradas no período','expense_cents'=>'Saídas no período','closing_cents'=>'Saldo final'
] as $key=>$label):?>
<div class="card"><strong><?=$money((int)($selectedSummary[$key]??0))?></strong><small><?=e($label)?></small></div>
<?php endforeach;?>
<div class="card"><strong><?=e((string)($selectedSummary['missing_proofs']??0))?></strong><small>Despesas sem comprovante</small></div>
</div>
<?php if($general):?>
<section class="card card-pad" style="margin-bottom:15px">
<h2>Consolidado da igreja</h2><p>O total geral já contém as movimentações dos departamentos. Não some novamente os valores.</p>
<div class="dept-action-buttons"><strong>Entradas gerais: <?=$fmt($general['income'])?></strong><strong>Saídas gerais: <?=$fmt($general['expense'])?></strong>
<a class="btn btn-light" href="/finance-control.php">Tesouraria geral</a><a class="btn btn-light" href="/department-report.php?from=<?=e($from)?>&to=<?=e($to)?>">Prestação de contas</a></div></section>
<?php endif;?>
<section class="card card-pad" style="margin-bottom:15px"><h2>Caixas do departamento <?=e($selected['name'])?></h2>
<div class="dept-table"><table><thead><tr><th>Caixa</th><th>Tipo</th><th>Saldo inicial</th><th>Situação</th></tr></thead><tbody>
<?php foreach($accounts as $a):?><tr><td><?=e($a['name'])?></td><td><?=e($a['account_type']==='cash'?'Dinheiro':'Conta bancária')?></td><td><?=$fmt($a['opening_balance'])?></td><td><?=!empty($a['active'])?'Ativo':'Inativo'?></td></tr><?php endforeach;?>
<?php if(!$accounts):?><tr><td colspan="4">Nenhum caixa deste departamento. Peça ao pastor ou tesoureiro para cadastrá-lo.</td></tr><?php endif;?>
</tbody></table></div>
</section>
<section class="card card-pad"><div class="page-head"><div><h2>Livro caixa — <?=e($selected['name'])?></h2><p>Todos os registros lançados aqui já aparecem na tesouraria geral e no contador.</p></div></div>
<div class="dept-table"><table><thead><tr><th>Data</th><th>Movimentação</th><th>Caixa</th><th>Categoria / descrição</th><th>Valor</th><th>Documento</th></tr></thead><tbody>
<?php foreach($entries as $entry):?><tr>
<td><?=e(date('d/m/Y',strtotime($entry['transaction_date'])))?></td><td><?=e($entry['direction']==='income'?'Entrada':'Saída')?> <?=e($entry['status'])?></td>
<td><?=e($entry['account_name']??'—')?></td><td><strong><?=e($entry['category'])?></strong><br><small><?=e($entry['description'])?></small></td>
<td><?=$fmt($entry['amount'])?></td><td><?=$entry['has_proof']?'Anexado':'Pendente'?></td>
</tr><?php endforeach;?>
<?php if(!$entries):?><tr><td colspan="6" class="empty">Nenhuma movimentação neste período.</td></tr><?php endif;?>
</tbody></table></div>
<?php if(count($entries)>=2500):?><p class="muted">Muitos registros. Reduza o período para exportar todos os movimentos.</p><?php endif;?>
</section>
<?php endif;?>
<?php if($service->mayPost()&&$selected):?>
<dialog class="dept-dialog" id="dept-new-entry" aria-labelledby="dept-entry-heading">
<h2 id="dept-entry-heading">Novo lançamento no departamento</h2>
<p>Este registro entra automaticamente no livro caixa geral, sem duplicar a receita.</p>
<form method="post" enctype="multipart/form-data" id="dept-entry-form"><?=csrf_field()?>
<input type="hidden" name="action" value="entry"><input type="hidden" name="department_id" value="<?=e($selectedId)?>">
<input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>">
<div class="dept-fields">
<label>Caixa<select class="select" name="account_id" required>
<option value="">Selecione</option><?php foreach($accounts as $a)if(!empty($a['active'])):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endif;?></select></label>
<label>Tipo<select class="select" name="direction"><option value="income">Entrada</option><option value="expense">Saída</option></select></label>
<label>Data<input class="input" type="date" name="transaction_date" value="<?=date('Y-m-d')?>" required></label>
<label>Valor (R$)<input class="input" name="amount" id="dept-amount" inputmode="decimal" required placeholder="0,00"></label>
<label>Categoria<input class="input" list="dept-categories" name="category" id="dept-category" maxlength="160" required></label>
<label>Fornecedor ou origem<input class="input" name="counterparty" id="dept-supplier" maxlength="190"></label>
<label class="wide">Descrição<input class="input" name="description" maxlength="255" required placeholder="Ex.: alimentos do encontro dos jovens"></label>
<label>Forma de pagamento<select class="select" name="payment_method"><option value="">Não informar</option><option>Dinheiro</option><option>PIX</option><option>Débito</option><option>Crédito</option><option>Transferência</option><option>Boleto</option></select></label>
<label>Número do documento<input class="input" name="document_number" id="dept-doc-number" maxlength="60"></label>
<label>Tipo de documento<select class="select" name="document_type"><?php foreach($kinds as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></label>
<label class="wide">Foto, cupom ou PDF do comprovante<input type="file" name="document_file" id="dept-file" accept="image/*,.pdf,application/pdf" capture="environment"></label>
</div>
<p class="muted">O líder precisa anexar comprovante nas saídas. Os documentos ficam arquivados para a tesouraria geral e o contador.</p>
<div class="dept-actions"><button type="button" class="btn btn-light" onclick="document.getElementById('dept-new-entry').close()">Cancelar</button><button class="btn btn-primary">Registrar movimentação</button></div></form>
</dialog>
<datalist id="dept-categories"><?php foreach($categories as $c):?><option value="<?=e($c)?>"><?php endforeach;?></datalist>
<?php endif;?>
<?php if($service->mayCreateAccount()&&$selected):?>
<dialog class="dept-dialog" id="dept-new-account" aria-labelledby="dept-account-heading">
<h2 id="dept-account-heading">Criar caixa para <?=e($selected['name'])?></h2>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="new_account"><input type="hidden" name="department_id" value="<?=e($selectedId)?>">
<input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>">
<div class="dept-fields"><label>Nome do caixa<input class="input" name="account_name" required maxlength="130" placeholder="Ex.: Caixa do ministério de louvor"></label>
<label>Tipo<select class="select" name="account_type"><option value="cash">Dinheiro</option><option value="bank">Conta bancária</option></select></label></div>
<div class="dept-actions"><button type="button" class="btn btn-light" onclick="document.getElementById('dept-new-account').close()">Cancelar</button><button class="btn btn-primary">Criar caixa</button></div></form>
</dialog>
<?php endif;?>
<?php View::footer(); ?>
