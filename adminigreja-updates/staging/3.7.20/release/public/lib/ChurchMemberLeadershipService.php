<?php
declare(strict_types=1);

require_once __DIR__.'/MemberDepartmentService.php';

/**
 * Uses the exact existing member identity and explicitly grants leadership.
 * Participant membership alone must never authorize a department treasury.
 */
final class ChurchMemberLeadershipService
{
    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private string $churchId,
        private string $actorId
    ){
        if($churchId==='' || $actorId==='' ||
           (string)($auth->currentChurch()['id']??'')!==$churchId ||
           !$auth->can('manage_users')){
            throw new RuntimeException('Sem autorização para vincular líderes desta igreja.');
        }
    }

    private function select(string $sql,array $params):PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public function member(string $id):array
    {
        if($id==='')throw new InvalidArgumentException('Escolha um membro cadastrado.');
        $row=$this->select('SELECT id,name,email,active FROM members WHERE church_id=? AND id=? LIMIT 1',
           [$this->churchId,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row||!(bool)$row['active'])throw new RuntimeException('Membro não encontrado ou inativo nesta igreja.');
        return $row;
    }

    public function linkedLogin(string $memberId):?array
    {
        $this->member($memberId);
        $row=$this->select('SELECT cm.user_id,cm.church_role,cm.is_owner,u.email,u.active
          FROM church_members cm JOIN users u ON u.id=cm.user_id
          WHERE cm.church_id=? AND cm.person_id=? LIMIT 1',[$this->churchId,$memberId])
          ->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    public function oldDepartmentIds(string $userId):array
    {
        // Older leader appointments can exist only in member_department_roles,
        // without an entry in department_access. Include both sources so edit
        // forms preserve every existing appointment and revocation is complete.
        $sql="SELECT department_id FROM department_access WHERE church_id=? AND user_id=?
              UNION
              SELECT r.department_id FROM member_department_roles r
              JOIN church_members cm ON cm.church_id=r.church_id AND cm.person_id=r.member_id
              WHERE cm.church_id=? AND cm.user_id=? AND r.role_name='leader'";
        return array_map('strval',$this->select($sql,
            [$this->churchId,$userId,$this->churchId,$userId])->fetchAll(PDO::FETCH_COLUMN));
    }

    public function sync(
        ?string $memberId,
        string $role,
        array $newDepartmentIds,
        ?string $oldMemberId,
        array $oldDepartmentIds
    ):void {
        $newDepartmentIds=array_values(array_unique(array_filter(array_map('strval',$newDepartmentIds))));
        $oldDepartmentIds=array_values(array_unique(array_filter(array_map('strval',$oldDepartmentIds))));
        if($role==='department_leader' && (!$memberId || !$newDepartmentIds))
            throw new InvalidArgumentException('Escolha o membro e pelo menos um departamento para conceder liderança.');

        $svc=new MemberDepartmentService($this->pdo,$this->auth,$this->churchId,$this->actorId);

        // Revocation: when editing a login, an omitted department stops
        // authorizing its old leader. Preserve participation and historical
        // financial transactions instead of deleting the department member.
        if($oldMemberId){
            foreach($oldDepartmentIds as $oldId){
                if($oldMemberId===$memberId && $role==='department_leader' &&
                   in_array($oldId,$newDepartmentIds,true))continue;
                $row=$this->select(
                    'SELECT role_name FROM member_department_roles WHERE church_id=? AND member_id=? AND department_id=?',
                    [$this->churchId,$oldMemberId,$oldId])->fetchColumn();
                if($row==='leader')$svc->assign($oldMemberId,$oldId,'participant');
            }
        }

        if($role==='department_leader'){
            $this->member((string)$memberId);
            foreach($newDepartmentIds as $deptId){
                // Explicit role assignment is necessary. Merely storing a
                // department_access entry does NOT grant a linked login rights.
                $svc->assign((string)$memberId,$deptId,'leader');
            }
        }
    }
}
