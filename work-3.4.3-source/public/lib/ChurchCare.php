<?php
declare(strict_types=1);

final class ChurchCare
{
    public function __construct(private PDO $pdo, private string $churchId) {}

    public const VISITOR_STATUS = ['new'=>'Novo visitante','contacted'=>'Contato realizado','discipleship'=>'Em discipulado','integrated'=>'Integrado','closed'=>'Encerrado'];
    public const CARE_KIND = ['absence'=>'Ausência percebida','visit'=>'Visita pastoral','counseling'=>'Atendimento pastoral','discipleship'=>'Discipulado'];
    public const CARE_STATUS = ['open'=>'Em acompanhamento','done'=>'Concluído'];
    public const ASSET_STATUS = ['good'=>'Em uso / bom','maintenance'=>'Em manutenção','retired'=>'Baixado'];

    public static function displayDate(?string $date): string
    {
        if(!$date) return '—';
        $time=strtotime($date);
        return $time===false?'—':date('d/m/Y',$time);
    }

    public function all(string $sql, array $params=[]): array
    {
        $q=$this->pdo->prepare($sql); $q->execute([$this->churchId,...$params]); return $q->fetchAll();
    }

    public function owned(string $table, string $id): array
    {
        if(!in_array($table,['members','church_visitors','pastoral_care','ministry_departments','church_assets'],true)) throw new InvalidArgumentException('Cadastro inválido.');
        $rows=$this->all("SELECT * FROM $table WHERE church_id=? AND id=?",[$id]);
        if(!$rows) throw new InvalidArgumentException('Cadastro não encontrado nesta igreja.');
        return $rows[0];
    }

    public static function text(array $data,string $key,int $max=255,bool $required=false): string
    {
        $value=trim((string)($data[$key]??''));
        if(($required && $value==='') || mb_strlen($value)>$max) throw new InvalidArgumentException('Preencha corretamente o campo: '.$key.'.');
        return $value;
    }

    public static function date(array $data,string $key,bool $required=false): ?string
    {
        $v=self::text($data,$key,10,$required);
        if($v==='') return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if(!$d || $d->format('Y-m-d')!==$v) throw new InvalidArgumentException('Informe uma data válida.');
        return $v;
    }

    public static function choice(array $data,string $key,array $allowed): string
    {
        $v=self::text($data,$key,30,true);
        if(!array_key_exists($v,$allowed)) throw new InvalidArgumentException('Opção inválida.');
        return $v;
    }

    public static function money(array $data,string $key): string
    {
        $v=trim((string)($data[$key]??'0'));
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$v)) throw new InvalidArgumentException('Informe um valor positivo com até duas casas decimais.');
        return $v;
    }

    private function save(string $table,string $id,array $data): string
    {
        if($id!=='') {
            $this->owned($table,$id);
            $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($data)));
            $this->pdo->prepare("UPDATE $table SET $sets WHERE church_id=? AND id=?")->execute([...array_values($data),$this->churchId,$id]);
        } else {
            $id=app_uuid(); $data=['id'=>$id,'church_id'=>$this->churchId,...$data];
            $cols=implode(',',array_keys($data)); $marks=implode(',',array_fill(0,count($data),'?'));
            $this->pdo->prepare("INSERT INTO $table($cols) VALUES($marks)")->execute(array_values($data));
        }
        return $id;
    }

    public function visitor(array $d): string
    {
        $member=self::text($d,'member_id',36);
        if($member!=='') $this->owned('members',$member);
        return $this->save('church_visitors',self::text($d,'id',36),[
            'name'=>self::text($d,'name',255,true),'phone'=>self::text($d,'phone',40),
            'first_visit'=>self::date($d,'first_visit',true),'next_contact'=>self::date($d,'next_contact'),
            'responsible'=>self::text($d,'responsible',190),'status'=>self::choice($d,'status',self::VISITOR_STATUS),
            'notes'=>self::text($d,'notes',5000),'member_id'=>$member?:null]);
    }

    public function care(array $d,string $userId): string
    {
        $member=self::text($d,'member_id',36,true); $this->owned('members',$member);
        return $this->save('pastoral_care','',[
            'member_id'=>$member,'kind'=>self::choice($d,'kind',self::CARE_KIND),
            'occurred_on'=>self::date($d,'occurred_on',true),'next_contact'=>self::date($d,'next_contact'),
            'responsible'=>self::text($d,'responsible',190,true),'status'=>self::choice($d,'status',self::CARE_STATUS),
            'notes'=>self::text($d,'notes',5000),'created_by'=>$userId]);
    }

    public function closeCare(string $id): void
    {
        $this->owned('pastoral_care',$id);
        $this->pdo->prepare("UPDATE pastoral_care SET status='done' WHERE church_id=? AND id=?")->execute([$this->churchId,$id]);
    }

    public function department(array $d): string
    {
        return $this->save('ministry_departments',self::text($d,'id',36),[
            'name'=>self::text($d,'name',190,true),'leader'=>self::text($d,'leader',190),
            'meeting_info'=>self::text($d,'meeting_info',255),'active'=>isset($d['active'])?1:0]);
    }

    public function enrollment(array $d): void
    {
        $dept=self::text($d,'department_id',36,true); $member=self::text($d,'member_id',36,true);
        $this->owned('ministry_departments',$dept); $this->owned('members',$member);
        if(($d['action']??'')==='remove') {
            $this->pdo->prepare('DELETE FROM ministry_members WHERE church_id=? AND department_id=? AND member_id=?')->execute([$this->churchId,$dept,$member]);
        } else {
            $this->pdo->prepare('INSERT INTO ministry_members(department_id,member_id,church_id,joined_on) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE joined_on=joined_on')->execute([$dept,$member,$this->churchId,date('Y-m-d')]);
        }
    }

    public function asset(array $d): string
    {
        return $this->save('church_assets',self::text($d,'id',36),[
            'code'=>self::text($d,'code',80,true),'name'=>self::text($d,'name',255,true),
            'category'=>self::text($d,'category',120),'location'=>self::text($d,'location',190),
            'responsible'=>self::text($d,'responsible',190),'acquired_on'=>self::date($d,'acquired_on'),
            'acquisition_value'=>self::money($d,'acquisition_value'),'status'=>self::choice($d,'status',self::ASSET_STATUS),
            'notes'=>self::text($d,'notes',5000)]);
    }

    public function maintenance(array $d): string
    {
        $asset=self::text($d,'asset_id',36,true); $this->owned('church_assets',$asset);
        $id=app_uuid();
        $this->pdo->prepare('INSERT INTO asset_maintenance(id,church_id,asset_id,performed_on,next_due,description,cost,responsible) VALUES(?,?,?,?,?,?,?,?)')->execute([
            $id,$this->churchId,$asset,self::date($d,'performed_on',true),self::date($d,'next_due'),
            self::text($d,'description',5000,true),self::money($d,'cost'),self::text($d,'responsible',190)]);
        return $id;
    }
}
