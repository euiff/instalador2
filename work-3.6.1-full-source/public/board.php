<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AnnouncementService.php';

$user=$auth->requireAbility('member_board');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$service=new AnnouncementService($pdo,$auth,(string)$church['id']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $service->markRead((string)($_POST['id']??''));
        flash('success','Aviso marcado como lido.');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
    }
    redirect('/board.php');
}

$rows=$service->visibleForCurrentUser();
$unread=count(array_filter($rows,fn($r)=>empty($r['is_read'])));
$priorityLabels=['normal'=>'Aviso','important'=>'Importante','urgent'=>'Urgente'];

View::header('Mural',$auth,'board.php');
View::flash();
?>
<style>
.member-board-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.member-board-head h1{margin:0 0 5px}.member-board-head p{margin:0;color:var(--muted)}
.member-notice{padding:17px;border-left:4px solid #a8b8c8;margin-bottom:12px}.member-notice.important{border-left-color:#d39a21}.member-notice.urgent{border-left-color:#c53d3d}.member-notice.unread{box-shadow:0 0 0 1px rgba(31,97,160,.18);background:#fbfdff}.member-notice-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.member-notice-top h2{margin:0;font-size:17px}.member-notice-meta{display:flex;gap:7px;flex-wrap:wrap;margin:8px 0}.member-notice-meta span{font-size:10px;padding:4px 7px;border-radius:999px;background:#eef3f7}.member-notice-body{white-space:pre-line;line-height:1.6}.member-notice-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
</style>

<div class="member-board-head">
  <div><h1>Mural da Igreja</h1><p>Avisos, orientações e comunicados destinados ao seu acesso.</p></div>
  <span class="badge <?=$unread?'off':'ok'?>"><?=$unread?> não lido(s)</span>
</div>

<?php foreach($rows as $row):?>
  <article class="card member-notice <?=e($row['priority'])?> <?=empty($row['is_read'])?'unread':''?>">
    <div class="member-notice-top">
      <div><h2><?=e($row['title'])?></h2></div>
      <?php if($row['pinned']):?><span class="badge ok">Fixado</span><?php endif?>
    </div>
    <div class="member-notice-meta">
      <span><?=e($priorityLabels[$row['priority']]??'Aviso')?></span>
      <span><?=date('d/m/Y H:i',strtotime($row['starts_at']?:$row['created_at']))?></span>
      <?php if(!empty($row['is_read'])):?><span>Lido</span><?php endif?>
    </div>
    <div class="member-notice-body"><?=e($row['body'])?></div>
    <div class="member-notice-actions">
      <?php if($row['link_url']):?><a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="<?=e($row['link_url'])?>">Abrir link</a><?php endif?>
      <?php if($row['attachment_url']):?><a class="btn btn-light" target="_blank" rel="noopener noreferrer" href="<?=e($row['attachment_url'])?>">Ver arquivo/cartaz</a><?php endif?>
      <?php if(empty($row['is_read'])):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn btn-light">Marcar como lido</button></form><?php endif?>
    </div>
  </article>
<?php endforeach?>

<?php if(!$rows):?><section class="card card-pad empty-state"><h2>Nenhum aviso no momento</h2><p>Quando a igreja publicar algo destinado a você, aparecerá aqui.</p></section><?php endif?>

<?php View::footer(); ?>
