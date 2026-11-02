<?php
declare(strict_types=1);

final class AnnouncementService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    private function user(): array
    {
        return $this->auth->requireLogin();
    }

    public function all(): array
    {
        $q=$this->q("SELECT a.*,
          (SELECT COUNT(*) FROM church_announcement_reads r WHERE r.announcement_id=a.id AND r.church_id=a.church_id) read_count,
          u.full_name created_by_name
          FROM church_announcements a
          LEFT JOIN users u ON u.id=a.created_by_user_id
          WHERE a.church_id=?
          ORDER BY a.active DESC,a.pinned DESC,a.created_at DESC",[$this->cid]);
        return $q->fetchAll();
    }

    public function save(array $input): string
    {
        if(!$this->auth->can('communications'))throw new RuntimeException('Você não possui permissão para publicar avisos.');

        $id=trim((string)($input['id']??''));
        $title=trim((string)($input['title']??''));
        $body=trim((string)($input['body']??''));
        $priority=(string)($input['priority']??'normal');
        $audience=(string)($input['audience_type']??'all');
        $target=trim((string)($input['target_id']??''))?:null;
        $link=trim((string)($input['link_url']??''))?:null;
        $attachment=trim((string)($input['attachment_url']??''))?:null;
        $starts=$this->dateTimeOrNull((string)($input['starts_at']??''));
        $ends=$this->dateTimeOrNull((string)($input['ends_at']??''));
        $pinned=isset($input['pinned'])?1:0;
        $active=isset($input['active'])?1:0;

        if($title===''||mb_strlen($title)>190)throw new InvalidArgumentException('Informe o título do aviso.');
        if($body===''||mb_strlen($body)>12000)throw new InvalidArgumentException('Informe o texto do aviso.');
        if(!in_array($priority,['normal','important','urgent'],true))$priority='normal';
        if(!in_array($audience,['all','members','leaders','department','group'],true))$audience='all';
        if(in_array($audience,['department','group'],true)&&!$target)throw new InvalidArgumentException('Selecione o destino do aviso.');
        if($audience==='department'&&$target){
            $q=$this->q('SELECT id FROM ministry_departments WHERE id=? AND church_id=? AND active=1',[$target,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Departamento não encontrado.');
        }
        if($audience==='group'&&$target){
            $q=$this->q('SELECT id FROM small_groups WHERE id=? AND church_id=? AND active=1',[$target,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Grupo/célula não encontrado.');
        }
        foreach([$link,$attachment] as $url){
            if($url!==null&&!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Informe links válidos começando com http:// ou https://.');
        }
        if($starts&&$ends&&$ends<$starts)throw new InvalidArgumentException('O fim da exibição deve ser depois do início.');

        $user=$this->user();
        if($id===''){
            $id=app_uuid();
            $this->q("INSERT INTO church_announcements(
              id,church_id,title,body,priority,audience_type,target_id,link_url,attachment_url,starts_at,ends_at,pinned,active,created_by_user_id
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[
                $id,$this->cid,$title,$body,$priority,$audience,$target,$link,$attachment,$starts,$ends,$pinned,$active,$user['id']
            ]);
            $this->audit('announcement_created',['announcement_id'=>$id,'title'=>$title]);
        }else{
            $q=$this->q('SELECT id FROM church_announcements WHERE id=? AND church_id=?',[$id,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Aviso não encontrado.');
            $this->q("UPDATE church_announcements SET
              title=?,body=?,priority=?,audience_type=?,target_id=?,link_url=?,attachment_url=?,starts_at=?,ends_at=?,pinned=?,active=?
              WHERE id=? AND church_id=?",[
                $title,$body,$priority,$audience,$target,$link,$attachment,$starts,$ends,$pinned,$active,$id,$this->cid
            ]);
            $this->audit('announcement_updated',['announcement_id'=>$id,'title'=>$title]);
        }
        return $id;
    }

    public function archive(string $id): void
    {
        if(!$this->auth->can('communications'))throw new RuntimeException('Você não possui permissão para arquivar avisos.');
        $this->q('UPDATE church_announcements SET active=0 WHERE id=? AND church_id=?',[$id,$this->cid]);
        $this->audit('announcement_archived',['announcement_id'=>$id]);
    }

    public function visibleForCurrentUser(): array
    {
        $user=$this->user();
        $membership=$this->auth->currentMembership();
        if(!$membership)return [];

        $personId=trim((string)($membership['person_id']??''))?:null;
        $role=$this->auth->churchRole();
        $leader=in_array($role,['owner','admin','pastor','secretary','treasurer','department_leader','group_leader'],true);

        $sql="SELECT a.*,
          EXISTS(
            SELECT 1 FROM church_announcement_reads r
            WHERE r.announcement_id=a.id AND r.church_id=a.church_id AND r.user_id=?
          ) is_read
          FROM church_announcements a
          WHERE a.church_id=? AND a.active=1
            AND (a.starts_at IS NULL OR a.starts_at<=NOW())
            AND (a.ends_at IS NULL OR a.ends_at>=NOW())
            AND (
              a.audience_type='all'
              OR (a.audience_type='members' AND ? IS NOT NULL)
              OR (a.audience_type='leaders' AND ?=1)
              OR (
                a.audience_type='department' AND (
                  EXISTS(
                    SELECT 1 FROM department_access da
                    WHERE da.church_id=a.church_id AND da.department_id=a.target_id AND da.user_id=?
                  )
                  OR EXISTS(
                    SELECT 1 FROM ministry_members mm
                    WHERE mm.church_id=a.church_id AND mm.department_id=a.target_id AND mm.member_id=?
                  )
                )
              )
              OR (
                a.audience_type='group' AND (
                  EXISTS(
                    SELECT 1 FROM small_group_leaders gl
                    WHERE gl.church_id=a.church_id AND gl.group_id=a.target_id AND gl.member_id=?
                  )
                  OR EXISTS(
                    SELECT 1 FROM small_group_members gm
                    WHERE gm.church_id=a.church_id AND gm.group_id=a.target_id AND gm.member_id=? AND gm.active=1
                  )
                )
              )
            )
          ORDER BY a.pinned DESC,
            FIELD(a.priority,'urgent','important','normal'),
            COALESCE(a.starts_at,a.created_at) DESC,a.created_at DESC";

        $personParam=$personId;
        $q=$this->q($sql,[
            $user['id'],$this->cid,$personId,$leader?1:0,$user['id'],$personParam,$personParam,$personParam
        ]);
        return $q->fetchAll();
    }

    public function markRead(string $id): void
    {
        $allowed=false;
        foreach($this->visibleForCurrentUser() as $row){
            if((string)$row['id']===$id){$allowed=true;break;}
        }
        if(!$allowed)throw new RuntimeException('Aviso não disponível para este acesso.');

        $this->q("INSERT INTO church_announcement_reads(church_id,announcement_id,user_id)
          VALUES(?,?,?)
          ON DUPLICATE KEY UPDATE read_at=VALUES(read_at)",[
            $this->cid,$id,$this->user()['id']
        ]);
    }

    public function unreadCount(): int
    {
        $count=0;
        foreach($this->visibleForCurrentUser() as $row)if(empty($row['is_read']))$count++;
        return $count;
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;
        $value=str_replace('T',' ',$value);
        if(strlen($value)===16)$value.=':00';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException('Data e hora inválidas.');
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
