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

$q=$pdo->prepare('SELECT m.*,c.name congregation_name,c.address congregation_address,c.pastor_name congregation_pastor,c.pastor_signature_url congregation_signature,c.logo_url congregation_logo FROM members m LEFT JOIN congregations c ON c.id=m.congregation_id WHERE m.id=? AND m.church_id=? LIMIT 1');
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
    $logo=trim((string)($m['congregation_logo']??''));
    if($logo==='')$logo=trim((string)($church['logo_url']??''));

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
$subtitle='';
$body='';
$isCertificate=false;

switch($type){
    case 'baptism_certificate':
        $title='Certificado de Batismo';
        $subtitle='Testemunho de fé e compromisso cristão';
        $isCertificate=true;
        $body="Certificamos que <strong>".e($m['name'])."</strong>, nascido(a) em ".e($fmt($m['birth_date'])).", foi batizado(a) nas águas em <strong>".e($fmt($m['baptism_date']))."</strong>, na igreja ".e($m['baptism_church']?:$church['name']).", pelo Pastor ".e($m['baptism_pastor']?:$pastor).".";
        break;
    case 'membership_certificate':
        $title='Certificado de Membro';
        $subtitle='Reconhecimento de membresia e comunhão';
        $isCertificate=true;
        $body="Certificamos, para os devidos fins, que <strong>".e($m['name'])."</strong> é membro ativo desta igreja desde <strong>".e($fmt($m['membership_date']))."</strong>, participando das atividades eclesiásticas e exercendo a função de <strong>".e($m['role']?:'membro')."</strong>.";
        break;
    case 'recommendation_letter':
        $title='Carta de Recomendação';
        $subtitle='Documento eclesiástico';
        $body="A Paz do Senhor! Pela presente, recomendamos o(a) irmão(ã) <strong>".e($m['name'])."</strong>, membro desta igreja desde ".e($fmt($m['membership_date'])).", de bom testemunho e conduta cristã, para que seja recebido(a) com amor fraternal.";
        break;
    case 'dismissal_letter':
        $title='Carta de Desligamento';
        $subtitle='Documento eclesiástico';
        $body="Declaramos que o(a) irmão(ã) <strong>".e($m['name'])."</strong>, membro desta igreja desde ".e($fmt($m['membership_date'])).", solicita seu desligamento nesta data, ficando livre para congregar em outra comunidade de fé.";
        break;
    case 'visitor_presentation':
        $title='Carta de Apresentação';
        $subtitle='Documento de apresentação e comunhão';
        $body="É com alegria que apresentamos <strong>".e($m['name'])."</strong>. Desejamos que seja recebido(a) com amor, consideração e comunhão cristã.";
        break;
    case 'attendance_declaration':
        $title='Declaração de Frequência';
        $subtitle='Declaração eclesiástica';
        $body="Declaramos, para os devidos fins, que <strong>".e($m['name'])."</strong> é membro ativo desta igreja desde ".e($fmt($m['membership_date']))." e frequenta regularmente os cultos e atividades eclesiásticas.";
        break;
}

