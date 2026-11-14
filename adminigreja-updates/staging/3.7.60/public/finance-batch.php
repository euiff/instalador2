<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/RegistryService.php';
require_once __DIR__.'/lib/DepartmentTreasuryService.php';
require_once __DIR__.'/lib/UploadService.php';

$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$registry=new RegistryService($pdo,$auth,$cid);
$cats=[];$seen=[];
foreach($registry->all('finance_category') as $r){
    $name=trim((string)($r['name']??''));
    $key=mb_strtolower($name,'UTF-8');
    if($name===''||isset($seen[$key]))continue;
    $seen[$key]=true;
    $direction=(string)($r['data']['direction']??'both');
    $cats[]=['name'=>$name,'direction'=>in_array($direction,['income','expense','both'],true)?$direction:'both'];
}
$q=$pdo->prepare('SELECT id,name,account_type,department_id FROM finance_accounts WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$accountRows=$q->fetchAll(PDO::FETCH_ASSOC);
$allowDepartments=$auth->can('department_treasury') && (new DepartmentTreasuryService($pdo,$auth,$cid,(string)$auth->requireLogin()['id']))->mayPost();
$accounts=array_values(array_filter($accountRows, static fn(array $a)=>empty($a['department_id'])||$allowDepartments));
$phpMax=UploadService::financeLimits()['php']??null;
$maxFileBytes=min(12*1024*1024,$phpMax!==null?max(1,(int)$phpMax-131072):12*1024*1024);
View::header('Lançamento de vários cupons',$auth,'finance.php');
?>
<style>
.batch-wrap{max-width:980px;margin:0 auto;padding:0 2px 110px}
.batch-upload-panel{border:1px solid #bfd5e7;border-radius:17px;background:#eff7ff;padding:18px;margin:14px 0}
.batch-upload-panel h2{font-size:19px;color:#123b64;margin:0 0 10px}
.batch-upload-panel p{font-size:14px;line-height:1.5}
.batch-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.batch-file-label{display:inline-flex;gap:6px;align-items:center;justify-content:center;padding:12px 16px;background:#09569a;color:white;border-radius:10px;font-weight:700;cursor:pointer}
.batch-camera-label{background:#fff;color:#104b79;border:1px solid #9cc7e3}
.batch-file-label input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.batch-summary{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}
.batch-summary span{padding:9px 13px;background:#f1f6fa;border-radius:8px}
.batch-receipt{border:1px solid #cbd9e5;border-radius:14px;background:#fff;padding:15px;margin:12px 0;box-shadow:0 2px 7px #17354b0c}
.batch-head{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.batch-preview{max-height:100px;max-width:85px;border-radius:8px;object-fit:contain;border:1px solid #ddd}
.batch-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:13px}
.batch-field{display:flex;flex-direction:column;gap:5px}
.batch-field label{font-weight:600;font-size:13px;color:#214467}
.batch-field input,.batch-field select,.batch-field textarea{width:100%;min-height:42px;border:1px solid #b7cad9;padding:8px 10px;border-radius:9px;background:#fff;color:#183a56}
.batch-field textarea{min-height:55px}
.batch-status{font-size:13px;padding:7px 9px;background:#f0f5f9;border-radius:7px}
.batch-error{color:#952d29;background:#fff0ed}
.batch-success{color:#116341;background:#e8f7ed}
.batch-pending{color:#8a590e;background:#fff3db}
.batch-document-note{font-size:12px;color:#67778a;overflow-wrap:anywhere}
.batch-submit-sticky{position:sticky;bottom:12px;background:#fff;border:1px solid #cfdae8;box-shadow:0 5px 20px #10182820;border-radius:15px;padding:14px;z-index:3;margin:20px 0}
.batch-primary{background:#09569a;color:#fff;border:0;border-radius:10px;padding:13px 17px;font-weight:700;cursor:pointer;width:100%}
.batch-primary:disabled{opacity:.5;cursor:not-allowed}
.batch-secondary{background:#f3f8fb;color:#184a71;border:1px solid #b5cbdc;border-radius:9px;padding:8px 10px;cursor:pointer;font-weight:600}
.batch-duplicates{background:#fff0ed;color:#8e2722;border:1px solid #efb8b0;border-radius:8px;padding:10px;margin-top:10px;font-size:13px}
.batch-empty{padding:28px;text-align:center;background:#fff;border:1px dashed #a6bdd0;border-radius:12px;color:#59728a}
.batch-required{color:#bd312d}
@media(max-width:560px){.batch-grid{grid-template-columns:1fr}.batch-upload-panel{padding:13px}.batch-receipt{padding:12px}.batch-upload-panel h2{font-size:17px}}
</style>
<div class="batch-wrap" data-finance-batch="1" data-max-receipts="8" data-max-file-bytes="<?=e((string)$maxFileBytes)?>">
  <div class="page-head">
    <div><h1>Lançar vários cupons</h1><p>Cada foto ou PDF será lido e registrado como um lançamento separado, com seu próprio comprovante.</p></div>
    <a class="btn btn-light" href="/finance.php">Voltar à Tesouraria</a>
  </div>
  <section class="batch-upload-panel">
    <h2>1. Escolha ou fotografe os cupons</h2>
    <details style="margin:8px 0"><summary style="cursor:pointer;font-weight:650">ⓘ Ajuda para os lançamentos múltiplos</summary><p>Escolha até 8 fotos ou PDFs. A IA lê cada arquivo separadamente. Os lançamentos só são registrados depois de conferir e confirmar os dados.</p></details>
    <div class="batch-actions">
      <label class="batch-file-label">+ Selecionar fotos ou PDFs
        <input data-batch-gallery type="file" multiple accept="image/*,application/pdf,.pdf" aria-label="Adicionar várias fotos ou PDFs">
      </label>
      <label class="batch-file-label batch-camera-label"> <span aria-hidden="true">📷</span> Tirar foto de um cupom
        <input data-batch-camera type="file" accept="image/*" capture="environment" aria-label="Tirar foto de outro cupom">
      </label>
    </div>
    <p class="batch-document-note">Cada arquivo deve respeitar o limite de <?=e(number_format($maxFileBytes/1048576,1,',','.'))?> MB da hospedagem. A leitura usa a mesma IA já configurada no Master. A foto original é guardada como anexo do lançamento; a análise pode enviar uma cópia compactada à IA. Arquivos HEIC podem precisar ser convertidos para JPG para leitura.</p>
    <div class="batch-field"><label for="batch-account">Centro de custo / conta de destino dos cupons</label>
      <select id="batch-account" data-batch-default-account>
        <option value="">Tesouraria Geral — sem conta específica</option>
        <?php foreach($accounts as $account):?>
        <option value="<?=e((string)$account['id'])?>"><?=e((string)$account['name'])?> (<?=e(!empty($account['department_id'])?'Departamento / ':'')?><?=e($account['account_type']==='bank'?'Banco':'Caixa')?>)</option>
        <?php endforeach?>
      </select>
      <small>Você pode alterar a conta de cada cupom individualmente antes de salvar.</small>
    </div>
  </section>
  <?=csrf_field()?>
  <template data-batch-category-template><select>
    <option value="">Selecione uma categoria da igreja</option>
    <?php foreach($cats as $category):?><option value="<?=e($category['name'])?>" data-direction="<?=e($category['direction'])?>"><?=e($category['name'])?></option><?php endforeach?>
  </select></template>
  <template data-batch-account-template><select>
    <option value="">Tesouraria Geral — sem conta específica</option>
    <?php foreach($accounts as $account):?><option value="<?=e((string)$account['id'])?>"><?=e((string)$account['name'])?></option><?php endforeach?>
  </select></template>
  <div class="batch-summary"><span data-batch-count>0 cupons</span><span data-batch-ready>0 prontos</span><span data-batch-saved>0 registrados</span></div>
  <div data-batch-list role="list"><div class="batch-empty">Os cupons selecionados aparecerão aqui, separados, para conferência.</div></div>
  <div class="batch-submit-sticky">
    <button class="batch-primary" type="button" data-batch-submit disabled>Conferir e registrar cupons separadamente</button>
    <p class="batch-document-note" data-batch-global-status role="status" aria-live="polite">A IA preenche os dados; você confirma uma única vez. Erros em um cupom não duplicam os demais.</p>
  </div>
</div>
<script defer src="/assets/finance-batch-3756.js?v=3.7.56"></script>
<?php View::footer(); ?>
