<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/ChurchInsightsService.php';

$user=$auth->requireAbility('dashboard');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$service=new ChurchInsightsService($pdo,(string)$church['id']);
$summary=$service->overview();
$events=$service->upcomingEvents(30);
$overdue=$service->overduePayables();
$pendingEvents=$service->pendingEventPayments();
$pendingKids=$service->pendingKids();
$groups=$service->groupPerformance(30);
$maxGroup=max(1,...array_map(fn($g)=>(int)$g['presence'],$groups));

function insight_money(float|int|string $value): string {
    return 'R$ '.number_format((float)$value,2,',','.');
}

View::header('Indicadores e Pendências',$auth,'insights.php');
View::flash();
?>
<style>
.insight-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.insight-head h1{margin:0 0 5px}.insight-head p{margin:0;color:var(--muted)}
.insight-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}.insight-kpi{padding:14px}.insight-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:800}.insight-kpi strong{display:block;font-size:22px;margin-top:4px}.insight-kpi .warn{color:#a45b18}.insight-kpi .danger{color:#aa2f2f}.insight-kpi .good{color:#18734c}
.insight-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}.insight-section{padding:16px}.insight-section h2{margin:0;font-size:16px}.insight-section-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px}
.insight-list{display:grid;gap:8px}.insight-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 11px;border:1px solid var(--line);border-radius:10px}.insight-row strong{font-size:11px}.insight-row small{display:block;color:var(--muted);font-size:9px;margin-top:2px}.insight-row .amount{text-align:right;font-weight:800;font-size:11px}
.group-performance{display:grid;gap:10px}.group-line{display:grid;grid-template-columns:minmax(130px,.8fr) 2fr auto;gap:10px;align-items:center}.group-line strong{font-size:11px}.bar-track{height:10px;background:#e9eef3;border-radius:999px;overflow:hidden}.bar-track span{display:block;height:100%;background:#2d78b7;border-radius:999px}.group-line small{color:var(--muted);white-space:nowrap}
.insight-actions{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.insight-action{padding:12px;border:1px solid var(--line);border-radius:11px;text-decoration:none;color:inherit}.insight-action strong{display:block;font-size:11px}.insight-action small{display:block;color:var(--muted);font-size:9px;margin-top:3px}
@media(max-width:980px){.insight-kpis{grid-template-columns:1fr 1fr}.insight-grid{grid-template-columns:1fr}.insight-actions{grid-template-columns:1fr 1fr}}@media(max-width:620px){.insight-kpis,.insight-actions{grid-template-columns:1fr}.group-line{grid-template-columns:1fr}.insight-head{flex-direction:column}}
</style>

<div class="insight-head">
  <div><h1>Indicadores e Pendências</h1><p>Uma visão rápida do que precisa de atenção na <?=e($church['name'])?>.</p></div>
  <button class="btn btn-light" type="button" onclick="window.print()">Imprimir / PDF</button>
</div>

<div class="insight-kpis">
  <section class="card insight-kpi"><small>Membros ativos</small><strong><?=$summary['members']?></strong><span class="muted">+<?=$summary['newMembers']?> nos últimos 30 dias</span></section>
  <section class="card insight-kpi"><small>Contas vencidas</small><strong class="<?=$summary['overduePayables']?'danger':'good'?>"><?=$summary['overduePayables']?></strong><span class="muted"><?=insight_money($summary['overdueAmount'])?></span></section>
  <section class="card insight-kpi"><small>Eventos · pagamentos pendentes</small><strong class="<?=$summary['eventPending']?'warn':'good'?>"><?=$summary['eventPending']?></strong><span class="muted"><?=insight_money($summary['eventPendingAmount'])?> em aberto</span></section>
  <section class="card insight-kpi"><small>Kids · aprovações pendentes</small><strong class="<?=$summary['kidsPending']?'warn':'good'?>"><?=$summary['kidsPending']?></strong><span class="muted"><?=$summary['kidsInRoom']?> criança(s) em sala hoje</span></section>
</div>

<div class="insight-kpis">
  <section class="card insight-kpi"><small>Células · reuniões 30 dias</small><strong><?=$summary['groupMeetings']?></strong><span class="muted"><?=$summary['groupPresence']?> presenças registradas</span></section>
  <section class="card insight-kpi"><small>Ensino · aulas 30 dias</small><strong><?=$summary['lessons']?></strong><span class="muted"><?=$summary['lessonPresence']?> presenças registradas</span></section>
  <section class="card insight-kpi"><small>Mural ativo</small><strong><?=$summary['activeAnnouncements']?></strong><span class="muted"><?=$summary['announcementReads']?> leituras em 30 dias</span></section>
  <section class="card insight-kpi"><small>Estudos publicados</small><strong><?=$summary['activeStudies']?></strong><span class="muted"><?=$summary['studyReads']?> leituras em 30 dias</span></section>
</div>

<div class="insight-grid">
  <section class="card insight-section">
    <div class="insight-section-head"><h2>Contas vencidas</h2><a class="muted" href="/finance-control.php">Abrir financeiro</a></div>
    <div class="insight-list">
      <?php foreach($overdue as $row):?><div class="insight-row"><div><strong><?=e($row['supplier'])?></strong><small><?=e($row['description'])?> · vencida há <?=(int)$row['days_overdue']?> dia(s)</small></div><div class="amount"><?=insight_money($row['amount'])?></div></div><?php endforeach?>
      <?php if(!$overdue):?><div class="empty">Nenhuma conta vencida.</div><?php endif?>
    </div>
  </section>

  <section class="card insight-section">
    <div class="insight-section-head"><h2>Pagamentos de eventos</h2><a class="muted" href="/event-registrations.php">Abrir inscrições</a></div>
    <div class="insight-list">
      <?php foreach($pendingEvents as $row):?><div class="insight-row"><div><strong><?=e($row['name'])?></strong><small><?=e($row['event_title'])?> · <?=e($row['phone'])?></small></div><div class="amount"><?=insight_money(max(0,(float)$row['amount_due']-(float)$row['amount_paid']))?></div></div><?php endforeach?>
      <?php if(!$pendingEvents):?><div class="empty">Nenhum pagamento de evento pendente.</div><?php endif?>
    </div>
  </section>
</div>

<div class="insight-grid">
  <section class="card insight-section">
    <div class="insight-section-head"><h2>Solicitações Kids</h2><a class="muted" href="/kids.php?view=programs">Abrir Kids</a></div>
    <div class="insight-list">
      <?php foreach($pendingKids as $row):?><div class="insight-row"><div><strong><?=e($row['child_name'])?></strong><small><?=e($row['program_title'])?> · <?=e($row['guardian_name'])?> · <?=e($row['guardian_phone'])?></small></div><span class="badge off">Pendente</span></div><?php endforeach?>
      <?php if(!$pendingKids):?><div class="empty">Nenhuma solicitação Kids pendente.</div><?php endif?>
    </div>
  </section>

  <section class="card insight-section">
    <div class="insight-section-head"><h2>Próximos eventos · 30 dias</h2><a class="muted" href="/events.php">Abrir agenda</a></div>
    <div class="insight-list">
      <?php foreach($events as $event):
        $people=(int)$event['registered_people'];
        $capacity=$event['capacity']!==null?(int)$event['capacity']:null;
      ?><div class="insight-row"><div><strong><?=e($event['title'])?></strong><small><?=date('d/m/Y H:i',strtotime($event['event_date']))?><?=!empty($event['location'])?' · '.e($event['location']):''?></small></div><div class="amount"><?=$people?> inscrito(s)<?=$capacity!==null?' / '.$capacity:''?><?php if((int)$event['pending_payments']>0:?><div class="muted"><?=(int)$event['pending_payments']?> pagamento(s) pendente(s)</div><?php endif?></div></div><?php endforeach?>
      <?php if(!$events):?><div class="empty">Nenhum evento nos próximos 30 dias.</div><?php endif?>
    </div>
  </section>
</div>

<section class="card insight-section">
  <div class="insight-section-head"><h2>Grupos e Células · últimos 30 dias</h2><a class="muted" href="/small-groups.php?view=reports">Relatório completo</a></div>
  <div class="group-performance">
    <?php foreach($groups as $group):
      $presence=(int)$group['presence'];$width=(int)round(($presence/$maxGroup)*100);
    ?>
      <div class="group-line"><strong><?=e($group['name'])?></strong><div class="bar-track"><span style="width:<?=max(2,min(100,$width))?>%"></span></div><small><?=(int)$group['meetings']?> reunião(ões) · <?=$presence?> presenças · <?=(int)$group['visitors']?> visitantes</small></div>
    <?php endforeach?>
    <?php if(!$groups):?><div class="empty">Nenhum dado de células nos últimos 30 dias.</div><?php endif?>
  </div>
</section>

<section class="card insight-section">
  <div class="insight-section-head"><h2>Acessos rápidos</h2></div>
  <div class="insight-actions">
    <a class="insight-action" href="/members.php"><strong>Membros</strong><small>Cadastros e aniversariantes</small></a>
    <a class="insight-action" href="/finance-control.php"><strong>Financeiro</strong><small>Caixas, contas e lançamentos</small></a>
    <a class="insight-action" href="/event-registrations.php"><strong>Eventos</strong><small>Inscrições e pagamentos</small></a>
    <a class="insight-action" href="/kids.php"><strong>Kids</strong><small>Check-in e aprovações</small></a>
    <a class="insight-action" href="/small-groups.php"><strong>Grupos e Células</strong><small>Frequência e visitantes</small></a>
    <a class="insight-action" href="/learning.php"><strong>Ensino</strong><small>Turmas, aulas e frequência</small></a>
    <a class="insight-action" href="/announcements.php"><strong>Mural</strong><small>Avisos e leituras</small></a>
    <a class="insight-action" href="/studies.php"><strong>Estudos</strong><small>Conteúdo e leituras</small></a>
  </div>
</section>
<?php View::footer(); ?>