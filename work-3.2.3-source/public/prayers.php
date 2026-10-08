<?php
require __DIR__ . '/bootstrap.php';
$auth->requireAbility('secretary');
$church=$auth->currentChurch(); if(!$church) redirect('/logout.php'); $cid=$church['id'];
$_SESSION['csrf']??=bin2hex(random_bytes(24)); $error=null; $success=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada.');
  $id=(string)($_POST['id']??''); $action=(string)($_POST['action']??'');
  if($action==='status'){
   $status=(string)($_POST['status']??'pending');
   if(!in_array($status,['pending','answered','archived'],true)) $status='pending';
   $q=$pdo->prepare("UPDATE prayer_requests SET status=?, responded_at=IF(?='answered',COALESCE(responded_at,NOW()),responded_at) WHERE id=? AND church_id=?");
   $q->execute([$status,$status,$id,$cid]); $success='Pedido atualizado.';
  }elseif($action==='response'){
   $response=trim((string)($_POST['ai_response']??''));
   $q=$pdo->prepare("UPDATE prayer_requests SET ai_response=?,status='answered',responded_at=NOW() WHERE id=? AND church_id=?");
   $q->execute([$response?:null,$id,$cid]); $success='Resposta registrada.';
  }elseif($action==='new'){
   $name=trim((string)($_POST['name']??''));$phone=trim((string)($_POST['phone']??''));$request=trim((string)($_POST['request']??''));
   if($phone===''||$request==='') throw new InvalidArgumentException('Telefone e pedido são obrigatórios.');
   $q=$pdo->prepare("INSERT INTO prayer_requests(id,church_id,phone,name,request,status) VALUES(?,?,?,?,?,'pending')");$q->execute([app_uuid(),$cid,$phone,$name?:null,$request]);$success='Pedido cadastrado.';
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$status=(string)($_GET['status']??'pending');$allowed=['pending','answered','archived','all'];if(!in_array($status,$allowed,true))$status='pending';
$sql='SELECT p.*,m.name member_name,c.name congregation_name FROM prayer_requests p LEFT JOIN members m ON m.id=p.member_id LEFT JOIN congregations c ON c.id=p.congregation_id WHERE p.church_id=?';$params=[$cid];if($status!=='all'){$sql.=' AND p.status=?';$params[]=$status;}$sql.=' ORDER BY p.created_at DESC LIMIT 500';$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
View::header('Pedidos de Oração',$auth,'prayers.php');
?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?>
<div class="card card-pad" style="margin-bottom:16px"><form method="get" class="toolbar"><select class="select" name="status" style="width:auto"><option value="pending" <?=$status==='pending'?'selected':''?>>Pendentes</option><option value="answered" <?=$status==='answered'?'selected':''?>>Respondidos</option><option value="archived" <?=$status==='archived'?'selected':''?>>Arquivados</option><option value="all" <?=$status==='all'?'selected':''?>>Todos</option></select><button class="btn btn-light">Filtrar</button></form></div>
<div class="grid two-col"><section class="card card-pad"><div class="page-head"><div><h1 style="font-size:16px">Pedidos</h1><p><?=count($rows)?> resultado(s)</p></div></div><?php if(!$rows):?><div class="empty">Nenhum pedido encontrado.</div><?php else:?><div class="list"><?php foreach($rows as $r):?><article class="list-row" style="align-items:flex-start"><div style="min-width:0"><strong><?=e($r['name']?:$r['member_name']?:'Sem nome')?></strong><div class="muted" style="font-size:11px;margin:3px 0 8px"><?=e($r['phone'])?> · <?=date('d/m/Y H:i',strtotime($r['created_at']))?></div><div style="font-size:13px;white-space:normal"><?=nl2br(e($r['request']))?></div><?php if($r['ai_response']):?><div class="alert alert-success" style="margin-top:10px;margin-bottom:0"><?=nl2br(e($r['ai_response']))?></div><?php endif;?></div><div><span class="badge <?=$r['status']==='answered'?'ok':''?>"><?=e($r['status'])?></span><div style="margin-top:8px"><a class="btn btn-light" style="padding:6px 8px" href="?status=<?=$status?>&edit=<?=e($r['id'])?>#resposta">Abrir</a></div></div></article><?php endforeach;?></div><?php endif;?></section>
<section class="card card-pad" id="resposta"><?php $selected=null;if(!empty($_GET['edit'])){foreach($rows as $r){if($r['id']===$_GET['edit']){$selected=$r;break;}}}?><?php if($selected):?><div class="page-head"><div><h1 style="font-size:16px">Responder pedido</h1><p><?=e($selected['name']?:'Sem nome')?> · <?=e($selected['phone'])?></p></div></div><div class="alert" style="background:#f7f8fb;border:1px solid var(--line)"><?=nl2br(e($selected['request']))?></div><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($selected['id'])?>"><input type="hidden" name="action" value="response"><div class="field"><label>Resposta / acompanhamento</label><textarea class="textarea" name="ai_response"><?=e($selected['ai_response']??'')?></textarea></div><button class="btn btn-primary" style="width:100%;margin-top:10px">Salvar como respondido</button></form><form method="post" style="display:flex;gap:8px;margin-top:8px"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($selected['id'])?>"><input type="hidden" name="action" value="status"><button class="btn btn-light" name="status" value="pending">Pendente</button><button class="btn btn-light" name="status" value="archived">Arquivar</button></form><?php else:?><div class="page-head"><div><h1 style="font-size:16px">Novo pedido</h1><p>Cadastro manual</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="new"><div class="field"><label>Nome</label><input class="input" name="name"></div><div class="field" style="margin-top:10px"><label>Telefone *</label><input class="input" name="phone" required></div><div class="field" style="margin-top:10px"><label>Pedido *</label><textarea class="textarea" name="request" required></textarea></div><button class="btn btn-primary" style="width:100%;margin-top:10px">Cadastrar pedido</button></form><?php endif;?></section></div>
<?php View::footer();?>