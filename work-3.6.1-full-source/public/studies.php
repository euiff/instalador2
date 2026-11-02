<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/StudyService.php';
require_once __DIR__.'/lib/MediaLibraryService.php';

$user=$auth->requireAbility('study_portal');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$cid=(string)$church['id'];
$service=new StudyService($pdo,$auth,$cid);
$mediaLibrary=new MediaLibraryService($pdo,$auth,$cid,__DIR__);
$isManager=$service->isManager();
$error=flash('error');$success=flash('success');

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='save_category'){
            $service->saveCategory($_POST);
            flash('success','Categoria de estudos salva.');
            redirect('/studies.php');
        }
        if($action==='save'){
            $audience=(string)($_POST['audience_type']??'all');
            $_POST['target_id']=$audience==='department'
                ?(string)($_POST['department_id']??'')
                :($audience==='group'?(string)($_POST['group_id']??''):'');
            $id=$service->save($_POST);
            flash('success','Estudo salvo.');
            redirect('/studies.php?id='.rawurlencode($id));
        }
        if($action==='archive'){
            $service->archive((string)($_POST['id']??''));
            flash('success','Estudo arquivado.');
            redirect('/studies.php');
        }
        if($action==='read'){
            $service->markRead((string)($_POST['id']??''));
            flash('success','Estudo marcado como lido.');
            redirect('/studies.php?id='.rawurlencode((string)$_POST['id']));
        }
        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível salvar. Revise os dados e tente novamente.':$e->getMessage();
    }
}

$categories=$service->categories($isManager?false:true);
$categoryId=trim((string)($_GET['category']??''));
$rows=$isManager?$service->all($categoryId?:null):$service->visible($categoryId?:null);

$selected=null;
if(!empty($_GET['id'])){
    try{$selected=$service->item((string)$_GET['id'],!$isManager);}
    catch(Throwable $e){$error=$e->getMessage();}
}

$edit=null;
if($isManager&&!empty($_GET['edit'])){
    try{$edit=$service->item((string)$_GET['edit']);}
    catch(Throwable $e){$error=$e->getMessage();}
}

$categoryEdit=null;
if($isManager&&!empty($_GET['category_edit'])){
    foreach($categories as $cat)if((string)$cat['id']===(string)$_GET['category_edit']){$categoryEdit=$cat;break;}
}

