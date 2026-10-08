<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/EngagementNotificationService.php';

$user=$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church)exit('Igreja não selecionada.');
$churchId=(string)$church['id'];
$groupService=new GroupBroadcastService($pdo);
$uploader=new UploadService(__DIR__);
$notifier=new EngagementNotificationService($pdo,__DIR__);

if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    verify_csrf();
    $action=$_POST['action']??'create';

    if($action==='create'){
      $targetGroupIds=$groupService->idsFromPost($_POST['target_group_ids']??[]);
      $title=trim($_POST['title']??'');
      $total=(int)($_POST['total_numbers']??0);
      $price=(float)($_POST['price']??0);
      if($title===''||$total<1||$price<0)throw new RuntimeException('Informe título, quantidade e valor.');

      $raffleId=app_uuid();
      $congregationId=$_POST['congregation_id']?:null;
      $pix=trim($_POST['pix_key']??'')?:null;
      $drawDate=$_POST['draw_date']?:null;
      $description=trim($_POST['description']??'')?:null;
      $prize=trim($_POST['prize_description']??'')?:null;
      $image=$uploader->notificationImage($_FILES['notification_image']??[],$churchId);

      $q=$pdo->prepare('INSERT INTO raffles(id,church_id,congregation_id,title,description,prize_description,image_url,total_numbers,price,pix_key,draw_date,status,target_group_ids,notification_image_path) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $q->execute([$raffleId,$churchId,$congregationId,$title,$description,$prize,null,$total,$price,$pix,$drawDate,'active',json_encode($targetGroupIds,JSON_UNESCAPED_UNICODE),$image['path']??null]);

      $q=$pdo->prepare('SELECT * FROM raffles WHERE id=? AND church_id=?');
      $q->execute([$raffleId,$churchId]);$raffle=$q->fetch();

      try{
        $result=$notifier->sendRaffle($church,$raffle,false);
        if((int)$result['sent']>0)$pdo->prepare('UPDATE raffles SET announcement_sent_at=NOW(),last_notification_sent_at=NOW() WHERE id=?')->execute([$raffleId]);
        flash('success','Rifa criada.'.((int)$result['sent']>0?' Divulgação enviada para '.$result['sent'].' grupo(s).':''));
      }catch(Throwable $e){
        flash('warning','Rifa criada, mas a divulgação automática não foi enviada: '.$e->getMessage());
      }
      redirect('/raffles.php');
    }

    if($action==='resend'){
      $id=(string)($_POST['id']??'');
      $q=$pdo->prepare('SELECT * FROM raffles WHERE id=? AND church_id=? AND status="active" LIMIT 1');
      $q->execute([$id,$churchId]);$raffle=$q->fetch();
      if(!$raffle)throw new RuntimeException('Rifa ativa não encontrada.');
      $result=$notifier->sendRaffle($church,$raffle,true);
      if((int)$result['sent']<1)throw new RuntimeException('Nenhum grupo recebeu o lembrete.');
      $pdo->prepare('UPDATE raffles SET last_notification_sent_at=NOW(),reminder_sent_at=NOW() WHERE id=? AND church_id=?')->execute([$id,$churchId]);
      flash('success','Lembrete da rifa reenviado para '.$result['sent'].' grupo(s).');
      redirect('/raffles.php');
    }

    if($action==='reserve'){
      $raffleId=$_POST['raffle_id']??'';$number=(int)($_POST['number']??0);$name=trim($_POST['buyer_name']??'');$phone=trim($_POST['buyer_phone']??'');
      $r=$pdo->prepare('SELECT total_numbers FROM raffles WHERE id=? AND church_id=? AND status="active"');$r->execute([$raffleId,$churchId]);$raffle=$r->fetch();
      if(!$raffle||$number<1||$number>(int)$raffle['total_numbers']||$name===''||$phone==='')throw new RuntimeException('Dados da reserva inválidos.');
      try{
        $q=$pdo->prepare('INSERT INTO raffle_numbers(id,raffle_id,church_id,number,buyer_name,buyer_phone,paid) VALUES(?,?,?,?,?,?,0)');
        $q->execute([app_uuid(),$raffleId,$churchId,$number,$name,$phone]);
        flash('success','Número reservado.');
      }catch(Throwable){throw new RuntimeException('Número já reservado.');}
      redirect('/raffles.php');
    }

    if($action==='paid'){
      $q=$pdo->prepare('UPDATE raffle_numbers SET paid=? WHERE id=? AND church_id=?');
      $q->execute([(int)($_POST['paid']??0),$_POST['id']??'',$churchId]);
      flash('success','Pagamento atualizado.');
      redirect('/raffles.php');
    }

    if($action==='draw'){
      $rid=$_POST['raffle_id']??'';
      $q=$pdo->prepare('SELECT number FROM raffle_numbers WHERE raffle_id=? AND church_id=? AND paid=1 ORDER BY RAND() LIMIT 1');
      $q->execute([$rid,$churchId]);$winner=$q->fetchColumn();
      if(!$winner)throw new RuntimeException('Não há números pagos para sortear.');
      $pdo->prepare('UPDATE raffles SET winner_number=?,drawn_at=NOW(),status="drawn" WHERE id=? AND church_id=?')->execute([(int)$winner,$rid,$churchId]);

      try{
        $rq=$pdo->prepare('SELECT * FROM raffles WHERE id=? AND church_id=?');$rq->execute([$rid,$churchId]);$raffle=$rq->fetch();
        if($raffle){
          $groupIds=json_decode((string)($raffle['target_group_ids']??'[]'),true);if(!is_array($groupIds))$groupIds=[];
          $msg="🏆 *RESULTADO DA RIFA*

*".$raffle['title']."*
🎉 Número vencedor: *".$winner."*";
          $res=$groupIds?$groupService->sendIds($church,$groupIds,$msg):$groupService->sendPurpose($church,'raffles',$msg,$raffle['congregation_id']??null);
          flash('success','Sorteio realizado. Número vencedor: '.$winner.((int)$res['sent']>0?' Resultado enviado para '.$res['sent'].' grupo(s).':''));
        }
      }catch(Throwable $e){
        flash('warning','Sorteio realizado, mas não foi possível anunciar o resultado: '.$e->getMessage());
      }
      redirect('/raffles.php');
    }
  }catch(Throwable $e){
    flash('error',$e->getMessage());
    redirect('/raffles.php');
  }
}

