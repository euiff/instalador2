<?php
require __DIR__.'/bootstrap.php';
$user=$auth->requireAbility('dashboard');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];

$canSecretary=$auth->can('secretary');
$canFinance=$auth->can('finance');

$stats=[];
$recentMembers=[];
$nextEvents=[];
if($canSecretary){
    $queries=[
        'members'=>'SELECT COUNT(*) FROM members WHERE church_id=? AND active=1',
        'congregations'=>'SELECT COUNT(*) FROM congregations WHERE church_id=? AND active=1',
        'prayers'=>"SELECT COUNT(*) FROM prayer_requests WHERE church_id=? AND status='pending'",
        'events'=>'SELECT COUNT(*) FROM events WHERE church_id=? AND active=1 AND event_date>=NOW()',
    ];
    foreach($queries as $key=>$sql){
        $q=$pdo->prepare($sql);$q->execute([$cid]);$stats[$key]=(int)$q->fetchColumn();
    }
    $q=$pdo->prepare('SELECT name,phone,created_at FROM members WHERE church_id=? ORDER BY created_at DESC LIMIT 6');
    $q->execute([$cid]);$recentMembers=$q->fetchAll();
    $q=$pdo->prepare('SELECT title,event_date,location FROM events WHERE church_id=? AND active=1 AND event_date>=NOW() ORDER BY event_date ASC LIMIT 5');
    $q->execute([$cid]);$nextEvents=$q->fetchAll();
}

$monthIncome=0.0;$monthExpense=0.0;$monthResult=0.0;$overduePayables=0;
if($canFinance){
    $q=$pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
      COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense
      FROM finance_entries
      WHERE church_id=? AND transaction_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND transaction_date<=CURDATE()");
    $q->execute([$cid]);$monthFinance=$q->fetch()?:['income'=>0,'expense'=>0];
    $monthIncome=(float)$monthFinance['income'];
    $monthExpense=(float)$monthFinance['expense'];
    $monthResult=$monthIncome-$monthExpense;
    $q=$pdo->prepare("SELECT COUNT(*) FROM finance_payables WHERE church_id=? AND status='open' AND due_date<CURDATE()");
    $q->execute([$cid]);$overduePayables=(int)$q->fetchColumn();
}

View::header('Dashboard',$auth,'dashboard.php');
?>

<?php if($canSecretary):?>
<div class="grid stats">
  <div class="card stat"><div class="stat-label">Membros ativos</div><div class="stat-value"><?=$stats['members']?></div><div class="stat-foot">Cadastros ativos da igreja</div></div>
  <div class="card stat"><div class="stat-label">Congregações</div><div class="stat-value"><?=$stats['congregations']?></div><div class="stat-foot">Unidades em funcionamento</div></div>
  <div class="card stat"><div class="stat-label">Pedidos pendentes</div><div class="stat-value"><?=$stats['prayers']?></div><div class="stat-foot">Pedidos de oração aguardando atenção</div></div>
  <div class="card stat"><div class="stat-label">Eventos futuros</div><div class="stat-value"><?=$stats['events']?></div><div class="stat-foot">Eventos ativos a partir de hoje</div></div>
</div>
<?php endif?>

<?php if($canFinance):?>
<div class="grid stats">
  <div class="card stat"><div class="stat-label">Entradas neste mês</div><div class="stat-value">R$ <?=number_format($monthIncome,2,',','.')?></div><div class="stat-foot">Lançamentos da Tesouraria</div></div>
  <div class="card stat"><div class="stat-label">Saídas neste mês</div><div class="stat-value">R$ <?=number_format($monthExpense,2,',','.')?></div><div class="stat-foot">Despesas contabilizadas</div></div>
  <div class="card stat"><div class="stat-label">Resultado do mês</div><div class="stat-value">R$ <?=number_format($monthResult,2,',','.')?></div><div class="stat-foot">Entradas menos saídas</div></div>
  <div class="card stat"><div class="stat-label">Contas vencidas</div><div class="stat-value"><?=$overduePayables?></div><div class="stat-foot"><a href="/finance.php">Abrir Tesouraria</a></div></div>
</div>
<?php endif?>

<?php if($canSecretary):?>
<div class="grid two-col">
  <section class="card card-pad">
    <div class="page-head" style="margin-bottom:8px"><div><h1 style="font-size:16px">Membros recentes</h1><p>Últimos cadastros realizados</p></div><a class="btn btn-light" href="members.php">Ver membros</a></div>
    <?php if(!$recentMembers):?><div class="empty">Nenhum membro cadastrado ainda.</div><?php else:?><div class="list"><?php foreach($recentMembers as $m):?><div class="list-row"><div><strong><?=e($m['name'])?></strong><div class="muted" style="font-size:11px;margin-top:3px"><?=e($m['phone'])?></div></div><small class="muted"><?=date('d/m/Y',strtotime($m['created_at']))?></small></div><?php endforeach?></div><?php endif?>
  </section>
  <section class="card card-pad">
    <div class="page-head" style="margin-bottom:8px"><div><h1 style="font-size:16px">Próximos eventos</h1><p>Agenda da igreja</p></div><a class="btn btn-light" href="events.php">Eventos</a></div>
    <?php if(!$nextEvents):?><div class="empty">Nenhum evento futuro.</div><?php else:?><div class="list"><?php foreach($nextEvents as $ev):?><div class="list-row"><div><strong><?=e($ev['title'])?></strong><div class="muted" style="font-size:11px;margin-top:3px"><?=e($ev['location']?:'Local não informado')?></div></div><small class="muted"><?=date('d/m H:i',strtotime($ev['event_date']))?></small></div><?php endforeach?></div><?php endif?>
  </section>
</div>
<?php elseif($canFinance):?>
<section class="card card-pad">
  <div class="page-head"><div><h1 style="font-size:17px">Painel da Tesouraria</h1><p>Seu acesso está concentrado no financeiro e nos relatórios da igreja.</p></div></div>
  <div class="actions"><a class="btn btn-primary" href="/finance.php">Abrir Tesouraria</a><a class="btn btn-light" href="/reports.php">Abrir Relatórios</a></div>
</section>
<?php endif?>

<?php View::footer();?>
