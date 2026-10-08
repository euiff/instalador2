<?php

declare(strict_types=1);

require_once __DIR__.'/EvolutionClient.php';

final class GroupBroadcastService
{
    public function __construct(private PDO $pdo) {}

    public function list(string $churchId,?string $purpose=null,?string $congregationId=null,bool $activeOnly=true): array
    {
        $where=['g.church_id=?'];
        $params=[$churchId];
        if($activeOnly)$where[]='g.active=1';
        if($congregationId!==null){
            $where[]='(g.congregation_id=? OR g.congregation_id IS NULL)';
            $params[]=$congregationId;
        }

        $sql='SELECT g.*,c.name congregation_name
              FROM church_groups g
              LEFT JOIN congregations c ON c.id=g.congregation_id
              WHERE '.implode(' AND ',$where).'
              ORDER BY c.name IS NULL DESC,c.name,g.name';
        $q=$this->pdo->prepare($sql);$q->execute($params);
        $rows=$q->fetchAll();

        if($purpose===null||$purpose==='')return $rows;
        return array_values(array_filter($rows,function(array $row) use($purpose){
            $purposes=json_decode((string)($row['purposes']??'[]'),true);
            return is_array($purposes)&&in_array($purpose,$purposes,true);
        }));
    }

    public function selected(string $churchId,array $ids): array
    {
        $ids=array_values(array_unique(array_filter(array_map('strval',$ids))));
        if(!$ids)return [];
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->pdo->prepare('SELECT g.*,c.name congregation_name FROM church_groups g LEFT JOIN congregations c ON c.id=g.congregation_id WHERE g.church_id=? AND g.active=1 AND g.id IN ('.$ph.') ORDER BY g.name');
        $q->execute([$churchId,...$ids]);
        return $q->fetchAll();
    }

    public function idsFromPost(mixed $value): array
    {
        if(!is_array($value))return [];
        return array_values(array_unique(array_filter(array_map(
            fn($v)=>preg_match('/^[A-Fa-f0-9-]{36}$/',(string)$v)?(string)$v:'',
            $value
        ))));
    }

    public function sendIds(array $church,array $groupIds,string $message,?string $campaignId=null): array
    {
        return $this->sendRows($church,$this->selected((string)$church['id'],$groupIds),$message,$campaignId,null);
    }

    public function sendIdsRich(array $church,array $groupIds,string $message,?string $imagePath=null,?string $campaignId=null): array
    {
        return $this->sendRows($church,$this->selected((string)$church['id'],$groupIds),$message,$campaignId,$imagePath);
    }

    public function sendPurpose(array $church,string $purpose,string $message,?string $congregationId=null,?string $campaignId=null): array
    {
        $rows=$this->list((string)$church['id'],$purpose,$congregationId,true);
        if(!$rows){
            $rows=$this->legacyRows($church,$purpose,$congregationId);
        }
        return $this->sendRows($church,$rows,$message,$campaignId,null);
    }

    public function sendPurposeRich(array $church,string $purpose,string $message,?string $congregationId=null,?string $imagePath=null,?string $campaignId=null): array
    {
        $rows=$this->list((string)$church['id'],$purpose,$congregationId,true);
        if(!$rows){
            $rows=$this->legacyRows($church,$purpose,$congregationId);
        }
        return $this->sendRows($church,$rows,$message,$campaignId,$imagePath);
    }

    public function syncEvolution(array $church): array
    {
        $client=$this->client($church);
        return $this->syncRemoteGroups((string)$church['id'],$client->groups());
    }

    public function syncRemoteGroups(string $churchId,array $remote): array
    {
        $created=0;$updated=0;$ignored=0;
        $seen=[];

        $find=$this->pdo->prepare('SELECT id FROM church_groups WHERE church_id=? AND evolution_group_jid=? LIMIT 1');
        $upsert=$this->pdo->prepare(
            'INSERT INTO church_groups(id,church_id,name,group_type,evolution_group_jid,purposes,active,last_sync_at)
             VALUES(?,?,?,"whatsapp",?,JSON_ARRAY("announcements"),1,NOW())
             ON DUPLICATE KEY UPDATE
               name=IF(name="" OR name LIKE "%Grupo sem nome%",VALUES(name),name),
               active=1,
               last_sync_at=NOW()'
        );

        foreach($remote as $row){
            if(!is_array($row)){$ignored++;continue;}

            $jid=$this->normalizeGroupJid((string)($row['jid']??''));
            $name=trim((string)($row['name']??''));
            if($jid===''){$ignored++;continue;}

            // Algumas versões da Evolution podem devolver o mesmo grupo mais
            // de uma vez. A sincronização deve ser idempotente.
            if(isset($seen[$jid])){$ignored++;continue;}
            $seen[$jid]=true;

            $find->execute([$churchId,$jid]);
            $exists=(bool)$find->fetchColumn();

            $upsert->execute([
                app_uuid(),
                $churchId,
                $name!==''?$name:'Grupo sem nome',
                $jid,
            ]);

            if($exists)$updated++;else $created++;
        }

        return [
            'created'=>$created,
            'updated'=>$updated,
            'ignored'=>$ignored,
            'found'=>count($remote),
        ];
    }

