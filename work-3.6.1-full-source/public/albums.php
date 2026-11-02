<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MediaLibraryService.php';

$user=$auth->requireAbility('media_portal');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$cid=(string)$church['id'];
$service=new MediaLibraryService($pdo,$auth,$cid,__DIR__);
$isManager=$service->isManager();

$folders=array_values(array_filter(
    $service->folders(true),
    fn($f)=>in_array((string)($f['folder_type']??'folder'),['photo_album','video_album'],true)
));

$albumId=trim((string)($_GET['album']??''));
$selected=null;
foreach($folders as $folder)if((string)$folder['id']===$albumId){$selected=$folder;break;}

$items=[];
if($selected){
    $type=$selected['folder_type']==='photo_album'?'image':'video';
    $items=$service->visibleForCurrentUser(['folder_id'=>$selected['id'],'media_type'=>$type]);
}

View::header('Fotos e Vídeos',$auth,'albums.php');
View::flash();
?>
<style>
.album-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.album-head h1{margin:0 0 5px}.album-head p{margin:0;color:var(--muted)}
.album-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.album-card{display:block;text-decoration:none;color:inherit;overflow:hidden}.album-thumb{height:170px;background:#edf2f6;display:flex;align-items:center;justify-content:center;font-size:42px;color:#71869a}.album-card-body{padding:13px}.album-card-body h2{font-size:15px;margin:0 0 4px}.album-card-body p{font-size:10px;color:var(--muted);margin:0}
.gallery-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.gallery-item{overflow:hidden;background:#000;border-radius:12px}.gallery-item img,.gallery-item video{width:100%;height:220px;object-fit:cover;display:block}.gallery-caption{padding:10px 12px;background:var(--card);color:var(--text)}.gallery-caption strong{display:block}.gallery-caption small{color:var(--muted)}
@media(max-width:900px){.album-grid,.gallery-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.album-grid,.gallery-grid{grid-template-columns:1fr}.album-head{flex-direction:column}}
</style>

<div class="album-head">
  <div><h1>Fotos e Vídeos</h1><p>Álbuns publicados pela igreja.</p></div>
  <?php if($isManager):?><a class="btn btn-primary" href="/media-library.php">Gerenciar Biblioteca</a><?php endif?>
</div>

<?php if(!$selected):?>
  <div class="album-grid">
    <?php foreach($folders as $album):?>
      <a class="card album-card" href="/albums.php?album=<?=e($album['id'])?>">
        <div class="album-thumb"><?=$album['folder_type']==='photo_album'?'▧':'▶'?></div>
        <div class="album-card-body"><h2><?=e($album['name'])?></h2><p><?=e($album['description']?:($album['folder_type']==='photo_album'?'Álbum de fotos':'Álbum de vídeos'))?> · <?=(int)$album['item_count']?> item(ns)</p></div>
      </a>
    <?php endforeach?>
    <?php if(!$folders):?><section class="card card-pad empty-state"><h2>Nenhum álbum publicado</h2><p><?=$isManager?'Crie pastas do tipo Álbum de Fotos ou Álbum de Vídeos na Biblioteca.':'Quando a igreja publicar fotos ou vídeos, eles aparecerão aqui.'?></p></section><?php endif?>
  </div>
<?php else:?>
  <div class="page-head"><div><a class="muted" href="/albums.php">← Voltar aos álbuns</a><h2 style="margin-top:8px"><?=e($selected['name'])?></h2><p><?=e($selected['description']?:'')?></p></div></div>
  <div class="gallery-grid">
    <?php foreach($items as $item):$url=$service->url($item);if(!$url)continue;?>
      <article class="gallery-item">
        <?php if($selected['folder_type']==='photo_album'):?><a href="<?=e($url)?>" target="_blank" rel="noopener noreferrer"><img src="<?=e($url)?>" alt="<?=e($item['title'])?>" loading="lazy"></a>
        <?php else:?><video src="<?=e($url)?>" controls preload="metadata"></video><?php endif?>
        <div class="gallery-caption"><strong><?=e($item['title'])?></strong><?php if($item['description']):?><small><?=e($item['description'])?></small><?php endif?></div>
      </article>
    <?php endforeach?>
    <?php if(!$items):?><section class="card card-pad empty-state"><h2>Álbum vazio</h2><p>Nenhuma mídia visível para o seu acesso neste álbum.</p></section><?php endif?>
  </div>
<?php endif?>

<?php View::footer(); ?>
