<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch();if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$id=trim((string)($_GET['id']??$_POST['entry_id']??''));
$q=$pdo->prepare('SELECT e.*,a.name account_name FROM finance_entries e LEFT JOIN finance_accounts a ON a.church_id=e.church_id AND a.id=e.account_id WHERE e.church_id=? AND e.id=? LIMIT 1');
$q->execute([$cid,$id]);$entry=$q->fetch(PDO::FETCH_ASSOC);
if(!$entry){http_response_code(404);exit('Lançamento não encontrado nesta igreja.');}
$error=null;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='details'){
            if($entry['status']!=='posted')throw new RuntimeException('Um lançamento estornado não pode ser editado.');
            $category=trim((string)($_POST['category']??''));
            $description=trim((string)($_POST['description']??''));
            if($category===''||$description===''||mb_strlen($category)>160||mb_strlen($description)>255)throw new InvalidArgumentException('Informe categoria e descrição válidas.');
            FinancePeriodGuard::assertOpen($pdo,$cid,(string)$entry['transaction_date']);
            $pdo->prepare("UPDATE finance_entries SET category=?,description=? WHERE church_id=? AND id=? AND status='posted'")
                ->execute([$category,$description,$cid,$id]);
            $pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                ->execute([app_uuid(),$auth->requireLogin()['id'],$cid,'finance_entry_details_edited',json_encode(['entry_id'=>$id],JSON_THROW_ON_ERROR)]);
            flash('success','Dados do lançamento salvos. Valor e data permanecem iguais.');
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
        redirect('/finance-entry-edit.php?id='.rawurlencode($id));
    }catch(Throwable $e){$error=$e->getMessage();}
}
$q=$pdo->prepare('SELECT id,original_name,document_type,created_at FROM finance_documents WHERE church_id=? AND entry_id=? ORDER BY created_at DESC');
$q->execute([$cid,$id]);$docs=$q->fetchAll(PDO::FETCH_ASSOC);
View::header('Editar lançamento',$auth,'finance.php');
?>
<div class="module-hero"><h1>Detalhes e documentos do lançamento</h1><p>Altere a descrição ou envie nota, cupom ou recibo mais tarde, sem criar outra entrada ou saída.</p></div>
<?php View::flash();if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>
<div class="card card-pad">
<h2><?=e($entry['direction']==='income'?'Entrada':'Saída')?> · R$ <?=number_format((float)$entry['amount'],2,',','.')?></h2>
<p><?=e(date('d/m/Y',strtotime($entry['transaction_date'])))?> · <?=e($entry['account_name']?:'Tesouraria Geral')?> · <?=e($entry['category'])?></p>
<p><strong><?=e($entry['description'])?></strong></p>
<p class="muted">O valor, a data e a conta não são editáveis para preservar o histórico contábil. Se esses dados estiverem incorretos, use estorno e registre novamente.</p>
</div>
<div class="grid two-col">
<section class="card card-pad"><h2>1. Editar categoria e descrição</h2>
<?php if($entry['status']==='posted'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="details"><input type="hidden" name="entry_id" value="<?=e($id)?>">
<div class="field"><label>Categoria</label><input class="input" name="category" required value="<?=e($entry['category'])?>"></div>
<div class="field"><label>Descrição</label><input class="input" name="description" required maxlength="255" value="<?=e($entry['description'])?>"></div>
<button class="btn btn-primary" type="submit">Salvar informações</button></form>
<?php else:?><p>Lançamento estornado: os detalhes originais permanecem preservados.</p><?php endif?></section>
<section class="card card-pad"><h2>2. Enviar nota ou recibo</h2>
<p>Selecione uma foto ou PDF que já esteja salvo no celular. Se ocorrer ERR_UPLOAD_FILE_CHANGED, abra o arquivo na galeria e escolha uma cópia salva novamente.</p>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="attach"><input type="hidden" name="entry_id" value="<?=e($id)?>">
<div class="field"><label>Tipo de documento</label><select class="select" name="document_type">
<option value="invoice">Nota fiscal</option><option value="fiscal_coupon">Cupom</option><option value="receipt">Recibo</option><option value="proof">Comprovante</option><option value="other">Outro</option></select></div>
<div class="field"><label>Arquivo (até 12 MB, conforme limite da hospedagem)</label><input class="input" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" name="document_file" required></div>
<button class="btn btn-primary" type="submit">Enviar documento</button></form>
<p><a href="/finance-upload-check.php">Verificar problema de upload</a></p>
</section></div>
<section class="card card-pad"><h2>Documentos já anexados</h2><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Arquivo</th><th>Tipo</th><th>Abrir</th></tr></thead><tbody>
<?php foreach($docs as $d):?><tr><td><?=e(date('d/m/Y',strtotime($d['created_at'])))?></td><td><?=e($d['original_name'])?></td><td><?=e($d['document_type'])?></td><td><a class="btn btn-light" href="/finance-document.php?id=<?=e($d['id'])?>" target="_blank" rel="noopener">Ver</a></td></tr><?php endforeach?>
<?php if(!$docs):?><tr><td colspan="4">Nenhuma nota ou recibo anexado até o momento.</td></tr><?php endif?></tbody></table></div><a class="btn btn-light" href="/finance.php">Voltar à Tesouraria</a></section>
<?php View::footer();?>
