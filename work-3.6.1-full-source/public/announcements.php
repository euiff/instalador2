<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AnnouncementService.php';
require_once __DIR__.'/lib/MediaLibraryService.php';

$user=$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church)redirect('/dashboard.php');
$cid=(string)$church['id'];
$service=new AnnouncementService($pdo,$auth,$cid);
$mediaLibrary=new MediaLibraryService($pdo,$auth,$cid,__DIR__);

$error=flash('error');
$success=flash('success');

$edit=null;
$editId=trim((string)($_GET['edit']??''));
if($editId!==''){
    foreach($service->all() as $row)if((string)$row['id']===$editId){$edit=$row;break;}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='save'){
            $audience=(string)($_POST['audience_type']??'all');
            $_POST['target_id']=$audience==='department'
                ?(string)($_POST['department_id']??'')
                :($audience==='group'?(string)($_POST['group_id']??''):'');
            $mediaId=trim((string)($_POST['media_library_id']??''));
            if($mediaId!==''){
                $mediaItem=$mediaLibrary->item($mediaId);
                $mediaUrl=$mediaLibrary->url($mediaItem);
                if(!$mediaUrl)throw new RuntimeException('O arquivo selecionado na Biblioteca não possui endereço disponível.');
                $_POST['attachment_url']=$mediaUrl;
            }
            $id=$service->save($_POST);
            flash('success','Aviso salvo no Mural.');
            redirect('/announcements.php?edit='.rawurlencode($id));
        }

        if($action==='archive'){
            $service->archive((string)($_POST['id']??''));
            flash('success','Aviso arquivado.');
            redirect('/announcements.php');
        }

        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível salvar o aviso. Revise os dados.':$e->getMessage();
    }
}

$rows=$service->all();

$q=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$departments=$q->fetchAll();

