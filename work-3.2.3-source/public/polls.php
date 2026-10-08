<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';

$user=$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church) exit('Igreja não selecionada.');

$churchId=(string)$church['id'];
$broadcast=new GroupBroadcastService($pdo);

function poll_message(array $poll): string
{
    $opts=json_decode((string)($poll['options']??'[]'),true);
    if(!is_array($opts))$opts=[];

    $lines=[
        '📊 *ENQUETE*',
        '',
        '❓ *'.trim((string)($poll['question']??'')).'*',
        '',
    ];

    foreach($opts as $i=>$opt){
        $text=is_array($opt)?trim((string)($opt['text']??'')):trim((string)$opt);
        if($text!=='')$lines[]='*'.($i+1).'.* '.$text;
    }

    if(!empty($poll['validity'])){
        $lines[]='';
        $lines[]='⏳ Validade: *'.trim((string)$poll['validity']).'*';
    }

    $lines[]='';
    $lines[]='Participe escolhendo uma das opções acima.';

    return implode("\n",$lines);
}

function poll_send(PDO $pdo,GroupBroadcastService $broadcast,array $church,array $poll): array
{
    $groupIds=json_decode((string)($poll['target_groups']??'[]'),true);
    if(!is_array($groupIds))$groupIds=[];

    $message=poll_message($poll);
    return $groupIds
        ?$broadcast->sendIds($church,$groupIds,$message)
        :$broadcast->sendPurpose($church,'polls',$message);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'create');

        if($action==='delete'){
            $q=$pdo->prepare('DELETE FROM polls WHERE id=? AND church_id=?');
            $q->execute([(string)($_POST['id']??''),$churchId]);
            flash('success','Enquete removida.');
            redirect('/polls.php');
        }

        if($action==='resend'){
            $q=$pdo->prepare('SELECT * FROM polls WHERE id=? AND church_id=? LIMIT 1');
            $q->execute([(string)($_POST['id']??''),$churchId]);
            $poll=$q->fetch();
            if(!$poll)throw new RuntimeException('Enquete não encontrada.');

            $result=poll_send($pdo,$broadcast,$church,$poll);
            if((int)($result['sent']??0)<1){
                $error=$result['errors'][0]['error']??'Nenhum grupo recebeu a enquete. Confira o grupo e a conexão da Evolution API.';
                $pdo->prepare('UPDATE polls SET status="error" WHERE id=?')->execute([$poll['id']]);
                throw new RuntimeException('Falha no reenvio: '.$error);
            }

            $pdo->prepare('UPDATE polls SET status="sent",sent_at=NOW() WHERE id=?')->execute([$poll['id']]);
            flash('success','Enquete reenviada para '.(int)$result['sent'].' grupo(s).');
            redirect('/polls.php');
        }

        $question=trim((string)($_POST['question']??''));

        $options=[];
        foreach((array)($_POST['options']??[]) as $option){
            $option=trim((string)$option);
            if($option!==''&&!in_array($option,$options,true))$options[]=$option;
        }

        if($question==='')throw new RuntimeException('Digite a pergunta da enquete.');
        if(count($options)<2)throw new RuntimeException('Informe pelo menos duas opções de resposta.');
        if(count($options)>8)throw new RuntimeException('Use no máximo oito opções.');

        $sendMode=(string)($_POST['send_mode']??'now');
        if(!in_array($sendMode,['now','schedule','draft'],true))$sendMode='now';

        $scheduled=null;
        if($sendMode==='schedule'){
            $scheduled=trim((string)($_POST['scheduled_at']??''))?:null;
            if(!$scheduled)throw new RuntimeException('Escolha a data e o horário do envio agendado.');
            $scheduled=str_replace('T',' ',$scheduled);
            if(strlen($scheduled)===16)$scheduled.=':00';
        }

        $expires=trim((string)($_POST['expires_at']??''))?:null;
        if($expires){
            $expires=str_replace('T',' ',$expires);
            if(strlen($expires)===16)$expires.=':00';
        }

        $targetGroups=$broadcast->idsFromPost($_POST['target_group_ids']??[]);
        $status=$sendMode==='schedule'?'scheduled':'draft';
        $id=app_uuid();

        $q=$pdo->prepare('INSERT INTO polls(id,church_id,question,options,results,target_groups,template_name,validity,status,scheduled_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([
            $id,$churchId,$question,
            json_encode($options,JSON_UNESCAPED_UNICODE),
            json_encode([],JSON_UNESCAPED_UNICODE),
            json_encode($targetGroups,JSON_UNESCAPED_UNICODE),
            trim((string)($_POST['template_name']??''))?:null,
            trim((string)($_POST['validity']??''))?:null,
            $status,$scheduled,$expires
        ]);

        if($sendMode==='now'){
            $q=$pdo->prepare('SELECT * FROM polls WHERE id=? AND church_id=? LIMIT 1');
            $q->execute([$id,$churchId]);
            $poll=$q->fetch();

            $result=poll_send($pdo,$broadcast,$church,$poll);
            if((int)($result['sent']??0)<1){
                $error=$result['errors'][0]['error']??'Nenhum grupo foi encontrado para receber a enquete.';
                $pdo->prepare('UPDATE polls SET status="error" WHERE id=?')->execute([$id]);
                flash('error','Enquete criada, mas não foi enviada: '.$error);
                redirect('/polls.php');
            }

            $pdo->prepare('UPDATE polls SET status="sent",sent_at=NOW() WHERE id=?')->execute([$id]);
            flash('success','Enquete criada e enviada agora para '.(int)$result['sent'].' grupo(s).');
            redirect('/polls.php');
        }

        flash('success',$sendMode==='schedule'?'Enquete criada e agendada.':'Enquete salva como rascunho.');
        redirect('/polls.php');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect('/polls.php');
    }
}