$congs=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY name');
$congs->execute([$churchId]);$congs=$congs->fetchAll();
$groups=$groupService->list($churchId,null,null,true);

$q=$pdo->prepare('SELECT r.*,c.name congregation_name,(SELECT COUNT(*) FROM raffle_numbers n WHERE n.raffle_id=r.id) reserved,(SELECT COUNT(*) FROM raffle_numbers n WHERE n.raffle_id=r.id AND n.paid=1) paid_count FROM raffles r LEFT JOIN congregations c ON c.id=r.congregation_id WHERE r.church_id=? ORDER BY r.created_at DESC');
$q->execute([$churchId]);$raffles=$q->fetchAll();

$nums=$pdo->prepare('SELECT n.*,r.title FROM raffle_numbers n INNER JOIN raffles r ON r.id=n.raffle_id WHERE n.church_id=? ORDER BY r.created_at DESC,n.number');
$nums->execute([$churchId]);$nums=$nums->fetchAll();

View::header('Rifas','raffles',$auth,$church);
?>
<div class="module-hero"><div><span class="eyebrow">Campanhas e arrecadação</span><h1>Rifas</h1><p>Crie a rifa, anexe o cartaz, explique como escolher o número, acompanhe reservas e reenvie lembretes aos grupos.</p></div><div class="hero-stat"><strong><?=count($raffles)?></strong><span>rifas cadastradas</span></div></div>
<?php View::flash(); ?>

<div class="grid-2">
<section class="card form-card">
  <div class="section-title"><span>🎟</span><div><h2>Nova rifa</h2><p>Dados da campanha, prêmio, cartaz e divulgação.</p></div></div>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <label class="full">Título<input name="title" required></label>
    <label>Congregação<select name="congregation_id"><option value="">Matriz/Todas</option><?php foreach($congs as $c):?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach?></select></label>
    <label>Total de números<input type="number" min="1" name="total_numbers" required></label>
    <label>Valor por número<input type="number" step="0.01" min="0" name="price" required></label>
    <label>Chave PIX<input name="pix_key"></label>
    <label>Data do sorteio<input type="datetime-local" name="draw_date"></label>
    <label class="full">Prêmio<textarea name="prize_description"></textarea></label>
    <label class="full">Descrição<textarea name="description"></textarea></label>
    <label class="full">Cartaz / folder
      <input type="file" name="notification_image" accept="image/jpeg,image/png,image/webp">
      <small class="help-text">Será enviado junto com a divulgação e com o botão Reenviar aviso.</small>
    </label>
    <label class="full">Grupos para divulgação e lembretes
      <div class="target-grid">
        <?php if(!$groups):?><div class="target-empty">Nenhum grupo cadastrado. <a href="/groups.php">Cadastrar grupos</a></div><?php endif?>
        <?php foreach($groups as $g):?><label class="target-option"><input type="checkbox" name="target_group_ids[]" value="<?=e($g['id'])?>"><span><strong><?=e($g['name'])?></strong><small><?=e($g['congregation_name']?:'Igreja geral / Matriz')?></small></span></label><?php endforeach?>
      </div>
      <small class="help-text">A mensagem explica: envie “quero 25” para reservar o número 25.</small>
    </label>
    <div class="full"><button class="btn primary">Criar e divulgar rifa</button></div>
  </form>
