<?php
declare(strict_types=1);
require_once __DIR__.'/GroupedFinanceStage.php';
require_once __DIR__.'/FinanceFiscalScan.php';

/**
 * Extra documents are supporting evidence for ONE existing financial entry.
 * They never create ledger amounts, extra entries, or trigger OCR.
 * Uploads arrive individually through the tenant/user-specific protected stage.
 */
final class FinanceManualAttachments
{
    private GroupedFinanceStage $stage;
    public function __construct(private PDO $db,private string $root)
    {
        $this->stage=new GroupedFinanceStage($db,$root);
    }

    public function prepare(string $cid,string $actor,string $json,?array $primaryFile=[]): array
    {
        if(trim($json)==='')return [];
        try{$tokens=json_decode($json,true,8,JSON_THROW_ON_ERROR);}
        catch(JsonException){throw new InvalidArgumentException('A lista de documentos extras está inválida. Selecione os anexos novamente.');}
        if(!is_array($tokens)||!array_is_list($tokens)||count($tokens)>8)
            throw new InvalidArgumentException('Envie até 8 documentos adicionais por lançamento.');
        $prepared=[];$seen=[];
        if((int)($primaryFile['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK
            && is_file((string)($primaryFile['tmp_name']??''))){
            $hash=hash_file('sha256',(string)$primaryFile['tmp_name']);
            if($hash!==false)$seen[$hash]=true;
        }
        foreach($tokens as $i=>$token){
            if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))
                throw new InvalidArgumentException('Anexo '.($i+1).': identificador inválido.');
            $meta=$this->stage->lookup($cid,$actor,$token);
            $hash=(string)$meta['file_sha256'];
            if(isset($seen[$hash]))
                throw new InvalidArgumentException('O mesmo arquivo aparece duas vezes neste lançamento. Retire a cópia repetida.');
            $seen[$hash]=true;
            $q=$this->db->prepare('SELECT entry_id FROM finance_documents WHERE church_id=? AND file_sha256=? LIMIT 1');
            $q->execute([$cid,$hash]);
            if($q->fetchColumn()!==false)
                throw new InvalidArgumentException('O documento adicional '.($i+1).' já está anexado a um lançamento desta igreja.');
            $prepared[]=$meta;
        }
        return $prepared;
    }

    public function insert(string $cid,string $entryId,string $actor,array $prepared,string $type='proof'): int
    {
        if(!in_array($type,['invoice','fiscal_coupon','receipt','proof','contract','other'],true))$type='proof';
        if(!$prepared)return 0;
        $q=$this->db->prepare('SELECT id FROM finance_entries WHERE church_id=? AND id=? LIMIT 1');
        $q->execute([$cid,$entryId]);
        if(!$q->fetchColumn())throw new RuntimeException('Lançamento não encontrado na igreja.');
        $insert=$this->db->prepare('INSERT INTO finance_documents
          (id,church_id,entry_id,payable_id,document_type,document_number,issued_at,
           original_name,storage_path,mime_type,file_size,notes,created_by_user_id,file_sha256,fiscal_access_key)
           VALUES(?,?,?,NULL,?,NULL,NULL,?,?,?,?,?,?,?,NULL)');
        foreach($prepared as $i=>$meta){
            // The exact same hash can be rejected if another request committed
            // after the form's preflight check. A per-church financial lock must
            // be held by the caller before writing ledger and supporting files.
            $q=$this->db->prepare('SELECT id FROM finance_documents WHERE church_id=? AND file_sha256=? LIMIT 1');
            $q->execute([$cid,(string)$meta['file_sha256']]);
            if($q->fetchColumn())
                throw new InvalidArgumentException('Documento adicional já arquivado. Nenhuma movimentação foi duplicada.');
            $insert->execute([
                app_uuid(),$cid,$entryId,$type,
                $meta['original_name'],$meta['path'],$meta['mime'],(int)$meta['size'],
                'Anexo adicional manual '.($i+1).' (sem leitura por IA)',
                $actor,$meta['file_sha256']
            ]);
        }
        return count($prepared);
    }

    public function consume(array $prepared): void
    {
        foreach($prepared as $meta){
            $this->stage->consume((string)$meta['token_path']);
        }
    }
}
