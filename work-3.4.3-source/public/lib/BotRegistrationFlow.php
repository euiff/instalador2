<?php

declare(strict_types=1);

final class BotRegistrationFlow
{
    public function __construct(private PDO $pdo) {}

    public function start(string $churchId, string $phone, string $senderName): string
    {
        $data = ['phone'=>$phone,'sender_name'=>$senderName];
        $this->saveState($churchId,$phone,'reg_congregation',$data);
        $q=$this->pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY sort_order,name');
        $q->execute([$churchId]);$rows=$q->fetchAll();
        if(!$rows){
            $data['congregation_id']=null;
            $this->saveState($churchId,$phone,'reg_name',$data);
            return '📝 Vamos fazer seu cadastro. Digite seu *nome completo*:';
        }
        $lines=['📝 Cadastro de membro','Escolha sua congregação:'];$map=[];$i=1;
        foreach($rows as $r){$lines[]="*$i.* {$r['name']}";$map[(string)$i]=$r['id'];$i++;}
        $data['congregations']=$map;$this->saveState($churchId,$phone,'reg_congregation',$data);
        $lines[]='*0.* Sede / Não informada';
        return implode("\n",$lines);
    }

    public function handle(array $state, string $text, ?string $photoUrl = null): array
    {
        $churchId=$state['church_id'];$phone=$state['phone'];$step=$state['state'];$data=json_decode((string)($state['data']??'{}'),true)?:[];
        $value=trim($text);$lower=mb_strtolower($value,'UTF-8');
        if(in_array($lower,['cancelar','sair','menu'],true)){
            $this->clear($state['id']);
            return ['done'=>true,'reply'=>'Cadastro cancelado. Digite *menu* para voltar ao início.'];
        }
        if($lower==='pular')$value='';

        switch($step){
            case 'reg_congregation':
                if($value==='0')$data['congregation_id']=null;
                elseif(isset($data['congregations'][$value]))$data['congregation_id']=$data['congregations'][$value];
                else return ['done'=>false,'reply'=>'Opção inválida. Digite o número da congregação ou *0* para sede.'];
                unset($data['congregations']);return $this->next($churchId,$phone,'reg_name',$data,'Digite seu *nome completo*:');
            case 'reg_name':
                if($value==='')return ['done'=>false,'reply'=>'O nome é obrigatório. Digite seu *nome completo*:'];
                $data['name']=$value;return $this->next($churchId,$phone,'reg_cpf',$data,'Digite seu *CPF* ou *pular*:');
            case 'reg_cpf':$data['cpf']=$value?:null;return $this->next($churchId,$phone,'reg_rg',$data,'Digite seu *RG* ou *pular*:');
            case 'reg_rg':$data['rg']=$value?:null;return $this->next($churchId,$phone,'reg_birth',$data,'Digite sua *data de nascimento* (DD/MM/AAAA) ou *pular*:');
            case 'reg_birth':
                $data['birth_date']=$this->dateBr($value);if($value!==''&&!$data['birth_date'])return ['done'=>false,'reply'=>'Data inválida. Use DD/MM/AAAA ou digite *pular*.'];
                return $this->next($churchId,$phone,'reg_marital',$data,"Qual seu *estado civil*?\n*1.* Solteiro(a)\n*2.* Casado(a)\n*3.* Divorciado(a)\n*4.* Viúvo(a)\n*5.* União Estável\nOu digite *pular*.");
            case 'reg_marital':
                $map=['1'=>'Solteiro(a)','2'=>'Casado(a)','3'=>'Divorciado(a)','4'=>'Viúvo(a)','5'=>'União Estável'];$data['marital_status']=$map[$value]??($value?:null);
                if(in_array($value,['2','5'],true))return $this->next($churchId,$phone,'reg_spouse',$data,'Digite o *nome do cônjuge* ou *pular*:');
                return $this->next($churchId,$phone,'reg_email',$data,'Digite seu *e-mail* ou *pular*:');
            case 'reg_spouse':$data['spouse_name']=$value?:null;return $this->next($churchId,$phone,'reg_email',$data,'Digite seu *e-mail* ou *pular*:');
            case 'reg_email':$data['email']=$value?:null;return $this->next($churchId,$phone,'reg_cep',$data,'Digite seu *CEP* ou *pular*:');
            case 'reg_cep':
                $data['cep']=$value?:null;if($value!=='')$data=array_merge($data,$this->lookupCep($value));
                return $this->next($churchId,$phone,'reg_role',$data,"Qual seu *cargo/função*?\n*1.* Membro\n*2.* Obreiro(a)\n*3.* Diácono(isa)\n*4.* Presbítero\n*5.* Pastor(a)\n*6.* Evangelista\n*7.* Missionário(a)\nOu digite *pular*.");
            case 'reg_role':
                $map=['1'=>'Membro','2'=>'Obreiro(a)','3'=>'Diácono(isa)','4'=>'Presbítero','5'=>'Pastor(a)','6'=>'Evangelista','7'=>'Missionário(a)'];$data['role']=$map[$value]??($value?:null);
                return $this->next($churchId,$phone,'reg_department',$data,"Qual *departamento* participa?\n*1.* Nenhum\n*2.* Louvor\n*3.* Mídia\n*4.* EBD\n*5.* Jovens\n*6.* Crianças\n*7.* Mulheres\n*8.* Homens\nOu digite *pular*.");
            case 'reg_department':
                $map=['1'=>null,'2'=>'Louvor','3'=>'Mídia','4'=>'EBD','5'=>'Jovens','6'=>'Crianças','7'=>'Mulheres','8'=>'Homens'];$data['department']=array_key_exists($value,$map)?$map[$value]:($value?:null);
                return $this->next($churchId,$phone,'reg_baptized',$data,"Você é *batizado nas águas*?\n*1.* Sim\n*2.* Não");
            case 'reg_baptized':
                if(!in_array($value,['1','2'],true))return ['done'=>false,'reply'=>'Digite *1* para Sim ou *2* para Não.'];
                $data['is_baptized']=$value==='1';
                if($data['is_baptized'])return $this->next($churchId,$phone,'reg_baptism_date',$data,'Digite a *data do batismo* (DD/MM/AAAA) ou *pular*:');
                return $this->next($churchId,$phone,'reg_photo',$data,'📷 Envie uma *foto sua* para a carteirinha ou digite *pular*.');
            case 'reg_baptism_date':
                $data['baptism_date']=$this->dateBr($value);if($value!==''&&!$data['baptism_date'])return ['done'=>false,'reply'=>'Data inválida. Use DD/MM/AAAA ou *pular*.'];
                return $this->next($churchId,$phone,'reg_baptism_church',$data,'Em qual *igreja* foi batizado? Ou *pular*:');
            case 'reg_baptism_church':$data['baptism_church']=$value?:null;return $this->next($churchId,$phone,'reg_baptism_pastor',$data,'Nome do *pastor que batizou* ou *pular*:');
            case 'reg_baptism_pastor':$data['baptism_pastor']=$value?:null;return $this->next($churchId,$phone,'reg_photo',$data,'📷 Envie uma *foto sua* para a carteirinha ou digite *pular*.');
            case 'reg_photo':
                if($photoUrl){$data['photo_url']=$photoUrl;return $this->confirm($churchId,$phone,$data);}
                if($value==='')return $this->confirm($churchId,$phone,$data);
                return ['done'=>false,'reply'=>'Envie uma imagem ou digite *pular* para continuar sem foto.'];
            case 'reg_confirm':
                if(in_array($lower,['1','sim','s'],true))return $this->persist($churchId,$phone,$data,$state['id']);
                if(in_array($lower,['2','não','nao','n'],true)){ $this->clear($state['id']); return ['done'=>true,'reply'=>'Cadastro cancelado. Digite *cadastro* quando quiser começar novamente.']; }
                return ['done'=>false,'reply'=>'Confirme com *1* para salvar ou *2* para cancelar.'];
        }
        return ['done'=>false,'reply'=>'Digite *menu* para voltar ao início.'];
    }

