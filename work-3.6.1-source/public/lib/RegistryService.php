<?php
declare(strict_types=1);
require_once __DIR__.'/PortalService.php';

final class RegistryService
{
    public const TYPES=[
        'finance_category'=>['Categorias financeiras','finance'],
        'cost_center'=>['Centros de custos','finance'],
        'contact'=>['Contatos e fornecedores','finance'],
        'person_category'=>['Categorias de pessoas','secretary'],
        'family'=>['Famílias','secretary'],
        'position'=>['Cargos e funções','secretary'],
        'custom_field'=>['Campos personalizados','secretary'],
    ];
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}
    private function guard(string $kind): void {
        if(!isset(self::TYPES[$kind])||!$this->auth->can(self::TYPES[$kind][1])||($this->auth->currentChurch()['id']??'')!==$this->cid)throw new RuntimeException('Cadastro não disponível para este acesso.');
    }
    public function all(string $kind,bool $includeArchived=false): array {
        $this->guard($kind);
        $q=$this->pdo->prepare('SELECT * FROM church_registers WHERE church_id=? AND kind=?'.($includeArchived?'':' AND active=1').' ORDER BY active DESC,name');$q->execute([$this->cid,$kind]);
        return array_map(fn($r)=>[...$r,'data'=>json_decode($r['details']??'{}',true)?:[]],$q->fetchAll());
    }
    public function find(string $kind,string $id,bool $active=true): array {
        $this->guard($kind);
        $q=$this->pdo->prepare('SELECT * FROM church_registers WHERE church_id=? AND kind=? AND id=?'.($active?' AND active=1':''));$q->execute([$this->cid,$kind,$id]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('Cadastro não encontrado nesta igreja.');
        return [...$r,'data'=>json_decode($r['details']??'{}',true)?:[]];
    }
    public function save(string $kind,array $input): string {
        $this->guard($kind);$id=trim((string)($input['id']??''));
        if($id!=='')$this->find($kind,$id,false);
        $name=trim((string)($input['name']??''));
        if($name===''||mb_strlen($name)>190)throw new InvalidArgumentException('Informe um nome de até 190 caracteres.');
        $details=[];
        if($kind==='finance_category'){
            $direction=(string)($input['direction']??'both');
            if(!in_array($direction,['income','expense','both'],true))throw new InvalidArgumentException('Tipo de categoria inválido.');
            $details['direction']=$direction;
        }
        if(in_array($kind,['contact','family'],true)){
            foreach(['phone'=>40,'email'=>190,'document'=>60,'responsible'=>190] as $key=>$max){
                $value=trim((string)($input[$key]??''));if(mb_strlen($value)>$max)throw new InvalidArgumentException('Campo muito longo: '.$key);$details[$key]=$value;
            }
            if($details['email']!==''&&!filter_var($details['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Informe um e-mail válido.');
        }
        if($kind==='custom_field'){
            $type=(string)($input['field_type']??'text');
            if(!in_array($type,['text','date','number','choice'],true))throw new InvalidArgumentException('Tipo de campo inválido.');
            $options=array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/',(string)($input['options']??''))?:[]))));
            if(count($options)>50||mb_strlen(implode('',$options))>4000)throw new InvalidArgumentException('Use até 50 opções e 4.000 caracteres.');
            if($type==='choice'&&!$options)throw new InvalidArgumentException('Preencha as opções do campo.');
            $details=['type'=>$type,'required'=>isset($input['required']),'options'=>$options];
        }
        $notes=trim((string)($input['notes']??''));if(mb_strlen($notes)>4000)throw new InvalidArgumentException('Observações: limite de 4.000 caracteres.');$details['notes']=$notes;
        $this->pdo->beginTransaction();
        try{
            if($id===''){$id=app_uuid();$q=$this->pdo->prepare('INSERT INTO church_registers(id,church_id,kind,name,details) VALUES(?,?,?,?,?)');$q->execute([$id,$this->cid,$kind,$name,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);}
            else {$q=$this->pdo->prepare('UPDATE church_registers SET name=?,details=? WHERE id=? AND church_id=? AND kind=?');$q->execute([$name,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id,$this->cid,$kind]);}
            $this->audit('registry_saved',['kind'=>$kind,'id'=>$id]);$this->pdo->commit();return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function archive(string $kind,string $id,bool $archived): void {
        $this->find($kind,$id,false);
        $this->pdo->beginTransaction();
        try{$this->pdo->prepare('UPDATE church_registers SET active=? WHERE id=? AND church_id=? AND kind=?')->execute([$archived?0:1,$id,$this->cid,$kind]);$this->audit($archived?'registry_archived':'registry_restored',['kind'=>$kind,'id'=>$id]);$this->pdo->commit();}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function attributes(array $input,array $previous=[]): array {
        $output=$previous;
        foreach($this->all('custom_field') as $field){
            $id=$field['id'];if(isset($input[$id])&&!is_scalar($input[$id]))throw new InvalidArgumentException('Valor de campo inválido.');$value=trim((string)($input[$id]??''));$spec=$field['data'];
            if($value===''&&!empty($spec['required']))throw new InvalidArgumentException('Preencha: '.$field['name']);
            if(mb_strlen($value)>2000)throw new InvalidArgumentException('Valor muito longo: '.$field['name']);
            if($value!==''){
                if($spec['type']==='date')PortalService::date($value);
                if($spec['type']==='number'&&!preg_match('/^-?\d{1,12}(?:[.,]\d{1,6})?$/D',$value))throw new InvalidArgumentException('Número inválido: '.$field['name']);
                if($spec['type']==='choice'&&!in_array($value,$spec['options'],true))throw new InvalidArgumentException('Opção inválida: '.$field['name']);
            }
            $output[$id]=$value;
        }
        return $output;
    }
    private function audit(string $action,array $details): void {
        $this->pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')->execute([app_uuid(),$this->auth->requireLogin()['id'],$this->cid,$action,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
}
