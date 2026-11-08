<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/ContributionWorkflow.php';
require_once __DIR__.'/lib/TenantBranding.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$flow=new ContributionWorkflow($pdo);
$token=trim((string)($_GET['t']??$_POST['t']??''));
$church=$flow->publicChurch($token);
if (!$church) {
    http_response_code(404);
    exit('Formulário não encontrado ou desativado.');
}

$theme=TenantBranding::normalize($church);
$types=$flow->catalog((string)$church['id'],'type');
$payments=$flow->catalog((string)$church['id'],'payment');
$campaigns=$flow->campaigns((string)$church['id']);
$error=null;
$successful=isset($_GET['enviado'])&&$_GET['enviado']==='1' && !empty($_SESSION['contribution_completed'][(string)$church['id']]);
if($successful)unset($_SESSION['contribution_completed'][(string)$church['id']]);
$requestKey=(string)($_POST['request_key']??bin2hex(random_bytes(32)));

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        verify_csrf();
        $userId=null;$memberId=null;
        if (isset($_SESSION['user_id'])) {
            $current=$auth->currentChurch();
            $membership=$auth->currentMembership();
            if ((string)($current['id']??'')===(string)$church['id'] &&
                (string)($membership['church_id']??'')===(string)$church['id']) {
                $userId=(string)$_SESSION['user_id'];
                $memberId=trim((string)($membership['person_id']??''))?:null;
            }
        }
        $result=$flow->submit($church,$_POST,$_FILES['proof_file']??[],new UploadService(__DIR__),$memberId,$userId);
        $_SESSION['contribution_completed'][(string)$church['id']] = $result['id'];
        redirect('/contribute.php?t='.rawurlencode($token).'&enviado=1');
    } catch (Throwable $e) {
        $error=$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="referrer" content="no-referrer">
<title>Registrar contribuição · <?=e($theme['name'])?></title>
<link rel="stylesheet" href="/assets/contribution-3726.css?v=1">
<link rel="stylesheet" href="/assets/campo-belo-real-3730.css?v=1">
</head>
<body style="<?=e(TenantBranding::style($church))?>">
<main class="contribute-shell">
  <header class="contribute-header">
    <div class="contribute-mark">
      <?php if($theme['logo']!==''):?><img src="<?=e($theme['logo'])?>" alt="Logo da igreja"><?php else:?><span aria-hidden="true">✝</span><?php endif?>
    </div>
    <div><strong><?=e($theme['name'])?></strong><small><?=e($theme['tagline']?:'Uma família, um propósito')?></small></div>
  </header>
  <?php if($successful):?>
    <section class="contribute-card contribute-success" role="status">
      <div class="contribute-success-icon" aria-hidden="true">✓</div>
      <h1>Registro recebido!</h1>
      <p>Seu formulário foi enviado para a Tesouraria desta igreja.</p>
      <p><strong>O registro ainda depende de conferência.</strong> Ele não confirma, por si só, que o dinheiro foi recebido ou conciliado.</p>
      <a class="contribute-button" href="/contribute.php?t=<?=e(rawurlencode($token))?>">Fazer novo registro</a>
    </section>
  <?php else:?>
    <section class="contribute-card">
      <div class="contribute-intro">
        <span class="contribute-eyebrow">Preenchimento rápido</span>
        <h1>Faça aqui seu lançamento</h1>
        <p>Dízimos, ofertas e outras contribuições.</p>
        <blockquote>“Cada um contribua segundo propôs no seu coração.” <strong>2 Coríntios 9:7</strong></blockquote>
      </div>
      <?php if($error):?><div role="alert" class="contribute-error"><?=e($error)?></div><?php endif?>
      <form method="post" enctype="multipart/form-data" class="contribute-form" id="contribute-form">
        <?=csrf_field()?>
        <input type="hidden" name="t" value="<?=e($token)?>">
        <input type="hidden" name="request_key" value="<?=e($requestKey)?>">
        <div class="contribute-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="contribute-field">
          <label for="contribute-type">Tipo de contribuição <em>*</em></label>
          <select id="contribute-type" name="type_id" required>
            <option value="">Selecione uma opção</option>
            <?php foreach($types as $type):?>
              <option value="<?=e($type['id'])?>" data-campaign="<?=$type['requires_campaign']?'1':'0'?>" data-anonymous="<?=$type['allow_anonymous']?'1':'0'?>" <?=($_POST['type_id']??'')===$type['id']?'selected':''?>><?=e($type['name'])?></option>
            <?php endforeach?>
          </select>
        </div>
        <div class="contribute-field">
          <label for="contribute-amount">Valor (R$) <em>*</em></label>
          <input id="contribute-amount" name="amount" type="text" inputmode="decimal"
             placeholder="Ex.: 150,00" maxlength="16" required value="<?=e((string)($_POST['amount']??''))?>">
        </div>
        <div class="contribute-field">
          <label for="contribute-method">Forma de entrega <em>*</em></label>
          <select id="contribute-method" name="payment_id" required>
            <option value="">Selecione uma opção</option>
            <?php foreach($payments as $payment):?>
              <option value="<?=e($payment['id'])?>" data-reference="<?=$payment['requires_reference']?'1':'0'?>" data-proof="<?=$payment['requires_proof']?'1':'0'?>" <?=($_POST['payment_id']??'')===$payment['id']?'selected':''?>><?=e($payment['name'])?></option>
            <?php endforeach?>
          </select>
        </div>
        <div class="contribute-field" id="contribute-ref-field" hidden>
          <label for="contribute-ref">Referência Pix / transferência <em>*</em></label>
          <input id="contribute-ref" name="bank_reference" maxlength="190"
             placeholder="ID da transação / autenticação" value="<?=e((string)($_POST['bank_reference']??''))?>">
        </div>
        <div class="contribute-field" id="contribute-campaign-field" hidden>
          <label for="contribute-campaign">Campanha vigente <em>*</em></label>
          <select id="contribute-campaign" name="campaign_id">
            <option value="">Selecione uma campanha</option>
            <?php foreach($campaigns as $campaign):?>
              <option value="<?=e($campaign['id'])?>" data-type="<?=e($campaign['contribution_type_id']??'')?>" <?=($_POST['campaign_id']??'')===$campaign['id']?'selected':''?>><?=e($campaign['name'])?></option>
            <?php endforeach?>
          </select>
        </div>
        <div class="contribute-field">
          <label for="contribute-date">Data <em>*</em></label>
          <input id="contribute-date" name="transaction_date" type="date"
             min="<?=e(date('Y-m-d',strtotime('-7 days')))?>" max="<?=e(date('Y-m-d'))?>"
             required value="<?=e((string)($_POST['transaction_date']??date('Y-m-d')))?>">
        </div>
        <div class="contribute-optional-heading">Campos opcionais</div>
        <div class="contribute-field">
          <label for="contribute-name">Seu nome <span id="contribute-name-optional">(opcional)</span></label>
          <input id="contribute-name" name="contributor_name" maxlength="190"
             placeholder="Como deseja ser identificado?" value="<?=e((string)($_POST['contributor_name']??''))?>">
        </div>
        <div class="contribute-field">
          <label for="contribute-proof">Comprovante <span id="contribute-proof-optional">(opcional)</span></label>
          <input id="contribute-proof" name="proof_file" type="file"
             accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
          <small>Foto ou PDF de até 12 MB.</small>
        </div>
        <div class="contribute-field">
          <label for="contribute-notes">Observação (opcional)</label>
          <textarea id="contribute-notes" name="observation" maxlength="250"
            rows="3" placeholder="Campanha, propósito ou informação para a Tesouraria"><?=e((string)($_POST['observation']??''))?></textarea>
        </div>
        <button type="submit" class="contribute-button">Enviar lançamento</button>
        <p class="contribute-notice">O envio é uma declaração para conferência da igreja. Não movimenta automaticamente o caixa ou a conta bancária.</p>
      </form>
    </section>
  <?php endif?>
  <footer class="contribute-footer">Igreja Master · Gestão com transparência</footer>
</main>
<script src="/assets/contribution-form-3726.js?v=1" defer></script>
</body></html>
