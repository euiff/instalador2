<?php
declare(strict_types=1);
final class EnuvesImportMap {
    private const GROUPS=[
      'Louvor'=>['louvor'],
      'Recepção'=>['recepção','recepcao'],
      'Ação Social'=>['ação social','acao social'],
      'Oração e Intercessão'=>['oração','oracao','intercessão','intercessao'],
      'Multimídia e Sonoplastia'=>['multimídia','multimidia','sonoplastia'],
      'Comunicação'=>['comunicação','comunicacao'],
      'Ministério Infantil'=>['crianças','criancas'],
      'Evangelismo'=>['evangelismo'],
      'Missões'=>['missões','missoes'],
      'Dança e Coreografia'=>['dança','danca'],
      'Teatro'=>['teatro'],
      'Jovens'=>['jovens'],
      'Adolescentes'=>['adolescentes'],
      'Juniores'=>['juniores'],
      'Adultos'=>['adultos'],
      'Melhor Idade'=>['melhor idade'],
      'Ministério da Palavra'=>['ministrante da palavra']
    ];
    public static function text(mixed $x,int $max=250):string {
        $s=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',(string)$x)??'');
        return mb_substr(preg_replace('/\s+/u',' ',$s)??$s,0,$max);
    }
    public static function date(mixed $x):?string {
        $s=trim((string)$x);
        if($s===''||$s==='0000-00-00')return null;
        if(preg_match('/^\d{4}-\d{2}-\d{2}/',$s,$m)) {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$m[0]);
            return $d&&$d->format('Y-m-d')===$m[0]?$m[0]:null;
        }
        if(ctype_digit($s)&&(int)$s>0&&(int)$s<120000)
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.$s.' days')->format('Y-m-d');
        return null;
    }
    public static function interests(array $row):array {
        $raw=mb_strtolower((string)($row['Que tipo de atividade você deseja participar na igreja?']??''),'UTF-8');
        $names=[];
        foreach(self::GROUPS as $name=>$keys)foreach($keys as $key)if(str_contains($raw,$key)){$names[$name]=$name;break;}
        return array_values($names);
    }
    public static function person(array $row):array {
        $name=self::text($row['full_name']??'',190);
        if($name==='')$name=self::text(($row['first_name']??'').' '.($row['last_name']??''),190);
        $phone=self::text($row['phone_1']??'',40)?:self::text($row['phone_2']??'',40);
        $email=mb_strtolower(self::text($row['email']??'',190));
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$email='';
        $cpf=preg_replace('/\D/','',(string)($row['doc_2']??''))??'';
        if(strlen($cpf)!==11||preg_match('/^(\d)\1{10}$/',$cpf))$cpf='';
        $inactive=in_array(mb_strtolower(self::text($row['archived']??'',12)),['1','yes','true','sim'],true);
        return [
           'source_id'=>self::text($row['id']??'',60),'name'=>$name,'phone'=>$phone,'email'=>$email?:null,
           'birth_date'=>self::date($row['birthydate']??''),'cpf'=>$cpf?:null,
           'rg'=>self::text($row['doc_1']??'',45)?:null,
           'marital_status'=>self::text($row['marital_status']??'',40)?:null,
           'address_state'=>self::text($row['id_state']??'',70)?:null,
           'notes'=>self::text($row['notes']??'',4000)?:null,
           'is_baptized'=>!empty($row['baptism_status'])&&$row['baptism_status']!=='0'?1:0,
           'active'=>$inactive?0:1
        ];
    }
    public static function summary(array $rows):array {
        $status=['total'=>0,'active'=>0,'archived'=>0,'missing_phone'=>0,'activity_rows'=>0,
                 'bad'=>0,'duplicate_ids'=>0,'departments'=>[]];
        $seen=[];
        foreach($rows as $row){
            $p=self::person($row);$status['total']++;
            if($p['active'])$status['active']++;else $status['archived']++;
            if($p['phone']==='')$status['missing_phone']++;
            if($p['source_id']===''||$p['name']==='')$status['bad']++;
            if($p['source_id']!==''&&isset($seen[$p['source_id']]))$status['duplicate_ids']++;
            $seen[$p['source_id']]=true;
            if(trim((string)($row['Que tipo de atividade você deseja participar na igreja?']??''))!=='')$status['activity_rows']++;
            foreach(self::interests($row) as $name)$status['departments'][$name]=($status['departments'][$name]??0)+1;
        }
        ksort($status['departments'],SORT_NATURAL|SORT_FLAG_CASE);
        return $status;
    }
}
