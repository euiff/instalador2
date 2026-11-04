<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/SystemCourseImageService.php';

$user=$auth->requireLogin();
if(!$auth->isSuperAdmin((string)$user['id'])){
    http_response_code(403);
    exit('Somente o administrador da plataforma pode publicar imagens globais do tutorial.');
}
$media=new SystemCourseImageService();
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        if(($_POST['action']??'')!=='import')throw new InvalidArgumentException('Ação desconhecida.');
        $file=$_FILES['course_images']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||
           !is_uploaded_file((string)($file['tmp_name']??'')))
            throw new InvalidArgumentException('Escolha o arquivo ZIP das imagens aprovadas.');
        $size=(int)($file['size']??0);
        if($size<=0||$size>10000000)
            throw new InvalidArgumentException('O pacote ZIP precisa ter até 10 MB.');
        $count=$media->importZip((string)$file['tmp_name']);
        flash('success',$count.' imagens verificadas e publicadas no curso de todas as igrejas.');
        redirect('/master-system-course-images.php');
    }catch(Throwable $ex){
        error_log('System course media import: '.get_class($ex).' '.$ex->getMessage());
        $error=$ex instanceof PDOException?'Falha ao importar o pacote.':$ex->getMessage();
    }
}
$count=$media->approvedCount();
$total=$media->total();
View::header('Imagens do Curso Oficial',$auth,'master-system-course-images.php');
View::flash();
?>
<style>
.sc-import-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(240px,.48fr);gap:16px}
.sc-badge{padding:6px 10px;border-radius:99px;background:#eff8f6;color:#126b60;display:inline-flex;font-size:12px;font-weight:700}
.sc-hint{line-height:1.6;color:#657c8e}
.sc-mini{border:1px solid #d4e0e9;padding:10px 14px;border-radius:9px;margin:6px 0;display:flex;gap:12px;justify-content:space-between}
@media(max-width:810px){.sc-import-layout{grid-template-columns:1fr}}
</style>
<section class="module-toolbar">
  <div class="module-title"><h1>Imagens do Curso Oficial</h1>
  <p>Importação central de telas de tutorial para as igrejas atuais e futuras.</p></div>
  <div class="actions">
    <a href="/system-course.php" class="btn btn-light">Prévia do curso</a>
    <a href="/master.php" class="btn btn-light">Voltar ao Master</a>
  </div>
</section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<div class="sc-import-layout">
<section class="card card-pad">
<h2>Importar pacote de imagens protegidas</h2>
<p class="sc-hint">Use somente o arquivo <strong>Imagens_Curso_IgrejaMaster_Importar_no_Painel.zip</strong> que acompanha o tutorial preparado. O sistema verifica cada foto pelo código de integridade (SHA-256) e rejeita qualquer imagem diferente — inclusive prints originais não anonimizados.</p>
<p class="sc-hint">Ao importar uma única vez, as imagens aparecem para todos os clientes no <strong>Ensino → Curso Oficial do Sistema</strong>, sem misturar dados pessoais entre igrejas.</p>
<form method="post" enctype="multipart/form-data" style="display:grid;gap:13px;max-width:550px">
<?=csrf_field()?>
<input type="hidden" name="action" value="import">
<label style="display:grid;gap:7px">Arquivo ZIP das telas aprovadas
<input type="file" class="input" name="course_images" accept=".zip,application/zip" required></label>
<button type="submit" class="btn btn-primary" onclick="return confirm('Publicar as imagens aprovadas no curso de todas as igrejas?')">Verificar e importar todas as imagens</button>
</form>
<p class="sc-hint">Importação exclusiva do Master. Esta área não aparece no curso dos clientes. As imagens só são entregues a pessoas autenticadas e autorizadas a acessar o treinamento.</p>
</section>
<aside class="card card-pad">
<span class="sc-badge">Publicação global segura</span>
<h2 style="margin-top:10px"><?=$count?> de <?=$total?> telas disponíveis</h2>
<div style="height:11px;background:#e9eff2;border-radius:99px;overflow:hidden"><span style="display:block;background:#239aa6;height:100%;width:<?=($total?round($count*100/$total):0)?>%"></span></div>
<p class="sc-hint">As imagens aprovadas são de demonstração. Não devem conter e-mail, CPF, telefone, senha, dados de crianças ou dados financeiros reais.</p>
<h3>Conferência das imagens</h3>
<?php foreach($media->catalog() as $item):?>
<div class="sc-mini"><span><?=e($item['title'])?></span><strong><?=($media->approved((string)$item['id'])?'Disponível':'Pendente')?></strong></div>
<?php endforeach;?>
</aside>
</div>
<?php View::footer();?>
