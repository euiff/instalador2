<?php
declare(strict_types=1);
require_once __DIR__.'/ReceiptParser.php';
require_once __DIR__.'/FinanceOperations.php';

/**
 * Financial input rules for both general and departmental cashbooks.
 * Old ledger rows are never rewritten. OCR suggestions are saved ONLY after
 * human review and must always be linked to a real uploaded document.
 */
final class TreasurySmartEntry
{
    public const BUILTIN=[
        'Dízimos'=>'income','Ofertas'=>'income','Missões'=>'both',
        'Campanhas'=>'income','Doações'=>'both','Eventos'=>'both',
        'Rifas'=>'income','Ação social'=>'both',
        'Água'=>'expense','Energia'=>'expense','Internet'=>'expense',
        'Aluguel'=>'expense','Material de limpeza'=>'expense',
        'Manutenção'=>'expense','Construção'=>'expense','Som e mídia'=>'expense',
        'Transporte'=>'expense','Alimentação'=>'expense',
        'Material de escritório'=>'expense','Impostos e taxas'=>'expense',
        'Contas a pagar'=>'expense','Outros'=>'both',
        'Alimentação e cozinha'=>'expense','Manutenção e reparos'=>'expense',
        'Combustível e transporte'=>'expense','Material para culto'=>'expense',
        'Equipamentos'=>'expense'
    ];