    public function normalizeGroupJid(string $jid): string
    {
        $jid=trim(mb_strtolower($jid,'UTF-8'));
        $jid=preg_replace('/\\s+/u','',$jid)??$jid;
        if($jid==='')return '';
        if(!str_contains($jid,'@'))$jid.='@g.us';
        return $jid;
    }

    private function sendRows(array $church,array $rows,string $message,?string $campaignId,?string $imagePath): array
    {
        $message=trim($message);
        if($message==='')throw new RuntimeException('A mensagem está vazia.');

        $client=$this->client($church);
        if(!$client->configured())throw new RuntimeException('Configure a Evolution API antes de enviar para grupos.');

        $sent=0;$failed=0;$errors=[];
        foreach($rows as $row){
            $jid=trim((string)($row['evolution_group_jid']??$row['jid']??''));
            if($jid==='')continue;

            $deliveryId=app_uuid();
            try{
                $mediaSent=false;
                if($imagePath&&is_file($imagePath)){
                    $mediaSent=$client->sendGroupImageFile($jid,$imagePath,$message);
                }
                if(!$mediaSent)$client->sendGroupText($jid,$message);

                $this->pdo->prepare('INSERT INTO communication_deliveries(id,campaign_id,church_id,group_id,target_jid,status,sent_at) VALUES(?,?,?,?,?,"sent",NOW())')
                    ->execute([$deliveryId,$campaignId,$church['id'],$row['id']??null,$jid]);
                $this->pdo->prepare('INSERT INTO bot_messages(id,church_id,phone,direction,message,message_type,status) VALUES(?,?,?,"outbound",?,"group","sent")')
                    ->execute([app_uuid(),$church['id'],$jid,$message]);
                $sent++;
            }catch(Throwable $e){
                $failed++;$errors[]=['group'=>$row['name']??$jid,'error'=>$e->getMessage()];
                try{
                    $this->pdo->prepare('INSERT INTO communication_deliveries(id,campaign_id,church_id,group_id,target_jid,status,error_message) VALUES(?,?,?,?,?,"error",?)')
                        ->execute([$deliveryId,$campaignId,$church['id'],$row['id']??null,$jid,$e->getMessage()]);
                }catch(Throwable){}
            }
        }
        return ['sent'=>$sent,'failed'=>$failed,'errors'=>$errors];
    }

    private function legacyRows(array $church,string $purpose,?string $congregationId): array
    {
        $rows=[];
        if($congregationId){
            $q=$this->pdo->prepare('SELECT id,name,whatsapp_group_id,notification_groups FROM congregations WHERE id=? AND church_id=? AND active=1 LIMIT 1');
            $q->execute([$congregationId,$church['id']]);
            if($c=$q->fetch()){
                $groups=json_decode((string)($c['notification_groups']??'{}'),true)?:[];
                $jid=$groups[$purpose]??$c['whatsapp_group_id']??null;
                if($jid)$rows[]=['id'=>null,'name'=>$c['name'],'evolution_group_jid'=>$jid];
            }
        }

        $groups=json_decode((string)($church['notification_groups']??'{}'),true)?:[];
        $jid=$groups[$purpose]??$church['whatsapp_group_id']??null;
        if($jid)$rows[]=['id'=>null,'name'=>$church['name'].' · Grupo principal','evolution_group_jid'=>$jid];

        $seen=[];$out=[];
        foreach($rows as $row){
            $jid=(string)$row['evolution_group_jid'];
            if(isset($seen[$jid]))continue;
            $seen[$jid]=true;$out[]=$row;
        }
        return $out;
    }

    private function client(array $church): EvolutionClient
    {
        return new EvolutionClient(
            (string)($church['evolution_api_url']??''),
            (string)($church['evolution_api_key']??''),
            (string)($church['evolution_instance_name']??'')
        );
    }
}
