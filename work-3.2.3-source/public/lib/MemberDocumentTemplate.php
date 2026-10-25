<?php

declare(strict_types=1);

final class MemberDocumentTemplate
{
    public function __construct(private string $appRoot) {}

    public function render(array $church,array $m,string $type,string $title,string $verification,array $options=[]): string
    {
        $pastor=(string)($m['congregation_pastor']?:($church['pastor_name']??'Responsável / Pastor'));
        $address=(string)($m['congregation_address']?:($church['address']??''));
        $today=date('d/m/Y');
        $fmt=fn($d)=>$d?date('d/m/Y',strtotime((string)$d)):'—';

        $logo=$this->asset((string)($m['congregation_logo']??''));
        if($logo==='')$logo=$this->asset((string)($church['logo_url']??''));

        $signature=$this->asset((string)($m['congregation_signature']??''));
        if($signature==='')$signature=$this->asset((string)($church['pastor_signature_url']??''));

        $photo=$this->asset((string)($m['photo_url']??''));

        $churchName=trim((string)($church['name']??'Igreja'));
        $congregation=trim((string)($m['congregation_name']??''));
        $churchLine=$congregation!==''?$churchName.' · '.$congregation:$churchName;
        $verifyUrl=$this->verifyUrl($church,$verification,$options);
        $qr=$this->qrDataUri($verifyUrl);

        if($type==='membership_card'){
            $role=trim((string)($m['role']?:'Membro'));
            $credentialTitle=stripos($role,'pastor')!==false?'CREDENCIAL DE PASTOR':'CARTEIRA DE MEMBRO';
            $parents=implode(' / ',array_filter([
                trim((string)($m['mother_name']??'')),
                trim((string)($m['father_name']??'')),
            ]));
            if($parents==='')$parents='Não informado';

            return $this->page($credentialTitle.' · '.$m['name'],$this->cardHtml(
                $churchName,$churchLine,$credentialTitle,$m,$role,$parents,$pastor,$address,$today,$fmt,$logo,$photo,$qr,$verification
            ),'card');
        }

        $isCertificate=in_array($type,['baptism_certificate','membership_certificate'],true);
        $subtitle='';
        $body='';

        switch($type){
            case 'baptism_certificate':
                $subtitle='Testemunho de fé e compromisso cristão';
                $body='Certificamos que <strong>'.$this->e($m['name']).'</strong>, nascido(a) em '.$this->e($fmt($m['birth_date']??null)).', foi batizado(a) nas águas em <strong>'.$this->e($fmt($m['baptism_date']??null)).'</strong>, na igreja '.$this->e($m['baptism_church']?:$churchName).', pelo Pastor '.$this->e($m['baptism_pastor']?:$pastor).'.';
                break;
            case 'membership_certificate':
                $subtitle='Reconhecimento de membresia e comunhão';
                $body='Certificamos, para os devidos fins, que <strong>'.$this->e($m['name']).'</strong> é membro ativo desta igreja desde <strong>'.$this->e($fmt($m['membership_date']??null)).'</strong>, participando das atividades eclesiásticas e exercendo a função de <strong>'.$this->e($m['role']?:'membro').'</strong>.';
                break;
            case 'recommendation_letter':
                $subtitle='Documento eclesiástico';
                $body='A Paz do Senhor! Pela presente, recomendamos o(a) irmão(ã) <strong>'.$this->e($m['name']).'</strong>, membro desta igreja desde '.$this->e($fmt($m['membership_date']??null)).', de bom testemunho e conduta cristã, para que seja recebido(a) com amor fraternal.';
                break;
            case 'dismissal_letter':
                $subtitle='Documento eclesiástico';
                $body='Declaramos que o(a) irmão(ã) <strong>'.$this->e($m['name']).'</strong>, membro desta igreja desde '.$this->e($fmt($m['membership_date']??null)).', solicita seu desligamento nesta data, ficando livre para congregar em outra comunidade de fé.';
                break;
            case 'visitor_presentation':
                $subtitle='Documento de apresentação e comunhão';
                $body='É com alegria que apresentamos <strong>'.$this->e($m['name']).'</strong>. Desejamos que seja recebido(a) com amor, consideração e comunhão cristã.';
                break;
            case 'attendance_declaration':
                $subtitle='Declaração eclesiástica';
                $start=$options['period_start']??null;
                $end=$options['period_end']??null;
                $purpose=trim((string)($options['purpose']??''));
                $body='Declaramos, para os devidos fins, que <strong>'.$this->e($m['name']).'</strong> é membro ativo desta igreja';
                if($start||$end){
                    $body.=' e mantém frequência';
                    if($start)$body.=' desde <strong>'.$this->e($fmt($start)).'</strong>';
                    if($end)$body.=' até <strong>'.$this->e($fmt($end)).'</strong>';
                }else{
                    $body.=' desde <strong>'.$this->e($fmt($m['membership_date']??null)).'</strong> e frequenta regularmente os cultos e atividades eclesiásticas';
                }
                $body.='.';
                if($purpose!=='')$body.=' Finalidade: <strong>'.$this->e($purpose).'</strong>.';
                break;
        }

        $html=$isCertificate
            ?$this->certificateHtml($churchName,$congregation,$title,$subtitle,$m,$body,$pastor,$today,$logo,$signature,$qr,$verification,$churchLine)
            :$this->letterHtml($churchName,$congregation,$address,$title,$subtitle,$m,$body,$pastor,$today,$logo,$signature,$qr,$verification,$churchLine);

        return $this->page($title.' · '.$m['name'],$html,$isCertificate?'certificate':'letter');
    }

