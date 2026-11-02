<?php

declare(strict_types=1);

final class SpiritualFlow
{
    public function __construct(private PDO $pdo, private AiClient $ai) {}

    public function start(string $churchId,string $phone,string $name=''): string
    {
        $this->save($churchId,$phone,'spiritual_menu',['name'=>$name]);
        return "🙏 *Apoio espiritual*\n\n*1.* Pedido de oração\n*2.* Aconselhamento pastoral\n*0.* Voltar ao menu";
    }

    public function startPrayer(string $churchId,string $phone,string $name=''): string
    {
        $this->save($churchId,$phone,'spiritual_prayer',['name'=>$name]);
        return "🙏 *PEDIDO DE ORAÇÃO*\n\nEscreva seu pedido com suas palavras. Ele ficará registrado para acompanhamento da igreja.";
    }

    public function startCounseling(string $churchId,string $phone,string $name=''): string
    {
        $this->save($churchId,$phone,'spiritual_counseling',['name'=>$name]);
        return "💬 *ACONSELHAMENTO PASTORAL*\n\nConte, com suas palavras, a situação sobre a qual você gostaria de receber uma orientação cristã.\n\nSe for uma emergência ou risco imediato, procure ajuda presencial ou serviço de emergência.";
    }

    public function handle(array $church,array $settings,array $state,string $text): array
    {
        $cid=(string)$church['id'];$phone=(string)$state['phone'];$id=(string)$state['id'];
        $data=json_decode((string)($state['data']??'{}'),true)?:[];$name=(string)($data['name']??'');
        $value=trim($text);$norm=mb_strtolower($value,'UTF-8');

        if(in_array($norm,['0','menu','voltar','cancelar'],true)){
            $this->clear($id);
            return ['done'=>true,'reply'=>'Digite *menu* para ver as opções principais.'];
        }

        switch((string)$state['state']){
            case 'spiritual_menu':
                if($value==='1'){$this->save($cid,$phone,'spiritual_prayer',$data);return ['done'=>false,'reply'=>'🙏 Escreva seu *pedido de oração*.'];}
                if($value==='2'){$this->save($cid,$phone,'spiritual_counseling',$data);return ['done'=>false,'reply'=>'💬 Conte, com suas palavras, sobre o que você gostaria de receber uma orientação cristã.'];}
                return ['done'=>false,'reply'=>"Escolha uma opção:\n*1.* Pedido de oração\n*2.* Aconselhamento pastoral\n*0.* Voltar"];

            case 'spiritual_prayer':
                if($value==='')return ['done'=>false,'reply'=>'Escreva seu pedido de oração.'];
                $m=$this->pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND phone=? LIMIT 1');$m->execute([$cid,$phone]);$member=$m->fetch();
                $aiResponse=!empty($church['ai_enabled'])&&$this->ai->configured()?$this->ai->spiritualResponse($value,(string)$church['name'],(string)($settings['ai_prompt']??'')):null;
                $q=$this->pdo->prepare('INSERT INTO prayer_requests(id,church_id,member_id,phone,name,request,ai_response,status,responded_at) VALUES(?,?,?,?,?,?,?,"pending",?)');
                $q->execute([app_uuid(),$cid,$member['id']??null,$phone,$member['name']??($name?:null),$value,$aiResponse,$aiResponse?date('Y-m-d H:i:s'):null]);
                $this->clear($id);
                return ['done'=>true,'reply'=>'🙏 Pedido de oração recebido. Estaremos orando por você.'.($aiResponse?"\n\n".$aiResponse:'')];

            case 'spiritual_counseling':
                if($value==='')return ['done'=>false,'reply'=>'Conte um pouco sobre a situação para eu poder orientar melhor.'];
                $prompt=(string)($settings['ai_prompt']??'');
                $response=$this->ai->configured()?$this->ai->spiritualResponse('Aconselhamento: '.$value,(string)$church['name'],$prompt):null;
                $this->clear($id);
                return ['done'=>true,'reply'=>$response?:'🙏 Sua mensagem foi recebida. Procure também um pastor ou líder da igreja para acompanhamento pessoal quando necessário.'];

        }

        $this->clear($id);
        return ['done'=>true,'reply'=>'Digite *menu* para continuar.'];
    }

    private function save(string $churchId,string $phone,string $state,array $data):void
    {
        $q=$this->pdo->prepare('INSERT INTO bot_conversation_states(id,church_id,phone,state,data,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE state=VALUES(state),data=VALUES(data),expires_at=VALUES(expires_at)');
        $q->execute([app_uuid(),$churchId,$phone,$state,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function clear(string $id):void
    {
        $this->pdo->prepare('DELETE FROM bot_conversation_states WHERE id=?')->execute([$id]);
    }
}
