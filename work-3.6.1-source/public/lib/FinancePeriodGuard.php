<?php
declare(strict_types=1);

final class FinancePeriodGuard
{
    private static array $held=[];
    private static function date(string $value): void {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Data financeira inválida.');
    }
    public static function hold(PDO $pdo,string $cid): void {
        $name='igreja_finance_'.sha1($cid);$key=spl_object_id($pdo).':'.$name;
        if(isset(self::$held[$key]))return;
        $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$name]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Outra operação financeira está em andamento. Tente novamente.');
        self::$held[$key]=true;
        register_shutdown_function(static function()use($pdo,$name){try{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}catch(Throwable){}});
    }
    public static function assertOpen(PDO $pdo,string $cid,string $date): void {
        self::date($date);self::hold($pdo,$cid);
        // A locking read sees a newly closed period even inside an existing transaction.
        $q=$pdo->prepare('SELECT id FROM finance_period_locks WHERE church_id=? AND active=1 AND period_start<=? AND period_end>=? LIMIT 1 FOR UPDATE');$q->execute([$cid,$date,$date]);
        if($q->fetchColumn())throw new RuntimeException('Este período financeiro está bloqueado. Solicite a reabertura ao administrador.');
    }
    public static function close(PDO $pdo,Auth $auth,string $cid,string $from,string $to,string $reason): void {
        if(!$auth->can('finance')||($auth->currentChurch()['id']??'')!==$cid)throw new RuntimeException('Acesso restrito à tesouraria.');
        self::date($from);self::date($to);$reason=trim($reason);
        if($from>$to||$reason===''||mb_strlen($reason)>1000)throw new InvalidArgumentException('Informe período válido e justificativa de até 1.000 caracteres.');
        self::hold($pdo,$cid);$pdo->beginTransaction();
        try{$q=$pdo->prepare('SELECT id FROM finance_period_locks WHERE church_id=? AND active=1 AND period_start<=? AND period_end>=? LIMIT 1 FOR UPDATE');$q->execute([$cid,$to,$from]);if($q->fetchColumn())throw new RuntimeException('Já existe um bloqueio que abrange parte desse período.');
            $id=app_uuid();$pdo->prepare('INSERT INTO finance_period_locks(id,church_id,period_start,period_end,reason,created_by_user_id) VALUES(?,?,?,?,?,?)')->execute([$id,$cid,$from,$to,$reason,$auth->requireLogin()['id']]);
            self::audit($pdo,$auth,$cid,'finance_period_locked',$id);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public static function reopen(PDO $pdo,Auth $auth,string $cid,string $id): void {
        if(!$auth->can('settings')||($auth->currentChurch()['id']??'')!==$cid)throw new RuntimeException('Somente a administração pode reabrir períodos.');
        self::hold($pdo,$cid);$pdo->beginTransaction();
        try{$q=$pdo->prepare('UPDATE finance_period_locks SET active=0,reopened_by_user_id=?,reopened_at=NOW() WHERE id=? AND church_id=? AND active=1');$q->execute([$auth->requireLogin()['id'],$id,$cid]);if($q->rowCount()!==1)throw new RuntimeException('Bloqueio não encontrado ou já reaberto.');self::audit($pdo,$auth,$cid,'finance_period_reopened',$id);$pdo->commit();}
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private static function audit(PDO $pdo,Auth $auth,string $cid,string $action,string $id): void {$pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')->execute([app_uuid(),$auth->requireLogin()['id'],$cid,$action,json_encode(['period_id'=>$id],JSON_THROW_ON_ERROR)]);}
}