    private function confirm(string $churchId,string $phone,array $data):array
    {
        $this->saveState($churchId,$phone,'reg_confirm',$data);
        $summary="✅ Confira seus dados:\n*Nome:* ".($data['name']??'')."\n*CPF:* ".($data['cpf']??'—')."\n*Cargo:* ".($data['role']??'—')."\n*Departamento:* ".($data['department']??'—')."\n*Batizado:* ".(!empty($data['is_baptized'])?'Sim':'Não')."\n*Foto:* ".(!empty($data['photo_url'])?'Recebida':'Não enviada')."\n\n*1.* Confirmar e salvar\n*2.* Cancelar";
        return ['done'=>false,'reply'=>$summary];
    }

    private function persist(string $churchId,string $phone,array $d,string $stateId):array
    {
        $exists=$this->pdo->prepare('SELECT id FROM members WHERE church_id=? AND phone=? LIMIT 1');$exists->execute([$churchId,$phone]);
        if($exists->fetchColumn()){ $this->clear($stateId); return ['done'=>true,'reply'=>'Você já possui cadastro nesta igreja.']; }
        $q=$this->pdo->prepare('INSERT INTO members(id,church_id,congregation_id,name,phone,email,birth_date,cpf,rg,cep,address_street,address_neighborhood,address_city,address_state,spouse_name,marital_status,role,department,is_baptized,baptism_date,baptism_church,baptism_pastor,photo_url,registration_status,registration_step,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"complete","complete",1)');
        $q->execute([app_uuid(),$churchId,$d['congregation_id']??null,$d['name']??($d['sender_name']??'Membro'),$phone,$d['email']??null,$d['birth_date']??null,$d['cpf']??null,$d['rg']??null,$d['cep']??null,$d['address_street']??null,$d['address_neighborhood']??null,$d['address_city']??null,$d['address_state']??null,$d['spouse_name']??null,$d['marital_status']??null,$d['role']??null,$d['department']??null,!empty($d['is_baptized'])?1:0,$d['baptism_date']??null,$d['baptism_church']??null,$d['baptism_pastor']??null,$d['photo_url']??null]);
        $this->clear($stateId);
        return ['done'=>true,'reply'=>'🎉 Cadastro concluído com sucesso! Seja bem-vindo(a) à nossa família. Deus abençoe! 🙏'];
    }

