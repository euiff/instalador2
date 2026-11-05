"""Keep each ministry cashbook separate, with same mobile entry interface as general."""

FIELDS=r'''<div class="dept-fields">
  <label>Caixa do departamento *<select class="select" name="account_id" required>
     <option value="">Selecione o caixa</option>
     <?php foreach($accounts as $a)if(!empty($a['active'])):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endif;?>
  </select></label>
  <label>Tipo de lançamento<select class="select" name="direction">
     <option value="income">Entrada</option><option value="expense">Saída</option>
  </select></label>
  <label>Data *<input class="input" type="date" name="transaction_date" value="<?=date('Y-m-d')?>" required></label>
  <label class="smart-money">Valor (R$) *<input class="input" name="amount" data-money-br inputmode="numeric" required placeholder="0,00"></label>
  <label data-smart-category>Categoria *
    <input class="input" data-category-search type="search" placeholder="Filtrar categorias...">
    <select class="select" name="category" required>
      <option value="">Selecione</option>
      <?php foreach($categoryCatalog as $c):?><option value="<?=e($c['name'])?>" data-direction="<?=e($c['direction'])?>"><?=e($c['name'])?></option><?php endforeach;?>
    </select></label>
  <label class="wide" data-income-only><span data-member-label>Membro contribuinte (opcional)</span>
    <select class="select" name="member_id">
      <option value="">Sem vínculo pessoal</option>
      <?php foreach($contributionMembers as $m):?><option value="<?=e($m['id'])?>"><?=e($m['name'])?></option><?php endforeach;?>
    </select>
    <small data-dizimo-note hidden>Dízimos exigem selecionar o membro contribuinte.</small>
  </label>
  <label class="wide">Descrição<input class="input" name="description" maxlength="255" placeholder="Informe a finalidade"></label>
  <label>Centro de custos<select class="select" name="cost_center_id">
    <option value="">Não informar</option>
    <?php foreach($costCenters as $v):?><option value="<?=e($v['id'])?>"><?=e($v['name'])?></option><?php endforeach;?>
  </select></label>
  <label>Contato cadastrado<div class="smart-quick-field">
    <select class="select" name="contact_id"><option value="">Não informar</option>
       <?php foreach($contacts as $v):?><option value="<?=e($v['id'])?>"><?=e($v['name'])?></option><?php endforeach;?>
    </select><button class="btn btn-light" type="button" data-add-contact>+ Novo contato</button>
  </div></label>
  <label class="wide">Fornecedor ou origem<input class="input" name="counterparty" maxlength="190"></label>
  <label>Forma de pagamento<select class="select" name="payment_method">
     <option value="">Não informar</option>
     <option>Dinheiro</option><option>PIX</option><option>Débito</option>
     <option>Crédito</option><option>Transferência</option><option>Boleto</option>
  </select></label>
  <label>Número do documento<input class="input" name="document_number" maxlength="60"></label>
  <label>Tipo do anexo<select class="select" name="document_type">
     <?php foreach($kinds as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?>
  </select></label>
  <label class="wide" data-receipt-upload>Foto ou PDF do comprovante
    <input type="file" name="document_file" accept="image/*,.pdf,application/pdf">
  </label>
  <div class="wide" data-expense-only data-ocr-area>
    <strong>Leitura inteligente da nota ou cupom</strong>
    <p class="muted">Selecione uma foto, clique Ler foto e confira todos os dados antes de lançar.</p>
    <button class="btn btn-light" type="button" data-read-ocr>Ler foto automaticamente</button>
    <small data-ocr-state role="status" aria-live="polite">Fotos JPG, PNG e WEBP. OCR no navegador, sem publicação automática.</small>
    <div data-ocr-items-preview></div>
    <label data-ocr-verify hidden><input type="checkbox" name="ocr_confirmed" value="1">
      Revisei os produtos e valores com o documento original.</label>
  </div>
  <div class="wide" data-expense-only>
    <div class="expense-receipt-option" data-receipt-proof-choice hidden>
      <label>Como comprovar a despesa?
      <select class="select" name="proof_method" data-receipt-method>
         <option value="upload">Anexar foto ou nota fiscal</option>
         <option value="receipt_screen">Gerar recibo e assinar agora na tela</option>
         <option value="receipt_print">Gerar recibo para imprimir e assinar</option>
      </select></label>
      <p>Recibo impresso sem assinatura ficará pendente. Não substitui documento fiscal obrigatório.</p>
      <div class="expense-receipt-fields" data-receipt-details hidden>
        <label>Nome do recebedor *<input class="input" name="recipient_name" maxlength="190"></label>
        <label>CPF/CNPJ (opcional)<input class="input" name="recipient_document" maxlength="32"></label>
      </div>
      <div data-receipt-signature hidden>
        <p>Peça ao próprio recebedor que assine com o dedo ou mouse.</p>
        <canvas data-receipt-canvas class="expense-sign-canvas" width="800" height="230" aria-label="Assinatura"></canvas>
        <input type="hidden" name="signature_data" data-signature-output>
        <button class="btn btn-light" type="button" data-signature-clear>Limpar assinatura</button>
        <label><input type="checkbox" name="signature_consent" value="1" data-signature-consent>
          Sou a pessoa indicada e confirmo que recebi o valor.</label>
      </div>
    </div>
  </div>
  <label class="wide">Observações (opcional)<textarea class="textarea" name="notes" maxlength="4000"></textarea></label>
</div>'''

def patch_department_page(source):
    t=source.decode('utf8')
    t=t.replace("require_once __DIR__.'/lib/DepartmentTreasuryService.php';",
                "require_once __DIR__.'/lib/DepartmentTreasuryService.php';\nrequire_once __DIR__.'/lib/TreasurySmartEntry.php';",1)
    anchor="$categories=['Ofertas','Dízimos','Doações','Eventos','Cantina','Material de limpeza','Material de escritório','Transporte','Equipamentos','Manutenção','Ajuda social','Despesas diversas'];"
    if t.count(anchor)!=1:raise RuntimeError('department categories anchor')
    load="""$categoryCatalog=TreasurySmartEntry::categories($pdo,$cid);
$q=$pdo->prepare("SELECT id,name FROM church_registers WHERE church_id=? AND kind=? AND active=1 ORDER BY name");
$q->execute([$cid,'cost_center']);$costCenters=$q->fetchAll(PDO::FETCH_ASSOC);
$q->execute([$cid,'contact']);$contacts=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$contributionMembers=$q->fetchAll(PDO::FETCH_ASSOC);"""
    t=t.replace(anchor,anchor+'\n'+load,1)
    anchor='id="dept-entry-form" data-expense-receipt-form'
    if t.count(anchor)!=1:raise RuntimeError('department entry form anchor')
    t=t.replace(anchor,anchor+' data-smart-treasury="department"',1)
    anchor='<input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>">'
    if t.count(anchor)!=2:raise RuntimeError('unexpected from/to hidden count')
    # Entry form first; account creation form remains unchanged.
    t=t.replace(anchor,anchor+'<input type="hidden" name="ocr_items_json" value="">',1)
    a=t.index('<div class="dept-fields">',t.index('id="dept-entry-form"'))
    b=t.index('<p class="muted">Líder e tesoureiro',a)
    t=t[:a]+FIELDS+'\n'+t[b:]
    from patch_finance_footer import CONTACT
    marker='<?php View::footer(); ?>'
    if t.count(marker)!=1:raise RuntimeError('dept footer anchor')
    t=t.replace(marker,CONTACT+'\n'+marker,1)
    return t.encode('utf8')
