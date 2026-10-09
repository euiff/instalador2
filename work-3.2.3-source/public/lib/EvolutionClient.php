<?php

declare(strict_types=1);

final class EvolutionClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $instance
    ) {
        $this->baseUrl=rtrim(trim($this->baseUrl),'/');
        $this->apiKey=trim($this->apiKey);
        $this->instance=trim($this->instance);
    }

    public function configured(): bool
    {
        return $this->baseUrl!==''&&$this->apiKey!==''&&$this->instance!=='';
    }

    public function connectionState(): array
    {
        if(!$this->configured())throw new RuntimeException('Preencha URL da API, API Key e nome da instância.');

        $attempts=[
            '/instance/connectionState/'.rawurlencode($this->instance),
            '/instance/fetchInstances?instanceName='.rawurlencode($this->instance),
        ];
        $last=null;
        foreach($attempts as $path){
            try{
                $data=$this->request('GET',$path);
                return [
                    'ok'=>true,
                    'state'=>$this->extractState($data),
                    'data'=>$data,
                ];
            }catch(Throwable $e){$last=$e;}
        }
        throw new RuntimeException('Não foi possível consultar a instância Evolution. '.($last?->getMessage()??''));
    }

    public function configureWebhook(string $url): array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');
        if(!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('URL de webhook inválida.');

        $events=[
            'MESSAGES_UPSERT',
            'MESSAGES_UPDATE',
            'CONNECTION_UPDATE',
        ];
        $attempts=[
            [
                'webhook'=>[
                    'enabled'=>true,
                    'url'=>$url,
                    'webhookByEvents'=>false,
                    'webhookBase64'=>false,
                    'events'=>$events,
                ],
            ],
            [
                'enabled'=>true,
                'url'=>$url,
                'webhookByEvents'=>false,
                'webhookBase64'=>false,
                'events'=>$events,
            ],
            [
                'enabled'=>true,
                'url'=>$url,
                'webhook_by_events'=>false,
                'webhook_base64'=>false,
                'events'=>$events,
            ],
        ];
        $last=null;
        foreach($attempts as $payload){
            try{return $this->request('POST','/webhook/set/'.rawurlencode($this->instance),$payload);}
            catch(Throwable $e){$last=$e;}
        }
        throw new RuntimeException('A Evolution respondeu, mas recusou a configuração automática do webhook. '.($last?->getMessage()??''));
    }

    public function webhookInfo(): array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');

        $attempts=[
            '/webhook/find/'.rawurlencode($this->instance),
            '/webhook/find?instanceName='.rawurlencode($this->instance),
        ];
        $last=null;
        foreach($attempts as $path){
            try{
                $data=$this->request('GET',$path);
                return [
                    'ok'=>true,
                    'url'=>$this->findWebhookValue($data,['url','webhookUrl','webhook_url']),
                    'enabled'=>$this->findWebhookBool($data,['enabled']),
                    'events'=>$this->findWebhookArray($data,['events']),
                    'data'=>$data,
                ];
            }catch(Throwable $e){$last=$e;}
        }

        throw new RuntimeException('Não foi possível consultar o webhook atual na Evolution. '.($last?->getMessage()??''));
    }

    public function qrCode(): ?array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');
        try{
            $data=$this->request('GET','/instance/connect/'.rawurlencode($this->instance));
            $base64=$this->findQrBase64($data);
            return ['data'=>$data,'base64'=>$base64];
        }catch(Throwable $e){
            throw new RuntimeException('Não foi possível solicitar o QR Code. '.$e->getMessage(),0,$e);
        }
    }

    public function sendText(string $number,string $text): array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');
        $number=preg_replace('/\D+/','',$number)?:$number;
        return $this->request('POST','/message/sendText/'.rawurlencode($this->instance),[
            'number'=>$number,
            'text'=>$text,
        ]);
    }

    public function sendGroupText(string $groupJid,string $text): array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');
        $groupJid=trim($groupJid);
        if($groupJid==='')throw new RuntimeException('JID do grupo não informado.');
        if(!str_contains($groupJid,'@'))$groupJid.='@g.us';

        return $this->request('POST','/message/sendText/'.rawurlencode($this->instance),[
            'number'=>$groupJid,
            'text'=>$text,
        ]);
    }

    public function sendGroupPoll(string $groupJid,string $question,array $values,int $selectableCount=1): array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');

        $groupJid=trim($groupJid);
        if($groupJid==='')throw new RuntimeException('JID do grupo não informado.');
        if(!str_contains($groupJid,'@'))$groupJid.='@g.us';

        $question=trim($question);
        $values=array_values(array_unique(array_filter(
            array_map(fn($v)=>trim((string)$v),$values),
            fn($v)=>$v!==''
        )));

        if($question==='')throw new RuntimeException('Pergunta da enquete vazia.');
        if(count($values)<2)throw new RuntimeException('A enquete precisa de pelo menos duas opções.');
        if(count($values)>10)throw new RuntimeException('A Evolution aceita no máximo dez opções na enquete.');
        $selectableCount=max(1,min($selectableCount,count($values),10));

        $path='/message/sendPoll/'.rawurlencode($this->instance);

        // Formato oficial da Evolution API:
        // number, name, selectableCount e values no nível principal.
        // Algumas versões aceitam o JID completo do grupo e outras resolvem
        // melhor usando apenas a parte numérica. Mantemos o MESMO payload
        // oficial e variamos somente o destino.
        $targets=[$groupJid];
        $numeric=preg_replace('/@g\\.us$/i','',$groupJid);
        if(is_string($numeric)&&$numeric!==''&&$numeric!==$groupJid)$targets[]=$numeric;
        $targets=array_values(array_unique($targets));

        $errors=[];
        foreach($targets as $target){
            $payload=[
                'number'=>$target,
                'name'=>$question,
                'selectableCount'=>$selectableCount,
                'values'=>$values,
            ];

            try{
                return $this->request('POST',$path,$payload);
            }catch(Throwable $e){
                $errors[]=$e->getMessage();
            }
        }

        $detail=$errors?implode(' | ',array_unique($errors)):'sem detalhe retornado';
        throw new RuntimeException('Não foi possível enviar a enquete nativa pelo WhatsApp. '.$detail);
    }

    public function responseMessageId(array $data): ?string
    {
        $candidates=[
            $data['key']['id']??null,
            $data['data']['key']['id']??null,
            $data['data']['Info']['ID']??null,
            $data['Info']['ID']??null,
            $data['id']??null,
        ];
        foreach($candidates as $value){
            if(is_string($value)&&trim($value)!=='')return trim($value);
        }
        return null;
    }

    public function sendDocumentFile(string $number,string $absolutePath,string $fileName,?string $caption=null): bool
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');
        if(!is_file($absolutePath)||!is_readable($absolutePath))return false;

        $bytes=file_get_contents($absolutePath);
        if($bytes===false||$bytes==='')return false;

        $number=str_contains($number,'@')?trim($number):(preg_replace('/\D+/','',$number)?:$number);
        $mime='application/pdf';
        $base64=base64_encode($bytes);
        $fileName=trim($fileName)!==''?$fileName:'documento.pdf';

        $attempts=[
            [
                'number'=>$number,
                'mediatype'=>'document',
                'mimetype'=>$mime,
                'caption'=>$caption??'',
                'media'=>$base64,
                'fileName'=>$fileName,
            ],
            [
                'number'=>$number,
                'mediatype'=>'document',
                'mimetype'=>$mime,
                'caption'=>$caption??'',
                'media'=>'data:'.$mime.';base64,'.$base64,
                'fileName'=>$fileName,
            ],
        ];

        foreach($attempts as $payload){
            try{
                $this->request('POST','/message/sendMedia/'.rawurlencode($this->instance),$payload);
                return true;
            }catch(Throwable){}
        }
        return false;
    }

    public function sendGroupImageFile(string $groupJid,string $absolutePath,string $caption=''): bool
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');
        $groupJid=trim($groupJid);
        if($groupJid==='')throw new RuntimeException('JID do grupo não informado.');
        if(!str_contains($groupJid,'@'))$groupJid.='@g.us';
        if(!is_file($absolutePath)||!is_readable($absolutePath))return false;

        $bytes=file_get_contents($absolutePath);
        if($bytes===false||$bytes==='')return false;

        $finfo=new finfo(FILEINFO_MIME_TYPE);
        $mime=(string)$finfo->file($absolutePath);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))return false;

        $base64=base64_encode($bytes);
        $fileName=basename($absolutePath);
        $attempts=[
            [
                'number'=>$groupJid,
                'mediatype'=>'image',
                'mimetype'=>$mime,
                'caption'=>$caption,
                'media'=>$base64,
                'fileName'=>$fileName,
            ],
            [
                'number'=>$groupJid,
                'mediatype'=>'image',
                'mimetype'=>$mime,
                'caption'=>$caption,
                'media'=>'data:'.$mime.';base64,'.$base64,
                'fileName'=>$fileName,
            ],
        ];

        foreach($attempts as $payload){
            try{
                $this->request('POST','/message/sendMedia/'.rawurlencode($this->instance),$payload);
                return true;
            }catch(Throwable){}
        }
        return false;
    }

    public function groups(): array
    {
        if(!$this->configured())throw new RuntimeException('Evolution API não configurada.');

        $attempts=[
            '/group/fetchAllGroups/'.rawurlencode($this->instance).'?getParticipants=false',
            '/group/fetchAllGroups/'.rawurlencode($this->instance),
        ];
        $last=null;
        foreach($attempts as $path){
            try{
                $data=$this->request('GET',$path);
                $rows=array_is_list($data)?$data:($data['groups']??$data['data']??[]);
                if(!is_array($rows))continue;

                $out=[];
                foreach($rows as $row){
                    if(!is_array($row))continue;
                    $jid=trim((string)($row['id']??$row['jid']??$row['remoteJid']??$row['groupJid']??''));
                    $name=trim((string)($row['subject']??$row['name']??$row['pushName']??'Grupo sem nome'));
                    if($jid==='')continue;
                    $out[]=['jid'=>$jid,'name'=>$name!==''?$name:'Grupo sem nome'];
                }
                return $out;
            }catch(Throwable $e){$last=$e;}
        }

        throw new RuntimeException('Não foi possível listar os grupos da Evolution. '.($last?->getMessage()??''));
    }

    public function sendAudioBase64(string $number,string $base64): bool
    {
        if(!$this->configured()||trim($base64)==='')return false;
        $number=str_contains($number,'@')?trim($number):(preg_replace('/\D+/','',$number)?:$number);
        $attempts=[
            ['/message/sendWhatsAppAudio/'.rawurlencode($this->instance),['number'=>$number,'audio'=>$base64]],
            ['/message/sendWhatsAppAudio/'.rawurlencode($this->instance),['number'=>$number,'audio'=>'data:audio/mp3;base64,'.$base64]],
            ['/message/sendMedia/'.rawurlencode($this->instance),['number'=>$number,'mediatype'=>'audio','mimetype'=>'audio/mpeg','media'=>$base64,'fileName'=>'audio.mp3']],
        ];
        foreach($attempts as [$path,$payload]){
            try{$this->request('POST',$path,$payload);return true;}catch(Throwable){}
        }
        return false;
    }

    public function deleteMessageForEveryone(string $remoteJid,string $messageId,?string $participant=null): bool
    {
        if(!$this->configured()||trim($remoteJid)===''||trim($messageId)==='')return false;
        $payload=['id'=>$messageId,'remoteJid'=>$remoteJid,'fromMe'=>false];
        if($participant)$payload['participant']=$participant;
        try{
            $this->request('POST','/chat/deleteMessageForEveryone/'.rawurlencode($this->instance),$payload);
            return true;
        }catch(Throwable){
            return false;
        }
    }

    public function mediaBase64(string $messageId): ?string
    {
        if(!$this->configured()||trim($messageId)==='')return null;
        $data=$this->request('POST','/chat/getBase64FromMediaMessage/'.rawurlencode($this->instance),[
            'message'=>['key'=>['id'=>$messageId]],
            'convertToMp4'=>false,
        ]);
        return !empty($data['base64'])&&is_string($data['base64'])?$data['base64']:null;
    }

    public function saveImageMessage(string $messageId,string $churchId,string $storageRoot): ?string
    {
        $base64=$this->mediaBase64($messageId);
        if(!$base64)return null;
        if(str_contains($base64,','))$base64=explode(',',$base64,2)[1];
        $bytes=base64_decode($base64,true);
        if($bytes===false||strlen($bytes)<32)return null;
        $info=@getimagesizefromstring($bytes);
        if(!$info)return null;
        $ext=match($info[2]??null){
            IMAGETYPE_PNG=>'png',
            IMAGETYPE_WEBP=>'webp',
            default=>'jpg',
        };
        $safeChurch=preg_replace('/[^a-zA-Z0-9_-]/','',$churchId)?:'church';
        $dir=rtrim($storageRoot,'/\\').'/storage/uploads/members/'.$safeChurch;
        if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Falha ao criar pasta de mídia.');
        $name=date('YmdHis').'-'.bin2hex(random_bytes(6)).'.'.$ext;
        if(file_put_contents($dir.'/'.$name,$bytes,LOCK_EX)===false)return null;
        return 'storage/uploads/members/'.$safeChurch.'/'.$name;
    }

    private function request(string $method,string $path,?array $payload=null): array
    {
        $url=$this->baseUrl.$path;
        $ch=curl_init($url);
        $options=[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>[
                'Accept: application/json',
                'Content-Type: application/json',
                'apikey: '.$this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>35,
            CURLOPT_CUSTOMREQUEST=>$method,
        ];
        if($payload!==null)$options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch,$options);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);

        if($raw===false||$status<200||$status>=300){
            $detail=trim((string)$raw);
            if(strlen($detail)>240)$detail=substr($detail,0,240).'…';
            throw new RuntimeException('HTTP '.$status.($error?': '.$error:'').($detail!==''?' — '.$detail:''));
        }
        $data=json_decode((string)$raw,true);
        return is_array($data)?$data:['raw'=>(string)$raw];
    }

    private function findWebhookValue(array $data,array $keys): ?string
    {
        $queue=[$data];
        while($queue){
            $node=array_shift($queue);
            foreach($node as $key=>$value){
                if(in_array((string)$key,$keys,true)&&is_string($value)&&trim($value)!=='')return trim($value);
                if(is_array($value))$queue[]=$value;
            }
        }
        return null;
    }

    private function findWebhookBool(array $data,array $keys): ?bool
    {
        $queue=[$data];
        while($queue){
            $node=array_shift($queue);
            foreach($node as $key=>$value){
                if(in_array((string)$key,$keys,true)){
                    if(is_bool($value))return $value;
                    if(is_int($value))return $value===1;
                    if(is_string($value)&&in_array(strtolower($value),['true','false','1','0'],true))return in_array(strtolower($value),['true','1'],true);
                }
                if(is_array($value))$queue[]=$value;
            }
        }
        return null;
    }

    private function findWebhookArray(array $data,array $keys): array
    {
        $queue=[$data];
        while($queue){
            $node=array_shift($queue);
            foreach($node as $key=>$value){
                if(in_array((string)$key,$keys,true)&&is_array($value))return array_values(array_filter(array_map('strval',$value)));
                if(is_array($value))$queue[]=$value;
            }
        }
        return [];
    }

    private function extractState(array $data): string
    {
        $candidates=[
            $data['instance']['state']??null,
            $data['instance']['status']??null,
            $data['state']??null,
            $data['status']??null,
            $data[0]['connectionStatus']??null,
            $data[0]['instance']['state']??null,
            $data[0]['status']??null,
        ];
        foreach($candidates as $value){
            if(is_string($value)&&trim($value)!=='')return trim($value);
        }
        return 'resposta recebida';
    }

    private function findQrBase64(array $data): ?string
    {
        $values=[
            $data['base64']??null,
            $data['qrcode']['base64']??null,
            $data['qr']['base64']??null,
            $data['code']??null,
        ];
        foreach($values as $value){
            if(!is_string($value)||trim($value)==='')continue;
            $value=trim($value);
            if(str_starts_with($value,'data:image/'))return $value;
            if(strlen($value)>120&&preg_match('/^[A-Za-z0-9+\/=\r\n]+$/',$value)){
                return 'data:image/png;base64,'.preg_replace('/\s+/','',$value);
            }
        }
        return null;
    }
}