$q=$pdo->prepare('SELECT id,name FROM small_groups WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$groups=$q->fetchAll();

$mediaItems=$mediaLibrary->reusableItems();

$priorityLabels=['normal'=>'Normal','important'=>'Importante','urgent'=>'Urgente'];
$audienceLabels=['all'=>'Todos os usuários','members'=>'Membros','leaders'=>'Liderança','department'=>'Departamento','group'=>'Grupo/Célula'];

View::header('Mural de Avisos',$auth,'announcements.php');
View::flash();
?>
<style>
.notice-layout{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(300px,.8fr);gap:16px;align-items:start}
.notice-card{padding:16px;border-left:4px solid #9fb3c8}.notice-card.important{border-left-color:#d39a21}.notice-card.urgent{border-left-color:#c53d3d}.notice-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:7px 0 10px}.notice-meta span{font-size:10px;padding:4px 7px;border-radius:999px;background:#f2f5f8}.notice-body{white-space:pre-line;line-height:1.55}.notice-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.notice-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}.notice-kpis .card{padding:13px}.notice-kpis small{display:block;color:var(--muted);font-size:10px}.notice-kpis strong{font-size:20px}
@media(max-width:950px){.notice-layout{grid-template-columns:1fr}}@media(max-width:640px){.notice-kpis{grid-template-columns:1fr}}
</style>

<section class="module-toolbar">
  <div class="module-title"><h1>Mural de Avisos</h1><p>Publique recados para todos ou segmente por membros, liderança, departamento e grupo/célula.</p></div>
  <div class="actions"><a class="btn btn-light" href="/board.php">Ver como usuário</a><button class="btn btn-primary" type="button" data-open-dialog="announcement-editor">＋ Novo aviso</button></div>
</section>

<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<?php
$activeCount=count(array_filter($rows,fn($r)=>!empty($r['active'])));
$pinnedCount=count(array_filter($rows,fn($r)=>!empty($r['active'])&&!empty($r['pinned'])));
$readTotal=array_sum(array_map(fn($r)=>(int)$r['read_count'],$rows));
?>
<div class="notice-kpis">
  <div class="card"><small>Avisos ativos</small><strong><?=$activeCount?></strong></div>
  <div class="card"><small>Fixados</small><strong><?=$pinnedCount?></strong></div>
  <div class="card"><small>Leituras registradas</small><strong><?=$readTotal?></strong></div>
</div>

<div class="notice-layout">
  <section>
    <?php foreach($rows as $row):?>
      <article class="card notice-card <?=e($row['priority'])?>" style="margin-bottom:12px">
        <div class="page-head">
          <div><h2 style="font-size:16px;margin:0"><?=e($row['title'])?></h2></div>
          <span class="badge <?=$row['active']?'ok':'off'?>"><?=$row['active']?'Ativo':'Arquivado'?></span>
        </div>
        <div class="notice-meta">
          <span><?=e($priorityLabels[$row['priority']]??$row['priority'])?></span>
          <span><?=e($audienceLabels[$row['audience_type']]??$row['audience_type'])?></span>
          <?php if($row['pinned']):?><span>Fixado no topo</span><?php endif?>
          <span><?=(int)$row['read_count']?> leitura(s)</span>
          <?php if($row['starts_at']):?><span>De <?=date('d/m/Y H:i',strtotime($row['starts_at']))?></span><?php endif?>
          <?php if($row['ends_at']):?><span>Até <?=date('d/m/Y H:i',strtotime($row['ends_at']))?></span><?php endif?>
        </div>
        <div class="notice-body"><?=e($row['body'])?></div>
        <div class="notice-actions">
          <a class="btn btn-light" href="/announcements.php?edit=<?=e($row['id'])?>">Editar</a>
          <?php if($row['active']):?><form method="post" onsubmit="return confirm('Arquivar este aviso?')"><?=csrf_field()?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn btn-light">Arquivar</button></form><?php endif?>
        </div>
      </article>
    <?php endforeach?>
    <?php if(!$rows):?><section class="card card-pad"><div class="empty">Nenhum aviso publicado ainda.</div></section><?php endif?>
  </section>

  <aside class="card card-pad">
    <h2 style="font-size:16px">Como usar o Mural</h2>
    <p class="muted">O Mural aparece no mesmo login do membro e da liderança. Cada pessoa vê apenas os avisos destinados ao seu perfil.</p>
    <div class="list">
      <div class="list-row"><strong>Todos</strong><small>Qualquer usuário da igreja</small></div>
      <div class="list-row"><strong>Membros</strong><small>Usuários vinculados a cadastro de membro</small></div>
      <div class="list-row"><strong>Liderança</strong><small>Pastores, administração, tesouraria e líderes</small></div>
      <div class="list-row"><strong>Departamento</strong><small>Participantes e líderes daquele ministério</small></div>
      <div class="list-row"><strong>Grupo/Célula</strong><small>Participantes e líderes daquele grupo</small></div>
    </div>
  </aside>
</div>

<dialog class="app-dialog" id="announcement-editor" aria-labelledby="announcement-title" <?=$edit?'open':''?>>
  <div class="dialog-shell">
    <header class="dialog-heading">
      <div><h2 id="announcement-title"><?=$edit?'Editar aviso':'Novo aviso'?></h2><p>Defina conteúdo, prioridade, público e período de exibição.</p></div>
      <button class="icon-button" type="button" data-close-dialog aria-label="Fechar">×</button>
    </header>
    <div class="dialog-body">
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
        <div class="form-grid">
          <label class="full">Título *<input class="input" name="title" required maxlength="190" value="<?=e($edit['title']??'')?>"></label>
          <label class="full">Mensagem *<textarea class="textarea tall" name="body" required maxlength="12000"><?=e($edit['body']??'')?></textarea></label>
          <label>Prioridade<select class="select" name="priority"><?php foreach($priorityLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($edit['priority']??'normal')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
          <label>Público<select class="select" name="audience_type" id="announcement-audience"><?php foreach($audienceLabels as $key=>$label):?><option value="<?=e($key)?>" <?=($edit['audience_type']??'all')===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>

          <label class="full" id="announcement-department-wrap">Departamento<select class="select" name="department_id"><option value="">Selecione...</option><?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=($edit['audience_type']??'')==='department'&&($edit['target_id']??'')===$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach?></select></label>
          <label class="full" id="announcement-group-wrap">Grupo/Célula<select class="select" name="group_id"><option value="">Selecione...</option><?php foreach($groups as $g):?><option value="<?=e($g['id'])?>" <?=($edit['audience_type']??'')==='group'&&($edit['target_id']??'')===$g['id']?'selected':''?>><?=e($g['name'])?></option><?php endforeach?></select></label>

          <label>Começar a exibir<input class="input" type="datetime-local" name="starts_at" value="<?=e(!empty($edit['starts_at'])?date('Y-m-d\TH:i',strtotime($edit['starts_at'])):'')?>"></label>
          <label>Parar de exibir<input class="input" type="datetime-local" name="ends_at" value="<?=e(!empty($edit['ends_at'])?date('Y-m-d\TH:i',strtotime($edit['ends_at'])):'')?>"></label>
          <label class="full">Link de ação<input class="input" type="url" name="link_url" value="<?=e($edit['link_url']??'')?>" placeholder="https://..."><small class="muted">Ex.: inscrição, formulário, página do evento.</small></label>
          <label class="full">Arquivo da Biblioteca<select class="select" name="media_library_id"><option value="">Nenhum / usar link abaixo</option><?php foreach($mediaItems as $media):?><option value="<?=e($media['id'])?>"><?=e($media['title'])?> · <?=e($media['media_type'])?></option><?php endforeach?></select><small class="muted">Ao selecionar um arquivo, ele substitui o link manual abaixo.</small></label>
          <label class="full">Link de arquivo/cartaz/vídeo<input class="input" type="url" name="attachment_url" value="<?=e($edit['attachment_url']??'')?>" placeholder="https://..."></label>
          <label><input type="checkbox" name="pinned" value="1" <?=!empty($edit['pinned'])?'checked':''?>> Fixar no topo</label>
          <label><input type="checkbox" name="active" value="1" <?=($edit ? (!empty($edit['active'])?'checked':'') : 'checked')?>> Aviso ativo</label>
        </div>
        <button class="btn btn-primary">Salvar aviso</button>
      </form>
    </div>
  </div>
</dialog>

<script>
(function(){
  const audience=document.getElementById('announcement-audience');
  const dep=document.getElementById('announcement-department-wrap');
  const group=document.getElementById('announcement-group-wrap');
  function sync(){
    const value=audience?.value||'all';
    if(dep)dep.hidden=value!=='department';
    if(group)group.hidden=value!=='group';
  }
  audience?.addEventListener('change',sync);sync();
})();
</script>
<?php View::footer(); ?>
