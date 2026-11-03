<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AccountingService.php';

$user=$auth->requireAbility('accounting_portal');
$church=$auth->currentChurch();
if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];
$service=new AccountingService($pdo,$auth,$cid,(string)$user['id']);
try{AccountingService::ensureSchema($pdo);}
catch(Throwable $e){
    error_log('accounting schema '.$e->getMessage());
    http_response_code(503);
    View::header('Contabilidade',$auth,'accounting.php');
    echo '<section class="card card-pad"><h1>Contabilidade</h1><p>A estrutura do módulo contábil precisa ser instalada pelo administrador. Os lançamentos existentes permanecem preservados.</p></section>';
    View::footer();exit;
}
$periodInput=(string)($_GET['period']??$_POST['reference_period']??date('Y-m'));
try{$period=AccountingService::period($periodInput);}catch(Throwable){$period=date('Y-m');}
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='upload'){
            $id=$service->upload($_POST,$_FILES['source_file']??[]);
            flash('success','Arquivo recebido e salvo na pasta contábil desta igreja.');
            redirect('/accounting.php?period='.rawurlencode($period).'&doc='.rawurlencode($id).'#accounting-document');
        }elseif($action==='confirm'){
            $id=trim((string)($_POST['document_id']??''));
            $service->confirmDocument($id,$_POST);
            flash('success','Documento conferido e confirmado.');
            redirect('/accounting.php?period='.rawurlencode($period).'&doc='.rawurlencode($id).'#accounting-document');
        }elseif($action==='review'){
            $service->reviewDocument(trim((string)($_POST['document_id']??'')),(string)($_POST['review_status']??''),(string)($_POST['review_note']??''));
            flash('success','Revisão registrada para a igreja.');
            redirect('/accounting.php?period='.rawurlencode($period).'#accounting-files');
        }elseif($action==='request'){
            $service->requestDocument($period,(string)($_POST['title']??''),(string)($_POST['details']??''));
            flash('success','Pendência enviada ao pastor e à tesouraria.');
            redirect('/accounting.php?period='.rawurlencode($period).'#accounting-requests');
        }elseif($action==='respond'){
            $service->respondRequest((string)($_POST['request_id']??''),(string)($_POST['document_id']??''));
            flash('success','Documento vinculado à solicitação.');
            redirect('/accounting.php?period='.rawurlencode($period).'#accounting-requests');
        }elseif($action==='period'){
            $service->periodStatus($period,(string)($_POST['period_action']??''),(string)($_POST['notes']??''));
            flash('success','Situação da competência atualizada.');
            redirect('/accounting.php?period='.rawurlencode($period).'#accounting-status');
        }else throw new InvalidArgumentException('Operação inválida.');
    }catch(Throwable $e){
        error_log('accounting action: '.get_class($e).' '.$e->getMessage());
        $error=$e instanceof PDOException?'Não foi possível concluir. Revise os dados e tente novamente.':$e->getMessage();
    }
}
$summary=$service->summary($period);
$docs=$service->documents($period);
$entries=$service->entries($period);
$legacy=$service->legacyDocuments($period);
$requests=$service->requests($period);
$categories=$service->categoryNames();
$accounts=$service->accounts();
$selected=null;$selectedItems=[];
if(isset($_GET['doc'])&&trim((string)$_GET['doc'])!==''){
    try{$selected=$service->document(trim((string)$_GET['doc']));$selectedItems=json_decode((string)($selected['item_details_json']??'[]'),true)?:[];}
    catch(Throwable $e){$error=$e->getMessage();}
}
$kindNames=['fiscal_coupon'=>'Cupom fiscal','invoice'=>'Nota fiscal','proof'=>'Comprovante','receipt'=>'Recibo','bank_statement'=>'Extrato bancário','payroll'=>'Folha de pagamento','contract'=>'Contrato','other'=>'Outros documentos'];
$statusNames=['draft'=>'Conferir dados','ready'=>'Pronto para o contador','needs_fix'=>'Correção solicitada','reviewed'=>'Conferido pelo contador'];
$money=static fn(mixed $v):string=>'R$ '.number_format((float)$v,2,',','.');
$safeCsv=static fn(mixed $s):string=>(string)$s;
View::header('Portal do Contador',$auth,'accounting.php');View::flash();
?>
<style>
.acc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}
.acc-grid .card{padding:17px}.acc-grid strong{display:block;font-size:1.55rem}
.acc-grid small{font-size:.85rem;opacity:.75}
.acc-controls{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.acc-controls label{display:grid;gap:5px}.acc-section{margin-top:16px}
.acc-table{overflow-x:auto}.acc-table table{width:100%;border-collapse:collapse;min-width:680px}
.acc-table td,.acc-table th{border-bottom:1px solid var(--border,#e2e8f0);padding:11px 10px;text-align:left;vertical-align:top;font-size:.89rem}
.acc-table th{font-weight:650}
.acc-dialog{width:min(720px,calc(100vw - 16px));max-height:92vh;overflow:auto;border:0;border-radius:18px;padding:24px;box-shadow:0 22px 70px #0004}
.acc-dialog::backdrop{background:#111827aa}
.acc-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:14px 0}
.acc-fields label{display:grid;gap:5px;font-size:.9rem}
.acc-fields .wide{grid-column:1/-1}
.acc-actions{display:flex;gap:9px;flex-wrap:wrap;margin:12px 0}
.acc-note{padding:12px;border-left:3px solid #64748b;background:var(--surface-secondary,#f4f6f9);margin:12px 0}
.acc-badge{display:inline-block;border-radius:999px;background:#edf2f7;color:#334155;padding:3px 9px;font-size:.77rem}
.acc-item{display:grid;grid-template-columns:1.5fr .75fr 1fr;gap:8px;align-items:center;padding:7px 0}
@media(max-width:600px){.acc-item{grid-template-columns:1fr 1fr}.acc-item input:first-child{grid-column:1/-1}}
</style>
<section class="module-toolbar">
 <div class="module-title"><h1>Portal do Contador</h1><p>Documentos, movimentações e prestação de contas de <?=e($church['name'])?>.</p></div>
 <div class="actions">
 <?php if($service->isManager()):?><button class="btn btn-primary" type="button" onclick="document.getElementById('acc-upload').showModal()">+ Enviar nota ou cupom</button><?php endif;?>
 <a class="btn btn-light" href="/accounting-package.php?period=<?=rawurlencode($period)?>">Baixar pacote do mês</a>
 </div>
</section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="card card-pad">
<form method="get" action="/accounting.php" class="acc-controls">
 <label>Competência<input class="input" type="month" name="period" value="<?=e($period)?>" required></label>
 <button class="btn btn-light">Ver mês</button><a class="btn btn-light" href="/finance-control.php?view=report&from=<?=rawurlencode($period.'-01')?>&to=<?=rawurlencode(date('Y-m-t',strtotime($period.'-01')))?>" <?=!$service->isManager()?'hidden':''?>>Livro caixa</a>
</form>
</section>
<div class="acc-grid">
 <div class="card"><strong><?=$money($summary['income']??0)?></strong><small>Entradas contabilizadas no sistema</small></div>
 <div class="card"><strong><?=$money($summary['expense']??0)?></strong><small>Saídas contabilizadas no sistema</small></div>
 <div class="card"><strong><?=e((string)($summary['documents']??0))?></strong><small>Documentos desta competência</small></div>
 <div class="card"><strong><?=e((string)($summary['expense_without_proof']??0))?></strong><small>Despesas sem anexo vinculado</small></div>
 <div class="card"><strong><?=e((string)($summary['pending_review']??0))?></strong><small>Arquivos que exigem atenção</small></div>
 <div class="card"><strong><?=e((string)($summary['open_requests']??0))?></strong><small>Solicitações em aberto</small></div>
</div>
<section class="card card-pad acc-section" id="accounting-status">
 <div class="page-head"><div><h2>Fechamento mensal</h2><p>Organização documental. Não substitui escrituração ou envio de declarações fiscais.</p></div><span class="acc-badge"><?=e(['open'=>'Em preparação','submitted'=>'Entregue ao contador','reviewed'=>'Conferido pelo contador'][$summary['period_status']]??'Em preparação')?></span></div>
 <div class="acc-note">
 Confira se as entradas e saídas estão lançadas, se há comprovantes das despesas, extratos bancários, recibos, contratos, folha de pagamento (quando houver) e solicitações pendentes.
 <strong>O contador valida quais obrigações e documentos efetivamente se aplicam à igreja.</strong>
 </div>
 <?php if($service->isManager()):?>
 <form method="post" class="acc-controls"><?=csrf_field()?><input type="hidden" name="action" value="period"><input type="hidden" name="period_action" value="submit"><input type="hidden" name="reference_period" value="<?=e($period)?>"><label>Observação da entrega<input class="input" name="notes" maxlength="1800" placeholder="Ex.: extratos e comprovantes enviados" style="min-width:250px"></label><button class="btn btn-primary">Entregar mês ao contador</button></form>
 <?php endif;?>
 <?php if($service->isAccountant()):?>
 <form method="post" class="acc-controls"><?=csrf_field()?><input type="hidden" name="action" value="period"><input type="hidden" name="period_action" value="review"><input type="hidden" name="reference_period" value="<?=e($period)?>"><label>Observações do contador<input class="input" name="notes" maxlength="1800" placeholder="Conferido / ressalvas" style="min-width:250px"></label><button class="btn btn-primary">Confirmar conferência do mês</button></form>
 <?php endif;?>
</section>
<section class="card card-pad acc-section" id="accounting-files">
<div class="page-head"><div><h2>Documentos enviados</h2><p>Fotos, PDF, XML, extratos e arquivos organizados pela competência.</p></div></div>
<div class="acc-table"><table><thead><tr><th>Documento</th><th>Data / valor</th><th>Categoria</th><th>Situação</th><th>Arquivo</th></tr></thead><tbody>
<?php foreach($docs as $d):?><tr>
 <td><strong><?=e($d['supplier_name']?:($kindNames[$d['document_kind']]??'Documento'))?></strong><br><small><?=e($d['original_name'])?> · <?=e($d['document_number']?:'Sem número')?></small></td>
 <td><?=e($d['issued_on']?:'Data pendente')?><br><?=$d['total']!==null?$money($d['total']):'Valor pendente'?></td>
 <td><?=e($d['confirmed_category']?:'A confirmar')?></td>
 <td><span class="acc-badge"><?=e($statusNames[$d['status']]??$d['status'])?></span><?php if($d['review_note']):?><br><small><?=e($d['review_note'])?></small><?php endif;?></td>
 <td><a class="btn btn-light" href="/accounting-file.php?id=<?=rawurlencode($d['id'])?>">Baixar</a> <a class="btn btn-light" href="?period=<?=rawurlencode($period)?>&doc=<?=rawurlencode($d['id'])?>#accounting-document">Abrir</a></td>
</tr><?php endforeach;?>
<?php if(!$docs):?><tr><td colspan="5"><div class="empty">Nenhum documento enviado neste mês.</div></td></tr><?php endif;?>
</tbody></table></div>
</section>
<?php if($selected):?>
<section class="card card-pad acc-section" id="accounting-document">
 <div class="page-head"><div><h2>Documento: <?=e($selected['original_name'])?></h2><p><?=e($statusNames[$selected['status']]??$selected['status'])?> · <?=e($kindNames[$selected['document_kind']]??'Documento')?></p></div><a class="btn btn-light" href="/accounting-file.php?id=<?=rawurlencode($selected['id'])?>">Ver original</a></div>
 <div class="acc-note">Os dados extraídos de imagens e XML são sugestões, não validação fiscal. Confira os valores com o original antes de registrar despesas.</div>
 <?php if($service->isManager()&&!$selected['finance_entry_id']):?>
 <form method="post" class="acc-confirm-form"><?=csrf_field()?><input type="hidden" name="action" value="confirm"><input type="hidden" name="reference_period" value="<?=e($period)?>"><input type="hidden" name="document_id" value="<?=e($selected['id'])?>">
 <div class="acc-fields">
 <label>Fornecedor<input class="input" name="supplier_name" maxlength="190" value="<?=e($selected['supplier_name']??'')?>"></label>
 <label>CNPJ do fornecedor<input class="input" name="supplier_cnpj" maxlength="18" value="<?=e($selected['supplier_cnpj']??'')?>"></label>
 <label>Número do documento<input class="input" name="document_number" maxlength="60" value="<?=e($selected['document_number']??'')?>"></label>
 <label>Data de emissão<input class="input" type="date" name="issued_on" value="<?=e($selected['issued_on']??'')?>"></label>
 <label>Valor total (R$)<input class="input" name="total" value="<?=e($selected['total']??'')?>" inputmode="decimal"></label>
 <label>Categoria contábil<input class="input" name="confirmed_category" list="accounting-categories" maxlength="160" value="<?=e($selected['confirmed_category']?:$selected['suggested_category']??'')?>"></label>
 <label>Conta para despesa<select class="select" name="account_id"><option value="">Não informar conta</option><?php foreach($accounts as $account):?><option value="<?=e($account['id'])?>"><?=e($account['name'])?></option><?php endforeach;?></select></label>
 </div>
 <?php if($selectedItems):?><h3>Itens identificados — confira as categorias</h3><div class="acc-items" data-accounting-items><?php foreach($selectedItems as $i):?><div class="acc-item"><input class="input" data-item-name value="<?=e($i['name']??'')?>" maxlength="160"><input class="input" data-item-amount value="<?=e($i['amount']??'')?>"><input class="input" data-item-category list="accounting-categories" value="<?=e($i['category']??'')?>" maxlength="160"></div><?php endforeach;?></div><?php endif;?>
 <input type="hidden" name="items_json" value="<?=e(json_encode($selectedItems,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>">
 <div class="acc-actions"><label><input type="checkbox" name="post_expense" value="1"> Também criar saída no livro caixa após confirmar</label></div>
 <button class="btn btn-primary">Conferir e salvar</button></form>
 <?php elseif($selected['finance_entry_id']):?><p>Este documento já está vinculado ao lançamento <?=e($selected['finance_entry_id'])?>. Ajustes financeiros devem ser feitos pela tesouraria com o devido histórico.</p><?php endif;?>
 <?php if($service->isAccountant()):?>
 <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="review"><input type="hidden" name="reference_period" value="<?=e($period)?>"><input type="hidden" name="document_id" value="<?=e($selected['id'])?>">
 <div class="acc-fields"><label>Situação da análise<select class="select" name="review_status"><option value="reviewed">Documento conferido</option><option value="needs_fix">Solicitar correção</option></select></label><label class="wide">Observação<input class="input" name="review_note" maxlength="2000" placeholder="Ex.: falta identificação do fornecedor"></label></div><button class="btn btn-primary">Registrar revisão</button></form>
 <?php endif;?>
</section>
<?php endif;?>
<section class="card card-pad acc-section" id="accounting-requests">
<div class="page-head"><div><h2>Pendências para preparar a contabilidade</h2><p>O contador solicita documentos; o pastor ou tesoureiro vincula o arquivo enviado.</p></div></div>
<?php if($service->isAccountant()):?><form method="post" class="acc-controls"><?=csrf_field()?><input type="hidden" name="action" value="request"><input type="hidden" name="reference_period" value="<?=e($period)?>"><label>O que falta?<input class="input" name="title" maxlength="190" required placeholder="Ex.: extrato bancário do mês"></label><label>Detalhes<input class="input" name="details" maxlength="2000" placeholder="Instruções de conferência"></label><button class="btn btn-primary">Solicitar documento</button></form><?php endif;?>
<div class="acc-table"><table><thead><tr><th>Pendência</th><th>Solicitado por</th><th>Situação / resposta</th></tr></thead><tbody>
<?php foreach($requests as $request):?><tr><td><strong><?=e($request['title'])?></strong><br><small><?=e($request['details']??'')?></small></td>
<td><?=e($request['requested_by']??'Contador')?></td><td><?=e($request['status']==='responded'?'Arquivo enviado':'Aguardando resposta')?>
<?php if($service->isManager()&&$request['status']==='open'):?>
<form method="post" class="acc-controls"><?=csrf_field()?><input type="hidden" name="action" value="respond"><input type="hidden" name="reference_period" value="<?=e($period)?>"><input type="hidden" name="request_id" value="<?=e($request['id'])?>"><label>Documento<select class="select" name="document_id" required><option value="">Selecione o arquivo</option><?php foreach($docs as $doc):?><option value="<?=e($doc['id'])?>"><?=e($doc['original_name'])?></option><?php endforeach;?></select></label><button class="btn btn-light">Vincular resposta</button></form>
<?php endif;?></td></tr><?php endforeach;?>
<?php if(!$requests):?><tr><td colspan="3"><div class="empty">Nenhuma pendência registrada pelo contador neste mês.</div></td></tr><?php endif;?>
</tbody></table></div>
</section>
<section class="card card-pad acc-section"><div class="page-head"><div><h2>Movimentações financeiras</h2><p>Espelho do livro caixa, sem editar saldos pelo portal do contador.</p></div></div>
<div class="acc-table"><table><thead><tr><th>Data</th><th>Movimento</th><th>Descrição</th><th>Categoria</th><th>Conta</th><th>Valor</th></tr></thead><tbody>
<?php foreach(array_slice($entries,0,300) as $entry):?><tr>
<td><?=e($entry['transaction_date'])?></td><td><?=e($entry['direction']==='income'?'Entrada':'Saída')?> · <?=e($entry['status'])?></td><td><?=e($entry['description'])?></td><td><?=e($entry['category'])?></td><td><?=e($entry['account_name']??'—')?></td><td><?=$money($entry['amount'])?></td></tr><?php endforeach;?>
<?php if(!$entries):?><tr><td colspan="6">Nenhuma movimentação no período.</td></tr><?php endif;?>
</tbody></table></div><p class="muted">Mostrando até 300 movimentações. O pacote mensal contém a exportação completa.</p></section>
<section class="card card-pad acc-section"><h2>Comprovantes já arquivados na tesouraria</h2><p>Inclui documentos antigos vinculados aos lançamentos, sem precisar recadastrá-los.</p>
<div class="acc-table"><table><thead><tr><th>Documento</th><th>Emissão</th><th>Arquivo</th></tr></thead><tbody>
<?php foreach(array_slice($legacy,0,200) as $doc):?><tr><td><?=e($doc['document_type'])?> · <?=e($doc['original_name'])?></td><td><?=e($doc['issued_at']??'—')?></td><td><a class="btn btn-light" href="/accounting-file.php?kind=legacy&id=<?=rawurlencode($doc['id'])?>">Baixar</a></td></tr><?php endforeach;?>
<?php if(!$legacy):?><tr><td colspan="3">Nenhum comprovante antigo neste mês.</td></tr><?php endif;?>
</tbody></table></div></section>
<datalist id="accounting-categories"><?php foreach($categories as $name):?><option value="<?=e($name)?>"><?php endforeach;?></datalist>
<?php if($service->isManager()):?>
<dialog class="acc-dialog" id="acc-upload" aria-labelledby="acc-upload-title">
<div class="page-head"><div><h2 id="acc-upload-title">Cadastrar nota, cupom ou comprovante</h2><p>Tire uma foto, faça a leitura e confira antes de salvar.</p></div></div>
<form method="post" enctype="multipart/form-data" id="acc-upload-form"><?=csrf_field()?><input type="hidden" name="action" value="upload"><input type="hidden" name="reference_period" value="<?=e($period)?>"><input type="hidden" name="ocr_text" id="acc-ocr-text"><input type="hidden" name="items_json" id="acc-items-json">
<div class="acc-fields">
 <label class="wide">Foto, PDF ou XML<input type="file" name="source_file" id="acc-source" accept="image/*,.pdf,.xml,application/pdf,application/xml" capture="environment" required></label>
 <label>Tipo de arquivo<select class="select" name="document_kind"><?php foreach($kindNames as $kind=>$label):?><option value="<?=e($kind)?>"><?=e($label)?></option><?php endforeach;?></select></label>
 <label>Fornecedor<input class="input" name="supplier_name" maxlength="190" id="acc-supplier" placeholder="Nome no comprovante"></label>
 <label>CNPJ<input class="input" name="supplier_cnpj" id="acc-cnpj" maxlength="18"></label>
 <label>Número<input class="input" name="document_number" id="acc-number" maxlength="60"></label>
 <label>Data de emissão<input class="input" name="issued_on" id="acc-issued" type="date"></label>
 <label>Valor total (R$)<input class="input" name="total" id="acc-total" inputmode="decimal" placeholder="0,00"></label>
 <label>Categoria sugerida<input class="input" name="confirmed_category" id="acc-category" list="accounting-categories" maxlength="160" placeholder="Confira a categoria"></label>
 <label>Conta financeira<select class="select" name="account_id"><option value="">Selecionar depois</option><?php foreach($accounts as $account):?><option value="<?=e($account['id'])?>"><?=e($account['name'])?></option><?php endforeach;?></select></label>
</div>
<div class="acc-actions"><button class="btn btn-light" type="button" id="acc-read-ocr">Ler foto automaticamente</button><span id="acc-ocr-state" role="status" aria-live="polite">OCR disponível para fotos; XML é extraído no servidor.</span></div>
<div id="acc-item-preview" aria-label="Itens identificados no cupom"></div>
<p class="muted">A imagem é analisada no navegador. Se não funcionar, os campos podem ser preenchidos manualmente. A leitura não confirma a validade da nota fiscal.</p>
<label><input type="checkbox" name="confirmed" value="1"> Conferi os dados com o documento original</label>
<div class="acc-actions"><label><input type="checkbox" name="post_expense" value="1"> Lançar como saída no caixa (somente após conferir)</label></div>
<div class="acc-actions"><button class="btn btn-light" type="button" onclick="document.getElementById('acc-upload').close()">Cancelar</button><button class="btn btn-primary">Salvar documento</button></div>
</form></dialog>
<script src="/assets/accounting-ocr.js?v=379" defer></script>
<?php endif;?>
<?php View::footer(); ?>
