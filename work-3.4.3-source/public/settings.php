<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/EvolutionClient.php';

$user=$auth->requireAbility('settings');
$church=$auth->currentChurch();
if(!$church)exit('Igreja não selecionada.');
$churchId=(string)$church['id'];

$q=$pdo->prepare('SELECT * FROM churches WHERE id=? LIMIT 1');
$q->execute([$churchId]);
$s=$q->fetch()?:[];

// O webhook deve existir mesmo antes da primeira configuração da Evolution.
// Assim o usuário sempre vê uma URL pronta para copiar/configurar.
if(trim((string)($s['evolution_webhook_token']??''))===''){
    $token=bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE churches SET evolution_webhook_token=? WHERE id=?')->execute([$token,$churchId]);
    $s['evolution_webhook_token']=$token;
}

$actionResult=null;
$actionError=null;
$qrImage=null;
$webhookDiagnostic=null;

$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$host=(string)($_SERVER['HTTP_HOST']??'');
$basePublicUrl=$host!==''?$scheme.'://'.$host:'';

function settings_secret(array $row,string $postKey,string $column): ?string {
    $posted=trim((string)($_POST[$postKey]??''));
    if($posted!=='')return $posted;
    $current=trim((string)($row[$column]??''));
    return $current!==''?$current:null;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'save');

        $webhookToken=trim((string)($s['evolution_webhook_token']??''));
        if($webhookToken==='')$webhookToken=bin2hex(random_bytes(24));

        $evolutionKey=settings_secret($s,'evolution_api_key','evolution_api_key');
        $geminiKey=settings_secret($s,'gemini_api_key','gemini_api_key');
        $ttsKey=settings_secret($s,'tts_api_key','tts_api_key');

        $values=[
            'name'=>trim((string)($_POST['name']??'')),
            'phone'=>null_if_blank($_POST['phone']??null),
            'email'=>null_if_blank($_POST['email']??null),
            'address'=>null_if_blank($_POST['address']??null),
            'pastor_name'=>null_if_blank($_POST['pastor_name']??null),
            'pix_key'=>null_if_blank($_POST['pix_key']??null),
            'welcome_message'=>null_if_blank($_POST['welcome_message']??null),
            'evolution_api_url'=>null_if_blank($_POST['evolution_api_url']??null),
            'evolution_api_key'=>$evolutionKey,
            'evolution_instance_name'=>null_if_blank($_POST['evolution_instance_name']??null),
            'evolution_webhook_token'=>$webhookToken,
            'gemini_api_key'=>$geminiKey,
            'tts_enabled'=>isset($_POST['tts_enabled'])?1:0,
            'tts_api_key'=>$ttsKey,
            'tts_voice_id'=>null_if_blank($_POST['tts_voice_id']??null),
            'daily_devotional_time'=>null_if_blank($_POST['daily_devotional_time']??null),
            'ai_enabled'=>isset($_POST['ai_enabled'])?1:0,
            'transparency_enabled'=>isset($_POST['transparency_enabled'])?1:0,
        ];
        if($values['name']==='')throw new RuntimeException('Informe o nome da igreja.');

        $sql='UPDATE churches SET name=?,phone=?,email=?,address=?,pastor_name=?,pix_key=?,welcome_message=?,evolution_api_url=?,evolution_api_key=?,evolution_instance_name=?,evolution_webhook_token=?,gemini_api_key=?,tts_enabled=?,tts_api_key=?,tts_voice_id=?,daily_devotional_time=?,ai_enabled=?,transparency_enabled=? WHERE id=?';
        $pdo->prepare($sql)->execute([
            $values['name'],$values['phone'],$values['email'],$values['address'],$values['pastor_name'],
            $values['pix_key'],$values['welcome_message'],$values['evolution_api_url'],$values['evolution_api_key'],
            $values['evolution_instance_name'],$values['evolution_webhook_token'],$values['gemini_api_key'],
            $values['tts_enabled'],$values['tts_api_key'],$values['tts_voice_id'],$values['daily_devotional_time'],
            $values['ai_enabled'],$values['transparency_enabled'],$churchId
        ]);

        $q=$pdo->prepare('SELECT * FROM churches WHERE id=? LIMIT 1');$q->execute([$churchId]);$s=$q->fetch()?:[];
        $webhookUrl=$basePublicUrl.'/api/whatsapp-webhook.php?token='.rawurlencode((string)$s['evolution_webhook_token']);
        $client=new EvolutionClient((string)($s['evolution_api_url']??''),(string)($s['evolution_api_key']??''),(string)($s['evolution_instance_name']??''));

        if($action==='test_whatsapp'){
            $state=$client->connectionState();
            $actionResult='Conexão com a Evolution API confirmada. Estado da instância: '.($state['state']??'resposta recebida').'.';
        }elseif($action==='setup_webhook'){
            $client->configureWebhook($webhookUrl);
            try{$webhookDiagnostic=$client->webhookInfo();}catch(Throwable){}
            $actionResult='Webhook configurado na Evolution API com sucesso.';
        }elseif($action==='check_webhook'){
            $webhookDiagnostic=$client->webhookInfo();
            $remoteUrl=(string)($webhookDiagnostic['url']??'');
            if($remoteUrl!==''&&hash_equals($webhookUrl,$remoteUrl)){
                $actionResult='Webhook conferido: a Evolution está apontando para a URL correta desta igreja.';
            }elseif($remoteUrl!==''){
                $actionError='A Evolution está apontando para outro webhook. Clique em “Configurar webhook” para corrigir.';
            }else{
                $actionError='A Evolution respondeu, mas não informou uma URL de webhook configurada.';
            }
        }elseif($action==='show_qr'){
            $qr=$client->qrCode();
            $qrImage=$qr['base64']??null;
            $actionResult=$qrImage?'QR Code recebido. Leia com o WhatsApp.':'A Evolution respondeu, mas não retornou uma imagem de QR Code. A instância pode já estar conectada.';
        }elseif($action==='send_test'){
            $phone=trim((string)($_POST['test_phone']??''));
            if($phone==='')throw new RuntimeException('Informe o número para receber a mensagem de teste, com DDD e código do país.');
            $client->sendText($phone,'✅ Teste do Igreja Master: integração WhatsApp/Evolution funcionando.');
            $actionResult='Mensagem de teste enviada para '.$phone.'.';
        }else{
            $webhookNotice='';
            if($client->configured()){
                try{
                    $client->configureWebhook($webhookUrl);
                    $webhookNotice=' Webhook da Evolution também atualizado.';
                }catch(Throwable){}
            }
            flash('success','Configurações salvas com sucesso.'.$webhookNotice);
            redirect('/settings.php');
        }
    }catch(Throwable $e){
        $actionError=$e->getMessage();
    }
}

