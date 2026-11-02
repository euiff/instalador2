<?php
declare(strict_types=1);

final class LearningService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public function isManager(): bool
    {
        return $this->auth->can('secretary');
    }

    public function personId(): ?string
    {
        $membership=$this->auth->currentMembership();
        if(!$membership||($membership['church_id']??'')!==$this->cid)return null;
        $id=trim((string)($membership['person_id']??''));
        return $id!==''?$id:null;
    }

    public function courses(bool $includeInactive=false): array
    {
        $sql='SELECT * FROM learning_courses WHERE church_id=?';
        $params=[$this->cid];
        if(!$includeInactive)$sql.=' AND active=1';
        $sql.=' ORDER BY active DESC,title';
        return $this->q($sql,$params)->fetchAll();
    }

    public function classes(bool $includeInactive=false): array
    {
        $sql="SELECT c.*,co.title course_title,co.category course_category,cg.name congregation_name,
          (SELECT COUNT(*) FROM learning_enrollments e WHERE e.class_id=c.id AND e.church_id=c.church_id AND e.status<>'cancelled') student_count,
          (SELECT COUNT(*) FROM learning_lessons l WHERE l.class_id=c.id AND l.church_id=c.church_id) lesson_count
          FROM learning_classes c
          INNER JOIN learning_courses co ON co.id=c.course_id AND co.church_id=c.church_id
          LEFT JOIN congregations cg ON cg.id=c.congregation_id AND cg.church_id=c.church_id
          WHERE c.church_id=?";
        $params=[$this->cid];

        if(!$includeInactive)$sql.=" AND c.status<>'cancelled'";

        if(!$this->isManager()){
            $person=$this->personId();
            if(!$person)return [];
            if($this->auth->churchRole()==='teacher'){
                $sql.=" AND EXISTS(
                  SELECT 1 FROM learning_class_teachers t
                  WHERE t.church_id=c.church_id AND t.class_id=c.id AND t.member_id=?
                )";
            }else{
                $sql.=" AND EXISTS(
                  SELECT 1 FROM learning_enrollments e
                  WHERE e.church_id=c.church_id AND e.class_id=c.id AND e.member_id=? AND e.status<>'cancelled'
                )";
            }
            $params[]=$person;
        }

        $sql.=" ORDER BY FIELD(c.status,'in_progress','open','planned','completed','cancelled'),COALESCE(c.start_date,'9999-12-31'),c.name";
        return $this->q($sql,$params)->fetchAll();
    }

    public function class(string $id): array
    {
        foreach($this->classes($this->isManager()) as $row){
            if((string)$row['id']===$id)return $row;
        }
        throw new RuntimeException('Turma não disponível para este acesso.');
    }

    public function teachers(string $classId): array
    {
        $this->class($classId);
        return $this->q("SELECT m.id,m.name,m.phone,m.email,t.teacher_order
          FROM learning_class_teachers t
          INNER JOIN members m ON m.id=t.member_id AND m.church_id=t.church_id
          WHERE t.church_id=? AND t.class_id=?
          ORDER BY t.teacher_order,m.name",[$this->cid,$classId])->fetchAll();
    }

    public function students(string $classId): array
    {
        $this->class($classId);
        return $this->q("SELECT m.id,m.name,m.phone,m.email,e.status,e.enrolled_on,e.completed_on,e.notes,
          (SELECT COUNT(*) FROM learning_lessons l WHERE l.church_id=e.church_id AND l.class_id=e.class_id) lesson_count,
          (SELECT COUNT(*) FROM learning_attendance a
            INNER JOIN learning_lessons l2 ON l2.id=a.lesson_id AND l2.church_id=a.church_id
            WHERE a.church_id=e.church_id AND l2.class_id=e.class_id AND a.member_id=e.member_id AND a.present=1) present_count
          FROM learning_enrollments e
          INNER JOIN members m ON m.id=e.member_id AND m.church_id=e.church_id
          WHERE e.church_id=? AND e.class_id=?
          ORDER BY FIELD(e.status,'enrolled','completed','cancelled'),m.name",[$this->cid,$classId])->fetchAll();
    }

    public function lessons(string $classId): array
    {
        $this->class($classId);
        return $this->q("SELECT l.*,
          (SELECT COUNT(*) FROM learning_attendance a WHERE a.church_id=l.church_id AND a.lesson_id=l.id AND a.present=1) present_count
          FROM learning_lessons l
          WHERE l.church_id=? AND l.class_id=?
          ORDER BY l.lesson_date DESC,l.created_at DESC",[$this->cid,$classId])->fetchAll();
    }

    public function saveCourse(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode criar ou editar cursos.');
        $id=trim((string)($input['id']??''));
        $title=trim((string)($input['title']??''));
        $category=trim((string)($input['category']??''))?:null;
        $description=trim((string)($input['description']??''))?:null;
        $duration=trim((string)($input['duration_hours']??''))?:null;
        $active=isset($input['active'])?1:0;

        if($title===''||mb_strlen($title)>190)throw new InvalidArgumentException('Informe o nome do curso/estudo.');
        if($category!==null&&mb_strlen($category)>120)throw new InvalidArgumentException('Categoria muito longa.');
        if($description!==null&&mb_strlen($description)>10000)throw new InvalidArgumentException('Descrição muito longa.');
        if($duration!==null&&(!is_numeric(str_replace(',','.',$duration))||(float)str_replace(',','.',$duration)<0))throw new InvalidArgumentException('Carga horária inválida.');
        if($duration!==null)$duration=number_format((float)str_replace(',','.',$duration),2,'.','');

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO learning_courses(id,church_id,title,category,description,duration_hours,active,created_by_user_id) VALUES(?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$title,$category,$description,$duration,$active,$this->auth->requireLogin()['id']
            ]);
            $this->audit('learning_course_created',['course_id'=>$id,'title'=>$title]);
        }else{
            $q=$this->q('SELECT id FROM learning_courses WHERE id=? AND church_id=?',[$id,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Curso não encontrado.');
            $this->q('UPDATE learning_courses SET title=?,category=?,description=?,duration_hours=?,active=? WHERE id=? AND church_id=?',[
                $title,$category,$description,$duration,$active,$id,$this->cid
            ]);
            $this->audit('learning_course_updated',['course_id'=>$id,'title'=>$title]);
        }
        return $id;
    }

    public function saveClass(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode criar ou editar turmas.');
        $id=trim((string)($input['id']??''));
        $courseId=trim((string)($input['course_id']??''));
        $name=trim((string)($input['name']??''));
        $congregation=trim((string)($input['congregation_id']??''))?:null;
        $start=$this->dateOrNull((string)($input['start_date']??''));
        $end=$this->dateOrNull((string)($input['end_date']??''));
        $meeting=trim((string)($input['meeting_info']??''))?:null;
        $capacity=trim((string)($input['capacity']??''))!==''?max(1,(int)$input['capacity']):null;
        $status=(string)($input['status']??'open');
        $notes=trim((string)($input['notes']??''))?:null;

        if($courseId===''||$name==='')throw new InvalidArgumentException('Selecione o curso e informe o nome da turma.');
        if(!in_array($status,['planned','open','in_progress','completed','cancelled'],true))$status='open';
        if($start&&$end&&$end<$start)throw new InvalidArgumentException('A data final deve ser posterior à inicial.');
        if($meeting!==null&&mb_strlen($meeting)>255)throw new InvalidArgumentException('Informação de encontro muito longa.');
        if($notes!==null&&mb_strlen($notes)>5000)throw new InvalidArgumentException('Observações muito longas.');

        $q=$this->q('SELECT id FROM learning_courses WHERE id=? AND church_id=? AND active=1',[$courseId,$this->cid]);
        if(!$q->fetchColumn())throw new RuntimeException('Curso não encontrado nesta igreja.');

        if($congregation){
            $q=$this->q('SELECT id FROM congregations WHERE id=? AND church_id=? AND active=1',[$congregation,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Congregação não encontrada.');
        }

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO learning_classes(id,church_id,course_id,congregation_id,name,start_date,end_date,meeting_info,capacity,status,notes,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$courseId,$congregation,$name,$start,$end,$meeting,$capacity,$status,$notes,$this->auth->requireLogin()['id']
            ]);
            $this->audit('learning_class_created',['class_id'=>$id,'name'=>$name]);
        }else{
            $this->class($id);
            $this->q('UPDATE learning_classes SET course_id=?,congregation_id=?,name=?,start_date=?,end_date=?,meeting_info=?,capacity=?,status=?,notes=? WHERE id=? AND church_id=?',[
                $courseId,$congregation,$name,$start,$end,$meeting,$capacity,$status,$notes,$id,$this->cid
            ]);
            $this->audit('learning_class_updated',['class_id'=>$id,'name'=>$name]);
        }
        return $id;
    }

    public function saveTeachers(string $classId,array $memberIds): void
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode definir professores.');
        $this->class($classId);
        $ids=[];
        foreach($memberIds as $id){
            $id=trim((string)$id);
            if($id!==''&&!in_array($id,$ids,true))$ids[]=$id;
        }
        if(count($ids)>4)throw new InvalidArgumentException('Cada turma pode ter no máximo quatro professores/líderes.');
        foreach($ids as $id){
            $q=$this->q('SELECT id FROM members WHERE id=? AND church_id=? AND active=1',[$id,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Professor não encontrado nesta igreja.');
        }

        $this->pdo->beginTransaction();
        try{
            $this->q('DELETE FROM learning_class_teachers WHERE church_id=? AND class_id=?',[$this->cid,$classId]);
            foreach($ids as $index=>$id){
                $this->q('INSERT INTO learning_class_teachers(church_id,class_id,member_id,teacher_order) VALUES(?,?,?,?)',[
                    $this->cid,$classId,$id,$index+1
                ]);
            }
            $this->audit('learning_teachers_updated',['class_id'=>$classId,'teachers'=>$ids]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function enroll(string $classId,string $memberId): void
    {
        $class=$this->class($classId);
        if(!$this->canManageClass($classId))throw new RuntimeException('Você não pode matricular alunos nesta turma.');

        $q=$this->q('SELECT id FROM members WHERE id=? AND church_id=? AND active=1',[$memberId,$this->cid]);
        if(!$q->fetchColumn())throw new RuntimeException('Membro não encontrado.');

        if(!empty($class['capacity'])){
            $q=$this->q("SELECT COUNT(*) FROM learning_enrollments WHERE church_id=? AND class_id=? AND status<>'cancelled'",[$this->cid,$classId]);
            if((int)$q->fetchColumn()>=(int)$class['capacity'])throw new RuntimeException('Esta turma já atingiu a capacidade definida.');
        }

        $this->q("INSERT INTO learning_enrollments(church_id,class_id,member_id,status,enrolled_on)
          VALUES(?,?,?,'enrolled',CURDATE())
          ON DUPLICATE KEY UPDATE status='enrolled',completed_on=NULL",[$this->cid,$classId,$memberId]);
        $this->audit('learning_student_enrolled',['class_id'=>$classId,'member_id'=>$memberId]);
    }

    public function setEnrollmentStatus(string $classId,string $memberId,string $status): void
    {
        if(!$this->canManageClass($classId))throw new RuntimeException('Você não pode alterar alunos desta turma.');
        if(!in_array($status,['enrolled','completed','cancelled'],true))throw new InvalidArgumentException('Situação inválida.');
        $completed=$status==='completed'?date('Y-m-d'):null;
        $q=$this->q('UPDATE learning_enrollments SET status=?,completed_on=? WHERE church_id=? AND class_id=? AND member_id=?',[
            $status,$completed,$this->cid,$classId,$memberId
        ]);
        if($q->rowCount()===0)throw new RuntimeException('Matrícula não encontrada.');
        $this->audit('learning_enrollment_status',['class_id'=>$classId,'member_id'=>$memberId,'status'=>$status]);
    }

    public function saveLesson(string $classId,array $input): string
    {
        if(!$this->canManageClass($classId))throw new RuntimeException('Você não pode registrar aulas nesta turma.');
        $title=trim((string)($input['title']??''));
        $date=$this->dateOrNull((string)($input['lesson_date']??''));
        $description=trim((string)($input['description']??''))?:null;
        $material=trim((string)($input['material_url']??''))?:null;
        $present=$input['present_member_ids']??[];

        if($title===''||!$date)throw new InvalidArgumentException('Informe título e data da aula.');
        if(mb_strlen($title)>190)throw new InvalidArgumentException('Título muito longo.');
        if($description!==null&&mb_strlen($description)>10000)throw new InvalidArgumentException('Descrição muito longa.');
        if($material!==null&&!filter_var($material,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Link do material inválido.');
        if(!is_array($present))throw new InvalidArgumentException('Lista de presença inválida.');

        $students=$this->students($classId);
        $allowed=array_values(array_map('strval',array_column(array_filter($students,fn($s)=>$s['status']!=='cancelled'),'id')));
        $present=array_values(array_unique(array_filter(array_map('strval',$present),fn($id)=>in_array($id,$allowed,true))));

        $id=app_uuid();
        $this->pdo->beginTransaction();
        try{
            $this->q('INSERT INTO learning_lessons(id,church_id,class_id,title,lesson_date,description,material_url,created_by_user_id) VALUES(?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$classId,$title,$date,$description,$material,$this->auth->requireLogin()['id']
            ]);
            foreach($allowed as $memberId){
                $this->q('INSERT INTO learning_attendance(church_id,lesson_id,member_id,present) VALUES(?,?,?,?)',[
                    $this->cid,$id,$memberId,in_array($memberId,$present,true)?1:0
                ]);
            }
            $this->audit('learning_lesson_created',['class_id'=>$classId,'lesson_id'=>$id,'present'=>count($present)]);
            $this->pdo->commit();
            return $id;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function myProgress(string $classId): ?array
    {
        $class=$this->class($classId);
        $person=$this->personId();
        if(!$person)return null;

        $enrollment=$this->q("SELECT * FROM learning_enrollments
          WHERE church_id=? AND class_id=? AND member_id=? AND status<>'cancelled' LIMIT 1",[
            $this->cid,$classId,$person
        ])->fetch();
        if(!$enrollment)return null;

        $lessonCount=(int)$this->q('SELECT COUNT(*) FROM learning_lessons WHERE church_id=? AND class_id=?',[
            $this->cid,$classId
        ])->fetchColumn();

        $presentCount=(int)$this->q("SELECT COUNT(*)
          FROM learning_attendance a
          INNER JOIN learning_lessons l ON l.id=a.lesson_id AND l.church_id=a.church_id
          WHERE a.church_id=? AND l.class_id=? AND a.member_id=? AND a.present=1",[
            $this->cid,$classId,$person
        ])->fetchColumn();

        $attendance=[];
        $q=$this->q("SELECT l.id lesson_id,l.lesson_date,l.title,a.present
          FROM learning_lessons l
          LEFT JOIN learning_attendance a ON a.lesson_id=l.id AND a.church_id=l.church_id AND a.member_id=?
          WHERE l.church_id=? AND l.class_id=?
          ORDER BY l.lesson_date DESC,l.created_at DESC",[
            $person,$this->cid,$classId
        ]);
        foreach($q->fetchAll() as $row)$attendance[$row['lesson_id']]=$row;

        return [
            'class'=>$class,
            'enrollment'=>$enrollment,
            'lesson_count'=>$lessonCount,
            'present_count'=>$presentCount,
            'attendance'=>$attendance,
            'percentage'=>$lessonCount>0?round(($presentCount/$lessonCount)*100,1):0,
        ];
    }

    public function canManageClass(string $classId): bool
    {
        if($this->isManager())return true;
        if($this->auth->churchRole()!=='teacher')return false;
        $person=$this->personId();
        if(!$person)return false;
        $q=$this->q('SELECT 1 FROM learning_class_teachers WHERE church_id=? AND class_id=? AND member_id=?',[
            $this->cid,$classId,$person
        ]);
        return (bool)$q->fetchColumn();
    }

    private function dateOrNull(string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Data inválida.');
        return $value;
    }

    private function audit(string $action,array $details=[]): void
    {
        try{
            $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[
                app_uuid(),$this->auth->requireLogin()['id'],$this->cid,$action,
                json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        }catch(Throwable){}
    }
}
