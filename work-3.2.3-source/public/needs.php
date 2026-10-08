<?php
require __DIR__.'/bootstrap.php';
$auth->requireAbility('needs');$church=$auth->currentChurch();if(!$church)redirect('/logout.php');$cid=$church['id'];
$error=flash('error');$success=flash('success');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  verify_csrf();$action=(string)($_POST['action']??'save');
  if($action==='status'){
   $id=(string)($_POST['id']??'');$status=(string)($_POST['status']??'requested');
   if(!in_array($status,['requested','approved','purchased','cancelled'],true))throw new RuntimeException('Status inválido.');
   $pdo->prepare('UPDATE church_needs SET status=? WHERE id=? AND church_id=?')->execute([$status,$id,$cid]);
   flash('success','Necessidade atualizada.');redirect('/needs.php');
  }
  $title=trim((string)($_POST['title']??''));$desc=trim((string)($_POST['description']??''))?:null;
  $qty=max(0.01,(float)str_replace(',','.',(string)($_POST['quantity']??'1')));
  $amount=(float)str_replace(',','.',str_replace('.','',(string)($_POST['estimated_amount']??'0')));
  $priority=(string)($_POST['priority']??'normal');
  if($title==='')throw new RuntimeException('Informe a necessidade.');
  if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';
  $pdo->prepare("INSERT INTO church_needs(id,church_id,title,description,quantity,estimated_amount,priority,status) VALUES(?,?,?,?,?,?,?,'requested')")
      ->execute([app_uuid(),$cid,$title,$desc,$qty,$amount,$priority]);
  flash('success','Necessidade cadastrada.');redirect('/needs.php');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$q=$pdo->prepare("SELECT * FROM church_needs WHERE church_id=? ORDER BY FIELD(status,'requested','approved','purchased','cancelled'),FIELD(priority,'urgent','high','normal','low'),created_at DESC");$q->execute([$cid]);$rows=$q->fetchAll();
View::header('Necessidades',$auth,'needs.php');?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?><?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<div class="grid two-col"><section class="card card-pad"><div class="page-head"><div><h1 style="font-size:16px">Necessidades da igreja</h1><p>Pedidos, compras e prioridades para acompanhamento e transparência.</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Prioridade</th><th>Estimativa</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['title'])?></strong><div class="muted"><?=e($r['description']?:'')?></div></td><td><?=e(ucfirst($r['priority']))?></td><td>R$ <?=number_format((float)$r['estimated_amount'],2,',','.')?></td><td><span class="badge <?=$r['status']==='purchased'?'ok':''?>"><?=e($r['status'])?></span></td><td><form method="post" style="display:flex;gap:5px;flex-wrap:wrap"><?=csrf_field()?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=e($r['id'])?>"><?php foreach(['approved'=>'Aprovar','purchased'=>'Comprado','cancelled'=>'Cancelar'] as $v=>$l):?><button class="btn btn-light" name="status" value="<?=$v?>" style="padding:6px 8px"><?=$l?></button><?php endforeach?></form></td></tr><?php endforeach?></tbody></table></div></section>
<section class="card card-pad"><h2 style="font-size:16px">Nova necessidade</h2><form method="post"><?=csrf_field()?><div class="form-grid"><div class="field full"><label>Item / necessidade *</label><input class="input" name="title" required></div><div class="field full"><label>Descrição</label><textarea class="textarea" name="description"></textarea></div><div class="field"><label>Quantidade</label><input class="input" name="quantity" value="1"></div><div class="field"><label>Valor estimado</label><input class="input" name="estimated_amount" placeholder="0,00"></div><div class="field full"><label>Prioridade</label><select class="select" name="priority"><option value="normal">Normal</option><option value="high">Alta</option><option value="urgent">Urgente</option><option value="low">Baixa</option></select></div></div><button class="btn btn-primary" style="width:100%;margin-top:12px">Cadastrar necessidade</button></form></section></div>
<?php View::footer();?>