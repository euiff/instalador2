<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AutomationRunner.php';

$user=$auth->requireAbility('settings');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action!=='run_now')throw new RuntimeException('Ação inválida.');

        $result=(new AutomationRunner($pdo))->run('manual',$cid);
        $basic=$result['result']['basic']??[];
        $advanced=$result['result']['advanced']??[];
        $total=0;
        array_walk_recursive($basic,function($v)use(&$total){if(is_int($v))$total+=$v;});
        array_walk_recursive($advanced,function($v)use(&$total){if(is_int($v))$total+=$v;});
        flash('success','Automações executadas com sucesso em '.$result['duration_ms'].' ms. Ações processadas: '.$total.'.');
    }catch(Throwable $e){
        flash('error','Falha ao executar automações: '.$e->getMessage());
    }
    redirect('/automations.php');
}

$q=$pdo->prepare('SELECT * FROM automation_runs WHERE church_id=? ORDER BY started_at DESC LIMIT 20');
$q->execute([$cid]);$runs=$q->fetchAll();
$lastManual=$runs[0]??null;

$globalCron=$pdo->query("SELECT id,run_source,status,error_message,started_at,finished_at,duration_ms FROM automation_runs WHERE church_id IS NULL AND run_source='cron' ORDER BY started_at DESC LIMIT 1")->fetch()?:null;

$counts=[];
$checks=[
    'campaigns'=>"SELECT COUNT(*) FROM communication_campaigns WHERE church_id=? AND status='scheduled'",
    'events'=>"SELECT COUNT(*) FROM events WHERE church_id=? AND active=1 AND reminder_sent=0 AND event_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR)",
    'raffles'=>"SELECT COUNT(*) FROM raffles WHERE church_id=? AND status='active' AND draw_date IS NOT NULL AND reminder_sent_at IS NULL AND draw_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR)",
    'prayer_clocks'=>"SELECT COUNT(*) FROM prayer_clocks WHERE church_id=? AND active=1 AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 1 DAY)",
    'polls'=>"SELECT COUNT(*) FROM polls WHERE church_id=? AND status IN('scheduled','draft') AND scheduled_at IS NOT NULL AND scheduled_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) AND sent_at IS NULL",
];
foreach($checks as $key=>$sql){$s=$pdo->prepare($sql);$s->execute([$cid]);$counts[$key]=(int)$s->fetchColumn();}

$evolutionReady=!empty($church['evolution_api_url'])&&!empty($church['evolution_api_key'])&&!empty($church['evolution_instance_name']);
$cronPath=__DIR__.'/cron.php';
$cronCommand='php -q '.escapeshellarg($cronPath);
$lastAge=$globalCron?time()-strtotime((string)$globalCron['started_at']):null;
$cronHealthy=$globalCron&&$globalCron['status']==='success'&&$lastAge!==null&&$lastAge<600;

View::header('Automações','automations',$auth,$church);
?>
<div class="page-head groups-page-head">
  <div>
    <h1>Automações</h1>
    <p>Monitore lembretes, comunicados agendados, rifas, relógio de oração, enquetes e devocionais.</p>
  </div>
  <div class="actions">
    <form method="post" onsubmit="return confirm('Executar agora as automações desta igreja? Mensagens que estiverem vencidas poderão ser enviadas.')">
      <?=csrf_field()?><input type="hidden" name="action" value="run_now">
      <button class="btn btn-primary">▶ Executar agora</button>
    </form>
  </div>
</div>
<?php View::flash(); ?>

<div class="grid stats">
  <div class="card stat">
    <div class="stat-label">Motor automático</div>
    <div class="stat-value" style="font-size:20px"><?=$cronHealthy?'Ativo':($globalCron?'Atenção':'Não iniciado')?></div>
    <div class="stat-foot"><?=$globalCron?'Último cron: '.e(date('d/m/Y H:i:s',strtotime($globalCron['started_at']))):'Cron do cPanel ainda não registrado'?></div>
  </div>
  <div class="card stat"><div class="stat-label">Comunicados agendados</div><div class="stat-value"><?=$counts['campaigns']?></div><div class="stat-foot">Aguardando horário</div></div>
  <div class="card stat"><div class="stat-label">Eventos nas próximas 24h</div><div class="stat-value"><?=$counts['events']?></div><div class="stat-foot">Com lembrete pendente</div></div>
  <div class="card stat"><div class="stat-label">Evolution API</div><div class="stat-value" style="font-size:20px"><?=$evolutionReady?'Configurada':'Pendente'?></div><div class="stat-foot"><a href="/settings.php">Abrir configurações</a></div></div>
