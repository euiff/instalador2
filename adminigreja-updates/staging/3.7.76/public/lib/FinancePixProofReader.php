<?php
declare(strict_types=1);

/**
 * Non-AI Pix receipt extraction for text produced by local OCR.
 * This class is deliberately limited to PIX payment confirmations; it never
 * invents a bank reference, merchant CPF/CNPJ or an accounting category.
 */
final class FinancePixProofReader
{
    private static function fold(string $value): string
    {
        return strtr(mb_strtoupper($value,'UTF-8'),[
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','Ê'=>'E',
            'Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ü'=>'U','Ç'=>'C',
        ]);
    }

    public static function detect(string $text): bool
    {
        $upper=self::fold($text);
        return (str_contains($upper,'PIX') &&
            (str_contains($upper,'COMPROVANTE')||str_contains($upper,'TRANSFERIDO')
                ||str_contains($upper,'TRANSACAO')||str_contains($upper,'TRANSAÇAO')))
            ||preg_match('/\b(?:ID\s+DA\s+TRANSA[ÇCG][ÃA]O|ID\s+PIX)\b/iu',$text)===1;
    }

    private static function party(array $lines,string $label): ?string
    {
        $on=false;$reading=false;$chunks=[];
        foreach($lines as $line){
            $fold=self::fold(trim($line));
            if(preg_match('/^\s*(?:RECEBEDOR|PAGADOR)\s*$/u',$fold)){
                if($on)break;
                $on=$fold===$label;
                $reading=false;
                continue;
            }
            if(!$on)continue;
            if(preg_match('/\b(?:CPF|CNPJ|INSTITUICAO|AGENCIA|CONTA|CHAVE PIX)\b/u',$fold))break;
            if(preg_match('/^NOME\s*[:\-]?\s*(.*)$/u',$fold,$m)){
                $reading=true;
                $after=trim(preg_replace('/^NOME\s*[:\-]?\s*/iu','',$line)??'');
                if($after!=='')$chunks[]=$after;
                continue;
            }
            if(!$reading)continue;
            if(preg_match('/^[*.\-\/\d ]{5,}$/',$line))break;
            if(mb_strlen($line)>2 && preg_match('/[\p{L}]/u',$line))$chunks[]=$line;
            if(count($chunks)>=4)break;
        }
        $name=trim(preg_replace('/\s+/u',' ',implode(' ',$chunks))??'');
        return mb_strlen($name)>=4?mb_substr($name,0,190):null;
    }

    private static function bankId(string $text,array &$warnings): ?string
    {
        // A real Pix EndToEnd ID is exactly E + eight digits + 23 alnum.
        // Search strict IDs anywhere (OCR may reorder columns and labels).
        if(preg_match('/(?<![A-Z0-9])E[0-9]{8}[A-Z0-9]{23}(?![A-Z0-9])/iu',$text,$found)){
            $candidate=strtoupper($found[0]);
            if(FinanceFiscalScan::isPixE2E($candidate))return $candidate;
        }
        // Common optical ambiguity: text printed E... appears as £...
        // Only correct the leading glyph if the bank reference label is also
        // present nearby. Never alter interior characters of bank IDs.
        if(!preg_match('/\bID\s*(?:DA\s*)?(?:TRANSA\S{1,16}|PIX)\b/iu',$text,$label,PREG_OFFSET_CAPTURE))
            return null;
        if(preg_match_all('/[£€][0-9]{8}[A-Z0-9]{23}/iu',$text,$matches,PREG_OFFSET_CAPTURE)){
            foreach($matches[0] as [$printed,$offset]){
                if(abs($offset-$label[0][1])>210)continue;
                $candidate='E'.strtoupper(mb_substr($printed,1));
                if(FinanceFiscalScan::isPixE2E($candidate)){
                    $warnings[]='Primeira letra do ID Pix corrigida após OCR (E parecia £). Confira os 32 caracteres no comprovante.';
                    return $candidate;
                }
            }
        }
        return null;
    }

