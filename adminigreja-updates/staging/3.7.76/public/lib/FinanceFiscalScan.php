<?php
declare(strict_types=1);
require_once __DIR__.'/FinancePixProofReader.php';

/**
 * Heuristic parsing of Portuguese fiscal receipts and duplicate checks.
 * OCR is performed in the user's browser; text is sent only to this church's server.
 * Never treat OCR output as authoritative accounting data.
 */
final class FinanceFiscalScan
{
    public static function key44(string $text): ?string
    {
        preg_match_all('/(?:\d[\s.\-]?){44}/u', $text, $matches);
        foreach ($matches[0] ?? [] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate);
            if (strlen($digits) !== 44 || !in_array(substr($digits, 20, 2), ['55','65'], true)) continue;
            $sum=0;$weight=2;
            for ($i=42;$i>=0;$i--) {$sum+=(int)$digits[$i]*$weight;$weight=$weight===9?2:$weight+1;}
            $digit=11-($sum%11);if($digit>=10)$digit=0;
            if($digit===(int)$digits[43]) return $digits;
        }
        return null;
    }

    public static function cleanDocumentNumber(string $value): ?string
    {
        $value=trim(preg_replace('/\s+/u', '', $value) ?? '');
        if ($value==='' || strlen($value)>120) return null;
        $value=preg_replace('/[^A-Za-z0-9\-_.\/]/', '', $value) ?? '';
        return $value!==''?$value:null;
    }

    /** A bank's EndToEnd Pix identifier is bank-issued and globally precise. */
    public static function isPixE2E(string $value): bool
    {
        return preg_match('/^E[0-9]{8}[A-Z0-9]{23}$/D',strtoupper(trim($value)))===1;
    }

    /** A stable local fingerprint identifies one byte-identical PDF or image.
     * It does NOT pretend to be a bank authentication number.
     */
    public static function fileReference(?string $sha256): ?string
    {
        $sha256=strtolower(trim((string)$sha256));
        return preg_match('/^[a-f0-9]{64}$/D',$sha256)
            ?'ARQ-'.strtoupper(substr($sha256,0,32)):null;
    }

    public static function isFileReference(string $value): bool
    {
        return preg_match('/^ARQ-[A-F0-9]{32}$/D',strtoupper($value))===1;
    }

    /** Never generate or invent a bank ID: only normalize a printed one.
     * Do not accept CPF, CNPJ or QR Pix payment payload as a transaction ID.
     */
    public static function bankReference(?string $raw,?string $type=null): ?string
    {
        $raw=trim((string)$raw);
        if($raw===''||mb_strlen($raw)>120)return null;
        $value=preg_replace('/\s+/u','',$raw)??'';
        $value=strtoupper($value);
        if(self::isPixE2E($value))return $value;
        if($type==='pix_e2e')return null; // Refuse incomplete/malformed bank E2E.
        if(!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{5,119}$/D',$value))return null;
        if(!preg_match('/[0-9]/',$value))return null;
        // CPF/CNPJ and fiscal access keys are not bank authentication IDs,
        // even when punctuated as 123.456.789-00 or 12.345.678/0001-00.
        $justDigits=preg_replace('/\D/','',$value)??'';
        if(preg_match('/^[0-9.\/-]+$/D',$value)
            && in_array(strlen($justDigits),[11,14,44],true))return null;
        if(preg_match('/^(?:N\/A|SEMREFERENCIA|NAOINFORMADO|PENDENTE|000000+)$/D',$value))return null;
        return $value;
    }

    public static function parse(string $text): array
    {
        $text=mb_substr(str_replace(["\r\n","\r"], "\n", $text),0,60000);
        $lines=array_values(array_filter(array_map('trim', explode("\n", $text)),static fn($x)=>$x!==''));
        // Bank receipts have distinct semantics: one Pix sent is an expense,
        // and its payer/receiver names must never be mixed with the bank logo.
        // Most scanned PDFs (including Sicoob) have no selectable PDF text.
        $pix=FinancePixProofReader::extract($text);
        if($pix!==null)return $pix;
        $key=self::key44($text);
        $cnpj=null;
        foreach($lines as $line){
            if(preg_match('/(?:CNPJ(?:\/CPF)?\s*[:.]?\s*)(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/iu',$line,$m)){
                $cnpj=preg_replace('/\D/','',$m[1]);break;
            }
        }
        if(!$cnpj && $key) $cnpj=substr($key,6,14);

        $issuer=null;
        $head=array_slice($lines,0,min(18,count($lines)));
        foreach($head as $i=>$line){
            if (preg_match('/\bCNPJ\b/i',$line) && $i>0){
                for($j=$i-1;$j>=max(0,$i-4);$j--){
                    $name=trim($head[$j]);
                    if(mb_strlen($name)>=4 && preg_match('/[\p{L}]/u',$name)
                       && !preg_match('/NFC-E|DANFE|DOCUMENTO AUXILIAR|NOTA FISCAL|CNPJ|ENDEREÇO|CONSUMIDOR|DOCUMENTO FISCAL/i',$name)){
                        $issuer=mb_substr($name,0,190);break;
                    }
                }
                if($issuer)break;
            }
        }
        if(!$issuer){
            foreach(array_slice($head,0,5) as $line){
                if(mb_strlen($line)>4&&preg_match('/[\p{L}]/u',$line)
                   && !preg_match('/DANFE|NFC-E|NOTA FISCAL|CUPOM FISCAL|DOCUMENTO AUXILIAR|CNPJ|CPF|CONSUMIDOR/i',$line)){
                    $issuer=mb_substr($line,0,190);break;
                }
            }
        }

        $number=null;
        foreach($lines as $line){
            if(preg_match('/(?:N[º°o]\s*|N[úu]mero\s*(?:da\s*)?(?:NFC-?E|NF-?E|NOTA|CUPOM)?\s*[:#.]?\s*|(?:NFC-?E|CUPOM|NOTA)\s*N[º°o.]?\s*[:#.]?\s*)(\d{2,12})\b/iu',$line,$m)){
                $number=ltrim($m[1],'0')?:'0';break;
            }
        }
        if(!$number && $key) $number=ltrim(substr($key,25,9),'0')?:'0';

        $issueDate=null;
        foreach($lines as $line){
            if(preg_match('/(?:EMISS[ÃA]O|DATA|EMITIDA?\s*EM|D[TH]\s*EMI)\D{0,20}(\d{2})[\/.\-](\d{2})[\/.\-](\d{2,4})/iu',$line,$m)){
                $year=(int)$m[3];if($year<100)$year+=2000;
                if(checkdate((int)$m[2],(int)$m[1],$year)){$issueDate=sprintf('%04d-%02d-%02d',$year,(int)$m[2],(int)$m[1]);break;}
            }
        }
        // Transfers/Pix often say "Realizado em" instead of "Emissão".
        if(!$issueDate){
            foreach($lines as $line){
                if(preg_match('/(?:REALIZAD[AO]\s+EM|DATA\s+(?:DA\s+)?(?:TRANSA[ÇC][ÃA]O|TRANSFER[ÊE]NCIA|PAGAMENTO)|PAGO\s+EM)\D{0,24}(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})/iu',$line,$m)
                    && checkdate((int)$m[2],(int)$m[1],(int)$m[3])){
                    $issueDate=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);break;
                }
            }
        }
        // Fiscal access keys only encode year/month, never guess the day.
        if(!$issueDate && $key)$issueDate=null;

        $amount=null;
        $patterns=[
            '/(?:VALOR\s+(?:TOTAL\s+(?:DA\s+)?(?:NOTA|NFC-?E|CUPOM)?|A\s+PAGAR)|TOTAL\s+(?:A\s+PAGAR|DA\s+NOTA|R\$|GERAL)|TOTAL\s+DO\s+CUPOM|TOTAL\s*[:=])\s*[:=\-]?\s*(?:R\$\s*)?([\d.,]{2,18})/iu',
            '/(?:VALOR\s+TOTAL|TOTAL\s+GERAL|TOTAL\s+PAGO|TOTAL\s+DA\s+COMPRA)\D{0,15}([\d.,]{2,18})/iu',
            '/(?:VALOR\s+(?:DA\s+)?(?:TRANSFER[ÊE]NCIA|TRANSA[ÇC][ÃA]O|OPERA[ÇC][ÃA]O|PIX)|VALOR\s+(?:RECEBIDO|ENVIADO|TRANSFERIDO|PAGO)|VALOR\s*[:=])\s*[:=\-]?\s*(?:R\$\s*)?([\d.,]{2,18})/iu'
        ];
        foreach($patterns as $regex){
            if(preg_match_all($regex,$text,$matches) && !empty($matches[1])){
                foreach(array_reverse($matches[1]) as $candidate){
                    $value=self::money($candidate);
                    if($value!==null){$amount=$value;break 2;}
                }
            }
        }
        $payment=null;
        foreach($lines as $line){
            if(preg_match('/(?:FORMA\s+DE\s+PAGAMENTO|MEIO\s+DE\s+PAGAMENTO|PAGAMENTO)\s*[:\-]?\s*(PIX|CART[ÃA]O\s+(?:DE\s+)?(?:D[ÉE]BITO|CR[ÉE]DITO)|DINHEIRO|TRANSFER[ÊE]NCIA|BOLETO)/iu',$line,$m)){
                $payment=mb_substr(trim($m[1]),0,60);break;
            }
        }
        $bankRef=null;$bankRefType=null;
        // The 32-character Pix EndToEnd ID has a defined structure, unlike
        // short receipt references. Recognize it even if OCR missed its label.
        if(preg_match('/\bE\d{8}[A-Z0-9]{23}\b/iu',$text,$match)
            && self::isPixE2E(strtoupper($match[0]))){
            $bankRef=self::bankReference($match[0],'pix_e2e');
            $bankRefType='pix_e2e';
        }
        // A printed bank reference takes precedence for payment receipts.
        foreach($lines as $line){
            if($bankRef)break;
            if(preg_match('/(?:ID\s*END\s*TO\s*END|END\s*TO\s*END|E2E\s*ID|ID\s*PIX)\s*[:#\-]?\s*([EA-Z0-9]{20,80})/iu',$line,$m)
                && self::isPixE2E($m[1])){
                $bankRef=self::bankReference($m[1]);$bankRefType='pix_e2e';break;
            }
        }
        if(!$bankRef){
            foreach($lines as $line){
                if(preg_match('/(?:ID\s*(?:DA\s*)?(?:TRANSA[ÇC][ÃA]O|OPERA[ÇC][ÃA]O)|AUTENTICA[ÇC][ÃA]O(?:\s*BANC[ÁA]RIA)?|C[ÓO]DIGO\s*(?:DA\s*)?(?:TRANSA[ÇC][ÃA]O|AUTENTICA[ÇC][ÃA]O)|REFER[ÊE]NCIA\s*BANC[ÁA]RIA)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9.\/-]{5,80})/iu',$line,$m)){
                    $bankRef=self::bankReference($m[1]);
                    if($bankRef){$bankRefType='bank_reference';break;}
                }
            }
        }
        $isFiscal=(bool)($key || preg_match('/NFC-?E|NOTA FISCAL|CUPOM FISCAL|DANFE/iu',$text));
        $description=$issuer?($isFiscal?'Compra em '.$issuer:'Comprovante de '.$issuer):null;
        $warnings=[];
        if($amount===null)$warnings[]='Não foi possível confirmar o valor total; informe-o manualmente.';
        if(!$issueDate)$warnings[]='Data não encontrada com segurança; confira no comprovante.';
        if(!$key && !$bankRef && !$number)$warnings[]='Sem código fiscal ou bancário confirmado: será usado identificador interno do arquivo.';
        // OCR in browsers does not classify accounting categories reliably.
        // Never silently choose a finance category or beneficiary.
        $warnings[]='Leitura automática local: confira valor, data, emitente e natureza antes de registrar.';
        return [
            'access_key'=>$key,'document_number'=>$key?:($bankRef?:self::cleanDocumentNumber((string)$number)),
            'bank_reference'=>$bankRef,'bank_reference_type'=>$bankRefType,
            'short_number'=>$number,'issuer'=>$issuer,'issuer_tax_id'=>$cnpj,
            'date'=>$issueDate,'amount'=>$amount,'payment_method'=>$payment,
            'description'=>$description,'document_type'=>$key?'invoice':($isFiscal?'fiscal_coupon':'proof'),
            'category'=>null,'bank_name'=>null,'warnings'=>$warnings,
            'confidence'=>($key&&$amount!==null?'high':($amount!==null||$cnpj||$bankRef?'medium':'low'))
        ];
    }

    public static function money(string $input): ?string
    {
        $input=preg_replace('/[^\d.,]/','',$input)??'';
        if($input==='')return null;
        if(str_contains($input,',')){
            $input=str_replace('.','',$input);
            $input=str_replace(',','.',$input);
        }elseif(substr_count($input,'.')>1){
            $last=strrpos($input,'.');
            $input=str_replace('.','',substr($input,0,$last)).substr($input,$last);
        }
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$input))return null;
        return number_format((float)$input,2,'.','');
    }

    public static function duplicates(PDO $db,string $cid,array $fields): array
    {
        $number=self::cleanDocumentNumber((string)($fields['document_number']??''));
        $amount=self::money((string)($fields['amount']??''));
        $date=(string)($fields['date']??'');
        $issuer=trim((string)($fields['issuer']??''));
        $fileHash=strtolower(trim((string)($fields['file_hash']??'')));
        if(!$number && $fileHash) $number=self::fileReference($fileHash);
        $strongId=$number!==null && (self::isPixE2E($number)
            ||self::isFileReference($number)
            ||($number===self::key44($number)));
        $hits=[];
        // File SHA-256 prevents reusing the same attachment with a renamed filename.
        if(preg_match('/^[a-f0-9]{64}$/D',$fileHash)){
            $q=$db->prepare('SELECT d.entry_id,d.id,d.original_name,e.transaction_date,e.amount FROM finance_documents d
                LEFT JOIN finance_entries e ON e.id=d.entry_id AND e.church_id=d.church_id
                WHERE d.church_id=? AND d.file_sha256=? LIMIT 3');
            $q->execute([$cid,$fileHash]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $hits[]=[
                'reason'=>'Mesmo arquivo já anexado','entry_id'=>$r['entry_id'],
                'document_id'=>$r['id'],'blocking'=>true];
        }
        if($number){
            $q=$db->prepare('SELECT e.id,e.transaction_date,e.amount,e.counterparty,e.document_number,
                d.document_number AS attached_number,d.id AS document_id
                FROM finance_entries e
                LEFT JOIN finance_documents d ON d.entry_id=e.id AND d.church_id=e.church_id
                WHERE e.church_id=? AND (e.document_number=? OR d.document_number=?)
                ORDER BY e.created_at DESC LIMIT 12');
            $q->execute([$cid,$number,$number]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
                $sameIssuer=$issuer!=='' && mb_strtolower(trim((string)$r['counterparty']),'UTF-8')===mb_strtolower($issuer,'UTF-8');
                $sameDate=$date!=='' && $date===(string)$r['transaction_date'];
                $sameAmount=$amount!==null && abs((float)$amount-(float)$r['amount'])<0.005;
                // A short receipt number is not globally unique. For bank
                // authentication codes, issuer+amount OR date+amount is a
                // sufficient contextual match, otherwise warn for review.
                $bankLike=(string)($fields['reference_type']??'')!==''
                    ||(strlen($number)>=10 && !ctype_digit($number));
                $contextMatch=$sameIssuer&&$sameDate&&$sameAmount;
                $priorIssuer=trim((string)$r['counterparty']);
                $bothIssuersKnown=$issuer!=='' && $priorIssuer!=='';
                $bankContext=$bankLike && strlen($number)>=10 && $sameAmount
                    && ($sameIssuer || (!$bothIssuersKnown && $sameDate));
                $blocking=$strongId||$contextMatch||$bankContext;
                $reason=match(true){
                    self::isPixE2E($number)=>'Mesmo EndToEnd ID Pix (transação já lançada)',
                    self::isFileReference($number)=>'Mesmo identificador de arquivo já registrado',
                    $number===self::key44($number)=>'Mesma chave de acesso fiscal',
                    $bankContext=>'Mesma referência bancária e dados de pagamento',
                    $contextMatch=>'Mesmo número, fornecedor, data e valor',
                    default=>'Referência de documento já utilizada: confira antes de registrar'
                };
                $hits[]=['reason'=>$reason,
                    'entry_id'=>$r['id'],'document_id'=>$r['document_id'],
                    'blocking'=>$blocking];
            }
        }
        // Only strong collisions block. Short coupon numbers can repeat across sellers.
        $unique=[];$seen=[];
        foreach($hits as $hit){
            $key=($hit['entry_id']??'').':'.($hit['document_id']??'').':'.$hit['reason'];
            if(isset($seen[$key]))continue;$seen[$key]=true;$unique[]=$hit;
        }
        return array_slice($unique,0,8);
    }
}