</div>

<div class="grid two-col automation-grid">
<section class="card settings-section">
  <div class="settings-section-head">
    <div class="settings-icon">↻</div>
    <div><h2>Configuração do cron no cPanel</h2><p>Para os envios acontecerem sozinhos, o cPanel precisa executar o cron a cada minuto.</p></div>
  </div>

  <div class="automation-cron-status <?=$cronHealthy?'is-ok':'is-warning'?>">
    <strong><?=$cronHealthy?'Cron executando normalmente':'Cron ainda não foi confirmado recentemente'?></strong>
    <span><?=$cronHealthy?'O cron global do servidor executou há menos de 10 minutos.':'Copie o comando abaixo para o Agendador de Tarefas Cron do cPanel.'?></span>
  </div>

  <div class="field" style="margin-top:16px">
    <label>Comando</label>
    <div class="copy-row">
      <input class="input code-input" id="cron-command" readonly value="<?=e($cronCommand)?>">
      <button class="btn btn-light" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('cron-command').value)">Copiar</button>
    </div>
  </div>

  <div class="cron-expression">
    <div><span>Minuto</span><strong>*</strong></div>
    <div><span>Hora</span><strong>*</strong></div>
    <div><span>Dia</span><strong>*</strong></div>
    <div><span>Mês</span><strong>*</strong></div>
    <div><span>Semana</span><strong>*</strong></div>
  </div>
  <p class="help-text">No cPanel: Cron Jobs → Uma vez por minuto → cole o comando acima. O caminho exibido é o caminho real desta instalação no servidor.</p>
</section>

<section class="card settings-section">
  <div class="settings-section-head">
    <div class="settings-icon">⌁</div>
    <div><h2>Fila das próximas automações</h2><p>Itens que podem ser processados pelo próximo ciclo.</p></div>
  </div>
  <div class="automation-queue">
    <a href="/campaigns.php"><span>✉ Comunicados</span><strong><?=$counts['campaigns']?></strong></a>
    <a href="/events.php"><span>□ Eventos / lembretes</span><strong><?=$counts['events']?></strong></a>
    <a href="/raffles.php"><span>◇ Rifas próximas</span><strong><?=$counts['raffles']?></strong></a>
    <a href="/prayer-clock.php"><span>◷ Relógio de oração</span><strong><?=$counts['prayer_clocks']?></strong></a>
    <a href="/polls.php"><span>✓ Enquetes</span><strong><?=$counts['polls']?></strong></a>
  </div>
  <div class="automation-note">
    <strong>Devocional e aniversários</strong>
    <p>Essas rotinas são verificadas por horário/data e só aparecem no histórico depois de processadas.</p>
  </div>
</section>
</div>

<section class="card" style="margin-top:18px">
  <div class="page-head" style="padding:18px 18px 8px">
    <div><h2 style="font-size:15px">Testes manuais desta igreja</h2><p>O histórico abaixo não expõe resultados agregados de outras igrejas. O cron global aparece apenas como indicador de saúde acima.</p></div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Início</th><th>Origem</th><th>Status</th><th>Duração</th><th>Resultado / erro</th></tr></thead>
    <tbody>
    <?php foreach($runs as $run):
      $result=json_decode((string)($run['result_json']??''),true);
    ?>
      <tr>
        <td><?=e(date('d/m/Y H:i:s',strtotime($run['started_at'])))?></td>
        <td><?=e(match($run['run_source']){'manual'=>'Teste manual','cron'=>'cPanel Cron','http_cron'=>'Cron HTTP',default=>$run['run_source']})?></td>
        <td><span class="badge <?=$run['status']==='success'?'ok':'off'?>"><?=e($run['status'])?></span></td>
        <td><?=e($run['duration_ms']!==null?$run['duration_ms'].' ms':'—')?></td>
        <td>
          <?php if($run['status']==='error'):?><span class="text-danger"><?=e($run['error_message']??'Erro não informado')?></span>
          <?php elseif(is_array($result)):?><details><summary>Ver processamento</summary><pre><?=e(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></details>
          <?php else:?><span class="muted">—</span><?php endif?>
        </td>
      </tr>
    <?php endforeach?>
    <?php if(!$runs):?><tr><td colspan="5"><div class="empty">Nenhuma execução registrada ainda.</div></td></tr><?php endif?>
    </tbody>
  </table></div>
</section>
<?php View::footer(); ?>
