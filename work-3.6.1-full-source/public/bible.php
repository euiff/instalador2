<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/BibleReadingService.php';
require_once __DIR__.'/lib/EvolutionClient.php';

$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$service=new BibleReadingService();
$preview=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='preview'){
            $ref=$service->parseReference((string)($_POST['reference']??''));
            if(!$ref)throw new RuntimeException('Referência bíblica inválida. Ex.: João 3, Salmos 23, 1 Coríntios 13.');
            $preview=$service->chapterText($ref['book'],$ref['chapter']);
        }

        if($action==='create_plan'){
            $phone=preg_replace('/\D+/','',(string)($_POST['phone']??''))?:'';
            $name=trim((string)($_POST['name']??''));
            $plan=(string)($_POST['plan_type']??'full_bible');
            $time=(string)($_POST['delivery_time']??'08:00');
            if($phone===''||strlen($phone)<10)throw new RuntimeException('Informe um telefone/WhatsApp válido.');
            if(!in_array($plan,['full_bible','new_testament','psalms_proverbs','full_bible_1year'],true))throw new RuntimeException('Plano inválido.');
            if(!preg_match('/^\d{2}:\d{2}$/',$time))throw new RuntimeException('Horário inválido.');

            $first=$service->firstForPlan($plan);
            $pdo->prepare('UPDATE bible_reading_plans SET active=0 WHERE church_id=? AND phone=? AND active=1')->execute([$cid,$phone]);
            $q=$pdo->prepare('INSERT INTO bible_reading_plans(id,church_id,phone,name,plan_type,current_book,current_chapter,include_audio,delivery_time,active) VALUES(?,?,?,?,?,?,?,?,?,1)');
            $q->execute([app_uuid(),$cid,$phone,$name?:null,$plan,$first['book'],1,isset($_POST['include_audio'])?1:0,$time.':00']);
            flash('success','Plano de leitura criado.');
            redirect('/bible.php');
        }

        if($action==='toggle_plan'){
            $id=(string)($_POST['id']??'');
            $q=$pdo->prepare('UPDATE bible_reading_plans SET active=IF(active=1,0,1),completed_at=IF(active=1,NOW(),NULL) WHERE id=? AND church_id=?');
            $q->execute([$id,$cid]);
            flash('success','Status do plano atualizado.');
            redirect('/bible.php');
        }

        if($action==='send_now'){
            $id=(string)($_POST['id']??'');
            $q=$pdo->prepare('SELECT * FROM bible_reading_plans WHERE id=? AND church_id=? LIMIT 1');
            $q->execute([$id,$cid]);$plan=$q->fetch();
            if(!$plan)throw new RuntimeException('Plano não encontrado.');

            $client=new EvolutionClient((string)($church['evolution_api_url']??''),(string)($church['evolution_api_key']??''),(string)($church['evolution_instance_name']??''));
            if(!$client->configured())throw new RuntimeException('Configure a Evolution API antes de enviar.');
            $message=$service->chapterText((string)$plan['current_book'],(int)$plan['current_chapter']);
            $client->sendText((string)$plan['phone'],$message);
            flash('success','Leitura enviada agora para o WhatsApp.');
            redirect('/bible.php');
        }
    }catch(Throwable $e){
        flash('error',$e->getMessage());
    }
}

$q=$pdo->prepare('SELECT * FROM bible_reading_plans WHERE church_id=? ORDER BY active DESC,created_at DESC LIMIT 200');
$q->execute([$cid]);$plans=$q->fetchAll();
$active=count(array_filter($plans,fn($p)=>(int)$p['active']===1));
$sentToday=count(array_filter($plans,fn($p)=>!empty($p['last_sent_at'])&&date('Y-m-d',strtotime($p['last_sent_at']))===date('Y-m-d')));

View::header('Bíblia e Leitura','bible',$auth,$church);
?>
<div class="module-hero">
  <div>
    <span class="eyebrow">Conteúdo bíblico</span>
    <h1>Bíblia e Planos de Leitura</h1>
    <p>Consulte capítulos completos, crie planos diários e acompanhe as leituras enviadas automaticamente pelo WhatsApp.</p>
  </div>
  <div class="hero-stat"><strong><?=$active?></strong><span>planos ativos</span></div>
