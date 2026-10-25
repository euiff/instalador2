<?php

declare(strict_types=1);

require_once __DIR__.'/BibleReadingService.php';

final class BotBibleFlow
{
    public function __construct(private PDO $pdo) {}

    public function start(string $churchId,string $phone): string
    {
        $this->saveState($churchId,$phone,'bible_menu',[]);
        return $this->menu();
    }

    public function handle(array $state,string $text): array
    {
        $churchId=(string)$state['church_id'];
        $phone=(string)$state['phone'];
        $step=(string)$state['state'];
        $data=json_decode((string)($state['data']??'{}'),true)?:[];
        $value=trim($text);
        $lower=mb_strtolower($value,'UTF-8');

        if(in_array($lower,['cancelar','sair','menu','0'],true)){
            $this->clear((string)$state['id']);
            return ['done'=>true,'reply'=>'Leitura bíblica encerrada. Digite *menu* para voltar ao início.'];
        }

        if($step==='bible_menu'){
            if($value==='1'){
                $this->saveState($churchId,$phone,'bible_reference',[]);
                return ['done'=>false,'reply'=>"📖 *Ler a Bíblia agora*

Digite o livro e o capítulo.
Exemplos:
• *João 3*
• *Salmos 23*
• *1 Coríntios 13*"];
            }

            $plans=[
                '2'=>'full_bible',
                '3'=>'new_testament',
                '4'=>'psalms_proverbs',
                '5'=>'full_bible_1year',
            ];
            if(isset($plans[$value])){
                $data=['plan_type'=>$plans[$value]];
                $this->saveState($churchId,$phone,'bible_plan_time',$data);
                return ['done'=>false,'reply'=>"⏰ Em qual horário você quer receber a leitura todos os dias?
Exemplos: *07:00*, *19:30* ou *8h*."];
            }

            if($value==='6'){
                return ['done'=>false,'reply'=>$this->plans($churchId,$phone)."

".$this->menu()];
            }

            if($value==='7'){
                $q=$this->pdo->prepare('UPDATE bible_reading_plans SET active=0,completed_at=COALESCE(completed_at,NOW()) WHERE church_id=? AND phone=? AND active=1');
                $q->execute([$churchId,$phone]);
                return ['done'=>false,'reply'=>($q->rowCount()>0?'✅ Plano(s) de leitura cancelado(s).':'Você não possui plano ativo.')."

".$this->menu()];
            }

            return ['done'=>false,'reply'=>"Opção inválida.

".$this->menu()];
        }

        if($step==='bible_reference'){
            $service=new BibleReadingService();
            $ref=$service->parseReference($value);
            if(!$ref){
                return ['done'=>false,'reply'=>"Não reconheci essa referência. Digite, por exemplo: *João 3*, *Salmos 23* ou *1 Coríntios 13*.

Digite *0* para voltar ao menu."];
            }
            $this->saveState($churchId,$phone,'bible_menu',[]);
            $chapter=$service->chapterText($ref['book'],$ref['chapter']);
            return ['done'=>false,'reply'=>$chapter."

━━━━━━━━━━━━
Digite *1* para ler outra passagem ou escolha outra opção:
".$this->menu(false)];
        }

        if($step==='bible_plan_time'){
            $time=$this->parseTime($value);
            if(!$time)return ['done'=>false,'reply'=>'Horário inválido. Use *07:00*, *19:30* ou *8h*.'];
            $data['delivery_time']=$time;
            $this->saveState($churchId,$phone,'bible_plan_audio',$data);
            return ['done'=>false,'reply'=>"🔊 Deseja receber também *áudio* da leitura quando disponível?
*1.* Sim
*2.* Não"];
        }

        if($step==='bible_plan_audio'){
            if(!in_array($value,['1','2'],true))return ['done'=>false,'reply'=>'Digite *1* para Sim ou *2* para Não.'];

            $type=(string)($data['plan_type']??'full_bible');
            $time=(string)($data['delivery_time']??'08:00');
            $service=new BibleReadingService();
            $first=$service->firstForPlan($type);

            $this->pdo->prepare('UPDATE bible_reading_plans SET active=0 WHERE church_id=? AND phone=? AND active=1')
                ->execute([$churchId,$phone]);

            $q=$this->pdo->prepare('INSERT INTO bible_reading_plans(id,church_id,phone,name,plan_type,current_book,current_chapter,include_audio,delivery_time,active) VALUES(?,?,?,?,?,?,?,?,?,1)');
            $q->execute([
                app_uuid(),$churchId,$phone,null,$type,$first['book'],1,
                $value==='1'?1:0,$time
            ]);

            $this->saveState($churchId,$phone,'bible_menu',[]);
            return [
                'done'=>false,
                'reply'=>"✅ *Plano de leitura ativado!*
📚 ".$service->planLabel($type)."
⏰ Todos os dias às ".substr($time,0,5)."
🔊 Áudio: ".($value==='1'?'Sim':'Não')."

O cron do sistema enviará sua leitura automaticamente.

".$this->menu()
            ];
        }

        return ['done'=>false,'reply'=>$this->menu()];
    }

    public function directReference(string $text): ?array
    {
        return (new BibleReadingService())->parseReference($text);
    }

    private function menu(bool $withTitle=true): string
    {
        $lines=[];
        if($withTitle)$lines[]='📖 *BÍBLIA E PLANO DE LEITURA*';
        $lines[]='*1.* Ler uma passagem agora';
        $lines[]='*2.* Plano Bíblia Completa';
        $lines[]='*3.* Plano Novo Testamento';
        $lines[]='*4.* Plano Salmos e Provérbios';
        $lines[]='*5.* Bíblia Completa em 1 ano';
        $lines[]='*6.* Ver meu plano';
        $lines[]='*7.* Cancelar plano';
        $lines[]='*0.* Voltar ao menu principal';
        return implode("
",$lines);
    }

    private function plans(string $churchId,string $phone): string
    {
        $q=$this->pdo->prepare('SELECT * FROM bible_reading_plans WHERE church_id=? AND phone=? ORDER BY created_at DESC LIMIT 5');
        $q->execute([$churchId,$phone]);
        $rows=$q->fetchAll();
        if(!$rows)return '📚 Você ainda não possui plano de leitura.';

        $service=new BibleReadingService();
        $lines=['📚 *Seus planos de leitura*'];
        foreach($rows as $r){
            $lines[]='• '.$service->planLabel((string)$r['plan_type']).' — '.($r['active']?'ativo':'encerrado').' — '.$r['current_book'].' '.(int)$r['current_chapter'].' — '.substr((string)$r['delivery_time'],0,5);
        }
        return implode("
",$lines);
    }

    private function parseTime(string $value): ?string
    {
        $value=trim(mb_strtolower($value,'UTF-8'));
        if(preg_match('/^(\d{1,2}):(\d{2})$/',$value,$m)){
            $h=(int)$m[1];$min=(int)$m[2];
        }elseif(preg_match('/^(\d{1,2})\s*h(?:\s*(\d{1,2}))?$/u',$value,$m)){
            $h=(int)$m[1];$min=isset($m[2])?(int)$m[2]:0;
        }else return null;
        if($h<0||$h>23||$min<0||$min>59)return null;
        return sprintf('%02d:%02d:00',$h,$min);
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