$webhookToken=trim((string)($s['evolution_webhook_token']??''));
$webhookUrl=$webhookToken!==''&&$basePublicUrl!==''?$basePublicUrl.'/api/whatsapp-webhook.php?token='.rawurlencode($webhookToken):'Salve as configurações para gerar a URL segura.';
$whatsappConfigured=!empty($s['evolution_api_url'])&&!empty($s['evolution_api_key'])&&!empty($s['evolution_instance_name']);

$lastWebhook=null;
$lastGroupInbound=null;
try{
    $q=$pdo->prepare("SELECT event_type,status,created_at FROM webhook_logs WHERE church_id=? AND source='evolution' ORDER BY created_at DESC LIMIT 1");
    $q->execute([$churchId]);$lastWebhook=$q->fetch()?:null;

    $q=$pdo->prepare("SELECT phone,message,created_at FROM bot_messages WHERE church_id=? AND direction='inbound' AND message_type='group' ORDER BY created_at DESC LIMIT 1");
    $q->execute([$churchId]);$lastGroupInbound=$q->fetch()?:null;
}catch(Throwable){}

View::header('Configurações','settings',$auth,$church);
?>
<div class="page-head">
  <div><h1>Configurações</h1><p>Dados da igreja, WhatsApp, inteligência artificial, voz e transparência.</p></div>
</div>
<?php View::flash(); ?>
<?php if($actionResult):?><div class="alert alert-success"><?=e($actionResult)?></div><?php endif?>
<?php if($actionError):?><div class="alert alert-error"><?=e($actionError)?></div><?php endif?>

