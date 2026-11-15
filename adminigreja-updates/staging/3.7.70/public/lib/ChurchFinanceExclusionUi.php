<?php
declare(strict_types=1);

/**
 * Explicit church-only controls. The POST endpoint and financial service
 * repeat the authorization checks; displaying this form grants no permission.
 */
final class ChurchFinanceExclusionUi
{
    public static function render(array $entry): void
    {
        if(!in_array((string)($entry['status']??''),['posted','reversed'],true))return;
        $id=(string)($entry['id']??'');
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$id))return;
        $alreadyReversed=(string)$entry['status']==='reversed';
        $label=$alreadyReversed?'Excluir estornado':'Excluir lançamento';
        $desc=(string)($entry['description']??'');
        $amount=number_format((float)($entry['amount']??0),2,',','.');
        ?>
        <details class="im-church-entry-exclude" style="margin:9px 0;max-width:100%">
          <summary class="btn btn-danger" style="display:inline-flex;cursor:pointer;padding:8px 12px"><?=e($label)?></summary>
          <form method="post" action="/finance.php"
                onsubmit="return confirm('Confirma excluir SOMENTE este lançamento? O valor deixará de compor o saldo ativo, mas os comprovantes e a auditoria serão preservados.');"
                style="margin-top:8px;min-width:0;max-width:390px;padding:12px;border:1px solid #d7a3a0;border-radius:12px;background:var(--card-bg,#fff)">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="church_exclude_entry">
            <input type="hidden" name="entry_id" value="<?=e($id)?>">
            <input type="hidden" name="confirm_entry_id" value="<?=e($id)?>">
            <p style="margin:4px 0 8px"><strong><?=e($label)?></strong> — <?=e($desc)?></p>
            <p style="margin:0 0 10px;color:var(--muted,#667085)">
              <?=e(date('d/m/Y',strtotime((string)$entry['transaction_date'])))?>
              · R$ <?=e($amount)?>
              · <?=e((string)($entry['account_name']??'Tesouraria Geral'))?>
            </p>
            <label for="exclude-church-reason-<?=e($id)?>">Motivo da exclusão (obrigatório, mínimo 10 caracteres)</label>
            <textarea class="textarea" id="exclude-church-reason-<?=e($id)?>" name="reason"
                required minlength="10" maxlength="200" rows="2"
                style="width:100%" placeholder="Ex.: lançamento repetido por engano, valor cadastrado incorretamente"></textarea>
            <label style="display:flex;align-items:start;gap:8px;margin:11px 0">
              <input type="checkbox" name="acknowledge_exclusion" value="1" required>
              <span>Confirmo a exclusão somente deste lançamento.</span>
            </label>
            <small class="muted" style="display:block;margin:8px 0">
              <?=$alreadyReversed
                ?'Como já está estornado, será ocultado sem alterar o saldo novamente.'
                :'Será removido dos saldos e relatórios ativos. Comprovantes, recibos e registro de auditoria ficam preservados.'?>
            </small>
            <button class="btn btn-danger" type="submit" style="width:100%">Confirmar exclusão</button>
          </form>
        </details>
        <?php
    }
}
