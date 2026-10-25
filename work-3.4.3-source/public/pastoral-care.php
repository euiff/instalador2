<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/lib/CareView.php';
$user=$auth->requireAbility('pastoral'); $church=$auth->currentChurch(); if(!$church) redirect('/logout.php');
$care=new ChurchCare($pdo,(string)$church['id']); $error=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        if(($_POST['action']??'')==='close') $care->closeCare(ChurchCare::text($_POST,'id',36,true));
        else $care->care($_POST,(string)$user['id']);
        flash('success','Acompanhamento salvo.'); redirect('/pastoral-care.php');
    } catch(Throwable $e) { $error=care_error($e); }
}
$status=(string)($_GET['status']??'open'); $due=isset($_GET['due']);
$filterMember=trim((string)($_GET['member_id']??''));
$sql='SELECT p.*,m.name FROM pastoral_care p INNER JOIN members m ON m.id=p.member_id AND m.church_id=p.church_id WHERE p.church_id=?'; $params=[];
if(isset(ChurchCare::CARE_STATUS[$status])) { $sql.=' AND p.status=?'; $params[]=$status; }
if($due) $sql.=' AND p.next_contact<=CURDATE()';
if($filterMember!=='') { $sql.=' AND p.member_id=?'; $params[]=$filterMember; }
$sql.=' ORDER BY p.next_contact IS NULL,p.next_contact,p.created_at DESC LIMIT 500'; $rows=$care->all($sql,$params);
$members=care_member_options($care);
View::header('Cuidado pastoral',$auth,'pastoral-care.php'); care_heading('Cuidado pastoral','Registre visitas, ausências percebidas e retornos. Acesso reservado à liderança pastoral.'); care_notice($error);
?>
<div class="grid two-col"><section class="card card-pad">
<form method="get" class="toolbar no-print"><?php if($filterMember!==''):?><input type="hidden" name="member_id" value="<?=e($filterMember)?>"><?php endif?><label>Status <select class="select" name="status"><option value="all" <?=$status==='all'?'selected':''?>>Todos</option><?php foreach(ChurchCare::CARE_STATUS as $k=>$v):?><option value="<?=e($k)?>" <?=$status===$k?'selected':''?>><?=e($v)?></option><?php endforeach?></select></label><label><input type="checkbox" name="due" value="1" <?=$due?'checked':''?>> Retornos até hoje</label><button class="btn btn-light">Filtrar</button></form>
<p class="muted"><?=count($rows)?> registro(s), até 500 por consulta. Ausências são registradas pela liderança; não são inferidas automaticamente.</p>
<?php if(!$rows):?><div class="empty">Nenhum acompanhamento neste filtro.</div><?php endif?>
<?php foreach($rows as $r):?><article class="care-entry"><div class="page-head"><div><strong><?=e($r['name'])?></strong><p><?=e(ChurchCare::CARE_KIND[$r['kind']]??$r['kind'])?> · <?=e(ChurchCare::displayDate($r['occurred_on']))?></p></div><span class="badge <?=$r['status']==='done'?'ok':'off'?>"><?=e(ChurchCare::CARE_STATUS[$r['status']]??$r['status'])?></span></div><p>Responsável: <?=e($r['responsible'])?></p><p>Retorno: <?=e(ChurchCare::displayDate($r['next_contact']))?> <?php if($r['status']==='open' && $r['next_contact'] && $r['next_contact']<=date('Y-m-d')):?><strong> · Requer atenção</strong><?php endif?></p><p class="care-notes"><?=nl2br(e($r['notes']??''))?></p><?php if($r['status']==='open'):?><form method="post" class="no-print"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="action" value="close"><button class="btn btn-light">Concluir acompanhamento</button></form><?php endif?></article><?php endforeach?>
</section><section data-registry-form="pastoral" class="card card-pad no-print"><h2>Novo acompanhamento</h2><p>Para registrar outro contato, crie um novo acompanhamento. Os registros anteriores permanecem no histórico.</p><form method="post"><?=csrf_field()?><div class="form-grid">
<?php care_field('member_id','Membro',$_POST['member_id']??$filterMember,'select',true,$members); care_field('kind','Tipo',$_POST['kind']??'visit','select',true,ChurchCare::CARE_KIND); care_field('occurred_on','Data do contato',$_POST['occurred_on']??date('Y-m-d'),'date',true); care_field('next_contact','Próximo retorno',$_POST['next_contact']??'','date'); care_field('responsible','Responsável',$_POST['responsible']??$user['full_name'],'text',true); care_field('status','Situação',$_POST['status']??'open','select',true,ChurchCare::CARE_STATUS); care_field('notes','Registro pastoral',$_POST['notes']??'','textarea'); ?>
</div><button class="btn btn-primary">Salvar acompanhamento</button></form></section></div>
<?php View::footer();
