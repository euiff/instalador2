<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/EventRegistrationService.php';

$user=$auth->requireAbility('secretary');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$churchId=(string)$church['id'];
$service=new EventRegistrationService($pdo,$churchId);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='status'){
            $service->updateStatus(
                (string)($_POST['id']??''),
                (string)($_POST['registration_status']??'pending'),
                (string)($_POST['payment_status']??'unpaid'),
                max(0,(float)str_replace(',','.',(string)($_POST['amount_paid']??'0'))),
                trim((string)($_POST['payment_reference']??''))?:null
            );
            flash('success','Inscrição atualizada.');
            $eventId=(string)($_POST['event_id']??'');
            redirect('/event-registrations.php'.($eventId!==''?'?event_id='.rawurlencode($eventId):''));
        }

        if($action==='create'){
            $eventId=(string)($_POST['event_id']??'');
            $event=$service->event($eventId);
            $methods=json_decode((string)($event['registration_payment_methods']??'[]'),true);
            if(!is_array($methods))$methods=[];
            $method=(float)($event['registration_fee']??0)>0
                ?(in_array('local',$methods,true)?'local':($methods[0]??'local'))
                :'free';

            $result=EventRegistrationService::registerPublic($pdo,$event,[
                'name'=>$_POST['name']??'',
                'phone'=>$_POST['phone']??'',
                'email'=>$_POST['email']??'',
                'birth_date'=>$_POST['birth_date']??'',
                'cpf'=>$_POST['cpf']??'',
                'guests_count'=>$_POST['guests_count']??0,
                'payment_method'=>$method,
                'notes'=>$_POST['notes']??'',
                'cep'=>$_POST['cep']??'',
                'address_street'=>$_POST['address_street']??'',
                'address_number'=>$_POST['address_number']??'',
                'address_neighborhood'=>$_POST['address_neighborhood']??'',
                'address_city'=>$_POST['address_city']??'',
                'address_state'=>$_POST['address_state']??'',
            ]);
            flash('success','Inscrição cadastrada pela secretaria.');
            redirect('/event-registrations.php?event_id='.rawurlencode($eventId));
        }

        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect('/event-registrations.php'.(!empty($_POST['event_id'])?'?event_id='.rawurlencode((string)$_POST['event_id']):''));
    }
}

$q=$pdo->prepare('SELECT id,title,event_date,capacity,registration_enabled,registration_fee,registration_public_token FROM events WHERE church_id=? AND active=1 ORDER BY event_date DESC');
$q->execute([$churchId]);
$events=$q->fetchAll();

$filter=trim((string)($_GET['event_id']??''));
$rows=$service->registrations($filter?:null);
$stats=$filter!==''?$service->stats($filter):null;

$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$host=(string)($_SERVER['HTTP_HOST']??'');
$basePublicUrl=$host!==''?$scheme.'://'.$host:'';

$registrationLabels=['pending'=>'Pendente','confirmed'=>'Confirmada','cancelled'=>'Cancelada','waitlist'=>'Lista de espera'];
$paymentLabels=['unpaid'=>'Não pago','pending'=>'Pendente','paid'=>'Pago','waived'=>'Isento/Grátis','refunded'=>'Estornado'];

