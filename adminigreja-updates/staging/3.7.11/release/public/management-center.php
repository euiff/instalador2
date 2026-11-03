<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/ManagementOverviewService.php';
$user=$auth->requireAbility('dashboard');
$church=$auth->currentChurch();
if(!$church)redirect('/dashboard.php');
$service=new ManagementOverviewService($pdo,$auth,(string)$church['id']);
$data=$service->metrics();
$perms=$data['permissions'];$numbers=$data['numbers'];
$tasks=ManagementOverviewService::tasks($numbers,$perms);
$links=$service->quickLinks();
$chart=$service->monthlyFinance();
$events=$service->nextEvents();
$max=1.0;
foreach($chart as $month)$max=max($max,$month['income'],$month['expense']);
$fmt=static fn(mixed $v):string=>'R$ '.number_format((float)$v,2,',','.');
$num=static fn(string $key):string=>array_key_exists($key,$numbers)&&$numbers[$key]!==null
    ?number_format((float)$numbers[$key],0,',','.'):'—';
$firstName=trim(explode(' ',(string)($user['full_name']?:'Olá'))[0]??'Olá');
$iconFor=[
    'users'=>'♙','users-round'=>'◎','wallet'=>'◈','landmark'=>'▣','files'=>'▤',
    'network'=>'◇','graduation-cap'=>'◫','calendar-days'=>'▦','baby'=>'♡',
    'megaphone'=>'◉','shield-check'=>'✓','file-text'=>'▤'
];
$periodLabel=mb_strtoupper((new DateTimeImmutable())->format('M/Y'),'UTF-8');
View::header('Central de Gestão 360',$auth,'management-center.php');
View::flash();
?>
<div class="im-360">
  <section class="im-hero" aria-labelledby="im-hero-title">
    <div class="im-hero-main">
      <span class="im-eyebrow"><span class="im-live" aria-hidden="true"></span> PAINEL DE GESTÃO</span>
      <h1 id="im-hero-title">Bom dia, <?=e($firstName)?>.</h1>
      <p>Um lugar para organizar pessoas, ministérios, finanças e o cuidado diário da igreja.</p>
      <div class="im-hero-actions">
        <button type="button" class="btn btn-primary" data-im-open="quick-create">+ Novo cadastro</button>
        <button type="button" class="btn btn-light" data-im-open="menu-search">Encontrar uma função <kbd>Ctrl K</kbd></button>
      </div>
    </div>
    <div class="im-hero-aside">
      <div class="im-hero-church"><?=e($church['name'])?></div>
      <div class="im-hero-date"><?=e(date('d/m/Y'))?></div>
      <small>Informações desta igreja, com acesso conforme suas permissões.</small>
    </div>
  </section>

  <div class="im-layout">
    <main class="im-primary">
      <section class="im-section" aria-labelledby="im-tasks-title">
        <div class="im-section-heading">
          <div><span class="im-overline">PRIORIDADES</span><h2 id="im-tasks-title">O que precisa de atenção</h2>
          <p>Próximos passos para manter o trabalho em dia.</p></div>
          <a href="/dashboard.php" class="im-soft-link">Início tradicional</a>
        </div>
        <div class="im-tasks">
          <?php foreach($tasks as $task):?>
          <a class="im-task im-task-<?=e($task['kind'])?>" href="<?=e($task['href'])?>">
            <span class="im-task-icon" aria-hidden="true"><?=e($task['kind']==='pending'?'!':($task['kind']==='setup'?'+':'✓'))?></span>
            <span class="im-task-copy"><strong><?=e($task['title'])?></strong><small><?=e($task['description'])?></small></span>
            <span class="im-task-arrow" aria-hidden="true">›</span>
          </a>
          <?php endforeach;?>
        </div>
      </section>

      <section class="im-section" aria-labelledby="im-links-title">
        <div class="im-section-heading">
          <div><span class="im-overline">ÁREAS DO SISTEMA</span><h2 id="im-links-title">Acesso rápido</h2>
          <p>Abra a função que precisa sem procurar nos submenus.</p></div>
        </div>
        <?php if($links):?>
        <label class="im-filter-label" for="im-module-filter">Buscar módulo ou função
          <input id="im-module-filter" class="input" type="search" autocomplete="off" placeholder="Ex.: membros, cupons, escalas, tesouraria…">
        </label>
        <div class="im-app-grid" id="im-app-results">
          <?php foreach($links as $link):?>
          <a class="im-app-card" href="<?=e($link['href'])?>">
            <span class="im-app-icon" aria-hidden="true"><?=e($iconFor[$link['icon']]??'◈')?></span>
            <span class="im-app-copy"><strong><?=e($link['title'])?></strong><small><?=e($link['description'])?></small></span>
            <span class="im-app-chevron" aria-hidden="true">›</span>
          </a>
          <?php endforeach;?>
        </div>
        <p class="im-no-results" hidden role="status">Nenhuma área encontrada. Tente outra palavra ou use a busca geral.</p>
        <?php else:?>
        <p>Seu perfil possui acesso limitado. Consulte um administrador para liberar funções adicionais.</p>
        <?php endif;?>
      </section>

      <?php if($perms['finance']&&$chart):?>
      <section class="im-section im-finance-section" aria-labelledby="im-finance-title">
        <div class="im-section-heading">
          <div><span class="im-overline">TESOURARIA</span><h2 id="im-finance-title">Evolução financeira</h2>
          <p>Últimos seis meses. Entradas e saídas do mesmo livro caixa, sem duplicar departamentos.</p></div>
          <a class="im-soft-link" href="/finance-control.php?view=report">Relatórios financeiros</a>
        </div>
        <div class="im-finance-panel">
          <div class="im-chart-legend" aria-hidden="true"><span><i class="im-legend-income"></i>Entradas</span><span><i class="im-legend-expense"></i>Saídas</span></div>
          <div class="im-chart" role="img" aria-label="Gráfico de comparação mensal entre entradas e saídas da igreja">
            <?php foreach($chart as $m):?>
            <?php $hi=round(max(0,min(100,$m['income']/$max*100)),2);$he=round(max(0,min(100,$m['expense']/$max*100)),2); ?>
            <div class="im-chart-column" title="<?=e($m['label'].' — Entradas: '.$fmt($m['income']).'; Saídas: '.$fmt($m['expense']))?>">
              <div class="im-chart-bars">
                <span class="im-chart-bar im-income" style="height:<?=$hi?>%" aria-hidden="true"></span>
                <span class="im-chart-bar im-expense" style="height:<?=$he?>%" aria-hidden="true"></span>
              </div><small><?=e($m['label'])?></small>
            </div>
            <?php endforeach;?>
          </div>
          <?php $last=end($chart); ?>
          <div class="im-chart-summary">
            <span><small>Entradas em <?=e($last['label'])?></small><strong><?=$fmt($last['income'])?></strong></span>
            <span><small>Saídas em <?=e($last['label'])?></small><strong><?=$fmt($last['expense'])?></strong></span>
            <span><small>Saldo operacional do mês</small><strong><?=$fmt($last['income']-$last['expense'])?></strong></span>
          </div>
        </div>
      </section>
      <?php endif;?>
    </main>

    <aside class="im-side" aria-label="Resumo e agenda">
      <section class="im-side-card">
        <div class="im-section-heading"><div><span class="im-overline">VISÃO RÁPIDA</span><h2>Indicadores</h2></div></div>
        <div class="im-metrics">
          <?php if($perms['people']):?>
            <a href="/members.php"><span>Pessoas ativas</span><strong><?=$num('members')?></strong></a>
            <a href="/departments.php"><span>Departamentos</span><strong><?=$num('departments')?></strong></a>
            <a href="/events.php"><span>Próximos eventos</span><strong><?=$num('events')?></strong></a>
            <a href="/users.php"><span>Logins vinculados</span><strong><?=$num('users')?></strong></a>
          <?php endif;?>
          <?php if($perms['finance']):?>
            <a href="/finance.php"><span>Contas financeiras</span><strong><?=$num('accounts')?></strong></a>
            <a href="/finance.php#payables"><span>Contas vencidas</span><strong><?=$num('overdue_payables')?></strong></a>
            <a href="/accounting.php"><span>Despesas sem comprovante</span><strong><?=$num('unproved_expenses')?></strong></a>
          <?php endif;?>
          <?php if($perms['accounting']):?>
            <a href="/accounting.php#accounting-requests"><span>Documentos solicitados</span><strong><?=$num('accounting_requests')?></strong></a>
            <a href="/accounting.php#accounting-files"><span>Cupons e notas para revisar</span><strong><?=$num('accounting_drafts')?></strong></a>
          <?php endif;?>
        </div>
        <p class="im-footnote">Contagens restritas à igreja selecionada.</p>
      </section>

      <?php if($perms['people']):?>
      <section class="im-side-card">
        <div class="im-section-heading"><div><span class="im-overline">PROGRAMAÇÃO</span><h2>Próximos eventos</h2></div><a href="/events.php" class="im-soft-link">Ver agenda</a></div>
        <div class="im-calendar-list">
          <?php foreach($events as $event):?>
          <a href="/events.php" class="im-calendar-row">
            <span class="im-date-box"><b><?=e(date('d',strtotime($event['event_date'])))?></b><small><?=e(date('m',strtotime($event['event_date'])))?></small></span>
            <span><strong><?=e($event['title'])?></strong><small><?=e(date('H:i',strtotime($event['event_date'])))?> · <?=e($event['location']?:'Local a definir')?></small></span>
          </a>
          <?php endforeach;?>
          <?php if(!$events):?><p class="im-empty">Nenhum evento futuro. <a href="/events.php">Organizar agenda</a></p><?php endif;?>
        </div>
      </section>
      <?php endif;?>

      <section class="im-side-card im-help">
        <span class="im-overline">FACILIDADE NO DIA A DIA</span>
        <h2>Encontre qualquer função</h2>
        <p>Use a busca do sistema ou o atalho <kbd>Ctrl K</kbd> para ir direto ao módulo que precisa.</p>
        <button type="button" class="btn btn-light" data-im-open="menu-search">Pesquisar agora</button>
      </section>
    </aside>
  </div>
</div>
<?php View::footer(); ?>