</section>

<section class="card form-card">
  <div class="section-title"><span>✍</span><div><h2>Reserva manual</h2><p>Inclua uma reserva feita pela secretaria.</p></div></div>
  <form method="post" class="form-grid">
    <?=csrf_field()?><input type="hidden" name="action" value="reserve">
    <label class="full">Rifa<select name="raffle_id" required><option value="">Selecione</option><?php foreach($raffles as $r):if($r['status']!=='active')continue;?><option value="<?=e($r['id'])?>"><?=e($r['title'])?></option><?php endforeach?></select></label>
    <label>Número<input type="number" min="1" name="number" required></label><label>Nome<input name="buyer_name" required></label><label>Telefone<input name="buyer_phone" required></label>
    <div class="full"><button class="btn primary">Reservar</button></div>
  </form>
</section>
</div>

<section class="card"><h2>Rifas cadastradas</h2><div class="table-wrap"><table><thead><tr><th>Rifa</th><th>Valor</th><th>Reservados</th><th>Pagos</th><th>Status</th><th>Vencedor</th><th>Último aviso</th><th>Ações</th></tr></thead><tbody>
<?php foreach($raffles as $r): ?>
<tr>
  <td><strong><?=e($r['title'])?></strong><?php if(!empty($r['notification_image_path'])):?><div class="muted">📎 Com cartaz</div><?php endif?></td>
  <td>R$ <?=number_format((float)$r['price'],2,',','.')?></td>
  <td><?=e((string)$r['reserved'])?>/<?=e((string)$r['total_numbers'])?></td>
  <td><?=e((string)$r['paid_count'])?></td>
  <td><?=e($r['status'])?></td>
  <td><?=e($r['winner_number']?(string)$r['winner_number']:'—')?></td>
  <td><?=!empty($r['last_notification_sent_at'])?e(date('d/m/Y H:i',strtotime($r['last_notification_sent_at']))):'—'?></td>
  <td style="white-space:nowrap">
    <a class="btn small" target="_blank" href="/report.php?type=raffle&id=<?=e($r['id'])?>&format=print">Imprimir</a>
    <a class="btn small" href="/report.php?type=raffle&id=<?=e($r['id'])?>&format=pdf">PDF</a>
    <?php if($r['status']==='active'):?>
      <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn small">Reenviar aviso</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('Realizar sorteio entre os números pagos?')"><?=csrf_field()?><input type="hidden" name="action" value="draw"><input type="hidden" name="raffle_id" value="<?=e($r['id'])?>"><button class="btn small">Sortear</button></form>
    <?php endif?>
  </td>
</tr>
<?php endforeach?>
</tbody></table></div></section>

<section class="card"><h2>Números reservados</h2><div class="table-wrap"><table><thead><tr><th>Rifa</th><th>Número</th><th>Comprador</th><th>Telefone</th><th>Pago</th><th></th></tr></thead><tbody><?php foreach($nums as $n): ?><tr><td><?=e($n['title'])?></td><td><?=e((string)$n['number'])?></td><td><?=e($n['buyer_name'])?></td><td><?=e($n['buyer_phone'])?></td><td><?=((int)$n['paid']===1?'Sim':'Não')?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?=e($n['id'])?>"><input type="hidden" name="paid" value="<?=((int)$n['paid']===1?0:1)?>"><button class="btn small"><?=((int)$n['paid']===1?'Desmarcar':'Marcar pago')?></button></form></td></tr><?php endforeach?></tbody></table></div></section>
<?php View::footer(); ?>
