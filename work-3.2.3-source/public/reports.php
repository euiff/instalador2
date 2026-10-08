<?php
require __DIR__.'/bootstrap.php';
$auth->requireAbility('reports');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=$church['id'];
$from=(string)($_GET['from']??date('Y-m-01'));
$to=(string)($_GET['to']??date('Y-m-d'));

$q=$pdo->prepare("SELECT
 COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
 COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense,
 COUNT(CASE WHEN status='posted' THEN 1 END) qty
 FROM finance_entries WHERE church_id=? AND transaction_date BETWEEN ? AND ?");
$q->execute([$cid,$from,$to]);$tot=$q->fetch()?:['income'=>0,'expense'=>0,'qty'=>0];

$q=$pdo->prepare("SELECT COALESCE(SUM(opening_balance),0) FROM finance_accounts WHERE church_id=? AND active=1");$q->execute([$cid]);$opening=(float)$q->fetchColumn();
$q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) FROM finance_entries WHERE church_id=? AND transaction_date<?");$q->execute([$cid,$from]);$opening+=(float)$q->fetchColumn();
$income=(float)$tot['income'];$expense=(float)$tot['expense'];$closing=$opening+$income-$expense;

$q=$pdo->prepare("SELECT direction,category,COUNT(*) qty,SUM(amount) total FROM finance_entries WHERE church_id=? AND status='posted' AND transaction_date BETWEEN ? AND ? GROUP BY direction,category ORDER BY direction,total DESC");$q->execute([$cid,$from,$to]);$categories=$q->fetchAll();
$q=$pdo->prepare("SELECT transaction_date day,
 SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END) income,
 SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END) expense
 FROM finance_entries WHERE church_id=? AND transaction_date BETWEEN ? AND ? GROUP BY transaction_date ORDER BY transaction_date DESC");$q->execute([$cid,$from,$to]);$byDay=$q->fetchAll();
$q=$pdo->prepare("SELECT COUNT(*) total,SUM(active=1) active_count,SUM(birth_date IS NOT NULL) with_birth FROM members WHERE church_id=?");$q->execute([$cid]);$memberStats=$q->fetch()?:[];
$q=$pdo->prepare("SELECT COUNT(*) qty,COALESCE(SUM(amount),0) total FROM finance_payables WHERE church_id=? AND status='open' AND due_date<CURDATE()");$q->execute([$cid]);$overdue=$q->fetch()?:['qty'=>0,'total'=>0];
$q=$pdo->prepare("SELECT COUNT(*) qty,COALESCE(SUM(amount),0) total FROM finance_payables WHERE church_id=? AND status='open' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)");$q->execute([$cid]);$dueSoon=$q->fetch()?:['qty'=>0,'total'=>0];

View::header('Relatórios',$auth,'reports.php');?>
<div class="card card-pad" style="margin-bottom:16px"><form method="get" class="toolbar"><div class="field"><label>De</label><input class="input" type="date" name="from" value="<?=e($from)?>"></div><div class="field"><label>Até</label><input class="input" type="date" name="to" value="<?=e($to)?>"></div><button class="btn btn-primary" style="margin-top:17px">Atualizar relatório</button><button class="btn btn-light" type="button" style="margin-top:17px" onclick="window.print()">Imprimir</button></form></div>
<div class="grid stats"><div class="card stat"><div class="stat-label">Saldo inicial</div><div class="stat-value">R$ <?=number_format($opening,2,',','.')?></div></div><div class="card stat"><div class="stat-label">Entradas</div><div class="stat-value">R$ <?=number_format($income,2,',','.')?></div></div><div class="card stat"><div class="stat-label">Saídas</div><div class="stat-value">R$ <?=number_format($expense,2,',','.')?></div></div><div class="card stat"><div class="stat-label">Saldo final</div><div class="stat-value">R$ <?=number_format($closing,2,',','.')?></div></div></div>
<div class="grid two-col"><section class="card card-pad"><div class="page-head"><div><h1 style="font-size:16px">Receitas e despesas por categoria</h1><p>Livro financeiro consolidado</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Categoria</th><th>Registros</th><th>Total</th></tr></thead><tbody><?php foreach($categories as $r):?><tr><td><span class="badge <?=$r['direction']==='income'?'ok':''?>"><?=e($r['direction']==='income'?'Entrada':'Saída')?></span></td><td><strong><?=e($r['category'])?></strong></td><td><?=e((string)$r['qty'])?></td><td>R$ <?=number_format((float)$r['total'],2,',','.')?></td></tr><?php endforeach?></tbody></table></div></section><section class="card card-pad"><div class="page-head"><div><h1 style="font-size:16px">Pendências da Tesouraria</h1><p>O que precisa de atenção</p></div></div><div class="grid stats" style="grid-template-columns:1fr 1fr"><div class="card stat"><div class="stat-label">Contas vencidas</div><div class="stat-value"><?=(int)$overdue['qty']?></div><div class="stat-foot">R$ <?=number_format((float)$overdue['total'],2,',','.')?></div></div><div class="card stat"><div class="stat-label">Vencem em 7 dias</div><div class="stat-value"><?=(int)$dueSoon['qty']?></div><div class="stat-foot">R$ <?=number_format((float)$dueSoon['total'],2,',','.')?></div></div></div><p><strong>Membros ativos:</strong> <?=(int)($memberStats['active_count']??0)?> · <strong>Cadastrados:</strong> <?=(int)($memberStats['total']??0)?></p></section></div>
<section class="card card-pad" style="margin-top:16px"><div class="page-head"><div><h1 style="font-size:16px">Movimentação diária</h1><p>Entradas, saídas e resultado por dia</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Entradas</th><th>Saídas</th><th>Resultado</th></tr></thead><tbody><?php foreach($byDay as $r):$d=(float)$r['income']-(float)$r['expense'];?><tr><td><?=date('d/m/Y',strtotime($r['day']))?></td><td>R$ <?=number_format((float)$r['income'],2,',','.')?></td><td>R$ <?=number_format((float)$r['expense'],2,',','.')?></td><td><strong>R$ <?=number_format($d,2,',','.')?></strong></td></tr><?php endforeach?></tbody></table></div></section>
<style>@media print{.sidebar,.mobile-header,.toolbar button,.sidebar-overlay{display:none!important}.page-wrap{margin:0}.content{padding:0}.card{box-shadow:none}.page-head{margin-bottom:10px}}</style><?php View::footer();?>
