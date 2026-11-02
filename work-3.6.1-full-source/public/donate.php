<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/PixPayloadService.php';

$token=trim((string)($_GET['t']??''));
$q=$pdo->prepare("SELECT id,name,pix_key,donation_title,donation_instructions,donation_merchant_name,donation_merchant_city
  FROM churches WHERE donation_public_token=? AND donation_enabled=1 AND active=1 LIMIT 1");
$q->execute([$token]);
$church=$q->fetch()?:null;

$error=null;$pixPayload=null;$qrUrl=null;$amount=null;$purpose='';
if(!$church){
    http_response_code(404);
    $error='Página de doações indisponível.';
}elseif($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $raw=str_replace(',','.',trim((string)($_POST['amount']??'')));
        if($raw===''||!is_numeric($raw)||(float)$raw<=0)throw new InvalidArgumentException('Informe um valor válido.');
        $amount=round((float)$raw,2);
        if($amount>1000000)throw new InvalidArgumentException('Valor acima do limite permitido nesta página.');
        $purpose=trim((string)($_POST['purpose']??''));
        if(mb_strlen($purpose)>60)$purpose=mb_substr($purpose,0,60);

        $pixPayload=PixPayloadService::payload(
            (string)$church['pix_key'],$amount,
            (string)($church['donation_merchant_name']?:$church['name']),
            (string)($church['donation_merchant_city']?:'BRASIL'),
            $purpose!==''?$purpose:'Contribuicao'
        );
        $qrUrl='https://quickchart.io/qr?size=340&text='.rawurlencode($pixPayload);
    }catch(Throwable $e){$error=$e->getMessage();}
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($church['donation_title']??'Doações')?></title>
<style>
:root{font-family:Inter,Arial,sans-serif;color:#172438;background:#f5f8fb}*{box-sizing:border-box}body{margin:0;padding:20px}.shell{max-width:640px;margin:4vh auto}.card{background:#fff;border:1px solid #dfe7ef;border-radius:22px;padding:26px;box-shadow:0 16px 40px rgba(30,70,110,.08)}.brand{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:#6e8194}h1{font-size:28px;margin:8px 0}.muted{color:#65768a;line-height:1.6}.field{margin:14px 0}.field label{display:block;font-weight:800;font-size:13px;margin-bottom:6px}.input{width:100%;padding:13px;border:1px solid #cdd8e4;border-radius:11px;font:inherit}.btn{border:0;border-radius:12px;padding:13px 16px;font-weight:800;background:#246aa7;color:#fff;cursor:pointer}.preset{display:flex;gap:7px;flex-wrap:wrap}.preset button{border:1px solid #cbd9e6;background:#fff;color:#24445f;border-radius:999px;padding:8px 12px;cursor:pointer}.alert{padding:12px 14px;border-radius:12px;margin:14px 0;background:#fff0f0;color:#922}.pix{text-align:center;margin-top:20px;padding:18px;border:1px solid #dce9f5;background:#f8fbff;border-radius:16px}.pix img{width:280px;max-width:100%}.copy{width:100%;min-height:100px;padding:11px;border:1px solid #cad8e5;border-radius:10px;word-break:break-all;margin:10px 0}.amount{font-size:24px;font-weight:900}.note{margin-top:16px;padding:12px;border-radius:12px;background:#fffaf0;color:#70591e;font-size:12px;line-height:1.5}@media(max-width:560px){body{padding:12px}.card{padding:18px}}
</style>
</head>
<body><div class="shell"><div class="card">
<?php if($church):?>
  <div class="brand"><?=e($church['name'])?></div>
  <h1><?=e($church['donation_title']?:'Dízimos e Ofertas')?></h1>
  <p class="muted"><?=e($church['donation_instructions']?:'Informe o valor para gerar o PIX. Deus abençoe sua contribuição.')?></p>
<?php endif?>
<?php if($error):?><div class="alert"><?=e($error)?></div><?php endif?>

<?php if($pixPayload):?>
  <div class="pix">
    <div class="amount">R$ <?=number_format((float)$amount,2,',','.')?></div>
    <img src="<?=e($qrUrl)?>" alt="QR Code PIX">
    <p class="muted">Escaneie o QR Code ou copie o PIX abaixo.</p>
    <textarea class="copy" id="pix-code" readonly><?=e($pixPayload)?></textarea>
    <button class="btn" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('pix-code').value)">Copiar PIX</button>
  </div>
  <div class="note">Este sistema apenas gera o código PIX com a chave configurada pela igreja. A confirmação bancária não é automática.</div>
<?php elseif($church):?>
  <form method="post"><?=csrf_field()?>
    <div class="field"><label>Valor da contribuição *</label><input class="input" id="amount" name="amount" inputmode="decimal" placeholder="0,00" required></div>
    <div class="preset"><button type="button" onclick="document.getElementById('amount').value='20,00'">R$ 20</button><button type="button" onclick="document.getElementById('amount').value='50,00'">R$ 50</button><button type="button" onclick="document.getElementById('amount').value='100,00'">R$ 100</button><button type="button" onclick="document.getElementById('amount').value='200,00'">R$ 200</button></div>
    <div class="field"><label>Finalidade / descrição</label><input class="input" name="purpose" maxlength="60" placeholder="Ex.: Dízimo, oferta, campanha"></div>
    <button class="btn">Gerar PIX</button>
  </form>
<?php endif?>
</div></div></body></html>