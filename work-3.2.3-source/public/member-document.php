<?php
require __DIR__.'/bootstrap.php';

$auth->requireAbility('secretary');
$user=$auth->user();
$church=$auth->currentChurch();
if(!$church)redirect('logout.php');

$cid=(string)$church['id'];
$id=trim((string)($_GET['id']??''));
$type=trim((string)($_GET['type']??''));

$allowed=['baptism_certificate','membership_card','membership_certificate','recommendation_letter','dismissal_letter','visitor_presentation','attendance_declaration'];
if(!in_array($type,$allowed,true)){
    http_response_code(400);
    exit('Documento inválido.');
}

$q=$pdo->prepare('SELECT m.*,c.name congregation_name,c.address congregation_address,c.pastor_name congregation_pastor,c.pastor_signature_url congregation_signature FROM members m LEFT JOIN congregations c ON c.id=m.congregation_id WHERE m.id=? AND m.church_id=? LIMIT 1');
$q->execute([$id,$cid]);
$m=$q->fetch();
if(!$m){
    http_response_code(404);
    exit('Membro não encontrado.');
}

$prefixes=[
    'baptism_certificate'=>'BAP',
    'membership_card'=>'CAR',
    'membership_certificate'=>'MEM',
    'recommendation_letter'=>'REC',
    'dismissal_letter'=>'DIS',
    'visitor_presentation'=>'VIS',
    'attendance_declaration'=>'FRE'
];

$q=$pdo->prepare('SELECT verification_code FROM issued_documents WHERE church_id=? AND member_id=? AND document_type=? AND revoked_at IS NULL ORDER BY issued_at DESC LIMIT 1');
$q->execute([$cid,$id,$type]);
$verification=$q->fetchColumn();

if(!$verification){
    $memberCode=strtoupper(substr(str_replace('-','',$id),0,6));
    $churchCode=strtoupper(substr(str_replace('-','',$cid),0,6));
    $verification=$prefixes[$type].'-'.$memberCode.'-'.$churchCode.'-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));
    $pdo->prepare('INSERT INTO issued_documents(id,church_id,member_id,document_type,verification_code,issued_by,metadata) VALUES(?,?,?,?,?,?,?)')
        ->execute([
            app_uuid(),$cid,$id,$type,$verification,$user['id']??null,
            json_encode(['member_name'=>$m['name'],'church_name'=>$church['name']],JSON_UNESCAPED_UNICODE)
        ]);
}

$pastor=$m['congregation_pastor']?:($church['pastor_name']??'');
$address=$m['congregation_address']?:($church['address']??'');
$today=date('d/m/Y');
$fmt=fn($d)=>$d?date('d/m/Y',strtotime((string)$d)):'—';

