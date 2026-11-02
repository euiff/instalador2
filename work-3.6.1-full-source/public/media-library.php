<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MediaLibraryService.php';

$user=$auth->requireAbility('media_portal');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$cid=(string)$church['id'];
$service=new MediaLibraryService($pdo,$auth,$cid,__DIR__);
$isManager=$service->isManager();
$error=flash('error');
$success=flash('success');

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='save_folder'){
            $service->saveFolder($_POST);
            flash('success','Pasta salva na Biblioteca.');
            redirect('/media-library.php');
        }

        if($action==='save_item'){
            $audience=(string)($_POST['audience_type']??'all');
            $_POST['target_id']=$audience==='department'
                ?(string)($_POST['department_id']??'')
                :($audience==='group'?(string)($_POST['group_id']??''):'');
            $id=$service->saveItem($_POST,$_FILES['media_file']??[]);
            flash('success','Arquivo salvo na Biblioteca.');
            redirect('/media-library.php?edit='.rawurlencode($id));
        }

        if($action==='archive'){
            $service->archive((string)($_POST['id']??''));
            flash('success','Arquivo arquivado.');
            redirect('/media-library.php');
        }

        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível salvar o arquivo. Revise os dados e tente novamente.':$e->getMessage();
    }
}

$folders=$service->folders($isManager?false:true);
$folderId=trim((string)($_GET['folder']??''));
$type=trim((string)($_GET['type']??''));
$search=trim((string)($_GET['q']??''));
$filters=[];
if($folderId!=='')$filters['folder_id']=$folderId;
if(in_array($type,['image','video','audio','pdf','document','link'],true))$filters['media_type']=$type;
if($search!=='')$filters['q']=$search;

$items=$isManager?$service->all($filters):$service->visibleForCurrentUser($filters);

$edit=null;
if($isManager&&!empty($_GET['edit'])){
    try{$edit=$service->item((string)$_GET['edit']);}
    catch(Throwable $e){$error=$e->getMessage();}
}

$folderEdit=null;
if($isManager&&!empty($_GET['folder_edit'])){
    foreach($folders as $folder)if((string)$folder['id']===(string)$_GET['folder_edit']){$folderEdit=$folder;break;}
}

$q=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$departments=$q->fetchAll();

