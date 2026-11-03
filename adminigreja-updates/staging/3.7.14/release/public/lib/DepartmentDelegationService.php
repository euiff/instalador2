<?php
declare(strict_types=1);
require_once __DIR__.'/DepartmentRoleAccess.php';

/**
 * Leaders appoint treasurers ONLY among current participants of departments they lead.
 * This role is NOT church-wide treasurer. It never grants finance/manage_users.
 */
final class DepartmentDelegationService
{
    private bool $admin;
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid,private string $actor)
    {
        if($actor===''||($auth->currentChurch()['id']??'')!==$cid)throw new RuntimeException('Igreja inválida.');
        $this->admin=$auth->can('manage_users');
        if(!$this->admin&&!DepartmentRoleAccess::isLeader($pdo,$cid,$actor))
            throw new RuntimeException('Somente um líder do departamento ou a administração pode indicar o tesoureiro.');
    }
    private function q(string $sql,array $args=[]):PDOStatement{
        $q=$this->pdo->prepare($sql);$q->execute($args);return $q;
    }
    private function authorize(string $departmentId):void
    {
        if(!preg_match('/^[a-f0-9-]{36}$/iD',$departmentId))
            throw new InvalidArgumentException('Departamento inválido.');
        $department=$this->q('SELECT id FROM ministry_departments WHERE church_id=? AND id=? AND active=1',
            [$this->cid,$departmentId])->fetchColumn();
        if(!$department)throw new RuntimeException('Departamento inexistente ou inativo.');
        if(!$this->admin&&!DepartmentRoleAccess::isLeader($this->pdo,$this->cid,$this->actor,$departmentId))
            throw new RuntimeException('Você só pode gerenciar o tesoureiro de departamentos que lidera.');
    }
    public function departments():array
    {
        if($this->admin)return $this->q('SELECT id,name,active FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name',
            [$this->cid])->fetchAll(PDO::FETCH_ASSOC);
        return DepartmentRoleAccess::listLedDepartments($this->pdo,$this->cid,$this->actor);
    }
    public function team(string $departmentId):array
    {
        $this->authorize($departmentId);
        return $this->q("SELECT r.member_id,r.department_id,r.role_name,m.name,
             EXISTS(SELECT 1 FROM church_members cm JOIN users u ON u.id=cm.user_id AND u.active=1
                 WHERE cm.church_id=r.church_id AND cm.person_id=r.member_id) login_active
          FROM member_department_roles r
          JOIN members m ON m.church_id=r.church_id AND m.id=r.member_id AND m.active=1
          WHERE r.church_id=? AND r.department_id=?
          ORDER BY FIELD(r.role_name,'leader','treasurer','participant'),m.name",
          [$this->cid,$departmentId])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function setTreasurer(string $departmentId,string $memberId,bool $appoint):string
    {
        $this->authorize($departmentId);
        if(!preg_match('/^[a-f0-9-]{36}$/iD',$memberId))throw new InvalidArgumentException('Membro inválido.');
        $this->pdo->beginTransaction();
        try{
            // Re-check authority while applying; the operation is tenant scoped.
            if(!$this->admin&&!DepartmentRoleAccess::isLeader($this->pdo,$this->cid,$this->actor,$departmentId))
                throw new RuntimeException('Sua liderança deste departamento foi alterada.');
            $row=$this->q("SELECT r.role_name
                FROM member_department_roles r
                JOIN members m ON m.id=r.member_id AND m.church_id=r.church_id AND m.active=1
                WHERE r.church_id=? AND r.department_id=? AND r.member_id=? FOR UPDATE",
                [$this->cid,$departmentId,$memberId])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Primeiro vincule a pessoa como participante deste departamento.');
            if($row['role_name']==='leader')
                throw new RuntimeException('Não é possível substituir a função de líder pela função de tesoureiro.');
            if(!in_array($row['role_name'],['participant','treasurer'],true))
                throw new RuntimeException('Função departamental desconhecida.');
            $next=$appoint?'treasurer':'participant';
            if($row['role_name']===$next)throw new RuntimeException('O membro já possui essa função neste departamento.');
            $this->q('UPDATE member_department_roles SET role_name=?,assigned_by_user_id=?
                WHERE church_id=? AND department_id=? AND member_id=?',
                [$next,$this->actor,$this->cid,$departmentId,$memberId]);
            $active=(bool)$this->q('SELECT 1 FROM church_members cm JOIN users u ON u.id=cm.user_id AND u.active=1
                WHERE cm.church_id=? AND cm.person_id=? LIMIT 1',
                [$this->cid,$memberId])->fetchColumn();
            $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',
                [app_uuid(),$this->actor,$this->cid,
                 $appoint?'department_treasurer_appointed':'department_treasurer_revoked',
                 json_encode(['department_id'=>$departmentId,'member_id'=>$memberId,'login_active'=>$active],
                    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return $appoint?($active?'active':'pending_login'):'revoked';
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
