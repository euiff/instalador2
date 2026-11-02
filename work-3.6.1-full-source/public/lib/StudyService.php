<?php
declare(strict_types=1);

final class StudyService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public function isManager(): bool
    {
        return $this->auth->can('communications')||$this->auth->can('secretary');
    }

    public function categories(bool $activeOnly=true): array
    {
        $sql="SELECT c.*,
          (SELECT COUNT(*) FROM church_studies s WHERE s.church_id=c.church_id AND s.category_id=c.id AND s.active=1) study_count
          FROM study_categories c WHERE c.church_id=?";
        if($activeOnly)$sql.=" AND c.active=1";
        $sql.=" ORDER BY c.sort_order,c.name";
        return $this->q($sql,[$this->cid])->fetchAll();
    }

    public function saveCategory(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Você não possui permissão para gerenciar categorias de estudos.');
        $id=trim((string)($input['id']??''));
        $name=trim((string)($input['name']??''));
        $description=trim((string)($input['description']??''))?:null;
        $sort=(int)($input['sort_order']??0);
        $active=isset($input['active'])?1:0;

        if($name===''||mb_strlen($name)>160)throw new InvalidArgumentException('Informe o nome da categoria.');
        if($description!==null&&mb_strlen($description)>500)throw new InvalidArgumentException('Descrição muito longa.');

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO study_categories(id,church_id,name,description,sort_order,active) VALUES(?,?,?,?,?,?)',[
                $id,$this->cid,$name,$description,$sort,$active
            ]);
        }else{
            $q=$this->q('UPDATE study_categories SET name=?,description=?,sort_order=?,active=? WHERE id=? AND church_id=?',[
                $name,$description,$sort,$active,$id,$this->cid
            ]);
            if($q->rowCount()===0&&!$this->q('SELECT 1 FROM study_categories WHERE id=? AND church_id=?',[$id,$this->cid])->fetchColumn()){
                throw new RuntimeException('Categoria não encontrada.');
            }
        }
        $this->audit('study_category_saved',['category_id'=>$id,'name'=>$name]);
        return $id;
    }

    public function save(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Você não possui permissão para publicar estudos.');

        $id=trim((string)($input['id']??''));
        $title=trim((string)($input['title']??''));
        $summary=trim((string)($input['summary']??''))?:null;
        $body=trim((string)($input['body']??''))?:null;
        $category=trim((string)($input['category_id']??''))?:null;
        $cover=trim((string)($input['cover_media_id']??''))?:null;
        $attachment=trim((string)($input['attachment_media_id']??''))?:null;
        $external=trim((string)($input['external_url']??''))?:null;
        $audience=(string)($input['audience_type']??'all');
        $target=trim((string)($input['target_id']??''))?:null;
        $featured=isset($input['featured'])?1:0;
        $active=isset($input['active'])?1:0;
        $published=$this->dateTimeOrNull((string)($input['published_at']??''))??date('Y-m-d H:i:s');

        if($title===''||mb_strlen($title)>190)throw new InvalidArgumentException('Informe o título do estudo.');
        if($summary!==null&&mb_strlen($summary)>1000)throw new InvalidArgumentException('Resumo muito longo.');
        if($body!==null&&mb_strlen($body)>50000)throw new InvalidArgumentException('Conteúdo muito longo.');
        if($external!==null&&!filter_var($external,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Link externo inválido.');
        if(!in_array($audience,['all','members','leaders','department','group'],true))$audience='all';
        if(in_array($audience,['department','group'],true)&&!$target)throw new InvalidArgumentException('Selecione o público específico.');

        if($category&&!$this->q('SELECT 1 FROM study_categories WHERE id=? AND church_id=?',[$category,$this->cid])->fetchColumn()){
            throw new RuntimeException('Categoria não encontrada.');
        }
        foreach([$cover,$attachment] as $mediaId){
            if(!$mediaId)continue;
            if(!$this->q('SELECT 1 FROM media_library_items WHERE id=? AND church_id=? AND active=1',[$mediaId,$this->cid])->fetchColumn()){
                throw new RuntimeException('Uma das mídias selecionadas não está disponível.');
            }
        }
        if($audience==='department'&&!$this->q('SELECT 1 FROM ministry_departments WHERE id=? AND church_id=? AND active=1',[$target,$this->cid])->fetchColumn()){
            throw new RuntimeException('Departamento não encontrado.');
        }
        if($audience==='group'&&!$this->q('SELECT 1 FROM small_groups WHERE id=? AND church_id=? AND active=1',[$target,$this->cid])->fetchColumn()){
            throw new RuntimeException('Grupo/célula não encontrado.');
        }

        $uid=$this->auth->requireLogin()['id'];
        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO church_studies(
              id,church_id,category_id,title,summary,body,cover_media_id,attachment_media_id,external_url,audience_type,target_id,featured,active,published_at,created_by_user_id
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$category,$title,$summary,$body,$cover,$attachment,$external,$audience,$target,$featured,$active,$published,$uid
            ]);
        }else{
            if(!$this->q('SELECT 1 FROM church_studies WHERE id=? AND church_id=?',[$id,$this->cid])->fetchColumn()){
                throw new RuntimeException('Estudo não encontrado.');
            }
            $this->q('UPDATE church_studies SET category_id=?,title=?,summary=?,body=?,cover_media_id=?,attachment_media_id=?,external_url=?,audience_type=?,target_id=?,featured=?,active=?,published_at=? WHERE id=? AND church_id=?',[
                $category,$title,$summary,$body,$cover,$attachment,$external,$audience,$target,$featured,$active,$published,$id,$this->cid
            ]);
        }
        $this->audit('study_saved',['study_id'=>$id,'title'=>$title]);
        return $id;
    }

    public function archive(string $id): void
    {
        if(!$this->isManager())throw new RuntimeException('Você não possui permissão para arquivar estudos.');
        $this->q('UPDATE church_studies SET active=0 WHERE id=? AND church_id=?',[$id,$this->cid]);
        $this->audit('study_archived',['study_id'=>$id]);
    }

    public function all(?string $categoryId=null): array
    {
        $sql=$this->baseSelect()." WHERE s.church_id=?";
        $params=[$this->cid];
        if($categoryId){$sql.=" AND s.category_id=?";$params[]=$categoryId;}
        $sql.=" ORDER BY s.active DESC,s.featured DESC,COALESCE(s.published_at,s.created_at) DESC";
        return $this->q($sql,$params)->fetchAll();
    }

    public function visible(?string $categoryId=null): array
    {
        if($this->isManager())return array_values(array_filter($this->all($categoryId),fn($s)=>!empty($s['active'])));

        $user=$this->auth->requireLogin();
        $membership=$this->auth->currentMembership();
        if(!$membership)return [];
        $person=trim((string)($membership['person_id']??''))?:null;
        $role=$this->auth->churchRole();
        $leader=in_array($role,['owner','admin','pastor','secretary','treasurer','department_leader','group_leader','teacher'],true);

        $sql=$this->baseSelect()." WHERE s.church_id=? AND s.active=1
          AND (s.published_at IS NULL OR s.published_at<=NOW())
          AND (
            s.audience_type='all'
            OR (s.audience_type='members' AND ? IS NOT NULL)
            OR (s.audience_type='leaders' AND ?=1)
            OR (
              s.audience_type='department' AND (
                EXISTS(SELECT 1 FROM department_access da WHERE da.church_id=s.church_id AND da.department_id=s.target_id AND da.user_id=?)
                OR EXISTS(SELECT 1 FROM ministry_members mm WHERE mm.church_id=s.church_id AND mm.department_id=s.target_id AND mm.member_id=?)
              )
            )
            OR (
              s.audience_type='group' AND (
                EXISTS(SELECT 1 FROM small_group_leaders gl WHERE gl.church_id=s.church_id AND gl.group_id=s.target_id AND gl.member_id=?)
                OR EXISTS(SELECT 1 FROM small_group_members gm WHERE gm.church_id=s.church_id AND gm.group_id=s.target_id AND gm.member_id=? AND gm.active=1)
              )
            )
          )";
        $params=[$this->cid,$person,$leader?1:0,$user['id'],$person,$person,$person];
        if($categoryId){$sql.=" AND s.category_id=?";$params[]=$categoryId;}
        $sql.=" ORDER BY s.featured DESC,COALESCE(s.published_at,s.created_at) DESC";
        return $this->q($sql,$params)->fetchAll();
    }

    public function item(string $id,bool $visibleOnly=false): array
    {
        $rows=$visibleOnly?$this->visible():$this->all();
        foreach($rows as $row)if((string)$row['id']===$id)return $row;
        throw new RuntimeException('Estudo não encontrado ou não disponível.');
    }

    public function markRead(string $id): void
    {
        $this->item($id,true);
        $this->q("INSERT INTO church_study_reads(church_id,study_id,user_id)
          VALUES(?,?,?)
          ON DUPLICATE KEY UPDATE read_at=VALUES(read_at)",[
            $this->cid,$id,$this->auth->requireLogin()['id']
        ]);
    }

    private function baseSelect(): string
    {
        return "SELECT s.*,c.name category_name,
          cm.storage_path cover_storage,cm.external_url cover_external,
          am.storage_path attachment_storage,am.external_url attachment_external,am.original_name attachment_name,
          EXISTS(SELECT 1 FROM church_study_reads r WHERE r.church_id=s.church_id AND r.study_id=s.id AND r.user_id=". $this->pdo->quote((string)$this->auth->requireLogin()['id']).") is_read,
          (SELECT COUNT(*) FROM church_study_reads rc WHERE rc.church_id=s.church_id AND rc.study_id=s.id) read_count
          FROM church_studies s
          LEFT JOIN study_categories c ON c.id=s.category_id AND c.church_id=s.church_id
          LEFT JOIN media_library_items cm ON cm.id=s.cover_media_id AND cm.church_id=s.church_id
          LEFT JOIN media_library_items am ON am.id=s.attachment_media_id AND am.church_id=s.church_id";
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;
        $value=str_replace('T',' ',$value);
        if(strlen($value)===16)$value.=':00';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException('Data de publicação inválida.');
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
