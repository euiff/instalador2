<?php

declare(strict_types=1);

final class BotEditFlow
{
    public function __construct(private PDO $pdo) {}

    public function start(string $churchId, string $phone): string
    {
        $m=$this->member($churchId,$phone);
        if(!$m) return 'Não encontrei seu cadastro. Digite *cadastro* para começar.';
        $this->saveState($churchId,$phone,'edit_select',['member_id'=>$m['id']]);
        return "✏️ O que deseja alterar?\n*1.* Nome\n*2.* E-mail\n*3.* Telefone\n*4.* CEP/endereço\n*5.* Cargo/Função\n*6.* Departamento\n*7.* Foto\n*0.* Cancelar";
    }

    public function handle(array $state, string $text, ?string $photoUrl = null): array
    {
        $churchId=$state['church_id'];$phone=$state['phone'];$step=$state['state'];$data=json_decode((string)($state['data']??'{}'),true)?:[];
        $memberId=$data['member_id']??null;
        if(!$memberId){$this->clear($state['id']);return ['done'=>true,'reply'=>'Cadastro não encontrado.'];}
        $value=trim($text);$lower=mb_strtolower($value,'UTF-8');
        if(in_array($lower,['0','cancelar','sair','menu'],true)){$this->clear($state['id']);return ['done'=>true,'reply'=>'Edição cancelada. Digite *menu* para voltar ao início.'];}

        if($step==='edit_select'){
            $map=['1'=>'edit_name','2'=>'edit_email','3'=>'edit_phone','4'=>'edit_cep','5'=>'edit_role','6'=>'edit_department','7'=>'edit_photo'];
            if(!isset($map[$value])) return ['done'=>false,'reply'=>'Opção inválida. Escolha de *1* a *7* ou *0* para cancelar.'];
            $next=$map[$value];$this->saveState($churchId,$phone,$next,$data);
            $prompt=match($next){
                'edit_name'=>'Digite seu novo *nome completo*:',
                'edit_email'=>'Digite seu novo *e-mail* ou *pular* para remover:',
                'edit_phone'=>'Digite seu novo *telefone* com DDD:',
                'edit_cep'=>'Digite seu novo *CEP*:',
                'edit_role'=>'Digite seu novo *cargo/função*:',
                'edit_department'=>'Digite seu novo *departamento* ou *pular* para remover:',
                'edit_photo'=>'📷 Envie agora a nova *foto* pelo WhatsApp.',
            };
            return ['done'=>false,'reply'=>$prompt];
        }

        if($step==='edit_photo'){
            if(!$photoUrl) return ['done'=>false,'reply'=>'Envie uma imagem ou digite *cancelar*.'];
            $this->pdo->prepare('UPDATE members SET photo_url=? WHERE id=? AND church_id=?')->execute([$photoUrl,$memberId,$churchId]);
            $this->clear($state['id']);return ['done'=>true,'reply'=>'✅ Foto atualizada com sucesso.'];
        }

        $field=null;$dbValue=$value;
        switch($step){
            case 'edit_name': if($value==='')return ['done'=>false,'reply'=>'O nome não pode ficar vazio.']; $field='name'; break;
            case 'edit_email': $field='email'; $dbValue=$lower==='pular'||$value===''?null:$value; if($dbValue&&!filter_var($dbValue,FILTER_VALIDATE_EMAIL))return ['done'=>false,'reply'=>'E-mail inválido. Tente novamente ou digite *pular*.']; break;
            case 'edit_phone': $digits=preg_replace('/\D+/','',$value)?:''; if(strlen($digits)<10)return ['done'=>false,'reply'=>'Telefone inválido. Informe DDD + número.']; $field='phone';$dbValue=$digits; break;
            case 'edit_role': $field='role';$dbValue=$lower==='pular'||$value===''?null:$value; break;
            case 'edit_department': $field='department';$dbValue=$lower==='pular'||$value===''?null:$value; break;
            case 'edit_cep':
                $addr=$this->lookupCep($value);if(!$addr)return ['done'=>false,'reply'=>'CEP não encontrado. Tente novamente.'];
                $q=$this->pdo->prepare('UPDATE members SET cep=?,address_street=?,address_neighborhood=?,address_city=?,address_state=? WHERE id=? AND church_id=?');
                $q->execute([preg_replace('/\D+/','',$value),$addr['address_street'],$addr['address_neighborhood'],$addr['address_city'],$addr['address_state'],$memberId,$churchId]);
                $this->clear($state['id']);return ['done'=>true,'reply'=>'✅ Endereço atualizado com sucesso.'];
            default:return ['done'=>false,'reply'=>'Digite *menu* para voltar ao início.'];
        }
        $sql='UPDATE members SET '.$field.'=? WHERE id=? AND church_id=?';$this->pdo->prepare($sql)->execute([$dbValue,$memberId,$churchId]);
        $this->clear($state['id']);return ['done'=>true,'reply'=>'✅ Cadastro atualizado com sucesso.'];
    }

    private function member(string $churchId,string $phone):?array
    { $q=$this->pdo->prepare('SELECT * FROM members WHERE church_id=? AND phone=? LIMIT 1');$q->execute([$churchId,$phone]);$m=$q->fetch();return $m?:null; }

    private function saveState(string $churchId,string $phone,string $state,array $data):void
    { $q=$this->pdo->prepare('INSERT INTO bot_conversation_states(id,church_id,phone,state,data,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE state=VALUES(state),data=VALUES(data),expires_at=VALUES(expires_at)');$q->execute([app_uuid(),$churchId,$phone,$state,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); }

    private function clear(string $id):void { $this->pdo->prepare('DELETE FROM bot_conversation_states WHERE id=?')->execute([$id]); }

    private function lookupCep(string $value):array
    {
        $cep=preg_replace('/\D+/','',$value);if(strlen($cep)!==8)return [];
        $ch=curl_init('https://viacep.com.br/ws/'.$cep.'/json/');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$raw=curl_exec($ch);curl_close($ch);$d=json_decode((string)$raw,true);
        if(!is_array($d)||!empty($d['erro']))return [];
        return ['address_street'=>$d['logradouro']??null,'address_neighborhood'=>$d['bairro']??null,'address_city'=>$d['localidade']??null,'address_state'=>$d['uf']??null];
    }
}
