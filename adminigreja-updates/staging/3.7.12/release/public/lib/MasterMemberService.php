<?php
declare(strict_types=1);

/** Master-only, church-scoped archive / restoration and guarded permanent deletion. */
final class MasterMemberService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $actorId){
        if(!$auth->isSuperAdmin()||$actorId==='')throw new RuntimeException('Acesso exclusivo Master.');
    }
    private function q(string $sql,array $args=[]):PDOStatement{
        $q=$this->pdo->prepare($sql);$q->execute($args);return $q;
    }
    public function members(string $cid,string $search='',int $page=1):array{
        $church=$this->q('SELECT 1 FROM churches WHERE id=?',[$cid])->fetchColumn();
        if(!$church)throw new RuntimeException('Igreja não encontrada.');
        $search=trim($search);
        if(mb_strlen($search)>100)throw new InvalidArgumentException('Busca muito longa.');
        $limit=50;$page=max(1,min($page,10000));$params=[$cid];
        $where='church_id=?';
        if($search!==''){
            $where.=' AND (name LIKE ? OR phone LIKE ?)';
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $params[]=$like;$params[]=$like;
        }
        $total=(int)$this->q('SELECT COUNT(*) FROM members WHERE '.$where,$params)->fetchColumn();
        $pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);
        $list=$this->q('SELECT id,name,phone,birth_date,active FROM members WHERE '.$where.
            ' ORDER BY name,id LIMIT 50 OFFSET '.(($page-1)*$limit),$params)->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$list,'page'=>$page,'pages'=>$pages,'total'=>$total];
    }
    private function audit(string $cid,string $mid,string $action,string $reason):void{
        $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',
            [app_uuid(),$this->actorId,$cid,$action,
            json_encode(['member_id'=>$mid,'reason'=>$reason],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    public function change(string $cid,string $mid,string $action,string $typedName,string $reason):void{
        if(!in_array($action,['archive','restore','purge'],true))
            throw new InvalidArgumentException('Operação inválida.');
        $reason=trim($reason);
        if(mb_strlen($reason)<8||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Informe o motivo da alteração (8 a 500 caracteres).');
        $this->pdo->beginTransaction();
        try{
            $member=$this->q('SELECT id,name,active FROM members WHERE church_id=? AND id=? FOR UPDATE',
                [$cid,$mid])->fetch(PDO::FETCH_ASSOC);
            if(!$member)throw new RuntimeException('Membro não encontrado nesta igreja.');
            if(trim($typedName)!==(string)$member['name'])
                throw new InvalidArgumentException('Digite o nome completo exatamente como aparece no cadastro.');
            if($action==='purge'){
                if(!empty($member['active']))throw new RuntimeException('Arquive o membro antes de solicitar exclusão definitiva.');
                $references=$this->references($mid);
                if($references)throw new RuntimeException('Exclusão definitiva bloqueada: existem vínculos com outros módulos. Use Arquivar para preservar o histórico.');
                foreach(['enuves_department_leaders','enuves_department_interests','enuves_people_details','enuves_import_people'] as $table)
                    $this->q('DELETE FROM '.$table.' WHERE church_id=? AND member_id=?',[$cid,$mid]);
                $this->audit($cid,$mid,'master_member_purged',$reason);
                $this->q('DELETE FROM members WHERE church_id=? AND id=? AND active=0',[$cid,$mid]);
            }else{
                $this->q('UPDATE members SET active=? WHERE church_id=? AND id=?',
                    [$action==='restore'?1:0,$cid,$mid]);
                $this->audit($cid,$mid,$action==='restore'?'master_member_restored':'master_member_archived',$reason);
            }
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function references(string $mid):array{
        // Scan relational references, including foreign keys with ON DELETE CASCADE:
        // never let a permanent deletion silently delete attendance or pastoral history.
        $block=[];
        $excluded=['enuves_import_people','enuves_people_details','enuves_department_interests','enuves_department_leaders'];
        $rows=$this->q("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('member_id','person_id')")->fetchAll(PDO::FETCH_ASSOC);
        $keys=$this->q("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
                AND REFERENCED_TABLE_NAME='members' AND REFERENCED_COLUMN_NAME='id'")->fetchAll(PDO::FETCH_ASSOC);
        foreach(array_merge($rows,$keys) as $spec){
            $table=(string)$spec['TABLE_NAME'];$column=(string)$spec['COLUMN_NAME'];
            if(in_array($table,$excluded,true)||$table==='members')continue;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)||!preg_match('/^[A-Za-z0-9_]+$/D',$column))continue;
            if($this->q("SELECT 1 FROM `$table` WHERE `$column`=? LIMIT 1",[$mid])->fetchColumn())
                $block[$table]=true;
        }
        return array_keys($block);
    }
}
