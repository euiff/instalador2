<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceVisionConfig.php';
require_once __DIR__.'/FinanceFiscalScan.php';

/** Gemini multimodal image/PDF reader for Brazilian fiscal receipts. */
final class FinanceVisionReader
{
    public static function extract(array $file,array $categories=[],string $mode='fast'): array
    {
        $key=FinanceVisionConfig::key();
        if($key==='')throw new RuntimeException('A leitura com IA precisa ser ativada no painel Master → Leitura Fiscal com IA. Até lá, preencha manualmente.');
        if(!function_exists('curl_init'))throw new RuntimeException('A extensão cURL precisa estar ativada no PHP da hospedagem.');
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)throw new RuntimeException('O comprovante não chegou ao leitor. Escolha a foto novamente.');
        $tmp=(string)($file['tmp_name']??'');
        if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Arquivo inválido para análise. Selecione uma foto ou PDF do celular.');
        $bytesize=(int)($file['size']??0);
        if($bytesize<=0 || $bytesize>10*1024*1024)
            throw new RuntimeException('Para leitura por IA, escolha foto/PDF de até 10 MB. O anexo original pode ter até 12 MB.');
        $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if(!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true))
            throw new RuntimeException('A leitura por IA aceita JPG, PNG, WEBP e PDF. Converta fotos HEIC para JPG antes de analisar.');
        $bytes=@file_get_contents($tmp);
        if($bytes===false||strlen($bytes)!==$bytesize)
            throw new RuntimeException('Não foi possível ler o arquivo na hospedagem.');

        // Only pre-existing tenant categories are presented to the model.
        $names=[];
        foreach($categories as $item){
            $label=trim((string)($item['name']??''));
            if($label!=='')$names[]=mb_substr($label,0,140);
            if(count($names)>=70)break;
        }
        $model=FinanceVisionConfig::model($mode);
        $prompt=<<<PROMPT
Você é um extrator especializado em DOCUMENTOS FISCAIS BRASILEIROS (NFC-e, NF-e, SAT, cupons térmicos, DANFE e comprovantes).
Leia VISUALMENTE a imagem/PDF fornecida; texto pequeno pode estar em qualquer parte da foto. Retorne APENAS o objeto JSON estruturado.
Campos solicitados:
- document_type: invoice (NF-e), fiscal_coupon (NFC-e/cupom), receipt (recibo) ou proof (comprovante bancário).
- issuer: NOME DO ESTABELECIMENTO que emitiu o documento; nunca confunda com nome do comprador.
- issuer_tax_id: CNPJ do estabelecimento, somente se visível.
- access_key: chave fiscal de 44 dígitos SOMENTE se os 44 dígitos estiverem legíveis. NÃO confunda com QR code, código Pix ou números de protocolo.
- short_number: número da nota, NFC-e ou cupom; não confunda série, número do caixa, pedido, CNPJ e chave de acesso.
- issue_date: data completa de emissão da nota no formato AAAA-MM-DD.
- total_amount: valor FINAL da compra, após descontos, em reais, com ponto decimal (por exemplo 45.90). Não usar troco, tributos, desconto, valor pago parcial ou preço unitário.
- payment_method: PIX, Dinheiro, Cartão de crédito, Cartão de débito, Transferência, Boleto, ou null.
- description: descrição curta e fiel da despesa (resumir itens que puder LER, sem adicionar itens inventados), 150 caracteres no máximo.
- category: somente uma das categorias contábeis pré-existentes da lista abaixo, SE for possível escolher sem ambiguidade, senão null.
- confidence: high, medium, low (qualidade GLOBAL da leitura).
- warning: resumo curto das dúvidas de leitura, ou null.
CATEGORIAS QUE EXISTEM NESTA IGREJA: __CATEGORIES__

