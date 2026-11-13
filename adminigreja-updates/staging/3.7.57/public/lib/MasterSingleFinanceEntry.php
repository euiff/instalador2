<?php
declare(strict_types=1);
require_once __DIR__.'/FinancePeriodGuard.php';

final class MasterSingleFinanceEntry
{
    public function __construct(private PDO $pdo,private string $root) {}

    public function exclude(string $cid,string $entryId,string $confirmId,string $reason,string $actor): array
    {
        $cid=trim($cid);$entryId=trim($entryId);$reason=trim($reason);
        if($cid===''||!preg_match('/^[0-9a-f-]{36}$/iD',$entryId))
            throw new InvalidArgumentException('Selecione um lançamento da igreja.');
        if(!hash_equals($entryId,trim($confirmId)))
            throw new InvalidArgumentException('A confirmação não corresponde ao lançamento selecionado.');
        if(mb_strlen($reason)<10 || mb_strlen($reason)>200)
            throw new InvalidArgumentException('Explique o motivo da exclusão (10 a 200 caracteres).');

        $q=$this->pdo->prepare('SELECT e.*,a.name account_name
            FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id
            WHERE e.church_id=? AND e.id=? LIMIT 1');
        $q->execute([$cid,$entryId]);$before=$q->fetch(PDO::FETCH_ASSOC);
        if(!$before)throw new RuntimeException('Esse lançamento não pertence à igreja selecionada.');
        if($before['status']!=='posted')throw new RuntimeException('Lançamento já estornado/excluído. Nenhum valor foi alterado.');

        FinancePeriodGuard::assertOpen($this->pdo,$cid,(string)$before['transaction_date']);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM finance_entries WHERE church_id=? AND id=? FOR UPDATE');
            $q->execute([$cid,$entryId]);$entry=$q->fetch(PDO::FETCH_ASSOC);
            if(!$entry||$entry['status']!=='posted')
                throw new RuntimeException('O lançamento foi alterado por outro usuário.');

            // Do not adjust balances already reconciled or closed.
            $tables=$this->pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
                AND TABLE_NAME IN ("finance_closings","finance_reconciliations")')->fetchAll(PDO::FETCH_COLUMN);
            if(in_array('finance_closings',$tables,true)){
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM finance_closings
                    WHERE church_id=? AND period_start<=? AND period_end>=?
                      AND (account_id IS NULL OR account_id=?)');
                $q->execute([$cid,$entry['transaction_date'],$entry['transaction_date'],$entry['account_id']]);
                if((int)$q->fetchColumn()>0)
                    throw new RuntimeException('Lançamento pertence a um fechamento financeiro. Revise o fechamento antes.');
            }
            if(in_array('finance_reconciliations',$tables,true) && !empty($entry['account_id'])){
                $q=$this->pdo->prepare("SELECT COUNT(*) FROM finance_reconciliations
                    WHERE church_id=? AND account_id=? AND reference_date>=? AND status NOT IN ('open','pending')");
                $q->execute([$cid,$entry['account_id'],$entry['transaction_date']]);
                if((int)$q->fetchColumn()>0)
                    throw new RuntimeException('Lançamento afeta período conciliado. Solicite revisão contábil.');
            }
            $snapshot=['format'=>'master-single-finance-entry-v1','church_id'=>$cid,'entry_id'=>$entryId,
                'created_at'=>gmdate('c'),'operator'=>$actor,'reason'=>$reason,'entry'=>$entry,'related'=>[]];
            foreach(['finance_documents','finance_entry_items','expense_receipts','finance_receipts',
                       'finance_receipt_drafts','finance_governance_history','finance_governance_approvals',
                       'finance_governance_matches','finance_governance_batch_items','finance_governance_entries'] as $table){
                $q=$this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $q->execute([$table]);if(!$q->fetchColumn())continue;
                $column=in_array($table,['finance_receipts','finance_receipt_drafts'],true)?'finance_entry_id':'entry_id';
                $q=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE church_id=? AND '.$column.'=?');
                $q->execute([$cid,$entryId]);$snapshot['related'][$table]=$q->fetchAll(PDO::FETCH_ASSOC);
            }
            $dir=rtrim($this->root,'/').'/private-finance-backups';
            if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
                throw new RuntimeException('Não foi possível preparar o backup privado.');
            if(!is_writable($dir))throw new RuntimeException('Pasta de backups não permite gravação.');
            $file='unico-'.preg_replace('/[^A-Za-z0-9-]/','',$entryId).'-'.
                gmdate('YmdHis').'-'.bin2hex(random_bytes(6)).'.json';
            $path=$dir.'/'.$file;
            $serialized=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(@file_put_contents($path,$serialized,LOCK_EX)!==strlen($serialized)
                ||hash_file('sha256',$path)!==hash('sha256',$serialized))
                throw new RuntimeException('O backup não foi verificado. Nenhuma movimentação excluída.');
            @chmod($path,0600);
            // Reversal is a logical exclusion: no longer part of cash balance,
            // while fiscal documents, receipts and audit remain available.
            $q=$this->pdo->prepare("UPDATE finance_entries SET status='reversed',reversed_at=NOW(),
                reversal_reason=?,reversed_by_user_id=? WHERE id=? AND church_id=? AND status='posted'");
            $q->execute(['[EXCLUSAO MASTER] '.$reason,$actor,$entryId,$cid]);
            if($q->rowCount()!==1)
                throw new RuntimeException('Não foi possível retirar o lançamento; nenhum valor alterado.');
            $this->pdo->prepare('INSERT INTO platform_audit_logs
                (id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                ->execute([app_uuid(),$actor,$cid,'master_single_finance_entry_excluded',
                    json_encode(['entry_id'=>$entryId,'reason'=>$reason,'amount'=>$entry['amount'],
                        'direction'=>$entry['direction'],'backup'=>$file,
                        'backup_sha256'=>hash_file('sha256',$path),'items_preserved'=>true],
                        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return ['entry_id'=>$entryId,'amount'=>$entry['amount'],'backup'=>$file];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
