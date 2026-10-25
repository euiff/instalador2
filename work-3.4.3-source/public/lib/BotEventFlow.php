<?php

declare(strict_types=1);

final class BotEventFlow
{
    public function __construct(private PDO $pdo) {}

    public function start(string $churchId,string $phone,array $settings): string
    {
        $preferred=trim((string)($settings['event_registration_event_id']??''));
        if(!empty($settings['event_registration_enabled'])&&$preferred!==''){
            $q=$this->pdo->prepare('SELECT id,title,event_date,location,capacity FROM events WHERE id=? AND church_id=? AND active=1 LIMIT 1');
            $q->execute([$preferred,$churchId]);
            if($event=$q->fetch()){
                $this->saveState($churchId,$phone,'event_name',['event_id'=>$event['id']]);
                return $this->eventSummary($event)."

✍️ *Inscrição pelo WhatsApp ativada.*
Digite seu *nome completo*:";
            }
        }

        $q=$this->pdo->prepare('SELECT id,title,event_date,location,capacity FROM events WHERE church_id=? AND active=1 AND event_date>=NOW() ORDER BY event_date LIMIT 8');
        $q->execute([$churchId]);$rows=$q->fetchAll();
        if(!$rows)return '📅 Não há eventos futuros cadastrados no momento.';

        $map=[];$lines=['📅 *PRÓXIMOS EVENTOS*'];
        foreach($rows as $i=>$event){
            $n=$i+1;$map[(string)$n]=$event['id'];
            $lines[]='*'.$n.'.* '.$event['title'].' — '.date('d/m/Y H:i',strtotime((string)$event['event_date'])).($event['location']?' — '.$event['location']:'');
        }
        $lines[]='';
        if(empty($settings['event_registration_enabled'])){
            $lines[]='ℹ️ A inscrição pelo WhatsApp não está ativada no momento.';
            $lines[]='Para participar, fale com a secretaria da igreja.';
            return implode("\n",$lines);
        }

        $lines[]='Para se inscrever, envie o *número do evento*.';
        $lines[]='Digite *0* para voltar ao menu.';
        $this->saveState($churchId,$phone,'event_choose',['events'=>$map]);
        return implode("\n",$lines);
    }

    public function handle(array $state,string $text): array
    {
        $churchId=(string)$state['church_id'];$phone=(string)$state['phone'];$step=(string)$state['state'];
        $data=json_decode((string)($state['data']??'{}'),true)?:[];
        $value=trim($text);$lower=mb_strtolower($value,'UTF-8');
        if(in_array($lower,['cancelar','sair','menu','0'],true)){
            $this->clear((string)$state['id']);
            return ['done'=>true,'reply'=>'Inscrição encerrada. Digite *menu* para voltar ao início.'];
        }

        if($step==='event_choose'){
            $eventId=$data['events'][$value]??null;
            if(!$eventId)return ['done'=>false,'reply'=>'Escolha um dos números da lista ou digite *0* para voltar.'];
            $data=['event_id'=>$eventId];
            $this->saveState($churchId,$phone,'event_name',$data);
            return ['done'=>false,'reply'=>'Digite seu *nome completo*:'];
        }

        if($step==='event_name'){
            if($value==='')return ['done'=>false,'reply'=>'O nome é obrigatório. Digite seu *nome completo*:'];
            $data['name']=$value;$this->saveState($churchId,$phone,'event_email',$data);
            return ['done'=>false,'reply'=>'Digite seu *e-mail* ou *pular*:'];
        }

        if($step==='event_email'){
            $data['email']=$lower==='pular'||$value===''?null:$value;
            $this->saveState($churchId,$phone,'event_guests',$data);
            return ['done'=>false,'reply'=>'Quantos *convidados adicionais* vão com você? Digite *0* se for sozinho(a).'];
        }

        if($step==='event_guests'){
            if(!preg_match('/^\d{1,2}$/',$value))return ['done'=>false,'reply'=>'Digite somente a quantidade de convidados. Ex.: *0*, *1* ou *2*.'];
            $data['guests_count']=(int)$value;

            $q=$this->pdo->prepare('SELECT * FROM events WHERE id=? AND church_id=? AND active=1 LIMIT 1');
            $q->execute([(string)($data['event_id']??''),$churchId]);$event=$q->fetch();
            if(!$event){$this->clear((string)$state['id']);return ['done'=>true,'reply'=>'Este evento não está mais disponível.'];}

            if(!empty($event['capacity'])){
                $c=$this->pdo->prepare('SELECT COALESCE(SUM(1+guests_count),0) FROM event_registrations WHERE event_id=? AND church_id=?');
                $c->execute([$event['id'],$churchId]);
                $used=(int)$c->fetchColumn();$wanted=1+(int)$data['guests_count'];
                if($used+$wanted>(int)$event['capacity'])return ['done'=>false,'reply'=>'Não há vagas suficientes para essa quantidade de pessoas. Informe um número menor de convidados.'];
            }

            $exists=$this->pdo->prepare('SELECT id FROM event_registrations WHERE event_id=? AND church_id=? AND phone=? LIMIT 1');
            $exists->execute([$event['id'],$churchId,$phone]);
            if($exists->fetchColumn()){
                $this->clear((string)$state['id']);
                return ['done'=>true,'reply'=>'✅ Você já possui inscrição neste evento.'];
            }

            $q=$this->pdo->prepare('INSERT INTO event_registrations(id,church_id,event_id,name,phone,email,guests_count) VALUES(?,?,?,?,?,?,?)');
            $q->execute([app_uuid(),$churchId,$event['id'],$data['name'],$phone,$data['email']??null,(int)$data['guests_count']]);
            $this->clear((string)$state['id']);

            return ['done'=>true,'reply'=>"✅ *Inscrição confirmada!*

".$this->eventSummary($event)."
👤 ".$data['name']."
👥 Convidados adicionais: ".(int)$data['guests_count']];
        }

        return ['done'=>false,'reply'=>'Digite *0* para voltar ao menu.'];
    }

    private function eventSummary(array $event): string
    {
        $msg='📅 *'.$event['title'].'*';
        if(!empty($event['event_date']))$msg.="
🗓️ ".date('d/m/Y H:i',strtotime((string)$event['event_date']));
        if(!empty($event['location']))$msg.="
📍 ".$event['location'];
        return $msg;
    }

    private function saveState(string $churchId,string $phone,string $state,array $data): void
    {
        $q=$this->pdo->prepare('INSERT INTO bot_conversation_states(id,church_id,phone,state,data,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE state=VALUES(state),data=VALUES(data),expires_at=VALUES(expires_at)');
        $q->execute([app_uuid(),$churchId,$phone,$state,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function clear(string $id): void
    {
        $this->pdo->prepare('DELETE FROM bot_conversation_states WHERE id=?')->execute([$id]);
    }
}
