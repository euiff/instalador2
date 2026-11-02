<?php
declare(strict_types=1);
require_once __DIR__.'/PortalService.php';
require_once __DIR__.'/RegistryService.php';

final class FinanceOperations
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}
    private function guard(): void {
        if(!$this->auth->can('finance')||($this->auth->currentChurch()['id']??'')!==$this->cid)throw new RuntimeException('Acesso restrito à tesouraria desta igreja.');
    }
    private function q(string $sql,array $params=[]): PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($params);return $q;}
    public static function cents(string $amount): int {
        if(!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D',$amount,$m))throw new InvalidArgumentException('Valor monetário inválido.');
        return ($m[1]==='-'?-1:1)*((int)$m[2]*100+(int)str_pad($m[3]??'',2,'0'));
    }
    public static function decimal(int $cents): string {return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);}
    public static function installmentDate(string $first,int $offset): string {
        PortalService::date($first);$date=new DateTimeImmutable($first);$day=(int)$date->format('d');
        $month=$date->modify('first day of this month')->modify('+'.$offset.' months');
        return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min($day,(int)$month->format('t')))->format('Y-m-d');
    }
    public function account(?string $id): ?array {
        $this->guard();if(!$id)return null;
        $account=$this->q('SELECT * FROM finance_accounts WHERE id=? AND church_id=? AND active=1',[$id,$this->cid])->fetch();
        if(!$account)throw new InvalidArgumentException('Conta financeira não encontrada nesta igreja.');return $account;
    }
    public function references(array $input): array {
        $this->guard();$registry=new RegistryService($this->pdo,$this->auth,$this->cid);$out=[];
        foreach(['cost_center_id'=>'cost_center','contact_id'=>'contact'] as $key=>$kind){$id=trim((string)($input[$key]??''))?:null;if($id)$registry->find($kind,$id);$out[$key]=$id;}
        return $out;
    }
    public function createReceivables(array $input): string {
        $this->guard();$refs=$this->references($input);
        $payer=trim((string)($input['payer_name']??''));$description=trim((string)($input['description']??''));$category=trim((string)($input['category']??''));
        if($payer===''||mb_strlen($payer)>190||$description===''||mb_strlen($description)>255||$category===''||mb_strlen($category)>160)throw new InvalidArgumentException('Informe pagador, descrição e categoria.');
        $amount=PortalService::money((string)($input['amount']??''));$date=PortalService::date((string)($input['due_date']??''));
        $installments=filter_var($input['installments']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>36]]);
        if($installments===false)throw new InvalidArgumentException('Use de 1 a 36 parcelas. O valor informado é por parcela.');
        $member=trim((string)($input['member_id']??''))?:null;
        if($member&&!$this->q('SELECT id FROM members WHERE church_id=? AND id=?',[$this->cid,$member])->fetchColumn())throw new InvalidArgumentException('Membro não encontrado nesta igreja.');
        $account=trim((string)($input['account_id']??''))?:null;$this->account($account);
        $batch=app_uuid();$this->pdo->beginTransaction();
        try{for($i=0;$i<$installments;$i++)$this->q('INSERT INTO finance_receivables(id,church_id,contact_id,member_id,account_id,cost_center_id,payer_name,category,description,amount,due_date,batch_id,installment_number,installment_count,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[app_uuid(),$this->cid,$refs['contact_id'],$member,$account,$refs['cost_center_id'],$payer,$category,$description,$amount,self::installmentDate($date,$i),$batch,$i+1,$installments,$this->auth->requireLogin()['id']]);
            $this->audit('receivable_created',['batch_id'=>$batch,'installments'=>$installments]);$this->pdo->commit();return $batch;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function receivables(): array {
        $this->guard();
        return $this->q("SELECT r.*,COALESCE(p.received,0) received,GREATEST(r.amount-COALESCE(p.received,0),0) outstanding FROM finance_receivables r LEFT JOIN (SELECT receivable_id,SUM(amount) received FROM finance_entries WHERE church_id=? AND direction='income' AND status='posted' AND receivable_id IS NOT NULL GROUP BY receivable_id) p ON p.receivable_id=r.id WHERE r.church_id=? ORDER BY r.due_date,r.created_at",[$this->cid,$this->cid])->fetchAll();
    }
    public function receive(string $id,array $input): string {
        $this->guard();$amount=PortalService::money((string)($input['amount']??''));$date=PortalService::date((string)($input['received_at']??''));
        FinancePeriodGuard::assertOpen($this->pdo,$this->cid,$date);
        $key=(string)($input['request_key']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Reabra o formulário de recebimento.');
        $this->pdo->beginTransaction();
        try{
            $r=$this->q('SELECT * FROM finance_receivables WHERE church_id=? AND id=? FOR UPDATE',[$this->cid,$id])->fetch();
            if(!$r||$r['status']!=='active')throw new RuntimeException('Valor a receber não encontrado ou cancelado.');
            $existing=$this->q('SELECT id,receivable_id FROM finance_entries WHERE church_id=? AND request_key=?',[$this->cid,$key])->fetch();
            if($existing){if($existing['receivable_id']!==$id)throw new RuntimeException('Solicitação inválida.');$this->pdo->commit();return $existing['id'];}
            $received=(string)$this->q("SELECT COALESCE(SUM(amount),0) FROM finance_entries WHERE church_id=? AND receivable_id=? AND status='posted' AND direction='income'",[$this->cid,$id])->fetchColumn();
            if(self::cents($amount)>self::cents($r['amount'])-self::cents($received))throw new InvalidArgumentException('O recebimento não pode superar o saldo em aberto.');
            $accountId=trim((string)($input['account_id']??''))?:($r['account_id']?:null);$account=$this->account($accountId);
            $entry=app_uuid();$this->q("INSERT INTO finance_entries(id,church_id,account_id,member_id,department_id,cost_center_id,contact_id,receivable_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,request_key) VALUES(?,?,?,?,?,?,?,?,'income',?,?,?,?,'posted','receivable',?,?,?)",[$entry,$this->cid,$accountId,$r['member_id'],$account['department_id']??null,$r['cost_center_id'],$r['contact_id'],$id,$r['category'],$r['description'],$amount,$date,$this->auth->requireLogin()['id'],$r['payer_name'],$key]);
            $this->audit('receivable_received',['receivable_id'=>$id,'entry_id'=>$entry]);$this->pdo->commit();return $entry;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function cancel(string $id): void {
        $this->guard();$this->pdo->beginTransaction();
        try{$r=$this->q('SELECT id,status FROM finance_receivables WHERE church_id=? AND id=? FOR UPDATE',[$this->cid,$id])->fetch();if(!$r||$r['status']!=='active')throw new RuntimeException('Registro não encontrado ou já cancelado.');
            $sum=(string)$this->q("SELECT COALESCE(SUM(amount),0) FROM finance_entries WHERE church_id=? AND receivable_id=? AND status='posted'",[$this->cid,$id])->fetchColumn();
            if(self::cents($sum)!==0)throw new RuntimeException('Há recebimentos registrados. Estorne-os na tesouraria antes de cancelar.');
            $this->q("UPDATE finance_receivables SET status='cancelled' WHERE id=? AND church_id=?",[$id,$this->cid]);$this->audit('receivable_cancelled',['receivable_id'=>$id]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function entries(string $from,string $to,?string $center=null): array {
        $this->guard();PortalService::date($from);PortalService::date($to);if($from>$to)throw new InvalidArgumentException('Período inválido.');
        if($center)(new RegistryService($this->pdo,$this->auth,$this->cid))->find('cost_center',$center,false);
        $params=[$this->cid,$from,$to];if($center)$params[]=$center;
        return $this->q('SELECT e.*,a.name account_name,c.name cost_center_name,m.name member_name FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id LEFT JOIN church_registers c ON c.id=e.cost_center_id AND c.church_id=e.church_id LEFT JOIN members m ON m.id=e.member_id AND m.church_id=e.church_id WHERE e.church_id=? AND e.transaction_date BETWEEN ? AND ?'.($center?' AND e.cost_center_id=?':'').' ORDER BY e.transaction_date DESC,e.created_at DESC',$params)->fetchAll();
    }
    public function auditRows(): array {
        $this->guard();return $this->q("SELECT action,created_at,details FROM (
          SELECT action,created_at,details FROM platform_audit_logs WHERE church_id=? AND (action LIKE 'receivable_%' OR action LIKE 'department_%' OR action LIKE 'registry_%' OR action LIKE 'finance_%')
          UNION ALL SELECT 'finance_entry_created',created_at,NULL FROM finance_entries WHERE church_id=? AND source NOT IN ('department','receivable','csv_import')
          UNION ALL SELECT 'finance_entry_reversed',reversed_at,NULL FROM finance_entries WHERE church_id=? AND reversed_at IS NOT NULL AND source<>'department'
        ) history ORDER BY created_at DESC LIMIT 200",[$this->cid,$this->cid,$this->cid])->fetchAll();
    }
    private function audit(string $action,array $details): void {$this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[app_uuid(),$this->auth->requireLogin()['id'],$this->cid,$action,json_encode($details,JSON_THROW_ON_ERROR)]);}
}
