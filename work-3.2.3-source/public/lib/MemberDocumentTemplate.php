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
        return '<div class="cards">
          <section class="card-id front"><div class="card-inner">
            <div class="card-head"><div class="brand-logo">'.($logo!==''?'<img src="'.$this->e($logo).'">':'<span>✦</span>').'</div><div class="brand-text"><strong>'.$this->e($church).'</strong><small>'.$this->e($credentialTitle).'</small></div></div>
            <div class="front-body"><div class="photo">'.($photo!==''?'<img src="'.$this->e($photo).'">':'<span>FOTO DO MEMBRO</span>').'</div><div><div class="member-name">'.$this->e($m['name']).'</div><div class="cred-title">'.$this->e($role).'</div><div class="data-grid">
              '.$this->field('Estado Civil',$m['marital_status']?:'—').$this->field('RG',$m['rg']?:'—').$this->field('CPF',$m['cpf']?:'—').$this->field('Membro desde',$fmt($m['membership_date']??null)).$this->field('Congregação',$m['congregation_name']?:'Sede').$this->field('Emissão',$today).'
            </div></div></div><div class="role-band">'.$this->e($role).' · '.$this->e($m['name']).'</div>
          </div></section>
          <section class="card-id back"><div class="card-inner">
            <div class="card-head"><div class="brand-logo">'.($logo!==''?'<img src="'.$this->e($logo).'">':'<span>✦</span>').'</div><div class="brand-text"><strong>'.$this->e($churchLine).'</strong><small>IDENTIFICAÇÃO ECLESIÁSTICA · AUTENTICIDADE DIGITAL</small></div></div>
            <div class="back-grid"><div class="back-fields">'.$this->backField('Filiação — Mãe / Pai',$parents).$this->backField('Data de Batismo',$fmt($m['baptism_date']??null)).$this->backField('Naturalidade',$m['naturalness']?:'—').$this->backField('Departamento',$m['department']?:'—').$this->backField('Pastor / Responsável',$pastor?:'—').'</div><div class="qr-wrap">'.($qr!==''?'<img src="'.$this->e($qr).'">':'').'<div>'.$this->e($verification).'</div></div></div>
            <div class="address"><strong>Endereço da igreja:</strong> '.$this->e($address?:'Não informado').'</div>
          </div></section>
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
        if(preg_match('#^https?://#i',$url))return $url;
        $path=parse_url($url,PHP_URL_PATH)?:$url;
        $absolute=rtrim($this->appRoot,'/\\').'/'.ltrim($path,'/');
        if(!is_file($absolute))return '';
        return 'file://'.$absolute;
    }

    private function e(mixed $v): string
    {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private function css(string $kind): string
    {
        $base='@page{size:A4 portrait;margin:8mm}*{box-sizing:border-box}body{margin:0;background:#fff;color:#172033;font-family:DejaVu Sans,Arial,sans-serif}.paper{position:relative;background:#fff;overflow:hidden}';
        if($kind==='certificate'){
            return $base.'.certificate{height:190mm;padding:14mm 18mm 15mm;border:3px solid #092f63}.certificate:before{content:"";position:absolute;inset:4mm;border:1px solid #d4aa37}.certificate:after{content:"";position:absolute;inset:7mm;border:1px solid #cad5e2}.corner{position:absolute;width:45mm;height:45mm}.corner.tl{left:-10mm;top:-14mm;background:#092f63}.corner.br{right:-10mm;bottom:-14mm;background:#d4aa37}.cert-content{position:relative;z-index:2;text-align:center;height:157mm}.cert-head{height:18mm;text-align:center}.cert-logo{width:17mm;height:17mm;object-fit:contain;vertical-align:middle;margin-right:4mm}.cert-church{display:inline-block;font-size:12pt;font-weight:700;color:#092f63;text-transform:uppercase}.cert-cong{font-size:8pt;color:#64748b}.cert-kicker{margin-top:7mm;font-size:8pt;color:#b48a16;font-weight:700;letter-spacing:2px;text-transform:uppercase}.cert-title{margin-top:2mm;color:#092f63;font-family:DejaVu Serif,serif;font-size:31pt;font-weight:700;text-transform:uppercase}.cert-subtitle{margin-top:2mm;color:#475569;font-size:9pt;text-transform:uppercase}.cert-presented{margin-top:8mm;font-size:8pt;color:#64748b}.cert-name{display:inline-block;margin-top:2mm;padding:0 8mm 2mm;border-bottom:1px solid #9cb2ca;color:#173e72;font-family:DejaVu Serif,serif;font-size:25pt;font-style:italic}.cert-body{width:150mm;margin:7mm auto 0;font-family:DejaVu Serif,serif;font-size:11pt;line-height:1.65;color:#27364a}.cert-bottom{position:absolute;left:0;right:0;bottom:8mm;display:table;width:100%}.signature-area,.cert-seal{display:table-cell;width:33.33%;vertical-align:bottom;text-align:center}.signature-image{max-width:42mm;max-height:14mm}.signature-line,.cert-date{border-top:1px solid #334155;padding-top:2mm;font-size:8pt;font-weight:700}.signature-role{font-size:7pt;color:#64748b}.cert-seal img{width:22mm;height:22mm}.cert-seal strong{display:block;font-size:7pt;color:#092f63}.auth-strip{position:absolute;left:8mm;right:8mm;bottom:3mm;font-size:6pt;color:#607086}.auth-strip span:last-child{float:right}';
        }
        if($kind==='card'){
            return $base.'@page{size:A4 portrait;margin:10mm}.cards{width:100%}.card-id{position:relative;width:85.6mm;height:54mm;margin:0 0 8mm;overflow:hidden;border-radius:3mm;color:#fff;background:#0750a0}.card-inner{padding:4mm}.card-head{height:11mm;border-bottom:1px solid #6eb3e9}.brand-logo{display:inline-block;width:9mm;height:9mm;background:#fff;border-radius:50%;vertical-align:middle;text-align:center;overflow:hidden}.brand-logo img{width:9mm;height:9mm;object-fit:cover}.brand-text{display:inline-block;vertical-align:middle;margin-left:3mm;width:60mm}.brand-text strong{display:block;font-size:8pt}.brand-text small{display:block;font-size:5pt}.front-body{margin-top:3mm}.photo{display:inline-block;width:22mm;height:27mm;background:#fff;border-radius:2mm;vertical-align:top;text-align:center;color:#567}.photo img{width:22mm;height:27mm;object-fit:cover}.photo span{font-size:5pt}.front-body>div:last-child{display:inline-block;width:50mm;margin-left:3mm;vertical-align:top}.member-name{font-size:10pt;font-weight:700;text-transform:uppercase}.cred-title{display:inline-block;margin:2mm 0;padding:1mm 2mm;background:#fff;color:#0750a0;border-radius:3mm;font-size:6pt;font-weight:700}.data-grid{font-size:5pt}.field{display:inline-block;width:48%;margin-bottom:1.5mm}.field b,.back-field b{display:block;color:#cfeaff}.field span,.back-field span{font-weight:700}.role-band{position:absolute;left:4mm;bottom:3mm;font-size:6pt;font-weight:700}.back-grid{margin-top:4mm}.back-fields{display:inline-block;width:54mm;vertical-align:top;font-size:5.5pt}.back-field{margin-bottom:2mm}.qr-wrap{display:inline-block;width:20mm;background:#fff;color:#092f63;padding:2mm;vertical-align:top;text-align:center;border-radius:2mm;font-size:4pt}.qr-wrap img{width:16mm;height:16mm}.address{position:absolute;left:4mm;right:4mm;bottom:3mm;font-size:4.8pt}';
        }
        return $base.'.letter{min-height:270mm;padding:0 18mm 12mm}.letter-top{height:4mm;background:#092f63}.letter-head{padding:9mm 0 6mm;border-bottom:2px solid #092f63}.letter-logo{width:18mm;height:18mm;object-fit:contain;vertical-align:middle;margin-right:5mm}.letter-church{display:inline-block;font-size:15pt;font-weight:700;color:#092f63;text-transform:uppercase}.letter-cong{font-size:9pt;color:#526277;margin-left:23mm}.letter-address{font-size:7pt;color:#7a8798;margin-left:23mm}.letter-title{text-align:center;margin:14mm 0 1mm;color:#092f63;font-family:DejaVu Serif,serif;font-size:20pt;text-transform:uppercase}.letter-subtitle{text-align:center;color:#b48a16;font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:1px}.letter-recipient{margin-top:13mm;font-size:10pt}.letter-body{margin-top:8mm;font-family:DejaVu Serif,serif;font-size:11pt;line-height:1.8;text-align:justify}.letter-place{text-align:right;margin-top:13mm;font-size:9pt;color:#526277}.letter-signature{margin:18mm auto 0;width:85mm;text-align:center}.letter-signature img{max-width:50mm;max-height:16mm}.letter-signature .line{border-top:1px solid #334155;padding-top:2mm;font-size:9pt;font-weight:700}.letter-signature .role{font-size:7pt;color:#64748b}.letter-auth{margin-top:18mm;border:1px solid #d9e1ea;border-left:4px solid #d4aa37;background:#f8fafc;padding:4mm}.auth-title{font-size:8pt;font-weight:700;color:#092f63}.auth-text{font-size:7pt;color:#64748b;width:135mm}.auth-code{font-size:7pt;font-weight:700}.qr-box{position:absolute;right:24mm;margin-top:-18mm}.qr-box img{width:20mm;height:20mm}.doc-footer{margin-top:8mm;text-align:center;font-size:6pt;color:#94a3b8}';
    }
}
