<?php

declare(strict_types=1);

final class AiClient
{
    public function __construct(private string $apiKey) {}

    public function configured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function spiritualResponse(string $request, string $churchName, ?string $customPrompt = null): ?string
    {
        if (!$this->configured()) return null;
        $system = $customPrompt ?: 'Responda em português brasileiro, com acolhimento pastoral cristão, linguagem simples, breve, sem prometer cura ou resultado garantido. Quando apropriado, inclua um versículo bíblico curto.';
        return $this->generate($system, "Igreja: {$churchName}\nPedido/mensagem: {$request}");
    }

    public function dailyDevotional(): string
    {
        $fallback=[
            'Bom dia! O Senhor é o meu pastor, nada me faltará. Salmos 23. Que Deus abençoe seu dia com paz e alegria!',
            'Bom dia! Tudo posso naquele que me fortalece. Filipenses 4:13. Que Deus renove suas forças hoje!',
            'Bom dia! Entrega o teu caminho ao Senhor, confia nele e ele tudo fará. Salmos 37:5. Confie no Senhor!',
            'Bom dia! Não temas, porque eu sou contigo. Isaías 41:10. Deus está ao seu lado em todos os momentos!',
        ];
        if(!$this->configured())return $fallback[array_rand($fallback)];
        $day=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('l');
        $system='Você é um pastor cristão. Gere uma palavra do dia muito curta em português brasileiro: no máximo duas frases curtas, inclua um versículo bíblico curto e uma frase de encorajamento. Sem emojis e sem markdown.';
        $text=$this->generate($system,'Gere a mensagem para hoje. Dia da semana: '.$day.'.');
        if(!$text)return $fallback[array_rand($fallback)];
        return trim((string)preg_replace('/[*_`]+/','',$text)," \t\n\r\0\x0B\"'");
    }

    public function transcribeAudioBase64(string $base64, string $mimeType = 'audio/ogg'): ?string
    {
        if (!$this->configured() || trim($base64)==='') return null;
        if (str_contains($base64, ',')) $base64 = explode(',', $base64, 2)[1];
        $url=$this->endpoint();
        $payload=[
            'systemInstruction'=>['parts'=>[['text'=>'Transcreva fielmente o áudio em português brasileiro. Retorne somente a transcrição, sem comentários.']]],
            'contents'=>[['role'=>'user','parts'=>[
                ['text'=>'Transcreva este áudio:'],
                ['inlineData'=>['mimeType'=>$mimeType,'data'=>$base64]],
            ]]],
            'generationConfig'=>['temperature'=>0,'maxOutputTokens'=>1000],
        ];
        return $this->request($url,$payload);
    }

    public function moderate(string $text, array $rules = []): array
    {
        if (!$this->configured()) return ['block'=>false,'reason'=>'ai_unavailable','analysis'=>null];
        $ruleText = json_encode($rules, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $prompt = 'Analise a mensagem para moderação de um grupo de igreja. Considere spam, conteúdo sexual/adulto, palavrões, assédio e as regras fornecidas. Responda SOMENTE JSON válido no formato {"block":true|false,"reason":"...","analysis":"..."}.';
        $raw = $this->generate($prompt, "Regras: {$ruleText}\nMensagem: {$text}");
        if (!$raw) return ['block'=>false,'reason'=>'ai_empty','analysis'=>null];
        $raw = trim((string)preg_replace('/^```(?:json)?|```$/m','',$raw));
        $data = json_decode($raw,true);
        return is_array($data) ? [
            'block'=>(bool)($data['block']??false),
            'reason'=>(string)($data['reason']??'ai'),
            'analysis'=>(string)($data['analysis']??$raw),
        ] : ['block'=>false,'reason'=>'ai_parse','analysis'=>$raw];
    }

    private function endpoint(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='.rawurlencode($this->apiKey);
    }

    private function generate(string $system, string $user): ?string
    {
        $payload=[
            'systemInstruction'=>['parts'=>[['text'=>$system]]],
            'contents'=>[['role'=>'user','parts'=>[['text'=>$user]]]],
            'generationConfig'=>['temperature'=>0.4,'maxOutputTokens'=>700],
        ];
        return $this->request($this->endpoint(),$payload);
    }

    private function request(string $url, array $payload): ?string
    {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>60,
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false || $status<200 || $status>=300) return null;
        $data=json_decode((string)$raw,true);
        $parts=$data['candidates'][0]['content']['parts']??[];
        $text=''; foreach($parts as $p){ if(isset($p['text'])) $text.=$p['text']; }
        return trim($text) ?: null;
    }
}
