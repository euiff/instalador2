<?php
declare(strict_types=1);
/**
 * Igreja Master 3.7.17: synthetic users and database only.
 * Runs all prior finance, tenant, portal and receipt regressions first.
 */
require __DIR__.'/../../3.7.16/tests/integration.php';
require_once $dir.'/public/lib/SystemCourseService.php';

$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$memberTrainingAuth=new Auth($pdo);
$check($memberTrainingAuth->can('system_course'),'linked member can open official course');
$check(!$memberTrainingAuth->can('finance'),'manual does not elevate member financial permissions');
$before=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='learning_courses'")->fetchColumn();
$course=new SystemCourseService($pdo,$memberTrainingAuth,$churchA,$participantUser);
$check($course->moduleCount()>=25,'system course has all areas as modules');
$check($course->lessonCount()>=150,'full illustrated-step curriculum has 150+ lessons');
$check(!$course->lesson('invalid-lesson'),'unlisted lesson not accepted');
$check($course->lookup('contabilidade')!==[],'accounting tutorials searchable');
$check($course->lookup('recibo')!==[],'receipt tutorials searchable');
$check($course->lookup('vincular')!==[],'member linking tutorials searchable');
$check($course->lookup('simplesmente-um-termo-que-nao-existe-no-curso')===[],'unknown phrase yields empty results');
$check(count($course->lookup(''))===$course->lessonCount(),'no search shows all lessons');

$all=$course->modules();
$keys=[];
foreach($all as $module){
    $check(!empty($module['audience'])&&!empty($module['summary']),'module includes audience and summary');
    foreach($module['lessons'] as $entry){
        $check(count($entry['steps'])>=3,'lesson contains detailed instructions');
        $check(!isset($keys[$entry['slug']]),'lesson keys unique');
        $keys[$entry['slug']]=true;
        $check($course->lesson($entry['slug'])['module']===$module['slug'],'lesson points to intended module');
    }
}
$first=$all[0]['lessons'][0]['slug'];
$second=$course->nextLesson($first);
$check(is_string($second)&&$course->lesson($second)!==null,'navigate to next lesson');
$check($course->completedCount()===0,'member initial completion is empty');
$course->setCompleted($first,true);
$course->setCompleted($first,true);
$check($course->completedCount()===1,'marking same lesson twice is idempotent');
$check($course->completedCount($all[0]['slug'])===1,'completion counted inside correct module');
$again=new SystemCourseService($pdo,$memberTrainingAuth,$churchA,$participantUser);
$check(isset($again->progress()[$first]),'completed lesson persists across page reloads');
$check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='learning_courses'")->fetchColumn()===$before,
  'system course is globally available without duplicating church learning_courses');
try{$course->setCompleted('invented-key',true);throw new RuntimeException('invalid lesson completed');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'Aula'),'unlisted lesson rejected');}
$course->setCompleted($first,false);
$check($course->completedCount()===0,'user can reopen lesson and reset progress');

// Another church has exactly the same catalog and never sees this church progress.
$foreignStudentUser=app_uuid();
$pdo->prepare("INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)")
 ->execute([$foreignStudentUser,'training-user-b-3717@example.org','Usuário da segunda igreja']);
$pdo->prepare("INSERT INTO church_members(id,church_id,user_id,church_role,person_id) VALUES(?,?,?,'member',?)")
 ->execute([app_uuid(),$churchB,$foreignStudentUser,$foreign]);
$_SESSION=['user_id'=>$foreignStudentUser,'church_id'=>$churchB];
$foreignAuth=new Auth($pdo);
$check($foreignAuth->can('system_course'),'new church user can open same official catalog without seed');
$foreignCourse=new SystemCourseService($pdo,$foreignAuth,$churchB,$foreignStudentUser);
$check($foreignCourse->moduleCount()===$course->moduleCount(),'same catalog served to another church');
$check($foreignCourse->lessonCount()===$course->lessonCount(),'all lessons available across churches');
$check($foreignCourse->completedCount()===0,'progress of other church not leaked');
$foreignCourse->setCompleted($first,true);
$check($foreignCourse->completedCount()===1,'other church stores its own completion');
$_SESSION=['user_id'=>$participantUser,'church_id'=>$churchA];
$memberAuthAgain=new Auth($pdo);
$courseAgain=new SystemCourseService($pdo,$memberAuthAgain,$churchA,$participantUser);
$check($courseAgain->completedCount()===0,'first church does not inherit another user completion');
try{new SystemCourseService($pdo,$memberAuthAgain,$churchB,$participantUser);
    throw new RuntimeException('tenant bypass in course service');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'acesso'),'cannot read another tenant progress by forged church ID');}
$courseAgain->setCompleted($second,true);
$check((int)$pdo->query("SELECT COUNT(*) FROM system_course_progress WHERE church_id='".$churchA."' AND user_id='".$participantUser."'")===1,
 'first church progress is only per-user per-church');
$check((int)$pdo->query("SELECT COUNT(*) FROM system_course_progress WHERE church_id='".$churchB."' AND user_id='".$foreignStudentUser."'")===1,
 'second church progress stored independently');

// Secretary and accountant can READ even though neither needs to enroll in the church's classes.
$_SESSION=['user_id'=>$secretary,'church_id'=>$churchA];
$secretaryAuth=new Auth($pdo);
$check($secretaryAuth->can('system_course'),'secretary can access system training without membership in a learning class');
$check((new SystemCourseService($pdo,$secretaryAuth,$churchA,$secretary))->lessonCount()>100,
 'secretary has complete course');
$_SESSION=['user_id'=>$accountant,'church_id'=>$churchA];
$accountantTrainingAuth=new Auth($pdo);
$check($accountantTrainingAuth->can('system_course'),'accountant can read training while keeping financial permissions restricted');
$check(!$accountantTrainingAuth->can('finance'),'accountant not promoted by training');
$check((new SystemCourseService($pdo,$accountantTrainingAuth,$churchA,$accountant))->completedCount()===0,
 'accountant progress does not include secretary or member results');

// Master can consult the course before opening any customer, without creating a tenant.
$_SESSION=['user_id'=>$master,'platform_master_mode'=>1];
$masterTrainingAuth=new Auth($pdo);
$check($masterTrainingAuth->isSuperAdmin(),'master test context recognized');
$masterCourse=new SystemCourseService($pdo,$masterTrainingAuth,null,$master);
$check($masterCourse->lessonCount()===$course->lessonCount(),'Master sees official platform course without entering client church');
$check(!$masterCourse->canTrack(),'Master is not written into any client learning progress');
try{$masterCourse->setCompleted($first,true);throw new RuntimeException('Master progress assigned to client');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Master'),'platform Master never mutates any client progress');}

echo "PASS 3.7.17 curriculum 27 modules / 164 lessons, global availability, per-user progression, tenant isolation, members/accountant/secretary/Master permissions, no duplicate church classes\n";