$q=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$departments=$q->fetchAll();
$q=$pdo->prepare('SELECT id,name FROM small_groups WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$groups=$q->fetchAll();

$mediaItems=$isManager?$mediaLibrary->reusableItems():$mediaLibrary->visibleForCurrentUser();
$coverItems=array_values(array_filter($mediaItems,fn($m)=>($m['media_type']??'')==='image'));
$audienceLabels=['all'=>'Todos','members'=>'Membros','leaders'=>'Liderança','department'=>'Departamento','group'=>'Grupo/Célula'];

function study_resource(?string $storage,?string $external): ?string {
    $external=trim((string)$external);
    if($external!=='')return $external;
    $storage=trim((string)$storage);
    return $storage!==''?'/'.ltrim($storage,'/'):null;
}

View::header('Estudos',$auth,'studies.php');
View::flash();
?>
<style>
.study-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.study-head h1{margin:0 0 5px}.study-head p{margin:0;color:var(--muted)}
.study-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;align-items:start}.study-cats{display:grid;gap:6px}.study-cat{display:flex;justify-content:space-between;gap:8px;padding:9px 10px;border-radius:9px;text-decoration:none;color:inherit}.study-cat:hover,.study-cat.active{background:#eef5fb;color:#174e88}
.study-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.study-card{overflow:hidden}.study-cover{height:180px;background:#edf2f6;display:flex;align-items:center;justify-content:center}.study-cover img{width:100%;height:100%;object-fit:cover}.study-cover span{font-size:42px;color:#74889c}.study-body{padding:15px}.study-body h2{margin:0 0 6px;font-size:16px}.study-body p{color:var(--muted);font-size:11px;line-height:1.5}.study-tags{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}.study-tags span{font-size:9px;background:#eef3f7;padding:3px 6px;border-radius:999px}
.study-article{max-width:900px;margin:0 auto}.study-article-cover{max-height:420px;width:100%;object-fit:cover;border-radius:14px}.study-content{white-space:pre-line;line-height:1.75;font-size:14px}.study-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
@media(max-width:900px){.study-layout{grid-template-columns:1fr}.study-cats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.study-grid,.study-cats{grid-template-columns:1fr}.study-head{flex-direction:column}}
</style>

<div class="study-head">
  <div><h1>Estudos</h1><p><?=$isManager?'Publique estudos por categoria, com capa, material e público-alvo.':'Estudos e materiais liberados pela sua igreja.'?></p></div>
  <?php if($isManager):?><div class="actions"><button class="btn btn-light" type="button" data-open-dialog="study-category-editor">＋ Categoria</button><button class="btn btn-primary" type="button" data-open-dialog="study-editor">＋ Novo estudo</button></div><?php endif?>
</div>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<?php if($selected):
  $cover=study_resource($selected['cover_storage']??null,$selected['cover_external']??null);
  $attachment=study_resource($selected['attachment_storage']??null,$selected['attachment_external']??null);
?>
<section class="card card-pad study-article">
  <div class="page-head"><div><a class="muted" href="/studies.php">← Voltar aos estudos</a><h1 style="margin-top:8px"><?=e($selected['title'])?></h1><p><?=e($selected['category_name']?:'Sem categoria')?> · <?=date('d/m/Y',strtotime($selected['published_at']?:$selected['created_at']))?></p></div><?php if($isManager):?><a class="btn btn-light" href="/studies.php?edit=<?=e($selected['id'])?>">Editar</a><?php endif?></div>
  <?php if($cover):?><img class="study-article-cover" src="<?=e($cover)?>" alt="<?=e($selected['title'])?>"><?php endif?>
  <?php if($selected['summary']):?><p style="font-size:15px;font-weight:700;line-height:1.6"><?=e($selected['summary'])?></p><?php endif?>
  <?php if($selected['body']):?><div class="study-content"><?=e($selected['body'])?></div><?php endif?>
  <div class="study-actions">
    <?php if($attachment):?><a class="btn btn-primary" href="<?=e($attachment)?>" target="_blank" rel="noopener noreferrer">Abrir material</a><?php endif?>
    <?php if($selected['external_url']):?><a class="btn btn-light" href="<?=e($selected['external_url'])?>" target="_blank" rel="noopener noreferrer">Abrir link complementar</a><?php endif?>
    <?php if(!$isManager&&empty($selected['is_read'])):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?=e($selected['id'])?>"><button class="btn btn-light">Marcar como lido</button></form><?php endif?>
  </div>
  <?php if($isManager):?><div class="muted" style="margin-top:12px"><?=(int)$selected['read_count']?> leitura(s) registrada(s)</div><?php endif?>
</section>
<?php else:?>
<div class="study-layout">
  <aside class="card card-pad">
    <h2 style="font-size:15px">Categorias</h2>
    <div class="study-cats">
      <a class="study-cat <?=$categoryId===''?'active':''?>" href="/studies.php"><span>Todos</span></a>
      <?php foreach($categories as $cat):?><a class="study-cat <?=$categoryId===$cat['id']?'active':''?>" href="/studies.php?category=<?=e($cat['id'])?>"><span><?=e($cat['name'])?></span><small><?=(int)$cat['study_count']?></small></a><?php endforeach?>
    </div>
    <?php if($isManager&&$categories):?><div style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px"><?php foreach($categories as $cat):?><div><a class="muted" href="/studies.php?category_edit=<?=e($cat['id'])?>">Editar <?=e($cat['name'])?></a></div><?php endforeach?></div><?php endif?>
  </aside>

  <section class="study-grid">
    <?php foreach($rows as $row):
      $cover=study_resource($row['cover_storage']??null,$row['cover_external']??null);
    ?>
      <article class="card study-card">
        <div class="study-cover"><?php if($cover):?><img src="<?=e($cover)?>" alt="<?=e($row['title'])?>" loading="lazy"><?php else:?><span>▤</span><?php endif?></div>
        <div class="study-body">
          <h2><?=e($row['title'])?></h2>
          <div class="study-tags"><span><?=e($row['category_name']?:'Sem categoria')?></span><?php if($row['featured']):?><span>Destaque</span><?php endif?><?php if(!$isManager&&!empty($row['is_read'])):?><span>Lido</span><?php endif?><?php if($isManager&&!$row['active']):?><span>Arquivado</span><?php endif?></div>
          <p><?=e($row['summary']?:mb_strimwidth((string)$row['body'],0,180,'…'))?></p>
          <div class="actions"><a class="btn btn-light" href="/studies.php?id=<?=e($row['id'])?>">Abrir estudo</a><?php if($isManager):?><a class="btn btn-light" href="/studies.php?edit=<?=e($row['id'])?>">Editar</a><?php endif?></div>
        </div>
      </article>
    <?php endforeach?>
    <?php if(!$rows):?><section class="card card-pad empty-state"><h2>Nenhum estudo encontrado</h2><p><?=$isManager?'Publique o primeiro estudo da igreja.':'Nenhum estudo foi liberado para este acesso.'?></p></section><?php endif?>
  </section>
</div>
<?php endif?>

<?php if($isManager):?>
<dialog class="app-dialog" id="study-category-editor" aria-labelledby="study-category-title" <?=$categoryEdit?'open':''?>>
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="study-category-title"><?=$categoryEdit?'Editar categoria':'Nova categoria'?></h2></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_category"><input type="hidden" name="id" value="<?=e($categoryEdit['id']??'')?>">
    <div class="form-grid"><label class="full">Nome *<input class="input" name="name" required maxlength="160" value="<?=e($categoryEdit['name']??'')?>"></label><label class="full">Descrição<input class="input" name="description" maxlength="500" value="<?=e($categoryEdit['description']??'')?>"></label><label>Ordem<input class="input" type="number" name="sort_order" value="<?=e((string)($categoryEdit['sort_order']??0))?>"></label><label><input type="checkbox" name="active" value="1" <?=!$categoryEdit||!empty($categoryEdit['active'])?'checked':''?>> Ativa</label></div>
    <button class="btn btn-primary">Salvar categoria</button>
  </form></div></div>
</dialog>

<dialog class="app-dialog" id="study-editor" aria-labelledby="study-title" <?=$edit?'open':''?>>
  <div class="dialog-shell"><header class="dialog-heading"><div><h2 id="study-title"><?=$edit?'Editar estudo':'Novo estudo'?></h2><p>Conteúdo, material, capa e público.</p></div><button class="icon-button" type="button" data-close-dialog>×</button></header>
  <div class="dialog-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
    <div class="form-grid">
      <label class="full">Título *<input class="input" name="title" required maxlength="190" value="<?=e($edit['title']??'')?>"></label>
      <label>Categoria<select class="select" name="category_id"><option value="">Sem categoria</option><?php foreach($categories as $cat):?><option value="<?=e($cat['id'])?>" <?=($edit['category_id']??'')===$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach?></select></label>
      <label>Publicar em<input class="input" type="datetime-local" name="published_at" value="<?=e(!empty($edit['published_at'])?date('Y-m-d\TH:i',strtotime($edit['published_at'])):date('Y-m-d\TH:i'))?>"></label>
      <label class="full">Resumo<textarea class="textarea" name="summary" maxlength="1000"><?=e($edit['summary']??'')?></textarea></label>
      <label class="full">Conteúdo<textarea class="textarea tall" name="body" maxlength="50000"><?=e($edit['body']??'')?></textarea></label>
      <label>Capa da Biblioteca<select class="select" name="cover_media_id"><option value="">Sem capa</option><?php foreach($coverItems as $media):?><option value="<?=e($media['id'])?>" <?=($edit['cover_media_id']??'')===$media['id']?'selected':''?>><?=e($media['title'])?></option><?php endforeach?></select></label>
      <label>Material anexo<select class="select" name="attachment_media_id"><option value="">Sem material</option><?php foreach($mediaItems as $media):?><option value="<?=e($media['id'])?>" <?=($edit['attachment_media_id']??'')===$media['id']?'selected':''?>><?=e($media['title'])?> · <?=e($media['media_type'])?></option><?php endforeach?></select></label>
      <label class="full">Link complementar<input class="input" type="url" name="external_url" value="<?=e($edit['external_url']??'')?>" placeholder="https://..."></label>
      <label>Público<select class="select" name="audience_type" id="study-audience"><?php foreach($audienceLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($edit['audience_type']??'all')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
      <label class="full" id="study-department-wrap">Departamento<select class="select" name="department_id"><option value="">Selecione...</option><?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=($edit['audience_type']??'')==='department'&&($edit['target_id']??'')===$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach?></select></label>
      <label class="full" id="study-group-wrap">Grupo/Célula<select class="select" name="group_id"><option value="">Selecione...</option><?php foreach($groups as $g):?><option value="<?=e($g['id'])?>" <?=($edit['audience_type']??'')==='group'&&($edit['target_id']??'')===$g['id']?'selected':''?>><?=e($g['name'])?></option><?php endforeach?></select></label>
      <label><input type="checkbox" name="featured" value="1" <?=!empty($edit['featured'])?'checked':''?>> Destacar</label>
      <label><input type="checkbox" name="active" value="1" <?=($edit ? (!empty($edit['active'])?'checked':'') : 'checked')?>> Publicado</label>
    </div>
    <button class="btn btn-primary">Salvar estudo</button>
  </form>
  <?php if($edit&&$edit['active']):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Arquivar este estudo?')"><?=csrf_field()?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=e($edit['id'])?>"><button class="btn btn-danger" style="width:100%">Arquivar estudo</button></form><?php endif?>
  </div></div>
</dialog>

<script>
(function(){
  const a=document.getElementById('study-audience'),d=document.getElementById('study-department-wrap'),g=document.getElementById('study-group-wrap');
  function sync(){const v=a?.value||'all';if(d)d.hidden=v!=='department';if(g)g.hidden=v!=='group';}
  a?.addEventListener('change',sync);sync();
})();
</script>
<?php endif?>

<?php View::footer(); ?>
