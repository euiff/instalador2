<?php
declare(strict_types=1);

/**
 * Recibo provisório de RECEBIMENTO: não cria lançamento financeiro até confirmação.
 * Toda operação pertence à igreja autenticada. Aprovação é idempotente por lock.
 */
final class PendingFinanceReceipt
{
    public function __construct(private PDO $pdo,private string $cid,private string $uid) {}
    public static function schema(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_receipt_drafts (
            id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL,
            member_id CHAR(36) NULL, account_id CHAR(36) NULL,
            cost_center_id CHAR(36) NULL, contact_id CHAR(36) NULL,
            payer_name VARCHAR(190) NOT NULL, payer_document VARCHAR(60) NULL,
            amount DECIMAL(14,2) NOT NULL, category VARCHAR(160) NOT NULL,
            description VARCHAR(255) NOT NULL, payment_method VARCHAR(60) NULL,
            received_at DATE NOT NULL, notes TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'awaiting_signature',
            signed_path VARCHAR(255) NULL, signed_name VARCHAR(255) NULL,
            signed_mime VARCHAR(120) NULL, signed_size INT UNSIGNED NULL,
            signature_method VARCHAR(20) NULL, signed_at DATETIME NULL,
            finance_entry_id CHAR(36) NULL, finance_receipt_id CHAR(36) NULL,
            created_by_user_id CHAR(36) NULL, approved_by_user_id CHAR(36) NULL,
            approved_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_receipt_draft_church(church_id,status,created_at),
            UNIQUE KEY uq_receipt_draft_entry(finance_entry_id),
            UNIQUE KEY uq_receipt_draft_receipt(finance_receipt_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    private function q(string $sql,array $args=[]): PDOStatement {
        $q=$this->pdo->prepare($sql);$q->execute($args);return $q;
    }
    public function list(): array {
        return $this->q("SELECT * FROM finance_receipt_drafts WHERE church_id=? ORDER BY created_at DESC LIMIT 100",[$this->cid])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function find(string $id,bool $lock=false): array {
        $s=$lock?' FOR UPDATE':'';
        $row=$this->q("SELECT * FROM finance_receipt_drafts WHERE church_id=? AND id=?".$s,[$this->cid,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Recibo pendente não encontrado nesta igreja.');
        return $row;
    }
    public function create(array $data,?string $memberId,?string $accountId,array $refs,string $amount,string $date): string {
        $payer=trim((string)($data['payer_name']??''));
        $description=trim((string)($data['description']??''));
        $category=trim((string)($data['category']??'Ofertas'))?:'Ofertas';
        if($payer===''||mb_strlen($payer)>190||$description===''||mb_strlen($description)>255||mb_strlen($category)>160)
            throw new InvalidArgumentException('Preencha corretamente o nome, a descrição e a categoria.');
        if((float)$amount<=0||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date))
            throw new InvalidArgumentException('Valor ou data do recibo inválidos.');
        $id=app_uuid();
        $this->q("INSERT INTO finance_receipt_drafts
          (id,church_id,member_id,account_id,cost_center_id,contact_id,payer_name,payer_document,amount,category,description,payment_method,received_at,notes,created_by_user_id)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[
            $id,$this->cid,$memberId,$accountId,$refs['cost_center_id']??null,$refs['contact_id']??null,
            $payer,mb_substr(trim((string)($data['payer_document']??'')),0,60)?:null,
            $amount,$category,$description,mb_substr(trim((string)($data['payment_method']??'')),0,60)?:null,$date,
            trim((string)($data['notes']??''))?:null,$this->uid
          ]);
        return $id;
    }
    public function sign(string $id,array $file,UploadService $upload,string $method): void {
        if(!in_array($method,['screen','paper'],true))throw new InvalidArgumentException('Tipo de assinatura inválido.');
        $row=$this->find($id);
        if($row['status']!=='awaiting_signature')throw new RuntimeException('Este recibo não está aguardando assinatura.');
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
            throw new InvalidArgumentException('Envie a assinatura na tela ou o documento assinado.');
        $stored=$upload->financeDocument($file,$this->cid);
        if(!$stored)throw new RuntimeException('Não foi possível salvar o comprovante assinado.');
        $update=$this->q("UPDATE finance_receipt_drafts SET signed_path=?,signed_name=?,signed_mime=?,signed_size=?,
          signature_method=?,signed_at=NOW(),status='awaiting_review'
          WHERE id=? AND church_id=? AND status='awaiting_signature'",[
            $stored['path'],$stored['original_name'],$stored['mime'],$stored['size'],$method,$id,$this->cid
        ]);
        if($update->rowCount()!==1)throw new RuntimeException('A situação do recibo mudou. Confira antes de tentar novamente.');
    }
    public function approve(string $id): string {
        // FinancePeriodGuard must be acquired before starting the transaction.
        FinancePeriodGuard::hold($this->pdo,$this->cid);
        $this->pdo->beginTransaction();
        try {
            $r=$this->find($id,true);
            if($r['status']==='posted')throw new RuntimeException('Este recibo já foi lançado; não será duplicado.');
            if($r['status']!=='awaiting_review'||empty($r['signed_path']))
                throw new RuntimeException('Antes de lançar é obrigatório anexar e conferir a assinatura.');
            FinancePeriodGuard::assertOpen($this->pdo,$this->cid,(string)$r['received_at']);
            if(!empty($r['account_id'])){
                $account=$this->q("SELECT id FROM finance_accounts WHERE id=? AND church_id=? AND department_id IS NULL AND active=1",
                    [$r['account_id'],$this->cid])->fetchColumn();
                if(!$account)throw new RuntimeException('A conta do recibo está inativa. Selecione uma conta ativa antes de confirmar.');
            }
            // Locks church row before taking next receipt number; protects concurrent issuances.
            if(!$this->q("SELECT id FROM churches WHERE id=? FOR UPDATE",[$this->cid])->fetchColumn())
                throw new RuntimeException('Igreja não localizada.');
            $number=(int)$this->q("SELECT COALESCE(MAX(receipt_number),0)+1 FROM finance_receipts WHERE church_id=?",[$this->cid])->fetchColumn();
            $entryId=app_uuid();$receiptId=app_uuid();
            $this->q("INSERT INTO finance_entries
             (id,church_id,account_id,member_id,department_id,cost_center_id,contact_id,direction,
              category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,payment_method,notes)
              VALUES(?,?,?,?,NULL,?,?,'income',?,?,?,?,'posted','receipt',?,?,?,?)",[
                $entryId,$this->cid,$r['account_id'],$r['member_id'],$r['cost_center_id'],$r['contact_id'],
                $r['category'],$r['description'],$r['amount'],$r['received_at'],$this->uid,
                $r['payer_name'],$r['payment_method'],$r['notes']
            ]);
            $this->q("INSERT INTO finance_receipts
              (id,church_id,receipt_number,finance_entry_id,member_id,payer_name,payer_document,
               amount,description,payment_method,received_at,notes,created_by_user_id)
               VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",[
                $receiptId,$this->cid,$number,$entryId,$r['member_id'],$r['payer_name'],$r['payer_document'],
                $r['amount'],$r['description'],$r['payment_method'],$r['received_at'],$r['notes'],$this->uid
            ]);
            $this->q("INSERT INTO finance_documents
              (id,church_id,entry_id,document_type,original_name,storage_path,mime_type,file_size,notes,created_by_user_id)
               VALUES(?,?,?,'receipt',?,?,?,?,?,?)",[
                app_uuid(),$this->cid,$entryId,$r['signed_name'],$r['signed_path'],$r['signed_mime'],$r['signed_size'],
                'Recibo assinado e conferido: '.$id,$this->uid
            ]);
            $changed=$this->q("UPDATE finance_receipt_drafts SET status='posted',
                finance_entry_id=?,finance_receipt_id=?,approved_by_user_id=?,approved_at=NOW()
                WHERE church_id=? AND id=? AND status='awaiting_review'",[
                $entryId,$receiptId,$this->uid,$this->cid,$id
            ]);
            if($changed->rowCount()!==1)throw new RuntimeException('Recibo já processado por outro usuário.');
            $this->pdo->commit();
            return $receiptId;
        } catch(Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
    public function cancel(string $id): void {
        $updated=$this->q("UPDATE finance_receipt_drafts SET status='cancelled' WHERE id=? AND church_id=? AND status IN ('awaiting_signature','awaiting_review')",[$id,$this->cid]);
        if($updated->rowCount()!==1)throw new RuntimeException('Só é possível cancelar recibo ainda não lançado.');
    }
}
