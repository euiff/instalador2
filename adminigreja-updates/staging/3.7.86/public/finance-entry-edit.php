<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/FinancePeriodGuard.php';
require_once __DIR__.'/lib/MasterSupportFinanceUi.php';
require_once __DIR__.'/lib/PortalService.php';
// Alinhar o carregamento dos serviços com finance.php: RegistryService
// depende da cadeia de serviços do Financeiro na instalação existente.
require_once __DIR__.'/lib/FinanceOperations.php';
require_once __DIR__.'/lib/FinanceFiscalScan.php';
require_once __DIR__.'/lib/FinanceEntryPresentation.php';
require_once __DIR__.'/lib/FinanceManualAttachments.php';
require_once __DIR__.'/lib/MasterSingleFinanceEntry.php';
require_once __DIR__.'/lib/ChurchFinanceExclusionUi.php';
require_once __DIR__.'/lib/GeneralTreasuryAccountScope.php';
require_once __DIR__.'/lib/ExpenseReceiptService.php';
require_once __DIR__.'/lib/DepartmentTreasuryService.php';
require_once __DIR__.'/lib/PendingFinanceReceipt.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch();if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$masterFinanceSupport=MasterSupportFinanceUi::allowed($auth,$church);
$id=trim((string)($_GET['edit_entry']??$_GET['id']??$_POST['entry_id']??''));
$q=$pdo->prepare('SELECT e.*,a.name account_name FROM finance_entries e LEFT JOIN finance_accounts a ON a.church_id=e.church_id AND a.id=e.account_id WHERE e.church_id=? AND e.id=? LIMIT 1');
$q->execute([$cid,$id]);$entry=$q->fetch(PDO::FETCH_ASSOC);
if(!$entry){http_response_code(404);exit('Lançamento não encontrado nesta igreja.');}
$error=null;
$financeOps=new FinanceOperations($pdo,$auth,$cid);
$registry=new RegistryService($pdo,$auth,$cid);
$categories=[];
foreach($registry->all('finance_category') as $item){
    $name=trim((string)($item['name']??''));
    if($name!=='')$categories[mb_strtolower($name,'UTF-8')]=['name'=>$name,'direction'=>(string)($item['data']['direction']??'both')];
}
$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$members=$q->fetchAll(PDO::FETCH_ASSOC);
function finance_edit_money(mixed $v):float {
    $s=preg_replace('/[^0-9,.\\-]/','',(string)$v)??'0';
    if(str_contains($s,','))$s=str_replace('.','',$s);
    return (float)str_replace(',','.',$s);
}
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
            $direction=(string)($_POST['direction']??'');
            $newDate=(string)($_POST['transaction_date']??'');
            $newAmount=finance_edit_money($_POST['amount']??'');
            $memberId=trim((string)($_POST['member_id']??''))?:null;
            $typedContributor=trim((string)($_POST['contributor']??''));
            if(!in_array($direction,['income','expense'],true))throw new InvalidArgumentException('Selecione Entrada ou Saída.');
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$newDate);
            if(!$dt||$dt->format('Y-m-d')!==$newDate)throw new InvalidArgumentException('Data inválida.');
            if(!is_finite($newAmount)||$newAmount<=0||$newAmount>999999999)throw new InvalidArgumentException('Valor inválido.');
            if($memberId!==null){
                if($direction!=='income')throw new InvalidArgumentException('Somente entradas podem ter membro contribuinte.');
                $q=$pdo->prepare('SELECT id FROM members WHERE church_id=? AND id=? AND active=1');
                $q->execute([$cid,$memberId]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Membro não encontrado nesta igreja.');
            }
            if($typedContributor!==''&&$memberId===null&&$direction==='income')$counterparty=$typedContributor;
            $selectedCategory=$categories[mb_strtolower($category,'UTF-8')]??null;
            if(!$selectedCategory||!in_array($selectedCategory['direction'],['both',$direction],true))
                throw new InvalidArgumentException('Selecione uma categoria contábil cadastrada para este tipo de lançamento.');
            $category=$selectedCategory['name'];
            if($category===''||$description===''||mb_strlen($category)>160||mb_strlen($description)>255
                ||mb_strlen($documentNumber)>190||mb_strlen($counterparty)>190||mb_strlen($paymentMethod)>60||mb_strlen($notes)>3000)
                throw new InvalidArgumentException('Informe lançamento contábil, descrição e número de documento válidos.');
            FinancePeriodGuard::assertOpen($pdo,$cid,(string)$entry['transaction_date']);
            FinancePeriodGuard::assertOpen($pdo,$cid,$newDate);
            $pdo->beginTransaction();
            try {
                // Trava e reconfere o estado: duas pessoas não podem alterar a mesma
                // movimentação simultaneamente nem sobrescrever dados mais recentes.
                $q=$pdo->prepare('SELECT id,account_id,department_id,status,category,description,document_number,counterparty,payment_method,notes,transaction_date,direction,amount,member_id,is_grouped
                    FROM finance_entries WHERE church_id=? AND id=? FOR UPDATE');
                $q->execute([$cid,$id]);$current=$q->fetch(PDO::FETCH_ASSOC);
                if(!$current||$current['status']!=='posted')
                    throw new RuntimeException('Este lançamento foi estornado ou não está disponível.');
                if((string)($current['account_id']??'')!==(string)($_POST['original_account_id']??'')
                    ||(string)($current['document_number']??'')!==(string)($_POST['original_document_number']??'')
                    ||(string)$current['direction']!==(string)($_POST['original_direction']??'')
                    ||(string)$current['transaction_date']!==(string)($_POST['original_date']??'')
                    ||number_format((float)$current['amount'],2,'.','')!==(string)($_POST['original_amount']??''))
                    throw new RuntimeException('Alguém já alterou o lançamento. Atualize a página e confira antes de salvar.');
                $oldAccountId=$current['account_id']?:null;
                $changedMoney=$direction!==$current['direction']||$newDate!==$current['transaction_date']
                    ||abs($newAmount-(float)$current['amount'])>0.00001;
                if(!empty($current['is_grouped'])&&$changedMoney)
                    throw new RuntimeException('Lançamentos agrupados precisam ser corrigidos pelos itens; o total não pode ser alterado isoladamente.');
                if($changedMoney){
                    $earliest=min($newDate,(string)$current['transaction_date']);
                    $q=$pdo->prepare('SELECT 1 FROM finance_closings WHERE church_id=? AND period_end>=? LIMIT 1');
                    $q->execute([$cid,$earliest]);
                    if($q->fetchColumn())throw new RuntimeException('Período fechado: reabra antes de alterar tipo, valor ou data.');
                    $q=$pdo->prepare('SELECT 1 FROM finance_reconciliations WHERE church_id=? AND reference_date>=? LIMIT 1');
                    $q->execute([$cid,$earliest]);
                    if($q->fetchColumn())throw new RuntimeException('Movimentação conciliada: não é permitido alterar tipo, valor ou data.');
                }
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
                $pdo->prepare("UPDATE finance_entries SET account_id=?,category=?,description=?,document_number=?,counterparty=?,payment_method=?,notes=?,direction=?,transaction_date=?,amount=?,member_id=?
                    WHERE church_id=? AND id=? AND status='posted'")->execute([
                    $newAccountId,$category,$description,$documentNumber?:null,$counterparty?:null,$paymentMethod?:null,$notes?:null,$direction,$newDate,$newAmount,$memberId,$cid,$id
                ]);
                $auditDetails=[
                    'entry_id'=>$id,
                    'before'=>['account_id'=>$oldAccountId,'category'=>$current['category'],
                        'description'=>$current['description'],'document_number'=>$current['document_number'],
                        'direction'=>$current['direction'],'transaction_date'=>$current['transaction_date'],'amount'=>$current['amount'],'member_id'=>$current['member_id'],
                        'counterparty'=>$current['counterparty'],'payment_method'=>$current['payment_method'],'notes'=>$current['notes']],
                    'after'=>['account_id'=>$newAccountId,'category'=>$category,
                        'description'=>$description,'document_number'=>$documentNumber?:null,
                        'direction'=>$direction,'transaction_date'=>$newDate,'amount'=>$newAmount,'member_id'=>$memberId,
                        'counterparty'=>$counterparty?:null,'payment_method'=>$paymentMethod?:null,'notes'=>$notes?:null]
                ];
                $pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                    ->execute([app_uuid(),$auth->requireLogin()['id'],$cid,'finance_entry_details_edited',
                        json_encode($auditDetails,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Lançamento atualizado com auditoria.');
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
            echo json_encode(['ok'=>true,'message'=>'Documento anexado e registrado.','entry_id'=>$id,'view_url'=>'/finance.php?edit_entry='.rawurlencode($id).'#entry-documents'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            exit;
        }
        redirect($action==='details'?'/finance.php?from='.rawurlencode(substr($newDate,0,7).'-01').'&to='.rawurlencode(date('Y-m-t',strtotime($newDate))).'#finance-movements':'/finance.php?edit_entry='.rawurlencode($id).'#entry-documents');
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
$groupItems=[];
$editorAlerts=[];
// Dados adicionais não devem impedir a abertura da edição quando uma tabela
// opcional ainda não foi migrada na hospedagem.
if(!empty($entry['is_grouped'])){
    try{
        $q=$pdo->prepare('SELECT i.*,m.name member_name,
            (SELECT COUNT(*) FROM finance_documents d WHERE d.church_id=i.church_id AND d.entry_item_id=i.id) doc_count
            FROM finance_entry_items i
            LEFT JOIN members m ON m.church_id=i.church_id AND m.id=i.member_id
            WHERE i.church_id=? AND i.entry_id=? ORDER BY i.item_index');
        $q->execute([$cid,$id]);$groupItems=$q->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $failure){
        error_log('Igreja Master editor optional grouped item query: '.$failure->getCode());
        $editorAlerts[]='Detalhes dos itens agrupados indisponíveis nesta hospedagem. O lançamento não foi alterado.';
    }
}
$docs=[];
try{
    $q=$pdo->prepare('SELECT id,original_name,document_type,created_at FROM finance_documents WHERE church_id=? AND entry_id=? ORDER BY created_at DESC');
    $q->execute([$cid,$id]);$docs=$q->fetchAll(PDO::FETCH_ASSOC);
}catch(PDOException $failure){
    error_log('Igreja Master editor document list query: '.$failure->getCode());
    $editorAlerts[]='Não foi possível carregar a lista de documentos. Os anexos existentes foram preservados.';
}
$accounts=[];
try{
    $q=$pdo->prepare('SELECT id,name,account_type,active,department_id FROM finance_accounts
        WHERE church_id=? AND (active=1 OR id=?) ORDER BY account_type,name');
    $q->execute([$cid,$entry['account_id']??'']);$accounts=$q->fetchAll(PDO::FETCH_ASSOC);
}catch(PDOException $failure){
    error_log('Igreja Master editor account list query: '.$failure->getCode());
    $editorAlerts[]='Não foi possível listar todas as contas; não altere a conta deste lançamento.';
    $accounts=[['id'=>$entry['account_id']??null,'name'=>$entry['account_name']??'Conta original','account_type'=>'cash','active'=>1,'department_id'=>$entry['department_id']??null]];
}
View::header('Editar lançamento',$auth,'finance.php');
?>
<div class="module-hero"><h1>Editar lançamento e anexar documentos</h1><p>Escolha uma das opções abaixo. Os arquivos permanecem vinculados a este lançamento.</p><nav class="im-entry-jump-links" aria-label="Ações deste lançamento"><a class="btn btn-primary" href="#edit-details">✏️ Editar dados</a><a class="btn btn-light" href="#attach-documents">📎 Anexar documento</a><a class="btn btn-light" href="#entry-documents">👁 Ver documentos</a><a class="btn btn-light" href="/finance.php#finance-movements">← Movimentações</a></nav></div>
<?php View::flash();if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>
<?php foreach($editorAlerts as $warning):?><div class="alert alert-warning" role="status"><?=e($warning)?></div><?php endforeach?>
<div class="card card-pad">
<h2><?=e($entry['direction']==='income'?'Entrada':'Saída')?> · R$ <?=number_format((float)$entry['amount'],2,',','.')?></h2>
<p><?=e(date('d/m/Y',strtotime($entry['transaction_date'])))?> · <?=e($entry['account_name']?:'Tesouraria Geral')?> · <?=e($entry['category'])?></p>
<p><strong><?=e($entry['description'])?></strong></p>
<p class="muted">Você pode corrigir a conta/caixa da Tesouraria Geral e o número do documento. Valor, data e tipo podem ser corrigidos em períodos abertos e não conciliados. Lançamentos agrupados mantêm regras próprias.</p>
</div>
<?php if($groupItems):?>
<section class="card card-pad" style="margin:14px 0">
 <h2>Itens deste lançamento agrupado (<?=count($groupItems)?>)</h2>
 <p class="muted">Estes valores compõem o total apresentado acima e <strong>não são contabilizados duas vezes</strong>. Cada cupom continua vinculado ao item correspondente.</p>
 <div class="table-wrap"><table class="table">
  <thead><tr><th>Item</th><th>Categoria</th><th>Contribuinte / fornecedor</th><th>Valor</th><th>Documentos</th></tr></thead><tbody>
  <?php foreach($groupItems as $part):?>
   <tr><td><strong><?=e((string)$part['item_index'])?>. <?=e($part['description'])?></strong></td>
     <td><?=e($part['category'])?></td>
     <td><?=e($part['member_name']?:$part['contributor']?:'—')?></td>
     <td>R$ <?=number_format((float)$part['amount'],2,',','.')?></td>
     <td><?=e((string)$part['doc_count'])?> arquivo(s)</td></tr>
  <?php endforeach?>
  </tbody></table></div>
</section>
<?php endif?>
<div class="grid two-col">
<section class="card card-pad" id="edit-details"><h2>1. Editar dados do lançamento</h2>
<?php if($entry['status']==='posted'):?><form method="post" action="/finance.php?edit_entry=<?=rawurlencode($id)?>#edit-details"><?=csrf_field()?><input type="hidden" name="action" value="details"><input type="hidden" name="entry_id" value="<?=e($id)?>">
<input type="hidden" name="original_account_id" value="<?=e($entry['account_id']??'')?>">
<input type="hidden" name="original_document_number" value="<?=e($entry['document_number']??'')?>">
<input type="hidden" name="original_direction" value="<?=e($entry['direction'])?>">
<input type="hidden" name="original_date" value="<?=e($entry['transaction_date'])?>">
<input type="hidden" name="original_amount" value="<?=number_format((float)$entry['amount'],2,'.','')?>">
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
<div class="field"><label>Tipo</label><select class="select" name="direction" id="im-edit-direction" required><option value="income" <?=$entry['direction']==='income'?'selected':''?>>Entrada</option><option value="expense" <?=$entry['direction']==='expense'?'selected':''?>>Saída</option></select></div>
<div class="field"><label>Data</label><input class="input" type="date" name="transaction_date" required value="<?=e($entry['transaction_date'])?>"></div>
<div class="field"><label>Valor (R$)</label><input class="input" name="amount" inputmode="decimal" required value="<?=number_format((float)$entry['amount'],2,',','.')?>"></div>
<div class="field"><label>Lançamento Contábil</label><select class="select" name="category" id="im-edit-category" required>
<?php foreach($categories as $item):?><option value="<?=e($item['name'])?>" data-direction="<?=e($item['direction'])?>" <?=mb_strtolower($item['name'],'UTF-8')===mb_strtolower((string)$entry['category'],'UTF-8')?'selected':''?>><?=e($item['name'])?></option><?php endforeach?>
<?php if(!isset($categories[mb_strtolower((string)$entry['category'],'UTF-8')])):?><option value="<?=e($entry['category'])?>" disabled selected>Categoria antiga: <?=e($entry['category'])?> (selecione uma cadastrada)</option><?php endif?></select></div>
<div class="field"><label>Contribuinte / Membro</label><select class="select" name="member_id"><option value="">Sem vínculo / nome digitado</option>
<?php foreach($members as $member):?><option value="<?=e($member['id'])?>" <?=($entry['member_id']??'')===$member['id']?'selected':''?>><?=e($member['name'])?></option><?php endforeach?></select>
<input class="input" name="contributor" maxlength="190" placeholder="Ou digite o nome" value="<?=e(empty($entry['member_id'])&&$entry['direction']==='income'?$entry['counterparty']??'':'')?>"></div>
<script>(function(){let d=document.getElementById('im-edit-direction'),c=document.getElementById('im-edit-category');function sync(){for(let o of c.options){let x=o.dataset.direction;o.hidden=!!x&&x!=='both'&&x!==d.value;}if(c.selectedOptions.length&&c.selectedOptions[0].hidden)c.value='';}d.addEventListener('change',sync);sync();})();</script>
<div class="field"><label>Número do documento / cupom / referência Pix</label>
<input class="input" name="document_number" maxlength="190" value="<?=e($entry['document_number']??'')?>" placeholder="Ex.: número do cupom, NF ou ID Pix"></div>
<div class="field"><label>Favorecido / Pagador (opcional)</label><input class="input" name="counterparty" maxlength="190" value="<?=e($entry['counterparty']??'')?>"></div>
<div class="field"><label>Forma de pagamento (opcional)</label><input class="input" name="payment_method" maxlength="60" value="<?=e($entry['payment_method']??'')?>"></div>
<div class="field"><label>Observações (opcional)</label><textarea class="textarea" name="notes" maxlength="3000"><?=e($entry['notes']??'')?></textarea></div>
<div class="field"><label>Descrição</label><input class="input" name="description" required maxlength="255" value="<?=e($entry['description'])?>"></div>
<button class="btn btn-primary" type="submit">Salvar alterações</button></form>
<?php else:?><p>Lançamento estornado: os detalhes originais permanecem preservados.</p><?php endif?></section>
<?php if($masterFinanceSupport && in_array($entry['status'],['posted','reversed'],true)):?>
<section class="card card-pad" style="border:1px solid #d6b8b5;margin:12px 0">
  <h2>Suporte Master — excluir este lançamento específico</h2>
  <p>Exclusão administrativa somente deste lançamento, com justificativa, backup e auditoria. Não afeta as outras movimentações da igreja.</p>
  <?php MasterSupportFinanceUi::exclusionForm($entry); ?>
</section>
<?php endif?>
<section class="card card-pad" id="attach-documents"><h2>2. Enviar nota ou recibo</h2>
<p>Selecione uma foto ou PDF que já esteja salvo no celular. Se ocorrer ERR_UPLOAD_FILE_CHANGED, abra o arquivo na galeria e escolha uma cópia salva novamente.</p>
<form method="post" action="/finance.php?edit_entry=<?=rawurlencode($id)?>#attach-documents" enctype="multipart/form-data" data-finance-attachment="1"><?=csrf_field()?><input type="hidden" name="action" value="attach"><input type="hidden" name="entry_id" value="<?=e($id)?>">
<div class="field"><label>Tipo de documento</label><select class="select" name="document_type">
<option value="invoice">Nota fiscal</option><option value="fiscal_coupon">Cupom</option><option value="receipt">Recibo</option><option value="proof">Comprovante</option><option value="other">Outro</option></select></div>
<div class="field"><label>Arquivo (até 12 MB, conforme limite da hospedagem)</label><input class="input" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" name="document_file" required></div>
<button class="btn btn-primary" type="submit">Enviar documento</button><p data-upload-result role="status" aria-live="polite"></p></form>
<p><a href="/finance-upload-check.php">Verificar problema de upload</a></p>
</section></div>
<section class="card card-pad" id="entry-documents"><h2>Documentos já anexados</h2><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Arquivo</th><th>Tipo</th><th>Abrir</th></tr></thead><tbody>
<?php foreach($docs as $d):?><tr><td><?=e(date('d/m/Y',strtotime($d['created_at'])))?></td><td><?=e($d['original_name'])?></td><td><?=e($d['document_type'])?></td><td><button class="btn btn-light" type="button" data-finance-preview="/finance-document.php?id=<?=e($d['id'])?>" data-finance-preview-name="<?=e($d['original_name'])?>">👁 Visualizar</button></td></tr><?php endforeach?>
<?php if(!$docs):?><tr><td colspan="4">Nenhuma nota ou recibo anexado até o momento.</td></tr><?php endif?></tbody></table></div><a class="btn btn-light" href="/finance.php">Voltar à Tesouraria</a></section>
<dialog id="im-finance-document-modal" class="im-finance-document-modal" aria-labelledby="im-finance-document-modal-title" aria-modal="true">
  <div class="im-finance-document-modal-head"><strong id="im-finance-document-modal-title">Visualizar documento</strong><button class="btn btn-light" type="button" data-finance-preview-close>✕ Fechar</button></div>
  <div class="im-finance-document-modal-body"><p data-im-preview-status role="status" aria-live="polite"></p><div class="im-finance-preview-pages" data-im-preview-display></div></div>
  <div class="im-finance-document-modal-foot"><span>Visualizar sem baixar</span><a class="btn btn-light" data-im-preview-download hidden>⬇ Baixar (opcional)</a><button class="btn btn-primary" type="button" data-finance-preview-close>Fechar</button></div>
</dialog>
<style>
.im-entry-jump-links{display:flex;flex-wrap:wrap;gap:9px;margin-top:12px}
#edit-details,#attach-documents,#entry-documents{scroll-margin-top:90px}
@media(max-width:650px){.im-entry-jump-links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.im-entry-jump-links .btn{min-height:44px;white-space:normal;text-align:center}}
.im-finance-document-modal{display:none;width:min(96vw,1000px);height:min(92dvh,900px);padding:0;border:0;border-radius:12px;overflow:hidden;background:#fff;color:#133c5e;box-sizing:border-box}
.im-finance-document-modal[open]{display:flex!important;flex-direction:column!important}
.im-finance-document-modal::backdrop{background:#061c33b8}
.im-finance-document-modal-head,.im-finance-document-modal-foot{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:10px 12px;border-bottom:1px solid #dde9f1}
.im-finance-document-modal-foot{border-top:1px solid #dde9f1;border-bottom:0}
.im-finance-document-modal-body{flex:1 1 auto;min-height:0;overflow:auto;background:#f2f5f8}
.im-finance-document-modal-body [data-im-preview-status]{padding:8px 12px;font-size:13px}
.im-finance-preview-pages{width:100%;display:flex;flex-direction:column;align-items:center;gap:12px;padding:9px;box-sizing:border-box}
.im-finance-preview-pages :is(img,canvas){display:block;max-width:100%;height:auto;background:white}
@media(max-width:900px){.im-finance-document-modal[open]{width:100vw;height:100dvh;max-height:100dvh;max-width:100vw;border-radius:0;padding-top:env(safe-area-inset-top,0px);padding-bottom:calc(env(safe-area-inset-bottom,0px) + 16px);margin:0;top:0;bottom:0}}
</style>
<script defer src="/assets/finance-document-preview-3782.js?v=3.7.82"></script>
<?php View::footer();?>
