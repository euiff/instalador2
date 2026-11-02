<?php
require_once __DIR__.'/ChurchCare.php';
$careDashboard=new ChurchCare($pdo,$cid);
$attention=[];
if($auth->can('pastoral')) {
    $r=$careDashboard->all("SELECT COUNT(*) total FROM pastoral_care WHERE church_id=? AND status='open' AND next_contact<=CURDATE()")[0];
    $attention[]=['Retornos pastorais',(int)$r['total'],'pastoral-care.php?due=1'];
}
if($canSecretary) {
    $r=$careDashboard->all("SELECT COUNT(*) total FROM church_visitors WHERE church_id=? AND status NOT IN ('closed','integrated') AND next_contact<=CURDATE()")[0];
    $attention[]=['Visitantes para contatar',(int)$r['total'],'visitors.php?due=1'];
    $r=$careDashboard->all('SELECT COUNT(*) total FROM members WHERE church_id=? AND active=1 AND MONTH(birth_date)=MONTH(CURDATE()) AND DAY(birth_date)=DAY(CURDATE())')[0];
    $attention[]=['Aniversariantes hoje',(int)$r['total'],'church-insights.php'];
}
if($canFinance) {
    $r=$careDashboard->all("SELECT COUNT(*) total FROM church_assets a WHERE a.church_id=? AND a.status<>'retired' AND (SELECT h.next_due FROM asset_maintenance h WHERE h.asset_id=a.id AND h.church_id=a.church_id ORDER BY h.performed_on DESC,h.created_at DESC,h.id DESC LIMIT 1)<=CURDATE()")[0];
    $attention[]=['Bens com manutenção prevista',(int)$r['total'],'assets.php?due=1'];
}
if($attention):?>
<section class="card card-pad"><div class="page-head"><div><h2>Atenção de hoje</h2><p>Próximas ações para cuidar das pessoas e da igreja</p></div></div><div class="care-shortcuts"><?php foreach($attention as [$label,$count,$href]):?><a class="care-shortcut" href="<?=e($href)?>"><strong><?=$count?></strong><span><?=e($label)?></span></a><?php endforeach?></div></section>
<?php endif?>
