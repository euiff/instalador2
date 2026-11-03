<?php
/**
 * Included within accounting.php; use the same church scope and authenticated session.
 * Department totals are a breakdown, NOT additional revenue.
 */
require_once __DIR__.'/DepartmentTreasuryService.php';
$deptService=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);
$monthStart=$period.'-01';
$monthEnd=(new DateTimeImmutable($monthStart))->format('Y-m-t');
$deptRows=$deptService->summary($monthStart,$monthEnd);
$departmentTotals=['income_cents'=>0,'expense_cents'=>0,'missing_proofs'=>0];
foreach($deptRows as $deptRow)foreach($departmentTotals as $key=>$v)$departmentTotals[$key]+=(int)$deptRow[$key];
$deptMoney=static fn(int $cents):string=>'R$ '.number_format($cents/100,2,',','.');
?>
<section class="card card-pad acc-section" id="accounting-departments">
<div class="page-head"><div><h2>Tesourarias dos departamentos</h2>
<p>Discriminação das receitas, despesas e saldos por departamento. Estes movimentos já estão incluídos no total da igreja.</p></div>
<a class="btn btn-light" href="/department-treasury.php?from=<?=e($monthStart)?>&to=<?=e($monthEnd)?>">Ver caixas</a></div>
<div class="acc-grid">
<div class="card"><strong><?=$deptMoney($departmentTotals['income_cents'])?></strong><small>Entradas dos departamentos (incluídas no geral)</small></div>
<div class="card"><strong><?=$deptMoney($departmentTotals['expense_cents'])?></strong><small>Saídas dos departamentos (incluídas no geral)</small></div>
<div class="card"><strong><?=e((string)$departmentTotals['missing_proofs'])?></strong><small>Despesas departamentais sem comprovante</small></div>
</div>
<div class="acc-table"><table><thead><tr><th>Departamento</th><th>Saldo anterior</th><th>Entradas</th><th>Saídas</th><th>Saldo final</th><th>Pendências</th></tr></thead><tbody>
<?php foreach($deptRows as $d):?><tr>
<td><?=e($d['name'])?></td><td><?=$deptMoney($d['opening_cents'])?></td>
<td><?=$deptMoney($d['income_cents'])?></td><td><?=$deptMoney($d['expense_cents'])?></td>
<td><?=$deptMoney($d['closing_cents'])?></td><td><?=e((string)$d['missing_proofs'])?></td>
</tr><?php endforeach;?>
<?php if(!$deptRows):?><tr><td colspan="6">Nenhum departamento cadastrado nesta igreja.</td></tr><?php endif;?>
</tbody></table></div>
<p class="muted">Os saldos incluem saldos iniciais dos caixas. Entradas e saídas departamentais já compõem a movimentação geral; não devem ser somadas uma segunda vez.</p>
</section>
