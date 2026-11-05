ACCOUNTS = r'''<section class="card card-pad" id="finance-accounts">
  <h2 style="font-size:16px">Caixa e contas bancárias da tesouraria geral</h2>
  <p class="muted">Renomeie contas aqui sem alterar os caixas dos ministérios.</p>
  <div class="table-wrap"><table class="table">
  <thead><tr><th>Conta</th><th>Tipo</th><th>Saldo inicial</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($generalAccounts as $a):?>
  <tr><td><strong><?=e($a['name'])?></strong></td>
  <td><?=e($a['account_type']==='bank'?'Banco':'Caixa')?></td>
  <td>R$ <?=number_format((float)$a['opening_balance'],2,',','.')?></td>
  <td><details><summary class="btn btn-light">Editar conta</summary>
     <form method="post" style="display:grid;gap:8px;min-width:260px;margin:10px 0">
       <?=csrf_field()?><input type="hidden" name="action" value="account_update">
       <input type="hidden" name="account_id" value="<?=e($a['id'])?>">
       <label>Nome<input class="input" name="name" maxlength="130" value="<?=e($a['name'])?>" required></label>
       <label>Tipo<select class="select" name="account_type">
          <option value="cash" <?=$a['account_type']==='cash'?'selected':''?>>Caixa</option>
          <option value="bank" <?=$a['account_type']==='bank'?'selected':''?>>Banco</option>
       </select></label>
       <label>Saldo inicial<input class="input" name="opening_balance" value="<?=number_format((float)$a['opening_balance'],2,',','.')?>"></label>
       <small>Após movimentos ou fechamentos, o saldo inicial fica protegido.</small>
       <button class="btn btn-primary">Salvar alterações</button>
     </form></details></td></tr>
  <?php endforeach;?>
  <?php if(!$generalAccounts):?><tr><td colspan="4">Nenhuma conta geral cadastrada.</td></tr><?php endif;?>
  </tbody></table></div>
  <form data-registry-form="finance-account" method="post" style="margin-top:14px">
    <?=csrf_field()?><input type="hidden" name="action" value="account">
    <div class="form-grid">
      <div class="field full"><label>Nova conta *<input class="input" name="name" maxlength="130" placeholder="Caixa da igreja / Banco..." required></label></div>
      <div class="field"><label>Tipo<select class="select" name="account_type"><option value="cash">Caixa</option><option value="bank">Banco</option></select></label></div>
      <div class="field"><label>Saldo inicial<input class="input" name="opening_balance" value="0,00"></label></div>
    </div>
    <button class="btn btn-primary" style="margin-top:10px">+ Criar conta geral</button>
  </form>
</section>'''

def patch_accounts_ui(source):
    t=source.decode('utf8')
    old='<section class="card card-pad">\n  <h2 style="font-size:16px">Caixa e contas bancárias</h2>'
    if t.count(old)!=1:raise RuntimeError('finance general account card not found')
    a=t.index(old);b=t.index('</section>',a)+len('</section>')
    return (t[:a]+ACCOUNTS+t[b:]).encode('utf8')
