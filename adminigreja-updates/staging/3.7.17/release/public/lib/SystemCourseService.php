<?php
declare(strict_types=1);

/**
 * Platform-owned immutable learning catalog, shared by every church.
 * Only per-user course progress is stored, always scoped by church_id and user_id.
 * This does not create a new learning_courses row for every customer.
 */
final class SystemCourseService
{
    private array $catalog;
    private array $lessons=[];
    private array $modules=[];
    private ?array $progressCache=null;

    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private ?string $churchId,
        private string $userId
    ){
        if($userId===''||!$auth->user()||$auth->user()['id']!==$userId)
            throw new RuntimeException('Faça login para acessar este curso.');
        if(!$auth->isSuperAdmin()){
            if($churchId===null||!$auth->can('system_course')||
               ($auth->currentChurch()['id']??'')!==$churchId)
                throw new RuntimeException('Você não possui acesso ao treinamento desta igreja.');
        }elseif($churchId!==null&&($auth->currentChurch()['id']??'')!==$churchId){
            throw new RuntimeException('O Master só pode acompanhar uma igreja após entrar em modo suporte.');
        }

        $filename=dirname(__DIR__).'/data/system-course.json';
        $contents=is_file($filename)?file_get_contents($filename):false;
        if(!is_string($contents))throw new RuntimeException('O curso oficial não está instalado corretamente.');
        $decoded=json_decode($contents,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)||($decoded['id']??'')!=='igreja-master-manual-oficial'||
            !isset($decoded['modules'])||!is_array($decoded['modules']))
            throw new RuntimeException('Catálogo de treinamento inválido.');
        $seenModules=[];$seenLessons=[];
        foreach($decoded['modules'] as $m){
            $slug=(string)($m['slug']??'');
            if(!preg_match('/^[a-z0-9-]{3,80}$/D',$slug)||isset($seenModules[$slug])||
                !isset($m['lessons'])||!is_array($m['lessons']))
                throw new RuntimeException('Módulos do curso inválidos ou duplicados.');
            $seenModules[$slug]=true;
            $this->modules[$slug]=$m;
            foreach($m['lessons'] as $lesson){
                $key=(string)($lesson['slug']??'');
                if(!preg_match('/^[a-z0-9-]{3,100}$/D',$key)||isset($seenLessons[$key])||
                    !str_starts_with($key,$slug.'-')||
                    !isset($lesson['steps'])||!is_array($lesson['steps'])||count($lesson['steps'])<2)
                    throw new RuntimeException('Aulas do curso inválidas ou duplicadas.');
                $seenLessons[$key]=true;
                $this->lessons[$key]=['module'=>$slug,'lesson'=>$lesson];
            }
        }
        $this->catalog=$decoded;
    }

    public function catalog():array{return $this->catalog;}
    public function modules():array{return array_values($this->modules);}
    public function module(string $slug):?array{return $this->modules[$slug]??null;}
    public function lesson(string $slug):?array{return $this->lessons[$slug]??null;}
    public function lessonCount():int{return count($this->lessons);}
    public function moduleCount():int{return count($this->modules);}
    public function canTrack():bool{return $this->churchId!==null&&!$this->auth->isSuperAdmin();}

    private function schema():void
    {
        if(!$this->canTrack())return;
        $sql=file_get_contents(dirname(__DIR__).'/database/046_system_course_progress.sql');
        if(!is_string($sql))throw new RuntimeException('Migração de progresso do curso indisponível.');
        $this->pdo->exec($sql);
    }

    private function q(string $sql,array $params=[]):PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public function progress():array
    {
        if(!$this->canTrack())return [];
        if($this->progressCache!==null)return $this->progressCache;
        $this->schema();
        $rows=$this->q('SELECT lesson_slug FROM system_course_progress WHERE church_id=? AND user_id=?',
            [$this->churchId,$this->userId])->fetchAll(PDO::FETCH_COLUMN);
        $out=[];
        foreach($rows as $slug)if(isset($this->lessons[(string)$slug]))$out[(string)$slug]=true;
        return $this->progressCache=$out;
    }

    public function setCompleted(string $slug,bool $done):void
    {
        if(!$this->canTrack())throw new RuntimeException('O Master acompanha o conteúdo, mas o progresso é individual para os clientes.');
        if(!isset($this->lessons[$slug]))throw new InvalidArgumentException('Aula não existe no curso oficial.');
        $this->schema();
        if($done){
            $this->q('INSERT INTO system_course_progress(church_id,user_id,lesson_slug) VALUES(?,?,?)
              ON DUPLICATE KEY UPDATE completed_at=VALUES(completed_at)',
              [$this->churchId,$this->userId,$slug]);
        }else{
            $this->q('DELETE FROM system_course_progress WHERE church_id=? AND user_id=? AND lesson_slug=?',
                [$this->churchId,$this->userId,$slug]);
        }
        $this->progressCache=null;
    }

    public function completedCount(?string $moduleSlug=null):int
    {
        $progress=$this->progress();
        if($moduleSlug===null)return count($progress);
        $module=$this->module($moduleSlug);
        if(!$module)return 0;
        $n=0;
        foreach($module['lessons'] as $l)if(isset($progress[$l['slug']]))$n++;
        return $n;
    }

    public function nextLesson(string $current):?string
    {
        $keys=array_keys($this->lessons);
        $index=array_search($current,$keys,true);
        if($index===false)return null;
        return $keys[$index+1]??null;
    }

    public function lookup(string $query):array
    {
        $query=mb_strtolower(trim($query),'UTF-8');
        $synonyms=['contabilidade'=>'contador','criar membros'=>'cadastrar um membro',
            'criar usuario'=>'criar um usuário','gerar nota'=>'nota fiscal',
            'cadastro de igreja'=>'criar uma igreja','dizimo'=>'dízimo'];
        $query=$synonyms[$query]??$query;
        if($query==='')return array_keys($this->lessons);
        if(mb_strlen($query,'UTF-8')>100)throw new InvalidArgumentException('Busca muito longa.');
        $result=[];
        foreach($this->lessons as $key=>$entry){
            $module=$this->module($entry['module']);
            $lesson=$entry['lesson'];
            $text=implode(' ',[
                $module['title']??'',$module['summary']??'', $lesson['title']??'',
                implode(' ',$lesson['steps']??[]), $lesson['tip']??'', $lesson['warning']??''
            ]);
            if(mb_stripos($text,$query,0,'UTF-8')!==false)$result[]=$key;
        }
        return $result;
    }
}
