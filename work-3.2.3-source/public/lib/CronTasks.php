<?php

declare(strict_types=1);

require_once __DIR__.'/EvolutionClient.php';
require_once __DIR__.'/GroupBroadcastService.php';
require_once __DIR__.'/EngagementNotificationService.php';

final class CronTasks
{
    public function __construct(private PDO $pdo,private ?string $churchId=null) {}

    public function run(): array
    {
        return [
            'birthdays'=>$this->birthdays(),
            'events'=>$this->eventReminders(),
            'prayer_clock'=>$this->prayerClockReminders(),
            'polls'=>$this->scheduledPolls(),
            'campaigns'=>$this->scheduledCampaigns(),
            'raffle_groups'=>$this->raffleGroupReminders(),
            'prayer_clock_groups'=>$this->prayerClockGroupReminders(),
        ];
    }

    private function churches(): array
    {
        if($this->churchId!==null){
            $q=$this->pdo->prepare('SELECT * FROM churches WHERE id=? AND active=1');
            $q->execute([$this->churchId]);
            return $q->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM churches WHERE active=1')->fetchAll();
    }

    private function client(array $church): EvolutionClient
    {
        return new EvolutionClient((string)($church['evolution_api_url']??''),(string)($church['evolution_api_key']??''),(string)($church['evolution_instance_name']??''));
    }

    private function send(array $church,string $phone,string $message): bool
    {
        $client=$this->client($church);if(!$client->configured())return false;
        try{$client->sendText($phone,$message);$this->pdo->prepare('INSERT INTO bot_messages(id,church_id,phone,direction,message,message_type,status) VALUES(?,?,?,"outbound",?,"text","sent")')->execute([app_uuid(),$church['id'],$phone,$message]);return true;}catch(Throwable $e){return false;}
    }

    private function birthdays(): int
    {
        $sent=0;$year=(int)date('Y');$md=date('m-d');
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT m.* FROM members m LEFT JOIN birthday_messages b ON b.member_id=m.id AND b.year=? AND b.message_type="birthday" WHERE m.church_id=? AND m.active=1 AND DATE_FORMAT(m.birth_date,"%m-%d")=? AND b.id IS NULL');
            $q->execute([$year,$church['id'],$md]);
            foreach($q->fetchAll() as $m){$msg='🎉 Feliz aniversário, *'.$m['name'].'*! Que Deus abençoe sua vida, sua família e seus projetos. Receba o carinho de toda a '.$church['name'].'! 🙏🎂';if($this->send($church,$m['phone'],$msg)){$this->pdo->prepare('INSERT INTO birthday_messages(id,church_id,member_id,year,message_type) VALUES(?,?,?,?,"birthday")')->execute([app_uuid(),$church['id'],$m['id'],$year]);$sent++;}}
        }return $sent;
    }

    private function eventReminders(): int
    {
        $sent=0;
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT * FROM events WHERE church_id=? AND active=1 AND reminder_sent=0 AND event_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR) ORDER BY event_date');
            $q->execute([$church['id']]);

