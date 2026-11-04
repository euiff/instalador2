<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/SystemCourseService.php';

$user=$auth->requireLogin();
$master=$auth->isSuperAdmin((string)$user['id']);
$church=$auth->currentChurch();
if(!$master&&!$auth->can('system_course')){
    http_response_code(403);
    exit('O Curso Oficial não está disponível para este acesso.');
}
$cid=$church?(string)$church['id']:null;
$course=new SystemCourseService($pdo,$auth,$cid,(string)$user['id']);
$catalog=$course->catalog();$modules=$course->modules();
$notice=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        if(($_POST['action']??'')!=='complete')throw new InvalidArgumentException('Ação desconhecida.');
        $lessonSlug=(string)($_POST['lesson_slug']??'');
        $lessonEntry=$course->lesson($lessonSlug);
        if(!$lessonEntry)throw new InvalidArgumentException('Aula não encontrada.');
        $done=(string)($_POST['complete']??'0')==='1';
        $course->setCompleted($lessonSlug,$done);
        flash('success',$done?'Aula marcada como concluída!':'Aula reaberta para estudo.');
        $go=$done?($course->nextLesson($lessonSlug)??$lessonSlug):$lessonSlug;
        $goEntry=$course->lesson($go);
        redirect('/system-course.php?mod='.rawurlencode($goEntry['module']).'&lesson='.rawurlencode($go).'#aula');
    }catch(Throwable $e){
        $notice=$e instanceof PDOException?'O progresso não pôde ser gravado. Peça ao suporte para conferir a atualização do banco.':$e->getMessage();
    }
}
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$allSlugs=$course->lookup($search);$match=array_fill_keys($allSlugs,true);
$modSlug=(string)($_GET['mod']??($modules[0]['slug']??''));
if(!$course->module($modSlug))$modSlug=$modules[0]['slug']??'';
if($search!==''){
    $moduleHasMatching=false;
    foreach($course->module($modSlug)['lessons']??[] as $row)if(isset($match[$row['slug']])){$moduleHasMatching=true;break;}
    if(!$moduleHasMatching&&$allSlugs){
        $first=$course->lesson($allSlugs[0]);
        $modSlug=$first['module'];
    }
}
$module=$course->module($modSlug);
$lessonSlug=(string)($_GET['lesson']??'');
$entry=$course->lesson($lessonSlug);
if(!$entry||$entry['module']!==$modSlug||($search!==''&&!isset($match[$lessonSlug]))){
    $lessonSlug='';
    foreach($module['lessons']??[] as $row)
        if(isset($match[$row['slug']])){$lessonSlug=$row['slug'];break;}
}
$lessonEntry=$lessonSlug!==''?$course->lesson($lessonSlug):null;
$lesson=$lessonEntry['lesson']??null;
$done=$course->progress();$total=$course->lessonCount();
$completed=count($done);$percent=$total?round($completed*100/$total):0;
$roles=array_values(array_unique(array_merge(...array_map(static fn($m)=>$m['audience']??[],$modules))));
$canOpen=static function(?string $permission)use($auth,$master):bool{
    if($permission===null||$permission==='')return false;
    if($permission==='master')return $master;
    return $auth->can($permission);
};
$routeFor=static function(?string $route)use($auth):?string{
    if(!$route)return null;
    // Some modules (the receipt) need an existing entry ID. Open their parent.
    if(str_starts_with($route,'/expense-receipt.php'))
        $route=$auth->can('finance')?'/finance.php':'/department-treasury.php';
    if(str_starts_with($route,'/accounting-package.php'))$route='/accounting.php';
    if(!preg_match('#^/[a-z0-9-]+\.php(?:\?[a-z0-9_=&-]+)?$#iD',$route))return null;
    return $route;
};
$moduleOf=static function(string $slug)use($course):string{
    return (string)($course->lesson($slug)['module']??'');
};
$all=($search==='');
View::header('Curso Oficial do Igreja Master',$auth,'system-course.php');
View::flash();
?>
<style>
.sys-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:18px;flex-wrap:wrap}
.sys-hero h1{margin:3px 0 6px;font-size:clamp(23px,2.6vw,31px)}
.sys-hero p{color:var(--muted);max-width:790px;line-height:1.6}
.sys-kicker{display:inline-flex;border-radius:999px;padding:5px 10px;background:#e9f3fa;color:#285d82;font-size:11px;font-weight:800}
.sys-stats{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}
.sys-stats>span{border:1px solid var(--line);border-radius:10px;padding:8px 11px;background:var(--card);font-size:12px}
.sys-search{display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;padding:13px;margin-bottom:16px}
.sys-search label{display:grid;gap:6px;flex:1;min-width:200px;font-weight:700;font-size:12px}
.sys-columns{display:grid;grid-template-columns:minmax(245px,305px) minmax(0,1fr);gap:16px;align-items:start}
.sys-sidebar{max-height:min(75vh,950px);overflow:auto;position:sticky;top:12px}
.sys-mod{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:10px;text-decoration:none;color:inherit;border:1px solid transparent}
.sys-mod:hover,.sys-mod.current{background:#edf5fb;border-color:#accde7;color:#205780}
.sys-mod strong{display:block;font-size:12px;line-height:1.4}.sys-mod small{color:var(--muted);font-size:10px}
.sys-number{flex:0 0 28px;height:28px;border-radius:9px;background:#e9eff5;display:grid;place-items:center;font-size:11px;font-weight:800}
.sys-content h2{margin:4px 0 8px}.sys-lesson-list{display:flex;gap:7px;flex-wrap:wrap;margin:13px 0 19px}
.sys-lesson-link{display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border:1px solid var(--line);border-radius:8px;background:var(--card);text-decoration:none;color:inherit;font-size:12px}
.sys-lesson-link.current{border-color:#3382bb;background:#edf7fd;color:#205a84}
.sys-card{border:1px solid var(--line);border-radius:15px;padding:clamp(15px,3vw,26px);background:var(--card)}
.sys-card>p{line-height:1.65}
.sys-steps{counter-reset:step;list-style:none;display:grid;gap:12px;margin:20px 0;padding:0}
.sys-steps li{counter-increment:step;display:flex;gap:13px;align-items:flex-start;line-height:1.65}
.sys-steps li::before{content:counter(step);font-size:12px;font-weight:800;flex:0 0 27px;height:27px;background:#e6eff7;color:#285a7b;border-radius:50%;display:grid;place-items:center;margin-top:1px}
.sys-note{background:#f3f6f8;border-left:3px solid #95acb9;padding:11px 13px;margin-top:13px;border-radius:8px;font-size:12px;line-height:1.65}
.sys-warning{background:#fffaf0;border-left:3px solid #d3ac5b}
.sys-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:20px}
.sys-progress{height:10px;border-radius:20px;background:#e8eef3;overflow:hidden}
.sys-progress span{display:block;height:100%;background:#2d8c9c}
.sys-noresult{padding:26px;text-align:center;color:var(--muted)}
.sys-module-heading{font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);font-weight:800}
@media(max-width:910px){.sys-columns{grid-template-columns:1fr}.sys-sidebar{position:static;max-height:300px}}
@media print{.sys-sidebar,.sys-search,.sys-actions,.sys-hero .actions,.sys-lesson-list{display:none!important}
.sys-columns{display:block}.sys-card{border:0;padding:0}.sys-hero{margin-bottom:8px}.sys-progress{display:none}}
</style>

<section class="sys-hero">
<div>
<span class="sys-kicker">CURSO OFICIAL • INCLUÍDO EM TODAS AS IGREJAS</span>
<h1><?=e($catalog['title'])?></h1>
<p><?=e($catalog['intro'])?></p>
<div class="sys-stats">
<span><strong><?=$course->moduleCount()?></strong> módulos</span>
<span><strong><?=$total?></strong> aulas práticas</span>
<span><strong><?=$completed?></strong> concluídas neste acesso</span>
<span><strong><?=$percent?>%</strong> do curso</span>
</div>
<div class="sys-progress" role="progressbar" aria-label="Progresso geral do curso" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?=$percent?>">
<span style="width:<?=$percent?>%"></span>
</div>
</div>
<div class="actions">
<a href="<?=$master&&!$cid?'/master.php':'/learning.php'?>" class="btn btn-light">Voltar a <?=e($master&&!$cid?'Master':'Cursos')?></a>
<button type="button" class="btn btn-light" onclick="window.print()">Imprimir aula</button>
</div>
</section>
<?php if($notice):?><div class="alert alert-error" role="alert"><?=e($notice)?></div><?php endif;?>
<form method="get" action="/system-course.php" class="card sys-search">
<label>Encontre um tutorial pelo assunto
<input class="input" name="q" type="search" value="<?=e($search)?>" maxlength="100" placeholder="Ex.: cadastrar membro, líder, nota fiscal, PIX, recibo, WhatsApp..."></label>
<button class="btn btn-primary" type="submit">Pesquisar aulas</button>
<?php if($search!==''):?><a class="btn btn-light" href="/system-course.php">Limpar busca</a><?php endif;?>
</form>
<div class="sys-columns">
<aside class="card card-pad sys-sidebar">
<div class="sys-module-heading">Módulos de treinamento</div>
<nav aria-label="Módulos do Curso Oficial" style="display:grid;gap:3px;margin-top:9px">
<?php foreach($modules as $i=>$m):
  $key=(string)$m['slug'];
  $matches=0;foreach($m['lessons'] as $ls)if(isset($match[$ls['slug']]))$matches++;
  if($search!==''&&$matches===0)continue;
  $finished=$course->completedCount($key);
  $goto=null;foreach($m['lessons'] as $ls)if(isset($match[$ls['slug']])){$goto=$ls['slug'];break;}
?>
<a class="sys-mod <?=$modSlug===$key?'current':''?>"
 href="/system-course.php?<?=e(http_build_query(['mod'=>$key,'lesson'=>$goto,'q'=>$search]))?>"
 <?=$modSlug===$key?'aria-current="page"':''?>>
 <span class="sys-number"><?=($i+1)?></span>
 <span><strong><?=e($m['title'])?></strong>
 <small><?=$finished?>/<?=count($m['lessons'])?> aulas concluídas • <?=e(implode(', ',$m['audience']??[]))?></small></span>
</a>
<?php endforeach;?>
</nav>
</aside>
<main class="sys-content">
<?php if(!$lesson):?>
<div class="card sys-noresult"><h2>Nenhuma aula encontrada</h2><p>Experimente procurar outro assunto, como membro, finanças, departamentos ou comunicação.</p></div>
<?php else:?>
<div class="card card-pad" style="margin-bottom:12px">
<div class="sys-module-heading">Módulo selecionado</div>
<h2><?=e($module['title'])?></h2><p class="muted"><?=e($module['summary'])?></p>
<div class="sys-lesson-list">
<?php foreach($module['lessons'] as $i=>$mLesson):
 if(!isset($match[$mLesson['slug']]))continue;
 $isDone=isset($done[$mLesson['slug']]);
?>
<a class="sys-lesson-link <?=$lessonSlug===$mLesson['slug']?'current':''?>" href="/system-course.php?<?=e(http_build_query(['mod'=>$modSlug,'lesson'=>$mLesson['slug'],'q'=>$search]))?>#aula">
<?=($i+1)?>. <?=e($mLesson['title'])?> <?=$isDone?'✓':''?>
</a>
<?php endforeach;?>
</div>
</div>
<article id="aula" class="sys-card">
<div class="sys-module-heading">Aula <?=e($lessonSlug)?> • <?=e(implode(', ',$module['audience']??[]))?></div>
<h2><?=e($lesson['title'])?></h2>
<ol class="sys-steps">
<?php foreach($lesson['steps'] as $step):?><li><span><?=e((string)$step)?></span></li><?php endforeach;?>
</ol>
<?php if(!empty($lesson['tip'])):?>
<div class="sys-note"><strong>Dica:</strong> <?=e($lesson['tip'])?></div><?php endif;?>
<?php if(!empty($lesson['warning'])):?>
<div class="sys-note sys-warning"><strong>Atenção:</strong> <?=e($lesson['warning'])?></div><?php endif;?>
<div class="sys-actions">
<?php
 $path=$routeFor($lesson['route']??null);
 if($path&&$canOpen($lesson['permission']??null)):?>
<a class="btn btn-light" href="<?=e($path)?>">Abrir esta função no sistema</a>
<?php elseif(!empty($lesson['route'])):?>
<small class="muted">Para executar este procedimento, entre com um perfil autorizado ou solicite ajuda ao gestor.</small>
<?php endif;?>
<?php if($course->canTrack()):?>
<form method="post" style="display:inline-flex"><?=csrf_field()?>
<input type="hidden" name="action" value="complete">
<input type="hidden" name="lesson_slug" value="<?=e($lessonSlug)?>">
<input type="hidden" name="complete" value="<?=isset($done[$lessonSlug])?'0':'1'?>">
<button class="btn <?=isset($done[$lessonSlug])?'btn-light':'btn-primary'?>" type="submit">
<?=isset($done[$lessonSlug])?'↻ Estudar novamente':'✓ Concluir aula e continuar'?>
</button></form>
<?php else:?><small class="muted">Modo Master: conteúdo disponível sem registro de progresso por igreja.</small><?php endif;?>
<?php $next=$course->nextLesson($lessonSlug);if($next):?>
<a class="btn btn-light" href="/system-course.php?<?=e(http_build_query(['mod'=>$moduleOf($next),'lesson'=>$next]))?>#aula">Próxima aula</a>
<?php endif;?>
</div>
</article>
<?php endif;?>
</main></div>
<?php View::footer();?>
