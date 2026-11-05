<?php
declare(strict_types=1);

/**
 * Financial origin is defined by the posting channel/account, never by a
 * contributor's ministry membership. This helper protects all general
 * treasury write endpoints from receiving a ministry cashbox ID.
 */
final class GeneralTreasuryAccountScope
{
    public static function assertGeneral(PDO $pdo,string $churchId,?string $accountId):?array
    {
        $id=trim((string)$accountId);
        if($id==='')return null;
        $q=$pdo->prepare('SELECT id,church_id,department_id,name,active FROM finance_accounts
            WHERE church_id=? AND id=? LIMIT 1');
        $q->execute([$churchId,$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||!(bool)$row['active'])
            throw new InvalidArgumentException('Conta financeira não encontrada ou inativa nesta igreja.');
        if(!empty($row['department_id']))
            throw new InvalidArgumentException(
              'Esta conta pertence a um departamento. Para registrar uma receita ou despesa do ministério, abra Tesouraria dos Departamentos e faça o lançamento no caixa autorizado.'
            );
        return $row;
    }

    public static function list(PDO $pdo,string $churchId):array
    {
        $q=$pdo->prepare('SELECT id,name,account_type,opening_balance,active FROM finance_accounts
            WHERE church_id=? AND department_id IS NULL AND active=1 ORDER BY name');
        $q->execute([$churchId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
