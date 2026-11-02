<?php
declare(strict_types=1);

final class MemberSelfService
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public function member(): array
    {
        $membership=$this->auth->currentMembership();
        $personId=trim((string)($membership['person_id']??''));
        if($personId==='')throw new RuntimeException('Seu login ainda não está vinculado a um cadastro de membro.');

        $q=$this->q("SELECT m.*,c.name congregation_name
          FROM members m
          LEFT JOIN congregations c ON c.id=m.congregation_id AND c.church_id=m.church_id
          WHERE m.id=? AND m.church_id=? AND m.active=1 LIMIT 1",[$personId,$this->cid]);
        $member=$q->fetch();
        if(!$member)throw new RuntimeException('Cadastro pessoal não encontrado nesta igreja.');
        return $member;
    }

    public function update(array $input): void
    {
        $member=$this->member();

        $phone=trim((string)($input['phone']??''));
        $email=mb_strtolower(trim((string)($input['email']??'')))?:null;
        $birth=trim((string)($input['birth_date']??''))?:null;
        $cep=trim((string)($input['cep']??''))?:null;
        $street=trim((string)($input['address_street']??''))?:null;
        $number=trim((string)($input['address_number']??''))?:null;
        $neighborhood=trim((string)($input['address_neighborhood']??''))?:null;
        $city=trim((string)($input['address_city']??''))?:null;
        $state=trim((string)($input['address_state']??''))?:null;
        $marital=trim((string)($input['marital_status']??''))?:null;
        $spouse=trim((string)($input['spouse_name']??''))?:null;
        $naturalness=trim((string)($input['naturalness']??''))?:null;

        if($phone==='')throw new InvalidArgumentException('Informe um telefone/WhatsApp.');
        if(mb_strlen($phone)>40)throw new InvalidArgumentException('Telefone muito longo.');
        if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('E-mail inválido.');
        if($birth!==null){
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$birth);
            if(!$d||$d->format('Y-m-d')!==$birth)throw new InvalidArgumentException('Data de nascimento inválida.');
            if($d>new DateTimeImmutable('today'))throw new InvalidArgumentException('Data de nascimento não pode estar no futuro.');
        }
        if($state!==null&&mb_strlen($state)>80)throw new InvalidArgumentException('Estado/UF inválido.');

        $this->q("UPDATE members SET
          phone=?,email=?,birth_date=?,cep=?,address_street=?,address_number=?,address_neighborhood=?,address_city=?,address_state=?,
          marital_status=?,spouse_name=?,naturalness=?
          WHERE id=? AND church_id=?",[
            $phone,$email,$birth,$cep,$street,$number,$neighborhood,$city,$state,$marital,$spouse,$naturalness,
            $member['id'],$this->cid
        ]);

        // Mantém o perfil do usuário coerente com o cadastro pessoal sem
        // alterar e-mail de login automaticamente.
        $user=$this->auth->requireLogin();
        $this->q("UPDATE profiles SET phone=?,full_name=? WHERE user_id=?",[
            $phone,$member['name'],$user['id']
        ]);

        try{
            $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[
                app_uuid(),$user['id'],$this->cid,'member_self_profile_updated',
                json_encode(['member_id'=>$member['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        }catch(Throwable){}
    }

    public function completeness(array $member): int
    {
        $fields=['phone','email','birth_date','cep','address_street','address_city','address_state','marital_status','naturalness'];
        $filled=0;
        foreach($fields as $field)if(trim((string)($member[$field]??''))!=='')$filled++;
        return (int)round(($filled/count($fields))*100);
    }
}
