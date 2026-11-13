<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceOperations.php';
require_once __DIR__.'/DepartmentRoleAccess.php';
require_once __DIR__.'/UploadService.php';
require_once __DIR__.'/FinanceFiscalScan.php';
require_once __DIR__.'/FinanceManualAttachments.php';
require_once __DIR__.'/ReceiptParser.php';
require_once __DIR__.'/ExpenseReceiptService.php';

/**
 * Each department sees only its own accounts and ledger entries.
 * A departmental entry IS a finance_entries row; no duplicate posting to the
 * central treasury. General and accountant reports are alternate read views.
 */
final class DepartmentTreasuryService
{
    private bool $fullAccess;
    private bool $canPost;

    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private string $churchId,
        private string $userId
    ) {
        $church=$auth->currentChurch();
        if(!$church||$churchId===''||$userId===''||(string)$church['id']!==$churchId||(!($auth->can('department_treasury') || ($auth->churchRole()==='accountant' && $auth->can('accounting_portal')))))
            throw new RuntimeException('Acesso negado à tesouraria deste departamento.');
        ExpenseReceiptService::ensureSchema($pdo);
        $role=$auth->churchRole();
        $this->fullAccess=in_array($role,['owner','admin','pastor','treasurer','accountant','super_admin'],true);
        $this->canPost=$role!=='accountant' && ($this->fullAccess || DepartmentRoleAccess::hasFinancialScope($pdo,$churchId,$userId));
    }

    private function query(string $sql,array $bind=[]):PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($bind);return $q;
    }

    public function mayPost():bool{return $this->canPost;}
    public function mayCreateAccount():bool
    {
        return $this->canPost && $this->fullAccess && $this->auth->churchRole()!=='accountant';
    }

    public function departments():array
    {
        if($this->fullAccess)
            return $this->query('SELECT id,name,active FROM ministry_departments WHERE church_id=? ORDER BY active DESC,name',
                [$this->churchId])->fetchAll(PDO::FETCH_ASSOC);
        return DepartmentRoleAccess::listFinancialDepartments($this->pdo,$this->churchId,$this->userId);
    }

    public function department(string $departmentId,bool $requireActive=false):array
    {
        if(!preg_match('/^[a-f\d-]{36}$/iD',$departmentId))throw new InvalidArgumentException('Departamento inválido.');
        $q=$this->query('SELECT id,name,active FROM ministry_departments WHERE church_id=? AND id=? LIMIT 1',
            [$this->churchId,$departmentId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Departamento não encontrado nesta igreja.');
        if(!$this->fullAccess){
            $allowed=DepartmentRoleAccess::hasFinancialScope($this->pdo,$this->churchId,$this->userId,$departmentId);
            if(!$allowed)throw new RuntimeException('Seu acesso não permite movimentar este departamento.');
        }
        if($requireActive&&empty($row['active']))throw new RuntimeException('Departamento inativo. Não é possível criar lançamentos.');
        return $row;
    }

    public function accounts(string $departmentId):array
    {
        $this->department($departmentId);
        return $this->query(
            'SELECT id,name,account_type,opening_balance,active FROM finance_accounts
             WHERE church_id=? AND department_id=? ORDER BY active DESC,name',
            [$this->churchId,$departmentId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createAccount(string $departmentId,array $input):string
    {
        if(!$this->mayCreateAccount())throw new RuntimeException('Somente pastor ou tesoureiro pode cadastrar caixas.');
        $this->department($departmentId,true);
        $name=mb_substr(trim((string)($input['account_name']??'')),0,130);
        $type=(string)($input['account_type']??'cash');
        if($name===''||!in_array($type,['cash','bank'],true))throw new InvalidArgumentException('Informe nome e tipo do caixa.');
        $exists=$this->query('SELECT id FROM finance_accounts WHERE church_id=? AND department_id=? AND name=? LIMIT 1',
            [$this->churchId,$departmentId,$name])->fetchColumn();
        if($exists)throw new InvalidArgumentException('Já existe um caixa com este nome no departamento.');
        $id=app_uuid();
        $this->pdo->beginTransaction();
        try{
            $this->query(
                'INSERT INTO finance_accounts(id,church_id,department_id,name,account_type,opening_balance,active)
                 VALUES(?,?,?,?,?,0,1)',
                [$id,$this->churchId,$departmentId,$name,$type]
            );
            $this->audit('department_cash_created',['department_id'=>$departmentId,'account_id'=>$id]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $id;
    }

    private function audit(string $action,array $details):void
    {
        $this->query('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[
            app_uuid(),$this->userId,$this->churchId,$action,
            json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
        ]);
    }

    public function createEntry(string $departmentId,array $data,array $file=[],array $extraPrepared=[]):string
    {
        if(!$this->canPost)throw new RuntimeException('O contador ou seu perfil não pode lançar movimentações neste departamento.');
        $this->department($departmentId,true);
        $accountId=trim((string)($data['account_id']??''));
        $account=$this->query(
            'SELECT id FROM finance_accounts WHERE id=? AND church_id=? AND department_id=? AND active=1 LIMIT 1',
            [$accountId,$this->churchId,$departmentId]
        )->fetch();
        if(!$account)throw new InvalidArgumentException('Selecione um caixa válido deste departamento.');
        $direction=(string)($data['direction']??'expense');
        if(!in_array($direction,['income','expense'],true))throw new InvalidArgumentException('Tipo de lançamento inválido.');
        $category=mb_substr(trim((string)($data['category']??'')),0,160);
        $description=mb_substr(trim((string)($data['description']??'')),0,255);
        $counterparty=mb_substr(trim((string)($data['counterparty']??'')),0,190);
        $documentNumber=mb_substr(trim((string)($data['document_number']??'')),0,60);
        $payment=mb_substr(trim((string)($data['payment_method']??'')),0,50);
        $date=(string)($data['transaction_date']??'');
        PortalService::date($date);
        $money=ReceiptParser::amount((string)($data['amount']??''));
        if($money===null||FinanceOperations::cents($money)<=0)throw new InvalidArgumentException('Informe valor positivo.');
        if($category===''||$description==='')throw new InvalidArgumentException('Informe a categoria e a descrição do movimento.');
        if((float)$money>999999999.99)throw new InvalidArgumentException('Valor acima do limite do lançamento.');
        // Não confundir o departamento do contribuinte com o departamento do caixa.
        $memberId=trim((string)($data['member_id']??''))?:null;
        if($memberId!==null){
            if($direction!=='income')throw new InvalidArgumentException('O vínculo pessoal só é permitido para entradas.');
            if(!$this->query('SELECT id FROM members WHERE church_id=? AND id=? LIMIT 1',[$this->churchId,$memberId])->fetchColumn())
                throw new InvalidArgumentException('Membro não encontrado nesta igreja.');
        }
        $costCenterId=trim((string)($data['cost_center_id']??''))?:null;
        $contactId=trim((string)($data['contact_id']??''))?:null;
        if($costCenterId!==null||$contactId!==null){
            if(!$this->auth->can('finance'))throw new RuntimeException('Perfil sem acesso aos cadastros financeiros.');
            $refs=(new FinanceOperations($this->pdo,$this->auth,$this->churchId))->references($data);
            $costCenterId=$refs['cost_center_id'];
            $contactId=$refs['contact_id'];
        }
        $requestKey=trim((string)($data['request_key']??''));
        if($requestKey!==''&&!preg_match('/^[a-f0-9]{64}$/D',$requestKey))
            throw new InvalidArgumentException('Identificador de lançamento inválido. Reabra o formulário.');
        $requestKey=$requestKey!==''?$requestKey:null;
        $notes=mb_substr(trim((string)($data['notes']??'')),0,2000);
        if($notes==='')$notes='Lançamento pelo caixa departamental';
        // Leaders must attach proof for expenses; general treasurer can register
        // pending documentation, visible to accountant as expense_without_proof.
        $hasFile=(int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
        $fileHash=$hasFile&&is_file((string)($file['tmp_name']??''))
            ?hash_file('sha256',(string)$file['tmp_name']):null;
        $duplicates=FinanceFiscalScan::duplicates($this->pdo,$this->churchId,[
            'document_number'=>$documentNumber,'amount'=>$money,'date'=>$date,
            'issuer'=>$counterparty,'file_hash'=>$fileHash
        ]);
        foreach($duplicates as $duplicate){
            if(!empty($duplicate['blocking'])||($duplicate['reason']??'')==='Mesmo arquivo já anexado')
                throw new InvalidArgumentException('Esta nota ou cupom já está registrado nesta igreja. Confira o lançamento anterior.');
        }
        $proofMethod=(string)($data['proof_method']??'upload');
        $generateReceipt=$direction==='expense'&&in_array($proofMethod,['receipt_screen','receipt_print'],true);
        if($generateReceipt){
            ExpenseReceiptService::validateInput($data);
            if($hasFile)throw new InvalidArgumentException('Escolha anexar comprovante OU gerar recibo.');
        }
        if($direction==='expense'&&!$hasFile&&!$extraPrepared&&!$generateReceipt&&!$this->fullAccess)
            throw new InvalidArgumentException('Anexe um comprovante ou gere um recibo para registrar esta despesa.');
        FinancePeriodGuard::assertOpen($this->pdo,$this->churchId,$date);
        $manualService=new FinanceManualAttachments($this->pdo,dirname(__DIR__));
        $saved=null;$entryId=app_uuid();
        try{
            if($hasFile){
                $uploader=new UploadService(dirname(__DIR__));
                $saved=$uploader->financeDocument($file,$this->churchId);
            }elseif(isset($file['error'])&&!in_array((int)$file['error'],[UPLOAD_ERR_NO_FILE,UPLOAD_ERR_OK],true)){
                throw new InvalidArgumentException('O comprovante não pôde ser enviado. Tente novamente.');
            }
            $this->pdo->beginTransaction();
            $this->query(
                "INSERT INTO finance_entries
                    (id,church_id,account_id,member_id,department_id,cost_center_id,contact_id,direction,category,description,amount,transaction_date,
                     status,source,created_by_user_id,counterparty,payment_method,document_number,notes,request_key)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'posted','department',?,?,?,?,?,?)",
                [$entryId,$this->churchId,$accountId,$memberId,$departmentId,$costCenterId,$contactId,$direction,$category,$description,$money,$date,
                    $this->userId,$counterparty?:null,$payment?:null,$documentNumber?:null,$notes,$requestKey]
            );
            if($saved){
                $this->query(
                    'INSERT INTO finance_documents
                        (id,church_id,entry_id,payable_id,document_type,document_number,issued_at,
                         original_name,storage_path,mime_type,file_size,notes,created_by_user_id,file_sha256,fiscal_access_key)
                     VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?)',
                    [app_uuid(),$this->churchId,$entryId,
                        in_array((string)($data['document_type']??'proof'),['fiscal_coupon','invoice','receipt','proof'],true)
                            ?(string)$data['document_type']:'proof',
                        $documentNumber?:null,$date,$saved['original_name'],$saved['path'],$saved['mime'],
                        $saved['size'],'Comprovante do departamento '.$departmentId,$this->userId,
                        $fileHash?:null,FinanceFiscalScan::key44($documentNumber)]
                );
            }
            if($generateReceipt){
                $receipt=new ExpenseReceiptService($this->pdo,$this->auth,$this->churchId,$this->userId);
                $receipt->create($entryId,$data);
            }
            $manualService->insert(
                $this->churchId,$entryId,$this->userId,$extraPrepared,
                (string)($data['extra_document_type']??'proof')
            );
            $this->audit('department_cash_posted',[
                'department_id'=>$departmentId,'account_id'=>$accountId,'entry_id'=>$entryId,'direction'=>$direction,
                'amount'=>$money,'has_proof'=>(bool)$saved||!empty($extraPrepared)||($generateReceipt&&$proofMethod==='receipt_screen'),
                'extra_documents'=>count($extraPrepared)
            ]);
            $this->pdo->commit();
            $manualService->consume($extraPrepared);
            return $entryId;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($saved&&is_file($saved['absolute']))@unlink($saved['absolute']);
            throw $e;
        }
    }

    /** These are one set of ledger rows with department attribution, NOT copied entries. */
    public function summary(string $from,string $to):array
    {
        PortalService::date($from);PortalService::date($to);
        if($to<$from)throw new InvalidArgumentException('Data final anterior à inicial.');
        $departments=$this->departments();
        if(!$departments)return [];
        $ids=array_map(static fn($d)=>(string)$d['id'],$departments);
        $holders=implode(',',array_fill(0,count($ids),'?'));
        $sql="SELECT d.id,d.name,d.active, COALESCE(b.opening_balance,0) opening_balance,
            COALESCE(t.before_total,0) before_total,
            COALESCE(t.income,0) income,
            COALESCE(t.expense,0) expense,
            COALESCE(t.missing_proofs,0) missing_proofs
          FROM ministry_departments d
          LEFT JOIN (
            SELECT church_id,department_id,SUM(opening_balance) opening_balance
            FROM finance_accounts WHERE church_id=? AND department_id IS NOT NULL
            GROUP BY church_id,department_id
          ) b ON b.church_id=d.church_id AND b.department_id=d.id
          LEFT JOIN (
            SELECT e.church_id,COALESCE(e.department_id,a.department_id) department_id,
             SUM(CASE WHEN e.transaction_date<? THEN CASE WHEN e.direction='income' THEN e.amount ELSE -e.amount END ELSE 0 END) before_total,
             SUM(CASE WHEN e.transaction_date BETWEEN ? AND ? AND e.direction='income' THEN e.amount ELSE 0 END) income,
             SUM(CASE WHEN e.transaction_date BETWEEN ? AND ? AND e.direction='expense' THEN e.amount ELSE 0 END) expense,
             SUM(CASE WHEN e.transaction_date BETWEEN ? AND ? AND e.direction='expense'
                 AND NOT EXISTS(SELECT 1 FROM finance_documents fd WHERE fd.church_id=e.church_id AND fd.entry_id=e.id)
                 THEN 1 ELSE 0 END) missing_proofs
            FROM finance_entries e
            LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id
            WHERE e.church_id=? AND e.status='posted' AND e.transaction_date<=?
            GROUP BY e.church_id,COALESCE(e.department_id,a.department_id)
          ) t ON t.church_id=d.church_id AND t.department_id=d.id
          WHERE d.church_id=? AND d.id IN ($holders)
          ORDER BY d.name";
        $bind=[$this->churchId,$from,$from,$to,$from,$to,$from,$to,$this->churchId,$to,$this->churchId,...$ids];
        $rows=$this->query($sql,$bind)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function(array $r):array{
            $opening=FinanceOperations::cents((string)$r['opening_balance'])
                +FinanceOperations::cents((string)$r['before_total']);
            $income=FinanceOperations::cents((string)$r['income']);
            $expense=FinanceOperations::cents((string)$r['expense']);
            return [
                ...$r,'opening_cents'=>$opening,'income_cents'=>$income,'expense_cents'=>$expense,
                'closing_cents'=>$opening+$income-$expense,'missing_proofs'=>(int)$r['missing_proofs']
            ];
        },$rows);
    }

    public function entries(string $departmentId,string $from,string $to,int $limit=1000):array
    {
        $this->department($departmentId);
        PortalService::date($from);PortalService::date($to);
        if($to<$from)throw new InvalidArgumentException('Período inválido.');
        $limit=max(1,min(5000,$limit));
        $sql="SELECT e.id,e.transaction_date,e.direction,e.category,e.description,e.amount,e.status,
          e.document_number,e.counterparty,e.payment_method,
          a.name account_name,
          EXISTS(SELECT 1 FROM finance_documents fd WHERE fd.church_id=e.church_id AND fd.entry_id=e.id) has_proof,
           (SELECT er.status FROM expense_receipts er WHERE er.church_id=e.church_id
               AND er.entry_id=e.id LIMIT 1) receipt_status
          FROM finance_entries e
          LEFT JOIN finance_accounts a ON a.church_id=e.church_id AND a.id=e.account_id
          WHERE e.church_id=? AND COALESCE(e.department_id,a.department_id)=?
            AND e.transaction_date BETWEEN ? AND ?
          ORDER BY e.transaction_date DESC,e.id DESC LIMIT ".$limit;
        return $this->query($sql,[$this->churchId,$departmentId,$from,$to])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function generalPostedTotals(string $from,string $to):array
    {
        if(!$this->fullAccess)throw new RuntimeException('Somente tesouraria geral e auditoria podem consultar o consolidado.');
        PortalService::date($from);PortalService::date($to);
        if($from>$to)throw new InvalidArgumentException('Período inválido.');
        return $this->query(
            "SELECT COALESCE(SUM(CASE WHEN direction='income' THEN amount ELSE 0 END),0) income,
              COALESCE(SUM(CASE WHEN direction='expense' THEN amount ELSE 0 END),0) expense
             FROM finance_entries WHERE church_id=? AND status='posted' AND transaction_date BETWEEN ? AND ?",
            [$this->churchId,$from,$to]
        )->fetch(PDO::FETCH_ASSOC)?:['income'=>0,'expense'=>0];
    }
}
