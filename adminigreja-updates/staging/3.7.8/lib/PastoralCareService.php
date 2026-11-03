<?php
declare(strict_types=1);

/**
 * Staging-only service: caller MUST authenticate and authorize the actor.
 * churchId MUST be taken from the server-side session, NEVER from POST/GET.
 * Confidential notes MUST NOT appear in ordinary member or visitor portals.
 */
final class PastoralCareService
{
    public function __construct(private PDO $db) {}

    public function createCase(int $churchId, int $actorId, string $subject, ?int $assignedUserId = null, ?int $personId = null, ?int $visitorId = null): int
    {
        if ($churchId < 1 || $actorId < 1 || trim($subject) === '' || mb_strlen($subject) > 180) {
            throw new InvalidArgumentException('Invalid case parameters');
        }
        $sql = 'INSERT INTO pastoral_cases (church_id,created_by,subject,assigned_user_id,person_id,visitor_id)
                VALUES (:church,:actor,:subject,:assignee,:person,:visitor)';
        $this->db->prepare($sql)->execute([
            ':church'=>$churchId, ':actor'=>$actorId, ':subject'=>trim($subject),
            ':assignee'=>$assignedUserId, ':person'=>$personId, ':visitor'=>$visitorId
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->audit($churchId,$actorId,$id,'create');
        return $id;
    }

    public function assign(int $churchId, int $actorId, int $caseId, ?int $assigneeId): bool
    {
        $stmt = $this->db->prepare('UPDATE pastoral_cases SET assigned_user_id=:assignee
            WHERE church_id=:church AND id=:id AND deleted_at IS NULL');
        $stmt->execute([':assignee'=>$assigneeId,':church'=>$churchId,':id'=>$caseId]);
        if ($stmt->rowCount() === 0) return false;
        $this->audit($churchId,$actorId,$caseId,'assign');
        return true;
    }

    public function schedule(int $churchId, int $actorId, int $caseId, string $dateTime): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $dateTime) throw new InvalidArgumentException('Invalid datetime');
        $stmt=$this->db->prepare("UPDATE pastoral_cases SET next_contact_at=:date,status='scheduled'
            WHERE church_id=:church AND id=:id AND deleted_at IS NULL AND status NOT IN ('closed','cancelled')");
        $stmt->execute([':date'=>$dateTime,':church'=>$churchId,':id'=>$caseId]);
        if ($stmt->rowCount() === 0) return false;
        $this->audit($churchId,$actorId,$caseId,'schedule');
        return true;
    }

    public function recordEvent(int $churchId, int $actorId, int $caseId, string $kind, string $summary, bool $confidential = true): bool
    {
        if (!in_array($kind,['note','visit','call','message','status_change'],true)) throw new InvalidArgumentException('Invalid kind');
        $exists=$this->db->prepare('SELECT id FROM pastoral_cases WHERE church_id=:church AND id=:id AND deleted_at IS NULL');
        $exists->execute([':church'=>$churchId,':id'=>$caseId]);
        if (!$exists->fetchColumn()) return false;
        $this->db->prepare('INSERT INTO pastoral_case_events
            (church_id,case_id,kind,happened_at,summary,is_confidential,recorded_by)
            VALUES (:church,:id,:kind,NOW(),:summary,:confidential,:actor)')->execute([
                ':church'=>$churchId,':id'=>$caseId,':kind'=>$kind,':summary'=>$summary,
                ':confidential'=>(int)$confidential,':actor'=>$actorId
            ]);
        $this->audit($churchId,$actorId,$caseId,'event:'.$kind);
        return true;
    }

    public function dueFollowups(int $churchId, ?int $assignedUserId = null, int $limit = 100): array
    {
        $limit = max(1,min(200,$limit));
        $sql="SELECT id,person_id,visitor_id,assigned_user_id,subject,status,next_contact_at
                FROM pastoral_cases WHERE church_id=:church AND deleted_at IS NULL
                AND status IN ('open','scheduled','in_progress')
                AND next_contact_at IS NOT NULL AND next_contact_at <= NOW()";
        $args=[':church'=>$churchId];
        if ($assignedUserId !== null) { $sql.=' AND assigned_user_id=:assignee'; $args[':assignee']=$assignedUserId; }
        $sql.=' ORDER BY next_contact_at ASC,id ASC LIMIT '.$limit;
        $stmt=$this->db->prepare($sql); $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function audit(int $churchId, int $actorId, int $caseId, string $action): void
    {
        $this->db->prepare('INSERT INTO pastoral_audit_log (church_id,case_id,actor_user_id,action)
            VALUES (:church,:case,:actor,:action)')->execute([
                ':church'=>$churchId,':case'=>$caseId,':actor'=>$actorId,':action'=>$action
            ]);
    }
}
