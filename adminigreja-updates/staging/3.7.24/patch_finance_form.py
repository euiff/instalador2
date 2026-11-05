"""New cashbook entry form: mobile categories and currency, direction-aware fields."""

FORM = r'''<section data-registry-form="finance-entry" class="card card-pad form-card">
  <div class="section-title"><span>＋</span><div>
    <h2>Novo lançamento</h2>
    <p>Formulário simples para entradas e saídas, com comprovantes e leitura opcional de nota.</p>
  </div></div>
  <p class="muted">Lançamentos deste formulário pertencem à <strong>Tesouraria Geral</strong>.
  Para um caixa de ministério, use <a href="/department-treasury.php">Tesourarias dos Departamentos</a>.</p>
  <form method="post" enctype="multipart/form-data" data-expense-receipt-form data-smart-treasury="general">
    <?=csrf_field()?><input type="hidden" name="action" value="entry">
    <input type="hidden" name="ocr_items_json" value="">
    <div class="form-grid">
      <div class="field"><label>Tipo de movimentação *</label>
        <select class="select" name="direction">
          <option value="income">Entrada</option><option value="expense">Saída</option>
        </select></div>
      <div class="field"><label>Data *</label>
        <input class="input" type="date" name="transaction_date" value="<?=date('Y-m-d')?>" required></div>
      <div class="field full"><label>Conta da tesouraria geral</label>
        <select class="select" name="account_id">
          <option value="">Caixa geral sem conta específica</option>
          <?php foreach($generalAccounts as $a):?>
          <option value="<?=e($a['id'])?>"><?=e($a['name'])?></option>
          <?php endforeach;?>
        </select>
        <small>Para criar ou renomear uma conta, abra <a href="#finance-accounts">Caixa e contas bancárias</a>.</small></div>
      <div class="field" data-smart-category><label>Categoria *</label>
        <input class="input" data-category-search type="search" placeholder="Filtrar categorias..." aria-label="Pesquisar categoria">
        <select class="select" name="category" required>
          <option value="">Selecione uma categoria</option>
          <?php foreach($categoryCatalog as $c):?>
          <option value="<?=e($c['name'])?>" data-direction="<?=e($c['direction'])?>"><?=e($c['name'])?></option>
          <?php endforeach;?>
        </select>
        <small data-category-empty hidden>Nenhuma categoria encontrada.</small>
        <small><a href="/registers.php?kind=finance_category">Cadastrar nova categoria</a></small></div>
      <div class="field smart-money"><label>Valor *</label>
        <input class="input" data-money-br name="amount" inputmode="numeric" placeholder="0,00" required></div>
      <div class="field full" data-income-only><label data-member-label>Membro contribuinte (opcional)</label>
        <select class="select" name="member_id">
          <option value="">Sem vínculo pessoal</option>
          <?php foreach($contributionMembers as $member):?>
          <option value="<?=e($member['id'])?>"><?=e($member['name'])?></option>
          <?php endforeach;?>
        </select>
        <small data-dizimo-note hidden>Para dízimos, escolha obrigatoriamente o membro. Esta entrada aparecerá em seu Portal do Membro.</small>
        <small>O membro não transfere o valor para o departamento dele.</small></div>
      <div class="field full"><label>Descrição</label>
        <input class="input" name="description" maxlength="255" placeholder="Informe a finalidade"></div>
      <div class="field"><label>Centro de custos</label>
        <select class="select" name="cost_center_id">
          <option value="">Não informar</option>
          <?php foreach($costCenters as $v):?><option value="<?=e($v['id'])?>"><?=e($v['name'])?></option><?php endforeach;?>
        </select></div>
      <div class="field"><label>Contato cadastrado</label>
        <div class="smart-quick-field">
        <select class="select" name="contact_id">
          <option value="">Não informar</option>
          <?php foreach($financeContacts as $v):?><option value="<?=e($v['id'])?>"><?=e($v['name'])?></option><?php endforeach;?>
        </select>
        <button type="button" class="btn btn-light" data-add-contact>+ Novo contato</button>
        </div></div>
      <div class="field full"><label>Favorecido / Pagador (opcional)</label>
        <input class="input" name="counterparty" maxlength="190" placeholder="Pessoa ou fornecedor"></div>
      <div class="field"><label>Forma de pagamento</label>
        <select class="select" name="payment_method">
          <option value="">Não informar</option>
          <?php foreach($methods as $m):?><option><?=e($m)?></option><?php endforeach;?>
        </select></div>
      <div class="field"><label>Número do cupom / nota</label>
        <input class="input" name="document_number" maxlength="60"></div>
      <div class="field"><label>Tipo de anexo</label>
        <select class="select" name="document_type">
          <?php foreach($docLabels as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?>
        </select></div>
      <div class="field full" data-receipt-upload><label>Anexo: foto, cupom, nota ou comprovante</label>
        <input class="input" type="file" name="document_file" accept="image/*,.pdf,application/pdf">
        <small>Imagem ou PDF até 12 MB. O original fica vinculado à movimentação.</small></div>
      <div class="field full" data-expense-only data-ocr-area>
        <strong>Leitura inteligente do cupom</strong>
        <p class="muted">Depois de escolher uma foto, clique em Ler foto. O leitor sugere data, valor, fornecedor, categoria e produtos, mas você deve conferir todos os dados.</p>
        <button type="button" class="btn btn-light" data-read-ocr>Ler foto automaticamente</button>
        <small data-ocr-state role="status" aria-live="polite">Funciona com JPG, PNG e WEBP. OCR no navegador; preenchimento manual continua disponível.</small>
        <div data-ocr-items-preview></div>
        <label data-ocr-verify hidden><input type="checkbox" name="ocr_confirmed" value="1">
          Conferi as informações e os produtos com o comprovante original.</label>
      </div>
      <div class="field full" data-expense-only>
        <div class="expense-receipt-option" data-receipt-proof-choice hidden>
          <label>Como comprovar a despesa?
            <select class="select" name="proof_method" data-receipt-method>
              <option value="upload">Anexar foto, cupom ou nota fiscal</option>
              <option value="receipt_screen">Gerar recibo e assinar agora na tela</option>
              <option value="receipt_print">Gerar recibo para imprimir e assinar depois</option>
            </select></label>
          <p>Recibos impressos sem assinatura ficam pendentes. Recibo não substitui nota fiscal legalmente exigida.</p>
          <div class="expense-receipt-fields" data-receipt-details hidden>
            <label>Nome de quem recebeu *<input class="input" name="recipient_name" maxlength="190"></label>
            <label>CPF/CNPJ do recebedor (opcional)<input class="input" name="recipient_document" maxlength="32"></label>
          </div>
          <div data-receipt-signature hidden>
            <p>Peça ao recebedor para assinar abaixo com dedo ou mouse.</p>
            <canvas data-receipt-canvas class="expense-sign-canvas" width="800" height="230" aria-label="Assinatura do recebedor"></canvas>
            <input type="hidden" name="signature_data" data-signature-output>
            <button class="btn btn-light" type="button" data-signature-clear>Limpar assinatura</button>
            <label><input type="checkbox" name="signature_consent" value="1" data-signature-consent>
              Sou a pessoa indicada como recebedora e confirmo o recebimento do valor.</label>
          </div>
        </div>
      </div>
      <div class="field full"><label>Observações (opcional)</label>
        <textarea class="textarea" name="notes" maxlength="4000"></textarea></div>
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%;margin-top:12px">Registrar lançamento</button>
  </form>
</section>'''


def patch_form(source):
    text=source.decode('utf8')
    begin=text.index('<section data-registry-form="finance-entry"')
    end=text.index('</section>',begin)+len('</section>')
    text=text[:begin]+FORM+text[end:]
    return text.encode('utf8')
