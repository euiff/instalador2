<?php
require_once __DIR__.'/ChurchCare.php';
$profileCare=new ChurchCare($pdo,(string)$church['id']);
$profileDepartments=$profileCare->all('SELECT d.name,d.leader FROM ministry_members mm INNER JOIN ministry_departments d ON d.id=mm.department_id AND d.church_id=mm.church_id WHERE mm.church_id=? AND mm.member_id=? ORDER BY d.name',[$m['id']]);
?>
<section class="card card-pad"><h2>Departamentos do membro</h2><?php if(!$profileDepartments):?><p class="muted">Nenhum vínculo adicional. O departamento informado no cadastro continua preservado.</p><?php endif?><?php foreach($profileDepartments as $pd):?><p><strong><?=e($pd['name'])?></strong> · <?=e($pd['leader']?:'Liderança não informada')?></p><?php endforeach?><a class="btn btn-light" href="departments.php">Organizar departamentos</a></section>
<?php if($auth->can('pastoral')):
$profileContacts=$profileCare->all('SELECT kind,occurred_on,status,next_contact FROM pastoral_care WHERE church_id=? AND member_id=? ORDER BY occurred_on DESC,created_at DESC LIMIT 5',[$m['id']]);?>
<section class="card card-pad"><h2>Acompanhamento pastoral</h2><?php if(!$profileContacts):?><p class="muted">Nenhum acompanhamento registrado.</p><?php endif?><?php foreach($profileContacts as $pc):?><p><?=e(ChurchCare::CARE_KIND[$pc['kind']]??$pc['kind'])?> · <?=e(ChurchCare::displayDate($pc['occurred_on']))?> · <?=e(ChurchCare::CARE_STATUS[$pc['status']]??$pc['status'])?> · Retorno: <?=e(ChurchCare::displayDate($pc['next_contact']))?></p><?php endforeach?><a class="btn btn-light" href="pastoral-care.php?status=all&amp;member_id=<?=e($m['id'])?>">Ver histórico / registrar contato</a></section>
<?php endif?>
