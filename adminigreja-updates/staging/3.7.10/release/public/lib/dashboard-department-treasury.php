<?php
/** Dashboard summary for church owner, pastor or general treasurer only. */
if(!$auth->can('department_treasury'))return;
require_once __DIR__.'/DepartmentTreasuryService.php';
try{
    $ds=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);
    $monthFrom=date('Y-m-01');$monthTo=date('Y-m-t');
    $deptRows=$ds->summary($monthFrom,$monthTo);
    $deptIncome=0;$deptExpense=0;$missing=0;
    foreach($deptRows as $row){$deptIncome+=(int)$row['income_cents'];$deptExpense+=(int)$row['expense_cents'];$missing+=(int)$row['missing_proofs'];}
}catch(Throwable $e){
    error_log('dashboard treasury departments: '.$e->getMessage());
    return;
}
?>
<section class="card card-pad" style="margin:16px 0">
<div class="page-head"><div><h2 style="font-size:17px">Tesourarias dos departamentos</h2><p>Movimentações do mês já incluídas nos totais gerais da igreja.</p></div>
<a class="btn btn-light" href="/department-treasury.php">Abrir caixas</a></div>
<div class="grid stats">
<div class="card stat"><div class="stat-label">Entradas departamentais</div><div class="stat-value">R$ <?=number_format($deptIncome/100,2,',','.')?></div></div>
<div class="card stat"><div class="stat-label">Saídas departamentais</div><div class="stat-value">R$ <?=number_format($deptExpense/100,2,',','.')?></div></div>
<div class="card stat"><div class="stat-label">Despesas sem comprovante</div><div class="stat-value"><?=e((string)$missing)?></div></div>
</div>
</section>