if($type==='membership_card'){
    $role=trim((string)($m['role']?:'Membro'));
    $credentialTitle=stripos($role,'pastor')!==false?'CREDENCIAL DE PASTOR':'CARTEIRA DE MEMBRO';
    $churchName=trim((string)($church['name']??'Igreja'));
    $congregation=trim((string)($m['congregation_name']??''));
    $churchLine=$congregation!==''?$churchName.' · '.$congregation:$churchName;

    $mother=trim((string)($m['mother_name']??''));
    $father=trim((string)($m['father_name']??''));
    $parents=implode(' / ',array_filter([$mother,$father]));
    if($parents==='')$parents='Não informado';

    $photo=trim((string)($m['photo_url']??''));
    $logo=trim((string)($church['logo_url']??''));

    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $host=(string)($_SERVER['HTTP_HOST']??'');
    $basePath=rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/'))),'/');
    if($basePath==='.'||$basePath==='/')$basePath='';
    $verifyUrl=$host!==''?$scheme.'://'.$host.$basePath.'/verify.php?code='.rawurlencode((string)$verification):'/verify.php?code='.rawurlencode((string)$verification);
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($credentialTitle)?> · <?=e($m['name'])?></title>
<style>
:root{--blue1:#083c8c;--blue2:#0667b7;--blue3:#0aa0d7;--gold:#f3cf5b;--ink:#fff}
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#0f172a;font-family:Arial,Helvetica,sans-serif}
.toolbar{max-width:1040px;margin:20px auto 12px;padding:0 14px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.btn{border:0;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer}
.btn-primary{background:#1565c0;color:#fff}.btn-light{background:#fff;color:#172033;border:1px solid #dbe2ea}
.notice{max-width:1040px;margin:0 auto 16px;padding:0 14px;color:#64748b;font-size:13px}
.cards{max-width:1040px;margin:0 auto;padding:0 14px 30px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;align-items:start}
.card-id{position:relative;overflow:hidden;width:100%;aspect-ratio:85.6/54;border-radius:18px;color:var(--ink);box-shadow:0 18px 45px rgba(15,23,42,.2);background:
radial-gradient(circle at 85% 10%,rgba(64,215,255,.55),transparent 26%),
linear-gradient(135deg,var(--blue1) 0%,var(--blue2) 48%,#032a68 100%)}
.card-id:before{content:"";position:absolute;left:-12%;right:-12%;bottom:-26%;height:58%;background:linear-gradient(18deg,rgba(0,201,230,.7),rgba(0,83,178,.18));transform:skewY(-8deg)}
.card-id:after{content:"";position:absolute;left:-8%;right:-8%;bottom:-12%;height:28%;background:linear-gradient(160deg,transparent 25%,rgba(244,204,74,.95) 26%,rgba(244,204,74,.95) 30%,transparent 31%)}
.card-inner{position:absolute;inset:0;padding:4.8%;z-index:2}
.card-head{display:flex;gap:3%;align-items:center;border-bottom:1px solid rgba(255,255,255,.22);padding-bottom:2.2%;min-height:18%}
.brand-logo{width:12%;aspect-ratio:1;border-radius:50%;background:rgba(255,255,255,.96);display:grid;place-items:center;overflow:hidden;flex:0 0 auto;border:2px solid rgba(243,207,91,.8)}
.brand-logo img{width:100%;height:100%;object-fit:cover}
.logo-fallback{font-weight:900;color:#0b4a8e;font-size:1.5vw}
.brand-text{min-width:0;flex:1}.brand-text strong{display:block;font-size:clamp(10px,1.38vw,20px);line-height:1.05;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.brand-text span{display:block;font-size:clamp(6px,.72vw,10px);opacity:.88;margin-top:2px;letter-spacing:.06em}
.front-body{display:grid;grid-template-columns:29% 1fr;gap:4%;padding-top:3%}
.photo{width:100%;aspect-ratio:3/3.6;border-radius:9%;background:rgba(255,255,255,.94);border:2px solid rgba(255,255,255,.9);overflow:hidden;display:grid;place-items:center}
.photo img{width:100%;height:100%;object-fit:cover}.photo span{color:#46627c;font-size:clamp(7px,.8vw,11px);text-align:center;padding:8px}
.member-name{font-size:clamp(12px,1.72vw,24px);line-height:1.02;font-weight:900;text-transform:uppercase;margin:0 0 3%;text-shadow:0 1px 1px rgba(0,0,0,.2)}
.cred-title{display:inline-block;background:rgba(255,255,255,.92);color:#084b91;border-radius:999px;padding:1.2% 3%;font-size:clamp(7px,.76vw,11px);font-weight:900;margin-bottom:3%}
.data-grid{display:grid;grid-template-columns:1fr 1fr;gap:2% 4%;font-size:clamp(6px,.72vw,10px)}
.field b{display:block;color:#d7efff;font-size:.86em}.field span{display:block;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.role-band{position:absolute;left:4.8%;right:4.8%;bottom:4.5%;z-index:4;font-size:clamp(8px,1vw,14px);font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.back .card-inner{padding:4.8%}
.back-grid{display:grid;grid-template-columns:1fr 31%;gap:4%;padding-top:4%}
.back-fields{display:grid;gap:3.2%;font-size:clamp(6.2px,.76vw,10.5px)}
.back-field b{display:block;color:#cfeaff;font-size:.86em;text-transform:uppercase;letter-spacing:.03em}.back-field span{display:block;font-weight:700}
.qr-wrap{background:rgba(255,255,255,.97);padding:6%;border-radius:10%;align-self:start;text-align:center;color:#0b3d77}
#qr{display:flex;justify-content:center}#qr img,#qr canvas{width:100%!important;height:auto!important;display:block}
.qr-code{font-size:clamp(5px,.58vw,8px);margin-top:5%;font-weight:800;word-break:break-all}
.address{position:absolute;left:4.8%;right:4.8%;bottom:7.5%;font-size:clamp(6px,.67vw,9px);font-weight:700;z-index:4;padding-right:25%}
.serial{position:absolute;right:4.8%;bottom:4.5%;font-size:clamp(5px,.56vw,8px);z-index:4}
@media(max-width:850px){.cards{grid-template-columns:1fr}.logo-fallback{font-size:4vw}.brand-text strong{font-size:3.5vw}.brand-text span{font-size:1.9vw}.member-name{font-size:4.4vw}.cred-title{font-size:2vw}.data-grid,.back-fields{font-size:1.9vw}.role-band{font-size:2.6vw}.address{font-size:1.7vw}.serial,.qr-code{font-size:1.45vw}}
@media print{
  @page{size:A4 portrait;margin:12mm}
  body{background:#fff}
  .toolbar,.notice{display:none!important}
  .cards{display:block;max-width:none;padding:0;margin:0}
  .card-id{width:85.6mm;height:54mm;aspect-ratio:auto;border-radius:3mm;box-shadow:none;margin:0 0 8mm 0;break-inside:avoid}
  .logo-fallback{font-size:11pt}.brand-text strong{font-size:9.8pt}.brand-text span{font-size:5.4pt}.member-name{font-size:12.5pt}.cred-title{font-size:5.7pt}.data-grid,.back-fields{font-size:5.4pt}.role-band{font-size:7.5pt}.address{font-size:5pt}.serial,.qr-code{font-size:4.2pt}
}
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn btn-light" onclick="history.back()">Voltar</button>
  <button class="btn btn-primary" onclick="window.print()">Imprimir / Salvar PDF</button>
</div>
<div class="notice">Imprima em 100% de escala para manter o tamanho padrão aproximado de 85,6 × 54 mm.</div>

<main class="cards">
  <section class="card-id front" aria-label="Frente da carteirinha">
    <div class="card-inner">
      <div class="card-head">
        <div class="brand-logo">
          <?php if($logo!==''):?><img src="<?=e($logo)?>" alt="Logo"><?php else:?><span class="logo-fallback">✦</span><?php endif?>
        </div>
        <div class="brand-text">
          <strong><?=e($churchName)?></strong>
          <span><?=e($credentialTitle)?></span>
        </div>
      </div>
      <div class="front-body">
        <div class="photo">
          <?php if($photo!==''):?><img src="<?=e($photo)?>" alt="Foto de <?=e($m['name'])?>"><?php else:?><span>FOTO DO MEMBRO</span><?php endif?>
        </div>
        <div>
          <div class="member-name"><?=e($m['name'])?></div>
          <div class="cred-title"><?=e($role)?></div>
          <div class="data-grid">
            <div class="field"><b>Estado Civil</b><span><?=e($m['marital_status']?:'—')?></span></div>
            <div class="field"><b>RG</b><span><?=e($m['rg']?:'—')?></span></div>
            <div class="field"><b>CPF</b><span><?=e($m['cpf']?:'—')?></span></div>
            <div class="field"><b>Membro desde</b><span><?=e($fmt($m['membership_date']??null))?></span></div>
            <div class="field"><b>Congregação</b><span><?=e($congregation?:'Sede')?></span></div>
            <div class="field"><b>Emissão</b><span><?=e($today)?></span></div>
          </div>
        </div>
      </div>
      <div class="role-band"><?=e($role)?> · <?=e($m['name'])?></div>
    </div>
  </section>

  <section class="card-id back" aria-label="Verso da carteirinha">
    <div class="card-inner">
      <div class="card-head">
        <div class="brand-logo">
          <?php if($logo!==''):?><img src="<?=e($logo)?>" alt="Logo"><?php else:?><span class="logo-fallback">✦</span><?php endif?>
        </div>
        <div class="brand-text">
          <strong><?=e($churchLine)?></strong>
          <span>IDENTIFICAÇÃO ECLESIÁSTICA · AUTENTICIDADE DIGITAL</span>
        </div>
      </div>
      <div class="back-grid">
        <div class="back-fields">
          <div class="back-field"><b>Filiação — Mãe / Pai</b><span><?=e($parents)?></span></div>
          <div class="back-field"><b>Data de Batismo</b><span><?=e($fmt($m['baptism_date']??null))?></span></div>
          <div class="back-field"><b>Nacionalidade</b><span><?=e($m['nationality']?:'—')?></span></div>
          <div class="back-field"><b>Naturalidade</b><span><?=e($m['naturalness']?:'—')?></span></div>
          <div class="back-field"><b>Departamento</b><span><?=e($m['department']?:'—')?></span></div>
          <div class="back-field"><b>Pastor / Responsável</b><span><?=e($pastor?:'—')?></span></div>
        </div>
        <div class="qr-wrap">
          <div id="qr"></div>
          <div class="qr-code"><?=e($verification)?></div>
        </div>
      </div>
      <div class="address"><strong>Endereço da igreja:</strong> <?=e($address?:'Não informado')?></div>
      <div class="serial"><?=e($verification)?></div>
    </div>
  </section>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>
<script>
(function(){
  var target=document.getElementById('qr');
  var url=<?=json_encode($verifyUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  if(window.QRCode&&target){
    new QRCode(target,{text:url,width:180,height:180,correctLevel:QRCode.CorrectLevel.M});
  }else if(target){
    target.innerHTML='<div style="font-size:10px;padding:18px 4px">QR indisponível<br>Use o código abaixo</div>';
  }
})();
</script>
</body>
</html><?php
    exit;
}

$title='Documento';
$body='';
switch($type){
    case 'baptism_certificate':
        $title='Certificado de Batismo';
        $body="Certificamos que <strong>".e($m['name'])."</strong>, nascido(a) em ".e($fmt($m['birth_date'])).", foi batizado(a) nas águas em <strong>".e($fmt($m['baptism_date']))."</strong>, na igreja ".e($m['baptism_church']?:$church['name']).", pelo Pastor ".e($m['baptism_pastor']?:$pastor).".";
        break;
    case 'membership_certificate':
        $title='Certificado de Membro';
        $body="Certificamos, para os devidos fins, que <strong>".e($m['name'])."</strong> é membro ativo desta igreja desde <strong>".e($fmt($m['membership_date']))."</strong>, participando das atividades eclesiásticas, exercendo a função de ".e($m['role']?:'membro').".";
        break;
    case 'recommendation_letter':
        $title='Carta de Recomendação';
        $body="A Paz do Senhor! Pela presente, recomendamos o(a) irmão(ã) <strong>".e($m['name'])."</strong>, membro desta igreja desde ".e($fmt($m['membership_date'])).", de bom testemunho e conduta cristã, para que seja recebido(a) com amor fraternal.";
        break;
    case 'dismissal_letter':
        $title='Carta de Desligamento';
        $body="Declaramos que o(a) irmão(ã) <strong>".e($m['name'])."</strong>, membro desta igreja desde ".e($fmt($m['membership_date'])).", solicita seu desligamento nesta data, ficando livre para congregar em outra comunidade de fé.";
        break;
    case 'visitor_presentation':
        $title='Carta de Apresentação';
        $body="É com alegria que apresentamos <strong>".e($m['name'])."</strong>. Desejamos que seja recebido(a) com amor e comunhão cristã.";
        break;
    case 'attendance_declaration':
        $title='Declaração de Frequência';
        $body="Declaramos, para os devidos fins, que <strong>".e($m['name'])."</strong> é membro ativo desta igreja desde ".e($fmt($m['membership_date']))." e frequenta regularmente os cultos e atividades eclesiásticas.";
        break;
}

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?></title>
<style>
body{font-family:Arial,sans-serif;background:#f3f5f8;margin:0;padding:24px;color:#1c2430}
.sheet{max-width:820px;margin:auto;background:white;padding:64px;min-height:980px;box-shadow:0 8px 30px #0002}
.head{text-align:center;border-bottom:2px solid #222;padding-bottom:24px;margin-bottom:54px}
.logo{max-width:90px;max-height:90px;border-radius:18px}.head h1{font-size:24px;margin:12px 0 4px}
.doc-title{text-align:center;text-transform:uppercase;font-size:28px;letter-spacing:1px;margin:0 0 50px}
.body{font-size:18px;line-height:1.9;text-align:justify}.footer{margin-top:100px;text-align:center}
.signature{margin:80px auto 0;border-top:1px solid #333;width:320px;padding-top:8px}
.verify{margin-top:50px;border:1px solid #ccd3dd;background:#f8fafc;padding:14px;text-align:center;font-size:12px}
.actions{max-width:820px;margin:16px auto;text-align:right}.btn{padding:10px 16px;border:0;border-radius:8px;background:#1f6feb;color:white;cursor:pointer}
@media print{body{background:white;padding:0}.sheet{box-shadow:none;max-width:none;min-height:auto}.actions{display:none}}
</style>
</head>
<body>
<div class="actions"><button class="btn" onclick="window.print()">Imprimir / Salvar PDF</button></div>
<article class="sheet">
  <div class="head">
    <?php if(!empty($church['logo_url'])):?><img class="logo" src="<?=e($church['logo_url'])?>"><?php endif?>
    <h1><?=e($church['name'])?></h1>
    <div><?=e($address)?></div>
  </div>
  <h2 class="doc-title"><?=e($title)?></h2>
  <div class="body"><?=$body?></div>
  <div class="footer">
    <p><?=e($today)?></p>
    <div class="signature"><?=e($pastor?:'Responsável / Pastor')?></div>
    <div class="verify">
      <strong>Verificação de autenticidade</strong><br>
      Código: <strong><?=e($verification)?></strong><br>
      Acesse <strong>/verify.php?code=<?=e($verification)?></strong>
    </div>
  </div>
</article>
</body>
</html>