<form method="post" class="settings-form">
<?=csrf_field()?>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">⌂</div>
    <div><h2>Dados da igreja</h2><p>Informações usadas nos documentos, relatórios, bot e portal público.</p></div>
  </div>
  <div class="form-grid">
    <div class="field"><label>Nome da igreja *</label><input class="input" name="name" value="<?=e($s['name']??'')?>" required></div>
    <div class="field"><label>Telefone</label><input class="input" name="phone" value="<?=e($s['phone']??'')?>" placeholder="64 99999-9999"></div>
    <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?=e($s['email']??'')?>"></div>
    <div class="field"><label>Pastor responsável</label><input class="input" name="pastor_name" value="<?=e($s['pastor_name']??'')?>"></div>
    <div class="field full"><label>Endereço</label><input class="input" name="address" value="<?=e($s['address']??'')?>"></div>
    <div class="field"><label>Chave PIX</label><input class="input" name="pix_key" value="<?=e($s['pix_key']??'')?>" placeholder="CPF, CNPJ, e-mail, telefone ou chave aleatória"></div>
    <div class="field"><label>Horário do devocional diário</label><input class="input" type="time" name="daily_devotional_time" value="<?=e(!empty($s['daily_devotional_time'])?substr((string)$s['daily_devotional_time'],0,5):'')?>"></div>
    <div class="field full"><label>Mensagem de boas-vindas do bot</label><textarea class="textarea" name="welcome_message" placeholder="Ex.: 🙏 Seja bem-vindo à nossa igreja!"><?=e($s['welcome_message']??'')?></textarea></div>
  </div>
</section>

<section class="settings-section card integration-card">
  <div class="settings-section-head">
    <div class="settings-icon whatsapp-icon">☏</div>
    <div class="grow"><h2>WhatsApp · Evolution API</h2><p>Conecte a instância do WhatsApp ao bot do Igreja Master.</p></div>
    <span class="status-pill <?=$whatsappConfigured?'status-ok':'status-off'?>"><?=$whatsappConfigured?'Dados preenchidos':'Não configurado'?></span>
  </div>

  <div class="setup-steps">
    <div><strong>1</strong><span>Informe a URL da Evolution API</span></div>
    <div><strong>2</strong><span>Informe a API Key global</span></div>
    <div><strong>3</strong><span>Informe exatamente o nome da instância</span></div>
    <div><strong>4</strong><span>Salve e teste a conexão</span></div>
    <div><strong>5</strong><span>Configure o webhook automático</span></div>
  </div>

  <div class="form-grid">
    <div class="field full">
      <label>URL da Evolution API</label>
      <input class="input" name="evolution_api_url" value="<?=e($s['evolution_api_url']??'')?>" placeholder="https://evolution.seudominio.com.br">
      <small class="help-text">Coloque somente a URL base da Evolution, sem /manager, /instance ou outras rotas no final.</small>
    </div>
    <div class="field">
      <label>Nome da instância</label>
      <input class="input" name="evolution_instance_name" value="<?=e($s['evolution_instance_name']??'')?>" placeholder="ex.: casa-da-bencao">
      <small class="help-text">Tem que ser exatamente o mesmo nome cadastrado no painel da Evolution.</small>
    </div>
    <div class="field">
      <label>API Key</label>
      <input class="input" type="password" name="evolution_api_key" value="" placeholder="<?=!empty($s['evolution_api_key'])?'Chave já salva · deixe em branco para manter':'Cole aqui a API Key da Evolution'?>" autocomplete="new-password">
      <small class="help-text">A chave fica salva no servidor e não é exibida novamente nesta tela.</small>
    </div>
    <div class="field full">
      <label>Webhook desta igreja</label>
      <div class="copy-row">
        <input class="input code-input" id="webhook-url" value="<?=e($webhookUrl)?>" readonly>
        <button class="btn btn-light" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('webhook-url').value)">Copiar URL</button>
      </div>
      <small class="help-text">O botão “Configurar webhook” envia essa URL automaticamente para a Evolution API.</small>
    </div>
  </div>

  <div class="integration-status">
    <div><span>Último webhook recebido</span><strong><?=$lastWebhook?e(date('d/m/Y H:i',strtotime($lastWebhook['created_at']))):'Ainda nenhum'?></strong></div>
    <div><span>Último evento</span><strong><?=e($lastWebhook['event_type']??'—')?></strong></div>
    <div><span>Status do webhook</span><strong><?=e($lastWebhook['status']??'—')?></strong></div>
    <div><span>Última mensagem de grupo</span><strong><?=$lastGroupInbound?e(date('d/m/Y H:i',strtotime($lastGroupInbound['created_at']))):'Ainda nenhuma'?></strong></div>
  </div>

  <?php if($lastGroupInbound):?>
    <div class="webhook-diagnostic">
      <strong>Última mensagem recebida de grupo</strong>
      <p><?=e($lastGroupInbound['message'])?></p>
    </div>
  <?php endif?>

  <?php if($webhookDiagnostic):?>
    <div class="webhook-diagnostic">
      <strong>Webhook informado pela Evolution</strong>
      <p class="code-input"><?=e((string)($webhookDiagnostic['url']??'URL não informada'))?></p>
      <small>Ativo: <?=($webhookDiagnostic['enabled']??null)===false?'não':'sim / não informado'?> · Eventos: <?=e(implode(', ',$webhookDiagnostic['events']??[]))?></small>
    </div>
  <?php endif?>

  <div class="integration-actions">
    <button class="btn btn-primary" type="submit" name="action" value="test_whatsapp">Testar conexão</button>
    <button class="btn btn-light" type="submit" name="action" value="setup_webhook">Configurar webhook</button>
    <button class="btn btn-light" type="submit" name="action" value="check_webhook">Verificar webhook</button>
    <button class="btn btn-light" type="submit" name="action" value="show_qr">Gerar / atualizar QR</button>
  </div>

  <?php if($qrImage):?>
    <div class="qr-panel"><img src="<?=e($qrImage)?>" alt="QR Code do WhatsApp"><div><strong>Leia este QR Code pelo WhatsApp</strong><p>Abra WhatsApp → Aparelhos conectados → Conectar um aparelho.</p></div></div>
  <?php endif?>

  <div class="test-message-box">
    <div class="field"><label>Número para teste</label><input class="input" name="test_phone" value="<?=e($_POST['test_phone']??'')?>" placeholder="5564999999999"></div>
    <button class="btn btn-light" type="submit" name="action" value="send_test">Enviar mensagem de teste</button>
  </div>