    private static function normalized(string $v):string
    {
        $lower=mb_strtolower(trim($v),'UTF-8');
        $latin=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$lower);
        return preg_replace('/[^a-z0-9]+/',' ',(string)($latin?:$lower))??'';
    }

    public static function isTithe(string $category):bool
    {
        return in_array(trim(self::normalized($category)),['dizimo','dizimos'],true);
    }

    public static function isOffering(string $category):bool
    {
        return in_array(trim(self::normalized($category)),['oferta','ofertas'],true);
    }

    public static function normalizeDescription(string $direction,string $category,string $description):string
    {
        $category=trim($category);$description=trim($description);
        if($category===''||mb_strlen($category,'UTF-8')>160)
            throw new InvalidArgumentException('Escolha uma categoria válida (até 160 caracteres).');
        if($description===''&&$direction==='income'){
            if(self::isTithe($category))$description='Dízimo';
            elseif(self::isOffering($category))$description='Oferta';
        }
        if($description===''||mb_strlen($description,'UTF-8')>255)
            throw new InvalidArgumentException('Informe a descrição do lançamento. Em Dízimos e Ofertas, ela é preenchida automaticamente.');
        return $description;
    }

    public static function validateMember(string $direction,string $category,?string $memberId):void
    {
        $memberId=trim((string)$memberId);
        if($direction==='income' && self::isTithe($category) && $memberId==='')
            throw new InvalidArgumentException('Selecione o membro contribuinte para registrar um dízimo. A entrada será exibida em seu Portal do Membro.');
        if($direction==='expense'&&$memberId!=='')
            throw new InvalidArgumentException('Membro contribuinte é usado somente nas entradas.');
    }

    public static function amount(string $raw):string
    {
        $raw=trim(preg_replace('/^R\\$\\s*/u','',trim($raw))??'');
        if(!preg_match('/^(?:[0-9]{1,10}(?:,[0-9]{1,2})?|[0-9]{1,3}(?:\\.[0-9]{3}){1,3},[0-9]{1,2}|[0-9]{1,10}(?:\\.[0-9]{1,2})?)$/D',$raw))
            throw new InvalidArgumentException('Formato monetário inválido. Use 125,50.');
        $amount=ReceiptParser::amount($raw);
        if($amount===null||FinanceOperations::cents($amount)<=0||(float)$amount>999999999.99)
            throw new InvalidArgumentException('Informe valor positivo em reais, por exemplo 125,50.');
        return $amount;
    }

    public static function categories(PDO $pdo,string $churchId):array
    {
        $list=[];
        foreach(self::BUILTIN as $name=>$direction)$list[mb_strtolower($name,'UTF-8')]=['name'=>$name,'direction'=>$direction];
        // First-generation records can predate the JSON details column;
        // treat their category as available in both directions.
        $meta=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='church_registers'
              AND COLUMN_NAME='details'");
        $meta->execute();$hasDetails=(int)$meta->fetchColumn()>0;
        $q=$pdo->prepare("SELECT name,".($hasDetails?'details':'NULL AS details')." FROM church_registers
            WHERE church_id=? AND kind='finance_category' AND active=1 ORDER BY name");
        $q->execute([$churchId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $name=trim((string)$r['name']);
            if($name==='')continue;
            $d=json_decode((string)($r['details']??'{}'),true);
            $direction=(string)($d['direction']??'both');
            if(!in_array($direction,['income','expense','both'],true))$direction='both';
            $list[mb_strtolower($name,'UTF-8')]=['name'=>$name,'direction'=>$direction];
        }
        usort($list,static fn($a,$b)=>strcoll($a['name'],$b['name']));
        return array_values($list);
    }

    public static function expenseCategoryNames(PDO $pdo,string $churchId):array
    {
        return array_values(array_map(static fn($v)=>$v['name'],
           array_filter(self::categories($pdo,$churchId),
               static fn($v)=>$v['direction']!=='income')));
    }

    /**
     * Browser-side OCR data must be explicitly reviewed; never trust the
     * model's amounts or categories as fiscal evidence.
     */
    public static function reviewedItems(array $input):array
    {
        $raw=(string)($input['ocr_items_json']??'');
        if(trim($raw)==='')return [];
        if(strlen($raw)>35000)throw new InvalidArgumentException('Itens do documento excedem o limite.');
        if((string)($input['ocr_confirmed']??'')!=='1')
            throw new InvalidArgumentException('Confirme que revisou os itens e valores do cupom antes de salvar.');
        $parsed=json_decode($raw,true);
        if(!is_array($parsed)||!array_is_list($parsed)||count($parsed)>80)
            throw new InvalidArgumentException('Lista de itens do documento inválida.');
        $items=[];
        foreach($parsed as $row){
            if(!is_array($row))throw new InvalidArgumentException('Item do comprovante inválido.');
            $name=trim((string)($row['name']??''));
            $category=trim((string)($row['category']??''));
            if($name===''||mb_strlen($name,'UTF-8')>160)
                throw new InvalidArgumentException('Revise os nomes dos produtos da nota (até 160 caracteres).');
            if(mb_strlen($category,'UTF-8')>160)
                throw new InvalidArgumentException('Categoria de produto muito longa.');
            $amount=self::amount((string)($row['amount']??''));
            $items[]=['name'=>$name,'category'=>$category?:'A classificar',
               'amount'=>$amount,'confidence'=>'reviewed_ocr'];
        }
        return $items;
    }

    public static function ensureSchema(PDO $pdo):void
    {
        // The update must also handle early installations where department
        // entries predated personal contributors and registry references.
        // DDL happens before any financial transaction is opened.
        $columns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance_entries'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $existing=array_fill_keys(array_map('strval',$columns),true);
        foreach(['member_id','cost_center_id','contact_id'] as $column){
            if(!isset($existing[$column])){
                $pdo->exec('ALTER TABLE finance_entries ADD COLUMN '.$column.' CHAR(36) NULL');
            }
        }
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance_entry_items'");
        if((int)$q->fetchColumn()!==0)return;
        $script=file_get_contents(dirname(__DIR__).'/database/047_finance_entry_items.sql');
        if(!is_string($script))throw new RuntimeException('Migração de itens financeiros indisponível.');
        $pdo->exec($script);
    }

    public static function saveItems(
        PDO $pdo,string $churchId,string $entryId,string $documentId,
        string $userId,array $items
    ):void {
        if(!$items)return;
        if(!$documentId)throw new InvalidArgumentException('O comprovante original é obrigatório para salvar itens lidos automaticamente.');
        $query=$pdo->prepare('INSERT INTO finance_entry_items
           (id,church_id,entry_id,finance_document_id,description,amount,category,created_by_user_id)
           VALUES(?,?,?,?,?,?,?,?)');
        foreach($items as $item){
            $query->execute([app_uuid(),$churchId,$entryId,$documentId,$item['name'],
                $item['amount'],$item['category'],$userId]);
        }
    }

    public static function validReference(PDO $pdo,string $churchId,string $kind,?string $id):?string
    {
        $id=trim((string)$id);
        if($id==='')return null;
        if(!in_array($kind,['contact','cost_center'],true))throw new InvalidArgumentException('Cadastro inválido.');
        $q=$pdo->prepare('SELECT id FROM church_registers WHERE church_id=? AND id=? AND kind=? AND active=1');
        $q->execute([$churchId,$id,$kind]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Contato ou centro de custos não pertence a esta igreja.');
        return $id;
    }

    public static function validMember(PDO $pdo,string $churchId,?string $id):?string
    {
        $id=trim((string)$id);
        if($id==='')return null;
        $q=$pdo->prepare('SELECT id FROM members WHERE church_id=? AND id=? AND active=1');
        $q->execute([$churchId,$id]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('O membro não pertence a esta igreja ou está inativo.');
        return $id;
    }
}
