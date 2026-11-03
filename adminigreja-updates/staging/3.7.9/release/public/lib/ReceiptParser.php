<?php
declare(strict_types=1);

/**
 * Non-authoritative OCR/XML field suggestions.
 * These are drafts ONLY; verify against source document before posting.
 * Does not call third-party OCR or fiscal endpoints.
 */
final class ReceiptParser
{
    private const CATEGORIES=[
        'Alimentação e cozinha'=>['arroz','feijao','cafe','acucar','leite','pao','agua mineral','biscoito','carne','frango','refeicao','lanche','refrigerante','farinha','fruta'],
        'Material de limpeza'=>['detergente','sabao','sabonete','agua sanitaria','desinfetante','amaciante','esponja','vassoura','rodo','limpeza','papel higienico','alcool 70'],
        'Material de escritório'=>['papel a4','caneta','lapis','caderno','envelope','impressao','toner','cartucho','grampeador'],
        'Manutenção e reparos'=>['lampada','tinta','cimento','parafuso','ferramenta','torneira','fio eletrico','eletrica','reparo','manutencao','material construcao'],
        'Combustível e transporte'=>['gasolina','etanol','diesel','combustivel','oleo motor','transporte','passagem','uber','estacionamento'],
        'Material para culto'=>['vela','biblia','hinario','livreto','santa ceia','hostia','copo descartavel'],
        'Equipamentos'=>['microfone','cabo hdmi','caixa de som','projetor','notebook','computador','monitor'],
    ];

