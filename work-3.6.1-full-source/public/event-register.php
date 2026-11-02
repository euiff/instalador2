<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/EventRegistrationService.php';
require_once __DIR__.'/lib/PixPayloadService.php';

$token=trim((string)($_GET['t']??''));
$event=$token!==''?EventRegistrationService::publicEvent($pdo,$token):null;
$error=null;$result=null;$pixPayload=null;$qrUrl=null;

if(!$event){
    http_response_code(404);
    $error='Evento indisponível ou inscrições encerradas.';
}elseif($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $result=EventRegistrationService::registerPublic($pdo,$event,$_POST);
        if($result['payment_method']==='pix'&&$result['amount_due']>0){
            $pixPayload=PixPayloadService::payload(
                (string)($event['pix_key']??''),
                (float)$result['amount_due'],
                (string)($event['donation_merchant_name']??$event['church_name']??'IGREJA'),
                (string)($event['donation_merchant_city']??'BRASIL'),
                'Inscricao '.mb_substr((string)$event['title'],0,40)
            );
            $qrUrl='https://quickchart.io/qr?size=320&text='.rawurlencode($pixPayload);
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$methods=json_decode((string)($event['registration_payment_methods']??'[]'),true);
if(!is_array($methods))$methods=[];
$fee=(float)($event['registration_fee']??0);
$registered=(int)($event['registered_people']??0);
$available=!empty($event['capacity'])?max(0,(int)$event['capacity']-$registered):null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inscrição · <?=e($event['title']??'Evento')?></title>
<style>
:root{font-family:Inter,Arial,sans-serif;color:#172438;background:#f5f8fb}*{box-sizing:border-box}body{margin:0;padding:22px}.shell{max-width:760px;margin:3vh auto}.card{background:#fff;border:1px solid #dde6ee;border-radius:20px;padding:24px;box-shadow:0 14px 38px rgba(30,70,110,.08)}.brand{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:#6f8295}.hero h1{margin:8px 0 8px;font-size:28px}.muted{color:#66778a;line-height:1.55}.facts{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin:18px 0}.fact{padding:11px;background:#f6f9fc;border-radius:12px}.fact small{display:block;color:#748498}.field{margin:12px 0}.field label{display:block;font-size:13px;font-weight:800;margin-bottom:6px}.input,.select,.textarea{width:100%;padding:12px;border:1px solid #cdd9e5;border-radius:11px;font:inherit}.textarea{min-height:80px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.btn{display:inline-block;border:0;border-radius:12px;padding:13px 16px;font-weight:800;background:#256aa7;color:#fff;text-decoration:none;cursor:pointer}.alert{padding:12px 14px;border-radius:12px;margin:14px 0}.err{background:#fff0f0;color:#922}.ok{background:#ecf9f0;color:#17623a}.pix{margin-top:18px;padding:18px;background:#f8fbff;border:1px solid #d9e8f6;border-radius:16px;text-align:center}.pix img{width:260px;max-width:100%}.copy{width:100%;min-height:86px;padding:10px;border:1px solid #cdd9e5;border-radius:10px;word-break:break-all}.status{font-weight:900;font-size:18px}.pay-options{display:grid;gap:8px}.pay-option{display:flex;gap:9px;align-items:center;padding:10px;border:1px solid #d8e1ea;border-radius:10px}@media(max-width:620px){body{padding:12px}.card{padding:18px}.facts,.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell"><div class="card">
<?php if($event):?>
  <div class="brand"><?=e($event['church_name'])?></div>
  <div class="hero"><h1><?=e($event['title'])?></h1><p class="muted"><?=e($event['description']?:'Inscrição para programação da igreja.')?></p></div>
  <div class="facts">
    <div class="fact"><small>Data</small><strong><?=date('d/m/Y H:i',strtotime($event['event_date']))?></strong></div>
    <div class="fact"><small>Local</small><strong><?=e($event['location']?:'A definir')?></strong></div>
    <div class="fact"><small>Vagas</small><strong><?=$available===null?'Sem limite':$available.' disponíveis'?></strong></div>
  </div>
  <?php if($fee>0):?><p><strong>Valor por participante:</strong> R$ <?=number_format($fee,2,',','.')?></p><?php else:?><p><strong>Evento gratuito.</strong></p><?php endif?>
<?php endif?>

<?php if($error):?><div class="alert err"><?=e($error)?></div><?php endif?>

<?php if($result):?>
  <div class="alert ok"><div class="status"><?=$result['registration_status']==='confirmed'?'Inscrição confirmada':'Inscrição recebida'?></div><div><?=e($event['title'])?> · <?=$result['people']?> pessoa(s)</div></div>
  <?php if($result['amount_due']>0):?><p><strong>Total:</strong> R$ <?=number_format((float)$result['amount_due'],2,',','.')?></p><?php endif?>
  <?php if($result['payment_method']==='pix'&&$pixPayload):?>
    <div class="pix"><h2>Pagamento por PIX</h2><img src="<?=e($qrUrl)?>" alt="QR Code PIX"><p class="muted">Escaneie o QR ou copie o código abaixo. A confirmação do pagamento é feita pela equipe da igreja.</p><textarea class="copy" id="pix-copy" readonly><?=e($pixPayload)?></textarea><button class="btn" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('pix-copy').value)">Copiar PIX</button></div>
  <?php elseif($result['payment_method']==='local'):?><div class="alert"><strong>Pagamento no local:</strong> sua inscrição ficará pendente até a confirmação da equipe.</div><?php endif?>
<?php elseif($event):?>
  <form method="post">
    <?=csrf_field()?>
    <div class="grid">
      <div class="field"><label>Nome completo *</label><input class="input" name="name" required maxlength="255"></div>
      <div class="field"><label>Telefone / WhatsApp *</label><input class="input" name="phone" required maxlength="40" inputmode="tel"></div>
      <div class="field"><label>E-mail <?=!empty($event['registration_require_email'])?'*':''?></label><input class="input" type="email" name="email" <?=!empty($event['registration_require_email'])?'required':''?>></div>
      <div class="field"><label>Data de nascimento</label><input class="input" type="date" name="birth_date"></div>
      <div class="field"><label>CPF / documento <?=!empty($event['registration_require_document'])?'*':''?></label><input class="input" name="cpf" <?=!empty($event['registration_require_document'])?'required':''?>></div>
      <div class="field"><label>Convidados adicionais</label><input class="input" type="number" min="0" max="20" name="guests_count" value="0"></div>
    </div>
    <?php if($fee>0):?>
      <div class="field"><label>Forma de pagamento *</label><div class="pay-options">
        <?php if(in_array('pix',$methods,true)):?><label class="pay-option"><input type="radio" name="payment_method" value="pix" required> PIX</label><?php endif?>
        <?php if(in_array('local',$methods,true)):?><label class="pay-option"><input type="radio" name="payment_method" value="local" required> Pagar no local / confirmação manual</label><?php endif?>
      </div></div>
    <?php endif?>
    <div class="field"><label>Observações</label><textarea class="textarea" name="notes" maxlength="2000"></textarea></div>
    <button class="btn">Concluir inscrição</button>
  </form>
<?php endif?>
</div></div>
</body></html>