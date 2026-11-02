<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AnnouncementService.php';
require_once __DIR__.'/lib/StudyService.php';
require_once __DIR__.'/lib/LearningService.php';
require_once __DIR__.'/lib/MediaLibraryService.php';
require_once __DIR__.'/lib/MemberSelfService.php';

$user=$auth->requireLogin();
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');
$cid=(string)$church['id'];

$announcements=new AnnouncementService($pdo,$auth,$cid);
$studies=new StudyService($pdo,$auth,$cid);
$learning=new LearningService($pdo,$auth,$cid);
$media=new MediaLibraryService($pdo,$auth,$cid,__DIR__);
$self=new MemberSelfService($pdo,$auth,$cid);

$member=null;$profileCompleteness=0;
try{$member=$self->member();$profileCompleteness=$self->completeness($member);}catch(Throwable){}

try{$notices=$announcements->visibleForCurrentUser();}catch(Throwable){$notices=[];}
$unreadNotices=array_values(array_filter($notices,fn($r)=>empty($r['is_read'])));

try{$studyRows=$studies->visible();}catch(Throwable){$studyRows=[];}
$unreadStudies=array_values(array_filter($studyRows,fn($r)=>empty($r['is_read'])));

try{$classes=$learning->classes(false);}catch(Throwable){$classes=[];}
try{$mediaRows=$media->visibleForCurrentUser();}catch(Throwable){$mediaRows=[];}