$q=$pdo->prepare('SELECT id,name FROM small_groups WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$groups=$q->fetchAll();

$typeLabels=['image'=>'Imagem','video'=>'Vídeo','audio'=>'Áudio','pdf'=>'PDF','document'=>'Documento','link'=>'Link'];
$audienceLabels=['all'=>'Todos os usuários','members'=>'Membros','leaders'=>'Liderança','department'=>'Departamento','group'=>'Grupo/Célula'];

$total=count($items);
$images=count(array_filter($items,fn($i)=>$i['media_type']==='image'));
$docs=count(array_filter($items,fn($i)=>in_array($i['media_type'],['pdf','document'],true)));
$featured=count(array_filter($items,fn($i)=>!empty($i['featured'])));

View::header('Biblioteca de Mídia',$auth,'media-library.php');
View::flash();
?>
<style>
.media-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.media-head h1{margin:0 0 5px}.media-head p{margin:0;color:var(--muted)}
.media-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}.media-stats .card{padding:13px}.media-stats small{display:block;color:var(--muted);font-size:10px}.media-stats strong{display:block;font-size:20px;margin-top:3px}
.media-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:16px;align-items:start}.media-folders{display:grid;gap:6px}.media-folder{display:flex;justify-content:space-between;gap:8px;padding:9px 10px;border-radius:9px;text-decoration:none;color:inherit}.media-folder:hover,.media-folder.active{background:#eef5fb;color:#174e88}.media-folder small{color:var(--muted)}
.media-toolbar{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px}.media-toolbar label{min-width:150px}.media-toolbar .grow{flex:1;min-width:220px}
.media-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.media-item{overflow:hidden}.media-preview{height:160px;background:#eef2f6;display:flex;align-items:center;justify-content:center;overflow:hidden}.media-preview img,.media-preview video{width:100%;height:100%;object-fit:cover}.media-preview audio{width:90%}.media-icon{font-size:44px;color:#60758b}.media-body{padding:13px}.media-body h3{margin:0 0 5px;font-size:14px}.media-body p{margin:0;color:var(--muted);font-size:11px;line-height:1.45}.media-tags{display:flex;gap:5px;flex-wrap:wrap;margin:8px 0}.media-tags span{font-size:9px;padding:3px 6px;border-radius:999px;background:#f0f4f7}.media-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.media-actions .btn{padding:6px 8px}
.media-size{font-size:9px;color:var(--muted)}
@media(max-width:1050px){.media-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:800px){.media-layout{grid-template-columns:1fr}.media-folders{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.media-grid,.media-stats,.media-folders{grid-template-columns:1fr}.media-head{flex-direction:column}}
</style>

<div class="media-head">
  <div>
    <h1>Biblioteca de Mídia e Arquivos</h1>
    <p><?=$isManager?'Centralize cartazes, fotos, PDFs, vídeos, áudios e materiais da igreja.':'Arquivos e materiais disponibilizados para o seu acesso.'?></p>
  </div>
  <?php if($isManager):?><div class="actions"><button class="btn btn-light" type="button" data-open-dialog="media-folder-editor">＋ Pasta</button><button class="btn btn-primary" type="button" data-open-dialog="media-item-editor">＋ Arquivo</button></div><?php endif?>
</div>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<div class="media-stats">
  <div class="card"><small>Arquivos exibidos</small><strong><?=$total?></strong></div>
  <div class="card"><small>Imagens</small><strong><?=$images?></strong></div>
  <div class="card"><small>PDFs e documentos</small><strong><?=$docs?></strong></div>
  <div class="card"><small>Em destaque</small><strong><?=$featured?></strong></div>
</div>

<div class="media-layout">
  <aside class="card card-pad">
    <div class="page-head"><div><h2 style="font-size:15px">Pastas</h2></div></div>
    <div class="media-folders">
      <a class="media-folder <?=$folderId===''?'active':''?>" href="/media-library.php"><span>Todos os arquivos</span></a>
      <?php foreach($folders as $folder):?>
        <a class="media-folder <?=$folderId===$folder['id']?'active':''?>" href="/media-library.php?folder=<?=e($folder['id'])?>">
          <span><?=e($folder['name'])?></span><small><?=(int)$folder['item_count']?></small>
        </a>
      <?php endforeach?>
    </div>
    <?php if($isManager&&$folders):?>
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
        <small class="muted">Organização</small>
        <?php foreach($folders as $folder):?><div style="margin-top:6px"><a href="/media-library.php?folder_edit=<?=e($folder['id'])?>" class="muted">Editar <?=e($folder['name'])?></a></div><?php endforeach?>
      </div>
    <?php endif?>
  </aside>

  <section>
    <form method="get" class="card card-pad media-toolbar">
      <label class="grow">Buscar<input class="input" name="q" value="<?=e($search)?>" placeholder="Título, categoria ou nome do arquivo"></label>
      <label>Tipo<select class="select" name="type"><option value="">Todos</option><?php foreach($typeLabels as $key=>$label):?><option value="<?=e($key)?>" <?=$type===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
      <?php if($folderId!==''):?><input type="hidden" name="folder" value="<?=e($folderId)?>"><?php endif?>
      <button class="btn btn-primary">Filtrar</button>
      <?php if($search!==''||$type!==''):?><a class="btn btn-light" href="/media-library.php<?=$folderId!==''?'?folder='.rawurlencode($folderId):''?>">Limpar</a><?php endif?>
    </form>

    <div class="media-grid">
    <?php foreach($items as $item):
      $url=$service->url($item);
      $size=(int)$item['file_size'];
      $sizeText=$size>0?($size>=1048576?number_format($size/1048576,1,',','.').' MB':number_format($size/1024,0,',','.').' KB'):'';
    ?>
      <article class="card media-item">
        <div class="media-preview">
          <?php if($url&&$item['media_type']==='image'):?><img src="<?=e($url)?>" alt="<?=e($item['title'])?>" loading="lazy">
          <?php elseif($url&&$item['media_type']==='video'):?><video src="<?=e($url)?>" controls preload="metadata"></video>
          <?php elseif($url&&$item['media_type']==='audio'):?><audio src="<?=e($url)?>" controls preload="none"></audio>
          <?php else:?><div class="media-icon"><?=$item['media_type']==='pdf'?'PDF':($item['media_type']==='link'?'↗':'▤')?></div><?php endif?>
        </div>
        <div class="media-body">
          <h3><?=e($item['title'])?></h3>
          <p><?=e(mb_strimwidth((string)($item['description']??''),0,115,'…'))?></p>
          <div class="media-tags">
            <span><?=e($typeLabels[$item['media_type']]??$item['media_type'])?></span>
            <?php if($item['folder_name']):?><span><?=e($item['folder_name'])?></span><?php endif?>
            <?php if($item['category']):?><span><?=e($item['category'])?></span><?php endif?>
            <?php if($item['featured']):?><span>Destaque</span><?php endif?>
            <?php if($isManager&&!$item['active']):?><span>Arquivado</span><?php endif?>
          </div>
          <?php if($sizeText):?><div class="media-size"><?=e($sizeText)?><?=!empty($item['original_name'])?' · '.e($item['original_name']):''?></div><?php endif?>
          <div class="media-actions">
            <?php if($url):?><a class="btn btn-light" target="_blank" rel="noopener noreferrer" href="<?=e($url)?>">Abrir</a><?php endif?>
            <?php if($isManager):?><a class="btn btn-light" href="/media-library.php?edit=<?=e($item['id'])?>">Editar</a><?php endif?>
            <?php if($isManager&&$item['active']):?><form method="post" onsubmit="return confirm('Arquivar este arquivo?')"><?=csrf_field()?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=e($item['id'])?>"><button class="btn btn-light">Arquivar</button></form><?php endif?>
          </div>
        </div>
      </article>
    <?php endforeach?>
    <?php if(!$items):?><section class="card card-pad empty-state"><h2>Nenhum arquivo encontrado</h2><p><?=$isManager?'Adicione o primeiro material à Biblioteca.':'A igreja ainda não disponibilizou arquivos para este acesso.'?></p></section><?php endif?>
    </div>
  </section>
</div>

<?php if($isManager):?>
<dialog class="app-dialog" id="media-folder-editor" aria-labelledby="media-folder-title" <?=$folderEdit?'open':''?>>
  <div class="dialog-shell">
    <header class="dialog-heading"><div><h2 id="media-folder-title"><?=$folderEdit?'Editar pasta':'Nova pasta'?></h2><p>Organize a biblioteca por ministério, tipo ou finalidade.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
    <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_folder"><input type="hidden" name="id" value="<?=e($folderEdit['id']??'')?>">
      <div class="form-grid">
        <label class="full">Nome *<input class="input" name="name" required maxlength="160" value="<?=e($folderEdit['name']??'')?>"></label>
        <label class="full">Descrição<input class="input" name="description" maxlength="500" value="<?=e($folderEdit['description']??'')?>"></label>
        <label>Ordem<input class="input" type="number" name="sort_order" value="<?=e((string)($folderEdit['sort_order']??0))?>"></label>
        <label><input type="checkbox" name="active" value="1" <?=!$folderEdit||!empty($folderEdit['active'])?'checked':''?>> Pasta ativa</label>
      </div>
      <button class="btn btn-primary">Salvar pasta</button>
    </form></div>
  </div>
</dialog>

<dialog class="app-dialog" id="media-item-editor" aria-labelledby="media-item-title" <?=$edit||isset($_GET['new'])?'open':''?>>
  <div class="dialog-shell">
    <header class="dialog-heading"><div><h2 id="media-item-title"><?=$edit?'Editar arquivo':'Adicionar à Biblioteca'?></h2><p>Faça upload ou cadastre um link externo.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
    <div class="dialog-body">
      <form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="save_item"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
        <div class="form-grid">
          <label class="full">Título *<input class="input" name="title" required maxlength="190" value="<?=e($edit['title']??'')?>"></label>
          <label>Pasta<select class="select" name="folder_id"><option value="">Sem pasta</option><?php foreach($folders as $folder):if(!$folder['active']&&($edit['folder_id']??'')!==$folder['id'])continue;?><option value="<?=e($folder['id'])?>" <?=($edit['folder_id']??'')===$folder['id']?'selected':''?>><?=e($folder['name'])?></option><?php endforeach?></select></label>
          <label>Categoria<input class="input" name="category" maxlength="120" value="<?=e($edit['category']??'')?>" placeholder="Ex.: Culto da Família"></label>
          <label class="full">Descrição<textarea class="textarea" name="description" maxlength="10000"><?=e($edit['description']??'')?></textarea></label>

          <label class="full">Enviar arquivo<input class="input" type="file" name="media_file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,audio/mpeg,audio/ogg,audio/wav,video/mp4,video/webm,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><small class="muted">Imagens, PDF, áudio, vídeo e documentos até 50 MB. O limite real também depende do PHP da hospedagem.</small></label>

          <label class="full">Ou usar link externo<input class="input" type="url" name="external_url" value="<?=e($edit['external_url']??'')?>" placeholder="https://..."><small class="muted">Útil para YouTube, Drive, arquivos grandes ou materiais já hospedados.</small></label>
          <label>Tipo do link externo<select class="select" name="media_type"><?php foreach($typeLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($edit['media_type']??'link')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
          <label>Público<select class="select" name="audience_type" id="media-audience"><?php foreach($audienceLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($edit['audience_type']??'all')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
          <label class="full" id="media-department-wrap">Departamento<select class="select" name="department_id"><option value="">Selecione...</option><?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=($edit['audience_type']??'')==='department'&&($edit['target_id']??'')===$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach?></select></label>
          <label class="full" id="media-group-wrap">Grupo/Célula<select class="select" name="group_id"><option value="">Selecione...</option><?php foreach($groups as $g):?><option value="<?=e($g['id'])?>" <?=($edit['audience_type']??'')==='group'&&($edit['target_id']??'')===$g['id']?'selected':''?>><?=e($g['name'])?></option><?php endforeach?></select></label>
          <label><input type="checkbox" name="featured" value="1" <?=!empty($edit['featured'])?'checked':''?>> Destacar</label>
          <label><input type="checkbox" name="active" value="1" <?=($edit ? (!empty($edit['active'])?'checked':'') : 'checked')?>> Arquivo ativo</label>
        </div>
        <button class="btn btn-primary">Salvar na Biblioteca</button>
      </form>
    </div>
  </div>
</dialog>

<script>
(function(){
  const audience=document.getElementById('media-audience');
  const dep=document.getElementById('media-department-wrap');
  const group=document.getElementById('media-group-wrap');
  function sync(){
    const value=audience?.value||'all';
    if(dep)dep.hidden=value!=='department';
    if(group)group.hidden=value!=='group';
  }
  audience?.addEventListener('change',sync);sync();
})();
</script>
<?php endif?>

<?php View::footer(); ?>
