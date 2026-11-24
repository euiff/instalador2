<?php
declare(strict_types=1);

/**
 * Visual labels are derived from the SAVED accounting entry (direction and
 * category), never guessed from an uploaded receipt, payer name or OCR.
 * Read-only display layer: no changes to balances or historical records.
 */
final class FinanceEntryPresentation {
    public static function classify(array $entry): array {
        $direction=(string)($entry['direction']??'');
        $category=trim((string)($entry['category']??''));
        $member=trim((string)($entry['member_name']??''));
        $contributor=trim((string)($entry['counterparty']??''));
        if($direction==='expense'){
            return [
                'type'=>'SAÍDA','type_class'=>'expense',
                'subtype'=>$category!==''?$category:'Despesa sem categoria',
                'category_label'=>'Tipo da saída',
                'person_label'=>$member!==''?'Membro favorecido':'Favorecido',
                'person'=>$member!==''?$member:($contributor!==''?$contributor:'Não informado'),
                'member_linked'=>$member!==''
            ];
        }
        $isTithe=preg_match('/\bd[ií]zim(?:os?|ista)\b/iu',$category)===1;
        $isOffering=preg_match('/\bofertas?\b/iu',$category)===1;
        $name=$member!==''?$member:($contributor!==''?$contributor:'Não informado');
        if($isTithe){
            return [
                'type'=>'DÍZIMO','type_class'=>'tithe',
                'subtype'=>$category,'category_label'=>'Categoria contábil',
                'person_label'=>'Dizimista',
                'person'=>$name,'member_linked'=>$member!==''
            ];
        }
        if($isOffering){
            return [
                'type'=>'OFERTA','type_class'=>'offering',
                'subtype'=>$category,'category_label'=>'Categoria contábil',
                'person_label'=>'Contribuinte',
                'person'=>$name,'member_linked'=>$member!==''
            ];
        }
        return [
            'type'=>$direction==='income'?'ENTRADA':'LANÇAMENTO',
            'type_class'=>$direction==='income'?'income':'unknown',
            'subtype'=>$category!==''?$category:'Sem categoria',
            'category_label'=>'Categoria contábil',
            'person_label'=>'Contribuinte',
            'person'=>$name,'member_linked'=>$member!==''
        ];
    }
}
