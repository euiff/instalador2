<?php

declare(strict_types=1);

final class TtsClient
{
    public function __construct(private string $apiKey, private string $voiceId) {}

    public function configured(): bool
    {
        return trim($this->apiKey)!=='' && trim($this->voiceId)!=='';
    }

    public function speechBase64(string $text): ?string
    {
        if(!$this->configured() || trim($text)==='') return null;
        $url='https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($this->voiceId).'?output_format=mp3_44100_128';
        $payload=[
            'text'=>$text,
            'model_id'=>'eleven_multilingual_v2',
            'voice_settings'=>[
                'stability'=>0.5,
                'similarity_boost'=>0.75,
                'style'=>0.3,
                'use_speaker_boost'=>true,
            ],
        ];
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','xi-api-key: '.$this->apiKey],
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>60,
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false||$status<200||$status>=300||strlen((string)$raw)<100)return null;
        return base64_encode((string)$raw);
    }
}
