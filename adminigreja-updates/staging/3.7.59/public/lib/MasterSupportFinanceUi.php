<?php
declare(strict_types=1);

/** Display-only controls; endpoint and service recheck platform Master roles. */
final class MasterSupportFinanceUi
{
    public static function allowed(Auth $auth,?array $church): bool
    {
        if(!$church || !$auth->isSuperAdmin() || !$auth->isMasterSupportMode())
            return false;
        $current=$auth->currentChurch();
        return $current!==null && (string)$current['id']===(string)$church['id'];
    }

    public static function exclusionForm(array $entry): void
    {
        if(!in_array((string)($entry['status']??''),['posted','reversed'],true))return;
        $alreadyReversed=(string)$entry['status']==='reversed';
        $id=(string)($entry['id']??'');
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$id))return;
        $amount=number_format((float)($entry['amount']??0),2,',','.');
        $summary=(string)($entry['description']??'');
        ?>
        <details class="master-entry-exclude" style="margin:9px 0;max-width:100%">
          <summary class="btn btn-danger" style="display:inline-flex;cursor:pointer"><?= $alreadyReversed?'Ocultar estornado (Master)':'Excluir lançamento (Master)' ?></summary>
          <form action="/finance.php" method="post"
                onsubmit="return confirm('Confirma excluir SOMENTE este lançamento do saldo ativo? A operação será registrada e os documentos continuarão preservados.');"
                style="border:1px solid #d7a3a0;border-radius:12px;padding:12px;margin-top:8px;min-width:0;max-width:390px;background:var(--card-bg,#fff)">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="master_exclude_entry">
            <input type="hidden" name="entry_id" value="<?=e($id)?>">
            <input type="hidden" name="confirm_entry_id" value="<?=e($id)?>">
            <p style="margin:3px 0 9px">
              <strong><?= $alreadyReversed?'Arquivar estornado':'Excluir do saldo ativo' ?> — somente este registro:</strong> <?=e($summary)?>
              <span style="display:block;color:var(--muted,#667085)">R$ <?=e($amount)?> · <?=e(date('d/m/Y',strtotime((string)$entry['transaction_date'])))?></span>
            </p>
            <label for="master-reason-<?=e($id)?>" style="display:block">Motivo da exclusão (obrigatório)</label>
            <textarea id="master-reason-<?=e($id)?>" class="textarea" name="reason"
                      required minlength="10" maxlength="200" rows="2"
                      style="max-width:100%;width:100%"
                      placeholder="Ex.: lançamento realizado em duplicidade por engano"></textarea>
            <label style="display:flex;gap:8px;align-items:start;margin:10px 0">
              <input type="checkbox" name="acknowledge_exclusion" value="1" required style="margin-top:4px">
              <span>Confirmo que quero retirar <strong>somente esta movimentação</strong> do saldo ativo.</span>
            </label>
            <small style="display:block;margin:8px 0;color:var(--muted,#667085)"><?= $alreadyReversed?'Este lançamento já está estornado. A ação apenas o remove da listagem, sem mexer no saldo novamente.':'O valor sai do saldo ativo. Os comprovantes e o histórico são preservados.' ?> A ação fica registrada para auditoria.</small>
            <button type="submit" class="btn btn-danger" style="width:100%">Confirmar exclusão deste lançamento</button>
          </form>
        </details>
        <?php
    }
}
