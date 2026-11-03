<?php
declare(strict_types=1);
/** Single church member-to-department assignment and leader scope service. */
final class MemberDepartmentService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid,private string $actor){
        if($cid===''||$actor===''||($auth->currentChurch()['id']??'')!==$cid||
           !($auth->can('secretary')||$auth->can('manage_users')))
            throw new RuntimeException('Acesso negado à organização dos departamentos.');
    }
    private function q(string $sql,array $args=[]):PDOStatement {
        $q=$this->pdo->prepare($sql);$q->execute($args);return $q;
    }
    public static function ensure(PDO $pdo):void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS member_department_roles (
          church_id CHAR(36) NOT NULL, member_id CHAR(36) NOT NULL, department_id CHAR(36) NOT NULL,
          role_name VARCHAR(25) NOT NULL DEFAULT 'participant', assigned_by_user_id CHAR(36) NOT NULL,
          assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY(church_id,member_id,department_id),
          KEY idx_member_department_scope(church_id,department_id,role_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function departments():array {
        return $this->q('SELECT id,name,active FROM ministry_departments WHERE church_id=? ORDER BY active DESC,name',[$this->cid])
            ->fetchAll(PDO::FETCH_ASSOC);
    }
    public function members():array {
        return $this->q('SELECT id,name,email FROM members WHERE church_id=? AND active=1 ORDER BY name LIMIT 3000',[$this->cid])
            ->fetchAll(PDO::FETCH_ASSOC);
    }
    public function assignments(?string $departmentId=null):array {
        $where='r.church_id=?';$args=[$this->cid];
        if($departmentId!==null&&$departmentId!==''){$this->department($departmentId);$where.=' AND r.department_id=?';$args[]=$departmentId;}
        return $this->q("SELECT r.member_id,r.department_id,r.role_name,m.name member_name,d.name department_name,
              cm.user_id,cm.church_role
            FROM member_department_roles r
            JOIN members m ON m.id=r.member_id AND m.church_id=r.church_id
            JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id
            LEFT JOIN church_members cm ON cm.person_id=m.id AND cm.church_id=m.church_id
            WHERE $where ORDER BY d.name,CASE WHEN r.role_name='leader' THEN 0 ELSE 1 END,m.name",$args)
            ->fetchAll(PDO::FETCH_ASSOC);
    }
    public function forMember(string $memberId):array {
        $this->member($memberId,false);
        return $this->q('SELECT department_id,role_name FROM member_department_roles
           WHERE church_id=? AND member_id=?',[$this->cid,$memberId])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function department(string $id):array {
        $x=$this->q('SELECT id,name,active FROM ministry_departments WHERE church_id=? AND id=?',[$this->cid,$id])
          ->fetch(PDO::FETCH_ASSOC);
        if(!$x)throw new InvalidArgumentException('Departamento não pertence a esta igreja.');
        return $x;
    }
    public function member(string $id,bool $requireActive=true):array {
        $x=$this->q('SELECT id,name,active FROM members WHERE church_id=? AND id=?',[$this->cid,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$x||($requireActive&&!$x['active']))throw new InvalidArgumentException('Membro não encontrado ou inativo nesta igreja.');
        return $x;
    }
    private function audit(string $kind,array $details):void {
        $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[
           app_uuid(),$this->actor,$this->cid,$kind,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    private function legacyMember(string $memberId,string $departmentId):void {
        // Keep pre-existing department counters in sync with the new assignments.
        // Legacy installations have ministry_members; no destructive recreation.
        $cols=$this->q("SELECT COLUMN_NAME,COLUMN_DEFAULT,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ministry_members'")->fetchAll(PDO::FETCH_ASSOC);
        if(!$cols)return;
        $available=array_column($cols,'COLUMN_NAME');
        foreach(['member_id','department_id','church_id'] as $required)if(!in_array($required,$available,true))return;
        $has=$this->q('SELECT 1 FROM ministry_members WHERE church_id=? AND member_id=? AND department_id=?',
            [$this->cid,$memberId,$departmentId])->fetchColumn();
        if($has)return;
        $fields=['church_id'=>$this->cid,'member_id'=>$memberId,'department_id'=>$departmentId];
        if(in_array('id',$available,true))$fields['id']=app_uuid();
        if(in_array('active',$available,true))$fields['active']=1;
        if(in_array('created_by_user_id',$available,true))$fields['created_by_user_id']=$this->actor;
        if(in_array('updated_by_user_id',$available,true))$fields['updated_by_user_id']=$this->actor;
        $keys=array_keys($fields);
        // Avoid inserting untrusted identifiers: all columns above are hardcoded.
        $this->q('INSERT INTO ministry_members('.implode(',',$keys).') VALUES('.
            implode(',',array_fill(0,count($keys),'?')).')',array_values($fields));
    }
    private function syncLeaderLogin(string $memberId,string $departmentId):bool {
        $logins=$this->q("SELECT user_id FROM church_members WHERE church_id=? AND person_id=?
            AND church_role='department_leader'",[$this->cid,$memberId])->fetchAll(PDO::FETCH_COLUMN);
        foreach($logins as $uid)
            $this->q('INSERT IGNORE INTO department_access(church_id,user_id,department_id) VALUES(?,?,?)',
                [$this->cid,$uid,$departmentId]);
        return count($logins)>0;
    }
    public function assign(string $memberId,string $departmentId,string $role):string {
        if(!in_array($role,['participant','leader'],true))throw new InvalidArgumentException('Função inválida.');
        if($role==='leader'&&!$this->auth->can('manage_users'))throw new RuntimeException('Somente pastor ou administrador autorizado pode designar líderes.');
        $this->member($memberId);
        $dept=$this->department($departmentId);
        if(empty($dept['active']))throw new InvalidArgumentException('Departamento inativo.');
        $outer=$this->pdo->inTransaction();
        if(!$outer)$this->pdo->beginTransaction();
        try {
            $this->q("INSERT INTO member_department_roles(church_id,member_id,department_id,role_name,assigned_by_user_id)
                VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),
                assigned_by_user_id=VALUES(assigned_by_user_id)",[
                $this->cid,$memberId,$departmentId,$role,$this->actor
            ]);
            $this->legacyMember($memberId,$departmentId);
            $active=$role==='leader'?$this->syncLeaderLogin($memberId,$departmentId):false;
            $this->audit('member_department_assigned',[
                'member_id'=>$memberId,'department_id'=>$departmentId,'role'=>$role,'login_active'=>$active
            ]);
            if(!$outer)$this->pdo->commit();
            return $role==='leader'&& !$active?'pending_login':'saved';
        }catch(Throwable $e){
            if(!$outer&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
    public function remove(string $memberId,string $departmentId):void {
        $this->member($memberId,false);$this->department($departmentId);
        $current=$this->q('SELECT role_name FROM member_department_roles WHERE church_id=? AND member_id=? AND department_id=?',[$this->cid,$memberId,$departmentId])->fetchColumn();
        if($current==='leader'&&!$this->auth->can('manage_users'))throw new RuntimeException('Somente pastor ou administrador pode remover uma liderança.');
        $outer=$this->pdo->inTransaction();if(!$outer)$this->pdo->beginTransaction();
        try{
            $this->q('DELETE FROM member_department_roles WHERE church_id=? AND member_id=? AND department_id=?',
                [$this->cid,$memberId,$departmentId]);
            // Scope cleanup: never remove an unrelated leader's access.
            $uids=$this->q("SELECT user_id FROM church_members WHERE church_id=? AND person_id=?
               AND church_role='department_leader'",[$this->cid,$memberId])->fetchAll(PDO::FETCH_COLUMN);
            foreach($uids as $uid)
               $this->q('DELETE FROM department_access WHERE church_id=? AND user_id=? AND department_id=?',
                   [$this->cid,$uid,$departmentId]);
            $this->q('DELETE FROM ministry_members WHERE church_id=? AND member_id=? AND department_id=?',
                [$this->cid,$memberId,$departmentId]);
            $this->audit('member_department_removed',['member_id'=>$memberId,'department_id'=>$departmentId]);
            if(!$outer)$this->pdo->commit();
        }catch(Throwable $e){if(!$outer&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
