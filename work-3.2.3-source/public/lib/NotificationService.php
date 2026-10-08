<?php

declare(strict_types=1);

require_once __DIR__.'/GroupBroadcastService.php';

final class NotificationService
{
    public function __construct(private PDO $pdo) {}

    public function notifyEventCreated(array $church,array $event): int
    {
        $msg="📅 *Novo evento cadastrado*\n\n*".$event['title'].'*';
        if(!empty($event['event_date']))$msg.="\n🗓️ ".date('d/m/Y H:i',strtotime((string)$event['event_date']));
        if(!empty($event['location']))$msg.="\n📍 ".$event['location'];
        if(!empty($event['theme']))$msg.="\n📖 Tema: ".$event['theme'];
        if(!empty($event['speaker_name']))$msg.="\n🎤 ".$event['speaker_name'];

        $service=new GroupBroadcastService($this->pdo);
        $selected=json_decode((string)($event['target_group_ids']??'[]'),true);
        $result=is_array($selected)&&$selected
            ?$service->sendIds($church,$selected,$msg)
            :$service->sendPurpose($church,'events',$msg,$event['congregation_id']??null);
        return (int)$result['sent'];
    }

    public function notifyServiceCreated(array $church,array $service): int
    {
        $days=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
        $msg="⛪ *Novo culto/reunião cadastrado*\n\n*".$service['name'].'*';
        if(isset($service['day_of_week'])&&$service['day_of_week']!==null)$msg.="\n📅 ".($days[(int)$service['day_of_week']]??'');
        if(!empty($service['time']))$msg.="\n⏰ ".substr((string)$service['time'],0,5);
        if(!empty($service['description']))$msg.="\n\n".$service['description'];

        $result=(new GroupBroadcastService($this->pdo))
            ->sendPurpose($church,'events',$msg,$service['congregation_id']??null);
        return (int)$result['sent'];
    }
}
