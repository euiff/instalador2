<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/ExpenseReceiptService.php';

$user=$auth->requireLogin();
$church=$auth->currentChurch();
if(!$church){http_response_code(403);exit('Igreja não selecionada.');}
$cid=(string)$church['id'];
$service=new ExpenseReceiptService($pdo,$auth,$cid,(string)$user['id']);
ExpenseReceiptService::ensureSchema($pdo);
$entryId=(string)($_POST['entry_id']??$_GET['entry']??'');
$error=null;
try{
    $entry=$service->entry($entryId);
    $receipt=$service->receipt($entryId);
}catch(Throwable $e){
    http_response_code(403);
    exit('Esta despesa não está disponível no seu acesso.');
}
if(($_GET['format']??'')==='pdf'){
    try{
        $bytes=$service->pdf($entryId);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="recibo-despesa.pdf"');
        header('Content-Length: '.strlen($bytes));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;exit;
    }catch(Throwable $e){http_response_code(404);exit('Não foi possível gerar o recibo PDF.');}
}
if(isset($_GET['file'])){
    try{
        $doc=$service->signedDocument($entryId);
        if(!$doc)throw new RuntimeException('Arquivo não encontrado.');
        $q=$pdo->prepare('SELECT storage_path,original_name,mime_type FROM finance_documents WHERE church_id=? AND entry_id=? AND id=?');
        $q->execute([$cid,$entryId,$doc['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Comprovante não encontrado.');
        $rel=(string)$row['storage_path'];
        if(!preg_match('#^storage/private/finance/[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$#D',$rel))
            throw new RuntimeException('Arquivo privado inválido.');
        $root=realpath(__DIR__.'/storage/private/finance');
        $abs=realpath(__DIR__.'/'.$rel);
        if(!$root||!$abs||!str_starts_with($abs,$root.DIRECTORY_SEPARATOR)||!is_file($abs))
            throw new RuntimeException('Arquivo privado não encontrado.');
        $mime=(string)$row['mime_type'];
        if(!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp'],true))
            throw new RuntimeException('Documento com formato não permitido.');
        header('Content-Type: '.$mime);
        header('Content-Disposition: attachment; filename="comprovante-assinado"');
        header('Content-Length: '.filesize($abs));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);exit;
    }catch(Throwable $e){http_response_code(404);exit('Comprovante indisponível para seu acesso.');}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if(!$service->canEdit($entryId))
            throw new RuntimeException('Somente a tesouraria responsável pode emitir ou assinar recibos.');
        if($action==='create'){
            if($receipt)throw new RuntimeException('O recibo desta despesa já existe.');
            $service->create($entryId,$_POST);
            flash('success','Recibo gerado e vinculado à despesa.');
        }elseif($action==='sign'){
            $service->signOnScreen($entryId,$_POST);
            flash('success','Recibo assinado na tela e arquivado em PDF para a tesouraria e o contador.');
        }elseif($action==='paper'){
            $service->attachSignedPaper($entryId,$_FILES['signed_file']??[]);
            flash('success','Foto/PDF do recibo assinado foi arquivado e vinculado à despesa.');
        }else throw new InvalidArgumentException('Operação desconhecida.');
        redirect('/expense-receipt.php?entry='.rawurlencode($entryId));
    }catch(Throwable $e){
        error_log('expense receipt: '.get_class($e).' '.$e->getMessage());
        $error=$e instanceof PDOException?'Não foi possível salvar o recibo. Nenhum documento foi concluído.':$e->getMessage();
    }
}
$edit=$service->canEdit($entryId);
$doc=$receipt?$service->signedDocument($entryId):null;
$pending=$receipt && $receipt['status']==='pending_signature';
$printPdf='/expense-receipt.php?entry='.rawurlencode($entryId).'&format=pdf';
View::header('Recibo de Despesa',$auth,'expense-receipt.php');View::flash();
?>
<style>
.recipt-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(275px,.85fr);gap:16px}
.recipt-fields{display:grid;gap:12px}.recipt-fields label{display:grid;gap:6px}
.recipt-status{padding:12px;border-radius:12px;border:1px solid #d0e7ed;background:#eff8fa}
.recipt-pending{border-color:#ead5a3;background:#fff9e8}
.recipt-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.recipt-preview{background:#fff;border:1px solid #dbe6ec;border-radius:12px;padding:21px}
.recipt-preview h2{text-align:center;letter-spacing:.02em;margin:0}
.recipt-preview hr{border:0;border-top:1px solid #dae4ea;margin:16px 0}
.recipt-preview p{line-height:1.55}
.recipt-caption{font-size:12px;color:#6a7d8d}
@media(max-width:850px){.recipt-grid{grid-template-columns:1fr}}
</style>
<section class="module-toolbar">
<div class="module-title"><h1>Recibo de despesa</h1>
<p>Emita, assine ou imprima o recibo referente a uma despesa já registrada.</p></div>
<div class="actions">
<a class="btn btn-light" href="<?=$auth->can('finance')?'/finance.php':'/department-treasury.php'?>">Voltar ao financeiro</a>
<?php if($receipt):?><a class="btn btn-primary" href="<?=e($printPdf)?>" target="_blank" rel="noopener">Abrir PDF / Imprimir</a><?php endif;?>
</div></section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<div class="recipt-grid">
<section class="card card-pad">
<h2>Despesa selecionada</h2>
<div class="recipt-preview">
<h2>RECIBO DE DESPESA</h2>
<p style="text-align:center"><strong><?=e($entry['church_name'])?></strong>
<?php if(!empty($entry['department_name'])):?><br><?=e($entry['department_name'])?><?php endif;?></p>
<hr>
<p><strong>Valor pago:</strong> R$ <?=number_format((float)$entry['amount'],2,',','.')?></p>
<p><strong>Data:</strong> <?=e(date('d/m/Y',strtotime($entry['transaction_date'])))?></p>
<p><strong>Categoria:</strong> <?=e($entry['category'])?></p>
<p><strong>Descrição:</strong> <?=e($entry['description'])?></p>
<p><strong>Forma de pagamento:</strong> <?=e($entry['payment_method']?:'Não informada')?></p>
<?php if($receipt):?>
<p><strong>Recebedor(a):</strong> <?=e($receipt['recipient_name'])?></p>
<p><strong>Identificação:</strong> <?=e($receipt['recipient_document']?:'Não informada')?></p>
<hr>
<div class="recipt-status <?=$pending?'recipt-pending':''?>">
<?php if($pending):?>
<strong>Aguardando assinatura</strong>
<p>Este recibo pode ser impresso, mas ainda não é considerado comprovante completo pelo financeiro.</p>
<?php else:?>
<strong>Recibo concluído</strong>
<p><?=e($receipt['status']==='signed_screen'?'Assinado na tela. PDF arquivado.':'Recibo assinado fisicamente e comprovante anexado.')?></p>
<?php endif;?>
</div>
<?php else:?>
<div class="recipt-status recipt-pending">Ainda não foi gerado um recibo para esta despesa.</div>
<?php endif;?>
</div>
<?php if($receipt):?>
<div class="recipt-actions">
<a class="btn btn-primary" href="<?=e($printPdf)?>" target="_blank" rel="noopener">Visualizar PDF para imprimir</a>
<?php if($doc):?><a class="btn btn-light" href="/expense-receipt.php?entry=<?=rawurlencode($entryId)?>&file=1">Baixar comprovante assinado</a><?php endif;?>
</div>
<?php endif;?>
<p class="recipt-caption">Um recibo é documento de quitação, mas não substitui nota fiscal quando ela for legalmente exigida. A assinatura feita na tela não comprova automaticamente a identidade de quem assinou.</p>
</section>
<aside class="card card-pad">
<?php if(!$receipt):?>
<h2>Gerar recibo desta despesa</h2>
<?php if($edit):?>
<form class="recipt-fields" method="post" data-expense-sign-form><?=csrf_field()?>
<input type="hidden" name="action" value="create">
<input type="hidden" name="entry_id" value="<?=e($entryId)?>">
<label>Nome de quem recebeu o pagamento *
<input class="input" name="recipient_name" value="<?=e($entry['counterparty']??'')?>" maxlength="190" required></label>
<label>CPF ou CNPJ (opcional)
<input class="input" name="recipient_document" maxlength="32"></label>
<label>Como deseja obter a assinatura?
<select name="proof_method" class="select" data-receipt-method>
<option value="receipt_print">Imprimir para assinar à mão</option>
<option value="receipt_screen">Assinar agora na tela</option></select></label>
<div data-receipt-signature hidden>
<p class="recipt-caption">Peça à pessoa que recebeu o pagamento para assinar com o dedo ou mouse.</p>
<canvas class="expense-sign-canvas" data-receipt-canvas width="800" height="230" aria-label="Área para assinatura do recebedor"></canvas>
<input type="hidden" name="signature_data" data-signature-output>
<button type="button" class="btn btn-light" data-signature-clear>Limpar assinatura</button>
<label><input type="checkbox" name="signature_consent" value="1" data-signature-consent> Sou a pessoa indicada como recebedora e confirmo que recebi o valor informado.</label>
</div>
<button class="btn btn-primary">Gerar recibo desta despesa</button>
</form>
<?php else:?>Somente a tesouraria responsável pode gerar o recibo.<?php endif;?>
<?php elseif($pending):?>
<h2>Finalizar recibo pendente</h2>
<p>Escolha uma das duas maneiras de concluir o comprovante.</p>
<?php if($edit):?>
<h3>Assinar agora na tela</h3>
<form method="post" class="recipt-fields" data-expense-sign-form><?=csrf_field()?>
<input type="hidden" name="action" value="sign">
<input type="hidden" name="entry_id" value="<?=e($entryId)?>">
<label>Confirme o nome exato de quem está assinando
<input class="input" name="confirm_recipient_name" maxlength="190" placeholder="<?=e($receipt['recipient_name'])?>" required></label>
<p class="recipt-caption">O recebedor assina com o dedo ou o mouse:</p>
<canvas class="expense-sign-canvas" data-receipt-canvas width="800" height="230" aria-label="Assinatura do recebedor"></canvas>
<input type="hidden" name="signature_data" data-signature-output>
<button type="button" class="btn btn-light" data-signature-clear>Limpar assinatura</button>
<label><input type="checkbox" name="signature_consent" value="1" required> Sou a pessoa indicada e confirmo que recebi o valor da despesa.</label>
<button class="btn btn-primary">Salvar assinatura e concluir recibo</button>
</form>
<hr>
<h3>Ou anexar o recibo assinado no papel</h3>
<form method="post" enctype="multipart/form-data" class="recipt-fields"><?=csrf_field()?>
<input type="hidden" name="action" value="paper"><input type="hidden" name="entry_id" value="<?=e($entryId)?>">
<label>Foto ou PDF do recibo assinado
<input class="input" type="file" name="signed_file" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
<button class="btn btn-primary">Arquivar recibo assinado</button>
</form>
<?php else:?>Aguardando a tesouraria responsável concluir a assinatura.<?php endif;?>
<?php else:?>
<h2>Recibo finalizado</h2>
<p>O comprovante está vinculado à mesma despesa e disponível para a tesouraria geral e o contador, conforme as permissões.</p>
<?php endif;?>
</aside></div>
<?php View::footer();?>
