<?php
declare(strict_types=1);
require_once __DIR__.'/EnuvesXlsxReader.php';
require_once __DIR__.'/EnuvesImportMap.php';

final class EnuvesImportService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $actorId){
        if($actorId===''||!$auth->isSuperAdmin())throw new RuntimeException('Apenas o Master pode importar cadastros.');
    }

    public static function ensureSchema(PDO $pdo):void{
        try {
            foreach(['enuves_import_batches','enuves_import_people','enuves_people_details',
                'enuves_department_interests','enuves_department_leaders'] as $table)
                $pdo->query('SELECT church_id FROM '.$table.' LIMIT 0');
            return;
        }catch(PDOException $e){
            if(!in_array((string)$e->getCode(),['42S02','HY000'],true)&&!str_contains($e->getMessage(),'1146'))throw $e;
        }
        $sql=file_get_contents(dirname(__DIR__).'/database/042_enuves_migration.sql');
        if(!$sql)throw new RuntimeException('Migração contábil/Enuves indisponível.');
        foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    }
    private function q(string $sql,array $params=[]):PDOStatement {
        $s=$this->pdo->prepare($sql);$s->execute($params);return $s;
    }
    public function churches():array{
        return $this->q('SELECT id,name FROM churches WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function church(string $id):array {
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$id))throw new InvalidArgumentException('Igreja inválida.');
        $c=$this->q('SELECT id,name,active FROM churches WHERE id=? LIMIT 1',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$c||!$c['active'])throw new RuntimeException('Igreja de destino inativa ou inexistente.');
        return $c;
    }
    private function duplicate(string $cid,array $p):?string {
        if($p['cpf']){
            $all=$this->q('SELECT id FROM members WHERE church_id=? AND cpf=? LIMIT 3',
                [$cid,$p['cpf']])->fetchAll(PDO::FETCH_COLUMN);
            if(count($all)>1)throw new RuntimeException('CPF duplicado na igreja: conferência manual necessária.');
            if(count($all)===1)return (string)$all[0];
        }
        if($p['birth_date']){
            $all=$this->q('SELECT id FROM members WHERE church_id=? AND name=? AND birth_date=? LIMIT 3',
                [$cid,$p['name'],$p['birth_date']])->fetchAll(PDO::FETCH_COLUMN);
            if(count($all)>1)throw new RuntimeException('Há pessoas com nome e nascimento repetidos na igreja.');
            if(count($all)===1)return (string)$all[0];
        }
        return null;
    }
    private function saveMember(string $cid,array $p):string {
        $id=app_uuid();
        $input=[
            'id'=>$id,'church_id'=>$cid,'congregation_id'=>null,
            'name'=>$p['name'],'phone'=>$p['phone'],'email'=>$p['email'],
            'birth_date'=>$p['birth_date'],'cpf'=>$p['cpf'],'rg'=>$p['rg'],
            'role'=>null,'department'=>null,'membership_date'=>null,
            'marital_status'=>$p['marital_status'],'naturalness'=>null,
            'address_city'=>null,'address_state'=>$p['address_state'],
            'notes'=>$p['notes'],'is_baptized'=>$p['is_baptized'],
            'category_id'=>null,'family_id'=>null,'extra_fields'=>'{}',
            'registration_status'=>'approved','registration_step'=>'complete','active'=>$p['active']
        ];
        $cols=array_keys($input);
        $sql='INSERT INTO members('.implode(',',$cols).') VALUES('.
            implode(',',array_fill(0,count($cols),'?')).')';
        $this->q($sql,array_values($input));
        return $id;
    }
    private function department(string $cid,string $name):array {
        $id=$this->q('SELECT id FROM ministry_departments WHERE church_id=? AND name=? LIMIT 1',
            [$cid,$name])->fetchColumn();
        if($id)return [(string)$id,false];
        $id=app_uuid();
        $this->q('INSERT INTO ministry_departments(id,church_id,name,active) VALUES(?,?,?,1)',
            [$id,$cid,$name]);
        return [$id,true];
    }
    private static function extra(array $r):string {
        $extra=[];
        foreach(['phone_2','address_1','address_2','address_number','postal_code','id_city',
            'id_state','id_country','spouse_name','spouse_christian','conversion_date',
            'baptism_date','scholarity','avatar_file','avatar_date','lang','id_lead',
            'id_lead_exact','created_at','Que tipo de atividade você deseja participar na igreja?'] as $key){
            $v=EnuvesImportMap::text($r[$key]??'',1800);
            if($v!=='')$extra[$key]=$v;
        }
        return json_encode($extra,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    public function import(string $file,string $cid,bool $withDepartments):array {
        $church=$this->church($cid);
        $rows=EnuvesXlsxReader::read($file);
        $summary=EnuvesImportMap::summary($rows);
        if($summary['bad']||$summary['duplicate_ids'])
            throw new InvalidArgumentException('Corrija os nomes ou IDs repetidos na planilha antes de importar.');
        $sha=hash_file('sha256',$file);
        if(!$sha)throw new RuntimeException('Falha ao verificar a integridade da planilha.');
        $batch=app_uuid();$created=0;$linked=0;$skipped=0;$newDept=0;$preferences=0;
        $deptMap=[];
        $this->pdo->beginTransaction();
        try{
            if($this->q('SELECT 1 FROM enuves_import_batches WHERE church_id=? AND file_sha256=?',
                [$cid,$sha])->fetchColumn())throw new InvalidArgumentException('Essa planilha já foi importada nesta igreja.');

            if($withDepartments)foreach(array_keys($summary['departments']) as $name){
                [$deptId,$isNew]=$this->department($cid,$name);
                $deptMap[$name]=$deptId;
                if($isNew)$newDept++;
            }
            foreach($rows as $idx=>$row){
                $p=EnuvesImportMap::person($row);
                if($this->q('SELECT member_id FROM enuves_import_people WHERE church_id=? AND source_id=?',
                    [$cid,$p['source_id']])->fetchColumn()){$skipped++;continue;}
                // CPF/name+birthdate matching may be ambiguous. Do not overwrite any existing record.
                $memberId=$this->duplicate($cid,$p);
                if(!$memberId){$memberId=$this->saveMember($cid,$p);$created++;}
                else $linked++;
                $rowHash=hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                $this->q('INSERT INTO enuves_import_people(church_id,source_id,member_id,batch_id,source_row,source_sha256)
                    VALUES(?,?,?,?,?,?)',[$cid,$p['source_id'],$memberId,$batch,$idx+2,$rowHash]);
                $this->q('INSERT INTO enuves_people_details(church_id,member_id,source_id,detail_json)
                    VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)',
                    [$cid,$memberId,$p['source_id'],self::extra($row)]);
                if($withDepartments)foreach(EnuvesImportMap::interests($row) as $name){
                    $deptId=$deptMap[$name]??null;
                    if(!$deptId)continue;
                    $q=$this->q('INSERT IGNORE INTO enuves_department_interests
                        (church_id,member_id,department_id,interest_label) VALUES(?,?,?,?)',
                        [$cid,$memberId,$deptId,mb_substr((string)($row['Que tipo de atividade você deseja participar na igreja?']??''),0,190)]);
                    $preferences+=$q->rowCount();
                }
            }
            $this->q("INSERT INTO enuves_import_batches
               (id,church_id,file_sha256,rows_seen,members_created,linked_existing,skipped,
                departments_created,interests_created,imported_by_user_id)
               VALUES(?,?,?,?,?,?,?,?,?,?)",
               [$batch,$cid,$sha,$summary['total'],$created,$linked,$skipped,$newDept,$preferences,$this->actorId]);
            $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details)
                VALUES(?,?,?,?,?)',[app_uuid(),$this->actorId,$cid,'enuves_people_import',
                json_encode(['batch'=>$batch,'created'=>$created,'linked'=>$linked,'skipped'=>$skipped,
                    'departments'=>$newDept,'interests'=>$preferences],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return [
                'church'=>$church['name'],'batch'=>$batch,'total'=>$summary['total'],
                'created'=>$created,'linked'=>$linked,'skipped'=>$skipped,
                'departments_created'=>$newDept,'interests_created'=>$preferences,
                'archived_in_source'=>$summary['archived'],'leaders_assigned_automatically'=>0
            ];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
    public function batches(string $cid):array {
        $this->church($cid);
        return $this->q('SELECT * FROM enuves_import_batches WHERE church_id=?
            ORDER BY imported_at DESC LIMIT 12',[$cid])->fetchAll(PDO::FETCH_ASSOC);
    }
}
