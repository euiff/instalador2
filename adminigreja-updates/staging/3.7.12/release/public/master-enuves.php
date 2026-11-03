<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/EnuvesImportService.php';
require_once __DIR__.'/lib/EnuvesLeadershipService.php';
$user=$auth->requireRole('super_admin');
EnuvesImportService::ensureSchema($pdo);
$import=new EnuvesImportService($pdo,$auth,(string)$user['id']);
$leaders=new EnuvesLeadershipService($pdo,$auth,(string)$user['id']);
$error=null;$result=null;
$churches=$import->churches();
$cid=trim((string)($_GET['church']??$_POST['church_id']??($_SESSION['enuves_upload']['church_id']??'')));
if($cid===''){
    foreach($churches as $c)if(mb_strtolower(trim($c['name']))==='igreja de deus campo belo'){$cid=$c['id'];break;}
}
$church=null;
if($cid!=='')try{$church=$import->church($cid);}catch(Throwable $e){$error=$e->getMessage();$cid='';}
$preview=null;
if(isset($_SESSION['enuves_upload'])&&is_array($_SESSION['enuves_upload'])){
    $stored=$_SESSION['enuves_upload'];
    if(time()-(int)($stored['at']??0)>7200||!is_file((string)($stored['path']??''))){
        @unlink((string)($stored['path']??''));unset($_SESSION['enuves_upload']);
    }elseif(($stored['church_id']??'')===$cid){
        $preview=$stored['preview']??null;
    }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
    verify_csrf();
    $action=(string)($_POST['action']??'');
    if(!$church)throw new InvalidArgumentException('Selecione a igreja de destino.');
    if($action==='preview'){
        $file=$_FILES['enuves_file']??[];
        $tmp=(string)($file['tmp_name']??'');
        $size=(int)($file['size']??0);
        $name=(string)($file['name']??'');
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)
            ||$size<300||$size>EnuvesXlsxReader::MAX_UPLOAD||!preg_match('/\.xlsx$/iD',$name))
            throw new InvalidArgumentException('Envie a exportação de pessoas do Enuves no formato XLSX (até 12 MB).');
        $folder=sys_get_temp_dir().'/igreja_master_enuves';
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))
            throw new RuntimeException('Não foi possível preparar o arquivo temporário.');
        $old=(string)($_SESSION['enuves_upload']['path']??'');
        if($old!=='')@unlink($old);
        $temp=$folder.'/'.bin2hex(random_bytes(20)).'.xlsx';
        if(!move_uploaded_file($tmp,$temp))throw new RuntimeException('Falha ao guardar o arquivo temporário.');
        @chmod($temp,0600);
        try{
            $parsed=EnuvesXlsxReader::read($temp);
            $overview=EnuvesImportMap::summary($parsed);
            if($overview['bad']||$overview['duplicate_ids'])throw new InvalidArgumentException('Corrija cadastros sem nome/ID ou IDs Enuves repetidos.');
            $_SESSION['enuves_upload']=[
                'path'=>$temp,'sha'=>hash_file('sha256',$temp),'at'=>time(),
                'church_id'=>$cid,'preview'=>$overview
            ];
            $preview=$overview;
        }catch(Throwable $e){@unlink($temp);throw $e;}
    }elseif($action==='import'){
        $staged=$_SESSION['enuves_upload']??null;
        if(!is_array($staged)||($staged['church_id']??'')!==$cid
            ||time()-(int)($staged['at']??0)>7200)
            throw new RuntimeException('Faça novamente a prévia com o arquivo e a igreja corretos.');
        $path=(string)$staged['path'];
        if(!is_file($path)||!hash_equals((string)$staged['sha'],(string)hash_file('sha256',$path)))
            throw new RuntimeException('O arquivo preparado foi alterado ou expirou.');
        $typed=trim((string)($_POST['confirm_church_name']??''));
        if($typed!==$church['name'])throw new InvalidArgumentException('Digite exatamente o nome da igreja de destino.');
        if(empty($_POST['confirmed']))throw new InvalidArgumentException('Confirme a conferência e o destino da importação.');
        $result=$import->import($path,$cid,!empty($_POST['create_departments']));
        @unlink($path);unset($_SESSION['enuves_upload']);$preview=null;
        flash('success','Importação concluída: '.$result['created'].' pessoas criadas; '.
            $result['linked'].' vinculadas a existentes; '.$result['departments_created'].
            ' departamentos criados; '.$result['interests_created'].' interesses registrados.');
        redirect('/master-enuves.php?church='.rawurlencode($cid).'#import-history');
    }elseif($action==='leader'){
        $status=$leaders->assign($cid,(string)($_POST['department_id']??''),(string)($_POST['member_id']??''));
        flash('success',$status==='active'
            ?'Liderança registrada e acesso do líder existente liberado para o departamento.'
            :'Liderança indicada. Crie ou vincule o login com perfil Líder de departamento para ativar o acesso.');
        redirect('/master-enuves.php?church='.rawurlencode($cid).'#leaders');
    }else throw new InvalidArgumentException('Operação inválida.');
 }catch(Throwable $e){
    error_log('enuves master import: '.get_class($e).' '.$e->getMessage());
    $error=$e instanceof PDOException?'Erro ao gravar registros. Nada foi importado se a transação falhou.':$e->getMessage();
 }
}
$batches=$church?$import->batches($cid):[];
$departments=$church?$leaders->departments($cid):[];
$people=$church?$leaders->people($cid):[];
$assigned=$church?$leaders->designated($cid):[];
View::header('Importar do Enuves',$auth,'master-enuves.php');
View::flash();
?>
<style>
.mig-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:14px 0}
.mig-grid .card{padding:15px}
.mig-grid strong{display:block;font-size:26px}
.mig-grid small{color:#60758a}
.mig-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.mig-fields{display:grid;gap:12px;margin:15px 0}.mig-fields label{display:grid;gap:6px}
.mig-table{overflow-x:auto}.mig-table table{min-width:620px;width:100%;border-collapse:collapse}
.mig-table td,.mig-table th{padding:10px;border-bottom:1px solid #e2e8ee;text-align:left}
.mig-warning{padding:13px 15px;background:#fff7e8;border:1px solid #efd8a8;border-radius:12px;color:#65491e}
.mig-actions{display:flex;gap:10px;flex-wrap:wrap;margin:15px 0}
@media(max-width:850px){.mig-two{grid-template-columns:1fr}}
</style>
<section class="module-toolbar">
<div class="module-title"><h1>Importação do Enuves</h1>
<p>Transfira pessoas, cadastros e interesses para a igreja correta, sem criar dados duplicados.</p></div>
<div class="actions"><a class="btn btn-light" href="/master-members.php?church=<?=rawurlencode($cid)?>">Gerenciar membros</a><a class="btn btn-light" href="/master.php">Painel Master</a></div>
</section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="card card-pad">
<h2>1. Escolha a igreja de destino</h2>
<form method="get" class="mig-fields" action="/master-enuves.php">
<label>Igreja cadastrada no Igreja Master
<select class="select" name="church" required onchange="this.form.submit()"><option value="">Selecione a igreja</option>
<?php foreach($churches as $c):?><option value="<?=e($c['id'])?>" <?=$cid===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?>
</select></label>
<noscript><button class="btn btn-light">Selecionar igreja</button></noscript>
</form>
<?php if(!$church):?><p class="mig-warning">A Igreja de Deus Campo Belo precisa existir no painel Master antes de receber dados. Use o cadastro de igrejas no Master.</p><?php else:?><p>Destino selecionado: <strong><?=e($church['name'])?></strong></p><?php endif;?>
</section>
<?php if($church):?>
<section class="card card-pad" style="margin-top:16px">
<h2>2. Envie a planilha do Enuves</h2>
<p>O sistema fará uma prévia, sem modificar registros. Use o arquivo XLSX exportado em Pessoas no Enuves.</p>
<form method="post" enctype="multipart/form-data" class="mig-fields">
<?=csrf_field()?><input type="hidden" name="action" value="preview"><input type="hidden" name="church_id" value="<?=e($cid)?>">
<label>Exportação de pessoas (.xlsx)
<input type="file" name="enuves_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
<button class="btn btn-primary" style="justify-self:start">Conferir arquivo e mostrar prévia</button>
</form>
</section>
<?php if($preview):?>
<section class="card card-pad" style="margin-top:16px" id="import-preview">
<h2>3. Confira o que será importado</h2>
<div class="mig-grid">
<?php foreach(['total'=>'Pessoas no arquivo','active'=>'Ativas','archived'=>'Arquivadas (inativas)','missing_phone'=>'Sem telefone','activity_rows'=>'Com interesse em atividades'] as $key=>$label):?>
<div class="card"><strong><?=e((string)$preview[$key])?></strong><small><?=e($label)?></small></div>
<?php endforeach;?>
</div>
<h3>Departamentos sugeridos pelos interesses</h3>
<div class="mig-table"><table><thead><tr><th>Departamento</th><th>Pessoas interessadas</th></tr></thead><tbody>
<?php foreach($preview['departments'] as $name=>$qty):?><tr><td><?=e($name)?></td><td><?=e((string)$qty)?></td></tr><?php endforeach;?>
</tbody></table></div>
<p class="mig-warning"><strong>Importante:</strong> indicar interesse em louvor, recepção ou crianças não significa ser líder ou integrante confirmado. O sistema criará departamentos e guardará os interesses, mas não atribuirá automaticamente funções, senhas ou acesso financeiro.</p>
<form method="post" class="mig-fields"><?=csrf_field()?>
<input type="hidden" name="action" value="import"><input type="hidden" name="church_id" value="<?=e($cid)?>">
<label><input type="checkbox" name="create_departments" value="1" checked> Criar departamentos ausentes e guardar interesses dos participantes</label>
<label>Digite o nome exato da igreja de destino para confirmar
<input class="input" name="confirm_church_name" required autocomplete="off" placeholder="<?=e($church['name'])?>"></label>
<label><input type="checkbox" name="confirmed" value="1" required> Confirmei que a importação deve ocorrer somente em <?=e($church['name'])?>.</label>
<button class="btn btn-primary" style="justify-self:start">Importar cadastros para esta igreja</button>
</form>
</section>
<?php endif;?>
<section class="card card-pad" style="margin-top:16px" id="leaders">
<h2>4. Designar líderes dos departamentos</h2>
<p>A planilha de pessoas do Enuves não informa de modo confiável quem lidera cada ministério. Escolha manualmente o líder aqui; se já existir um login com perfil de líder vinculado a ele, o acesso ao departamento será habilitado.</p>
<?php if($departments&&$people):?>
<form method="post" class="mig-fields"><?=csrf_field()?>
<input type="hidden" name="action" value="leader"><input type="hidden" name="church_id" value="<?=e($cid)?>">
<label>Departamento<select class="select" name="department_id" required><option value="">Selecione</option>
<?php foreach($departments as $d):?><option value="<?=e($d['id'])?>"><?=e($d['name'])?> (<?=e((string)$d['interested'])?> interessados)</option><?php endforeach;?></select></label>
<label>Líder escolhido<select class="select" name="member_id" required><option value="">Selecione a pessoa</option>
<?php foreach($people as $p):?><option value="<?=e($p['id'])?>"><?=e($p['name'])?></option><?php endforeach;?></select></label>
<button class="btn btn-primary" style="justify-self:start">Designar líder selecionado</button></form>
<?php else:?><p>Importe as pessoas e os departamentos para escolher os responsáveis.</p><?php endif;?>
<?php if($assigned):?><div class="mig-table"><table><thead><tr><th>Departamento</th><th>Líder designado</th><th>Acesso</th></tr></thead><tbody>
<?php foreach($assigned as $a):?><tr><td><?=e($a['department_name'])?></td><td><?=e($a['member_name'])?></td><td><?=e($a['status']==='active'?'Ativo':'Pendente de login de líder')?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</section>
<section class="card card-pad" style="margin-top:16px" id="import-history">
<h2>Histórico de importações</h2>
<div class="mig-table"><table><thead><tr><th>Quando</th><th>Pessoas criadas</th><th>Vinculadas</th><th>Ignoradas</th><th>Departamentos</th></tr></thead><tbody>
<?php foreach($batches as $b):?><tr><td><?=e($b['imported_at'])?></td><td><?=e((string)$b['members_created'])?></td><td><?=e((string)$b['linked_existing'])?></td><td><?=e((string)$b['skipped'])?></td><td><?=e((string)$b['departments_created'])?></td></tr><?php endforeach;?>
<?php if(!$batches):?><tr><td colspan="5">Nenhuma importação executada nesta igreja.</td></tr><?php endif;?>
</tbody></table></div></section>
<?php endif;?>
<?php View::footer();?>