View::header('Inscrições de Eventos',$auth,'event-registrations.php');
View::flash();
?>
<style>
.reg-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:14px 0}.reg-stats .card{padding:13px}.reg-stats small{display:block;color:var(--muted);font-size:10px}.reg-stats strong{display:block;font-size:20px;margin-top:4px}
.reg-layout{display:grid;grid-template-columns:minmax(300px,.7fr) minmax(0,1.7fr);gap:16px;align-items:start}.reg-form{position:sticky;top:16px}.reg-status-form{display:grid;grid-template-columns:1fr 1fr 120px 1fr auto;gap:6px;align-items:end;min-width:650px}.reg-link{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.reg-link input{flex:1;min-width:280px}
@media(max-width:1050px){.reg-layout{grid-template-columns:1fr}.reg-form{position:static}.reg-stats{grid-template-columns:1fr 1fr}}@media(max-width:620px){.reg-stats{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <div><h1>Inscrições de Eventos</h1><p>Acompanhe participantes, vagas e pagamentos em um só lugar.</p></div>
</div>

<form method="get" class="card card-pad actions" style="margin-bottom:14px">
  <label style="min-width:300px">Evento<select class="select" name="event_id"><option value="">Todos os eventos</option><?php foreach($events as $e):?><option value="<?=e($e['id'])?>" <?=$filter===$e['id']?'selected':''?>><?=e($e['title'])?> · <?=date('d/m/Y H:i',strtotime($e['event_date']))?></option><?php endforeach?></select></label>
  <button class="btn btn-primary">Filtrar</button>
  <?php if($filter!==''):?><a class="btn btn-light" target="_blank" href="/report.php?type=event&id=<?=e($filter)?>&format=print">Imprimir lista</a><a class="btn btn-light" href="/report.php?type=event&id=<?=e($filter)?>&format=pdf">PDF</a><?php endif?>
</form>

<?php if($stats):?>
<div class="reg-stats">
  <div class="card"><small>Pessoas inscritas</small><strong><?=$stats['people']?></strong></div>
  <div class="card"><small>Confirmadas</small><strong><?=$stats['confirmed']?></strong></div>
  <div class="card"><small>Pendentes</small><strong><?=$stats['pending']?></strong></div>
  <div class="card"><small>Recebido</small><strong>R$ <?=number_format((float)$stats['paid'],2,',','.')?></strong></div>
  <div class="card"><small>Vagas disponíveis</small><strong><?=$stats['available']===null?'∞':$stats['available']?></strong></div>
</div>

<?php $ev=$stats['event']; if(!empty($ev['registration_enabled'])&&!empty($ev['registration_public_token'])):
  $publicUrl=$basePublicUrl.'/event-register.php?t='.rawurlencode((string)$ev['registration_public_token']);
?>
<section class="card card-pad" style="margin-bottom:14px">
  <div class="page-head"><div><h2 style="font-size:15px">Link público</h2><p>Compartilhe nas redes, WhatsApp ou gere um QR Code.</p></div></div>
  <div class="reg-link"><input class="input" id="event-reg-url" readonly value="<?=e($publicUrl)?>" onclick="this.select()"><button class="btn btn-light" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('event-reg-url').value)">Copiar</button><a class="btn btn-light" target="_blank" rel="noopener noreferrer" href="https://quickchart.io/qr?size=300&text=<?=rawurlencode($publicUrl)?>">QR Code</a><a class="btn btn-light" target="_blank" href="<?=e($publicUrl)?>">Abrir página</a></div>
</section>
<?php endif?>
<?php endif?>

<div class="reg-layout">
<section class="card card-pad reg-form">
  <h2 style="font-size:16px">Inscrição pela secretaria</h2>
  <p class="muted">Para inscrições recebidas pessoalmente ou por telefone.</p>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid">
      <label class="full">Evento<select class="select" name="event_id" required><option value="">Selecione...</option><?php foreach($events as $e):?><option value="<?=e($e['id'])?>" <?=$filter===$e['id']?'selected':''?>><?=e($e['title'])?></option><?php endforeach?></select></label>
      <label>Nome *<input class="input" name="name" required></label>
      <label>Telefone *<input class="input" name="phone" required></label>
      <label>E-mail<input class="input" type="email" name="email"></label>
      <label>Nascimento<input class="input" type="date" name="birth_date"></label>
      <label>CPF<input class="input" name="cpf"></label>
      <label>Convidados<input class="input" type="number" min="0" max="20" name="guests_count" value="0"></label>
      <label>CEP<input class="input" name="cep"></label>
      <label>Rua<input class="input" name="address_street"></label>
      <label>Número<input class="input" name="address_number"></label>
      <label>Bairro<input class="input" name="address_neighborhood"></label>
      <label>Cidade<input class="input" name="address_city"></label>
      <label>UF<input class="input" name="address_state" maxlength="2"></label>
      <label class="full">Observações<textarea class="textarea" name="notes"></textarea></label>
    </div>
    <button class="btn btn-primary">Cadastrar inscrição</button>
  </form>
</section>

<section class="card card-pad">
  <div class="page-head"><div><h2 style="font-size:16px">Participantes</h2><p><?=count($rows)?> inscrição(ões)</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Participante</th><th>Evento</th><th>Pessoas</th><th>Valor</th><th>Status</th></tr></thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><strong><?=e($r['name'])?></strong><div class="muted"><?=e($r['phone'])?><?=!empty($r['email'])?' · '.e($r['email']):''?></div></td>
      <td><?=e($r['event_title'])?></td>
      <td><?=1+(int)$r['guests_count']?></td>
      <td><strong>R$ <?=number_format((float)$r['amount_due'],2,',','.')?></strong><div class="muted">Pago: R$ <?=number_format((float)$r['amount_paid'],2,',','.')?></div></td>
      <td>
        <form method="post" class="reg-status-form">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?=e($r['id'])?>">
          <input type="hidden" name="event_id" value="<?=e($filter?:$r['event_id'])?>">
          <label>Inscrição<select class="select" name="registration_status"><?php foreach($registrationLabels as $key=>$label):?><option value="<?=e($key)?>" <?=$r['registration_status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
          <label>Pagamento<select class="select" name="payment_status"><?php foreach($paymentLabels as $key=>$label):?><option value="<?=e($key)?>" <?=$r['payment_status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
          <label>Pago<input class="input" type="number" min="0" step="0.01" name="amount_paid" value="<?=e((string)$r['amount_paid'])?>"></label>
          <label>Referência<input class="input" name="payment_reference" value="<?=e($r['payment_reference']??'')?>" placeholder="PIX, recibo..."></label>
          <button class="btn btn-light">Salvar</button>
        </form>
      </td>
    </tr>
  <?php endforeach?>
  <?php if(!$rows):?><tr><td colspan="5"><div class="empty">Nenhuma inscrição encontrada.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>
</div>
<?php View::footer(); ?>