$q=$pdo->prepare('SELECT * FROM polls WHERE church_id=? ORDER BY created_at DESC');
$q->execute([$churchId]);
$rows=$q->fetchAll();
$groups=$broadcast->list($churchId,null,null,true);

$purposePollGroups=array_values(array_filter($groups,function(array $g){
    $p=json_decode((string)($g['purposes']??'[]'),true);
    return is_array($p)&&in_array('polls',$p,true);
}));

View::header('Enquetes','polls',$auth,$church);
?>
<style>
.poll-page{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:20px;align-items:start}
.poll-builder{overflow:hidden;padding:0}
.poll-builder-head{padding:22px 24px;background:linear-gradient(135deg,#0d3f78,#1768ad);color:#fff}
.poll-builder-head h2{margin:0 0 5px;font-size:20px}.poll-builder-head p{margin:0;color:#dbeeff;font-size:13px}
.poll-form{padding:22px 24px}.poll-step{margin-bottom:24px}.poll-step-title{display:flex;align-items:center;gap:10px;margin-bottom:12px}.poll-step-num{width:27px;height:27px;border-radius:50%;background:#eaf2fb;color:#14548f;display:grid;place-items:center;font-weight:900}.poll-step-title strong{font-size:14px}.poll-label{font-size:12px;font-weight:800;color:#344256;margin-bottom:7px;display:block}
.poll-question{min-height:92px;font-size:17px;font-weight:700;resize:vertical}
.poll-options{display:grid;gap:9px}.poll-option{display:grid;grid-template-columns:34px 1fr;gap:9px;align-items:center}.poll-option span{width:34px;height:34px;border-radius:9px;background:#f1f5f9;display:grid;place-items:center;font-weight:900;color:#526277}
.poll-more{margin-top:10px}
.poll-send-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.poll-send-card{position:relative;display:block;border:1px solid #dce3eb;border-radius:12px;padding:13px;cursor:pointer;background:#fff}.poll-send-card input{position:absolute;opacity:0}.poll-send-card:has(input:checked){border-color:#1768ad;background:#eff7ff;box-shadow:0 0 0 1px #1768ad}.poll-send-card strong{display:block;font-size:13px}.poll-send-card small{display:block;color:#718096;font-size:10px;margin-top:4px}
.poll-groups{display:grid;gap:8px;max-height:240px;overflow:auto}.poll-group{display:flex;gap:10px;align-items:flex-start;padding:11px;border:1px solid #e1e7ee;border-radius:11px;background:#fff}.poll-group input{margin-top:3px}.poll-group strong{display:block;font-size:13px}.poll-group small{display:block;color:#718096;margin-top:2px;font-size:10px}
.poll-summary{background:#f8fafc;border:1px solid #e1e7ee;border-radius:12px;padding:15px;margin-bottom:18px}.poll-summary strong{display:block;color:#173f70;margin-bottom:5px}.poll-summary p{margin:0;color:#64748b;font-size:12px;line-height:1.5}
.poll-actions{display:flex;gap:10px}.poll-actions .btn-primary{flex:1}
.poll-list{display:grid;gap:12px}.poll-card{border:1px solid #e1e7ee;border-radius:14px;padding:16px;background:#fff}.poll-card-top{display:flex;justify-content:space-between;gap:12px}.poll-card h3{font-size:14px;margin:0;line-height:1.4}.poll-meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.poll-meta span{font-size:10px;padding:5px 8px;border-radius:999px;background:#f1f5f9;color:#536274}.poll-status-sent{background:#e8f7ee!important;color:#247a46!important}.poll-status-error{background:#fff0f0!important;color:#b42318!important}.poll-status-scheduled{background:#fff6df!important;color:#946200!important}.poll-card-actions{display:flex;gap:7px;margin-top:12px}
.poll-empty{padding:40px 20px;text-align:center;color:#718096}
.poll-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:1000px){.poll-page{grid-template-columns:1fr}.poll-send-cards{grid-template-columns:1fr}.poll-two{grid-template-columns:1fr}}
</style>

<div class="page-head groups-page-head">
  <div>
    <h1>Enquetes</h1>
    <p>Crie a pergunta, escolha as respostas e envie diretamente para os grupos da igreja.</p>
  </div>
  <div class="actions"><a class="btn btn-light" href="/groups.php">Gerenciar grupos</a></div>
</div>
<?php View::flash(); ?>

<div class="poll-page">
<section class="card poll-builder">
  <div class="poll-builder-head">
    <h2>Nova enquete</h2>
    <p>Um formulário simples em quatro etapas. Você vê claramente o que será enviado e para quem.</p>
  </div>

  <form method="post" class="poll-form" id="poll-form">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="create">

    <div class="poll-step">
      <div class="poll-step-title"><span class="poll-step-num">1</span><strong>Qual é a pergunta?</strong></div>
      <label class="poll-label" for="poll-question">Pergunta da enquete *</label>
      <textarea class="textarea poll-question" id="poll-question" name="question" required maxlength="500" placeholder="Ex.: Qual horário você prefere para o próximo culto especial?"></textarea>
    </div>

    <div class="poll-step">
      <div class="poll-step-title"><span class="poll-step-num">2</span><strong>Quais são as opções?</strong></div>
      <div class="poll-options" id="poll-options">
        <?php for($i=1;$i<=4;$i++):?>
          <label class="poll-option" <?=$i>2?'data-extra="1" style="display:none"':''?>>
            <span><?=$i?></span>
            <input class="input" name="options[]" <?=$i<=2?'required':''?> placeholder="Opção <?=$i?>">
          </label>
        <?php endfor?>
      </div>
      <button class="btn btn-light poll-more" type="button" id="add-option">+ Adicionar opção</button>
      <small class="help-text">Mínimo 2 opções. Você pode adicionar até 8.</small>
    </div>

    <div class="poll-step">
      <div class="poll-step-title"><span class="poll-step-num">3</span><strong>Para quais grupos?</strong></div>
      <div class="poll-groups">
        <?php if(!$groups):?>
          <div class="target-empty">Nenhum grupo cadastrado. <a href="/groups.php">Cadastre ou sincronize os grupos</a>.</div>
        <?php endif?>
        <?php foreach($groups as $g):
          $p=json_decode((string)($g['purposes']??'[]'),true);
          $isPoll=is_array($p)&&in_array('polls',$p,true);
        ?>
          <label class="poll-group">
            <input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>">
            <span>
              <strong><?=e($g['name'])?><?=$isPoll?' · Enquetes':''?></strong>
              <small><?=e($g['congregation_name']?:'Igreja geral / Matriz')?><?=empty($g['evolution_group_jid'])?' · ⚠ sem JID Evolution':''?></small>
            </span>
          </label>
        <?php endforeach?>
      </div>
      <?php if($purposePollGroups):?><small class="help-text">Se nenhum grupo for marcado, serão usados os <?=count($purposePollGroups)?> grupo(s) configurados com a finalidade “Enquetes”.</small><?php endif?>
    </div>

    <div class="poll-step">
      <div class="poll-step-title"><span class="poll-step-num">4</span><strong>Quando enviar?</strong></div>
      <div class="poll-send-cards">
        <label class="poll-send-card"><input type="radio" name="send_mode" value="now" checked><strong>Enviar agora</strong><small>Cria e envia imediatamente ao WhatsApp.</small></label>
        <label class="poll-send-card"><input type="radio" name="send_mode" value="schedule"><strong>Agendar</strong><small>Escolha a data e o horário do envio.</small></label>
        <label class="poll-send-card"><input type="radio" name="send_mode" value="draft"><strong>Salvar rascunho</strong><small>Guarda sem enviar.</small></label>
      </div>

      <div id="schedule-fields" style="display:none;margin-top:14px">
        <div class="poll-two">
          <div class="field"><label>Enviar em *</label><input class="input" name="scheduled_at" type="datetime-local"></div>
          <div class="field"><label>Encerrar / expirar em</label><input class="input" name="expires_at" type="datetime-local"></div>
        </div>
      </div>

      <div class="poll-two" style="margin-top:14px">
        <div class="field"><label>Validade exibida na mensagem</label><input class="input" name="validity" placeholder="Ex.: até amanhã às 18h"></div>
        <div class="field"><label>Identificação interna</label><input class="input" name="template_name" placeholder="Ex.: Culto de jovens - outubro"></div>
      </div>
    </div>

    <div class="poll-summary">
      <strong>Como funciona o envio</strong>
      <p>Ao escolher “Enviar agora”, o sistema só marcará a enquete como enviada depois que o WhatsApp confirmar pelo menos um grupo. Se houver erro, ele aparecerá nesta página.</p>
    </div>

    <div class="poll-actions">
      <button class="btn btn-primary" type="submit">📊 Criar enquete</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="settings-section-head" style="padding:18px 18px 6px">
    <div class="settings-icon">✓</div>
    <div><h2>Enquetes criadas</h2><p>Acompanhe envio, agendamento e reenvie quando precisar lembrar o grupo.</p></div>
  </div>

  <div class="poll-list" style="padding:12px 18px 18px">
    <?php foreach($rows as $row):
      $targetIds=json_decode((string)($row['target_groups']??'[]'),true);
      if(!is_array($targetIds))$targetIds=[];
      $status=(string)$row['status'];
      $statusLabel=match($status){'sent'=>'Enviada','scheduled'=>'Agendada','error'=>'Erro de envio','draft'=>'Rascunho',default=>$status};
    ?>
      <article class="poll-card">
        <div class="poll-card-top">
          <h3><?=e($row['question'])?></h3>
          <span class="badge poll-status-<?=e($status)?>"><?=e($statusLabel)?></span>
        </div>
        <div class="poll-meta">
          <span><?=count(json_decode((string)$row['options'],true)?:[])?> opções</span>
          <span><?=$targetIds?count($targetIds).' grupo(s) escolhido(s)':'Grupos de finalidade Enquetes'?></span>
          <?php if(!empty($row['sent_at'])):?><span>Enviada <?=e(date('d/m H:i',strtotime($row['sent_at'])))?></span><?php endif?>
          <?php if(!empty($row['scheduled_at'])&&$status==='scheduled'):?><span>Para <?=e(date('d/m H:i',strtotime($row['scheduled_at'])))?></span><?php endif?>
        </div>
        <div class="poll-card-actions">
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn btn-light small">↻ Reenviar</button></form>
          <form method="post" onsubmit="return confirm('Excluir esta enquete?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn btn-danger small">Excluir</button></form>
        </div>
      </article>
    <?php endforeach?>
    <?php if(!$rows):?><div class="poll-empty">Nenhuma enquete criada ainda.</div><?php endif?>
  </div>
</section>
</div>

<script>
(function(){
  const add=document.getElementById('add-option');
  const container=document.getElementById('poll-options');
  let count=4;

  add?.addEventListener('click',function(){
    const hidden=container.querySelector('[data-extra="1"][style*="display:none"]');
    if(hidden){hidden.style.display='grid';hidden.removeAttribute('data-extra');return;}
    if(count>=8){add.disabled=true;return;}
    count++;
    const label=document.createElement('label');
    label.className='poll-option';
    label.innerHTML='<span>'+count+'</span><input class="input" name="options[]" placeholder="Opção '+count+'">';
    container.appendChild(label);
    if(count>=8)add.disabled=true;
  });

  const schedule=document.getElementById('schedule-fields');
  document.querySelectorAll('input[name="send_mode"]').forEach(function(radio){
    radio.addEventListener('change',function(){
      schedule.style.display=this.value==='schedule'&&this.checked?'block':'none';
      const field=schedule.querySelector('input[name="scheduled_at"]');
      if(field)field.required=this.value==='schedule'&&this.checked;
    });
  });
})();
</script>
<?php View::footer(); ?>
