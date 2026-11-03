<?php
declare(strict_types=1);

/** Igreja Master 3.7.8 — agenda pastoral multi-igrejas, privada e auditável. */
final class PastoralAgendaService
{
    private const STATUSES=['open','scheduled','in_progress','closed','cancelled'];
    private const PRIORITIES=['low','normal','high'];
    private const EVENTS=['visit','call','message','note'];
    private bool $privileged;

    public function __construct(private PDO $pdo, private Auth $auth, private string $churchId, private string $actorId)
    {
        if($churchId===''||$actorId===''||!$auth->can('pastoral'))throw new RuntimeException('Acesso negado.');
        $this->privileged=in_array($auth->churchRole(),['owner','pastor','admin','administrator','secretary'],true)
            || $auth->isMasterSupportMode();
    }

    /** First use is safe on existing installations without explicit migration runner. */
    public static function ensureSchema(PDO $pdo): void
    {
        try {$pdo->query('SELECT id FROM pastoral_cases LIMIT 0');return;}
        catch(PDOException $e) {
            // Existing table failures other than "table missing" must not be hidden.
            $state=(string)$e->getCode();
            if(!in_array($state,['42S02','HY000'],true) && !str_contains($e->getMessage(),'1146'))throw $e;
        }
        $file=dirname(__DIR__).'/database/040_pastoral_agenda.sql';
        $sql=file_get_contents($file);
        if(!is_string($sql)||$sql==='')throw new RuntimeException('Migração pastoral indisponível.');
        foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    }

    private function canSeeRow(array $case): bool
    {
        return $this->privileged ||
            (string)$case['created_by_user_id']===$this->actorId ||
            (string)($case['assigned_user_id']??'')===$this->actorId;
    }

    private function constraint(): string
    {
        return $this->privileged?'':" AND (c.created_by_user_id=:actor OR c.assigned_user_id=:actor)";
    }

    private function scopeArgs(): array
    {
        return $this->privileged?[':church'=>$this->churchId]:[':church'=>$this->churchId,':actor'=>$this->actorId];
    }

    private function belongsToChurch(string $table,string $id): bool
    {
        $allowed=['members','church_visitors','church_members'];
        if(!in_array($table,$allowed,true))throw new InvalidArgumentException('Referência inválida.');
        $where=$table==='church_members'?'user_id=? AND church_id=?':'id=? AND church_id=?';
        $q=$this->pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1");
        $q->execute([$id,$this->churchId]);
        return (bool)$q->fetchColumn();
    }

    private function assignee(?string $userId): ?string
    {
        $id=trim((string)$userId);
        if($id==='')return null;
        if(!preg_match('/^[a-zA-Z0-9-]{8,64}$/',$id)||!$this->belongsToChurch('church_members',$id))
            throw new InvalidArgumentException('Responsável não pertence à igreja.');
        return $id;
    }

    private function ref(?string $id,string $table): ?string
    {
        $id=trim((string)$id);
        if($id==='')return null;
        if(!preg_match('/^[a-zA-Z0-9-]{8,64}$/',$id)||!$this->belongsToChurch($table,$id))
            throw new InvalidArgumentException('Pessoa não pertence à igreja.');
        return $id;
    }

