<?php

declare(strict_types=1);

require_once __DIR__.'/GroupBroadcastService.php';
require_once __DIR__.'/UploadService.php';

final class EngagementNotificationService
{
    public function __construct(
        private PDO $pdo,
        private string $appRoot
    ) {}

    public function prayerClockMessage(array $clock,bool $reminder=false): string
    {
        $title=trim((string)($clock['title']??'Relógio de Oração'));
        $date=!empty($clock['event_date'])?date('d/m/Y',strtotime((string)$clock['event_date'])):'';
        $endDate=!empty($clock['end_date'])?date('d/m/Y',strtotime((string)$clock['end_date'])):'';
        $start=sprintf('%02d:00',(int)($clock['start_hour']??0));
        $end=(int)($clock['end_hour']??24);
        $end=sprintf('%02d:00',$end===24?0:$end);

        $msg=$reminder?'🔔 *LEMBRETE · RELÓGIO DE ORAÇÃO*':'🙏 *RELÓGIO DE ORAÇÃO*';
        $msg.="

*".$title."*";
        if($date!=='')$msg.="
📅 ".$date.($endDate!==''&&$endDate!==$date?' até '.$endDate:'');
        $msg.="
⏰ ".$start." às ".$end;
        $msg.="
⏱️ Turnos de ".(int)($clock['slot_duration_minutes']??60)." minutos";
        $msg.="

✍️ *COMO PARTICIPAR*";
        $msg.="
Envie aqui no grupo seu nome e o horário desejado.";
        $msg.="
Exemplos:";
        $msg.="
• *Euripedes 14h*";
        $msg.="
• *Maria 19:00*";
        $msg.="

📋 Para ver a lista atualizada, envie apenas *lista*.";
        $msg.="
✅ Depois da inscrição o bot confirma e mostra os horários preenchidos/vagos.";
        return $msg;
    }

    public function raffleMessage(array $raffle,bool $reminder=false): string
    {
        $title=trim((string)($raffle['title']??'Rifa'));
        $msg=$reminder?'🔔 *LEMBRETE · RIFA*':'🎟️ *RIFA*';
        $msg.="

*".$title."*";
        if(!empty($raffle['prize_description']))$msg.="
🎁 Prêmio: ".$raffle['prize_description'];
        $msg.="
💰 Valor por número: R$ ".number_format((float)($raffle['price']??0),2,',','.');
        $msg.="
🔢 Números: 1 a ".(int)($raffle['total_numbers']??0);
        if(!empty($raffle['draw_date']))$msg.="
📅 Sorteio: ".date('d/m/Y H:i',strtotime((string)$raffle['draw_date']));
        if(!empty($raffle['description']))$msg.="

".trim((string)$raffle['description']);
        $msg.="

✍️ *COMO ESCOLHER SEU NÚMERO*";
        $msg.="
Envie aqui no grupo:";
        $msg.="
*quero 25*";
        $msg.="

Troque 25 pelo número que você deseja. O bot confere se está disponível e faz a reserva.";
        if(!empty($raffle['pix_key']))$msg.="
💠 Após a reserva, o bot informa a chave PIX para pagamento.";
        $msg.="
✅ Se o número já estiver reservado, escolha outro.";
        return $msg;
    }

    public function eventMessage(array $event,bool $reminder=false): string
    {
        $title=trim((string)($event['title']??'Evento'));
        $msg=$reminder?'🔔 *LEMBRETE · EVENTO*':'📅 *CONVITE · EVENTO*';
        $msg.="

*".$title."*";
        if(!empty($event['event_date']))$msg.="
🗓️ ".date('d/m/Y H:i',strtotime((string)$event['event_date']));
        if(!empty($event['location']))$msg.="
📍 ".$event['location'];
        if(!empty($event['theme']))$msg.="
📖 Tema: ".$event['theme'];
        if(!empty($event['speaker_name']))$msg.="
🎤 ".$event['speaker_name'];
        if(!empty($event['description']))$msg.="

".trim((string)$event['description']);

        $msg.="

✍️ *COMO PARTICIPAR / SE INSCREVER*";
        $msg.="
No WhatsApp privado da igreja:";
        $msg.="
1. Envie *menu*";
        $msg.="
2. Escolha *4 · Eventos e Inscrições*";
        $msg.="
3. Escolha o evento";
        $msg.="
4. Informe seu nome, e-mail (ou *pular*) e quantidade de convidados";
        $msg.="
✅ Se a inscrição pelo bot estiver ativada, a confirmação chega no próprio WhatsApp.";
        $msg.="
ℹ️ Se a inscrição não estiver ativada, o bot orienta a procurar a secretaria.";
        return $msg;
    }

    public function serviceMessage(array $service,bool $reminder=false): string
    {
        $days=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
        $msg=$reminder?'🔔 *LEMBRETE · CULTO/REUNIÃO*':'⛪ *CONVITE · CULTO/REUNIÃO*';
        $msg.="

*".trim((string)($service['name']??'Culto'))."*";
        if(isset($service['day_of_week'])&&$service['day_of_week']!==null)$msg.="
📅 ".($days[(int)$service['day_of_week']]??'');
        if(!empty($service['time']))$msg.="
⏰ ".substr((string)$service['time'],0,5);
        if(!empty($service['description']))$msg.="

".trim((string)$service['description']);
        $msg.="

🙏 Participe conosco e compartilhe este convite com sua família.";
        return $msg;
    }

    public function sendPrayerClock(array $church,array $clock,bool $reminder=false): array
    {
        return $this->send($church,$clock,'prayer_clock',$this->prayerClockMessage($clock,$reminder));
    }

    public function sendRaffle(array $church,array $raffle,bool $reminder=false): array
    {
        return $this->send($church,$raffle,'raffles',$this->raffleMessage($raffle,$reminder));
    }

    public function sendEvent(array $church,array $event,bool $reminder=false): array
    {
        return $this->send($church,$event,'events',$this->eventMessage($event,$reminder));
    }

    public function sendService(array $church,array $service,bool $reminder=false): array
    {
        return $this->send($church,$service,'events',$this->serviceMessage($service,$reminder));
    }

    private function send(array $church,array $entity,string $purpose,string $message): array
    {
        $groupService=new GroupBroadcastService($this->pdo);
        $selected=json_decode((string)($entity['target_group_ids']??'[]'),true);
        if(!is_array($selected))$selected=[];

        $imagePath=$this->imageAbsolute((string)($entity['notification_image_path']??''));

        return $selected
            ?$groupService->sendIdsRich($church,$selected,$message,$imagePath)
            :$groupService->sendPurposeRich(
                $church,
                $purpose,
                $message,
                $entity['congregation_id']??null,
                $imagePath
            );
    }

    private function imageAbsolute(string $relative): ?string
    {
        $relative=trim($relative);
        if($relative==='')return null;
        try{
            $absolute=(new UploadService($this->appRoot))->absolute($relative);
            return is_file($absolute)?$absolute:null;
        }catch(Throwable){
            return null;
        }
    }
}
