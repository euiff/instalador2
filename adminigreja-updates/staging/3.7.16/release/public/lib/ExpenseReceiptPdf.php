<?php
declare(strict_types=1);

/**
 * One-page PDF voucher generator; no external executables/libraries.
 * Browser-captured signature is embedded as JPEG when present.
 * A pending paper receipt is visibly marked as unsigned.
 */
final class ExpenseReceiptPdf
{
    private const WIDTH=595;
    private const HEIGHT=842;

    private static function ps(string $value):string
    {
        $s=@iconv('UTF-8','Windows-1252//TRANSLIT',$value);
        if($s===false)$s=preg_replace('/[^\x20-\x7e]/','?', $value)??'';
        return str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)', ' ',' '],$s);
    }

    private static function text(string &$stream,string $label,int $x,int $y,int $size=11,bool $bold=false):void
    {
        $stream.='BT /'.($bold?'F2':'F1').' '.$size.' Tf 1 0 0 1 '.$x.' '.$y.' Tm ('.self::ps($label).") Tj ET\n";
    }

    private static function wrap(string $value,int $limit=84):array
    {
        $words=preg_split('/\s+/u',trim($value))?:[];
        $lines=[];$row='';
        foreach($words as $word){
            if($word==='')continue;
            if(mb_strlen($word)>$limit){
                if($row!==''){$lines[]=$row;$row='';}
                while(mb_strlen($word)>$limit){$lines[]=mb_substr($word,0,$limit);$word=mb_substr($word,$limit);}
            }
            $test=$row===''?$word:$row.' '.$word;
            if(mb_strlen($test)>$limit && $row!==''){$lines[]=$row;$row=$word;}else $row=$test;
        }
        if($row!=='')$lines[]=$row;
        return array_slice($lines,0,9);
    }

    private static function money(string $amount):string
    {
        return 'R$ '.number_format((float)$amount,2,',','.');
    }

    public static function render(array $receipt,array $entry,string $church,?string $signatureJpeg=null):string
    {
        $signed=$receipt['status']==='signed_screen'&&is_string($signatureJpeg)&&$signatureJpeg!=='';
        $physical=$receipt['status']==='signed_paper';
        $img=null;$width=0;$height=0;$channels=3;
        if($signed){
            if(strlen($signatureJpeg)>500000)throw new InvalidArgumentException('Assinatura digitalizada muito grande.');
            $info=@getimagesizefromstring($signatureJpeg);
            if(!$info || ($info['mime']??'')!=='image/jpeg' || $info[0]<150||$info[0]>1800 || $info[1]<70||$info[1]>900)
                throw new InvalidArgumentException('Imagem de assinatura inválida.');
            $width=(int)$info[0];$height=(int)$info[1];$channels=(int)($info['channels']??3);
            if(!in_array($channels,[1,3],true))throw new InvalidArgumentException('Assinatura JPEG incompatível.');
            $img=$signatureJpeg;
        }
        $st="0.08 0.19 0.28 rg\n";
        self::text($st,'RECIBO DE DESPESA',58,775,19,true);
        self::text($st,mb_substr($church,0,65),58,752,12,true);
        $st.="0.50 0.55 0.59 RG 0.7 w 58 735 m 537 735 l S\n";
        $date=(string)($entry['transaction_date']??'');
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))$date=substr($date,8,2).'/'.substr($date,5,2).'/'.substr($date,0,4);
        self::text($st,'Comprovante gerado pela tesouraria da igreja',58,711,11);
        self::text($st,'Data da despesa: '.$date,58,687,11);
        self::text($st,'Valor pago: '.self::money((string)($entry['amount']??0)),58,662,14,true);
        self::text($st,'Recebedor(a): '.mb_substr((string)($receipt['recipient_name']??''),0,70),58,632,11);
        $doc=trim((string)($receipt['recipient_document']??''));
        if($doc!=='')self::text($st,'Documento informado pelo recebedor: '.$doc,58,615,10);
        $dept=trim((string)($entry['department_name']??''));
        self::text($st,'Setor: '.($dept?:'Tesouraria geral'),58,590,11);
        self::text($st,'Forma de pagamento: '.((string)($entry['payment_method']??'')?:'Não informada'),58,573,11);
        $cat=(string)($entry['category']??'');
        self::text($st,'Categoria: '.mb_substr($cat,0,70),58,556,10);
        self::text($st,'Referente a:',58,524,11,true);
        $y=506;
        foreach(self::wrap((string)($entry['description']??''),81) as $line){
            self::text($st,$line,58,$y,11);
            $y-=16;
        }
        $base=min($y-39,373);
        self::text($st,'Declaro ter recebido o valor acima pela despesa descrita.',58,$base,11);
        self::text($st,'Assinatura do recebedor:',58,$base-36,11,true);
        if($signed&&$img!==null){
            // White signature canvas embedded as read-only image.
            $st.="q 250 0 0 88 70 ".($base-137)." cm /Im1 Do Q\n";
            self::text($st,'Assinado na tela em '.(string)($receipt['signed_at']??''),58,$base-160,9);
        }else{
            $st.="0.42 0.47 0.51 RG 0.8 w 58 ".($base-116)." m 360 ".($base-116)." l S\n";
            if($physical)self::text($st,'Via física assinada e anexada ao sistema separadamente.',58,$base-140,10);
            else self::text($st,'AGUARDANDO ASSINATURA MANUAL — NAO E COMPROVANTE CONCLUIDO',58,$base-143,10,true);
        }
        $st.="0.65 0.68 0.71 RG 0.7 w 58 148 m 537 148 l S\n";
        self::text($st,'Nº de controle: '.substr((string)($receipt['id']??''),0,36),58,132,9);
        self::text($st,'Referência do lançamento: '.substr((string)($entry['id']??''),0,36),58,116,9);
        self::text($st,'Este recibo não substitui nota ou documento fiscal exigido pela legislação.',58,94,9);
        self::text($st,'A assinatura na tela registra a declaração do signatário; identidade não verificada automaticamente.',58,80,8);
        $streams=['1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n',
                  '2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n'];
        $resources='<< /Font << /F1 4 0 R /F2 5 0 R >>';
        if($img!==null)$resources.=' /XObject << /Im1 7 0 R >>';
        $resources.=' >>';
        $streams[]='3 0 obj'."\n".'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] /Resources '.$resources.' /Contents 6 0 R >>'."\nendobj\n";
        $streams[]="4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $streams[]="5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";
        $streams[]='6 0 obj'."\n".'<< /Length '.strlen($st).' >>'."\nstream\n".$st."endstream\nendobj\n";
        if($img!==null){
            $streams[]='7 0 obj'."\n".'<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace '.($channels===1?'/DeviceGray':'/DeviceRGB').' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($img).' >>'."\nstream\n".$img."\nendstream\nendobj\n";
        }
        $pdf="%PDF-1.4\n%"."\xE2\xE3\xCF\xD3"."\n";$offset=[0];
        foreach($streams as $i=>$object){
            $offset[]=strlen($pdf);$pdf=$pdf.str_replace('\\n',"\n",$object);
        }
        $xref=strlen($pdf);$pdf.='xref'."\n0 ".count($offset)."\n0000000000 65535 f \n";
        for($i=1;$i<count($offset);$i++)$pdf.=sprintf('%010d 00000 n ', $offset[$i])."\n";
        $pdf.='trailer'."\n".'<< /Size '.count($offset).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
        return $pdf;
    }
}
