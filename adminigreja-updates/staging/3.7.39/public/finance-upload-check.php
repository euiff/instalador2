<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$upload=new UploadService(__DIR__);
$limits=UploadService::financeLimits();
$status=$upload->financeStorageStatus($cid);
$message='';
$ok=null;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    if(empty($_POST)){
        $message='A requisição chegou vazia. O arquivo pode ultrapassar post_max_size da hospedagem.';
        $ok=false;
    } else {
        verify_csrf();
        try{
            $saved=$upload->financeDocument($_FILES['test_file']??[],$cid);
            if(!$saved)throw new RuntimeException('Selecione um arquivo de teste.');
            // Não gerar lançamentos de teste nem deixar cópia do documento.
            if(!@unlink($saved['absolute']))error_log('Igreja Master: test upload cleanup failed');
            $message='Upload de teste concluído com sucesso. O recebimento e a gravação funcionaram.';
            $ok=true;
        } catch(Throwable $e){
            $message=$e->getMessage();
            $ok=false;
        }
    }
}
$max=$limits['php'];
$effective=$max===null?$limits['system']:min($limits['system'],$max);
View::header('Diagnóstico dos uploads',$auth,'finance.php');
?>
<section class="card card-pad">
<h1>Verificação de notas e recibos</h1>
<p>Esta página verifica a configuração do servidor. O teste grava um arquivo temporariamente na pasta privada da igreja, não cria entrada nem saída e remove o arquivo após a verificação.</p>
<?php if($ok!==null):?><div class="alert <?=$ok?'alert-success':'alert-error'?>"><?=e($message)?></div><?php endif?>
<div class="table-wrap"><table class="table"><tbody>
<tr><th>Pasta privada da igreja</th><td><?=e($status['ok']?'Gravável':'Sem gravação')?></td></tr>
<tr><th>Detalhes da pasta</th><td><?=e($status['message'])?></td></tr>
<tr><th>Upload ativado no PHP</th><td><?=e($limits['file_uploads']?'Sim':'Não')?></td></tr>
<tr><th>upload_max_filesize</th><td><?=e($limits['upload_max_filesize'])?></td></tr>
<tr><th>post_max_size</th><td><?=e($limits['post_max_size'])?></td></tr>
<tr><th>Pasta temporária do PHP</th><td><?=e($limits['tmp_writable']?'Gravável':'Sem permissão')?></td></tr>
<tr><th>Tamanho máximo efetivo</th><td><?=e(UploadService::displayBytes($effective))?></td></tr>
</tbody></table></div>
<h2>Testar envio de documento</h2>
<form method="post" enctype="multipart/form-data">
<?=csrf_field()?>
<label>Selecione um PDF ou foto para testar</label>
<input class="input" type="file" name="test_file" accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif" required>
<button class="btn btn-primary" type="submit">Verificar upload sem lançar no financeiro</button>
</form>
<p class="muted">Se o limite do PHP for inferior a 12 MB, o administrador do cPanel poderá ajustar <code>upload_max_filesize</code> e <code>post_max_size</code>. Também deve conferir a propriedade das pastas e o espaço da hospedagem. Não use permissão 777.</p>
<p><a class="btn btn-light" href="/finance.php">Voltar à Tesouraria</a></p>
</section>
<?php View::footer();?>
