<?php

declare(strict_types=1);

require_once __DIR__.'/MemberDocumentService.php';

final class BotDocumentFlow
{
    public function __construct(private PDO $pdo,private string $appRoot) {}

    public function start(string $churchId,string $phone): string
    {
        $service=new MemberDocumentService($this->pdo,$this->appRoot);
        $member=$service->memberByPhone($churchId,$phone);
        if(!$member){
            return "📄 *Documentos*

Não encontrei um cadastro de membro ligado a este número de WhatsApp.
Digite *7* para fazer seu cadastro ou fale com a secretaria.";
        }

        $types=$service->types($member);
        $map=[];$lines=['📄 *DOCUMENTOS DO MEMBRO*','👤 '.$member['name'],'','Escolha o documento que deseja receber:'];
        $i=1;
        foreach($types as $key=>[$prefix,$label]){
            $map[(string)$i]=$key;
            $lines[]='*'.$i.'.* '.$label;
            $i++;
        }
        $lines[]='*0.* Voltar ao menu principal';
        $this->saveState($churchId,$phone,'doc_menu',['member_id'=>$member['id'],'types'=>$map]);
        return implode("
",$lines);
    }

    public function handle(array $state,string $text,array $church,?string $issuedBy=null): array
    {
        $churchId=(string)$state['church_id'];$phone=(string)$state['phone'];
        $data=json_decode((string)($state['data']??'{}'),true)?:[];
        $value=trim($text);$lower=mb_strtolower($value,'UTF-8');

        if(in_array($lower,['cancelar','sair','menu','0'],true)){
            $this->clear((string)$state['id']);
            return ['done'=>true,'reply'=>'Documentos encerrados. Digite *menu* para voltar ao início.'];
        }

        if((string)$state['state']!=='doc_menu')return ['done'=>false,'reply'=>'Digite *0* para voltar ao menu.'];

        $type=$data['types'][$value]??null;
        if(!$type)return ['done'=>false,'reply'=>'Opção inválida. Digite o número do documento desejado ou *0* para voltar.'];

        $service=new MemberDocumentService($this->pdo,$this->appRoot);
        $member=$service->member($churchId,(string)($data['member_id']??''));
        if(!$member){$this->clear((string)$state['id']);return ['done'=>true,'reply'=>'Seu cadastro não foi encontrado. Fale com a secretaria.'];}

        $issued=$service->issue($church,$member,$type,$issuedBy);
        $path=$service->saveTemporary($issued,$churchId);
        $this->clear((string)$state['id']);

        return [
            'done'=>true,
            'reply'=>'✅ Documento gerado: *'.$issued['title'].'*.'."
".'O PDF foi enviado nesta conversa.',
            'document_path'=>$path,
            'document_name'=>$issued['file_name'],
        ];
    }

    private function saveState(string $churchId,string $phone,string $state,array $data): void
    {
        $q=$this->pdo->prepare('INSERT INTO bot_conversation_states(id,church_id,phone,state,data,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE state=VALUES(state),data=VALUES(data),expires_at=VALUES(expires_at)');
        $q->execute([app_uuid(),$churchId,$phone,$state,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function clear(string $id): void
    {
        $this->pdo->prepare('DELETE FROM bot_conversation_states WHERE id=?')->execute([$id]);
    }
}