            foreach($q->fetchAll() as $ev){
                $notifier=new EngagementNotificationService($this->pdo,dirname(__DIR__));
                $msg=$notifier->eventMessage($ev,true);
                $ok=0;

                try{
                    $groupResult=$notifier->sendEvent($church,$ev,true);
                    $ok+=(int)$groupResult['sent'];
                }catch(Throwable){}

                $m=$this->pdo->prepare('SELECT phone FROM members WHERE church_id=? AND active=1 AND phone<>""');
                $m->execute([$church['id']]);
                foreach(array_unique(array_column($m->fetchAll(),'phone')) as $phone){
                    if($this->send($church,$phone,$msg))$ok++;
                }

                if($ok>0){
                    $this->pdo->prepare('UPDATE events SET reminder_sent=1,last_notification_sent_at=NOW() WHERE id=?')->execute([$ev['id']]);
                    $sent+=$ok;
                }
            }
        }
        return $sent;
    }

    private function prayerClockReminders(): int
    {
        $sent=0;$today=date('Y-m-d');
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT r.*,p.title,p.event_date,p.reminder_minutes_before FROM prayer_clock_registrations r INNER JOIN prayer_clocks p ON p.id=r.prayer_clock_id LEFT JOIN prayer_clock_reminders_sent s ON s.registration_id=r.id AND s.slot_date=? WHERE r.church_id=? AND p.active=1 AND ? BETWEEN p.event_date AND COALESCE(p.end_date,p.event_date) AND s.id IS NULL');$q->execute([$today,$church['id'],$today]);
            foreach($q->fetchAll() as $r){$slot=strtotime($today.' '.$r['slot_time']);$before=(int)($r['reminder_minutes_before']?:30);if(time()<$slot-$before*60||time()>$slot+5*60)continue;$msg='🙏 Lembrete: seu horário no *'.$r['title'].'* é às '.date('H:i',$slot).'. Que este seja um tempo precioso de oração!';if($this->send($church,$r['phone'],$msg)){$this->pdo->prepare('INSERT INTO prayer_clock_reminders_sent(id,prayer_clock_id,registration_id,slot_date) VALUES(?,?,?,?)')->execute([app_uuid(),$r['prayer_clock_id'],$r['id'],$today]);$sent++;}}
        }return $sent;
    }

    private function scheduledCampaigns(): int
    {
        $sent=0;
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT * FROM communication_campaigns WHERE church_id=? AND status="scheduled" AND scheduled_at IS NOT NULL AND scheduled_at<=NOW() ORDER BY scheduled_at');
            $q->execute([$church['id']]);
            foreach($q->fetchAll() as $campaign){
                $ids=json_decode((string)($campaign['target_group_ids']??'[]'),true);
                if(!is_array($ids))$ids=[];
                try{
                    $service=new GroupBroadcastService($this->pdo);
                    $result=$ids
                        ?$service->sendIds($church,$ids,(string)$campaign['message'],(string)$campaign['id'])
                        :$service->sendPurpose($church,'announcements',(string)$campaign['message'],null,(string)$campaign['id']);
                    $status=(int)$result['sent']>0?'sent':'error';
                    $this->pdo->prepare('UPDATE communication_campaigns SET status=?,sent_at=IF(?="sent",NOW(),sent_at) WHERE id=?')->execute([$status,$status,$campaign['id']]);
                    $sent+=(int)$result['sent'];
                }catch(Throwable){
                    $this->pdo->prepare('UPDATE communication_campaigns SET status="error" WHERE id=?')->execute([$campaign['id']]);
                }
            }
        }
        return $sent;
    }

    private function raffleGroupReminders(): int
    {
        $sent=0;
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT * FROM raffles WHERE church_id=? AND status="active" AND draw_date IS NOT NULL AND reminder_sent_at IS NULL AND draw_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR)');
            $q->execute([$church['id']]);

            foreach($q->fetchAll() as $raffle){
                try{
                    $notifier=new EngagementNotificationService($this->pdo,dirname(__DIR__));
                    $result=$notifier->sendRaffle($church,$raffle,true);
                    if((int)$result['sent']>0){
                        $this->pdo->prepare('UPDATE raffles SET reminder_sent_at=NOW(),last_notification_sent_at=NOW() WHERE id=?')->execute([$raffle['id']]);
                        $sent+=(int)$result['sent'];
                    }
                }catch(Throwable){}
            }
        }
        return $sent;
    }

    private function prayerClockGroupReminders(): int
    {
        $sent=0;
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT * FROM prayer_clocks WHERE church_id=? AND active=1 AND group_reminder_sent_at IS NULL AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 1 DAY)');
            $q->execute([$church['id']]);

            foreach($q->fetchAll() as $clock){
                try{
                    $notifier=new EngagementNotificationService($this->pdo,dirname(__DIR__));
                    $result=$notifier->sendPrayerClock($church,$clock,true);
                    if((int)$result['sent']>0){
                        $this->pdo->prepare('UPDATE prayer_clocks SET group_reminder_sent_at=NOW(),last_notification_sent_at=NOW() WHERE id=?')->execute([$clock['id']]);
                        $sent+=(int)$result['sent'];
                    }
                }catch(Throwable){}
            }
        }
        return $sent;
    }

    private function scheduledPolls(): int
    {
        $sent=0;
        foreach($this->churches() as $church){
            $q=$this->pdo->prepare('SELECT * FROM polls WHERE church_id=? AND status IN("scheduled","draft") AND scheduled_at IS NOT NULL AND scheduled_at<=NOW() AND sent_at IS NULL');
            $q->execute([$church['id']]);

            foreach($q->fetchAll() as $poll){
                $opts=json_decode((string)$poll['options'],true)?:[];
                $lines=['📊 *Enquete*',trim((string)$poll['question'])];
                foreach($opts as $i=>$opt){
                    $lines[]='*'.($i+1).'.* '.(is_array($opt)?($opt['text']??json_encode($opt)):$opt);
                }
                $msg=implode("\n",$lines);
                $ok=0;
                $errorMessage=null;

                $groupIds=json_decode((string)($poll['target_groups']??'[]'),true);
                if(!is_array($groupIds))$groupIds=[];
                try{
                    $service=new GroupBroadcastService($this->pdo);
                    $result=$groupIds
                        ?$service->sendIds($church,$groupIds,$msg)
                        :$service->sendPurpose($church,'polls',$msg);
                    $ok+=(int)($result['sent']??0);
                    if($ok===0)$errorMessage=$result['errors'][0]['error']??'Nenhum grupo recebeu a enquete.';
                }catch(Throwable $e){
                    $errorMessage=$e->getMessage();
                }

                if($ok>0){
                    $this->pdo->prepare('UPDATE polls SET status="sent",sent_at=NOW() WHERE id=?')->execute([$poll['id']]);
                    $sent+=$ok;
                }else{
                    $this->pdo->prepare('UPDATE polls SET status="error" WHERE id=?')->execute([$poll['id']]);
                }
            }
        }
        return $sent;
    }
}
