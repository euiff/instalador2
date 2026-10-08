<?php
require __DIR__.'/bootstrap.php';

$user=$auth->requireAbility('settings');
$church=$auth->currentChurch();
if(!$church)exit('Igreja não selecionada.');
$churchId=(string)$church['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $amounts=array_values(array_filter(array_map('floatval',preg_split('/\s*,\s*/',(string)($_POST['donation_amounts']??''))),fn($v)=>$v>0));
        $fields=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',(string)($_POST['registration_fields']??'')))));
        $q=$pdo->prepare('INSERT INTO bot_settings(id,church_id,menu_text,schedule_text,donation_amounts,ai_prompt,thank_you_message,event_registration_enabled,event_registration_event_id,event_registration_menu_text,registration_fields) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE menu_text=VALUES(menu_text),schedule_text=VALUES(schedule_text),donation_amounts=VALUES(donation_amounts),ai_prompt=VALUES(ai_prompt),thank_you_message=VALUES(thank_you_message),event_registration_enabled=VALUES(event_registration_enabled),event_registration_event_id=VALUES(event_registration_event_id),event_registration_menu_text=VALUES(event_registration_menu_text),registration_fields=VALUES(registration_fields)');
        $q->execute([
            app_uuid(),$churchId,
            null_if_blank($_POST['menu_text']??null),
            null_if_blank($_POST['schedule_text']??null),
            json_encode($amounts,JSON_UNESCAPED_UNICODE),
            null_if_blank($_POST['ai_prompt']??null),
            null_if_blank($_POST['thank_you_message']??null),
            isset($_POST['event_registration_enabled'])?1:0,
            null_if_blank($_POST['event_registration_event_id']??null),
            null_if_blank($_POST['event_registration_menu_text']??null),
            json_encode($fields,JSON_UNESCAPED_UNICODE)
        ]);
        flash('success','Configuração do bot salva com sucesso.');
        redirect('/bot-settings.php');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect('/bot-settings.php');
    }
}

$q=$pdo->prepare('SELECT * FROM bot_settings WHERE church_id=? LIMIT 1');
$q->execute([$churchId]);
$s=$q->fetch()?:[];

$events=$pdo->prepare('SELECT id,title,event_date FROM events WHERE church_id=? AND active=1 ORDER BY event_date DESC');
$events->execute([$churchId]);
$events=$events->fetchAll();

$amounts=isset($s['donation_amounts'])?(json_decode((string)$s['donation_amounts'],true)?:[]):[];
$fields=isset($s['registration_fields'])?(json_decode((string)$s['registration_fields'],true)?:[]):[];

$whatsappReady=!empty($church['evolution_api_url'])&&!empty($church['evolution_api_key'])&&!empty($church['evolution_instance_name']);

$defaultMenu="1️⃣ Horários dos Cultos\n2️⃣ Pedido de Oração\n3️⃣ Bíblia e Plano de Leitura\n4️⃣ Eventos e Inscrições\n5️⃣ Falar com a Igreja\n6️⃣ Documentos\n7️⃣ Cadastro de Membro\n8️⃣ Meus Dados\n9️⃣ Aconselhamento Pastoral";

View::header('Configuração do Bot','bot-settings',$auth,$church);
?>
<div class="page-head">
  <div><h1>Configuração do Bot</h1><p>Organize o atendimento do WhatsApp por funções claras: cultos, oração, Bíblia, eventos, documentos, cadastro e aconselhamento.</p></div>
  <a class="btn btn-light" href="/settings.php">Configurar WhatsApp</a>
</div>
<?php View::flash(); ?>

<div class="integration-summary card card-pad" style="margin-bottom:16px">
  <div>
    <strong>WhatsApp / Evolution API</strong>
    <span class="muted"><?=$whatsappReady?'A integração possui URL, chave e instância preenchidas.':'A integração ainda não está completa.'?></span>
  </div>
  <span class="status-pill <?=$whatsappReady?'status-ok':'status-off'?>"><?=$whatsappReady?'Configurado':'Pendente'?></span>
</div>

