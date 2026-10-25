<?php
require __DIR__.'/bootstrap.php'; require __DIR__.'/lib/CareView.php';
$auth->requireAbility('secretary'); $church=$auth->currentChurch(); if(!$church) redirect('/logout.php');
$care=new ChurchCare($pdo,(string)$church['id']);
$start=(new DateTimeImmutable('first day of this month'))->modify('-11 months'); $months=[];
for($i=0;$i<12;$i++) { $d=$start->modify('+'.$i.' months'); $months[$d->format('Y-m')]=['label'=>$d->format('m/Y'),'members'=>0,'visitors'=>0]; }
$admissions=$care->all("SELECT DATE_FORMAT(COALESCE(membership_date,DATE(created_at)),'%Y-%m') month_key,COUNT(*) total FROM members WHERE church_id=? AND COALESCE(membership_date,DATE(created_at))>=? AND COALESCE(membership_date,DATE(created_at))<=CURDATE() GROUP BY month_key",[$start->format('Y-m-d')]);
foreach($admissions as $r) if(isset($months[$r['month_key']])) $months[$r['month_key']]['members']=(int)$r['total'];
$visits=$care->all("SELECT DATE_FORMAT(first_visit,'%Y-%m') month_key,COUNT(*) total FROM church_visitors WHERE church_id=? AND first_visit>=? AND first_visit<=CURDATE() GROUP BY month_key",[$start->format('Y-m-d')]);
foreach($visits as $r) if(isset($months[$r['month_key']])) $months[$r['month_key']]['visitors']=(int)$r['total'];
$total=$care->all('SELECT COUNT(*) total,SUM(CASE WHEN active=1 THEN 1 ELSE 0 END) active FROM members WHERE church_id=?')[0];
$birthdays=$care->all('SELECT id,name,birth_date,phone FROM members WHERE church_id=? AND active=1 AND MONTH(birth_date)=MONTH(CURDATE()) ORDER BY DAY(birth_date),name');
$max=max(1,...array_column($months,'members'),...array_column($months,'visitors'));
View::header('Pessoas e crescimento',$auth,'church-insights.php'); care_heading('Pessoas e crescimento','Acompanhe as entradas de membros, primeiras visitas e aniversariantes.');
?>
<div class="grid stats"><div class="card stat"><div class="stat-label">Membros ativos</div><div class="stat-value"><?=(int)$total['active']?></div></div><div class="card stat"><div class="stat-label">Membros inativos</div><div class="stat-value"><?=(int)$total['total']-(int)$total['active']?></div></div><div class="card stat"><div class="stat-label">Aniversariantes do mês</div><div class="stat-value"><?=count($birthdays)?></div></div></div>
<section class="card card-pad"><h2>Entradas nos últimos 12 meses</h2><p class="muted">Membros: data de membresia; quando não informada, data do cadastro. Visitantes: primeira visita. São entradas registradas, sem cálculo de crescimento líquido ou histórico de desligamentos.</p><div class="table-wrap"><table class="table"><thead><tr><th>Mês</th><th>Membros</th><th>Visitantes</th><th aria-label="Comparação visual">Comparativo</th></tr></thead><tbody><?php foreach($months as $m):?><tr><td><?=e($m['label'])?></td><td><?=$m['members']?></td><td><?=$m['visitors']?></td><td><div class="insight-bar members" style="width:<?=round($m['members']/$max*100,2)?>%" aria-label="<?=$m['members']?> membros"></div><div class="insight-bar visitors" style="width:<?=round($m['visitors']/$max*100,2)?>%" aria-label="<?=$m['visitors']?> visitantes"></div></td></tr><?php endforeach?></tbody></table></div><p>Azul: membros · Verde: visitantes</p></section>
<section class="card card-pad"><h2>Aniversariantes deste mês</h2><div class="table-wrap"><table class="table"><thead><tr><th>Dia</th><th>Membro</th><th>Telefone</th></tr></thead><tbody><?php if(!$birthdays):?><tr><td colspan="3">Nenhum aniversariante com data de nascimento informada.</td></tr><?php endif?><?php foreach($birthdays as $b):?><tr><td><?=date('d/m',strtotime($b['birth_date']))?></td><td><a href="member-profile.php?id=<?=e($b['id'])?>"><?=e($b['name'])?></a></td><td><?=e($b['phone'])?></td></tr><?php endforeach?></tbody></table></div></section>
<?php View::footer();
