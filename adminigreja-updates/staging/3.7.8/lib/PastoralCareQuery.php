<?php
declare(strict_types=1);

/**
 * Read-only pastoral queries. Every query is scoped by the authenticated church ID.
 * The caller must check access via the existing church permission system.
 */
final class PastoralCareQuery
{
    public function __construct(private PDO $db) {}

    public function listing(int $churchId, ?int $assigneeId, string $status, string $term, int $page = 1, int $pageSize = 20): array
    {
        if ($churchId < 1) throw new InvalidArgumentException('Invalid church');
        if (!in_array($status, ['', 'open', 'scheduled', 'in_progress', 'closed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        $page=max(1,$page); $pageSize=max(1,min(100,$pageSize));
        $where='church_id=:church AND deleted_at IS NULL';
        $args=[':church'=>$churchId];
        if ($assigneeId !== null) { $where.=' AND assigned_user_id=:assignee'; $args[':assignee']=$assigneeId; }
        if ($status !== '') { $where.=' AND status=:status'; $args[':status']=$status; }
        if (trim($term) !== '') { $where.=' AND subject LIKE :term'; $args[':term']='%'.trim($term).'%'; }
        $count=$this->db->prepare('SELECT COUNT(*) FROM pastoral_cases WHERE '.$where);
        $count->execute($args);
        $total=(int)$count->fetchColumn();
        $sql='SELECT id,subject,status,priority,assigned_user_id,person_id,visitor_id,next_contact_at,created_at
              FROM pastoral_cases WHERE '.$where.'
              ORDER BY CASE WHEN next_contact_at IS NULL THEN 1 ELSE 0 END,
                       next_contact_at ASC,id DESC LIMIT :limit OFFSET :offset';
        $q=$this->db->prepare($sql);
        foreach ($args as $key=>$value) $q->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->bindValue(':limit',$pageSize,PDO::PARAM_INT);
        $q->bindValue(':offset',($page-1)*$pageSize,PDO::PARAM_INT);
        $q->execute();
        return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'page_size'=>$pageSize];
    }

    public function summary(int $churchId, ?int $assigneeId=null): array
    {
        if ($churchId<1) throw new InvalidArgumentException('Invalid church');
        $where='church_id=:church AND deleted_at IS NULL';
        $args=[':church'=>$churchId];
        if ($assigneeId!==null) {$where.=' AND assigned_user_id=:assignee';$args[':assignee']=$assigneeId;}
        $sql="SELECT
          COUNT(*) AS total,
          COALESCE(SUM(CASE WHEN status IN ('open','scheduled','in_progress') THEN 1 ELSE 0 END),0) AS active,
          COALESCE(SUM(CASE WHEN status IN ('open','scheduled','in_progress') AND next_contact_at IS NOT NULL AND next_contact_at < NOW() THEN 1 ELSE 0 END),0) AS overdue,
          COALESCE(SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END),0) AS closed
          FROM pastoral_cases WHERE ".$where;
        $q=$this->db->prepare($sql);$q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active'=>0,'overdue'=>0,'closed'=>0];
    }

    public function caseEvents(int $churchId,int $caseId,bool $mayViewConfidential): array
    {
        if ($churchId<1||$caseId<1) throw new InvalidArgumentException('Invalid scope');
        $sql='SELECT e.id,e.kind,e.happened_at,e.summary,e.is_confidential,e.recorded_by
              FROM pastoral_case_events e INNER JOIN pastoral_cases c
              ON c.church_id=e.church_id AND c.id=e.case_id
              WHERE e.church_id=:church AND e.case_id=:case AND c.deleted_at IS NULL';
        if (!$mayViewConfidential) $sql.=' AND e.is_confidential=0';
        $sql.=' ORDER BY e.happened_at DESC,e.id DESC LIMIT 200';
        $q=$this->db->prepare($sql);
        $q->execute([':church'=>$churchId,':case'=>$caseId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