<form method="post" class="settings-form">
<?=csrf_field()?>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">☰</div>
    <div><h2>Menu principal</h2><p>É a mensagem mostrada quando a pessoa envia “oi”, “menu”, “início” ou “0”.</p></div>
  </div>
  <div class="form-grid">
    <div class="field full">
      <label>Texto do menu</label>
      <textarea class="textarea tall" name="menu_text" placeholder="<?=e($defaultMenu)?>"><?=e($s['menu_text']??'')?></textarea>
      <small class="help-text">Se deixar vazio, o sistema usa o menu novo. Menus antigos com PIX na opção 3 ou “Palavra Bíblica” na opção 6 são substituídos automaticamente para evitar funções duplicadas.</small>
    </div>
    <div class="field full">
      <label>Horários dos cultos</label>
      <textarea class="textarea" name="schedule_text" placeholder="Ex.: Domingo 19h · Quarta 19h30 · Sexta 19h30"><?=e($s['schedule_text']??'')?></textarea>
      <small class="help-text">Esse texto é enviado quando a pessoa escolhe a opção 1.</small>
    </div>
  </div>
</section>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">◆</div>
    <div><h2>Contribuições opcionais</h2><p>Configuração mantida apenas para quem ainda usa contribuições pelo WhatsApp. Ela não faz parte do menu principal novo nem da Tesouraria.</p></div>
  </div>
  <div class="form-grid">
    <div class="field">
      <label>Valores sugeridos para contribuição</label>
      <input class="input" name="donation_amounts" value="<?=e(implode(', ',$amounts))?>" placeholder="20, 50, 100">
      <small class="help-text">Separe os valores por vírgula.</small>
    </div>
    <div class="field">
      <label>Chave PIX atual</label>
      <input class="input" value="<?=e($church['pix_key']??'Não configurada')?>" readonly>
      <small class="help-text"><a href="/settings.php">Alterar em Configurações → Dados da igreja.</a></small>
    </div>
    <div class="field full">
      <label>Mensagem de agradecimento</label>
      <textarea class="textarea" name="thank_you_message" placeholder="Ex.: Deus abençoe sua contribuição!"><?=e($s['thank_you_message']??'')?></textarea>
    </div>
  </div>
</section>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">✦</div>
    <div><h2>Comportamento da IA</h2><p>Oriente o tom das respostas inteligentes do bot.</p></div>
  </div>
  <div class="field">
    <label>Instrução / prompt da IA</label>
    <textarea class="textarea tall" name="ai_prompt" placeholder="Ex.: Responda com linguagem cristã acolhedora, objetiva e respeitosa. Não invente informações."><?=e($s['ai_prompt']??'')?></textarea>
    <small class="help-text">A chave Gemini é configurada em Configurações. Aqui você define somente o comportamento.</small>
  </div>
</section>

<section class="settings-section card">
  <div class="settings-section-head">
    <div class="settings-icon">□</div>
    <div><h2>Inscrição em eventos pelo bot</h2><p>Permita que a pessoa se inscreva em um evento diretamente pelo WhatsApp.</p></div>
  </div>
  <div class="form-grid">
    <label class="toggle-row full"><input type="checkbox" name="event_registration_enabled" <?=!empty($s['event_registration_enabled'])?'checked':''?>><span><strong>Ativar inscrição pelo bot</strong><small>O recurso só será usado quando houver um evento selecionado.</small></span></label>
    <div class="field full">
      <label>Evento</label>
      <select class="select" name="event_registration_event_id">
        <option value="">Nenhum evento selecionado</option>
        <?php foreach($events as $event):?>
          <option value="<?=e($event['id'])?>" <?=($s['event_registration_event_id']??'')===$event['id']?'selected':''?>><?=e($event['title'])?> · <?=date('d/m/Y',strtotime($event['event_date']))?></option>
        <?php endforeach?>
      </select>
    </div>
    <div class="field full">
      <label>Texto do menu de inscrição</label>
      <textarea class="textarea" name="event_registration_menu_text" placeholder="Ex.: Para se inscrever, envie seu nome completo."><?=e($s['event_registration_menu_text']??'')?></textarea>
    </div>
    <div class="field full">
      <label>Campos adicionais</label>
      <textarea class="textarea" name="registration_fields" placeholder="CPF&#10;Cidade&#10;Congregação"><?=e(implode("\n",$fields))?></textarea>
      <small class="help-text">Coloque um campo por linha. Use só o que realmente precisar perguntar.</small>
    </div>
  </div>
</section>

<div class="settings-savebar">
  <div><strong>Configuração do bot</strong><span>Salve para aplicar as mudanças nas próximas conversas.</span></div>
  <button class="btn btn-primary btn-lg" type="submit">Salvar configuração do bot</button>
</div>
</form>
<?php View::footer(); ?>