REGRAS CRÍTICAS:
1. Nunca invente, calcule ou complete caracteres parcialmente ilegíveis, nem use dados de outro documento.
2. Se um campo não estiver legível/comprovado, devolva null; não adivinhe valores por contexto.
3. Capture a data impressa no documento, não a data de hoje.
4. Se houver mais de um TOTAL, priorize VALOR TOTAL DA NOTA / TOTAL A PAGAR depois de descontos, não troco.
5. CPF/CNPJ do cliente não é CNPJ do emitente.
6. Nunca classifique doação/dízimo como compra sem evidência.
7. A lista de categorias não autoriza inventar a categoria; null se não houver correspondência segura.
PROMPT;
        $prompt=str_replace('__CATEGORIES__',json_encode($names,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),$prompt);
        $properties=[];
        foreach(['document_type','issuer','issuer_tax_id','access_key','short_number','issue_date',
                  'total_amount','payment_method','description','category','confidence','warning'] as $name){
            $properties[$name]=['type'=>['string','null']];
        }
        $schema=['type'=>'object','properties'=>$properties,'required'=>array_keys($properties)];
        $payload=[
            'contents'=>[['role'=>'user','parts'=>[
                ['inline_data'=>['mime_type'=>$mime,'data'=>base64_encode($bytes)]],
                ['text'=>$prompt],
            ]]],
            'generationConfig'=>[
                'responseMimeType'=>'application/json',
                'responseJsonSchema'=>$schema,
                'temperature'=>0,
                'thinkingConfig'=>['thinkingLevel'=>$mode==='precise'?'medium':'low'],
                'maxOutputTokens'=>4096
            ]
        ];
        unset($bytes);
        $json=json_encode($payload,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        unset($payload);
        $curl=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent');
        if($curl===false)throw new RuntimeException('Não foi possível iniciar a conexão com a IA.');
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$json,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>7,
            CURLOPT_TIMEOUT=>$mode==='precise'?40:28,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_ENCODING=>''
        ]);
        $response=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        $transportError=curl_error($curl);
        curl_close($curl);
        unset($key,$json);
        if($response===false){
            error_log('Finance Vision provider transport error: '.$transportError);
            throw new RuntimeException('A análise da foto não respondeu. Verifique a internet e tente novamente, ou use o preenchimento manual.');
        }
        if($status!==200){
            error_log('Finance Vision provider HTTP '.$status.' model='.$model);
            $message=match(true){
                in_array($status,[401,403],true)=>'A chave da IA não foi aceita. O Master precisa revisar a configuração.',
                $status===429=>'Limite de leituras da API atingido. Aguarde ou verifique sua cota no Google AI Studio.',
                in_array($status,[500,502,503,504],true)=>'Serviço de IA temporariamente indisponível. Tente novamente.',
                $status===400=>'O modelo de IA ou o arquivo não foi aceito pela API. Verifique a configuração no Master.',
                default=>'A leitura pela IA não foi concluída (HTTP '.$status.').'
            };
            throw new RuntimeException($message);
        }
        $decoded=json_decode((string)$response,true);
        $answer='';
        foreach(($decoded['candidates'][0]['content']['parts']??[]) as $part){
            if(isset($part['text'])&&is_string($part['text']))$answer.=$part['text'];
        }
        $source=json_decode($answer,true);
        if(!is_array($source))throw new RuntimeException('A IA não devolveu os dados estruturados. Tente a leitura detalhada ou preencha manualmente.');
        return self::normalize($source,$names);
    }

    public static function normalize(array $data,array $categories=[]): array
    {
        $get=static function(string $name) use($data): ?string {
            $v=$data[$name]??null;
            return is_string($v)||is_numeric($v)?(trim((string)$v)!==''?trim((string)$v):null):null;
        };
        $warnings=[];
        $accessInput=$get('access_key');
        $key=$accessInput?FinanceFiscalScan::key44($accessInput):null;
        if($accessInput && !$key)$warnings[]='Chave fiscal não validada; confira no documento.';

        $documentType=$get('document_type');
        if(!in_array($documentType,['invoice','fiscal_coupon','receipt','proof'],true))$documentType='proof';

        $cnpj=$get('issuer_tax_id');
        $cnpj=$cnpj?preg_replace('/\D/','',$cnpj):null;
        if($cnpj && !self::validCnpj($cnpj)){
            $warnings[]='CNPJ não passou na validação; verifique a foto.';
            $cnpj=null;
        }
        if(!$cnpj && $key)$cnpj=substr($key,6,14);

        $date=$get('issue_date');
        if($date){
            $parts=explode('-',$date);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)
                || !checkdate((int)($parts[1]??0),(int)($parts[2]??0),(int)($parts[0]??0))
                || (int)$parts[0]<2000||(int)$parts[0]>(int)date('Y')+1){
                $date=null;
                $warnings[]='A data não estava legível com segurança.';
            }
        }
        $money=$get('total_amount');
        $moneyClean=$money!==null?preg_replace('/^R\$\s*/u','',$money):null;
        // Do not silently strip letters or extra symbols from model-generated sums.
        $validMoney=$moneyClean!==null && preg_match(
            '/^(?:\d{1,10}(?:[.,]\d{1,2})?|\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?)$/D',
            $moneyClean
        );
        $amount=$validMoney?FinanceFiscalScan::money($moneyClean):null;
        if($money!==null && ($amount===null||(float)$amount<=0)){
            $amount=null;$warnings[]='Valor total não identificado com segurança.';
        }
        $number=$key?:FinanceFiscalScan::cleanDocumentNumber($get('short_number')??'');
        $issuer=$get('issuer');
        if($issuer)$issuer=mb_substr(preg_replace('/\s+/u',' ',$issuer)??$issuer,0,190);
        $description=$get('description');
        if($description)$description=mb_substr(preg_replace('/\s+/u',' ',$description)??$description,0,150);
        if(!$description && $issuer)$description='Compra em '.$issuer;
        $method=$get('payment_method');
        if($method){
            $method=mb_strtolower($method,'UTF-8');
            $method=match(true){
                str_contains($method,'pix')=>'PIX',
                str_contains($method,'crédito')||str_contains($method,'credito')=>'Cartão de crédito',
                str_contains($method,'débito')||str_contains($method,'debito')=>'Cartão de débito',
                str_contains($method,'dinheiro')=>'Dinheiro',
                str_contains($method,'transfer')=>'Transferência',
                str_contains($method,'boleto')=>'Boleto',
                default=>null
            };
        }
        $category=null;
        foreach($categories as $allowed){
            if($get('category') && mb_strtolower($get('category'),'UTF-8')===mb_strtolower($allowed,'UTF-8')){
                $category=$allowed;break;
            }
        }
        $confidence=$get('confidence');
        if(!in_array($confidence,['high','medium','low'],true))$confidence='low';
        if($amount===null || (!$issuer && !$key))$confidence='low';
        if($get('warning'))$warnings[]=mb_substr($get('warning'),0,180);
        return [
            'access_key'=>$key,'short_number'=>($get('short_number')?:null),
            'document_number'=>$number,'issuer'=>$issuer,'issuer_tax_id'=>$cnpj,
            'date'=>$date,'amount'=>$amount,'payment_method'=>$method,
            'description'=>$description,'category'=>$category,
            'document_type'=>$documentType,
            'confidence'=>$confidence,
            'warnings'=>$warnings
        ];
    }

    public static function validCnpj(string $digits): bool
    {
        if(!preg_match('/^\d{14}$/D',$digits)||preg_match('/^(\d)\1{13}$/',$digits))return false;
        foreach([12,13] as $len){
            $weights=$len===12?[5,4,3,2,9,8,7,6,5,4,3,2]:[6,5,4,3,2,9,8,7,6,5,4,3,2];
            $sum=0;for($i=0;$i<$len;$i++)$sum+=(int)$digits[$i]*$weights[$i];
            $check=($sum%11<2)?0:11-($sum%11);
            if($check!==(int)$digits[$len])return false;
        }
        return true;
    }
}