    private function date(?string $date): ?string
    {
        $date=trim((string)$date);
        if($date==='')return null;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$dt||$dt->format('Y-m-d')!==$date)throw new InvalidArgumentException('Data inválida.');
        return $date;
    }

    private function audit(string $caseId,string $action): void
    {
        $q=$this->pdo->prepare('INSERT INTO pastoral_case_audit(id,church_id,case_id,actor_user_id,action) VALUES(?,?,?,?,?)');
        $q->execute([app_uuid(),$this->churchId,$caseId,$this->actorId,$action]);
    }

    public function assignees(): array
    {
        $q=$this->pdo->prepare('SELECT u.id,u.full_name FROM users u JOIN church_members cm ON cm.user_id=u.id WHERE cm.church_id=? AND u.active=1 ORDER BY u.full_name LIMIT 500');
        $q->execute([$this->churchId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $input): string
    {
        $subject=trim((string)($input['subject']??''));
        if($subject===''||mb_strlen($subject)>180)throw new InvalidArgumentException('Informe um assunto com até 180 caracteres.');
        $status='open';
        $priority=(string)($input['priority']??'normal');
        if(!in_array($priority,self::PRIORITIES,true))throw new InvalidArgumentException('Prioridade inválida.');
        $assignee=$this->assignee($input['assigned_user_id']??null);
        $visitor=$this->ref($input['visitor_id']??null,'church_visitors');
        $member=$this->ref($input['member_id']??null,'members');
        $due=$this->date($input['next_contact_on']??null);
        if($due!==null)$status='scheduled';
        $id=app_uuid();$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('INSERT INTO pastoral_cases (id,church_id,visitor_id,member_id,assigned_user_id,created_by_user_id,subject,status,priority,next_contact_on) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$id,$this->churchId,$visitor,$member,$assignee,$this->actorId,$subject,$status,$priority,$due]);
            $this->audit($id,'create');
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $id;
    }

    public function find(string $id): array
    {
        if(!preg_match('/^[a-zA-Z0-9-]{8,64}$/',$id))throw new InvalidArgumentException('Registro inválido.');
        $args=$this->scopeArgs();$args[':id']=$id;
        $q=$this->pdo->prepare('SELECT c.*,u.full_name assigned_name FROM pastoral_cases c LEFT JOIN users u ON u.id=c.assigned_user_id WHERE c.church_id=:church AND c.id=:id AND c.deleted_at IS NULL'.$this->constraint().' LIMIT 1');
        $q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Acompanhamento não encontrado para seu acesso.');
        return $row;
    }

    public function listing(array $filters, int $page=1, int $size=20): array
    {
        $page=max(1,min($page,100000));$size=max(1,min($size,100));
        $args=$this->scopeArgs();$where='c.church_id=:church AND c.deleted_at IS NULL'.$this->constraint();
        $status=trim((string)($filters['status']??''));
        if($status!==''&&!in_array($status,self::STATUSES,true))throw new InvalidArgumentException('Filtro inválido.');
        if($status!==''){$where.=' AND c.status=:status';$args[':status']=$status;}
        $search=trim((string)($filters['q']??''));
        if(mb_strlen($search)>180)throw new InvalidArgumentException('Busca muito longa.');
        if($search!==''){$where.=' AND c.subject LIKE :search';$args[':search']='%'.$search.'%';}
        $assigned=trim((string)($filters['assigned']??''));
        if($assigned!==''){
            $assigned=$this->assignee($assigned);
            $where.=' AND c.assigned_user_id=:assigned';$args[':assigned']=$assigned;
        }
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM pastoral_cases c WHERE '.$where);
        $q->execute($args);$total=(int)$q->fetchColumn();
        $sql='SELECT c.id,c.subject,c.status,c.priority,c.next_contact_on,c.assigned_user_id,c.created_at,u.full_name assigned_name FROM pastoral_cases c LEFT JOIN users u ON u.id=c.assigned_user_id WHERE '.$where.' ORDER BY (c.next_contact_on IS NULL),c.next_contact_on ASC,c.created_at DESC LIMIT :lim OFFSET :off';
        $q=$this->pdo->prepare($sql);
        foreach($args as $k=>$v)$q->bindValue($k,$v);
        $q->bindValue(':lim',$size,PDO::PARAM_INT);$q->bindValue(':off',($page-1)*$size,PDO::PARAM_INT);
        $q->execute();
        return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size))];
    }

    public function summary(): array
    {
        $sql="SELECT COUNT(*) total,
            COALESCE(SUM(c.status IN ('open','scheduled','in_progress')),0) active,
            COALESCE(SUM(c.next_contact_on IS NOT NULL AND c.next_contact_on < CURDATE() AND c.status IN ('open','scheduled','in_progress')),0) overdue,
            COALESCE(SUM(c.status='closed'),0) closed FROM pastoral_cases c
            WHERE c.church_id=:church AND c.deleted_at IS NULL".$this->constraint();
        $q=$this->pdo->prepare($sql);$q->execute($this->scopeArgs());
        return $q->fetch(PDO::FETCH_ASSOC)?:['total'=>0,'active'=>0,'overdue'=>0,'closed'=>0];
    }

    public function saveEvent(string $id,array $input): void
    {
        $case=$this->find($id);
        if(!in_array((string)$case['status'],['open','scheduled','in_progress'],true))
            throw new InvalidArgumentException('Reabra o acompanhamento para registrar novos contatos.');
        $kind=(string)($input['kind']??'');
        if(!in_array($kind,self::EVENTS,true))throw new InvalidArgumentException('Tipo de contato inválido.');
        $summary=trim((string)($input['summary']??''));
        if(mb_strlen($summary)>5000)throw new InvalidArgumentException('Observação muito longa.');
        $on=$this->date($input['happened_on']??date('Y-m-d'))??date('Y-m-d');
        $due=$this->date($input['next_contact_on']??null);
        $confidential=1;
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('INSERT INTO pastoral_case_events (id,church_id,case_id,created_by_user_id,kind,happened_on,summary,confidential) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([app_uuid(),$this->churchId,$id,$this->actorId,$kind,$on,$summary,$confidential]);
            $q=$this->pdo->prepare("UPDATE pastoral_cases SET next_contact_on=?,status=? WHERE id=? AND church_id=?");
            $q->execute([$due,$due!==null?'scheduled':'in_progress',$id,$this->churchId]);
            $this->audit($id,'contact');
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function assign(string $id,?string $newAssignee): void
    {
        if(!$this->privileged)throw new RuntimeException('Somente responsáveis autorizados podem atribuir atendimentos.');
        $this->find($id);$assignee=$this->assignee($newAssignee);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('UPDATE pastoral_cases SET assigned_user_id=? WHERE id=? AND church_id=? AND deleted_at IS NULL');
            $q->execute([$assignee,$id,$this->churchId]);
            $this->audit($id,'assign');
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function status(string $id,string $status): void
    {
        $this->find($id);
        if(!in_array($status,['open','closed','cancelled'],true))throw new InvalidArgumentException('Situação inválida.');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare("UPDATE pastoral_cases SET status=?,closed_at=? WHERE church_id=? AND id=? AND deleted_at IS NULL");
            $q->execute([$status,$status==='closed'?date('Y-m-d H:i:s'):null,$this->churchId,$id]);
            $this->audit($id,'status:'.$status);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function history(string $id): array
    {
        $this->find($id);
        $q=$this->pdo->prepare('SELECT kind,happened_on,summary,created_at FROM pastoral_case_events WHERE church_id=? AND case_id=? ORDER BY happened_on DESC,created_at DESC LIMIT 150');
        $q->execute([$this->churchId,$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
