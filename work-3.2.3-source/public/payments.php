<?php
require __DIR__ . '/bootstrap.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch(); if(!$church) redirect('/logout.php'); $cid=$church['id'];
$_SESSION['csrf']??=bin2hex(random_bytes(24)); $error=null; $success=null;
function finance_category_from_pix(string $type): string {
 return match($type){'dizimo'=>'Dízimos','oferta'=>'Ofertas','campanha'=>'Campanhas','evento'=>'Eventos',default=>'Outras receitas'};
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada.');
  $action=(string)($_POST['action']??'');$id=(string)($_POST['id']??'');
  if($action==='status'){
   $status=(string)($_POST['status']??'pending');if(!in_array($status,['pending','confirmed','failed','cancelled'],true))$status='pending';
   $pdo->beginTransaction();
   try{
    $q=$pdo->prepare("UPDATE pix_payments SET status=?, paid_at=IF(?='confirmed',COALESCE(paid_at,NOW()),paid_at) WHERE id=? AND church_id=?");$q->execute([$status,$status,$id,$cid]);
    $p=$pdo->prepare('SELECT * FROM pix_payments WHERE id=? AND church_id=? LIMIT 1');$p->execute([$id,$cid]);$payment=$p->fetch();
    if($payment){
     if($status==='confirmed'){
      $category=finance_category_from_pix((string)$payment['payment_type']);
      $f=$pdo->prepare("INSERT INTO finance_entries(id,church_id,member_id,direction,income_kind,category,description,amount,transaction_date,status,source,pix_payment_id,created_at)
       VALUES(?,?,?,'income',?,?,?,?,?,'posted','pix',?,?)
       ON DUPLICATE KEY UPDATE member_id=VALUES(member_id),income_kind=VALUES(income_kind),category=VALUES(category),description=VALUES(description),amount=VALUES(amount),transaction_date=VALUES(transaction_date),status='posted'");
      $f->execute([app_uuid(),$cid,$payment['member_id']??null,$payment['payment_type'],$category,'PIX - '.(($payment['name']??'')?:$payment['phone']),$payment['amount'],date('Y-m-d',strtotime($payment['paid_at']?:$payment['created_at'])),$payment['id'],$payment['created_at']]);
     }else{
      $pdo->prepare("UPDATE finance_entries SET status='cancelled' WHERE church_id=? AND pix_payment_id=?")->execute([$cid,$id]);
     }
    }
    $pdo->commit();$success='Pagamento e livro financeiro atualizados.';
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }elseif($action==='new'){
   $name=trim((string)($_POST['name']??''));$phone=trim((string)($_POST['phone']??''));$amount=(float)str_replace(',','.',(string)($_POST['amount']??'0'));$type=trim((string)($_POST['payment_type']??'oferta'))?:'oferta';
   if($phone===''||$amount<=0) throw new InvalidArgumentException('Telefone e valor válido são obrigatórios.');
   $q=$pdo->prepare("INSERT INTO pix_payments(id,church_id,phone,name,amount,payment_type,reference_id,status) VALUES(?,?,?,?,?,?,?,'pending')");$q->execute([app_uuid(),$cid,$phone,$name?:null,$amount,$type,'manual-'.bin2hex(random_bytes(6))]);$success='Pagamento lançado como pendente.';
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$status=(string)($_GET['status']??'all');if(!in_array($status,['all','pending','confirmed','failed','cancelled'],true))$status='all';
$from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));
$sql='SELECT p.*,m.name member_name,c.name congregation_name FROM pix_payments p LEFT JOIN members m ON m.id=p.member_id LEFT JOIN congregations c ON c.id=p.congregation_id WHERE p.church_id=? AND DATE(p.created_at) BETWEEN ? AND ?';$params=[$cid,$from,$to];if($status!=='all'){$sql.=' AND p.status=?';$params[]=$status;}$sql.=' ORDER BY p.created_at DESC LIMIT 1000';$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
$total=0;$confirmed=0;$pending=0;foreach($rows as $r){$total+=(float)$r['amount'];if($r['status']==='confirmed')$confirmed+=(float)$r['amount'];if($r['status']==='pending')$pending+=(float)$r['amount'];}
View::header('Pagamentos PIX',$auth,'payments.php');
?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?>
<div class="grid stats"><div class="card stat"><div class="stat-label">Movimentado no filtro</div><div class="stat-value">R$ <?=number_format($total,2,',','.')?></div></div><div class="card stat"><div class="stat-label">Confirmado</div><div class="stat-value">R$ <?=number_format($confirmed,2,',','.')?></div></div><div class="card stat"><div class="stat-label">Pendente</div><div class="stat-value">R$ <?=number_format($pending,2,',','.')?></div></div><div class="card stat"><div class="stat-label">Registros</div><div class="stat-value"><?=count($rows)?></div></div></div>
<div class="grid two-col"><section class="card card-pad"><form method="get" class="toolbar"><input class="input" type="date" name="from" value="<?=e($from)?>" style="width:auto"><input class="input" type="date" name="to" value="<?=e($to)?>" style="width:auto"><select class="select" name="status" style="width:auto"><option value="all" <?=$status==='all'?'selected':''?>>Todos</option><option value="pending" <?=$status==='pending'?'selected':''?>>Pendentes</option><option value="confirmed" <?=$status==='confirmed'?'selected':''?>>Confirmados</option><option value="failed" <?=$status==='failed'?'selected':''?>>Falhos</option><option value="cancelled" <?=$status==='cancelled'?'selected':''?>>Cancelados</option></select><button class="btn btn-light">Filtrar</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Nome</th><th>Tipo</th><th>Valor</th><th>Status</th><th></th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6"><div class="empty">Nenhum pagamento encontrado.</div></td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td><?=date('d/m/Y H:i',strtotime($r['created_at']))?></td><td><strong><?=e($r['name']?:$r['member_name']?:'Sem nome')?></strong><div class="muted" style="font-size:10px"><?=e($r['phone'])?></div></td><td><?=e(ucfirst($r['payment_type']))?></td><td><strong>R$ <?=number_format((float)$r['amount'],2,',','.')?></strong></td><td><span class="badge <?=$r['status']==='confirmed'?'ok':''?>"><?=e($r['status'])?></span></td><td><form method="post" style="display:flex;gap:5px"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="action" value="status"><?php if($r['status']!=='confirmed'):?><button class="btn btn-light" style="padding:6px 8px" name="status" value="confirmed">Confirmar</button><?php endif;?><?php if($r['status']!=='cancelled'):?><button class="btn btn-light" style="padding:6px 8px" name="status" value="cancelled">Cancelar</button><?php endif;?></form></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card card-pad"><div class="page-head"><div><h1 style="font-size:16px">Lançamento manual</h1><p>Para registros recebidos fora do fluxo automático</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="new"><div class="field"><label>Nome</label><input class="input" name="name"></div><div class="field" style="margin-top:10px"><label>Telefone *</label><input class="input" name="phone" required></div><div class="field" style="margin-top:10px"><label>Valor *</label><input class="input" name="amount" inputmode="decimal" placeholder="0,00" required></div><div class="field" style="margin-top:10px"><label>Tipo</label><select class="select" name="payment_type"><option value="oferta">Oferta</option><option value="dizimo">Dízimo</option><option value="campanha">Campanha</option><option value="evento">Evento</option><option value="outro">Outro</option></select></div><button class="btn btn-primary" style="width:100%;margin-top:12px">Registrar pagamento</button></form></section></div>
<?php View::footer();?>