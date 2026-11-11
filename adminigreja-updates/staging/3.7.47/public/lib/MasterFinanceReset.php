<?php
declare(strict_types=1);
final class MasterFinanceReset {
    public function __construct(private PDO $db, private string $base) {}
    private function tables(): array {
        $q=$this->db->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='church_id' AND (TABLE_NAME LIKE 'finance\\_%' OR TABLE_NAME='expense_receipts') ORDER BY TABLE_NAME");
        $names=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($names as $name)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Tabela inesperada. Reinício impedido.');
        return $names;
    }
    public function counts(string $cid): array {
        $out=[];
        foreach($this->tables() as $table){
            $q=$this->db->prepare("SELECT COUNT(*) FROM $table WHERE church_id=?");
            $q->execute([$cid]);$out[$table]=(int)$q->fetchColumn();
        }
        return $out;
    }
    public function run(string $cid,string $confirmation,string $actor): array {
        $q=$this->db->prepare('SELECT name FROM churches WHERE id=?');$q->execute([$cid]);
        $name=$q->fetchColumn();
        if(!$name||!hash_equals((string)$name,trim($confirmation)))throw new RuntimeException('Digite o nome exato da igreja selecionada.');
        $lock='im-finreset-'.substr(hash('sha256',$cid),0,24);
        $q=$this->db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Há outro procedimento em andamento.');
        try{return $this->execute($cid,$actor);}finally{
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
    /**
     * Zera SOMENTE movimentações financeiras da igreja.
     * Preserva contas/caixas, membros, departamentos e outras igrejas.
     * Backups são gerados antes do DELETE, com transação e checagens de FK.
     */
    public function clearEntriesOnly(string $cid,string $confirmation,string $actor): array {
        $q=$this->db->prepare('SELECT name FROM churches WHERE id=?');
        $q->execute([$cid]);$name=$q->fetchColumn();
        if(!$name||!hash_equals((string)$name,trim($confirmation)))
            throw new RuntimeException('Digite exatamente o nome da igreja selecionada.');
        $lockName='im-finreset-'.substr(hash('sha256',$cid),0,24);
        $q=$this->db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Outro procedimento financeiro está em andamento.');
        try{return $this->clearEntriesLocked($cid,$actor);}
        finally {$this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
    }

    private function clearEntriesLocked(string $cid,string $actor): array {
        // Known contribution_requests FK is included; unknown dependencies still block.
        // Fail closed if an unknown table references finance_entries:
        // do not disable foreign keys or allow silent cascading.
        $dependent=$this->db->query("SELECT TABLE_NAME, COLUMN_NAME, DELETE_RULE
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            JOIN information_schema.KEY_COLUMN_USAGE kcu
              ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA
             AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME
             AND kcu.TABLE_NAME=rc.TABLE_NAME
            WHERE rc.CONSTRAINT_SCHEMA=DATABASE()
              AND rc.REFERENCED_TABLE_NAME='finance_entries'")->fetchAll(PDO::FETCH_ASSOC);
        $known=['finance_documents','finance_receipts','expense_receipts','finance_receipt_drafts','contribution_requests'];
        foreach($dependent as $dep){
            if(!in_array((string)$dep['TABLE_NAME'],$known,true))
                throw new RuntimeException('Existe uma relação financeira adicional ('.$dep['TABLE_NAME'].'). Reinício suspenso para revisão segura.');
        }
        $tables=$this->tables();
        $required=['finance_entries','finance_accounts'];
        foreach($required as $table)if(!in_array($table,$tables,true))
            throw new RuntimeException('Estrutura financeira incompleta. Nenhum lançamento foi apagado.');
        // Preserve accounts as well as their opening balances; balances can only be
        // zero after reset if opening balances were already zero.
        // Somente movimentos e documentos diretamente ligados a eles.
        // Contas, caixas, recibos independentes e demais cadastros são mantidos.
        // O formulário público via QR registra pedidos e eventos vinculados
        // a finance_entries. Eles também precisam ser incluídos no backup e
        // excluídos, somente quando relacionados aos lançamentos desta igreja.
        // Catálogos/campanhas de contribuições NÃO são removidos.
        $hasContributionRequests=$this->db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contribution_requests'")->fetchColumn()>0;
        $hasContributionEvents=$this->db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contribution_events'")->fetchColumn()>0;
        if($hasContributionRequests && !$hasContributionEvents){
            // instalações antigas podem não ter histórico de eventos
        } elseif($hasContributionEvents && !$hasContributionRequests){
            throw new RuntimeException('Estrutura de contribuições incompleta. Nenhum lançamento removido.');
        }
        $clear=['contribution_events','contribution_requests','finance_documents',
            'expense_receipts','finance_receipts','finance_receipt_drafts','finance_entries'];
        $clear=array_values(array_filter($clear,static function(string $table) use ($tables,$hasContributionRequests,$hasContributionEvents): bool {
            if($table==='contribution_requests')return $hasContributionRequests;
            if($table==='contribution_events')return $hasContributionEvents;
            return in_array($table,$tables,true);
        }));
        $predicates=[
            'contribution_events'=>"church_id=? AND request_id IN (
                SELECT id FROM contribution_requests WHERE church_id=? AND finance_entry_id IN
                (SELECT id FROM finance_entries WHERE church_id=?))",
            'contribution_requests'=>"church_id=? AND finance_entry_id IN
                (SELECT id FROM finance_entries WHERE church_id=?)",
            'finance_documents'=>'church_id=? AND entry_id IN (SELECT id FROM finance_entries WHERE church_id=?)',
            'expense_receipts'=>'church_id=? AND entry_id IN (SELECT id FROM finance_entries WHERE church_id=?)',
            'finance_receipts'=>'church_id=? AND finance_entry_id IN (SELECT id FROM finance_entries WHERE church_id=?)',
            'finance_receipt_drafts'=>'church_id=? AND finance_entry_id IN (SELECT id FROM finance_entries WHERE church_id=?)',
            'finance_entries'=>'church_id=?',
        ];
        $arguments=[
            'contribution_events'=>[$cid,$cid,$cid],
            'contribution_requests'=>[$cid,$cid],
            'finance_documents'=>[$cid,$cid],
            'expense_receipts'=>[$cid,$cid],
            'finance_receipts'=>[$cid,$cid],
            'finance_receipt_drafts'=>[$cid,$cid],
            'finance_entries'=>[$cid],
        ];
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT name FROM churches WHERE id=? FOR UPDATE');
            $q->execute([$cid]);$name=$q->fetchColumn();
            if(!$name)throw new RuntimeException('Igreja não encontrada.');
            // Fechamentos e conciliações são registros contábeis válidos; nunca
            // apagá-los silenciosamente junto das movimentações.
            foreach(['finance_closings','finance_reconciliations'] as $control){
                if(!in_array($control,$tables,true))continue;
                $q=$this->db->prepare("SELECT COUNT(*) FROM `$control` WHERE church_id=?");
                $q->execute([$cid]);
                if((int)$q->fetchColumn()>0)
                    throw new RuntimeException('A igreja possui fechamentos ou conciliações. Revise esses registros antes de zerar somente lançamentos.');
            }
            $snapshot=['format'=>'igreja-master-only-entries-v2',
                'church_id'=>$cid,'church_name'=>$name,'created_at'=>gmdate('c'),
                'preserved'=>['finance_accounts','finance_payables','finance_closings',
                    'finance_reconciliations','members','ministry_departments','church_members'],
                'opening_balances_preserved'=>true,'tables'=>[]];
            foreach($clear as $table){
                $q=$this->db->prepare("SELECT * FROM `$table` WHERE ".$predicates[$table]);
                $q->execute($arguments[$table]);$snapshot['tables'][$table]=$q->fetchAll(PDO::FETCH_ASSOC);
            }
            // Impede que cascatas desconhecidas excluam registros de outras áreas.
            $allowedChildren=array_fill_keys($clear,true);
            $q=$this->db->query("SELECT DISTINCT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
                AND REFERENCED_TABLE_NAME IS NOT NULL");
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $fk){
                if(isset($allowedChildren[$fk['REFERENCED_TABLE_NAME']])&&!isset($allowedChildren[$fk['TABLE_NAME']]))
                    throw new RuntimeException('Reinício impedido por vínculo externo com '.$fk['TABLE_NAME'].'. Nenhum registro apagado.');
            }
            $dir=rtrim($this->base,'/').'/private-finance-backups';
            if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
                throw new RuntimeException('Falha ao criar pasta privada de backups.');
            if(!is_writable($dir))throw new RuntimeException('Pasta privada de backups sem permissão de gravação.');
            $filename='movimentos-'.preg_replace('/[^A-Za-z0-9_-]/','',$cid).'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(5)).'.json';
            $path=$dir.'/'.$filename;
            $payload=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if(@file_put_contents($path,$payload,LOCK_EX)!==strlen($payload)
                ||hash_file('sha256',$path)!==hash('sha256',$payload))
                throw new RuntimeException('Falha ao criar ou verificar backup. Nenhum registro foi apagado.');
            @chmod($path,0600);
            $removed=[];
            foreach($clear as $table){
                $stmt=$this->db->prepare("DELETE FROM `$table` WHERE ".$predicates[$table]);
                $stmt->execute($arguments[$table]);$removed[$table]=$stmt->rowCount();
            }
            $this->db->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                ->execute([app_uuid(),$actor,$cid,'master_finance_entries_reset',
                    json_encode(['backup'=>$filename,'sha256'=>hash_file('sha256',$path),
                        'removed'=>$removed,'accounts_preserved'=>true],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['deleted'=>array_sum($removed),'backup'=>$filename];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private function execute(string $cid,string $actor): array {
        $tables=$this->tables();
        if(!$tables)throw new RuntimeException('Nenhuma tabela financeira localizada.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT name FROM churches WHERE id=? FOR UPDATE');
            $q->execute([$cid]);$name=$q->fetchColumn();
            if(!$name)throw new RuntimeException('Igreja não encontrada.');
            $dependencies=[];foreach($tables as $table)$dependencies[$table]=[];
            $fk=$this->db->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
            foreach($fk as $row){
                $child=$row['TABLE_NAME'];$parent=$row['REFERENCED_TABLE_NAME'];
                if(isset($dependencies[$parent])&&isset($dependencies[$child])&&$child!==$parent)$dependencies[$parent][$child]=1;
                elseif(isset($dependencies[$parent])&&!isset($dependencies[$child]))
                    throw new RuntimeException('Reinício bloqueado por dependência em '.$child.'. Nenhum dado removido.');
            }
            $order=[];$state=[];
            $walk=function($table)use(&$walk,&$order,&$state,$dependencies){
                if(($state[$table]??0)===1)throw new RuntimeException('Dependência circular. Reinício bloqueado.');
                if(($state[$table]??0)===2)return;
                $state[$table]=1;
                foreach(array_keys($dependencies[$table]) as $child)$walk($child);
                $state[$table]=2;$order[]=$table;
            };
            foreach($tables as $table)$walk($table);
            $snapshot=['format'=>'igreja-master-finance-v1','church_id'=>$cid,'name'=>$name,'generated_at'=>date('c'),'tables'=>[],'documents'=>'Arquivos físicos preservados no storage'];
            foreach($tables as $table){
                $q=$this->db->prepare("SELECT * FROM $table WHERE church_id=?");
                $q->execute([$cid]);$snapshot['tables'][$table]=$q->fetchAll(PDO::FETCH_ASSOC);
            }
            $dir=rtrim($this->base,'/').'/private-finance-backups';
            if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar a pasta privada de backups.');
            if(!is_writable($dir))throw new RuntimeException('Backup não autorizado: pasta sem permissão de gravação.');
            $data=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $filename='reset-'.preg_replace('/[^a-z0-9-]/i','',$cid).'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(5)).'.json';
            $filepath=$dir.'/'.$filename;
            if(@file_put_contents($filepath,$data,LOCK_EX)!==strlen($data)||hash_file('sha256',$filepath)!==hash('sha256',$data))
                throw new RuntimeException('Falha na verificação do backup. Dados preservados.');
            @chmod($filepath,0600);
            $deleted=[];
            foreach($order as $table){
                $q=$this->db->prepare("DELETE FROM $table WHERE church_id=?");
                $q->execute([$cid]);$deleted[$table]=$q->rowCount();
            }
            $this->db->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')->execute([
                app_uuid(),$actor,$cid,'master_finance_reset',
                json_encode(['backup'=>$filename,'sha256'=>hash_file('sha256',$filepath),'deleted'=>$deleted,'attachments_preserved'=>true],JSON_THROW_ON_ERROR)
            ]);
            $this->db->commit();
            return ['backup'=>$filename,'deleted'=>array_sum($deleted)];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
