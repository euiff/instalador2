<?php
declare(strict_types=1);
require_once __DIR__.'/ExpenseReceiptPdf.php';
require_once __DIR__.'/DepartmentRoleAccess.php';
require_once __DIR__.'/UploadService.php';

/**
 * Expense receipts are distinct from inbound offering/tithe receipts.
 * An unsigned printable form is NOT a concluded supporting document.
 */
final class ExpenseReceiptService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $churchId,private string $userId)
    {
        $church=$auth->currentChurch();
        if(!$church||$church['id']!==$churchId||$userId===''||$churchId==='')
            throw new RuntimeException('Igreja não autorizada.');
    }

    public static function ensureSchema(PDO $pdo):void
    {
        $path=dirname(__DIR__).'/database/045_expense_receipts.sql';
        if(!is_file($path))throw new RuntimeException('Migração 045 indisponível.');
        $sql=file_get_contents($path);
        if(!is_string($sql))throw new RuntimeException('Falha ao preparar cadastro de recibos.');
        $pdo->exec($sql);
    }

    private function q(string $sql,array $args=[]):PDOStatement
    {
        $stmt=$this->pdo->prepare($sql);$stmt->execute($args);return $stmt;
    }

    private function isManager():bool
    {
        return $this->auth->can('finance');
    }

    private function authorized(array $entry,bool $write):void
    {
        if((string)$entry['church_id']!==$this->churchId)throw new RuntimeException('Lançamento de outra igreja.');
        if($write&&$entry['status']!=='posted')throw new RuntimeException('Não é possível emitir/assinar recibos de lançamentos estornados.');
        if($this->isManager())return;
        if(!$write&&$this->auth->can('accounting_portal'))return;
        $dept=(string)($entry['department_id']??'');
        if($dept!=='' && $this->auth->can('department_treasury') &&
           DepartmentRoleAccess::hasFinancialScope($this->pdo,$this->churchId,$this->userId,$dept))return;
        throw new RuntimeException('Você só pode acessar recibos dos caixas autorizados de seu departamento.');
    }

    public function entry(string $id,bool $write=false):array
    {
        if(!preg_match('/^[0-9a-fA-F-]{36}$/D',$id))throw new InvalidArgumentException('Lançamento inválido.');
        $row=$this->q('SELECT e.*,c.name church_name,d.name department_name,a.name account_name
            FROM finance_entries e
            JOIN churches c ON c.id=e.church_id
            LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id
            LEFT JOIN ministry_departments d ON d.id=COALESCE(e.department_id,a.department_id) AND d.church_id=e.church_id
            WHERE e.id=? AND e.church_id=? LIMIT 1',[$id,$this->churchId])->fetch(PDO::FETCH_ASSOC);
        if(!$row || $row['direction']!=='expense')throw new RuntimeException('Despesa não encontrada nesta igreja.');
        $this->authorized($row,$write);
        if(($row['department_id']??null)===null && ($row['account_id']??null)!==null){
            $sub=$this->q('SELECT department_id FROM finance_accounts WHERE id=? AND church_id=?',
                [$row['account_id'],$this->churchId])->fetchColumn();
            $row['department_id']=$sub?:null;
        }
        return $row;
    }

    public function receipt(string $entryId):?array
    {
        $this->entry($entryId);
        $row=$this->q('SELECT * FROM expense_receipts WHERE church_id=? AND entry_id=?',
            [$this->churchId,$entryId])->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    private static function text(mixed $value,int $limit):string
    {
        $value=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',(string)$value)??'');
        return mb_substr(preg_replace('/\s+/u',' ',$value)??$value,0,$limit);
    }

    public static function validateInput(array $input):array
    {
        $name=self::text($input['recipient_name']??'',190);
        $id=self::text($input['recipient_document']??'',32);
        $mode=(string)($input['proof_method']??'receipt_print');
        if(!in_array($mode,['receipt_screen','receipt_print'],true))
            throw new InvalidArgumentException('Selecione assinatura na tela ou recibo para impressão.');
        if(mb_strlen($name)<3)throw new InvalidArgumentException('Informe o nome de quem recebeu o pagamento.');
        if($id!==''&&!preg_match('/^[0-9.\/\- ]{5,32}$/D',$id))
            throw new InvalidArgumentException('CPF/CNPJ informado em formato inválido.');
        $signed=$mode==='receipt_screen';
        if($signed && empty($input['signature_consent']))
            throw new InvalidArgumentException('O recebedor precisa confirmar a assinatura na tela.');
        $jpeg=$signed?self::signature((string)($input['signature_data']??'')):null;
        return ['recipient_name'=>$name,'recipient_document'=>$id?:null,
                'mode'=>$mode,'jpeg'=>$jpeg];
    }

    private static function signature(string $data):string
    {
        if(!str_starts_with($data,'data:image/jpeg;base64,')||strlen($data)>1000000)
            throw new InvalidArgumentException('Peça ao recebedor para assinar no quadro indicado.');
        $jpg=base64_decode(substr($data,strlen('data:image/jpeg;base64,')),true);
        if(!is_string($jpg)||strlen($jpg)<800||strlen($jpg)>500000)
            throw new InvalidArgumentException('Assinatura incompleta ou maior que o permitido.');
        $meta=@getimagesizefromstring($jpg);
        if(!$meta||($meta['mime']??null)!=='image/jpeg'||$meta[0]<150||$meta[0]>1800||
           $meta[1]<70||$meta[1]>900)
            throw new InvalidArgumentException('Imagem da assinatura inválida.');
        return $jpg;
    }

    private function audit(string $action,array $data):void
    {
        $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',
            [app_uuid(),$this->userId,$this->churchId,$action,
                json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }

    private function savePdfFile(string $receiptId,string $bytes):array
    {
        if(strlen($bytes)<700||!str_starts_with($bytes,'%PDF-'))
            throw new RuntimeException('O recibo PDF não foi gerado corretamente.');
        $base=dirname(__DIR__).'/storage/private/finance';
        $dir=$base.'/'.preg_replace('/[^A-Za-z0-9_-]/','',$this->churchId);
        if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))
            throw new RuntimeException('Não foi possível criar pasta de recibos.');
        foreach([$base,$dir] as $folder){
            $deny=$folder.'/.htaccess';
            if(!is_file($deny))@file_put_contents($deny,"Require all denied\nDeny from all\n",LOCK_EX);
            $index=$folder.'/index.php';
            if(!is_file($index))@file_put_contents($index,"<?php http_response_code(403);exit;\n",LOCK_EX);
        }
        $filename='recibo-despesa-'.preg_replace('/[^a-f0-9-]/i','',$receiptId).'.pdf';
        $path=$dir.'/'.$filename;
        if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))
            throw new RuntimeException('Não foi possível salvar o recibo PDF.');
        @chmod($path,0640);
        return ['path'=>'storage/private/finance/'.basename($dir).'/'.$filename,
            'absolute'=>$path,'mime'=>'application/pdf','size'=>strlen($bytes),
            'original_name'=>$filename];
    }

    private function addFinanceDocument(string $entryId,string $receiptId,string $date,array $file,string $note):string
    {
        $docId=app_uuid();
        $this->q(
            'INSERT INTO finance_documents (id,church_id,entry_id,payable_id,document_type,document_number,
                issued_at,original_name,storage_path,mime_type,file_size,notes,created_by_user_id)
             VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,?)',
            [$docId,$this->churchId,$entryId,'receipt',substr($receiptId,0,36),
                $date,$file['original_name'],$file['path'],$file['mime'],$file['size'],$note,$this->userId]
        );
        return $docId;
    }

    private function signExisting(array $receipt,array $entry,string $jpg):string
    {
        $signed=$receipt;
        $signed['status']='signed_screen';
        $signed['signed_at']=date('Y-m-d H:i:s');
        $pdf=ExpenseReceiptPdf::render($signed,$entry,(string)$entry['church_name'],$jpg);
        $file=$this->savePdfFile((string)$receipt['id'],$pdf);
        try{
            $documentId=$this->addFinanceDocument(
                (string)$entry['id'],(string)$receipt['id'],(string)$entry['transaction_date'],$file,
                'Recibo de despesa assinado na tela. Identidade do signatário não verificada automaticamente.'
            );
            $this->q("UPDATE expense_receipts SET status='signed_screen',signature_method='onscreen',
                finance_document_id=?,signed_at=?,signed_by_user_id=?
                WHERE id=? AND church_id=? AND status='pending_signature'",
                [$documentId,$signed['signed_at'],$this->userId,$receipt['id'],$this->churchId]);
            $this->audit('expense_receipt_signed_on_screen',
                ['receipt_id'=>$receipt['id'],'entry_id'=>$entry['id'],'document_id'=>$documentId]);
            return $documentId;
        }catch(Throwable $error){
            @unlink($file['absolute']);
            throw $error;
        }
    }

    public function create(string $entryId,array $input):array
    {
        $data=self::validateInput($input);
        $entry=$this->entry($entryId,true);
        $outer=$this->pdo->inTransaction();
        if(!$outer)$this->pdo->beginTransaction();
        try{
            if($this->q('SELECT id FROM expense_receipts WHERE church_id=? AND entry_id=? LIMIT 1',
               [$this->churchId,$entryId])->fetchColumn())
                throw new InvalidArgumentException('Esta despesa já possui recibo. Abra o recibo existente.');
            $id=app_uuid();
            $this->q('INSERT INTO expense_receipts
                (id,church_id,entry_id,recipient_name,recipient_document,generated_by_user_id)
                VALUES(?,?,?,?,?,?)',
                [$id,$this->churchId,$entryId,$data['recipient_name'],$data['recipient_document'],$this->userId]);
            $receipt=$this->q('SELECT * FROM expense_receipts WHERE id=? AND church_id=?',
                [$id,$this->churchId])->fetch(PDO::FETCH_ASSOC);
            if($data['mode']==='receipt_screen')$this->signExisting($receipt,$entry,$data['jpeg']);
            else $this->audit('expense_receipt_generated_pending',
                ['receipt_id'=>$id,'entry_id'=>$entryId,'status'=>'pending_signature']);
            if(!$outer)$this->pdo->commit();
            return ['id'=>$id,'status'=>$data['mode']==='receipt_screen'?'signed_screen':'pending_signature'];
        }catch(Throwable $e){
            if(!$outer&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function signOnScreen(string $entryId,array $input):void
    {
        $entry=$this->entry($entryId,true);
        if(($input['signature_consent']??'')!=='1')
            throw new InvalidArgumentException('A assinatura precisa ser confirmada pelo recebedor.');
        $name=self::text($input['confirm_recipient_name']??'',190);
        $jpg=self::signature((string)($input['signature_data']??''));
        $this->pdo->beginTransaction();
        try{
            $receipt=$this->q('SELECT * FROM expense_receipts WHERE church_id=? AND entry_id=? FOR UPDATE',
                [$this->churchId,$entryId])->fetch(PDO::FETCH_ASSOC);
            if(!$receipt||$receipt['status']!=='pending_signature')
                throw new RuntimeException('Recibo não encontrado ou já assinado.');
            if($name!==$receipt['recipient_name'])
                throw new InvalidArgumentException('Confirme o nome exato da pessoa que está assinando.');
            $this->signExisting($receipt,$entry,$jpg);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function attachSignedPaper(string $entryId,array $file):void
    {
        $entry=$this->entry($entryId,true);
        $this->pdo->beginTransaction();$saved=null;
        try{
            $receipt=$this->q('SELECT * FROM expense_receipts WHERE church_id=? AND entry_id=? FOR UPDATE',
                [$this->churchId,$entryId])->fetch(PDO::FETCH_ASSOC);
            if(!$receipt||$receipt['status']!=='pending_signature')
                throw new RuntimeException('Não existe recibo pendente desta despesa.');
            if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
                throw new InvalidArgumentException('Anexe uma foto ou PDF do recibo assinado fisicamente.');
            $uploader=new UploadService(dirname(__DIR__));
            $saved=$uploader->financeDocument($file,$this->churchId);
            if(!$saved)throw new RuntimeException('Documento não recebido.');
            $doc=$this->addFinanceDocument($entryId,$receipt['id'],$entry['transaction_date'],$saved,
               'Recibo de despesa impresso e assinado em papel; imagem/PDF anexado pela tesouraria.');
            $this->q("UPDATE expense_receipts SET status='signed_paper',signature_method='paper',
                finance_document_id=?,signed_at=NOW(),signed_by_user_id=?
                WHERE church_id=? AND entry_id=? AND status='pending_signature'",
                [$doc,$this->userId,$this->churchId,$entryId]);
            $this->audit('expense_receipt_signed_paper_attached',
                ['receipt_id'=>$receipt['id'],'entry_id'=>$entryId,'document_id'=>$doc]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if(is_array($saved)&&is_file($saved['absolute']))@unlink($saved['absolute']);
            throw $e;
        }
    }

    public function pdf(string $entryId):string
    {
        $entry=$this->entry($entryId);
        $receipt=$this->receipt($entryId);
        if(!$receipt)throw new RuntimeException('Este lançamento ainda não possui recibo.');
        if($receipt['status']==='signed_screen' && $receipt['finance_document_id']){
            $doc=$this->q('SELECT storage_path FROM finance_documents WHERE church_id=? AND entry_id=? AND id=?',
                [$this->churchId,$entryId,$receipt['finance_document_id']])->fetchColumn();
            if(!$doc)throw new RuntimeException('Documento do recibo não encontrado.');
            $base=dirname(__DIR__).'/storage/private/finance/';
            $rel=(string)$doc;
            if(!preg_match('#^storage/private/finance/[A-Za-z0-9_-]+/recibo-despesa-[a-z0-9-]+\.pdf$#iD',$rel))
                throw new RuntimeException('Caminho privado do recibo inválido.');
            $abs=dirname(__DIR__).'/'.$rel;
            if(!is_file($abs))throw new RuntimeException('PDF de recibo indisponível.');
            $content=file_get_contents($abs);
            if(!is_string($content)||!str_starts_with($content,'%PDF-'))
                throw new RuntimeException('PDF de recibo inválido.');
            return $content;
        }
        return ExpenseReceiptPdf::render($receipt,$entry,(string)$entry['church_name']);
    }

    public function signedDocument(string $entryId):?array
    {
        $this->entry($entryId);
        $rec=$this->receipt($entryId);
        if(!$rec||!$rec['finance_document_id'])return null;
        $doc=$this->q('SELECT id,original_name,mime_type FROM finance_documents
             WHERE church_id=? AND entry_id=? AND id=?',
             [$this->churchId,$entryId,$rec['finance_document_id']])->fetch(PDO::FETCH_ASSOC);
        return $doc?:null;
    }

    public function canEdit(string $entryId):bool
    {
        try{$this->entry($entryId,true);return true;}catch(Throwable){return false;}
    }
}