    private function page(string $title,string $body,string $kind): string
    {
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).'</title><style>'.$this->css($kind).'</style></head><body>'.$body.'</body></html>';
    }

    private function certificateHtml(string $church,string $congregation,string $title,string $subtitle,array $m,string $body,string $pastor,string $today,string $logo,string $signature,string $qr,string $verification,string $churchLine): string
    {
        return '<main class="paper certificate">
          <div class="corner tl"></div><div class="corner br"></div>
          <div class="cert-content">
            <div class="cert-head">'.($logo!==''?'<img class="cert-logo" src="'.$this->e($logo).'">':'').'<div><div class="cert-church">'.$this->e($church).'</div>'.($congregation!==''?'<div class="cert-cong">'.$this->e($congregation).'</div>':'').'</div></div>
            <div class="cert-kicker">Documento Oficial</div>
            <div class="cert-title">Certificado</div>
            <div class="cert-subtitle">'.$this->e($title).'</div>
            <div class="cert-presented">confere o presente certificado a</div>
            <div class="cert-name">'.$this->e($m['name']).'</div>
            <div class="cert-body">'.$body.'</div>
            <div class="cert-bottom">
              <div class="signature-area">'.($signature!==''?'<img class="signature-image" src="'.$this->e($signature).'">':'').'<div class="signature-line">'.$this->e($pastor).'</div><div class="signature-role">Responsável eclesiástico</div></div>
              <div class="cert-seal">'.($qr!==''?'<img src="'.$this->e($qr).'">':'').'<strong>OFICIAL</strong></div>
              <div class="signature-area"><div class="cert-date">'.$this->e($today).'</div><div class="signature-role">Data de emissão</div></div>
            </div>
          </div>
          <div class="auth-strip"><span>Autenticidade: <strong>'.$this->e($verification).'</strong></span><span>'.$this->e($churchLine).'</span></div>
        </main>';
    }

    private function letterHtml(string $church,string $congregation,string $address,string $title,string $subtitle,array $m,string $body,string $pastor,string $today,string $logo,string $signature,string $qr,string $verification,string $churchLine): string
    {
        return '<main class="paper letter">
          <div class="letter-top"></div>
          <header class="letter-head"><div>'.($logo!==''?'<img class="letter-logo" src="'.$this->e($logo).'">':'').'</div><div><div class="letter-church">'.$this->e($church).'</div>'.($congregation!==''?'<div class="letter-cong">'.$this->e($congregation).'</div>':'').'<div class="letter-address">'.$this->e($address?:'Endereço não informado').'</div></div></header>
          <h1 class="letter-title">'.$this->e($title).'</h1><div class="letter-subtitle">'.$this->e($subtitle).'</div>
          <div class="letter-recipient"><strong>Referência:</strong> '.$this->e($m['name']).'</div>
          <div class="letter-body">'.$body.'</div>
          <div class="letter-place">'.$this->e($congregation?:$church).', '.$this->e($today).'</div>
          <div class="letter-signature">'.($signature!==''?'<img src="'.$this->e($signature).'">':'').'<div class="line">'.$this->e($pastor).'</div><div class="role">Responsável eclesiástico</div></div>
          <div class="letter-auth"><div><div class="auth-title">Verificação de autenticidade</div><div class="auth-text">Este documento possui código único de verificação. Utilize o QR Code ou o código abaixo para validar.</div><div class="auth-code">'.$this->e($verification).'</div></div><div class="qr-box">'.($qr!==''?'<img src="'.$this->e($qr).'">':'').'</div></div>
          <div class="doc-footer">'.$this->e($churchLine).' · Documento gerado pelo sistema de gestão da igreja</div>
        </main>';
    }

    private function cardHtml(string $church,string $churchLine,string $credentialTitle,array $m,string $role,string $parents,string $pastor,string $address,string $today,Closure $fmt,string $logo,string $photo,string $qr,string $verification): string
    {
        $memberSince=$fmt($m['membership_date']??null);
        $baptismDate=$fmt($m['baptism_date']??null);
        $marital=trim((string)($m['marital_status']??''))?:'—';
        $rg=trim((string)($m['rg']??''))?:'—';
        $cpf=trim((string)($m['cpf']??''))?:'—';
        $naturalness=trim((string)($m['naturalness']??''))?:'—';
        $department=trim((string)($m['department']??''))?:'—';
        $congregation=trim((string)($m['congregation_name']??''))?:'Sede';

        return '<div class="cards">
          <section class="card-id card-front">
            <div class="card-swoosh one"></div><div class="card-swoosh two"></div>
            <div class="card-top">
              <div class="card-brand-logo">'.($logo!==''?'<img src="'.$this->e($logo).'" alt="Logo">':'<span>✦</span>').'</div>
              <div class="card-brand">
                <div class="card-church">'.$this->e($church).'</div>
                <div class="card-subtitle">'.$this->e($credentialTitle).'</div>
              </div>
            </div>

            <div class="card-main">
              <div class="card-photo">'.($photo!==''?'<img src="'.$this->e($photo).'" alt="Foto de '.$this->e($m['name']).'">':'<span>FOTO</span>').'</div>
              <div class="card-info">
                <div class="card-name-line"><span>'.$this->e($role).':</span> <strong>'.$this->e($m['name']).'</strong></div>
                <div class="card-data">
                  <div class="card-data-item"><b>Estado Civil</b><span>'.$this->e($marital).'</span></div>
                  <div class="card-data-item"><b>RG</b><span>'.$this->e($rg).'</span></div>
                  <div class="card-data-item"><b>CPF</b><span>'.$this->e($cpf).'</span></div>
                  <div class="card-data-item"><b>Membro desde</b><span>'.$this->e($memberSince).'</span></div>
                </div>
                <div class="card-congregation"><b>Congregação</b><span>'.$this->e($congregation).'</span></div>
              </div>
            </div>

            <div class="card-bottom-band"><strong>'.$this->e($role).': '.$this->e($m['name']).'</strong></div>
          </section>

          <section class="card-id card-back">
            <div class="card-swoosh one"></div><div class="card-swoosh two"></div>
            <div class="card-back-title">DADOS DA CREDENCIAL</div>

            <div class="back-layout">
              <div class="back-left">
                <div class="back-pill"><b>Filiação</b><span>'.$this->e($parents).'</span></div>
                <div class="back-row">
                  <div class="back-small"><b>Data de Batismo</b><span>'.$this->e($baptismDate).'</span></div>
                  <div class="back-small"><b>Naturalidade</b><span>'.$this->e($naturalness).'</span></div>
                </div>
                <div class="back-row">
                  <div class="back-small"><b>Departamento</b><span>'.$this->e($department).'</span></div>
                  <div class="back-small"><b>Emissão</b><span>'.$this->e($today).'</span></div>
                </div>
                <div class="back-pastor"><b>Pastor / Responsável</b><span>'.$this->e($pastor?:'—').'</span></div>
              </div>

              <div class="back-qr">
                '.($qr!==''?'<img src="'.$this->e($qr).'" alt="QR Code">':'<div class="qr-placeholder">QR</div>').'
                <small>'.$this->e($verification).'</small>
              </div>
            </div>

            <div class="back-address"><b>Endereço da igreja:</b> '.$this->e($address?:'Não informado').'</div>
            <div class="back-footer">'.$this->e($churchLine).'</div>
          </section>
        </div>';
    }

    private function field(string $label,string $value): string
    {
        return '<div class="field"><b>'.$this->e($label).'</b><span>'.$this->e($value).'</span></div>';
    }

    private function backField(string $label,string $value): string
    {
        return '<div class="back-field"><b>'.$this->e($label).'</b><span>'.$this->e($value).'</span></div>';
    }

    private function verifyUrl(array $church,string $verification,array $options): string
    {
        $base=trim((string)($options['base_url']??$church['public_url']??''));
        if($base===''){
            $host=(string)($_SERVER['HTTP_HOST']??'');
            if($host!==''){
                $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
                $base=$scheme.'://'.$host;
            }
        }
        return rtrim($base,'/').'/verify.php?code='.rawurlencode($verification);
    }

    private function qrDataUri(string $value): string
    {
        if(!class_exists('BaconQrCode\\Writer'))return '';
        try{
            $renderer=new BaconQrCode\Renderer\ImageRenderer(
                new BaconQrCode\Renderer\RendererStyle\RendererStyle(220,1),
                new BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );
            $writer=new BaconQrCode\Writer($renderer);
            $svg=$writer->writeString($value);
            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        }catch(Throwable){
            return '';
        }
    }

    private function asset(string $url): string
    {
        $url=trim($url);
        if($url==='')return '';

        $path=parse_url($url,PHP_URL_PATH)?:$url;
        $absolute=rtrim($this->appRoot,'/\\').'/'.ltrim($path,'/');

        // Sempre que a imagem pertencer ao próprio sistema, incorporamos os
        // bytes no HTML. Assim o mesmo arquivo funciona no navegador e no
        // Dompdf/WhatsApp sem depender de file://, permissões ou URL pública.
        if(is_file($absolute)&&is_readable($absolute)){
            $bytes=@file_get_contents($absolute);
            if(is_string($bytes)&&$bytes!==''){
                $mime='application/octet-stream';
                if(function_exists('finfo_open')){
                    $finfo=@finfo_open(FILEINFO_MIME_TYPE);
                    if($finfo){
                        $detected=@finfo_file($finfo,$absolute);
                        if(is_string($detected)&&$detected!=='')$mime=$detected;
                        @finfo_close($finfo);
                    }
                }
                if(!str_starts_with($mime,'image/')){
                    $ext=strtolower(pathinfo($absolute,PATHINFO_EXTENSION));
                    $mime=match($ext){
                        'png'=>'image/png',
                        'webp'=>'image/webp',
                        'gif'=>'image/gif',
                        default=>'image/jpeg',
                    };
                }
                return 'data:'.$mime.';base64,'.base64_encode($bytes);
            }
        }

        return preg_match('#^https?://#i',$url)?$url:'/'.ltrim($path,'/');
    }

    private function e(mixed $v): string
    {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private function css(string $kind): string
    {
        $common='*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#172033;font-family:DejaVu Sans,Arial,sans-serif}.paper{position:relative;background:#fff;overflow:hidden;page-break-inside:avoid;break-inside:avoid}img{max-width:100%}';

        if($kind==='certificate'){
            return '@page{size:A4 landscape;margin:0}'.$common.'
            body{width:297mm;height:210mm;overflow:hidden}
            .certificate{width:297mm;height:210mm;padding:15mm 22mm 14mm;border:3px solid #092f63}
            .certificate:before{content:"";position:absolute;inset:5mm;border:1px solid #d4aa37}.certificate:after{content:"";position:absolute;inset:8mm;border:1px solid #cad5e2}
            .corner{position:absolute;width:55mm;height:55mm}.corner.tl{left:-13mm;top:-18mm;background:#092f63}.corner.br{right:-13mm;bottom:-18mm;background:#d4aa37}
            .cert-content{position:relative;z-index:2;text-align:center;height:174mm}
            .cert-head{height:19mm;text-align:center}.cert-logo{width:18mm;height:18mm;object-fit:contain;vertical-align:middle;margin-right:4mm}.cert-church{display:inline-block;font-size:14pt;font-weight:700;color:#092f63;text-transform:uppercase}.cert-cong{font-size:8pt;color:#64748b}
            .cert-kicker{margin-top:5mm;font-size:8pt;color:#b48a16;font-weight:700;letter-spacing:2px;text-transform:uppercase}.cert-title{margin-top:1mm;color:#092f63;font-family:DejaVu Serif,serif;font-size:32pt;font-weight:700;text-transform:uppercase}.cert-subtitle{margin-top:1mm;color:#475569;font-size:9pt;text-transform:uppercase}
            .cert-presented{margin-top:6mm;font-size:8pt;color:#64748b}.cert-name{display:inline-block;margin-top:2mm;padding:0 10mm 2mm;border-bottom:1px solid #9cb2ca;color:#173e72;font-family:DejaVu Serif,serif;font-size:27pt;font-style:italic}.cert-body{width:205mm;margin:6mm auto 0;font-family:DejaVu Serif,serif;font-size:11pt;line-height:1.55;color:#27364a}
            .cert-bottom{position:absolute;left:10mm;right:10mm;bottom:6mm;display:table;width:calc(100% - 20mm)}.signature-area,.cert-seal{display:table-cell;width:33.33%;vertical-align:bottom;text-align:center}.signature-image{max-width:44mm;max-height:13mm}.signature-line,.cert-date{border-top:1px solid #334155;padding-top:2mm;font-size:8pt;font-weight:700}.signature-role{font-size:7pt;color:#64748b}.cert-seal img{width:20mm;height:20mm}.cert-seal strong{display:block;font-size:7pt;color:#092f63}.auth-strip{position:absolute;left:10mm;right:10mm;bottom:3mm;font-size:6pt;color:#607086}.auth-strip span:last-child{float:right}';
        }

        if($kind==='card'){
            return '@page{size:A4 portrait;margin:0}'.$common.'
            body{width:210mm;min-height:297mm;padding:14mm;background:#eef2f6}
            .cards{width:100%;display:block}
            .card-id{position:relative;width:85.6mm;height:54mm;margin:0 auto 12mm;overflow:hidden;border-radius:3mm;color:#fff;background:linear-gradient(135deg,#061b55 0%,#063f93 48%,#087bc0 100%);box-shadow:0 2mm 5mm rgba(4,22,61,.2)}
            .card-id:before{content:"";position:absolute;right:-12mm;top:-16mm;width:40mm;height:75mm;background:linear-gradient(155deg,rgba(63,214,226,.35),rgba(255,255,255,.05));transform:rotate(10deg)}
            .card-swoosh{position:absolute;left:-12mm;right:-12mm;height:1.2mm;background:#e3bc3f;transform:rotate(-8deg);opacity:.95}.card-swoosh.one{top:14mm}.card-swoosh.two{bottom:8mm;height:.7mm;opacity:.75}
            .card-top{position:relative;z-index:2;height:14mm;padding:2.5mm 4mm 1.5mm;display:flex;align-items:center;border-bottom:.45mm solid rgba(245,207,78,.85)}
            .card-brand-logo{width:10mm;height:10mm;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 10mm;box-shadow:0 0 0 .45mm rgba(244,202,63,.8)}.card-brand-logo img{width:100%;height:100%;object-fit:contain}.card-brand-logo span{color:#0b4a8f;font-size:7mm}
            .card-brand{padding-left:3mm;line-height:1.05}.card-church{font-size:7.8pt;font-weight:900;text-transform:uppercase;letter-spacing:.15mm;text-shadow:0 .2mm .4mm rgba(0,0,0,.35)}.card-subtitle{margin-top:1mm;font-size:4.6pt;font-style:italic;color:#dcecff}
            .card-main{position:relative;z-index:2;display:flex;gap:3mm;padding:3mm 4mm 0}
            .card-photo{width:23mm;height:28mm;flex:0 0 23mm;border-radius:2mm;overflow:hidden;background:#fff;border:.55mm solid #f2d16a;display:flex;align-items:center;justify-content:center;color:#375679;font-size:6pt;font-weight:700}.card-photo img{width:100%;height:100%;object-fit:cover}
            .card-info{flex:1;min-width:0}.card-name-line{font-size:6.8pt;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2.5mm}.card-name-line span{font-size:6pt}.card-name-line strong{font-size:7.5pt;text-transform:uppercase}
            .card-data{display:grid;grid-template-columns:1fr 1fr;gap:1.4mm 2mm}.card-data-item b,.card-congregation b{display:block;font-size:4.2pt;color:#d7e7ff;margin-bottom:.4mm}.card-data-item span,.card-congregation span{display:block;background:#edf4fb;color:#16365f;border-radius:2mm;padding:.7mm 1.5mm;font-size:5.1pt;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .card-congregation{margin-top:1.4mm}.card-bottom-band{position:absolute;z-index:3;left:0;right:0;bottom:0;height:7mm;display:flex;align-items:center;justify-content:center;background:linear-gradient(90deg,rgba(4,31,92,.94),rgba(5,68,145,.94));border-top:.4mm solid #dfb93f;text-transform:uppercase;font-size:6.4pt;letter-spacing:.25mm}
            .card-back{padding:0}.card-back-title{position:relative;z-index:2;margin:3mm 4mm 1.7mm;font-size:6.8pt;font-weight:900;letter-spacing:.3mm;border-bottom:.45mm solid #d9b33a;padding-bottom:1.3mm}
            .back-layout{position:relative;z-index:2;display:flex;gap:3mm;padding:0 4mm}.back-left{flex:1;min-width:0}.back-pill b,.back-small b,.back-pastor b{display:block;color:#d8e8ff;font-size:4.1pt;margin-bottom:.5mm}.back-pill span,.back-small span,.back-pastor span{display:block;background:#edf4fb;color:#17375f;border-radius:2mm;padding:.75mm 1.5mm;font-size:5pt;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .back-row{display:grid;grid-template-columns:1fr 1fr;gap:2mm;margin-top:1.5mm}.back-pastor{margin-top:1.5mm}.back-qr{width:23mm;flex:0 0 23mm;text-align:center;background:#fff;border-radius:2mm;padding:1.5mm;color:#193b65;box-shadow:0 0 0 .45mm #d9b33a}.back-qr img{width:18mm;height:18mm;object-fit:contain}.back-qr small{display:block;font-size:3.6pt;line-height:1.1;word-break:break-all}.qr-placeholder{height:18mm;display:flex;align-items:center;justify-content:center;font-weight:900}
            .back-address{position:absolute;z-index:2;left:4mm;right:4mm;bottom:6.3mm;font-size:4.5pt;color:#eff7ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.back-footer{position:absolute;z-index:2;left:0;right:0;bottom:0;height:5mm;background:rgba(4,31,92,.92);border-top:.4mm solid #d9b33a;display:flex;align-items:center;justify-content:center;font-size:4.3pt;font-weight:800;text-transform:uppercase;letter-spacing:.2mm}';
        }

        return '@page{size:A4 portrait;margin:0}'.$common.'
        body{width:210mm;height:297mm;overflow:hidden}
        .letter{width:210mm;height:297mm;padding:0 18mm 14mm;page-break-after:avoid}
        .letter-top{height:4mm;background:linear-gradient(90deg,#092f63,#0c4f91,#d4aa37)}
        .letter-head{padding:10mm 0 6mm;border-bottom:2px solid #092f63}.letter-logo{width:18mm;height:18mm;object-fit:contain;vertical-align:middle;margin-right:5mm}.letter-church{display:inline-block;font-size:15pt;font-weight:700;color:#092f63;text-transform:uppercase}.letter-cong{font-size:9pt;color:#526277;margin-left:23mm}.letter-address{font-size:7pt;color:#7a8798;margin-left:23mm}
        .letter-title{text-align:center;margin:14mm 0 1mm;color:#092f63;font-family:DejaVu Serif,serif;font-size:20pt;text-transform:uppercase}.letter-subtitle{text-align:center;color:#b48a16;font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:1px}.letter-recipient{margin-top:13mm;font-size:10pt}.letter-body{margin-top:8mm;font-family:DejaVu Serif,serif;font-size:11pt;line-height:1.72;text-align:justify}.letter-place{text-align:right;margin-top:12mm;font-size:9pt;color:#526277}
        .letter-signature{margin:15mm auto 0;width:85mm;text-align:center}.letter-signature img{max-width:50mm;max-height:15mm}.letter-signature .line{border-top:1px solid #334155;padding-top:2mm;font-size:9pt;font-weight:700}.letter-signature .role{font-size:7pt;color:#64748b}
        .letter-auth{position:absolute;left:18mm;right:18mm;bottom:22mm;border:1px solid #d9e1ea;border-left:4px solid #d4aa37;background:#f8fafc;padding:4mm;min-height:27mm}.auth-title{font-size:8pt;font-weight:700;color:#092f63}.auth-text{font-size:7pt;color:#64748b;width:130mm}.auth-code{font-size:7pt;font-weight:700}.qr-box{position:absolute;right:5mm;top:3mm}.qr-box img{width:20mm;height:20mm}.doc-footer{position:absolute;left:18mm;right:18mm;bottom:10mm;text-align:center;font-size:6pt;color:#94a3b8}';
    }

}
