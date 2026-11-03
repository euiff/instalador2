<?php
declare(strict_types=1);
require_once __DIR__.'/EnuvesImportService.php';

final class EnuvesLeadershipService
{
    private EnuvesImportService $import;
    public function __construct(private PDO $pdo,private Auth $auth,private string $actorId){
        if(!$auth->isSuperAdmin())throw new RuntimeException('Acesso exclusivo ao Master.');
        $this->import=new EnuvesImportService($pdo,$auth,$actorId);
    }
    private function q(string $s,array $params=[]):PDOStatement{
        $q=$this->pdo->prepare($s);$q->execute($params);return $q;
    }
    public function departments(string $cid):array{
        $this->import->church($cid);
        return $this->q('SELECT d.id,d.name,
            (SELECT COUNT(*) FROM enuves_department_interests i WHERE i.church_id=d.church_id AND i.department_id=d.id) interested,
            (SELECT COUNT(*) FROM enuves_department_leaders l WHERE l.church_id=d.church_id AND l.department_id=d.id) leaders
            FROM ministry_departments d WHERE d.church_id=? AND d.active=1 ORDER BY d.name',
            [$cid])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function people(string $cid):array{
        $this->import->church($cid);
        return $this->q('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name LIMIT 1800',
            [$cid])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function designated(string $cid):array{
        $this->import->church($cid);
        return $this->q('SELECT d.name department_name,m.name member_name,l.status
            FROM enuves_department_leaders l
            JOIN ministry_departments d ON d.church_id=l.church_id AND d.id=l.department_id
            JOIN members m ON m.church_id=l.church_id AND m.id=l.member_id
            WHERE l.church_id=? ORDER BY d.name,m.name',[$cid])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function assign(string $cid,string $did,string $mid):string{
        $this->import->church($cid);
        if(!$this->q('SELECT 1 FROM ministry_departments WHERE church_id=? AND id=? AND active=1',
            [$cid,$did])->fetchColumn())throw new InvalidArgumentException('Departamento não pertence à igreja.');
        if(!$this->q('SELECT 1 FROM members WHERE church_id=? AND id=? AND active=1',
            [$cid,$mid])->fetchColumn())throw new InvalidArgumentException('Membro não pertence à igreja ou está inativo.');
        $this->pdo->beginTransaction();
        try{
            // Mark a leader designation only after manual, explicit selection by Master.
            $this->q("INSERT INTO enuves_department_leaders
                (church_id,department_id,member_id,status,assigned_by_user_id)
                VALUES(?,?,?,'pending_login',?)
                ON DUPLICATE KEY UPDATE assigned_at=NOW(),assigned_by_user_id=VALUES(assigned_by_user_id)",
                [$cid,$did,$mid,$this->actorId]);
            $status='pending_login';
            $users=$this->q("SELECT user_id FROM church_members WHERE church_id=? AND person_id=?
                AND church_role='department_leader'",[$cid,$mid])->fetchAll(PDO::FETCH_COLUMN);
            foreach($users as $uid){
                $this->q('INSERT IGNORE INTO department_access(church_id,user_id,department_id) VALUES(?,?,?)',
                    [$cid,$uid,$did]);
                $status='active';
            }
            $this->q('UPDATE enuves_department_leaders SET status=? WHERE church_id=? AND department_id=? AND member_id=?',
                [$status,$cid,$did,$mid]);
            $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',
                [app_uuid(),$this->actorId,$cid,'enuves_department_leader_assigned',
                    json_encode(['department_id'=>$did,'member_id'=>$mid,'status'=>$status],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return $status;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