    private static function normalize(string $value):string
    {
        $lower=mb_strtolower($value,'UTF-8');
        $trans=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$lower);
        return preg_replace('/\s+/',' ',(string)($trans?:$lower))??'';
    }

    public static function suggest(string $description,array $categoryNames=[]):array
    {
        $text=self::normalize($description);
        $label='Despesas diversas (revisar)';$matched=false;
        foreach(self::CATEGORIES as $category=>$keywords)foreach($keywords as $word){
            if(str_contains($text,self::normalize($word))){$label=$category;$matched=true;break 2;}
        }
        // Respect the church's expense category catalog; never create categories implicitly.
        if($categoryNames){
            foreach($categoryNames as $name){
                if(self::normalize((string)$name)===self::normalize($label))return ['category'=>(string)$name,'confidence'=>$matched?'suggested':'unknown'];
            }
            if($matched){
                foreach($categoryNames as $name){
                    if(str_contains(self::normalize((string)$name),self::normalize($label)))return ['category'=>(string)$name,'confidence'=>'suggested'];
                }
            }
            return ['category'=>'','confidence'=>'review'];
        }
        return ['category'=>$label,'confidence'=>$matched?'suggested':'review'];
    }

    public static function amount(string $raw):?string
    {
        $s=trim(preg_replace('/[^0-9,\.]/','',$raw)??'');
        if($s==='')return null;
        if(str_contains($s,','))$s=str_replace(['.',','],['','.'],$s);
        elseif(substr_count($s,'.')>1)$s=str_replace('.','',$s);
        if(!preg_match('/^\d+(?:\.\d{1,2})?$/D',$s))return null;
        if((float)$s>99999999999)return null;
        return number_format((float)$s,2,'.','');
    }

    private static function validCnpj(string $value):bool
    {
        $n=preg_replace('/\D/','',$value)??'';
        if(strlen($n)!==14||preg_match('/^(\d)\1{13}$/',$n))return false;
        for($j=12;$j<14;$j++){
            $weights=$j===12?[5,4,3,2,9,8,7,6,5,4,3,2]:[6,5,4,3,2,9,8,7,6,5,4,3,2];
            $sum=0;foreach($weights as $i=>$weight)$sum+=(int)$n[$i]*$weight;
            $remainder=$sum%11;$digit=$remainder<2?0:11-$remainder;
            if((int)$n[$j]!==$digit)return false;
        }
        return true;
    }

    public static function parse(string $raw,array $categories=[]):array
    {
        $text=mb_substr(str_replace(["\r\n","\r"],"\n",$raw),0,25000);
        $lines=array_values(array_filter(array_map('trim',preg_split('/\n/u',$text)?:[])));
        $out=['supplier'=>'','cnpj'=>'','number'=>'','issued_on'=>'','total'=>'','access_key'=>'','category'=>'','items'=>[],'warnings'=>[]];
        if(!$lines)return $out;
        foreach(array_slice($lines,0,5) as $line){
            if(!preg_match('/^(DANFE|CNPJ|CPF|DOCUMENTO|NOTA FISCAL|CUPOM|NFC-E|EXTRATO)/iu',$line) && mb_strlen($line)>3 && mb_strlen($line)<110){
                $out['supplier']=$line;break;
            }
        }
        if(preg_match('/\b\d{2}[.\s]?\d{3}[.\s]?\d{3}[\/\s]?\d{4}[-\s]?\d{2}\b/',$text,$m)){
            $cnpj=preg_replace('/\D/','',$m[0]);if(self::validCnpj($cnpj))$out['cnpj']=$cnpj;
        }
        if(preg_match('/(?:chave\s+de\s+acesso\s*[:\s]*)?((?:\d[\s.]*){44})/iu',$text,$m)){
            $key=preg_replace('/\D/','',$m[1]);if(strlen($key)===44)$out['access_key']=$key;
        }
        foreach($lines as $line){
            if(!$out['issued_on'] && preg_match('/\b(\d{2})[\/.-](\d{2})[\/.-](20\d{2})\b/',$line,$m)){
                if(checkdate((int)$m[2],(int)$m[1],(int)$m[3]))$out['issued_on']=$m[3].'-'.$m[2].'-'.$m[1];
            }
            if(!$out['number']&&preg_match('/(?:n[.º°o]?\s*(?:nf|nota|cupom)|numero\s+(?:da\s+)?nota)\s*[:#]?\s*(\d{2,12})/iu',$line,$m))$out['number']=$m[1];
            if(preg_match('/(?:valor\s+(?:total|a\s+pagar)|total\s+(?:a\s+pagar|r\$)|total\s*[:=]|vl\.?\s*total)\s*R?\$?\s*([\d.,]+)/iu',$line,$m)){
                $amount=self::amount($m[1]);if($amount!==null)$out['total']=$amount;
            }
            // Number + text + terminal item price. OCR layouts vary; suggestions only.
            if(count($out['items'])<80 && preg_match('/^\s*(?:\d{1,3}\s+)?([\pL\d][\pL\d .\-\/]{3,65}?)\s+(?:\d+[,.]\d{1,3}\s*[xX]\s*)?(?:R\$\s*)?(\d{1,7}[,.]\d{2})\s*$/u',$line,$m)){
                $name=trim($m[1]);
                if(preg_match('/total|troco|desconto|tribut|pagamento|cartao|subtotal|cnpj|cpf|documento|acrescimo/iu',$name))continue;
                $amount=self::amount($m[2]);if($amount===null)continue;
                $suggest=self::suggest($name,$categories);
                $out['items'][]=['name'=>$name,'amount'=>$amount,'category'=>$suggest['category'],'confidence'=>$suggest['confidence']];
            }
        }
        if($out['total']==='' && count($out['items'])===1)$out['warnings'][]='Total não identificado com segurança; confira no documento.';
        if(!$out['cnpj'])$out['warnings'][]='CNPJ do fornecedor não validado.';
        if(!$out['issued_on'])$out['warnings'][]='Data não identificada.';
        if(!$out['total'])$out['warnings'][]='Total não identificado.';
        $combined=implode(' ',array_column($out['items'],'name'));
        $out['category']=self::suggest($combined?:$out['supplier'],$categories)['category'];
        $out['warnings'][]='Leitura automática não comprova autenticidade fiscal. Conferência obrigatória.';
        return $out;
    }

    public static function parseXml(string $xml,array $categories=[]):array
    {
        if(strlen($xml)>8*1024*1024)throw new InvalidArgumentException('XML excede o limite permitido.');
        $old=libxml_use_internal_errors(true);
        try{
            $doc=new DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;
            if(!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new InvalidArgumentException('XML inválido.');
            $xpath=new DOMXPath($doc);
            $read=static function(string $expr)use($xpath):string{return trim((string)$xpath->evaluate('string('.$expr.')'));};
            $out=['supplier'=>$read('(//*[local-name()="emit"]/*[local-name()="xNome"])[1]'),
                'cnpj'=>$read('(//*[local-name()="emit"]/*[local-name()="CNPJ"])[1]'),
                'number'=>$read('(//*[local-name()="ide"]/*[local-name()="nNF"])[1]'),
                'issued_on'=>'','total'=>$read('(//*[local-name()="ICMSTot"]/*[local-name()="vNF"])[1]'),
                'access_key'=>'','category'=>'','items'=>[],'warnings'=>[]];
            $issued=$read('(//*[local-name()="ide"]/*[local-name()="dhEmi"])[1]');
            if(!$issued)$issued=$read('(//*[local-name()="ide"]/*[local-name()="dEmi"])[1]');
            if(preg_match('/^\d{4}-\d{2}-\d{2}/',$issued,$m))$out['issued_on']=$m[0];
            $key=$read('(//*[local-name()="infNFe"]/@Id)[1]');
            if(preg_match('/NFe(\d{44})/',$key,$m))$out['access_key']=$m[1];
            foreach($xpath->query('//*[local-name()="det"]')?:[] as $detail){
                $name=trim((string)$xpath->evaluate('string(./*[local-name()="prod"]/*[local-name()="xProd"])',$detail));
                $amount=self::amount((string)$xpath->evaluate('string(./*[local-name()="prod"]/*[local-name()="vProd"])',$detail));
                if(!$name||$amount===null)continue;
                $suggest=self::suggest($name,$categories);
                $out['items'][]=['name'=>mb_substr($name,0,150),'amount'=>$amount,'category'=>$suggest['category'],'confidence'=>$suggest['confidence']];
                if(count($out['items'])>=500)break;
            }
            if(!self::validCnpj($out['cnpj'])){$out['cnpj']='';$out['warnings'][]='CNPJ inválido no XML.';}
            $out['total']=self::amount($out['total'])??'';
            $out['category']=self::suggest(implode(' ',array_column($out['items'],'name')),$categories)['category'];
            $out['warnings'][]='XML lido localmente, sem consulta de autenticidade à SEFAZ.';
            return $out;
        }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
}