    public static function extract(string $text): ?array
    {
        if(!self::detect($text))return null;
        $text=mb_substr(str_replace(["\r\n","\r"],"\n",$text),0,60000);
        $lines=array_values(array_filter(array_map('trim',preg_split('/\n/u',$text)?:[]),static fn($v)=>$v!==''));
        $upper=self::fold($text);
        $warnings=[];
        $direction=null;
        if(preg_match('/(?:COMPROVANTE\s+DE\s+ENVIO\s+PIX|PIX\s+ENVIADO|PIX\s+ENVIADA|TRANSFERIDO\s+EM)/u',$upper)){
            $direction='expense';
        }elseif(preg_match('/(?:COMPROVANTE\s+DE\s+RECEBIMENTO\s+PIX|PIX\s+RECEBIDO|PIX\s+RECEBIDA)/u',$upper)){
            $direction='income';
        }

        // Crucial: an isolated "R$ 2.172,03" underneath the Pix title is
        // the amount. The generic fiscal parser used to miss this layout.
        $amount=null;
        foreach($lines as $line){
            if(preg_match('/(?:^|\s)R\s*[$S]\s*([\d]{1,3}(?:\.[\d]{3})*,[\d]{2}|[\d]+,[\d]{2})\b/iu',$line,$m)){
                $amount=FinanceFiscalScan::money($m[1]);if($amount!==null)break;
            }
        }
        if($amount===null){
            foreach($lines as $line){
                if(preg_match('/(?:VALOR\s+(?:PIX|TRANSFERIDO|RECEBIDO|ENVIADO|DA\s+TRANSACAO|DA\s+TRANSFERENCIA)|TOTAL\s+(?:PAGO|RECEBIDO))\s*[:\-]?\s*(?:R\s*[$S]\s*)?([\d.,]+)/iu',self::fold($line),$m)){
                    $amount=FinanceFiscalScan::money($m[1]);if($amount!==null)break;
                }
            }
        }

        // Only use actual payment date, NOT "comprovante gerado em" time.
        $date=null;$time=null;$paymentLine=null;
        foreach($lines as $i=>$line){
            $normalized=self::fold($line);
            if(preg_match('/(?:TRANSFERID[AO]|REALIZAD[AO]|PAGO|ENVIADO|RECEBIDO)\s+EM\s*' .
                    '(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})' .
                    '(?:\s*(?:AS|A[S]?)\s*(\d{2}):(\d{2})(?::(\d{2}))?)?/u',$normalized,$m)
                && checkdate((int)$m[2],(int)$m[1],(int)$m[3])){
                $date=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
                if(isset($m[4])&&$m[4]!=='')$time=sprintf('%02d:%02d:%02d',(int)$m[4],(int)($m[5]??0),(int)($m[6]??0));
                $paymentLine=$i;
                break;
            }
        }
        if($date===null)$warnings[]='Data do pagamento não encontrada com segurança. Não confundir com horário de geração do PDF.';
        if($amount===null)$warnings[]='Valor Pix não reconhecido com segurança. Confira manualmente.';

        $receiver=self::party($lines,'RECEBEDOR');
        $payer=self::party($lines,'PAGADOR');
        $counterparty=$direction==='expense'?$receiver:($direction==='income'?$payer:null);
        if($counterparty===null)$warnings[]='Nome do pagador ou recebedor não identificado com segurança. Confira antes de lançar.';
        if(!$direction)$warnings[]='Não foi possível identificar se o Pix foi enviado ou recebido. Selecione Entrada/Saída manualmente.';

        $bankId=self::bankId($text,$warnings);
        if(!$bankId)$warnings[]='ID EndToEnd Pix não confirmado. Confira no rodapé; o sistema usará um ID interno do arquivo apenas para detectar a mesma cópia.';

        $memo=null;
        if($paymentLine!==null){
            foreach(array_slice($lines,$paymentLine+1,3) as $line){
                $u=self::fold($line);
                if(preg_match('/^(?:COMPROVANTE|RECEBEDOR|PAGADOR|ID\s+DA|CONFERENCIA|GERADO|[0-9*\/.]+)/u',$u))break;
                if(preg_match('/[\p{L}]/u',$line)&&mb_strlen($line)>4&&mb_strlen($line)<145){
                    $memo=trim($line);break;
                }
            }
        }
        $description=($direction==='expense'?'Pix enviado':($direction==='income'?'Pix recebido':'Comprovante Pix'));
        if($memo)$description.=' — '.$memo;
        elseif($counterparty)$description.=' — '.$counterparty;

        return [
            'document_type'=>'proof','direction'=>$direction,
            'amount'=>$amount,'date'=>$date,'payment_time'=>$time,
            'issuer'=>$counterparty,'receiver'=>$receiver,'payer'=>$payer,
            'issuer_tax_id'=>null, // masked CNPJ is NOT a valid complete tax ID
            'payment_method'=>'PIX manual','bank_reference'=>$bankId,
            'bank_reference_type'=>$bankId?'pix_e2e':null,
            'document_number'=>$bankId,'short_number'=>null,'access_key'=>null,
            'description'=>mb_substr($description,0,255),'category'=>null,
            'bank_name'=>str_contains($upper,'SICOOB')?'Sicoob':null,
            'warnings'=>$warnings,
            'confidence'=>($amount!==null && $date!==null && $bankId!==null && $counterparty!==null)
                ?'high':($amount!==null && $date!==null?'medium':'low'),
        ];
    }
}