$q=$pdo->prepare("SELECT id,title,event_date,location,theme,registration_enabled,registration_public_token,capacity
  FROM events
  WHERE church_id=? AND active=1 AND event_date>=NOW()
  ORDER BY event_date
  LIMIT 6");
$q->execute([$cid]);$events=$q->fetchAll();

$q=$pdo->prepare("SELECT id,name,birth_date,photo_url
  FROM members
  WHERE church_id=? AND active=1 AND birth_date IS NOT NULL
    AND (MONTH(birth_date)=MONTH(CURDATE()) OR MONTH(birth_date)=MONTH(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)))
  ORDER BY CASE WHEN MONTH(birth_date)=MONTH(CURDATE()) THEN 0 ELSE 1 END,DAY(birth_date),name
  LIMIT 12");
$q->execute([$cid]);$birthdays=$q->fetchAll();

$yearContribution=0.0;
if($member){
    $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_entries
      WHERE church_id=? AND member_id=? AND direction='income' AND status='posted'
        AND transaction_date BETWEEN ? AND ?");
    $q->execute([$cid,$member['id'],date('Y-01-01'),date('Y-12-31')]);
    $yearContribution=(float)$q->fetchColumn();
}

$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$host=(string)($_SERVER['HTTP_HOST']??'');
$donationUrl=(!empty($church['donation_enabled'])&&!empty($church['donation_public_token'])&&$host!=='')
    ?$scheme.'://'.$host.'/donate.php?t='.rawurlencode((string)$church['donation_public_token'])
    :null;

$firstName=$member?trim(explode(' ',trim((string)$member['name']))[0]??''):'';
if($firstName==='')$firstName=trim(explode(' ',trim((string)($user['full_name']??'')))[0]??'');
if($firstName==='')$firstName='Olá';

View::header('Meu acesso',$auth,'portal-home.php');
View::flash();
?>
<style>
.member-home{display:grid;gap:16px}.member-hero{position:relative;overflow:hidden;padding:22px;border-radius:18px;background:linear-gradient(135deg,#174f86,#2d79b9);color:white}.member-hero:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08);right:-70px;top:-90px}.member-hero h1{margin:0 0 5px;font-size:25px}.member-hero p{margin:0;color:rgba(255,255,255,.82)}.member-hero-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px;position:relative;z-index:2}.member-hero .btn{background:white;color:#174f86;border-color:white}
.member-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.member-kpi{padding:14px}.member-kpi small{display:block;color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase}.member-kpi strong{display:block;font-size:21px;margin-top:4px}.member-kpi a{font-size:10px}
.member-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}.member-section{padding:16px}.member-section h2{font-size:16px;margin:0}.member-section-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:11px}
.member-feed{display:grid;gap:8px}.member-feed-item{display:block;padding:11px 12px;border:1px solid var(--line);border-radius:11px;color:inherit;text-decoration:none}.member-feed-item:hover{border-color:#9bb9d8;background:#fbfdff}.member-feed-item strong{display:block;font-size:12px}.member-feed-item small{display:block;color:var(--muted);margin-top:3px}.member-feed-item.unread{border-left:4px solid #2c78b7}
.member-events{display:grid;gap:8px}.member-event{display:grid;grid-template-columns:52px 1fr auto;gap:10px;align-items:center;padding:10px;border-bottom:1px solid #edf1f5}.member-event:last-child{border-bottom:0}.member-date{border-radius:10px;background:#edf5fb;text-align:center;padding:6px}.member-date strong{display:block;font-size:16px;color:#1b5b91}.member-date small{font-size:9px;color:#607184;text-transform:uppercase}
.member-quick{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.member-quick a{display:block;padding:13px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:inherit;background:var(--card)}.member-quick strong{display:block;font-size:12px}.member-quick small{display:block;color:var(--muted);font-size:9px;margin-top:3px}
.member-bdays{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.member-bday{padding:9px 10px;background:#f7f9fc;border-radius:10px}.member-bday strong{display:block;font-size:11px}.member-bday small{color:var(--muted);font-size:9px}
.profile-meter{height:8px;background:#e7edf3;border-radius:999px;overflow:hidden;margin-top:7px}.profile-meter span{display:block;height:100%;background:#2c78b7}
@media(max-width:980px){.member-grid{grid-template-columns:1fr}.member-kpis{grid-template-columns:1fr 1fr}}@media(max-width:650px){.member-kpis,.member-quick,.member-bdays{grid-template-columns:1fr}.member-event{grid-template-columns:48px 1fr}.member-event .btn{grid-column:1/-1}.member-hero{padding:18px}}
</style>

<div class="member-home">
  <section class="member-hero">
    <h1>Olá, <?=e($firstName)?> 👋</h1>
    <p>Este é o seu acesso à <?=e($church['name'])?>. Avisos, eventos, estudos, cursos e suas informações ficam reunidos aqui.</p>
    <div class="member-hero-actions">
      <?php if($member):?><a class="btn" href="/my-profile.php">Meu cadastro</a><a class="btn" href="/portal.php?view=personal">Minhas contribuições</a><?php endif?>
      <?php if($donationUrl):?><a class="btn" target="_blank" rel="noopener noreferrer" href="<?=e($donationUrl)?>">Contribuir / PIX</a><?php endif?>
    </div>
  </section>

  <div class="member-kpis">
    <section class="card member-kpi"><small>Avisos não lidos</small><strong><?=count($unreadNotices)?></strong><a href="/board.php">Abrir Mural</a></section>
    <section class="card member-kpi"><small>Estudos novos</small><strong><?=count($unreadStudies)?></strong><a href="/studies.php">Ver estudos</a></section>
    <section class="card member-kpi"><small>Minhas turmas</small><strong><?=count($classes)?></strong><a href="/learning.php">Abrir Ensino</a></section>
    <section class="card member-kpi"><small>Contribuições <?=date('Y')?></small><strong>R$ <?=number_format($yearContribution,2,',','.')?></strong><a href="/portal.php?view=personal">Ver histórico</a></section>
  </div>

  <div class="member-grid">
    <section class="card member-section">
      <div class="member-section-head"><h2>Próximos eventos</h2><a class="muted" href="/events.php">Agenda</a></div>
      <div class="member-events">
        <?php foreach($events as $event):?>
          <div class="member-event">
            <div class="member-date"><strong><?=date('d',strtotime($event['event_date']))?></strong><small><?=mb_strtoupper(strftime('%b',strtotime($event['event_date'])))?></small></div>
            <div><strong><?=e($event['title'])?></strong><small class="muted"><?=date('H:i',strtotime($event['event_date']))?><?=!empty($event['location'])?' · '.e($event['location']):''?></small></div>
            <?php if(!empty($event['registration_enabled'])&&!empty($event['registration_public_token'])):?><a class="btn btn-light" target="_blank" href="/event-register.php?t=<?=e($event['registration_public_token'])?>">Inscrever</a><?php endif?>
          </div>
        <?php endforeach?>
        <?php if(!$events):?><div class="empty">Nenhum evento futuro cadastrado.</div><?php endif?>
      </div>
    </section>

    <section class="card member-section">
      <div class="member-section-head"><h2>Meu cadastro</h2><?php if($member):?><a class="muted" href="/my-profile.php">Atualizar</a><?php endif?></div>
      <?php if($member):?>
        <strong><?=$profileCompleteness?>% preenchido</strong>
        <div class="profile-meter"><span style="width:<?=max(0,min(100,$profileCompleteness))?>%"></span></div>
        <p class="muted">Mantenha telefone, e-mail e endereço atualizados para facilitar a comunicação da igreja.</p>
        <div class="member-feed-item"><strong><?=e($member['name'])?></strong><small><?=e($member['phone'])?><?=!empty($member['email'])?' · '.e($member['email']):''?></small></div>
      <?php else:?>
        <div class="empty">A secretaria ainda precisa vincular este login ao seu cadastro pessoal.</div>
      <?php endif?>
    </section>
  </div>

  <div class="member-grid">
    <section class="card member-section">
      <div class="member-section-head"><h2>Mural</h2><a class="muted" href="/board.php">Ver todos</a></div>
      <div class="member-feed">
        <?php foreach(array_slice($notices,0,4) as $notice):?>
          <a class="member-feed-item <?=empty($notice['is_read'])?'unread':''?>" href="/board.php"><strong><?=e($notice['title'])?></strong><small><?=e(mb_strimwidth((string)$notice['body'],0,125,'…'))?></small></a>
        <?php endforeach?>
        <?php if(!$notices):?><div class="empty">Nenhum aviso no momento.</div><?php endif?>
      </div>
    </section>

    <section class="card member-section">
      <div class="member-section-head"><h2>Estudos recentes</h2><a class="muted" href="/studies.php">Ver todos</a></div>
      <div class="member-feed">
        <?php foreach(array_slice($studyRows,0,4) as $study):?>
          <a class="member-feed-item <?=empty($study['is_read'])?'unread':''?>" href="/studies.php?id=<?=e($study['id'])?>"><strong><?=e($study['title'])?></strong><small><?=e($study['category_name']?:'Estudo')?> · <?=e(mb_strimwidth((string)($study['summary']??''),0,105,'…'))?></small></a>
        <?php endforeach?>
        <?php if(!$studyRows):?><div class="empty">Nenhum estudo publicado.</div><?php endif?>
      </div>
    </section>
  </div>

  <section class="card member-section">
    <div class="member-section-head"><h2>Acessos rápidos</h2></div>
    <div class="member-quick">
      <a href="/board.php"><strong>Mural</strong><small>Avisos da igreja</small></a>
      <a href="/learning.php"><strong>Ensino e Turmas</strong><small>Cursos, aulas e frequência</small></a>
      <a href="/studies.php"><strong>Estudos</strong><small>Conteúdo e materiais</small></a>
      <a href="/media-library.php"><strong>Biblioteca</strong><small><?=count($mediaRows)?> arquivo(s) disponível(is)</small></a>
      <a href="/albums.php"><strong>Fotos e Vídeos</strong><small>Álbuns da igreja</small></a>
      <?php if($auth->can('group_portal')):?><a href="/small-groups.php"><strong>Grupos e Células</strong><small>Seus grupos e frequências</small></a><?php endif?>
      <?php if($auth->can('kids_portal')):?><a href="/kids.php"><strong>Kids</strong><small>Programações e check-in</small></a><?php endif?>
      <?php if($auth->can('department_portal')):?><a href="/portal.php?view=department"><strong>Caixa do Departamento</strong><small>Movimentações do seu ministério</small></a><?php endif?>
      <?php if($donationUrl):?><a target="_blank" href="<?=e($donationUrl)?>"><strong>Contribuir / PIX</strong><small>Gerar QR e copia-e-cola</small></a><?php endif?>
    </div>
  </section>

  <section class="card member-section">
    <div class="member-section-head"><h2>Aniversariantes</h2><span class="muted">Este mês e próximo</span></div>
    <div class="member-bdays">
      <?php foreach($birthdays as $birthday):?>
        <div class="member-bday"><strong><?=e($birthday['name'])?></strong><small><?=date('d/m',strtotime($birthday['birth_date']))?></small></div>
      <?php endforeach?>
      <?php if(!$birthdays):?><div class="empty">Nenhum aniversariante encontrado.</div><?php endif?>
    </div>
  </section>
</div>
<?php View::footer(); ?>