<?php

declare(strict_types=1);

final class PosterGenerator
{
    public function __construct(private string $apiKey) {}

    public function configured(): bool { return trim($this->apiKey)!==''; }

    public function eventPoster(array $church,array $event,string $storageRoot): ?string
    {
        if(!$this->configured()) return null;
        $date=new DateTimeImmutable((string)$event['event_date']);
        $prompt="Crie um cartaz/flyer profissional e moderno para evento de igreja.\n\nEVENTO: \"{$event['title']}\"\nIGREJA: \"{$church['name']}\"\nDATA: ".$date->format('d/m/Y')."\nHORÁRIO: ".$date->format('H:i')."\n".
            (!empty($event['location'])?'LOCAL: '.$event['location']."\n":'').
            (!empty($event['description'])?'DESCRIÇÃO: '.$event['description']."\n":'').
            (!empty($event['theme'])?'TEMA: '.$event['theme']."\n":'').
            (!empty($event['speaker_name'])?'PRELETOR/CANTOR: '.$event['speaker_name']."\n":'').
            "\nREQUISITOS: design profissional, moderno e elegante; cores quentes e convidativas; título grande e legível; data e horário visíveis; estética cristã sem exagero; formato vertical 9:16 para WhatsApp; acabamento de alta qualidade.";
        $parts=$this->parts($prompt,[$church['logo_url']??null,$event['speaker_photo_url']??null]);
        return $this->generateAndSave($parts,$storageRoot,(string)$church['id'],'event-'.(string)$event['id']);
    }

    public function servicePoster(array $church,array $service,?array $congregation,string $storageRoot): ?string
    {
        if(!$this->configured()) return null;
        $days=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
        $name=(string)($congregation['name']??$church['name']);
        $address=(string)($congregation['address']??$church['address']??'');
        $day=isset($service['day_of_week'])&&$service['day_of_week']!==null?($days[(int)$service['day_of_week']]??'Toda semana'):'Toda semana';
        $time=!empty($service['time'])?substr((string)$service['time'],0,5):'';
        $prompt="TAREFA: Criar um CARTAZ PROFISSIONAL para culto de igreja evangélica brasileira.\n\nTEXTOS EXATOS:\nTÍTULO: \"{$service['name']}\"\nIGREJA: \"{$name}\"\nDIA: \"{$day}\"\n".
            ($time!==''?"HORÁRIO: \"{$time}\"\n":'').
            ($address!==''?"LOCAL: \"{$address}\"\n":'').
            (!empty($service['description'])?"DESCRIÇÃO: {$service['description']}\n":'').
            "\nESTILO: moderno, impactante, tipografia forte e legível, qualidade profissional de agência, formato vertical 9:16, hierarquia clara, estética cristã elegante. Não altere os textos fornecidos.";
        $parts=$this->parts($prompt,[$church['logo_url']??null]);
        return $this->generateAndSave($parts,$storageRoot,(string)$church['id'],'service-'.(string)$service['id']);
    }

    private function parts(string $prompt,array $urls): array
    {
        $parts=[['text'=>$prompt]];
        foreach($urls as $url){
            if(!$url||!preg_match('#^https?://#i',(string)$url))continue;
            $img=$this->fetchImage((string)$url);if($img)$parts[]=['inlineData'=>['mimeType'=>$img['mime'],'data'=>base64_encode($img['bytes'])]];
        }
        return $parts;
    }

    private function generateAndSave(array $parts,string $storageRoot,string $churchId,string $prefix): ?string
    {
        $models=['gemini-2.0-flash-preview-image-generation','gemini-2.0-flash-exp-image-generation','gemini-2.0-flash-exp'];
        foreach($models as $model){
            $image=$this->generate($model,$parts);if(!$image)continue;
            $safeChurch=preg_replace('/[^a-zA-Z0-9_-]/','',$churchId)?:'church';
            $dir=rtrim($storageRoot,'/\\').'/storage/uploads/posters/'.$safeChurch;
            if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))return null;
            $ext=str_contains($image['mime'],'png')?'png':'jpg';
            $name=preg_replace('/[^a-zA-Z0-9_-]/','',$prefix).'-'.date('YmdHis').'.'.$ext;
            if(file_put_contents($dir.'/'.$name,$image['bytes'],LOCK_EX)===false)return null;
            return 'storage/uploads/posters/'.$safeChurch.'/'.$name;
        }
        return null;
    }

    private function generate(string $model,array $parts): ?array
    {
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($this->apiKey);
        $payload=['contents'=>[['parts'=>$parts]],'generationConfig'=>['responseModalities'=>['IMAGE','TEXT']]];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false||$status<200||$status>=300)return null;
        $data=json_decode((string)$raw,true);$out=$data['candidates'][0]['content']['parts']??[];
        foreach($out as $part){$inline=$part['inlineData']??$part['inline_data']??null;if(!is_array($inline))continue;$b64=$inline['data']??null;if(!$b64)continue;$bytes=base64_decode((string)$b64,true);if($bytes===false||strlen($bytes)<100)return null;return ['bytes'=>$bytes,'mime'=>(string)($inline['mimeType']??$inline['mime_type']??'image/png')];}
        return null;
    }

    private function fetchImage(string $url): ?array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20]);$bytes=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
        if($bytes===false||$status<200||$status>=300||strlen((string)$bytes)<100)return null;
        return ['bytes'=>(string)$bytes,'mime'=>$mime?:'image/png'];
    }
}
