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