    private function next(string $churchId,string $phone,string $state,array $data,string $reply):array
    { $this->saveState($churchId,$phone,$state,$data); return ['done'=>false,'reply'=>$reply]; }

    private function saveState(string $churchId,string $phone,string $state,array $data):void
    {
        $q=$this->pdo->prepare('INSERT INTO bot_conversation_states(id,church_id,phone,state,data,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE state=VALUES(state),data=VALUES(data),expires_at=VALUES(expires_at)');
        $q->execute([app_uuid(),$churchId,$phone,$state,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function clear(string $id):void { $this->pdo->prepare('DELETE FROM bot_conversation_states WHERE id=?')->execute([$id]); }

    private function dateBr(string $value):?string
    {
        if($value==='')return null;
        $dt=DateTime::createFromFormat('d/m/Y',$value);
        return $dt&&$dt->format('d/m/Y')===$value?$dt->format('Y-m-d'):null;
    }

    private function lookupCep(string $value):array
    {
        $cep=preg_replace('/\D+/','',$value);if(strlen($cep)!==8)return [];
        $ch=curl_init('https://viacep.com.br/ws/'.$cep.'/json/');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$raw=curl_exec($ch);curl_close($ch);$d=json_decode((string)$raw,true);
        if(!is_array($d)||!empty($d['erro']))return [];
        return ['address_street'=>$d['logradouro']??null,'address_neighborhood'=>$d['bairro']??null,'address_city'=>$d['localidade']??null,'address_state'=>$d['uf']??null];
    }
}