</div>
<?php View::flash(); ?>

<div class="feature-grid three">
  <div class="feature-card"><span class="feature-icon">📖</span><strong>Leitura imediata</strong><p>Abra um capítulo completo informando livro e capítulo.</p></div>
  <div class="feature-card"><span class="feature-icon">⏰</span><strong>Entrega diária</strong><p>O cron envia a leitura automaticamente no horário escolhido.</p></div>
  <div class="feature-card"><span class="feature-icon">✓</span><strong>Enviados hoje</strong><p><b><?=$sentToday?></b> plano(s) já receberam a leitura de hoje.</p></div>
</div>

<div class="grid two-col modern-grid">
<section class="card form-card">
  <div class="section-title"><span>📚</span><div><h2>Ler uma passagem agora</h2><p>Ex.: João 3, Salmos 23 ou 1 Coríntios 13.</p></div></div>
  <form method="post">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="preview">
    <div class="field full"><label>Livro e capítulo</label><input class="input input-lg" name="reference" placeholder="João 3" required></div>
    <button class="btn btn-primary btn-lg">Abrir passagem</button>
  </form>
  <?php if($preview):?><div class="reading-preview"><?=nl2br(e($preview))?></div><?php endif?>
</section>

<section class="card form-card">
  <div class="section-title"><span>🗓️</span><div><h2>Novo plano de leitura</h2><p>Cadastre o WhatsApp e defina o horário de entrega.</p></div></div>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="create_plan">
    <div class="form-grid">
      <div class="field"><label>Nome</label><input class="input" name="name" placeholder="Nome da pessoa"></div>
      <div class="field"><label>WhatsApp *</label><input class="input" name="phone" placeholder="5564999999999" required></div>
      <div class="field full"><label>Plano *</label><select class="select" name="plan_type">
        <option value="full_bible">Bíblia Completa</option>
        <option value="new_testament">Novo Testamento</option>
        <option value="psalms_proverbs">Salmos e Provérbios</option>
        <option value="full_bible_1year">Bíblia Completa em 1 ano</option>
      </select></div>
      <div class="field"><label>Horário diário</label><input class="input" type="time" name="delivery_time" value="08:00"></div>
      <label class="choice-card"><input type="checkbox" name="include_audio"><span><strong>Áudio</strong><small>Enviar áudio quando a voz estiver configurada.</small></span></label>
    </div>
    <button class="btn btn-primary btn-lg" style="width:100%;margin-top:12px">Criar plano</button>
  </form>
</section>
</div>

<section class="card card-pad" style="margin-top:18px">
  <div class="page-head"><div><h2 style="font-size:16px">Planos cadastrados</h2><p>Ative, pause ou envie a leitura atual manualmente.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Pessoa</th><th>Plano</th><th>Próxima leitura</th><th>Horário</th><th>Último envio</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($plans as $p):?>
    <tr>
      <td><strong><?=e($p['name']?:'Sem nome')?></strong><div class="muted"><?=e($p['phone'])?></div></td>
      <td><?=e($service->planLabel((string)$p['plan_type']))?></td>
      <td><?=e($p['current_book'])?> <?=e((string)$p['current_chapter'])?></td>
      <td><?=e(substr((string)$p['delivery_time'],0,5))?></td>
      <td><?=$p['last_sent_at']?e(date('d/m/Y H:i',strtotime($p['last_sent_at']))):'—'?></td>
      <td><span class="badge <?=$p['active']?'ok':'off'?>"><?=$p['active']?'Ativo':'Pausado'?></span></td>
      <td class="action-cell">
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="send_now"><input type="hidden" name="id" value="<?=e($p['id'])?>"><button class="btn btn-light">Enviar agora</button></form>
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle_plan"><input type="hidden" name="id" value="<?=e($p['id'])?>"><button class="btn <?=$p['active']?'btn-danger':'btn-light'?>"><?=$p['active']?'Pausar':'Ativar'?></button></form>
      </td>
    </tr>
  <?php endforeach?>
  <?php if(!$plans):?><tr><td colspan="7"><div class="empty">Nenhum plano cadastrado.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>
<?php View::footer(); ?>
