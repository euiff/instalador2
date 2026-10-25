<?php

declare(strict_types=1);

final class DashboardService
{
    public function __construct(private PDO $pdo) {}

    public function stats(string $churchId): array
    {
        $payments = $this->scalarRow("SELECT COUNT(*) total_payments,
            SUM(status='confirmed') confirmed_payments,
            SUM(status='pending') pending_payments,
            COALESCE(SUM(CASE WHEN status='confirmed' THEN amount ELSE 0 END),0) total_amount
            FROM pix_payments WHERE church_id=?", [$churchId]);

        $prayers = $this->scalarRow("SELECT COUNT(*) prayer_requests,
            SUM(status='pending') pending_prayers
            FROM prayer_requests WHERE church_id=?", [$churchId]);

        return [
            'totalPayments' => (int)($payments['total_payments'] ?? 0),
            'confirmedPayments' => (int)($payments['confirmed_payments'] ?? 0),
            'pendingPayments' => (int)($payments['pending_payments'] ?? 0),
            'totalAmount' => (float)($payments['total_amount'] ?? 0),
            'prayerRequests' => (int)($prayers['prayer_requests'] ?? 0),
            'pendingPrayers' => (int)($prayers['pending_prayers'] ?? 0),
            'members' => $this->count('members', $churchId, ' AND active=1'),
            'events' => $this->count('events', $churchId, ' AND active=1'),
            'messages' => $this->count('bot_messages', $churchId),
            'recentPayments' => $this->recentPayments($churchId),
            'recentPrayers' => $this->recentPrayers($churchId),
        ];
    }

    private function count(string $table, string $churchId, string $extra=''): int
    {
        $allowed = ['members','events','bot_messages'];
        if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Tabela inválida.');
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE church_id=?{$extra}");
        $q->execute([$churchId]);
        return (int)$q->fetchColumn();
    }

    private function recentPayments(string $churchId): array
    {
        $q = $this->pdo->prepare('SELECT id,name,phone,amount,payment_type,status,created_at FROM pix_payments WHERE church_id=? ORDER BY created_at DESC LIMIT 5');
        $q->execute([$churchId]);
        return $q->fetchAll();
    }

    private function recentPrayers(string $churchId): array
    {
        $q = $this->pdo->prepare('SELECT id,name,phone,request,status,created_at FROM prayer_requests WHERE church_id=? ORDER BY created_at DESC LIMIT 5');
        $q->execute([$churchId]);
        return $q->fetchAll();
    }

    private function scalarRow(string $sql, array $params): array
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        return $q->fetch() ?: [];
    }
}
