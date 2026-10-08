<?php

declare(strict_types=1);

require_once __DIR__.'/BotBibleFlow.php';
require_once __DIR__.'/BotEventFlow.php';
require_once __DIR__.'/BotDocumentFlow.php';
require_once __DIR__.'/BibleReadingService.php';

final class BotWebhookHandler
{
    public function __construct(private PDO $pdo) {}

    public function handle(array $payload): array
    {
        $instance=(string)($payload['instance']??'');
        $data=is_array($payload['data']??null)?$payload['data']:[];
        $key=is_array($data['key']??null)?$data['key']:[];
        $jid=(string)($key['remoteJid']??'');
        if(!empty($key['fromMe'])) return ['ok'=>true,'ignored'=>'from_me'];

        $q=$this->pdo->prepare('SELECT * FROM churches WHERE evolution_instance_name=? AND active=1 LIMIT 1');
        $q->execute([$instance]);$church=$q->fetch();
        if(!$church) return ['ok'=>true,'ignored'=>'church_not_found'];

        $client=new EvolutionClient((string)($church['evolution_api_url']??''),(string)($church['evolution_api_key']??''),(string)($church['evolution_instance_name']??''));
        $ai=new AiClient((string)($church['gemini_api_key']??''));
        $message=is_array($data['message']??null)?$data['message']:[];
        $text=(string)($message['conversation']??$message['extendedTextMessage']['text']??$message['buttonsResponseMessage']['selectedButtonId']??$message['listResponseMessage']['singleSelectReply']['selectedRowId']??'');
        $name=(string)($data['pushName']??'');

        if($this->isGroupJid($jid)){
            if(trim($text)==='') return ['ok'=>true,'ignored'=>'group_no_text'];

            $participant=$this->firstString([
                $key['participant']??null,
                $data['participant']??null,
                $key['senderPn']??null,
                $data['senderPn']??null,
                $data['sender']??null,
                $key['remoteJidAlt']??null,
            ]);
            $phone=$this->jidDigits($participant);

            // Algumas versões da Evolution/Baileys podem não enviar participant
            // em mensagens de grupo. O sistema antigo continuava o fluxo mesmo
            // assim; não podemos descartar a inscrição do relógio de oração.
            if($phone===''){
                $stableSource=trim($name)!==''?$name:(string)($key['id']??'participante');
                $phone='group-'.substr(hash('sha256',$jid.'|'.$stableSource),0,24);
            }

            try{
                $this->pdo->prepare('INSERT INTO bot_messages(id,church_id,phone,direction,message,message_type,status) VALUES(?,?,?,"inbound",?,"group","received")')
                    ->execute([app_uuid(),$church['id'],$phone,$text]);
            }catch(Throwable){}

            $handler=new GroupMessageHandler($this->pdo,$client,$ai);
            $result=$handler->handle($church,$jid,$phone,$name,$text,$key);

            if(!empty($result['reply'])&&$client->configured()){
                $client->sendGroupText($jid,(string)$result['reply']);
                try{
                    $this->pdo->prepare('INSERT INTO bot_messages(id,church_id,phone,direction,message,message_type,status) VALUES(?,?,?,"outbound",?,"group","sent")')
                        ->execute([app_uuid(),$church['id'],$jid,(string)$result['reply']]);
                }catch(Throwable){}
            }

            return [
                'ok'=>true,
                'group'=>true,
                'group_jid'=>$jid,
                'sender'=>$phone,
                'handled'=>(bool)($result['handled']??false),
                'reason'=>(string)($result['reason']??''),
            ];
        }

        $phone=preg_replace('/\D+/','',preg_replace('/@.*/','',$jid))?:'';
        if($phone==='') return ['ok'=>true,'ignored'=>'no_phone'];

        if(isset($message['audioMessage'])&&!empty($church['ai_enabled'])&&$ai->configured()&&$client->configured()){
            $messageId=(string)($key['id']??'');$mime=(string)($message['audioMessage']['mimetype']??'audio/ogg');
            try{$b64=$client->mediaBase64($messageId);if($b64){$t=$ai->transcribeAudioBase64($b64,$mime);if($t)$text=$t;}}catch(Throwable){}
        }

        $stateQ=$this->pdo->prepare('SELECT * FROM bot_conversation_states WHERE church_id=? AND phone=? AND expires_at>NOW() LIMIT 1');
        $stateQ->execute([$church['id'],$phone]);$state=$stateQ->fetch();
        $photoUrl=null;
        $photoStates=['edit_photo','reg_photo'];
        if(isset($message['imageMessage'])&&$state&&in_array((string)$state['state'],$photoStates,true)&&$client->configured()){
            try{$photoUrl=$client->saveImageMessage((string)($key['id']??''),(string)$church['id'],dirname(__DIR__));}catch(Throwable){}
        }

        if(trim($text)===''&&!$photoUrl) return ['ok'=>true,'ignored'=>'no_content'];
        $this->pdo->prepare('INSERT INTO bot_messages(id,church_id,phone,direction,message,message_type,status) VALUES(?,?,?,"inbound",?,?,"received")')
            ->execute([app_uuid(),$church['id'],$phone,$text!==''?$text:'[imagem]',isset($message['audioMessage'])?'audio':(isset($message['imageMessage'])?'image':'text')]);

        $reply='';
        if($state&&str_starts_with((string)$state['state'],'doc_')){
            $doc=(new BotDocumentFlow($this->pdo,dirname(__DIR__)))->handle($state,$text,$church,null);
            $reply=(string)($doc['reply']??'');
            if(!empty($doc['document_path'])&&$client->configured()){
                $sent=$client->sendDocumentFile(
                    $phone,
                    (string)$doc['document_path'],
                    (string)($doc['document_name']??'documento.pdf'),
                    'Documento emitido por '.$church['name']
                );
                @unlink((string)$doc['document_path']);
                if(!$sent)$reply.="

⚠️ O documento foi gerado, mas não consegui anexar o PDF. Tente novamente ou solicite à secretaria.";
            }
        }else{
            $reply=$this->reply($church,$phone,$name,$text,$state,$photoUrl,$client,$ai);
        }

        if($reply!==''&&$client->configured()){
            $client->sendText($phone,$reply);
            $this->pdo->prepare('INSERT INTO bot_messages(id,church_id,phone,direction,message,message_type,status) VALUES(?,?,?,"outbound",?,"text","sent")')
                ->execute([app_uuid(),$church['id'],$phone,$reply]);
        }
        return ['ok'=>true];
    }

