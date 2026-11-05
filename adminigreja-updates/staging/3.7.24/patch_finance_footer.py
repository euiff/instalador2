from patch_finance_core import replace_once

CONTACT = r'''<dialog data-treasury-contact-dialog aria-labelledby="smart-contact-title">
  <h2 id="smart-contact-title">Novo contato ou fornecedor</h2>
  <p class="muted">O contato será salvo somente nesta igreja e aparecerá na lista deste lançamento.</p>
  <form method="post">
    <label>Nome *<input class="input" name="name" maxlength="190" required></label>
    <label>Telefone (opcional)<input class="input" name="phone" maxlength="40"></label>
    <label>E-mail (opcional)<input class="input" name="email" type="email" maxlength="190"></label>
    <small data-contact-response role="status" aria-live="polite"></small>
    <div class="actions">
      <button type="button" class="btn btn-light" data-contact-close>Cancelar</button>
      <button type="submit" class="btn btn-primary">Salvar contato</button>
    </div>
  </form>
</dialog>
<link rel="stylesheet" href="/assets/treasury-smart.css?v=3724">
<script src="/assets/accounting-ocr.js?v=3724" defer></script>
<script src="/assets/treasury-smart.js?v=3724" defer></script>
<script src="/assets/treasury-ocr.js?v=3724" defer></script>'''

def patch_footer(source):
    text=source.decode('utf8')
    text=replace_once(text,'<?php View::footer();?>',CONTACT+'\n<?php View::footer();?>','contact modal and scripts')
    return text.encode('utf8')

def expose_ocr_loader(source):
    text=source.decode('utf8')
    text=replace_once(text,
       "  const form=document.getElementById('acc-upload-form');\n  if(!form)return;",
       "  const form=document.getElementById('acc-upload-form');\n  window.IgrejaOCRLoad=loadTesseract;\n  if(!form)return;",
       'safe existing OCR loader shared with treasury')
    return text.encode('utf8')
