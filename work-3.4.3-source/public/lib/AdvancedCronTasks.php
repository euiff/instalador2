<?php

declare(strict_types=1);

require_once __DIR__.'/EvolutionClient.php';
require_once __DIR__.'/AiClient.php';
require_once __DIR__.'/TtsClient.php';
require_once __DIR__.'/BibleReadingService.php';
require_once __DIR__.'/GroupBroadcastService.php';

final class AdvancedCronTasks
{
    public function __construct(private PDO $pdo,private ?string $churchId=null) {}

    public function run(): array
    {
        return [
            'daily_devotional'=>$this->dailyDevotional(),
            'bible_reading'=>$this->bibleReading(),
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

    private function due(?string $time): bool
    {
        if(!$time)return false;
        return substr((string)$time,0,5)===date('H:i');
    }

    private function groupTarget(array $row): ?string
    {
        $groups=json_decode((string)($row['notification_groups']??'{}'),true)?:[];
        return $groups['services']??$groups['events']??($row['whatsapp_group_id']??null);
    }

    private function delivered(string $churchId,string $type,string $target): bool
    {
        $q=$this->pdo->prepare('SELECT 1 FROM automation_deliveries WHERE church_id=? AND automation_type=? AND target_key=? AND delivery_date=CURDATE() LIMIT 1');
        $q->execute([$churchId,$type,$target]);return (bool)$q->fetchColumn();
    }

    private function mark(string $churchId,?string $congregationId,string $type,string $target,array $details=[]): void
    {
        $q=$this->pdo->prepare('INSERT IGNORE INTO automation_deliveries(id,church_id,congregation_id,automation_type,target_key,delivery_date,details) VALUES(?,?,?,?,?,CURDATE(),?)');
        $q->execute([app_uuid(),$churchId,$congregationId,$type,$target,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function dailyDevotional(): array
    {
        $texts=0;$audios=0;$groups=0;
        foreach($this->churches() as $church){
            $client=$this->client($church);if(!$client->configured())continue;
            $targets=[];

            $gq=$this->pdo->prepare('SELECT * FROM church_groups WHERE church_id=? AND active=1');
            $gq->execute([$church['id']]);
            $registered=$gq->fetchAll();
            $devotionalGroups=[];
            foreach($registered as $group){
                $purposes=json_decode((string)($group['purposes']??'[]'),true);
                if(!is_array($purposes)||!in_array('devotional',$purposes,true))continue;
                $jid=trim((string)($group['evolution_group_jid']??''));
                if($jid==='')continue;
                $devotionalGroups[]=$group;
            }

            $q=$this->pdo->prepare('SELECT * FROM congregations WHERE church_id=? AND active=1');
            $q->execute([$church['id']]);
            foreach($q->fetchAll() as $cong){
                $time=$cong['daily_devotional_time']?:($church['daily_devotional_time']?:'08:30');
                if(!$this->due($time))continue;

                $found=false;
                foreach($devotionalGroups as $group){
                    if((string)($group['congregation_id']??'')!==(string)$cong['id'])continue;
                    $targets[(string)$group['evolution_group_jid']]=$cong['id'];
                    $found=true;
                }
                if(!$found){
                    $legacy=$this->groupTarget($cong);
                    if($legacy)$targets[$legacy]=$cong['id'];
                }
            }

            if($this->due($church['daily_devotional_time']?:'08:30')){
                $foundGeneral=false;
                foreach($devotionalGroups as $group){
                    if(!empty($group['congregation_id']))continue;
                    $targets[(string)$group['evolution_group_jid']]=null;
                    $foundGeneral=true;
                }
                if(!$foundGeneral){
                    $legacy=$this->groupTarget($church);
                    if($legacy)$targets[$legacy]=null;
                }
            }

            if(!$targets)continue;

            $ai=new AiClient((string)($church['gemini_api_key']??''));
            $message=$ai->dailyDevotional();
            $tts=new TtsClient((string)($church['tts_api_key']??''),(string)($church['tts_voice_id']??''));
            $audio=!empty($church['tts_enabled'])?$tts->speechBase64($message):null;

            foreach($targets as $target=>$congregationId){
                if($this->delivered($church['id'],'daily_devotional',$target))continue;
                try{
                    $client->sendGroupText($target,"🌅 *PALAVRA DO DIA*\n\n".$message);
                    $texts++;
                    if($audio&&$client->sendAudioBase64($target,$audio))$audios++;
                    $this->mark($church['id'],$congregationId,'daily_devotional',$target,['audio'=>(bool)$audio]);
                    $groups++;
                }catch(Throwable){}
            }
        }
        return ['groups'=>$groups,'texts'=>$texts,'audios'=>$audios];
    }

    private function bibleReading(): array
    {
        $sent=0;$completed=0;$service=new BibleReadingService();
        $sql='SELECT p.*,c.evolution_api_url,c.evolution_api_key,c.evolution_instance_name,c.tts_enabled,c.tts_api_key,c.tts_voice_id FROM bible_reading_plans p INNER JOIN churches c ON c.id=p.church_id WHERE p.active=1 AND c.active=1';
        $params=[];
        if($this->churchId!==null){$sql.=' AND p.church_id=?';$params[]=$this->churchId;}
        $q=$this->pdo->prepare($sql);$q->execute($params);
        foreach($q->fetchAll() as $plan){
            if(!$this->due($plan['delivery_time']))continue;
            if(!empty($plan['last_sent_at'])&&date('Y-m-d',strtotime($plan['last_sent_at']))===date('Y-m-d'))continue;
            $client=new EvolutionClient((string)$plan['evolution_api_url'],(string)$plan['evolution_api_key'],(string)$plan['evolution_instance_name']);if(!$client->configured())continue;
            $chapters=$plan['plan_type']==='full_bible_1year'?3:1;
            $book=(string)$plan['current_book'];$chapter=(int)$plan['current_chapter'];$messages=[];$wasCompleted=false;
            for($i=0;$i<$chapters;$i++){
                $messages[]=$service->chapterText($book,$chapter);
                $next=$service->next($book,$chapter,(string)$plan['plan_type']);
                $book=$next['book'];$chapter=$next['chapter'];$wasCompleted=$wasCompleted||$next['completed'];
                if($next['completed'])break;
            }
            $message=implode("\n\n━━━━━━━━━━━━\n\n",$messages);
            try{
                $client->sendText((string)$plan['phone'],$message);
                if(!empty($plan['include_audio'])&&!empty($plan['tts_enabled'])){
                    $tts=new TtsClient((string)$plan['tts_api_key'],(string)$plan['tts_voice_id']);$audio=$tts->speechBase64(strip_tags(preg_replace('/[*_`]+/','',$message)));if($audio)$client->sendAudioBase64((string)$plan['phone'],$audio);
                }
                $u=$this->pdo->prepare('UPDATE bible_reading_plans SET current_book=?,current_chapter=?,last_sent_at=NOW(),completed_at=IF(?,NOW(),completed_at),active=IF(?,0,active) WHERE id=?');
                $u->execute([$book,$chapter,$wasCompleted?1:0,$wasCompleted?1:0,$plan['id']]);$sent++;if($wasCompleted)$completed++;
            }catch(Throwable){ }
        }
        return ['sent'=>$sent,'completed'=>$completed];
    }
}