    private function isGroupJid(string $jid): bool
    {
        $jid=mb_strtolower(trim($jid),'UTF-8');
        return str_ends_with($jid,'@g.us')||str_contains($jid,'@g.us');
    }

    private function firstString(array $values): string
    {
        foreach($values as $value){
            if(is_string($value)&&trim($value)!=='')return trim($value);
        }
        return '';
    }

    private function jidDigits(string $jid): string
    {
        if($jid==='')return '';
        $base=preg_replace('/@.*/','',$jid)??$jid;
        return preg_replace('/\D+/','',$base)?:'';
    }

    private function reply(array $church,string $phone,string $name,string $text,array|false $state,?string $photoUrl,EvolutionClient $client,AiClient $ai):string
    {
        $cid=(string)$church['id'];
        $normalized=mb_strtolower(trim($text),'UTF-8');
        $s=$this->pdo->prepare('SELECT * FROM bot_settings WHERE church_id=? LIMIT 1');
        $s->execute([$cid]);
        $settings=$s->fetch()?:[];

        if($state&&str_starts_with((string)$state['state'],'reg_')) return (new BotRegistrationFlow($this->pdo))->handle($state,$text,$photoUrl)['reply'];
        if($state&&str_starts_with((string)$state['state'],'edit_')) return (new BotEditFlow($this->pdo))->handle($state,$text,$photoUrl)['reply'];
        if($state&&str_starts_with((string)$state['state'],'spiritual_')) return (new SpiritualFlow($this->pdo,$ai))->handle($church,$settings,$state,$text)['reply'];
        if($state&&str_starts_with((string)$state['state'],'bible_')) return (new BotBibleFlow($this->pdo))->handle($state,$text)['reply'];
        if($state&&str_starts_with((string)$state['state'],'event_')) return (new BotEventFlow($this->pdo))->handle($state,$text)['reply'];

        if(in_array($normalized,['oi','olá','ola','menu','início','inicio','0'],true)){
            return (string)($church['welcome_message']?:'🙏 Seja bem-vindo(a)!')."\n\n".$this->menuText($settings);
        }

        if(in_array($normalized,['horarios','horários','cultos','1'],true)){
            return (string)($settings['schedule_text']?:'📅 Consulte a secretaria para os horários dos cultos.');
        }

        if(in_array($normalized,['oração','oracao','pedido de oração','pedido de oracao','2'],true)){
            return (new SpiritualFlow($this->pdo,$ai))->startPrayer($cid,$phone,$name);
        }

        if(in_array($normalized,['bíblia','biblia','leitura bíblica','leitura biblica','3'],true)){
            return (new BotBibleFlow($this->pdo))->start($cid,$phone);
        }

        if(str_starts_with($normalized,'biblia ')||str_starts_with($normalized,'bíblia ')){
            $reference=trim(preg_replace('/^b[íi]blia\s+/ui','',$text)??'');
            $ref=(new BibleReadingService())->parseReference($reference);
            return $ref
                ?(new BibleReadingService())->chapterText($ref['book'],$ref['chapter'])
                :"Não reconheci a referência. Exemplo: *Bíblia João 3*.";
        }

        if(in_array($normalized,['cancelar plano','cancelar leitura'],true)){
            $q=$this->pdo->prepare('UPDATE bible_reading_plans SET active=0,completed_at=COALESCE(completed_at,NOW()) WHERE church_id=? AND phone=? AND active=1');
            $q->execute([$cid,$phone]);
            return $q->rowCount()>0?'✅ Seu plano de leitura foi cancelado.':'Você não possui plano de leitura ativo.';
        }

        if(in_array($normalized,['eventos','evento','inscrição','inscricao','4'],true)){
            return (new BotEventFlow($this->pdo))->start($cid,$phone,$settings);
        }

        if(in_array($normalized,['contato','falar com igreja','falar com a igreja','5'],true)){
            $parts=['📞 *FALE COM A IGREJA*'];
            if(!empty($church['phone']))$parts[]='Telefone/WhatsApp: '.$church['phone'];
            if(!empty($church['email']))$parts[]='E-mail: '.$church['email'];
            if(!empty($church['address']))$parts[]='Endereço: '.$church['address'];
            if(count($parts)===1)$parts[]='Os dados de contato ainda não foram cadastrados.';
            return implode("\n",$parts);
        }

        if(in_array($normalized,['documentos','documento','declaração','declaracao','6'],true)){
            return (new BotDocumentFlow($this->pdo,dirname(__DIR__)))->start($cid,$phone);
        }

        if(in_array($normalized,['cadastro','cadastrar','me cadastrar','7'],true)){
            return (new BotRegistrationFlow($this->pdo))->start($cid,$phone,$name);
        }

        if(in_array($normalized,['editar','meus dados','alterar cadastro','8'],true)){
            return (new BotEditFlow($this->pdo))->start($cid,$phone);
        }

        if(in_array($normalized,['aconselhamento','aconselhamento pastoral','apoio','9'],true)){
            return (new SpiritualFlow($this->pdo,$ai))->startCounseling($cid,$phone,$name);
        }

        return "Não entendi essa opção. Digite *menu* para ver as opções disponíveis.";
    }

    private function menuText(array $settings): string
    {
        $default="1️⃣ Horários dos Cultos\n2️⃣ Pedido de Oração\n3️⃣ Bíblia e Plano de Leitura\n4️⃣ Eventos e Inscrições\n5️⃣ Falar com a Igreja\n6️⃣ Documentos\n7️⃣ Cadastro de Membro\n8️⃣ Meus Dados\n9️⃣ Aconselhamento Pastoral";
        $custom=trim((string)($settings['menu_text']??''));
        if($custom==='')return $default;

        // Menus salvos da versão antiga tinham PIX na opção 3 e Palavra Bíblica
        // fixa na 6. Eles são substituídos pelo menu novo para evitar duplicação.
        $legacy=(
            str_contains($custom,'Ofertas / Dízimos')||
            str_contains($custom,'Palavra Bíblica')||
            str_contains($custom,'Apoio espiritual')
        );
        return $legacy?$default:$custom;
    }
}
