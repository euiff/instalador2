<?php
declare(strict_types=1);

/**
 * Summary of PIX payments for the CURRENT church, including rows beyond the
 * first 1000 displayed on the screen. Only confirmed payments are considered
 * received; pending amounts are not cash/revenue.
 */
final class PixPaymentSummary
{
    private const STATUSES=['all','pending','confirmed','failed','cancelled'];

    public static function totals(PDO $pdo,string $churchId,string $from,string $to,string $status='all'):array
    {
        if($churchId==='')throw new InvalidArgumentException('Igreja inválida.');
        foreach([$from,$to] as $date){
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!$parsed||$parsed->format('Y-m-d')!==$date)
                throw new InvalidArgumentException('Período inválido.');
        }
        if($from>$to)throw new InvalidArgumentException('Período invertido.');
        if(!in_array($status,self::STATUSES,true))
            throw new InvalidArgumentException('Situação inválida.');
        $where='WHERE church_id=? AND DATE(created_at) BETWEEN ? AND ?';
        $bind=[$churchId,$from,$to];
        if($status!=='all'){$where.=' AND status=?';$bind[]=$status;}

        // Never derive dashboard totals by summing the limited grid (LIMIT 1000).
        $sql="SELECT COUNT(*) quantity,
           COALESCE(SUM(amount),0) total,
           COALESCE(SUM(CASE WHEN status='confirmed' THEN amount ELSE 0 END),0) confirmed,
           COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) pending,
           COALESCE(SUM(CASE WHEN status IN ('failed','cancelled') THEN amount ELSE 0 END),0) inactive
           FROM pix_payments ".$where;
        $q=$pdo->prepare($sql);$q->execute($bind);
        $row=$q->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'quantity'=>(int)($row['quantity']??0),
            'total'=>(float)($row['total']??0),
            'confirmed'=>(float)($row['confirmed']??0),
            'pending'=>(float)($row['pending']??0),
            'inactive'=>(float)($row['inactive']??0)
        ];
    }
}
