<?php
require __DIR__.'/bootstrap.php';require_once __DIR__.'/lib/FinanceCsvImport.php';
$user=$auth->requireAbility('finance');$church=$auth->currentChurch();if(!$church)redirect('/dashboard.php');$cid=$church['id'];$importer=new FinanceCsvImport($pdo,$auth,$cid);$error=null;
if(($_GET['template']??'')==='csv'){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="modelo-importacao.csv"');echo "\xEF\xBB\xBFData;Tipo;Categoria;Descrição;Valor;Membro;Conta;Centro de custos\n2026-11-02;Entrada;Ofertas;Oferta de exemplo;10,00;;;\n";exit;}
$key='finance_import_preview';
if($_SERVER['REQUEST_METHOD']==='POST')try{
    verify_csrf();$action=(string)($_POST['action']??'');
    if($action==='preview'){
        unset($_SESSION[$key]);$file=$_FILES['csv']??[];
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>1048576||!is_uploaded_file((string)($file['tmp_name']??'')))throw new InvalidArgumentException('Selecione um arquivo CSV de até 1 MB.');
        $preview=$importer->preview((string)file_get_contents($file['tmp_name']));$_SESSION[$key]=[...$preview,'church_id'=>$cid,'user_id'=>$user['id'],'created_at'=>time()];
    }elseif($action==='commit'){
        $preview=$_SESSION[$key]??null;
        if(!$preview||$preview['church_id']!==$cid||$preview['user_id']!==$user['id']||time()-$preview['created_at']>900)throw new RuntimeException('Prévia expirada. Envie o arquivo novamente.');
        $importer->commit($preview);unset($_SESSION[$key]);flash('success','Importação concluída: '.count($preview['rows']).' lançamentos.');redirect('/finance-control.php?view=report&from='.rawurlencode(min(array_column($preview['rows'],'transaction_date'))).'&to='.rawurlencode(max(array_column($preview['rows'],'transaction_date'))));
    }elseif($action==='discard')unset($_SESSION[$key]);else throw new InvalidArgumentException('Ação inválida.');
}catch(Throwable $e){$error=$e instanceof PDOException?'A importação não foi concluída. Nenhuma linha foi gravada.':$e->getMessage();}
$preview=$_SESSION[$key]??null;if($preview&&($preview['church_id']!==$cid||$preview['user_id']!==$user['id']||time()-$preview['created_at']>900))$preview=null;
View::header('Importar movimentações',$auth,'finance-import.php');View::flash();
?>
<section class="module-toolbar"><div class="module-title"><h1>Importar movimentações</h1><p>Envie um CSV, confira a prévia e confirme a gravação.</p></div><a class="btn btn-light" href="?template=csv">Baixar modelo CSV</a></section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif?>
<section class="card card-pad"><h2>1. Selecionar arquivo</h2><p>CSV UTF-8 separado por ponto e vírgula, até 1 MB e 1.000 linhas. Use o modelo ou uma exportação do relatório financeiro. Nomes de membros, contas e centros de custos precisam corresponder a um único cadastro ativo desta igreja.</p><form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="preview"><label>Arquivo CSV<input class="input" name="csv" type="file" accept=".csv,text/csv" required></label><button class="btn btn-primary">Conferir prévia</button></form></section>
<?php if($preview):?><section class="card card-pad"><h2>2. Conferir <?=count($preview['rows'])?> lançamentos</h2><p>Nenhuma movimentação foi gravada ainda. <?=$preview['skipped']?(int)$preview['skipped'].' linhas estornadas/canceladas foram ignoradas.':''?> Abaixo, até 50 linhas da prévia.</p><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Valor</th></tr></thead><tbody><?php foreach(array_slice($preview['rows'],0,50) as $row):?><tr><td><?=e($row['transaction_date'])?></td><td><?=$row['direction']==='income'?'Entrada':'Saída'?></td><td><?=e($row['category'])?></td><td><?=e($row['description'])?></td><td>R$ <?=number_format((float)$row['amount'],2,',','.')?></td></tr><?php endforeach?></tbody></table></div><div class="actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="commit"><button class="btn btn-primary">Confirmar importação de <?=count($preview['rows'])?> lançamentos</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="discard"><button class="btn btn-light">Descartar prévia</button></form></div></section><?php endif?>
<?php View::footer();
