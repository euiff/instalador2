<?php

declare(strict_types=1);

final class GroupMessageHandler
{
    public function __construct(private PDO $pdo, private EvolutionClient $evolution, private AiClient $ai) {}

    public function handle(array $church, string $groupId, string $senderPhone, string $senderName, string $text, array $messageKey=[]): array
    {
        $churchId=$church['id'];$text=trim($text);
        $registeredGroupId=$this->registeredGroupId((string)$churchId,$groupId);
        $result=$this->raffle($churchId,$groupId,$registeredGroupId,$senderPhone,$senderName,$text);
        if($result) return $result;
        $result=$this->prayerClock($churchId,$groupId,$registeredGroupId,$senderPhone,$senderName,$text);
        if($result) return $result;
        return $this->moderate($church,$groupId,$senderPhone,$senderName,$text,$messageKey);
    }


    private function pollVote(string $churchId,string $groupJid,?string $registeredGroupId,string $phone,string $name,string $text): ?array
    {
        if(!preg_match('/^(?:voto\s*)?([1-8])$/ui',trim($text),$m))return null;
        $choice=(int)$m[1];

        $q=$this->pdo->prepare('SELECT * FROM polls WHERE church_id=? AND status="sent" AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY sent_at DESC,created_at DESC');
        $q->execute([$churchId]);

        $poll=null;
        foreach($q->fetchAll() as $candidate){
            $targets=json_decode((string)($candidate['target_groups']??'[]'),true);
            if(!is_array($targets))$targets=[];

            if($targets){
                if(!$registeredGroupId||!in_array($registeredGroupId,$targets,true))continue;
            }else{
                if(!$registeredGroupId)continue;
                $g=$this->pdo->prepare('SELECT purposes FROM church_groups WHERE id=? AND church_id=? AND active=1 LIMIT 1');
                $g->execute([$registeredGroupId,$churchId]);
                $purposes=json_decode((string)($g->fetchColumn()?:'[]'),true);
                if(!is_array($purposes)||!in_array('polls',$purposes,true))continue;
            }

            $poll=$candidate;
            break;
        }

        if(!$poll)return null;

        $options=json_decode((string)($poll['options']??'[]'),true);
        if(!is_array($options)||$choice<1||$choice>count($options)){
            return ['handled'=>true,'reply'=>'❌ Opção inválida. Responda *VOTO 1* até *VOTO '.count($options).'* conforme as opções da enquete.'];
        }

        $optionText=trim((string)$options[$choice-1]);
        if($optionText==='')return ['handled'=>true,'reply'=>'❌ Essa opção não está disponível.'];

        $normalizedPhone=preg_replace('/\D+/','',$phone)?:trim($phone);
        if($normalizedPhone==='')$normalizedPhone=trim($phone);
        if($normalizedPhone==='')return ['handled'=>true,'reply'=>'❌ Não consegui identificar seu número para registrar o voto.'];

        $existing=$this->pdo->prepare('SELECT id,option_index FROM poll_votes WHERE poll_id=? AND voter_phone=? LIMIT 1');
        $existing->execute([$poll['id'],$normalizedPhone]);
        $vote=$existing->fetch();

        if($vote){
            $this->pdo->prepare('UPDATE poll_votes SET group_id=?,voter_name=?,option_index=?,option_text=?,updated_at=NOW() WHERE id=?')
                ->execute([$registeredGroupId,$name?:null,$choice,$optionText,$vote['id']]);
            $changed=(int)$vote['option_index']!==$choice;
        }else{
            $this->pdo->prepare('INSERT INTO poll_votes(id,poll_id,church_id,group_id,voter_phone,voter_name,option_index,option_text) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([app_uuid(),$poll['id'],$churchId,$registeredGroupId,$normalizedPhone,$name?:null,$choice,$optionText]);
            $changed=true;
        }

        $counts=array_fill(1,count($options),0);
        $s=$this->pdo->prepare('SELECT option_index,COUNT(*) qty FROM poll_votes WHERE poll_id=? GROUP BY option_index');
        $s->execute([$poll['id']]);
        foreach($s->fetchAll() as $row){
            $idx=(int)$row['option_index'];
            if(isset($counts[$idx]))$counts[$idx]=(int)$row['qty'];
        }

        $results=[];
        foreach($counts as $idx=>$qty)$results[(string)$idx]=$qty;
        $this->pdo->prepare('UPDATE polls SET results=? WHERE id=?')
            ->execute([json_encode($results,JSON_UNESCAPED_UNICODE),$poll['id']]);

        $total=array_sum($counts);
        $lines=[];
        foreach($options as $i=>$opt){
            $idx=$i+1;
            $qty=$counts[$idx]??0;
            $pct=$total>0?round(($qty/$total)*100):0;
            $lines[]='*'.$idx.'.* '.$opt.' — *'.$qty.'* voto'.($qty===1?'':'s').' ('.$pct.'%)';
        }

        $prefix=$changed?'✅ *Voto registrado!*':'✅ *Seu voto já estava nessa opção.*';
        return [
            'handled'=>true,
            'reply'=>$prefix."\n".'👤 '.($name?:'Participante')."\n".'🗳️ *'.$choice.'. '.$optionText.'*'."\n\n".'📊 *Resultado parcial*'."\n".implode("\n",$lines)."\n\n".'Total: *'.$total.'* voto'.($total===1?'':'s').'. Você pode alterar seu voto respondendo *VOTO* + o novo número.'
        ];
    }

    private function raffle(string $churchId,string $groupJid,?string $registeredGroupId,string $phone,string $name,string $text):?array
    {
        if(!preg_match('/\bquero\s+(?:o\s+)?(?:n[uú]mero\s+)?(\d{1,6})\b/ui',$text,$m)) return null;
        $number=(int)$m[1];

        $q=$this->pdo->prepare('SELECT * FROM raffles WHERE church_id=? AND status="active" AND (draw_date IS NULL OR draw_date>NOW()) ORDER BY created_at DESC');
        $q->execute([$churchId]);
        $raffle=null;
        foreach($q->fetchAll() as $candidate){
            $targets=json_decode((string)($candidate['target_group_ids']??'[]'),true);
            if(!is_array($targets))$targets=[];
            if($targets){
                if(!$registeredGroupId||!in_array($registeredGroupId,$targets,true))continue;
            }
            $raffle=$candidate;
            break;
        }
        if(!$raffle) return null;
        if($number<1 || $number>(int)$raffle['total_numbers']) return ['handled'=>true,'reply'=>'Esse número não existe nesta rifa. Escolha entre 1 e '.$raffle['total_numbers'].'.'];
        $check=$this->pdo->prepare('SELECT buyer_name,paid FROM raffle_numbers WHERE raffle_id=? AND number=? LIMIT 1');$check->execute([$raffle['id'],$number]);$taken=$check->fetch();
        if($taken) return ['handled'=>true,'reply'=>'O número '.$number.' já está reservado. Escolha outro número.'];
        try{
            $i=$this->pdo->prepare('INSERT INTO raffle_numbers(id,raffle_id,church_id,number,buyer_name,buyer_phone,paid) VALUES(?,?,?,?,?,?,0)');
            $i->execute([app_uuid(),$raffle['id'],$churchId,$number,$name?:$phone,$phone]);
        }catch(Throwable){return ['handled'=>true,'reply'=>'O número '.$number.' acabou de ser reservado por outra pessoa. Escolha outro.'];}
        $reply='🎟️ Número *'.$number.'* reservado para '.($name?:$phone).'. Valor: R$ '.number_format((float)$raffle['price'],2,',','.').'.';
        if(!empty($raffle['pix_key']))$reply.='\nPIX: *'.$raffle['pix_key'].'*';
        return ['handled'=>true,'reply'=>$reply];
    }

    private function prayerClock(string $churchId,string $groupJid,?string $registeredGroupId,string $phone,string $name,string $text):?array
    {
        $normalizedCommand=mb_strtolower(trim($text),'UTF-8');
        $isListCommand=in_array($normalizedCommand,['lista','lista oração','lista oracao','lista do relógio','lista do relogio','relógio','relogio'],true);
        $parsed=$this->parsePrayerClockRegistration($text);

        // Não intercepta conversas normais do grupo.
        if(!$isListCommand&&!$parsed)return null;

        $clock=$this->matchingPrayerClock($churchId,$registeredGroupId);
        if(!$clock){
            return [
                'handled'=>false,
                'reason'=>'prayer_clock_no_matching_clock',
            ];
        }

        if($isListCommand){
            return ['handled'=>true,'reply'=>$this->prayerClockList($clock)];
        }

        $person=$parsed['name']!==''?$parsed['name']:$name;
        $hour=(int)$parsed['hour'];
        $minute=(int)$parsed['minute'];
        if($person==='')$person=$phone;

        $slot=sprintf('%02d:%02d:00',$hour,$minute);
        $validSlots=$this->prayerClockSlots($clock);
        if(!in_array(substr($slot,0,5),$validSlots,true)){
            $duration=(int)$clock['slot_duration_minutes'];
            return [
                'handled'=>true,
                'reply'=>'❌ Horário *'.substr($slot,0,5).'* não disponível neste relógio. Os turnos são de '.$this->durationLabel($duration).'.'."

".$this->prayerClockList($clock)
            ];
        }

        $count=$this->pdo->prepare('SELECT COUNT(*) FROM prayer_clock_registrations WHERE prayer_clock_id=? AND slot_time=?');
        $count->execute([$clock['id'],$slot]);
        if((int)$count->fetchColumn()>=(int)$clock['max_per_slot']){
            return [
                'handled'=>true,
                'reply'=>'❌ Horário *'.substr($slot,0,5).'* já está lotado. Escolha outro horário.'."

".$this->prayerClockList($clock)
            ];
        }

        $dup=$this->pdo->prepare('SELECT 1 FROM prayer_clock_registrations WHERE prayer_clock_id=? AND phone=? AND slot_time=? LIMIT 1');
        $dup->execute([$clock['id'],$phone,$slot]);
        if($dup->fetchColumn()){
            return [
                'handled'=>true,
                'reply'=>'⚠️ *'.$person.'*, você já está inscrito(a) às *'.substr($slot,0,5).'*.'."

".$this->prayerClockList($clock)
            ];
        }

        try{
            $this->pdo->prepare('INSERT INTO prayer_clock_registrations(id,prayer_clock_id,church_id,name,phone,slot_time) VALUES(?,?,?,?,?,?)')
                ->execute([app_uuid(),$clock['id'],$churchId,$person,$phone,$slot]);
        }catch(Throwable $e){
            return ['handled'=>true,'reply'=>'❌ Não consegui confirmar esse horário agora. Tente novamente em alguns segundos.'];
        }

        return [
            'handled'=>true,
            'reply'=>'✅ *Inscrição confirmada!*'."
".
                     '👤 *'.$person.'*'."
".
                     '🕐 *'.substr($slot,0,5).'*'."

".
                     $this->prayerClockList($clock)
        ];
    }

    private function parsePrayerClockRegistration(string $text): ?array
    {
        $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
        if($text==='')return null;

        $patterns=[
            '/^(.+?)\s+(\d{1,2}):(\d{2})\s*$/u',
            '/^(.+?)\s+(\d{1,2})\s*[hH]\s*(\d{1,2})\s*$/u',
            '/^(.+?)\s+(\d{1,2})\s*(?:h|hs|hr|hrs|hra|hras|hora|horas)\.?\s*$/ui',
        ];

        foreach($patterns as $index=>$pattern){
            if(!preg_match($pattern,$text,$m))continue;
            $person=trim((string)$m[1]);
            $hour=(int)$m[2];
            $minute=$index<2?(int)($m[3]??0):0;
            if($person===''||$hour<0||$hour>23||$minute<0||$minute>59)return null;
            return ['name'=>$person,'hour'=>$hour,'minute'=>$minute];
        }
        return null;
    }

    private function matchingPrayerClock(string $churchId,?string $registeredGroupId): ?array
    {
        /*
         * O convite do relógio pode ser divulgado antes da data da vigília.
         * Portanto a inscrição pelo grupo também precisa funcionar antes da
         * data, desde que o relógio esteja ativo e vinculado ao grupo.
         *
         * Prioridade:
         *  1. relógio que está acontecendo hoje;
         *  2. próximo relógio futuro;
         *  3. relógio ativo mais recente ainda válido.
         */
        $q=$this->pdo->prepare(
            'SELECT pc.*,
                    CASE
                      WHEN pc.event_date<=CURDATE()
                       AND COALESCE(pc.end_date,pc.event_date)>=CURDATE() THEN 0
                      WHEN pc.event_date>CURDATE() THEN 1
                      ELSE 2
                    END AS match_priority
             FROM prayer_clocks pc
             WHERE pc.church_id=? AND pc.active=1
               AND COALESCE(pc.end_date,pc.event_date)>=CURDATE()
             ORDER BY match_priority ASC,
                      CASE WHEN pc.event_date>=CURDATE() THEN pc.event_date END ASC,
                      pc.created_at DESC'
        );
        $q->execute([$churchId]);

        foreach($q->fetchAll() as $candidate){
            if($this->clockMatchesGroup($candidate,$registeredGroupId,$churchId))return $candidate;
        }
        return null;
    }

    private function clockMatchesGroup(array $clock,?string $registeredGroupId,string $churchId): bool
    {
        $targets=json_decode((string)($clock['target_group_ids']??'[]'),true);
        if(!is_array($targets))$targets=[];

        // Quando o relógio foi criado escolhendo grupos específicos, respeita
        // exatamente essa seleção.
        if($targets){
            return $registeredGroupId!==null&&in_array($registeredGroupId,$targets,true);
        }

        // Compatibilidade com relógios antigos sem target_group_ids:
        // usa o cadastro central do grupo e sua congregação/finalidade.
        if($registeredGroupId===null)return false;

        $q=$this->pdo->prepare('SELECT congregation_id,purposes FROM church_groups WHERE id=? AND church_id=? AND active=1 LIMIT 1');
        $q->execute([$registeredGroupId,$churchId]);
        $group=$q->fetch();
        if(!$group)return false;

        $purposes=json_decode((string)($group['purposes']??'[]'),true);
        if(is_array($purposes)&&$purposes&&!in_array('prayer_clock',$purposes,true))return false;

        $clockCong=(string)($clock['congregation_id']??'');
        $groupCong=(string)($group['congregation_id']??'');

        // Relógio geral pode funcionar em grupo geral ou em grupo explicitamente
        // marcado para Relógio de Oração. Relógio de congregação só responde nos
        // grupos daquela congregação.
        if($clockCong==='')return true;
        return $groupCong!==''&&hash_equals($clockCong,$groupCong);
    }

    private function prayerClockSlots(array $clock): array
    {
        $start=max(0,min(23,(int)($clock['start_hour']??0)));
        $rawEnd=(int)($clock['end_hour']??24);
        $end=$rawEnd===24?24:max(0,min(23,$rawEnd));
        $duration=max(1,(int)($clock['slot_duration_minutes']??60));

        $periodMinutes=$end>$start
            ?($end-$start)*60
            :(24-$start+$end)*60;

        // Compatibilidade com relógio 00h-00h/24h.
        if($periodMinutes===0)$periodMinutes=24*60;

        $totalSlots=(int)floor($periodMinutes/$duration);
        $slots=[];
        for($i=0;$i<$totalSlots;$i++){
            $minutes=($start*60+$i*$duration)%1440;
            $slots[]=sprintf('%02d:%02d',(int)floor($minutes/60),(int)($minutes%60));
        }
        return $slots;
    }

    private function prayerClockList(array $clock): string
    {
        $q=$this->pdo->prepare('SELECT name,slot_time FROM prayer_clock_registrations WHERE prayer_clock_id=? ORDER BY slot_time,name');
        $q->execute([$clock['id']]);
        $registrations=$q->fetchAll();

        $bySlot=[];
        foreach($registrations as $registration){
            $slot=substr((string)$registration['slot_time'],0,5);
            $bySlot[$slot]??=[];
            $bySlot[$slot][]=(string)$registration['name'];
        }

        $slots=$this->prayerClockSlots($clock);
        $filledSlots=0;
        $lines=[];

        foreach($slots as $slot){
            $names=$bySlot[$slot]??[];
            $startMinutes=((int)substr($slot,0,2))*60+(int)substr($slot,3,2);
            $endMinutes=($startMinutes+(int)$clock['slot_duration_minutes'])%1440;
            $end=sprintf('%02d:%02d',(int)floor($endMinutes/60),(int)($endMinutes%60));

            if($names){
                $filledSlots++;
                $lines[]='🟢 *'.$slot.' - '.$end.'* — '.implode(', ',$names);
            }else{
                $lines[]='⚪ *'.$slot.' - '.$end.'* — _vago_';
            }
        }

        $date=$this->formatPrayerDate((string)$clock['event_date']);
        $dateLine=$date;
        if(!empty($clock['end_date'])&&(string)$clock['end_date']!==(string)$clock['event_date']){
            $dateLine='De '.$date.' até '.$this->formatPrayerDate((string)$clock['end_date']);
        }

        $vacant=max(0,count($slots)-$filledSlots);

        $message='📋 *LISTA DO RELÓGIO DE ORAÇÃO*'."
";
        $message.='📌 *'.$clock['title'].'*'."
";
        $message.='📅 '.$dateLine."

";
        $message.='✅ *Preenchidos:* '.$filledSlots.'/'.count($slots)."
";
        $message.='⭕ *Vagos:* '.$vacant."

";
        $message.='━━━━━━━━━━━━━━━'."

";
        $message.=implode("
",$lines);
        $message.="

".'━━━━━━━━━━━━━━━';
        $message.="

".'✍️ Para se inscrever, envie:'."
";
        $message.='_Seu Nome HH:MM_ ou _Seu Nome 14h_'."

";
        $message.='🙏 Deus abençoe!';

        return $message;
    }

    private function formatPrayerDate(string $date): string
    {
        $ts=strtotime($date);
        return $ts?date('d/m/Y',$ts):$date;
    }

    private function durationLabel(int $minutes): string
    {
        return match($minutes){
            30=>'30 minutos',
            60=>'1 hora',
            120=>'2 horas',
            default=>$minutes.' minutos',
        };
    }

    private function registeredGroupId(string $churchId,string $groupJid): ?string
    {
        $groupJid=trim($groupJid);
        if($groupJid==='')return null;
        $q=$this->pdo->prepare('SELECT id FROM church_groups WHERE church_id=? AND evolution_group_jid=? AND active=1 LIMIT 1');
        $q->execute([$churchId,$groupJid]);
        $id=$q->fetchColumn();
        return $id!==false?(string)$id:null;
    }

    private function moderate(array $church,string $groupId,string $phone,string $name,string $text,array $messageKey):array
    {
        $q=$this->pdo->prepare('SELECT * FROM group_moderation_settings WHERE church_id=? AND enabled=1 LIMIT 1');$q->execute([$church['id']]);$cfg=$q->fetch();
        if(!$cfg||$text==='')return ['handled'=>false];
        $blocked=json_decode((string)($cfg['blocked_words']??'[]'),true)?:[];
        $lower=mb_strtolower($text,'UTF-8');$reason=null;$analysis=null;
        foreach($blocked as $word){$word=trim(mb_strtolower((string)$word,'UTF-8'));if($word!==''&&str_contains($lower,$word)){$reason='palavra bloqueada: '.$word;break;}}
        if(!$reason&&!empty($cfg['moderate_spam'])&&(preg_match('/https?:\/\//i',$text)&&mb_strlen($text)>250))$reason='possível spam';
        if(!$reason&&!empty($cfg['ai_moderation_enabled'])&&$this->ai->configured()){
            $ai=$this->ai->moderate($text,['adult'=>(bool)$cfg['moderate_adult_content'],'politics'=>(bool)$cfg['moderate_politics'],'profanity'=>(bool)$cfg['moderate_profanity'],'spam'=>(bool)$cfg['moderate_spam']]);
            if($ai['block']){$reason=$ai['reason'];$analysis=$ai['analysis'];}
        }
        if(!$reason)return ['handled'=>false];

        $messageId=(string)($messageKey['id']??'');
        $participant=(string)($messageKey['participant']??'');
        $deleted=false;
        if($messageId!==''&&$this->evolution->configured()){
            $deleted=$this->evolution->deleteMessageForEveryone($groupId,$messageId,$participant?:null);
        }
        $action=$deleted?'deleted_and_warned':'warning_only';
        $this->pdo->prepare('INSERT INTO moderation_logs(id,church_id,group_id,sender_phone,sender_name,original_message,reason,ai_analysis,action_taken,notified_user) VALUES(?,?,?,?,?,?,?,?,?,1)')->execute([app_uuid(),$church['id'],$groupId,$phone,$name?:null,$text,$reason,$analysis,$action]);
        $warning=(string)($cfg['warning_message']?:'⚠️ Esta mensagem não está de acordo com as regras do grupo.');
        if(!empty($cfg['mention_user_in_warning'])&&$name)$warning=$name.', '.$warning;
        if($deleted)$warning.=' A mensagem foi removida automaticamente.';
        return ['handled'=>true,'reply'=>$warning,'moderated'=>true,'deleted'=>$deleted];
    }
}
