<?php
declare(strict_types=1);

final class ChurchInsightsService
{
    public function __construct(private PDO $pdo,private string $cid) {}

    private function scalar(string $sql,array $params=[]): float
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return (float)$q->fetchColumn();
    }

    private function rows(string $sql,array $params=[]): array
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q->fetchAll();
    }

    public function overview(): array
    {
        $members=(int)$this->scalar("SELECT COUNT(*) FROM members WHERE church_id=? AND active=1",[$this->cid]);
        $newMembers=(int)$this->scalar("SELECT COUNT(*) FROM members WHERE church_id=? AND active=1 AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",[$this->cid]);

        $overduePayables=(int)$this->scalar("SELECT COUNT(*) FROM finance_payables WHERE church_id=? AND status='open' AND due_date<CURDATE()",[$this->cid]);
        $overdueAmount=$this->scalar("SELECT COALESCE(SUM(amount),0) FROM finance_payables WHERE church_id=? AND status='open' AND due_date<CURDATE()",[$this->cid]);

        $eventPending=(int)$this->scalar("SELECT COUNT(*) FROM event_registrations WHERE church_id=? AND registration_status<>'cancelled' AND payment_status IN ('unpaid','pending') AND amount_due>0",[$this->cid]);
        $eventPendingAmount=$this->scalar("SELECT COALESCE(SUM(GREATEST(amount_due-amount_paid,0)),0) FROM event_registrations WHERE church_id=? AND registration_status<>'cancelled' AND payment_status IN ('unpaid','pending')",[$this->cid]);

        $kidsPending=(int)$this->scalar("SELECT COUNT(*) FROM kids_checkin_requests WHERE church_id=? AND status='pending'",[$this->cid]);
        $kidsInRoom=(int)$this->scalar("SELECT COUNT(*) FROM kids_checkins ci INNER JOIN kids_programs p ON p.id=ci.program_id AND p.church_id=ci.church_id WHERE ci.church_id=? AND ci.status='checked_in' AND p.program_date=CURDATE()",[$this->cid]);

        $groupMeetings=(int)$this->scalar("SELECT COUNT(*) FROM small_group_meetings WHERE church_id=? AND meeting_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)",[$this->cid]);
        $groupPresence=(int)$this->scalar("SELECT COUNT(*) FROM small_group_attendance a INNER JOIN small_group_meetings m ON m.id=a.meeting_id AND m.church_id=a.church_id WHERE a.church_id=? AND a.present=1 AND m.meeting_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)",[$this->cid]);

        $lessons=(int)$this->scalar("SELECT COUNT(*) FROM learning_lessons WHERE church_id=? AND lesson_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)",[$this->cid]);
        $lessonPresence=(int)$this->scalar("SELECT COUNT(*) FROM learning_attendance a INNER JOIN learning_lessons l ON l.id=a.lesson_id AND l.church_id=a.church_id WHERE a.church_id=? AND a.present=1 AND l.lesson_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)",[$this->cid]);

        $activeAnnouncements=(int)$this->scalar("SELECT COUNT(*) FROM church_announcements WHERE church_id=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW())",[$this->cid]);
        $announcementReads=(int)$this->scalar("SELECT COUNT(*) FROM church_announcement_reads WHERE church_id=? AND read_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",[$this->cid]);

        $activeStudies=(int)$this->scalar("SELECT COUNT(*) FROM church_studies WHERE church_id=? AND active=1 AND (published_at IS NULL OR published_at<=NOW())",[$this->cid]);
        $studyReads=(int)$this->scalar("SELECT COUNT(*) FROM church_study_reads WHERE church_id=? AND read_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",[$this->cid]);

        return compact(
            'members','newMembers','overduePayables','overdueAmount','eventPending','eventPendingAmount',
            'kidsPending','kidsInRoom','groupMeetings','groupPresence','lessons','lessonPresence',
            'activeAnnouncements','announcementReads','activeStudies','studyReads'
        );
    }

    public function upcomingEvents(int $days=30): array
    {
        $days=max(1,min($days,90));
        return $this->rows("SELECT e.id,e.title,e.event_date,e.location,e.capacity,e.registration_enabled,
          (SELECT COALESCE(SUM(1+r.guests_count),0) FROM event_registrations r WHERE r.church_id=e.church_id AND r.event_id=e.id AND r.registration_status<>'cancelled') registered_people,
          (SELECT COUNT(*) FROM event_registrations r WHERE r.church_id=e.church_id AND r.event_id=e.id AND r.registration_status<>'cancelled' AND r.payment_status IN ('unpaid','pending') AND r.amount_due>0) pending_payments
          FROM events e
          WHERE e.church_id=? AND e.active=1 AND e.event_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL ".$days." DAY)
          ORDER BY e.event_date LIMIT 12",[$this->cid]);
    }

    public function overduePayables(int $limit=8): array
    {
        $limit=max(1,min($limit,30));
        return $this->rows("SELECT id,supplier,description,amount,due_date,DATEDIFF(CURDATE(),due_date) days_overdue
          FROM finance_payables WHERE church_id=? AND status='open' AND due_date<CURDATE()
          ORDER BY due_date LIMIT ".$limit,[$this->cid]);
    }

    public function pendingEventPayments(int $limit=8): array
    {
        $limit=max(1,min($limit,30));
        return $this->rows("SELECT r.id,r.name,r.phone,r.amount_due,r.amount_paid,r.payment_method,e.title event_title,e.event_date
          FROM event_registrations r
          INNER JOIN events e ON e.id=r.event_id AND e.church_id=r.church_id
          WHERE r.church_id=? AND r.registration_status<>'cancelled' AND r.payment_status IN ('unpaid','pending') AND r.amount_due>0
          ORDER BY e.event_date,r.created_at LIMIT ".$limit,[$this->cid]);
    }

    public function pendingKids(int $limit=8): array
    {
        $limit=max(1,min($limit,30));
        return $this->rows("SELECT r.id,r.child_name,r.guardian_name,r.guardian_phone,r.requested_at,p.title program_title,p.program_date
          FROM kids_checkin_requests r
          INNER JOIN kids_programs p ON p.id=r.program_id AND p.church_id=r.church_id
          WHERE r.church_id=? AND r.status='pending'
          ORDER BY r.requested_at LIMIT ".$limit,[$this->cid]);
    }

    public function groupPerformance(int $days=30): array
    {
        $days=max(7,min($days,90));
        return $this->rows("SELECT g.id,g.name,
          COUNT(DISTINCT m.id) meetings,
          COALESCE(SUM(m.visitor_count),0) visitors,
          COALESCE(SUM((SELECT COUNT(*) FROM small_group_attendance a WHERE a.church_id=m.church_id AND a.meeting_id=m.id AND a.present=1)),0) presence
          FROM small_groups g
          LEFT JOIN small_group_meetings m ON m.group_id=g.id AND m.church_id=g.church_id AND m.meeting_date>=DATE_SUB(CURDATE(),INTERVAL ".$days." DAY)
          WHERE g.church_id=? AND g.active=1
          GROUP BY g.id,g.name
          ORDER BY meetings DESC,presence DESC,g.name
          LIMIT 10",[$this->cid]);
    }
}
