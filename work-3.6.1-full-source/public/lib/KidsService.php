<?php
declare(strict_types=1);

final class KidsService
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
        $m=$this->auth->currentMembership();
        if(!$m||($m['church_id']??'')!==$this->cid)return null;
        $id=trim((string)($m['person_id']??''));
        return $id!==''?$id:null;
    }

    public function assignedClassIds(): array
    {
        if($this->isManager()){
            return array_column($this->q('SELECT id FROM kids_classes WHERE church_id=?',[$this->cid])->fetchAll(),'id');
        }
        if($this->auth->churchRole()!=='kids_worker')return [];
        $person=$this->personId();
        if(!$person)return [];
        $q=$this->q('SELECT class_id FROM kids_team WHERE church_id=? AND member_id=? AND active=1',[$this->cid,$person]);
        return array_values(array_unique(array_map('strval',array_column($q->fetchAll(),'class_id'))));
    }

    public function classes(bool $includeInactive=false): array
    {
        $sql="SELECT c.*,
          (SELECT COUNT(*) FROM kids_rooms r WHERE r.church_id=c.church_id AND r.class_id=c.id AND r.active=1) room_count,
          (SELECT COUNT(*) FROM kids_team t WHERE t.church_id=c.church_id AND t.class_id=c.id AND t.active=1) team_count
          FROM kids_classes c WHERE c.church_id=?";
        $params=[$this->cid];
        if(!$includeInactive)$sql.=" AND c.active=1";
        if(!$this->isManager()){
            $ids=$this->assignedClassIds();
            if(!$ids)return [];
            $sql.=" AND c.id IN (".implode(',',array_fill(0,count($ids),'?')).")";
            $params=array_merge($params,$ids);
        }
        $sql.=" ORDER BY c.active DESC,c.name";
        return $this->q($sql,$params)->fetchAll();
    }

    public function class(string $id): array
    {
        foreach($this->classes($this->isManager()) as $row)if((string)$row['id']===$id)return $row;
        throw new RuntimeException('Turma Kids não disponível para este acesso.');
    }

    public function rooms(string $classId): array
    {
        $this->class($classId);
        return $this->q('SELECT * FROM kids_rooms WHERE church_id=? AND class_id=? ORDER BY active DESC,name',[$this->cid,$classId])->fetchAll();
    }

    public function team(string $classId): array
    {
        $this->class($classId);
        return $this->q("SELECT m.id,m.name,m.phone,m.email,t.access_level,t.active
          FROM kids_team t
          INNER JOIN members m ON m.id=t.member_id AND m.church_id=t.church_id
          WHERE t.church_id=? AND t.class_id=?
          ORDER BY t.active DESC,FIELD(t.access_level,'manager','leader','worker'),m.name",[$this->cid,$classId])->fetchAll();
    }

    public function saveClass(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode criar ou editar turmas Kids.');
        $id=trim((string)($input['id']??''));
        $name=trim((string)($input['name']??''));
        $min=$input['min_age_months']!==''&&isset($input['min_age_months'])?max(0,(int)$input['min_age_months']):null;
        $max=$input['max_age_months']!==''&&isset($input['max_age_months'])?max(0,(int)$input['max_age_months']):null;
        $description=trim((string)($input['description']??''))?:null;
        $active=isset($input['active'])?1:0;

        if($name===''||mb_strlen($name)>160)throw new InvalidArgumentException('Informe o nome da turma.');
        if($min!==null&&$max!==null&&$max<$min)throw new InvalidArgumentException('Faixa etária inválida.');
        if($description!==null&&mb_strlen($description)>1000)throw new InvalidArgumentException('Descrição muito longa.');

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO kids_classes(id,church_id,name,min_age_months,max_age_months,description,active) VALUES(?,?,?,?,?,?,?)',[
                $id,$this->cid,$name,$min,$max,$description,$active
            ]);
        }else{
            $q=$this->q('UPDATE kids_classes SET name=?,min_age_months=?,max_age_months=?,description=?,active=? WHERE id=? AND church_id=?',[
                $name,$min,$max,$description,$active,$id,$this->cid
            ]);
            if($q->rowCount()===0&&!$this->q('SELECT 1 FROM kids_classes WHERE id=? AND church_id=?',[$id,$this->cid])->fetchColumn()){
                throw new RuntimeException('Turma não encontrada.');
            }
        }
        $this->audit('kids_class_saved',['class_id'=>$id,'name'=>$name]);
        return $id;
    }

    public function saveRoom(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode criar ou editar salas.');
        $id=trim((string)($input['id']??''));
        $classId=trim((string)($input['class_id']??''));
        $name=trim((string)($input['name']??''));
        $location=trim((string)($input['location_name']??''))?:null;
        $capacity=trim((string)($input['capacity']??''))!==''?max(1,(int)$input['capacity']):null;
        $active=isset($input['active'])?1:0;
        $this->class($classId);
        if($name==='')throw new InvalidArgumentException('Informe o nome da sala.');

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO kids_rooms(id,church_id,class_id,name,location_name,capacity,active) VALUES(?,?,?,?,?,?,?)',[
                $id,$this->cid,$classId,$name,$location,$capacity,$active
            ]);
        }else{
            $q=$this->q('UPDATE kids_rooms SET class_id=?,name=?,location_name=?,capacity=?,active=? WHERE id=? AND church_id=?',[
                $classId,$name,$location,$capacity,$active,$id,$this->cid
            ]);
            if($q->rowCount()===0&&!$this->q('SELECT 1 FROM kids_rooms WHERE id=? AND church_id=?',[$id,$this->cid])->fetchColumn()){
                throw new RuntimeException('Sala não encontrada.');
            }
        }
        $this->audit('kids_room_saved',['room_id'=>$id,'class_id'=>$classId]);
        return $id;
    }

    public function saveTeam(string $classId,array $rows): void
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode definir a equipe Kids.');
        $this->class($classId);

        $normalized=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $memberId=trim((string)($row['member_id']??''));
            if($memberId==='')continue;
            $level=(string)($row['access_level']??'worker');
            if(!in_array($level,['manager','leader','worker'],true))$level='worker';
            $normalized[$memberId]=$level;
        }

        foreach($normalized as $memberId=>$level){
            if(!$this->q('SELECT 1 FROM members WHERE id=? AND church_id=? AND active=1',[$memberId,$this->cid])->fetchColumn()){
                throw new RuntimeException('Um integrante da equipe não foi encontrado nesta igreja.');
            }
        }

        $this->pdo->beginTransaction();
        try{
            $this->q('DELETE FROM kids_team WHERE church_id=? AND class_id=?',[$this->cid,$classId]);
            foreach($normalized as $memberId=>$level){
                $this->q('INSERT INTO kids_team(church_id,class_id,member_id,access_level,active) VALUES(?,?,?,?,1)',[
                    $this->cid,$classId,$memberId,$level
                ]);
            }
            $this->audit('kids_team_updated',['class_id'=>$classId,'members'=>array_keys($normalized)]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function children(string $search=''): array
    {
        $sql="SELECT c.*,
          (SELECT g.full_name FROM kids_guardians g WHERE g.church_id=c.church_id AND g.child_id=c.id ORDER BY g.primary_contact DESC,g.created_at LIMIT 1) guardian_name,
          (SELECT g.phone FROM kids_guardians g WHERE g.church_id=c.church_id AND g.child_id=c.id ORDER BY g.primary_contact DESC,g.created_at LIMIT 1) guardian_phone
          FROM kids_children c WHERE c.church_id=? AND c.active=1";
        $params=[$this->cid];
        if($search!==''){
            $sql.=" AND (c.full_name LIKE ? OR EXISTS(SELECT 1 FROM kids_guardians g2 WHERE g2.church_id=c.church_id AND g2.child_id=c.id AND (g2.full_name LIKE ? OR g2.phone LIKE ?)))";
            $like='%'.$search.'%';array_push($params,$like,$like,$like);
        }
        $sql.=" ORDER BY c.full_name LIMIT 500";
        return $this->q($sql,$params)->fetchAll();
    }

    public function child(string $id): array
    {
        $q=$this->q('SELECT * FROM kids_children WHERE id=? AND church_id=? LIMIT 1',[$id,$this->cid]);
        $row=$q->fetch();
        if(!$row)throw new RuntimeException('Criança não encontrada.');
        return $row;
    }

    public function guardians(string $childId): array
    {
        $this->child($childId);
        return $this->q('SELECT * FROM kids_guardians WHERE church_id=? AND child_id=? ORDER BY primary_contact DESC,full_name',[$this->cid,$childId])->fetchAll();
    }

    public function saveChild(array $input): string
    {
        if(!$this->isManager()&&$this->auth->churchRole()!=='kids_worker')throw new RuntimeException('Acesso não permitido.');
        $id=trim((string)($input['id']??''));
        $name=trim((string)($input['full_name']??''));
        $birth=$this->dateOrNull((string)($input['birth_date']??''));
        $gender=trim((string)($input['gender']??''))?:null;
        $allergies=trim((string)($input['allergies']??''))?:null;
        $needs=trim((string)($input['special_needs']??''))?:null;
        $notes=trim((string)($input['notes']??''))?:null;
        $image=isset($input['image_authorized'])?1:0;
        $memberId=trim((string)($input['member_id']??''))?:null;

        if($name===''||mb_strlen($name)>190)throw new InvalidArgumentException('Informe o nome da criança.');
        if($memberId&&!$this->q('SELECT 1 FROM members WHERE id=? AND church_id=?',[$memberId,$this->cid])->fetchColumn()){
            throw new RuntimeException('Cadastro de membro vinculado não encontrado.');
        }

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO kids_children(id,church_id,member_id,full_name,birth_date,gender,allergies,special_needs,image_authorized,notes,active) VALUES(?,?,?,?,?,?,?,?,?,?,1)',[
                $id,$this->cid,$memberId,$name,$birth,$gender,$allergies,$needs,$image,$notes
            ]);
        }else{
            $this->child($id);
            $this->q('UPDATE kids_children SET member_id=?,full_name=?,birth_date=?,gender=?,allergies=?,special_needs=?,image_authorized=?,notes=? WHERE id=? AND church_id=?',[
                $memberId,$name,$birth,$gender,$allergies,$needs,$image,$notes,$id,$this->cid
            ]);
        }
        $this->audit('kids_child_saved',['child_id'=>$id,'name'=>$name]);
        return $id;
    }

    public function saveGuardian(string $childId,array $input): string
    {
        if(!$this->isManager()&&$this->auth->churchRole()!=='kids_worker')throw new RuntimeException('Acesso não permitido.');
        $this->child($childId);
        $id=trim((string)($input['id']??''));
        $name=trim((string)($input['full_name']??''));
        $phone=trim((string)($input['phone']??''));
        $relationship=trim((string)($input['relationship_name']??''))?:null;
        $primary=isset($input['primary_contact'])?1:0;
        $pickup=isset($input['authorized_pickup'])?1:0;
        $memberId=trim((string)($input['member_id']??''))?:null;

        if($name===''||$phone==='')throw new InvalidArgumentException('Informe nome e telefone do responsável.');

        if($primary)$this->q('UPDATE kids_guardians SET primary_contact=0 WHERE church_id=? AND child_id=?',[$this->cid,$childId]);

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO kids_guardians(id,church_id,child_id,member_id,full_name,phone,relationship_name,primary_contact,authorized_pickup) VALUES(?,?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$childId,$memberId,$name,$phone,$relationship,$primary,$pickup
            ]);
        }else{
            $this->q('UPDATE kids_guardians SET member_id=?,full_name=?,phone=?,relationship_name=?,primary_contact=?,authorized_pickup=? WHERE id=? AND church_id=? AND child_id=?',[
                $memberId,$name,$phone,$relationship,$primary,$pickup,$id,$this->cid,$childId
            ]);
        }
        $this->audit('kids_guardian_saved',['child_id'=>$childId,'guardian_id'=>$id]);
        return $id;
    }

    public function programs(bool $futureOnly=false): array
    {
        $sql="SELECT p.*,c.name class_name,r.name room_name,r.capacity,
          (SELECT COUNT(*) FROM kids_checkins ci WHERE ci.church_id=p.church_id AND ci.program_id=p.id AND ci.status='checked_in') checked_in_count,
          (SELECT COUNT(*) FROM kids_checkin_requests qr WHERE qr.church_id=p.church_id AND qr.program_id=p.id AND qr.status='pending') pending_requests
          FROM kids_programs p
          INNER JOIN kids_classes c ON c.id=p.class_id AND c.church_id=p.church_id
          INNER JOIN kids_rooms r ON r.id=p.room_id AND r.church_id=p.church_id
          WHERE p.church_id=?";
        $params=[$this->cid];
        if($futureOnly)$sql.=" AND p.program_date>=CURDATE()";
        if(!$this->isManager()){
            $ids=$this->assignedClassIds();
            if(!$ids)return [];
            $sql.=" AND p.class_id IN (".implode(',',array_fill(0,count($ids),'?')).")";
            $params=array_merge($params,$ids);
        }
        $sql.=" ORDER BY p.program_date DESC,COALESCE(p.start_time,'00:00:00') DESC,p.created_at DESC LIMIT 300";
        return $this->q($sql,$params)->fetchAll();
    }

    public function program(string $id): array
    {
        foreach($this->programs(false) as $row)if((string)$row['id']===$id)return $row;
        throw new RuntimeException('Programação Kids não disponível para este acesso.');
    }

    public function saveProgram(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode criar programações Kids.');
        $id=trim((string)($input['id']??''));
        $classId=trim((string)($input['class_id']??''));
        $roomId=trim((string)($input['room_id']??''));
        $title=trim((string)($input['title']??''));
        $date=$this->dateOrNull((string)($input['program_date']??''));
        $start=$this->timeOrNull((string)($input['start_time']??''));
        $end=$this->timeOrNull((string)($input['end_time']??''));
        $status=(string)($input['status']??'draft');
        $notes=trim((string)($input['notes']??''))?:null;

        if(!$date||$title===''||$classId===''||$roomId==='')throw new InvalidArgumentException('Informe turma, sala, título e data.');
        if(!in_array($status,['draft','open','closed'],true))$status='draft';
        $this->class($classId);
        if(!$this->q('SELECT 1 FROM kids_rooms WHERE id=? AND church_id=? AND class_id=? AND active=1',[$roomId,$this->cid,$classId])->fetchColumn()){
            throw new RuntimeException('Sala não pertence à turma selecionada.');
        }

        if($id===''){
            $id=app_uuid();
            $token=bin2hex(random_bytes(16));
            $this->q('INSERT INTO kids_programs(id,church_id,class_id,room_id,title,program_date,start_time,end_time,status,public_token,notes,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$classId,$roomId,$title,$date,$start,$end,$status,$token,$notes,$this->auth->requireLogin()['id']
            ]);
        }else{
            $this->program($id);
            $this->q('UPDATE kids_programs SET class_id=?,room_id=?,title=?,program_date=?,start_time=?,end_time=?,status=?,notes=? WHERE id=? AND church_id=?',[
                $classId,$roomId,$title,$date,$start,$end,$status,$notes,$id,$this->cid
            ]);
        }
        $this->audit('kids_program_saved',['program_id'=>$id,'title'=>$title]);
        return $id;
    }

    public function currentCheckins(string $programId): array
    {
        $this->program($programId);
        return $this->q("SELECT ci.*,c.full_name child_name,c.birth_date,
          g.full_name pickup_guardian_name
          FROM kids_checkins ci
          INNER JOIN kids_children c ON c.id=ci.child_id AND c.church_id=ci.church_id
          LEFT JOIN kids_guardians g ON g.id=ci.pickup_guardian_id AND g.church_id=ci.church_id
          WHERE ci.church_id=? AND ci.program_id=?
          ORDER BY FIELD(ci.status,'checked_in','checked_out'),ci.checkin_at DESC",[$this->cid,$programId])->fetchAll();
    }

    public function checkin(string $programId,string $childId,?string $notes=null): array
    {
        $program=$this->program($programId);
        if($program['status']!=='open')throw new RuntimeException('A programação precisa estar aberta para realizar check-in.');
        $this->child($childId);

        $existing=$this->q('SELECT * FROM kids_checkins WHERE church_id=? AND program_id=? AND child_id=? LIMIT 1',[
            $this->cid,$programId,$childId
        ])->fetch();
        if($existing){
            if($existing['status']==='checked_in')return $existing;
            throw new RuntimeException('Esta criança já possui registro nesta programação.');
        }

        if(!empty($program['capacity'])){
            $count=(int)$this->q("SELECT COUNT(*) FROM kids_checkins WHERE church_id=? AND program_id=? AND status='checked_in'",[
                $this->cid,$programId
            ])->fetchColumn();
            if($count>=(int)$program['capacity'])throw new RuntimeException('A sala atingiu a capacidade máxima.');
        }

        $id=app_uuid();
        $code=(string)random_int(100000,999999);
        $this->q("INSERT INTO kids_checkins(id,church_id,program_id,child_id,room_id,checked_in_by_user_id,security_code,status,notes)
          VALUES(?,?,?,?,?,?,?,'checked_in',?)",[
            $id,$this->cid,$programId,$childId,$program['room_id'],$this->auth->requireLogin()['id'],$code,$notes
        ]);
        $this->audit('kids_checkin',['program_id'=>$programId,'child_id'=>$childId,'checkin_id'=>$id]);
        return $this->q('SELECT * FROM kids_checkins WHERE id=?',[$id])->fetch();
    }

    public function checkout(string $checkinId,string $securityCode,string $guardianId): void
    {
        $q=$this->q("SELECT ci.*,p.class_id FROM kids_checkins ci
          INNER JOIN kids_programs p ON p.id=ci.program_id AND p.church_id=ci.church_id
          WHERE ci.id=? AND ci.church_id=? LIMIT 1",[$checkinId,$this->cid]);
        $row=$q->fetch();
        if(!$row)throw new RuntimeException('Check-in não encontrado.');
        $this->program((string)$row['program_id']);
        if($row['status']!=='checked_in')throw new RuntimeException('A criança já realizou checkout.');
        if(!hash_equals((string)$row['security_code'],trim($securityCode)))throw new RuntimeException('Código de segurança incorreto.');

        $g=$this->q('SELECT * FROM kids_guardians WHERE id=? AND church_id=? AND child_id=? AND authorized_pickup=1 LIMIT 1',[
            $guardianId,$this->cid,$row['child_id']
        ])->fetch();
        if(!$g)throw new RuntimeException('Responsável não autorizado para retirada.');

        $this->q("UPDATE kids_checkins SET checkout_at=NOW(),checked_out_by_user_id=?,pickup_guardian_id=?,status='checked_out' WHERE id=? AND church_id=?",[
            $this->auth->requireLogin()['id'],$guardianId,$checkinId,$this->cid
        ]);
        $this->audit('kids_checkout',['checkin_id'=>$checkinId,'guardian_id'=>$guardianId]);
    }

    public function saveRecord(string $programId,array $input): string
    {
        $this->program($programId);
        $type=(string)($input['record_type']??'general');
        if(!in_array($type,['general','pedagogical','behavior','incident'],true))$type='general';
        $content=trim((string)($input['content']??''));
        $childId=trim((string)($input['child_id']??''))?:null;
        $media=trim((string)($input['media_item_id']??''))?:null;
        $visible=isset($input['visible_to_guardian'])?1:0;

        if($content==='')throw new InvalidArgumentException('Informe o registro.');
        if($childId)$this->child($childId);
        if($media&&!$this->q('SELECT 1 FROM media_library_items WHERE id=? AND church_id=? AND active=1',[$media,$this->cid])->fetchColumn()){
            throw new RuntimeException('Mídia não encontrada.');
        }

        $id=app_uuid();
        $this->q('INSERT INTO kids_records(id,church_id,program_id,child_id,record_type,content,media_item_id,visible_to_guardian,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?)',[
            $id,$this->cid,$programId,$childId,$type,$content,$media,$visible,$this->auth->requireLogin()['id']
        ]);
        $this->audit('kids_record_created',['record_id'=>$id,'program_id'=>$programId,'child_id'=>$childId]);
        return $id;
    }

    public function records(string $programId): array
    {
        $this->program($programId);
        return $this->q("SELECT r.*,c.full_name child_name,m.title media_title,m.storage_path media_storage,m.external_url media_external
          FROM kids_records r
          LEFT JOIN kids_children c ON c.id=r.child_id AND c.church_id=r.church_id
          LEFT JOIN media_library_items m ON m.id=r.media_item_id AND m.church_id=r.church_id
          WHERE r.church_id=? AND r.program_id=?
          ORDER BY r.created_at DESC",[$this->cid,$programId])->fetchAll();
    }

    public function pendingRequests(string $programId): array
    {
        $this->program($programId);
        return $this->q("SELECT * FROM kids_checkin_requests WHERE church_id=? AND program_id=? AND status='pending' ORDER BY requested_at",[
            $this->cid,$programId
        ])->fetchAll();
    }

    public function approveRequest(string $requestId): array
    {
        $q=$this->q("SELECT r.*,p.id program_ok FROM kids_checkin_requests r
          INNER JOIN kids_programs p ON p.id=r.program_id AND p.church_id=r.church_id
          WHERE r.id=? AND r.church_id=? AND r.status='pending' LIMIT 1",[$requestId,$this->cid]);
        $req=$q->fetch();
        if(!$req)throw new RuntimeException('Solicitação pendente não encontrada.');
        $this->program((string)$req['program_id']);

        $childId=$req['child_id']?:null;
        if(!$childId){
            $childId=$this->saveChild([
                'full_name'=>$req['child_name'],
                'birth_date'=>$req['birth_date']??'',
                'gender'=>'',
                'allergies'=>'',
                'special_needs'=>'',
                'notes'=>'Visitante cadastrado a partir de solicitação pública.',
            ]);
            $this->saveGuardian($childId,[
                'full_name'=>$req['guardian_name'],
                'phone'=>$req['guardian_phone'],
                'relationship_name'=>'Responsável',
                'primary_contact'=>1,
                'authorized_pickup'=>1,
            ]);
        }

        $checkin=$this->checkin((string)$req['program_id'],(string)$childId,'Solicitado pelo check-in público.');
        $this->q("UPDATE kids_checkin_requests SET status='approved',child_id=?,reviewed_at=NOW(),reviewed_by_user_id=? WHERE id=? AND church_id=?",[
            $childId,$this->auth->requireLogin()['id'],$requestId,$this->cid
        ]);
        return $checkin;
    }

    public function rejectRequest(string $requestId): void
    {
        $q=$this->q("UPDATE kids_checkin_requests SET status='rejected',reviewed_at=NOW(),reviewed_by_user_id=? WHERE id=? AND church_id=? AND status='pending'",[
            $this->auth->requireLogin()['id'],$requestId,$this->cid
        ]);
        if($q->rowCount()===0)throw new RuntimeException('Solicitação pendente não encontrada.');
    }

    public static function publicProgram(PDO $pdo,string $token): ?array
    {
        $q=$pdo->prepare("SELECT p.*,c.name class_name,r.name room_name,r.capacity,ch.name church_name
          FROM kids_programs p
          INNER JOIN kids_classes c ON c.id=p.class_id AND c.church_id=p.church_id
          INNER JOIN kids_rooms r ON r.id=p.room_id AND r.church_id=p.church_id
          INNER JOIN churches ch ON ch.id=p.church_id
          WHERE p.public_token=? AND p.status='open' AND ch.active=1 LIMIT 1");
        $q->execute([$token]);
        return $q->fetch()?:null;
    }

    public static function requestPublicCheckin(PDO $pdo,array $program,array $input): string
    {
        $childName=trim((string)($input['child_name']??''));
        $birth=trim((string)($input['birth_date']??''))?:null;
        $guardianName=trim((string)($input['guardian_name']??''));
        $phone=trim((string)($input['guardian_phone']??''));
        $notes=trim((string)($input['notes']??''))?:null;

        if($childName===''||$guardianName===''||$phone==='')throw new InvalidArgumentException('Informe criança, responsável e telefone.');
        if($birth){
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$birth);
            if(!$d||$d->format('Y-m-d')!==$birth)throw new InvalidArgumentException('Data de nascimento inválida.');
        }

        $childId=null;$type='visitor';
        if($birth){
            $q=$pdo->prepare("SELECT c.id
              FROM kids_children c
              INNER JOIN kids_guardians g ON g.child_id=c.id AND g.church_id=c.church_id
              WHERE c.church_id=? AND c.active=1 AND c.birth_date=? AND REPLACE(REPLACE(REPLACE(g.phone,' ',''),'-',''),'(','') LIKE ?
              ORDER BY g.primary_contact DESC LIMIT 1");
            $digits=preg_replace('/\D+/','',$phone)?:$phone;
            $q->execute([$program['church_id'],$birth,'%'.substr($digits,-8).'%']);
            $childId=$q->fetchColumn()?:null;
            if($childId)$type='existing';
        }

        $id=app_uuid();
        $q=$pdo->prepare("INSERT INTO kids_checkin_requests(
          id,church_id,program_id,child_id,child_name,birth_date,guardian_name,guardian_phone,request_type,status,notes
        ) VALUES(?,?,?,?,?,?,?,?,?,'pending',?)");
        $q->execute([
            $id,$program['church_id'],$program['id'],$childId,$childName,$birth,$guardianName,$phone,$type,$notes
        ]);
        return $id;
    }

    private function dateOrNull(string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Data inválida.');
        return $value;
    }

    private function timeOrNull(string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value))throw new InvalidArgumentException('Horário inválido.');
        return $value.':00';
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