$docLogo=trim((string)($m['congregation_logo']??''));
if($docLogo==='')$docLogo=trim((string)($church['logo_url']??''));
$docChurch=trim((string)($church['name']??'Igreja'));
$docCongregation=trim((string)($m['congregation_name']??''));
$docChurchLine=$docCongregation!==''?$docChurch.' · '.$docCongregation:$docChurch;

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
<title><?=e($title)?> · <?=e($m['name'])?></title>
<style>
:root{
  --navy:#092f63;
  --blue:#0c4f91;
  --blue-soft:#eaf2fb;
  --gold:#d4aa37;
  --gold-light:#f4df9b;
  --ink:#172033;
  --muted:#64748b;
  --paper:#fff;
}
*{box-sizing:border-box}
body{margin:0;background:#edf1f5;color:var(--ink);font-family:Arial,Helvetica,sans-serif}
.actions{max-width:1120px;margin:18px auto 10px;padding:0 18px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
.btn{border:0;border-radius:10px;padding:11px 16px;font-weight:800;cursor:pointer}.btn-primary{background:var(--blue);color:#fff}.btn-light{background:#fff;border:1px solid #d7dee8;color:#263246}
.document-wrap{max-width:1120px;margin:0 auto 34px;padding:0 18px}
.paper{position:relative;background:var(--paper);box-shadow:0 20px 60px rgba(15,23,42,.16);overflow:hidden}

/* CERTIFICADOS */
.certificate{min-height:750px;padding:56px 68px 58px;border:3px solid var(--navy)}
.certificate:before,.certificate:after{content:"";position:absolute;pointer-events:none}
.certificate:before{inset:12px;border:1px solid var(--gold)}
.certificate:after{inset:20px;border:1px solid rgba(9,47,99,.22)}
.corner{position:absolute;width:190px;height:190px;z-index:0}
.corner.tl{left:-42px;top:-60px;background:linear-gradient(135deg,var(--navy) 0 45%,transparent 46%),linear-gradient(135deg,transparent 0 53%,var(--gold) 54% 58%,transparent 59%)}
.corner.br{right:-42px;bottom:-60px;transform:rotate(180deg);background:linear-gradient(135deg,var(--navy) 0 45%,transparent 46%),linear-gradient(135deg,transparent 0 53%,var(--gold) 54% 58%,transparent 59%)}
.cert-content{position:relative;z-index:2;text-align:center;min-height:630px;display:flex;flex-direction:column;align-items:center}
.cert-head{display:flex;align-items:center;justify-content:center;gap:16px;min-height:76px}
.cert-logo{width:72px;height:72px;object-fit:contain;border-radius:14px}
.cert-church{font-size:17px;font-weight:900;color:var(--navy);text-transform:uppercase;letter-spacing:.08em}
.cert-cong{font-size:11px;color:var(--muted);margin-top:4px;letter-spacing:.04em}
.cert-kicker{margin-top:34px;font-size:13px;color:var(--gold);font-weight:900;text-transform:uppercase;letter-spacing:.24em}
.cert-title{margin:6px 0 0;color:var(--navy);font-family:Georgia,'Times New Roman',serif;font-size:54px;line-height:1;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.cert-subtitle{margin-top:11px;color:#475569;font-size:14px;letter-spacing:.08em;text-transform:uppercase}
.cert-presented{margin-top:34px;font-size:13px;color:#64748b}
.cert-name{margin:8px 0 18px;padding:0 30px 9px;border-bottom:1px solid #9cb2ca;color:#173e72;font-family:'Brush Script MT','Segoe Script',cursive;font-size:50px;line-height:1.1}
.cert-body{max-width:760px;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:1.75;color:#27364a;text-align:center}
.cert-bottom{margin-top:auto;width:100%;display:grid;grid-template-columns:1fr 150px 1fr;gap:28px;align-items:end}
.signature-area{font-size:12px;color:#475569}
.signature-image{display:block;max-width:180px;max-height:58px;margin:0 auto -2px;object-fit:contain}
.signature-line{border-top:1px solid #334155;padding-top:7px;font-weight:800;color:#172033}
.signature-role{margin-top:3px;font-size:10px;color:#64748b}
.cert-seal{width:120px;height:120px;border-radius:50%;margin:auto;display:grid;place-items:center;text-align:center;color:#fff;font-weight:900;font-size:11px;line-height:1.15;background:radial-gradient(circle,#194f83 0 44%,var(--gold) 45% 54%,#12395e 55% 100%);box-shadow:0 0 0 4px #fff,0 0 0 5px #e4c96f}
.cert-date{border-top:1px solid #334155;padding-top:7px;font-size:12px;color:#172033}
.auth-strip{position:absolute;left:32px;right:32px;bottom:20px;display:flex;justify-content:space-between;gap:14px;align-items:center;font-size:9px;color:#607086;z-index:3}
.auth-strip strong{color:var(--navy)}

/* CARTAS E DECLARAÇÕES */
.letter{min-height:1040px;padding:0 72px 58px}
.letter-top{height:16px;background:linear-gradient(90deg,var(--navy),var(--blue),var(--gold))}
.letter-head{display:grid;grid-template-columns:88px 1fr;gap:20px;align-items:center;padding:34px 0 22px;border-bottom:2px solid var(--navy)}
.letter-logo{width:78px;height:78px;object-fit:contain;border-radius:12px}
.letter-church{font-size:22px;font-weight:900;color:var(--navy);text-transform:uppercase}.letter-cong{font-size:13px;color:#526277;margin-top:5px}.letter-address{font-size:11px;color:#7a8798;margin-top:5px}
.letter-title{text-align:center;margin:52px 0 6px;color:var(--navy);font-family:Georgia,'Times New Roman',serif;font-size:30px;text-transform:uppercase;letter-spacing:.05em}
.letter-subtitle{text-align:center;color:var(--gold);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.18em}
.letter-recipient{margin:48px 0 0;font-size:15px;color:#344256}.letter-recipient strong{color:#18263a}
.letter-body{margin-top:30px;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:1.95;text-align:justify;color:#283649}
.letter-place{text-align:right;margin-top:52px;font-size:14px;color:#526277}
.letter-signature{margin:78px auto 0;width:340px;text-align:center}
.letter-signature img{display:block;max-width:210px;max-height:68px;margin:0 auto -3px;object-fit:contain}
.letter-signature .line{border-top:1px solid #334155;padding-top:8px;font-weight:800}.letter-signature .role{margin-top:3px;color:#64748b;font-size:12px}
.letter-auth{margin-top:78px;border:1px solid #d9e1ea;border-left:5px solid var(--gold);background:#f8fafc;padding:16px 18px;display:grid;grid-template-columns:1fr 92px;gap:20px;align-items:center}
.auth-title{font-size:12px;font-weight:900;color:var(--navy);text-transform:uppercase;letter-spacing:.08em}.auth-text{margin-top:5px;font-size:11px;line-height:1.55;color:#64748b}.auth-code{font-family:monospace;font-size:11px;color:#22324a;font-weight:800}
.qr-box{background:#fff;padding:6px;border:1px solid #d7dee8;border-radius:8px;text-align:center}#docQr{display:flex;justify-content:center}#docQr img,#docQr canvas{width:78px!important;height:78px!important}
.doc-footer{margin-top:26px;text-align:center;font-size:9px;color:#94a3b8}

@media(max-width:760px){
  .certificate{padding:38px 26px}.cert-title{font-size:34px}.cert-name{font-size:36px}.cert-body{font-size:15px}.cert-bottom{grid-template-columns:1fr}.cert-seal{display:none}
  .letter{padding:0 28px 38px}.letter-head{grid-template-columns:64px 1fr}.letter-logo{width:58px;height:58px}.letter-body{font-size:16px}.letter-auth{grid-template-columns:1fr}.qr-box{width:100px}
}
@media print{
  @page{size:A4 portrait;margin:8mm}
  body{background:#fff}
  .actions{display:none!important}
  .document-wrap{max-width:none;margin:0;padding:0}
  .paper{box-shadow:none;width:100%;min-height:0}
  .certificate{height:190mm;min-height:190mm;padding:14mm 18mm 15mm}.cert-content{min-height:157mm}.cert-title{font-size:31pt}.cert-name{font-size:29pt}.cert-body{font-size:12pt}.corner{width:45mm;height:45mm}
  .letter{min-height:270mm;padding:0 18mm 12mm}.letter-body{font-size:12pt}.letter-title{margin-top:14mm}
}
</style>
</head>
<body>
<div class="actions">
  <button class="btn btn-light" onclick="history.back()">Voltar</button>
  <button class="btn btn-primary" onclick="window.print()">Imprimir / Salvar PDF</button>
</div>

<div class="document-wrap">
<?php if($isCertificate):?>
  <article class="paper certificate">
    <span class="corner tl"></span><span class="corner br"></span>
    <div class="cert-content">
      <div class="cert-head">
        <?php if($docLogo!==''):?><img class="cert-logo" src="<?=e($docLogo)?>" alt="Logo"><?php endif?>
        <div>
          <div class="cert-church"><?=e($docChurch)?></div>
          <?php if($docCongregation!==''):?><div class="cert-cong"><?=e($docCongregation)?></div><?php endif?>
        </div>
      </div>

      <div class="cert-kicker">Documento Oficial</div>
      <h1 class="cert-title">Certificado</h1>
      <div class="cert-subtitle"><?=e($title)?></div>
      <div class="cert-presented">confere o presente certificado a</div>
      <div class="cert-name"><?=e($m['name'])?></div>
      <div class="cert-body"><?=$body?></div>

      <div class="cert-bottom">
        <div class="signature-area">
          <?php if(!empty($m['congregation_signature'])):?><img class="signature-image" src="<?=e($m['congregation_signature'])?>" alt="Assinatura"><?php endif?>
          <div class="signature-line"><?=e($pastor?:'Responsável / Pastor')?></div>
          <div class="signature-role">Responsável eclesiástico</div>
        </div>

        <div class="cert-seal">DOCUMENTO<br>OFICIAL<br>✦</div>

        <div class="signature-area">
          <div class="cert-date"><?=e($today)?></div>
          <div class="signature-role">Data de emissão</div>
        </div>
      </div>
    </div>

    <div class="auth-strip">
      <span>Autenticidade: <strong><?=e($verification)?></strong></span>
      <span><?=e($docChurchLine)?></span>
    </div>
  </article>
<?php else:?>
  <article class="paper letter">
    <div class="letter-top"></div>
    <header class="letter-head">
      <div><?php if($docLogo!==''):?><img class="letter-logo" src="<?=e($docLogo)?>" alt="Logo"><?php endif?></div>
      <div>
        <div class="letter-church"><?=e($docChurch)?></div>
        <?php if($docCongregation!==''):?><div class="letter-cong"><?=e($docCongregation)?></div><?php endif?>
        <div class="letter-address"><?=e($address?:'Endereço não informado')?></div>
      </div>
    </header>

    <h1 class="letter-title"><?=e($title)?></h1>
    <div class="letter-subtitle"><?=e($subtitle)?></div>

    <div class="letter-recipient"><strong>Referência:</strong> <?=e($m['name'])?></div>
    <div class="letter-body"><?=$body?></div>

    <div class="letter-place"><?=e($docCongregation?:$docChurch)?>, <?=e($today)?></div>

    <div class="letter-signature">
      <?php if(!empty($m['congregation_signature'])):?><img src="<?=e($m['congregation_signature'])?>" alt="Assinatura"><?php endif?>
      <div class="line"><?=e($pastor?:'Responsável / Pastor')?></div>
      <div class="role">Responsável eclesiástico</div>
    </div>

    <div class="letter-auth">
      <div>
        <div class="auth-title">Verificação de autenticidade</div>
        <div class="auth-text">Este documento possui código único de verificação. Aponte a câmera para o QR Code ou utilize o código no portal de validação.</div>
        <div class="auth-code"><?=e($verification)?></div>
      </div>
      <div class="qr-box"><div id="docQr"></div></div>
    </div>

    <div class="doc-footer"><?=e($docChurchLine)?> · Documento gerado pelo sistema de gestão da igreja</div>
  </article>
<?php endif?>
</div>

<?php if(!$isCertificate):?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>
<script>
(function(){
  var el=document.getElementById('docQr');
  var url=<?=json_encode($verifyUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  if(window.QRCode&&el)new QRCode(el,{text:url,width:100,height:100,correctLevel:QRCode.CorrectLevel.M});
})();
</script>
<?php endif?>
</body>
</html>
