<?php
declare(strict_types=1);

require_once __DIR__.'/UploadService.php';

final class MediaLibraryService
{
    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private string $cid,
        private string $appRoot
    ) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public function isManager(): bool
    {
        return $this->auth->can('communications')||$this->auth->can('secretary');
    }

    public function folders(bool $activeOnly=true): array
    {
        $sql='SELECT f.*,
          (SELECT COUNT(*) FROM media_library_items i WHERE i.folder_id=f.id AND i.church_id=f.church_id AND i.active=1) item_count
          FROM media_library_folders f WHERE f.church_id=?';
        if($activeOnly)$sql.=' AND f.active=1';
        $sql.=' ORDER BY f.sort_order,f.name';
        return $this->q($sql,[$this->cid])->fetchAll();
    }

    public function saveFolder(array $input): string
    {
        if(!$this->isManager())throw new RuntimeException('Você não possui permissão para organizar a biblioteca.');

        $id=trim((string)($input['id']??''));
        $name=trim((string)($input['name']??''));
        $description=trim((string)($input['description']??''))?:null;
        $sort=(int)($input['sort_order']??0);
        $active=isset($input['active'])?1:0;

        if($name===''||mb_strlen($name)>160)throw new InvalidArgumentException('Informe o nome da pasta.');
        if($description!==null&&mb_strlen($description)>500)throw new InvalidArgumentException('Descrição da pasta muito longa.');

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO media_library_folders(id,church_id,name,description,sort_order,active,created_by_user_id) VALUES(?,?,?,?,?,?,?)',[
                $id,$this->cid,$name,$description,$sort,$active,$this->auth->requireLogin()['id']
            ]);
            $this->audit('media_folder_created',['folder_id'=>$id,'name'=>$name]);
        }else{
            $q=$this->q('UPDATE media_library_folders SET name=?,description=?,sort_order=?,active=? WHERE id=? AND church_id=?',[
                $name,$description,$sort,$active,$id,$this->cid
            ]);
            if($q->rowCount()===0){
                $exists=$this->q('SELECT 1 FROM media_library_folders WHERE id=? AND church_id=?',[$id,$this->cid])->fetchColumn();
                if(!$exists)throw new RuntimeException('Pasta não encontrada.');
            }
            $this->audit('media_folder_updated',['folder_id'=>$id,'name'=>$name]);
        }

        return $id;
    }

    public function saveItem(array $input,array $file=[]): string
    {
        if(!$this->isManager())throw new RuntimeException('Você não possui permissão para adicionar arquivos à biblioteca.');

        $id=trim((string)($input['id']??''));
        $title=trim((string)($input['title']??''));
        $description=trim((string)($input['description']??''))?:null;
        $category=trim((string)($input['category']??''))?:null;
        $folderId=trim((string)($input['folder_id']??''))?:null;
        $externalUrl=trim((string)($input['external_url']??''))?:null;
        $audience=(string)($input['audience_type']??'all');
        $targetId=trim((string)($input['target_id']??''))?:null;
        $featured=isset($input['featured'])?1:0;
        $active=isset($input['active'])?1:0;

        if($title===''||mb_strlen($title)>190)throw new InvalidArgumentException('Informe o título do arquivo.');
        if($description!==null&&mb_strlen($description)>10000)throw new InvalidArgumentException('Descrição muito longa.');
        if($category!==null&&mb_strlen($category)>120)throw new InvalidArgumentException('Categoria muito longa.');
        if(!in_array($audience,['all','members','leaders','department','group'],true))$audience='all';
        if(in_array($audience,['department','group'],true)&&!$targetId)throw new InvalidArgumentException('Selecione o destino deste arquivo.');

        if($folderId){
            $q=$this->q('SELECT 1 FROM media_library_folders WHERE id=? AND church_id=? AND active=1',[$folderId,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Pasta não encontrada.');
        }

        if($audience==='department'){
            $q=$this->q('SELECT 1 FROM ministry_departments WHERE id=? AND church_id=? AND active=1',[$targetId,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Departamento não encontrado.');
        }
        if($audience==='group'){
            $q=$this->q('SELECT 1 FROM small_groups WHERE id=? AND church_id=? AND active=1',[$targetId,$this->cid]);
            if(!$q->fetchColumn())throw new RuntimeException('Grupo/célula não encontrado.');
        }

        if($externalUrl!==null&&!filter_var($externalUrl,FILTER_VALIDATE_URL)){
            throw new InvalidArgumentException('Informe um link externo válido.');
        }

        $current=null;
        if($id!==''){
            $q=$this->q('SELECT * FROM media_library_items WHERE id=? AND church_id=? LIMIT 1',[$id,$this->cid]);
            $current=$q->fetch();
            if(!$current)throw new RuntimeException('Arquivo não encontrado.');
        }

        $uploaded=(new UploadService($this->appRoot))->mediaLibraryFile($file,$this->cid);

        $storagePath=$uploaded['path']??($current['storage_path']??null);
        $originalName=$uploaded['original_name']??($current['original_name']??null);
        $mime=$uploaded['mime']??($current['mime_type']??null);
        $size=(int)($uploaded['size']??($current['file_size']??0));

        if(!$storagePath&&$externalUrl===null&&$current){
            $externalUrl=$current['external_url']?:null;
        }

        if(!$storagePath&&$externalUrl===null){
            throw new InvalidArgumentException('Envie um arquivo ou informe um link externo.');
        }

        if($uploaded&&$externalUrl!==null){
            // Quando um novo upload é enviado, ele passa a ser a fonte principal.
            $externalUrl=null;
        }

        $type=$this->detectType($mime,$storagePath,$externalUrl,$input['media_type']??null);

        if($id===''){
            $id=app_uuid();
            $this->q('INSERT INTO media_library_items(
              id,church_id,folder_id,title,description,category,media_type,storage_path,external_url,original_name,mime_type,file_size,audience_type,target_id,featured,active,created_by_user_id
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $id,$this->cid,$folderId,$title,$description,$category,$type,$storagePath,$externalUrl,$originalName,$mime,$size,$audience,$targetId,$featured,$active,$this->auth->requireLogin()['id']
            ]);
            $this->audit('media_item_created',['media_id'=>$id,'title'=>$title,'media_type'=>$type]);
        }else{
            $this->q('UPDATE media_library_items SET
              folder_id=?,title=?,description=?,category=?,media_type=?,storage_path=?,external_url=?,original_name=?,mime_type=?,file_size=?,audience_type=?,target_id=?,featured=?,active=?
              WHERE id=? AND church_id=?',[
                $folderId,$title,$description,$category,$type,$storagePath,$externalUrl,$originalName,$mime,$size,$audience,$targetId,$featured,$active,$id,$this->cid
            ]);
            $this->audit('media_item_updated',['media_id'=>$id,'title'=>$title,'media_type'=>$type]);
        }

        return $id;
    }

    public function archive(string $id): void
    {
        if(!$this->isManager())throw new RuntimeException('Você não possui permissão para arquivar arquivos.');
        $this->q('UPDATE media_library_items SET active=0 WHERE id=? AND church_id=?',[$id,$this->cid]);
        $this->audit('media_item_archived',['media_id'=>$id]);
    }

    public function all(array $filters=[]): array
    {
        $where=['i.church_id=?'];
        $params=[$this->cid];

        if(!empty($filters['active_only']))$where[]='i.active=1';
        if(!empty($filters['folder_id'])){$where[]='i.folder_id=?';$params[]=$filters['folder_id'];}
        if(!empty($filters['media_type'])){$where[]='i.media_type=?';$params[]=$filters['media_type'];}
        if(!empty($filters['q'])){
            $where[]='(i.title LIKE ? OR i.description LIKE ? OR i.category LIKE ? OR i.original_name LIKE ?)';
            $like='%'.$filters['q'].'%';
            array_push($params,$like,$like,$like,$like);
        }

        $sql="SELECT i.*,f.name folder_name,u.full_name created_by_name
          FROM media_library_items i
          LEFT JOIN media_library_folders f ON f.id=i.folder_id AND f.church_id=i.church_id
          LEFT JOIN users u ON u.id=i.created_by_user_id
          WHERE ".implode(' AND ',$where)."
          ORDER BY i.featured DESC,i.active DESC,i.created_at DESC";

        return $this->q($sql,$params)->fetchAll();
    }

    public function visibleForCurrentUser(array $filters=[]): array
    {
        if($this->isManager())return $this->all([...$filters,'active_only'=>true]);

        $user=$this->auth->requireLogin();
        $membership=$this->auth->currentMembership();
        if(!$membership)return [];
        $personId=trim((string)($membership['person_id']??''))?:null;
        $role=$this->auth->churchRole();
        $leader=in_array($role,['owner','admin','pastor','secretary','treasurer','department_leader','group_leader','teacher'],true);

        $where=['i.church_id=?','i.active=1'];
        $params=[$this->cid];

        if(!empty($filters['folder_id'])){$where[]='i.folder_id=?';$params[]=$filters['folder_id'];}
        if(!empty($filters['media_type'])){$where[]='i.media_type=?';$params[]=$filters['media_type'];}
        if(!empty($filters['q'])){
            $where[]='(i.title LIKE ? OR i.description LIKE ? OR i.category LIKE ? OR i.original_name LIKE ?)';
            $like='%'.$filters['q'].'%';
            array_push($params,$like,$like,$like,$like);
        }

        $where[]="(
            i.audience_type='all'
            OR (i.audience_type='members' AND ? IS NOT NULL)
            OR (i.audience_type='leaders' AND ?=1)
            OR (
              i.audience_type='department' AND (
                EXISTS(
                  SELECT 1 FROM department_access da
                  WHERE da.church_id=i.church_id AND da.department_id=i.target_id AND da.user_id=?
                )
                OR EXISTS(
                  SELECT 1 FROM ministry_members mm
                  WHERE mm.church_id=i.church_id AND mm.department_id=i.target_id AND mm.member_id=?
                )
              )
            )
            OR (
              i.audience_type='group' AND (
                EXISTS(
                  SELECT 1 FROM small_group_leaders gl
                  WHERE gl.church_id=i.church_id AND gl.group_id=i.target_id AND gl.member_id=?
                )
                OR EXISTS(
                  SELECT 1 FROM small_group_members gm
                  WHERE gm.church_id=i.church_id AND gm.group_id=i.target_id AND gm.member_id=? AND gm.active=1
                )
              )
            )
          )";

        array_push($params,$personId,$leader?1:0,$user['id'],$personId,$personId,$personId);

        $sql="SELECT i.*,f.name folder_name
          FROM media_library_items i
          LEFT JOIN media_library_folders f ON f.id=i.folder_id AND f.church_id=i.church_id
          WHERE ".implode(' AND ',$where)."
          ORDER BY i.featured DESC,i.created_at DESC";

        return $this->q($sql,$params)->fetchAll();
    }

    public function item(string $id,bool $requireVisible=false): array
    {
        $rows=($requireVisible||!$this->isManager())?$this->visibleForCurrentUser():$this->all();
        foreach($rows as $row)if((string)$row['id']===$id)return $row;
        throw new RuntimeException('Arquivo não encontrado ou não disponível.');
    }

    public function url(array $item): ?string
    {
        $external=trim((string)($item['external_url']??''));
        if($external!=='')return $external;
        $path=trim((string)($item['storage_path']??''));
        return $path!==''?'/'.ltrim($path,'/'):null;
    }

    public function localPath(array $item): ?string
    {
        $path=trim((string)($item['storage_path']??''));
        if($path==='')return null;
        try{
            $absolute=(new UploadService($this->appRoot))->absolute($path);
            return is_file($absolute)?$absolute:null;
        }catch(Throwable){
            return null;
        }
    }

    public function reusableItems(?string $type=null): array
    {
        $filters=['active_only'=>true];
        if($type)$filters['media_type']=$type;
        return $this->all($filters);
    }

    private function detectType(?string $mime,?string $storagePath,?string $externalUrl,mixed $hint): string
    {
        $hint=is_string($hint)?trim($hint):'';
        if(in_array($hint,['image','video','audio','pdf','document','link'],true)&&$externalUrl&&!$mime)return $hint;

        $mime=strtolower((string)$mime);
        if(str_starts_with($mime,'image/'))return 'image';
        if(str_starts_with($mime,'video/'))return 'video';
        if(str_starts_with($mime,'audio/'))return 'audio';
        if($mime==='application/pdf')return 'pdf';
        if($mime!=='')return 'document';

        $source=strtolower((string)($storagePath?:$externalUrl));
        if(preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/',$source))return 'image';
        if(preg_match('/\.(mp4|webm)(\?.*)?$/',$source))return 'video';
        if(preg_match('/\.(mp3|ogg|wav)(\?.*)?$/',$source))return 'audio';
        if(preg_match('/\.pdf(\?.*)?$/',$source))return 'pdf';
        return $externalUrl?'link':'document';
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
