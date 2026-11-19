<?php
declare(strict_types=1);

/**
 * Identify the church in a bank receipt without trusting a client-supplied
 * church name. Applies ONLY to its current authenticated tenant.
 *
 * Suggestions never create finance entries: the pastor/treasurer must
 * review the information and explicitly submit the financial form.
 */
final class FinanceChurchPartyDirection
{
    private const GENERIC = [
        'igreja','igrejas','de','do','da','dos','das','e','no','na','em','o','a',
        'brasil','brasileira','brasileiro','associacao','religiosa',
        'ministerio','comunidade','congregacao','templo','instituicao',
        'ltda','me','sa','s','a','go','mg','sp','df','rj','pr','sc','ba'
    ];

    private static function words(?string $value): array
    {
        $value=trim((string)$value);
        if($value==='')return [];
        $value=mb_strtolower($value,'UTF-8');
        $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value) ?: $value;
        $value=strtolower($value);
        // Preserve only whole meaningful words. This prevents "Campo Belo"
        // matching unrelated names simply because both contain "igreja".
        $words=preg_split('/[^a-z0-9]+/',preg_replace('/\b(campo\s+belo)\b/i','$1',$value)??$value) ?: [];
        $words=array_values(array_filter($words,static function(string $word):bool {
            return strlen($word)>=3 && !in_array($word,self::GENERIC,true)
                && !ctype_digit($word);
        }));
        return array_values(array_unique($words));
    }

    public static function matches(?string $churchName,?string $party): bool
    {
        $name=self::words($churchName);
        $other=self::words($party);
        if(count($name)<2||count($other)<2)return false;
        $hits=count(array_intersect($name,$other));
        // Every distinctive part is necessary for a two/three-part church
        // name: "Igreja de Deus" alone must not be enough to identify
        // "Igreja de Deus - Campo Belo" (a separate congregation).
        if(count($name)<=3)return $hits===count($name);
        return $hits>=max(3,(int)ceil(count($name)*.8));
    }

    /**
     * Returns a suggestion + explanation, not an authorization to post.
     * $fields comes from OCR/optional model; the $churchName is read from
     * Auth::currentChurch(), never from the visitor's request.
     */
    public static function apply(array $fields,string $churchName): array
    {
        if(($fields['document_type']??null)!=='proof')return $fields;
        $payer=trim((string)($fields['payer']??''));
        $receiver=trim((string)($fields['receiver']??''));
        if($payer===''&&$receiver==='')return $fields;
        $payerMatch=self::matches($churchName,$payer);
        $receiverMatch=self::matches($churchName,$receiver);
        $direction=$fields['direction']??null;
        $warnings=is_array($fields['warnings']??null)?$fields['warnings']:[];
        $fields['direction_review_required']=true;
        if($payerMatch && !$receiverMatch){
            $fields['direction']='expense';
            $fields['suggested_direction']='expense';
            $fields['direction_source']='church_is_payer';
            $fields['direction_reason']='A igreja deste lançamento foi identificada como PAGADORA no comprovante. Sugerimos SAÍDA.';
            $fields['issuer']=$receiver?:($fields['issuer']??null);
            if($direction==='income'){
                $warnings[]='O comprovante parecia indicar entrada, mas a igreja aparece como pagadora. Confira antes de salvar como saída.';
            }
        }elseif($receiverMatch && !$payerMatch){
            $fields['direction']='income';
            $fields['suggested_direction']='income';
            $fields['direction_source']='church_is_receiver';
            $fields['direction_reason']='A igreja deste lançamento foi identificada como RECEBEDORA no comprovante. Sugerimos ENTRADA.';
            $fields['issuer']=$payer?:($fields['issuer']??null);
            if($direction==='expense'){
                $warnings[]='O comprovante parecia indicar saída, mas a igreja aparece como recebedora. Confira antes de salvar como entrada.';
            }
        }elseif($payerMatch&&$receiverMatch){
            // Transfers between accounts belonging to the same church should
            // not inflate both income and expenses: ask for manual review.
            $fields['direction']=null;
            $fields['suggested_direction']=null;
            $fields['direction_source']='both_parties_church';
            $fields['direction_reason']='Pagador e recebedor correspondem à igreja. Pode ser transferência entre contas próprias. Confira antes de escolher Entrada ou Saída.';
            $warnings[]='Possível transferência interna: não contabilize duas vezes a movimentação.';
        }else{
            // Generic names/partial OCR are NOT proof of church identity.
            $fields['suggested_direction']=null;
            $fields['direction_source']='party_unconfirmed';
            $fields['direction_reason']='Não foi possível confirmar se a igreja é pagadora ou recebedora. Confira os nomes e selecione o tipo manualmente.';
            $warnings[]='Não foi possível vincular com segurança as partes do comprovante à igreja selecionada.';
        }
        $fields['warnings']=array_values(array_unique($warnings));
        return $fields;
    }
}