</section>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">✦</div>
    <div><h2>Inteligência artificial e voz</h2><p>Gemini para respostas do bot e ElevenLabs/TTS para geração de áudio.</p></div>
  </div>
  <div class="form-grid">
    <label class="toggle-row full"><input type="checkbox" name="ai_enabled" <?=!empty($s['ai_enabled'])?'checked':''?>><span><strong>Ativar IA</strong><small>Permite respostas assistidas, transcrição e recursos inteligentes.</small></span></label>
    <div class="field full"><label>Gemini API Key</label><input class="input" type="password" name="gemini_api_key" value="" placeholder="<?=!empty($s['gemini_api_key'])?'Chave já salva · deixe em branco para manter':'Cole a chave do Google Gemini'?>" autocomplete="new-password"></div>
    <label class="toggle-row full"><input type="checkbox" name="tts_enabled" <?=!empty($s['tts_enabled'])?'checked':''?>><span><strong>Ativar voz</strong><small>Permite ao sistema enviar áudios quando a rotina utilizar TTS.</small></span></label>
    <div class="field"><label>TTS Voice ID</label><input class="input" name="tts_voice_id" value="<?=e($s['tts_voice_id']??'')?>" placeholder="Voice ID do ElevenLabs"></div>
    <div class="field"><label>TTS API Key</label><input class="input" type="password" name="tts_api_key" value="" placeholder="<?=!empty($s['tts_api_key'])?'Chave já salva · deixe em branco para manter':'API Key do TTS'?>" autocomplete="new-password"></div>
  </div>
</section>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">▥</div>
    <div><h2>Portal de transparência</h2><p>Controle a publicação pública dos dados financeiros preparados para prestação de contas.</p></div>
  </div>
  <label class="toggle-row"><input type="checkbox" name="transparency_enabled" <?=!empty($s['transparency_enabled'])?'checked':''?>><span><strong>Ativar portal público de transparência</strong><small>Quando ativado, o endereço público da igreja poderá ser compartilhado.</small></span></label>
  <?php if(!empty($s['transparency_enabled'])):?><div class="section-actions"><a class="btn btn-light" target="_blank" href="/transparencia/<?=e($s['slug'])?>">Abrir portal público ↗</a></div><?php endif?>
</section>

<div class="settings-savebar">
  <div><strong>Configurações da <?=e($s['name']??'igreja')?></strong><span>Salve antes de sair da página.</span></div>
  <button class="btn btn-primary btn-lg" type="submit" name="action" value="save">Salvar configurações</button>
</div>

</form>
<?php View::footer(); ?>
