<?php
declare(strict_types=1);
require_once __DIR__.'/FinancePeriodGuard.php';

/** All portal reads and mutations are scoped by church and the authenticated membership. */
final class PortalService
{
    public function __construct(private PDO $pdo, private Auth $auth, private string $cid) {}

    private function query(string $sql, array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql); $q->execute($params); return $q;
    }

    public static function date(string $value): string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Informe uma data válida.');
        return $value;
    }

    public static function money(string $value): string
    {
        $value=trim($value);
        if(str_contains($value,',')) {
            if(!preg_match('/^\d{1,10}(?:,\d{1,2})?$|^\d{1,3}(?:\.\d{3}){1,3},\d{1,2}$/D',$value)) throw new InvalidArgumentException('Valor inválido. Use 125,50.');
            $value=str_replace(',','.',str_replace('.','',$value));
        }
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$value)) throw new InvalidArgumentException('Valor inválido.');
        [$whole,$decimal]=array_pad(explode('.',$value,2),2,'');
        $cents=(int)$whole*100+(int)str_pad($decimal,2,'0');
        if($cents<=0 || $cents>999999999999) throw new InvalidArgumentException('O valor deve ser maior que zero.');
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    public function member(): ?array
    {
        $membership=$this->auth->currentMembership();
        if(!$membership || ($membership['church_id']??'')!==$this->cid || empty($membership['person_id'])) return null;
        return $this->query('SELECT id,name,email,phone FROM members WHERE church_id=? AND id=?',[$this->cid,$membership['person_id']])->fetch()?:null;
    }

    public function departments(): array
    {
        $user=$this->auth->requireLogin();
        $sql='SELECT d.*,a.id account_id,a.opening_balance FROM ministry_departments d LEFT JOIN finance_accounts a ON a.church_id=d.church_id AND a.department_id=d.id AND a.active=1 WHERE d.church_id=? AND d.active=1';
        $params=[$this->cid];
        if(!$this->auth->can('finance')) {
            if($this->auth->churchRole()!=='department_leader') return [];
            $sql.=' AND EXISTS (SELECT 1 FROM department_access x WHERE x.church_id=d.church_id AND x.department_id=d.id AND x.user_id=?)';
            $params[]=$user['id'];
        }
        return $this->query($sql.' ORDER BY d.name',$params)->fetchAll();
    }

    public function department(string $id): array
    {
        foreach($this->departments() as $d) if($d['id']===$id) return $d;
        throw new RuntimeException('Departamento não disponível para este acesso.');
    }

    public function ledger(?string $departmentId,string $from,string $to): array
    {
        self::date($from);self::date($to);
        if($from>$to) throw new InvalidArgumentException('A data inicial deve ser anterior à final.');
        if($departmentId!==null) {
            $department=$this->department($departmentId);
            // Match both the account and department. An unrelated account never enters this caixa.
            $scope='e.account_id=?'; $args=[$department['account_id']??''];
        } else {
            $member=$this->member();
            if(!$member) return [];
            $scope="e.member_id=? AND e.direction='income'"; $args=[$member['id']];
        }
        return $this->query('SELECT e.id,e.transaction_date,e.direction,e.category,e.description,e.amount,e.status,e.reversal_reason FROM finance_entries e WHERE e.church_id=? AND '.$scope.' AND e.transaction_date BETWEEN ? AND ? ORDER BY e.transaction_date DESC,e.created_at DESC',[$this->cid,...$args,$from,$to])->fetchAll();
    }

    public function balance(string $departmentId): string
    {
        $d=$this->department($departmentId);
        return (string)$this->query("SELECT CAST(? AS DECIMAL(14,2))+COALESCE(SUM(CASE WHEN direction='income' THEN amount ELSE -amount END),0) FROM finance_entries WHERE church_id=? AND account_id=? AND status='posted'",[$d['opening_balance']??'0',$this->cid,$d['account_id']??''])->fetchColumn();
    }

    public function createEntry(string $departmentId,array $input): string
    {
        $department=$this->department($departmentId);
        if(empty($department['account_id'])) throw new RuntimeException('Peça à tesouraria para ativar o caixa deste departamento.');
        $direction=(string)($input['direction']??'');
        if(!in_array($direction,['income','expense'],true)) throw new InvalidArgumentException('Tipo de movimentação inválido.');
        $amount=self::money((string)($input['amount']??''));
        $date=self::date((string)($input['transaction_date']??''));
        FinancePeriodGuard::assertOpen($this->pdo,$this->cid,$date);
        $description=trim((string)($input['description']??''));
        $category=trim((string)($input['category']??''));
        $key=(string)($input['request_key']??'');
        if($description===''||mb_strlen($description)>255||$category===''||mb_strlen($category)>160) throw new InvalidArgumentException('Informe descrição e categoria dentro do limite permitido.');
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new InvalidArgumentException('Reabra o formulário e tente novamente.');
        $user=$this->auth->requireLogin();
        $this->pdo->beginTransaction();
        try {
            if(!$this->query('SELECT id FROM finance_accounts WHERE id=? AND church_id=? AND department_id=? AND active=1 FOR UPDATE',[$department['account_id'],$this->cid,$departmentId])->fetchColumn())throw new RuntimeException('Caixa indisponível.');
            $previous=$this->query('SELECT id,department_id FROM finance_entries WHERE church_id=? AND request_key=?',[$this->cid,$key])->fetch();
            if($previous) {
                if($previous['department_id']!==$departmentId) throw new RuntimeException('Solicitação inválida.');
                $this->pdo->commit();return $previous['id'];
            }
            $id=app_uuid();
            $this->query("INSERT INTO finance_entries(id,church_id,account_id,department_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,request_key) VALUES(?,?,?,?,?,?,?,?,?,'posted','department',?,?)",[$id,$this->cid,$department['account_id'],$departmentId,$direction,$category,$description,$amount,$date,$user['id'],$key]);
            $this->audit('department_entry_created',['entry_id'=>$id,'department_id'=>$departmentId]);
            $this->pdo->commit();return $id;
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e; }
    }

    public function reverse(string $departmentId,string $entryId,string $reason): void
    {
        $d=$this->department($departmentId);$reason=trim($reason);
        if($reason===''||mb_strlen($reason)>1000) throw new InvalidArgumentException('Informe o motivo do estorno (até 1.000 caracteres).');
        $date=$this->query('SELECT transaction_date FROM finance_entries WHERE id=? AND church_id=? AND account_id=?',[$entryId,$this->cid,$d['account_id']??''])->fetchColumn();
        if(!$date)throw new RuntimeException('Movimentação não encontrada neste caixa.');
        FinancePeriodGuard::assertOpen($this->pdo,$this->cid,(string)$date);
        $this->pdo->beginTransaction();
        try {
            $q=$this->query("UPDATE finance_entries SET status='reversed',reversed_at=NOW(),reversal_reason=?,reversed_by_user_id=? WHERE id=? AND church_id=? AND account_id=? AND status='posted'",[$reason,$this->auth->requireLogin()['id'],$entryId,$this->cid,$d['account_id']??'']);
            if($q->rowCount()!==1) throw new RuntimeException('Movimentação não encontrada neste caixa ou já estornada.');
            $this->audit('department_entry_reversed',['entry_id'=>$entryId,'department_id'=>$departmentId]);
            $this->pdo->commit();
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e; }
    }

    public function enableCash(string $departmentId): void
    {
        if(!$this->auth->can('finance')) throw new RuntimeException('Somente a tesouraria pode ativar caixas.');
        $d=$this->department($departmentId);
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM ministry_departments WHERE id=? AND church_id=? FOR UPDATE',[$departmentId,$this->cid]);
            $this->query("INSERT INTO finance_accounts(id,church_id,name,account_type,department_id,opening_balance,active) VALUES(?,?,?,'cash',?,0,1) ON DUPLICATE KEY UPDATE active=1",[app_uuid(),$this->cid,'Caixa · '.$d['name'],$departmentId]);
            $this->audit('department_cash_enabled',['department_id'=>$departmentId]);
            $this->pdo->commit();
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e; }
    }

    private function audit(string $action,array $details): void
    {
        $this->query('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[app_uuid(),$this->auth->requireLogin()['id'],$this->cid,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}
