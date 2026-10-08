<?php
require __DIR__.'/bootstrap.php';
$user=$auth->requireAbility('secretary'); $church=$auth->currentChurch(); if(!$church) exit('Igreja não selecionada.');
$churchId=$church['id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action']??'create';
  if($action==='delete'){
    $q=$pdo->prepare('DELETE er FROM event_registrations er INNER JOIN events e ON e.id=er.event_id WHERE er.id=? AND er.church_id=?');
    $q->execute([$_POST['id']??'',$churchId]);
    flash('success','Inscrição removida.'); redirect('/event-registrations.php');
  }
  $eventId=$_POST['event_id']??''; $name=trim($_POST['name']??''); $phone=trim($_POST['phone']??'');
  if($eventId===''||$name===''||$phone===''){ flash('error','Informe evento, nome e telefone.'); redirect('/event-registrations.php'); }
  $q=$pdo->prepare('SELECT 1 FROM events WHERE id=? AND church_id=?'); $q->execute([$eventId,$churchId]); if(!$q->fetchColumn()){ flash('error','Evento inválido.'); redirect('/event-registrations.php'); }
  $id=app_uuid();
  $s=$pdo->prepare('INSERT INTO event_registrations(id,church_id,event_id,name,phone,email,birth_date,cpf,rg,cep,address_street,address_number,address_neighborhood,address_city,address_state,guests_count,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $s->execute([$id,$churchId,$eventId,$name,$phone,trim($_POST['email']??'')?:null,$_POST['birth_date']?:null,trim($_POST['cpf']??'')?:null,trim($_POST['rg']??'')?:null,trim($_POST['cep']??'')?:null,trim($_POST['address_street']??'')?:null,trim($_POST['address_number']??'')?:null,trim($_POST['address_neighborhood']??'')?:null,trim($_POST['address_city']??'')?:null,trim($_POST['address_state']??'')?:null,(int)($_POST['guests_count']??0),trim($_POST['notes']??'')?:null]);
  flash('success','Inscrição cadastrada.'); redirect('/event-registrations.php');
}
$events=$pdo->prepare('SELECT id,title,event_date FROM events WHERE church_id=? AND active=1 ORDER BY event_date DESC'); $events->execute([$churchId]); $events=$events->fetchAll();
$filter=$_GET['event_id']??''; $params=[$churchId]; $where='er.church_id=?'; if($filter!==''){ $where.=' AND er.event_id=?'; $params[]=$filter; }
$q=$pdo->prepare("SELECT er.*,e.title event_title,e.event_date FROM event_registrations er INNER JOIN events e ON e.id=er.event_id WHERE $where ORDER BY er.created_at DESC"); $q->execute($params); $rows=$q->fetchAll();
View::header('Inscrições de Eventos','event-registrations',$auth,$church);
?>
<div class="page-head"><div><h1>Inscrições de Eventos</h1><p>Gerencie os participantes dos eventos da igreja.</p></div></div>
<?php View::flash(); ?>
<div class="grid-2">
<section class="card"><h2>Nova inscrição</h2><form method="post" class="form-grid"><?=csrf_field()?>
<label>Evento<select name="event_id" required><option value="">Selecione</option><?php foreach($events as $e): ?><option value="<?=e($e['id'])?>"><?=e($e['title'])?> — <?=e(date('d/m/Y H:i',strtotime($e['event_date'])))?></option><?php endforeach?></select></label>
<label>Nome<input name="name" required></label><label>Telefone<input name="phone" required></label><label>E-mail<input name="email" type="email"></label><label>Nascimento<input name="birth_date" type="date"></label><label>CPF<input name="cpf"></label><label>RG<input name="rg"></label><label>CEP<input name="cep"></label><label>Rua<input name="address_street"></label><label>Número<input name="address_number"></label><label>Bairro<input name="address_neighborhood"></label><label>Cidade<input name="address_city"></label><label>UF<input name="address_state"></label><label>Convidados<input name="guests_count" type="number" min="0" value="0"></label><label class="full">Observações<textarea name="notes"></textarea></label><div class="full"><button class="btn primary">Salvar inscrição</button></div></form></section>
<section class="card">
<div class="page-head" style="padding:14px 14px 0">
  <div><h2 style="font-size:15px;margin:0">Lista de participantes</h2><p><?=count($rows)?> inscrição(ões)</p></div>
  <?php if($filter!==''):?><div class="actions"><a class="btn btn-light" target="_blank" href="/report.php?type=event&id=<?=e($filter)?>&format=print">🖨️ Imprimir</a><a class="btn btn-primary" href="/report.php?type=event&id=<?=e($filter)?>&format=pdf">PDF</a></div><?php endif?>
</div>
<form method="get" class="inline-form"><select name="event_id"><option value="">Todos os eventos</option><?php foreach($events as $e): ?><option value="<?=e($e['id'])?>" <?=$filter===$e['id']?'selected':''?>><?=e($e['title'])?></option><?php endforeach?></select><button class="btn">Filtrar</button></form><div class="table-wrap"><table><thead><tr><th>Participante</th><th>Evento</th><th>Telefone</th><th>Convidados</th><th></th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e($r['name'])?></td><td><?=e($r['event_title'])?></td><td><?=e($r['phone'])?></td><td><?=e((string)$r['guests_count'])?></td><td><form method="post" onsubmit="return confirm('Remover inscrição?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn danger small">Excluir</button></form></td></tr><?php endforeach?><tr><td colspan="5"><strong><?=count($rows)?> inscrição(ões)</strong></td></tr></tbody></table></div></section>
</div>
<?php View::footer(); ?>