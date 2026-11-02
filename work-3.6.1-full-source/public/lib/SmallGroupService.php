<?php
declare(strict_types=1);

/** Grupos/células com escopo por igreja e acesso restrito aos líderes vinculados. */
final class SmallGroupService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);
        $q->execute($params);
        return $q;
    }

    private function user(): array
    {
        return $this->auth->requireLogin();
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

    public function groups(bool $includeInactive=false): array
    {
        $sql="SELECT g.*,c.name congregation_name,
          (SELECT COUNT(*) FROM small_group_members gm WHERE gm.group_id=g.id AND gm.church_id=g.church_id AND gm.active=1) member_count,
          (SELECT COUNT(*) FROM small_group_meetings mt WHERE mt.group_id=g.id AND mt.church_id=g.church_id) meeting_count
          FROM small_groups g
          LEFT JOIN congregations c ON c.id=g.congregation_id AND c.church_id=g.church_id
          WHERE g.church_id=?";
        $params=[$this->cid];

        if(!$includeInactive)$sql.=" AND g.active=1";

        if(!$this->isManager()){
            if($this->auth->churchRole()!=='group_leader')return [];
            $person=$this->personId();
            if(!$person)return [];
            $sql.=" AND EXISTS(
                SELECT 1 FROM small_group_leaders gl
                WHERE gl.church_id=g.church_id AND gl.group_id=g.id AND gl.member_id=?
            )";
            $params[]=$person;
        }

        $sql.=" ORDER BY g.active DESC,g.name";
        return $this->q($sql,$params)->fetchAll();
    }

    public function group(string $id): array
    {
        foreach($this->groups(true) as $group){
            if((string)$group['id']===$id){
                if(!$this->isManager()&&!$group['active'])break;
                return $group;
            }
        }
        throw new RuntimeException('Grupo/célula não disponível para este acesso.');
    }

    public function leaders(string $groupId): array
    {
        $this->group($groupId);
        return $this->q("SELECT m.id,m.name,m.phone,m.email,gl.leader_order
          FROM small_group_leaders gl
          INNER JOIN members m ON m.id=gl.member_id AND m.church_id=gl.church_id
          WHERE gl.church_id=? AND gl.group_id=?
          ORDER BY gl.leader_order,m.name",[$this->cid,$groupId])->fetchAll();
    }

    public function members(string $groupId): array
    {
        $this->group($groupId);
        return $this->q("SELECT m.id,m.name,m.phone,m.email,gm.joined_on,gm.active
          FROM small_group_members gm
          INNER JOIN members m ON m.id=gm.member_id AND m.church_id=gm.church_id
          WHERE gm.church_id=? AND gm.group_id=? AND gm.active=1
          ORDER BY m.name",[$this->cid,$groupId])->fetchAll();
    }

    public function meetings(string $groupId,int $limit=50): array
    {
        $this->group($groupId);
        $limit=max(1,min($limit,200));
        return $this->q("SELECT mt.*,
          (SELECT COUNT(*) FROM small_group_attendance a WHERE a.meeting_id=mt.id AND a.church_id=mt.church_id AND a.present=1) present_count
          FROM small_group_meetings mt
          WHERE mt.church_id=? AND mt.group_id=?
          ORDER BY mt.meeting_date DESC,mt.created_at DESC
          LIMIT ".$limit,[$this->cid,$groupId])->fetchAll();
    }

    public function saveGroup(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode criar ou editar grupos.');

        $id=trim((string)($input['id']??''));
        $name=trim((string)($input['name']??''));
        $type=trim((string)($input['group_type']??'cell'));
        $congregation=trim((string)($input['congregation_id']??''))?:null;
        $day=trim((string)($input['meeting_day']??''))?:null;
        $time=trim((string)($input['meeting_time']??''))?:null;
        $location=trim((string)($input['location_name']??''))?:null;
        $address=trim((string)($input['address']??''))?:null;
        $notes=trim((string)($input['notes']??''))?:null;
        $active=isset($input['active'])?1:0;

        if($name===''||mb_strlen($name)>190)throw new InvalidArgumentException('Informe o nome do grupo/célula.');
        if(!in_array($type,['cell','small_group','discipleship','home_group','other'],true))$type='other';
        if($day!==null&&mb_strlen($day)>30)throw new InvalidArgumentException('Dia da reunião inválido.');
        if($location!==null&&mb_strlen($location)>190)throw new InvalidArgumentException('Local muito longo.');
        if($address!==null&&mb_strlen($address)>500)throw new InvalidArgumentException('Endereço muito longo.');
        if($notes!==null&&mb_strlen($notes)>5000)throw new InvalidArgumentException('Observações muito longas.');

        if($congregation){
            $q=$this->q('SELECT id FROM congregations WHERE id=? AND church_id=? AND active=1',[$congregation,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Congregação não encontrada nesta igreja.');
        }

        $user=$this->user();
        if($id===''){
            $id=app_uuid();
            $this->q("INSERT INTO small_groups(
                id,church_id,congregation_id,name,group_type,meeting_day,meeting_time,location_name,address,notes,active,created_by_user_id
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",[
                $id,$this->cid,$congregation,$name,$type,$day,$time,$location,$address,$notes,$active,$user['id']
            ]);
            $this->audit('small_group_created',['group_id'=>$id,'name'=>$name]);
        }else{
            $this->group($id);
            $this->q("UPDATE small_groups SET
                congregation_id=?,name=?,group_type=?,meeting_day=?,meeting_time=?,location_name=?,address=?,notes=?,active=?
                WHERE id=? AND church_id=?",[
                $congregation,$name,$type,$day,$time,$location,$address,$notes,$active,$id,$this->cid
            ]);
            $this->audit('small_group_updated',['group_id'=>$id,'name'=>$name]);
        }

        return $id;
    }

    public function saveLeaders(string $groupId,array $memberIds): void
    {
        if(!$this->isManager())throw new RuntimeException('Somente a administração pode definir líderes.');
        $this->group($groupId);

        $ids=[];
        foreach($memberIds as $memberId){
            $memberId=trim((string)$memberId);
            if($memberId!==''&&!in_array($memberId,$ids,true))$ids[]=$memberId;
        }
        if(count($ids)>4)throw new InvalidArgumentException('Cada grupo pode ter no máximo quatro líderes.');

        foreach($ids as $id){
            $q=$this->q('SELECT id FROM members WHERE id=? AND church_id=? AND active=1',[$id,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Um dos líderes selecionados não pertence a esta igreja.');
        }

        $this->pdo->beginTransaction();
        try{
            $this->q('DELETE FROM small_group_leaders WHERE church_id=? AND group_id=?',[$this->cid,$groupId]);
            foreach($ids as $index=>$id){
                $this->q('INSERT INTO small_group_leaders(church_id,group_id,member_id,leader_order) VALUES(?,?,?,?)',[
                    $this->cid,$groupId,$id,$index+1
                ]);
            }
            $this->audit('small_group_leaders_updated',['group_id'=>$groupId,'leaders'=>$ids]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function enroll(string $groupId,string $memberId): void
    {
        $this->group($groupId);
        if(!$this->isManager()&&$this->auth->churchRole()!=='group_leader'){
            throw new RuntimeException('Acesso não permitido.');
        }

        $q=$this->q('SELECT id FROM members WHERE id=? AND church_id=? AND active=1',[$memberId,$this->cid]);
        if(!$q->fetchColumn())throw new RuntimeException('Membro não encontrado nesta igreja.');

        $this->q("INSERT INTO small_group_members(church_id,group_id,member_id,joined_on,active)
          VALUES(?,?,?,CURDATE(),1)
          ON DUPLICATE KEY UPDATE active=1,joined_on=COALESCE(joined_on,CURDATE())",[
            $this->cid,$groupId,$memberId
        ]);
        $this->audit('small_group_member_added',['group_id'=>$groupId,'member_id'=>$memberId]);
    }

    public function remove(string $groupId,string $memberId): void
    {
        $this->group($groupId);
        if(!$this->isManager()&&$this->auth->churchRole()!=='group_leader'){
            throw new RuntimeException('Acesso não permitido.');
        }
        $this->q('UPDATE small_group_members SET active=0 WHERE church_id=? AND group_id=? AND member_id=?',[
            $this->cid,$groupId,$memberId
        ]);
        $this->audit('small_group_member_removed',['group_id'=>$groupId,'member_id'=>$memberId]);
    }

    public function saveMeeting(string $groupId,array $input): string
    {
        $this->group($groupId);
        $date=trim((string)($input['meeting_date']??''));
        $theme=trim((string)($input['theme']??''))?:null;
        $notes=trim((string)($input['notes']??''))?:null;
        $visitors=max(0,min(999,(int)($input['visitor_count']??0)));
        $present=$input['present_member_ids']??[];

        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Informe uma data válida para a reunião.');
        if($theme!==null&&mb_strlen($theme)>255)throw new InvalidArgumentException('Tema muito longo.');
        if($notes!==null&&mb_strlen($notes)>5000)throw new InvalidArgumentException('Anotações muito longas.');
        if(!is_array($present))throw new InvalidArgumentException('Lista de presença inválida.');

        $allowed=array_column($this->members($groupId),'id');
        $present=array_values(array_unique(array_filter(array_map('strval',$present),fn($id)=>in_array($id,$allowed,true))));

        $user=$this->user();
        $id=app_uuid();

        $this->pdo->beginTransaction();
        try{
            $this->q('INSERT INTO small_group_meetings(
                id,church_id,group_id,meeting_date,theme,visitor_count,notes,created_by_user_id
            ) VALUES(?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$groupId,$date,$theme,$visitors,$notes,$user['id']
            ]);

            foreach($allowed as $memberId){
                $this->q('INSERT INTO small_group_attendance(church_id,meeting_id,member_id,present) VALUES(?,?,?,?)',[
                    $this->cid,$id,$memberId,in_array($memberId,$present,true)?1:0
                ]);
            }

            $this->audit('small_group_meeting_recorded',[
                'group_id'=>$groupId,'meeting_id'=>$id,'present'=>count($present),'visitors'=>$visitors
            ]);
            $this->pdo->commit();
            return $id;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function report(string $from,string $to): array
    {
        $from=$this->validDate($from);
        $to=$this->validDate($to);
        if($from>$to)throw new InvalidArgumentException('A data inicial deve ser anterior à final.');

        $rows=[];
        foreach($this->groups(true) as $group){
            $summary=$this->q("SELECT COUNT(*) meetings,COALESCE(SUM(visitor_count),0) visitors
              FROM small_group_meetings
              WHERE church_id=? AND group_id=? AND meeting_date BETWEEN ? AND ?",[
                $this->cid,$group['id'],$from,$to
            ])->fetch()?:['meetings'=>0,'visitors'=>0];

            $presence=(int)$this->q("SELECT COUNT(*)
              FROM small_group_attendance a
              INNER JOIN small_group_meetings mt ON mt.id=a.meeting_id AND mt.church_id=a.church_id
              WHERE a.church_id=? AND mt.group_id=? AND mt.meeting_date BETWEEN ? AND ? AND a.present=1",[
                $this->cid,$group['id'],$from,$to
            ])->fetchColumn();

            $rows[]=[
                'id'=>$group['id'],
                'name'=>$group['name'],
                'group_type'=>$group['group_type'],
                'active'=>$group['active'],
                'meetings'=>(int)$summary['meetings'],
                'visitors'=>(int)$summary['visitors'],
                'total_presence'=>$presence,
            ];
        }
        return $rows;
    }

    private function validDate(string $value): string
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Data inválida.');
        return $value;
    }

    private function audit(string $action,array $details=[]): void
    {
        try{
            $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[
                app_uuid(),$this->user()['id'],$this->cid,$action,
                json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        }catch(Throwable){}
    }
}
