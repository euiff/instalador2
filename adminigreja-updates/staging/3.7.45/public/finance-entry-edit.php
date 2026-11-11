<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/FinancePeriodGuard.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch();if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$id=trim((string)($_GET['id']??$_POST['entry_id']??''));
$q=$pdo->prepare('SELECT e.*,a.name account_name FROM finance_entries e LEFT JOIN finance_accounts a ON a.church_id=e.church_id AND a.id=e.account_id WHERE e.church_id=? AND e.id=? LIMIT 1');
$q->execute([$cid,$id]);$entry=$q->fetch(PDO::FETCH_ASSOC);
if(!$entry){http_response_code(404);exit('Lançamento não encontrado nesta igreja.');}
$error=null;
$uploadAjax=(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest')&&((string)($_POST['action']??'')==='attach');
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='details'){
            $category=trim((string)($_POST['category']??''));
            $description=trim((string)($_POST['description']??''));
            $documentNumber=trim((string)($_POST['document_number']??''));
            $counterparty=trim((string)($_POST['counterparty']??''));
            $paymentMethod=trim((string)($_POST['payment_method']??''));
            $notes=trim((string)($_POST['notes']??''));
            $newAccountId=trim((string)($_POST['account_id']??''))?:null;
            if($category===''||$description===''||mb_strlen($category)>160||mb_strlen($description)>255
                ||mb_strlen($documentNumber)>190||mb_strlen($counterparty)>190||mb_strlen($paymentMethod)>60||mb_strlen($notes)>3000)
                throw new InvalidArgumentException('Informe lançamento contábil, descrição e número de documento válidos.');
            FinancePeriodGuard::assertOpen($pdo,$cid,(string)$entry['transaction_date']);
            $pdo->beginTransaction();
            try {
                // Trava e reconfere o estado: duas pessoas não podem alterar a mesma
                // movimentação simultaneamente nem sobrescrever dados mais recentes.
                $q=$pdo->prepare('SELECT id,account_id,department_id,status,category,description,document_number,counterparty,payment_method,notes,transaction_date
                    FROM finance_entries WHERE church_id=? AND id=? FOR UPDATE');
                $q->execute([$cid,$id]);$current=$q->fetch(PDO::FETCH_ASSOC);
                if(!$current||$current['status']!=='posted')
                    throw new RuntimeException('Este lançamento foi estornado ou não está disponível.');
                if((string)($current['account_id']??'')!==(string)($_POST['original_account_id']??'')
                    ||(string)($current['document_number']??'')!==(string)($_POST['original_document_number']??''))
                    throw new RuntimeException('Alguém já alterou o lançamento. Atualize a página e confira antes de salvar.');
                $oldAccountId=$current['account_id']?:null;
                if(!empty($current['department_id'])){
                    if($newAccountId!==$oldAccountId)
                        throw new RuntimeException('A transferência entre caixas departamentais exige operação específica. Este lançamento não foi alterado.');
                    if(!$auth->can('department_treasury'))
                        throw new RuntimeException('Sem permissão para editar lançamento de departamento.');
                }elseif($newAccountId!==null){
                    $q=$pdo->prepare('SELECT id,department_id,active FROM finance_accounts WHERE church_id=? AND id=?');
                    $q->execute([$cid,$newAccountId]);$newAccount=$q->fetch(PDO::FETCH_ASSOC);
                    if(!$newAccount||(int)$newAccount['active']!==1||!empty($newAccount['department_id']))
                        throw new RuntimeException('Escolha uma conta ou caixa ativo da Tesouraria Geral.');
                }
                if($newAccountId!==$oldAccountId){
                    // Não reclassificar contas de períodos fechados ou conciliados.
                    $q=$pdo->prepare('SELECT 1 FROM finance_closings
                        WHERE church_id=? AND period_end>=? AND (account_id IS NULL OR account_id=? OR account_id=?) LIMIT 1');
                    $q->execute([$cid,$current['transaction_date'],$oldAccountId,$newAccountId]);
                    if($q->fetchColumn())
                        throw new RuntimeException('Existe fechamento financeiro envolvendo esta data. Reabra/corrija o período antes de trocar o caixa.');
                    $q=$pdo->prepare('SELECT 1 FROM finance_reconciliations
                        WHERE church_id=? AND reference_date>=? AND (account_id=? OR account_id=?) LIMIT 1');
                    $q->execute([$cid,$current['transaction_date'],$oldAccountId,$newAccountId]);
                    if($q->fetchColumn())
                        throw new RuntimeException('Existe conciliação bancária posterior a esta movimentação. Revise a conciliação antes de trocar a conta.');
                }
                $pdo->prepare("UPDATE finance_entries SET account_id=?,category=?,description=?,document_number=?,counterparty=?,payment_method=?,notes=?
                    WHERE church_id=? AND id=? AND status='posted'")->execute([
                    $newAccountId,$category,$description,$documentNumber?:null,$counterparty?:null,$paymentMethod?:null,$notes?:null,$cid,$id
                ]);
                $auditDetails=[
                    'entry_id'=>$id,
                    'before'=>['account_id'=>$oldAccountId,'category'=>$current['category'],
                        'description'=>$current['description'],'document_number'=>$current['document_number'],
                        'counterparty'=>$current['counterparty'],'payment_method'=>$current['payment_method'],'notes'=>$current['notes']],
                    'after'=>['account_id'=>$newAccountId,'category'=>$category,
                        'description'=>$description,'document_number'=>$documentNumber?:null,
                        'counterparty'=>$counterparty?:null,'payment_method'=>$paymentMethod?:null,'notes'=>$notes?:null]
                ];
                $pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                    ->execute([app_uuid(),$auth->requireLogin()['id'],$cid,'finance_entry_details_edited',
                        json_encode($auditDetails,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Alterações registradas com auditoria. Valor e data foram preservados.');
        }elseif($action==='attach'){
            $uploader=new UploadService(__DIR__);
            $file=$_FILES['document_file']??[];
            $saved=$uploader->financeDocument($file,$cid);
            if(!$saved)throw new RuntimeException('Selecione a nota ou o cupom.');
            $type=(string)($_POST['document_type']??'proof');
            if(!in_array($type,['invoice','fiscal_coupon','receipt','proof','other'],true))$type='proof';
            try{
                $pdo->prepare('INSERT INTO finance_documents(id,church_id,entry_id,document_type,original_name,storage_path,mime_type,file_size,notes,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([app_uuid(),$cid,$id,$type,$saved['original_name'],$saved['path'],$saved['mime'],$saved['size'],'Anexado posteriormente', $auth->requireLogin()['id']]);
            }catch(Throwable $e){if(is_file($saved['absolute']))@unlink($saved['absolute']);throw $e;}
            flash('success','Documento anexado ao lançamento sem registrar outro valor.');
        }else throw new InvalidArgumentException('Ação inválida.');
        if($uploadAjax){
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok'=>true,'message'=>'Documento anexado e registrado.','entry_id'=>$id],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            exit;
        }
        redirect('/finance-entry-edit.php?id='.rawurlencode($id));
    }catch(Throwable $e){
        error_log('Igreja Master finance-entry-edit failed: '.get_class($e).': '.$e->getMessage());
        $error=$e instanceof PDOException ? 'Não foi possível gravar o documento no banco de dados. Consulte o log da hospedagem.' : $e->getMessage();
        if($uploadAjax){
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok'=>false,'message'=>$error],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            exit;
        }
    }
}
$q=$pdo->prepare('SELECT id,original_name,document_type,created_at FROM finance_documents WHERE church_id=? AND entry_id=? ORDER BY created_at DESC');
$q->execute([$cid,$id]);$docs=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT id,name,account_type,active,department_id FROM finance_accounts
    WHERE church_id=? AND (active=1 OR id=?) ORDER BY account_type,name');
$q->execute([$cid,$entry['account_id']??'']);$accounts=$q->fetchAll(PDO::FETCH_ASSOC);
View::header('Editar lançamento',$auth,'finance.php');
?>
<div class="module-hero"><h1>Detalhes e documentos do lançamento</h1><p>Altere a descrição ou envie nota, cupom ou recibo mais tarde, sem criar outra entrada ou saída.</p></div>
<?php View::flash();if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>
<div class="card card-pad">
<h2><?=e($entry['direction']==='income'?'Entrada':'Saída')?> · R$ <?=number_format((float)$entry['amount'],2,',','.')?></h2>
<p><?=e(date('d/m/Y',strtotime($entry['transaction_date'])))?> · <?=e($entry['account_name']?:'Tesouraria Geral')?> · <?=e($entry['category'])?></p>
<p><strong><?=e($entry['description'])?></strong></p>
<p class="muted">Você pode corrigir a conta/caixa da Tesouraria Geral e o número do documento. Valor e data continuam protegidos. Alterações em períodos fechados ou conciliados são bloqueadas.</p>
</div>
<div class="grid two-col">
<section class="card card-pad"><h2>1. Editar dados do lançamento</h2>
<?php if($entry['status']==='posted'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="details"><input type="hidden" name="entry_id" value="<?=e($id)?>">
<input type="hidden" name="original_account_id" value="<?=e($entry['account_id']??'')?>">
<input type="hidden" name="original_document_number" value="<?=e($entry['document_number']??'')?>">
<div class="field"><label>Conta / Caixa de destino</label>
<select class="select" name="account_id">
<option value="" <?=empty($entry['account_id'])?'selected':''?>>Tesouraria Geral — sem conta específica</option>
<?php foreach($accounts as $account):
    if(!empty($account['department_id'])&&($account['id']!==($entry['account_id']??'')))continue;
    if(!empty($entry['department_id'])&&$account['id']!==($entry['account_id']??''))continue;
    ?>
<option value="<?=e($account['id'])?>" <?=($entry['account_id']??'')===$account['id']?'selected':''?> <?=empty($account['active'])&&$account['id']!==($entry['account_id']??'')?'disabled':''?>>
<?=e($account['name'])?> · <?=e($account['account_type']==='bank'?'Banco':'Caixa')?>
</option><?php endforeach?>
</select>
<small class="muted">Corrija a conta da Tesouraria Geral. Contas de departamento e períodos conciliados são protegidos.</small></div>
<div class="field"><label>Lançamento Contábil</label><input class="input" name="category" required maxlength="160" value="<?=e($entry['category'])?>"></div>
<div class="field"><label>Número do documento / cupom / referência Pix</label>
<input class="input" name="document_number" maxlength="190" value="<?=e($entry['document_number']??'')?>" placeholder="Ex.: número do cupom, NF ou ID Pix"></div>
<div class="field"><label>Favorecido / Pagador (opcional)</label><input class="input" name="counterparty" maxlength="190" value="<?=e($entry['counterparty']??'')?>"></div>
<div class="field"><label>Forma de pagamento (opcional)</label><input class="input" name="payment_method" maxlength="60" value="<?=e($entry['payment_method']??'')?>"></div>
<div class="field"><label>Observações (opcional)</label><textarea class="textarea" name="notes" maxlength="3000"><?=e($entry['notes']??'')?></textarea></div>
<div class="field"><label>Descrição</label><input class="input" name="description" required maxlength="255" value="<?=e($entry['description'])?>"></div>
<button class="btn btn-primary" type="submit">Salvar alterações</button></form>
<?php else:?><p>Lançamento estornado: os detalhes originais permanecem preservados.</p><?php endif?></section>
<section class="card card-pad"><h2>2. Enviar nota ou recibo</h2>
<p>Selecione uma foto ou PDF que já esteja salvo no celular. Se ocorrer ERR_UPLOAD_FILE_CHANGED, abra o arquivo na galeria e escolha uma cópia salva novamente.</p>
<form method="post" enctype="multipart/form-data" data-finance-attachment="1"><?=csrf_field()?><input type="hidden" name="action" value="attach"><input type="hidden" name="entry_id" value="<?=e($id)?>">
<div class="field"><label>Tipo de documento</label><select class="select" name="document_type">
<option value="invoice">Nota fiscal</option><option value="fiscal_coupon">Cupom</option><option value="receipt">Recibo</option><option value="proof">Comprovante</option><option value="other">Outro</option></select></div>
<div class="field"><label>Arquivo (até 12 MB, conforme limite da hospedagem)</label><input class="input" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" name="document_file" required></div>
<button class="btn btn-primary" type="submit">Enviar documento</button><p data-upload-result role="status" aria-live="polite"></p></form>
<p><a href="/finance-upload-check.php">Verificar problema de upload</a></p>
</section></div>
<section class="card card-pad"><h2>Documentos já anexados</h2><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Arquivo</th><th>Tipo</th><th>Abrir</th></tr></thead><tbody>
<?php foreach($docs as $d):?><tr><td><?=e(date('d/m/Y',strtotime($d['created_at'])))?></td><td><?=e($d['original_name'])?></td><td><?=e($d['document_type'])?></td><td><a class="btn btn-light" href="/finance-document.php?id=<?=e($d['id'])?>" target="_blank" rel="noopener">Ver</a></td></tr><?php endforeach?>
<?php if(!$docs):?><tr><td colspan="4">Nenhuma nota ou recibo anexado até o momento.</td></tr><?php endif?></tbody></table></div><a class="btn btn-light" href="/finance.php">Voltar à Tesouraria</a></section>
<?php View::footer();?>
