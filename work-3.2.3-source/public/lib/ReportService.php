<?php

declare(strict_types=1);

final class ReportService
{
    public function __construct(private PDO $pdo) {}

    public function build(array $church,string $type,string $id='',?string $from=null,?string $to=null): array
    {
        return match($type){
            'prayer_clock'=>$this->prayerClock($church,$id),
            'raffle'=>$this->raffle($church,$id),
            'event'=>$this->event($church,$id),
            'service'=>$this->service($church,$id),
            'finance'=>$this->finance($church,$from,$to),
            'finance_closing'=>$this->financeClosing($church,$id),
            'receipt'=>$this->receipt($church,$id),
            default=>throw new RuntimeException('Tipo de relatório inválido.'),
        };
    }

    private function prayerClock(array $church,string $id): array
    {
        $q=$this->pdo->prepare('SELECT pc.*,c.name congregation_name FROM prayer_clocks pc LEFT JOIN congregations c ON c.id=pc.congregation_id WHERE pc.id=? AND pc.church_id=? LIMIT 1');
        $q->execute([$id,$church['id']]);$clock=$q->fetch();
        if(!$clock)throw new RuntimeException('Relógio de Oração não encontrado.');

        $q=$this->pdo->prepare('SELECT name,phone,slot_time FROM prayer_clock_registrations WHERE prayer_clock_id=? AND church_id=? ORDER BY slot_time,name');
        $q->execute([$id,$church['id']]);$regs=$q->fetchAll();
        $bySlot=[];
        foreach($regs as $r)$bySlot[substr((string)$r['slot_time'],0,5)][]=$r;

        $slots=$this->slots($clock);
        $rows=[];
        foreach($slots as $slot){
            $list=$bySlot[$slot]??[];
            if(!$list){
                $rows[]=[$slot,'— vago —',''];
                continue;
            }
            foreach($list as $r)$rows[]=[$slot,(string)$r['name'],(string)$r['phone']];
        }

        $date=date('d/m/Y',strtotime((string)$clock['event_date']));
        if(!empty($clock['end_date'])&&$clock['end_date']!==$clock['event_date'])$date.=' a '.date('d/m/Y',strtotime((string)$clock['end_date']));

        return [
            'title'=>'Relógio de Oração · '.$clock['title'],
            'subtitle'=>$church['name'],
            'summary'=>[
                'Período'=>$date,
                'Congregação'=>$clock['congregation_name']?:'Geral / Matriz',
                'Duração do turno'=>(int)$clock['slot_duration_minutes'].' min',
                'Inscrições'=>count($regs),
            ],
            'columns'=>['Horário','Participante','Telefone'],
            'rows'=>$rows,
        ];
    }

    private function raffle(array $church,string $id): array
    {
        $q=$this->pdo->prepare('SELECT r.*,c.name congregation_name FROM raffles r LEFT JOIN congregations c ON c.id=r.congregation_id WHERE r.id=? AND r.church_id=? LIMIT 1');
        $q->execute([$id,$church['id']]);$raffle=$q->fetch();
        if(!$raffle)throw new RuntimeException('Rifa não encontrada.');

        $q=$this->pdo->prepare('SELECT number,buyer_name,buyer_phone,paid FROM raffle_numbers WHERE raffle_id=? AND church_id=? ORDER BY number');
        $q->execute([$id,$church['id']]);$sold=$q->fetchAll();
        $map=[];foreach($sold as $n)$map[(int)$n['number']]=$n;

        $rows=[];
        for($i=1;$i<=(int)$raffle['total_numbers'];$i++){
            $n=$map[$i]??null;
            $rows[]=[
                str_pad((string)$i,3,'0',STR_PAD_LEFT),
                $n?(string)$n['buyer_name']:'— disponível —',
                $n?(string)$n['buyer_phone']:'',
                $n?((int)$n['paid']===1?'Pago':'Reservado'):'Disponível',
            ];
        }

        return [
            'title'=>'Rifa · '.$raffle['title'],
            'subtitle'=>$church['name'],
            'summary'=>[
                'Prêmio'=>$raffle['prize_description']?:'—',
                'Valor'=>'R$ '.number_format((float)$raffle['price'],2,',','.'),
                'Sorteio'=>$raffle['draw_date']?date('d/m/Y H:i',strtotime((string)$raffle['draw_date'])):'Não definido',
                'Reservados'=>count($sold).' / '.(int)$raffle['total_numbers'],
            ],
            'columns'=>['Número','Comprador','Telefone','Situação'],
            'rows'=>$rows,
        ];
    }

    private function event(array $church,string $id): array
    {
        $q=$this->pdo->prepare('SELECT e.*,c.name congregation_name FROM events e LEFT JOIN congregations c ON c.id=e.congregation_id WHERE e.id=? AND e.church_id=? LIMIT 1');
        $q->execute([$id,$church['id']]);$event=$q->fetch();
        if(!$event)throw new RuntimeException('Evento não encontrado.');

        $q=$this->pdo->prepare('SELECT name,phone,email,guests_count FROM event_registrations WHERE event_id=? AND church_id=? ORDER BY name');
        $q->execute([$id,$church['id']]);$regs=$q->fetchAll();
        $rows=[];
        foreach($regs as $r)$rows[]=[
            $r['name'],
            $r['phone'],
            $r['email']?:'—',
            (string)((int)$r['guests_count']+1),
        ];

        return [
            'title'=>'Lista do Evento · '.$event['title'],
            'subtitle'=>$church['name'],
            'summary'=>[
                'Data'=>date('d/m/Y H:i',strtotime((string)$event['event_date'])),
                'Local'=>$event['location']?:'—',
                'Congregação'=>$event['congregation_name']?:'Geral / Matriz',
                'Inscrições'=>count($regs),
            ],
            'columns'=>['Participante','Telefone','E-mail','Pessoas'],
            'rows'=>$rows,
        ];
    }

    private function service(array $church,string $id): array
    {
        $q=$this->pdo->prepare('SELECT s.*,c.name congregation_name FROM service_types s LEFT JOIN congregations c ON c.id=s.congregation_id WHERE s.id=? AND s.church_id=? LIMIT 1');
        $q->execute([$id,$church['id']]);$service=$q->fetch();
        if(!$service)throw new RuntimeException('Culto/reunião não encontrado.');
        $days=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];

        return [
            'title'=>'Culto / Reunião · '.$service['name'],
            'subtitle'=>$church['name'],
            'summary'=>[
                'Congregação'=>$service['congregation_name']?:'Geral / Matriz',
                'Dia'=>isset($service['day_of_week'])&&$service['day_of_week']!==null?($days[(int)$service['day_of_week']]??'—'):'—',
                'Horário'=>$service['time']?substr((string)$service['time'],0,5):'—',
                'Recorrente'=>!empty($service['is_recurring'])?'Sim':'Não',
            ],
            'columns'=>['Descrição'],
            'rows'=>[[$service['description']?:'Sem descrição adicional.']],
        ];
    }

    private function finance(array $church,?string $from,?string $to): array
    {
        $from=$from&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)?$from:date('Y-m-01');
        $to=$to&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)?$to:date('Y-m-d');

        $q=$this->pdo->prepare('SELECT COALESCE(SUM(opening_balance),0) FROM finance_accounts WHERE church_id=? AND active=1');
        $q->execute([$church['id']]);$opening=(float)$q->fetchColumn();

        $q=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) FROM finance_entries WHERE church_id=? AND transaction_date<?");
        $q->execute([$church['id'],$from]);$opening+=(float)$q->fetchColumn();

        $q=$this->pdo->prepare("SELECT e.*,a.name account_name FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id WHERE e.church_id=? AND e.status='posted' AND e.transaction_date BETWEEN ? AND ? ORDER BY e.transaction_date,e.created_at");
        $q->execute([$church['id'],$from,$to]);$entries=$q->fetchAll();

        $income=0.0;$expense=0.0;$rows=[];
        foreach($entries as $e){
            $amount=(float)$e['amount'];
            if($e['direction']==='income')$income+=$amount;else$expense+=$amount;
            $rows[]=[
                date('d/m/Y',strtotime((string)$e['transaction_date'])),
                $e['direction']==='income'?'Entrada':'Saída',
                $e['category'],
                $e['description'],
                $e['counterparty']?:'—',
                $e['account_name']?:'—',
                ($e['direction']==='income'?'+ ':'- ').'R$ '.number_format($amount,2,',','.'),
            ];
        }
        $closing=$opening+$income-$expense;

        return [
            'title'=>'Relatório da Tesouraria',
            'subtitle'=>$church['name'],
            'summary'=>[
                'Período'=>date('d/m/Y',strtotime($from)).' a '.date('d/m/Y',strtotime($to)),
                'Saldo inicial'=>'R$ '.number_format($opening,2,',','.'),
                'Entradas'=>'R$ '.number_format($income,2,',','.'),
                'Saídas'=>'R$ '.number_format($expense,2,',','.'),
                'Saldo final'=>'R$ '.number_format($closing,2,',','.'),
                'Resultado do período'=>'R$ '.number_format($income-$expense,2,',','.'),
            ],
            'columns'=>['Data','Tipo','Categoria','Descrição','Favorecido/Pagador','Conta','Valor'],
            'rows'=>$rows,
        ];
    }

    private function financeClosing(array $church,string $id): array
    {
        $q=$this->pdo->prepare('SELECT f.*,a.name account_name,u.full_name closed_by_name FROM finance_closings f LEFT JOIN finance_accounts a ON a.id=f.account_id LEFT JOIN users u ON u.id=f.closed_by_user_id WHERE f.id=? AND f.church_id=? LIMIT 1');
        $q->execute([$id,$church['id']]);$closing=$q->fetch();
        if(!$closing)throw new RuntimeException('Fechamento de caixa não encontrado.');

        $sql="SELECT e.*,a.name account_name FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id WHERE e.church_id=? AND e.status='posted' AND e.transaction_date BETWEEN ? AND ?";
        $params=[$church['id'],$closing['period_start'],$closing['period_end']];
        if(!empty($closing['account_id'])){$sql.=' AND e.account_id=?';$params[]=$closing['account_id'];}
        $sql.=' ORDER BY e.transaction_date,e.created_at';
        $q=$this->pdo->prepare($sql);$q->execute($params);$entries=$q->fetchAll();

        $rows=[];
        foreach($entries as $e){
            $rows[]=[
                date('d/m/Y',strtotime((string)$e['transaction_date'])),
                $e['direction']==='income'?'Entrada':'Saída',
                $e['category'],
                $e['description'],
                $e['counterparty']?:'—',
                ($e['direction']==='income'?'+ ':'- ').'R$ '.number_format((float)$e['amount'],2,',','.'),
            ];
        }

        $period=date('d/m/Y',strtotime((string)$closing['period_start']));
        if($closing['period_end']!==$closing['period_start'])$period.=' a '.date('d/m/Y',strtotime((string)$closing['period_end']));

        return [
            'title'=>'Fechamento de Caixa · '.($closing['period_type']==='monthly'?'Mensal':'Diário'),
            'subtitle'=>$church['name'],
            'summary'=>[
                'Período'=>$period,
                'Conta'=>$closing['account_name']?:'Consolidado · todas as contas',
                'Saldo inicial'=>'R$ '.number_format((float)$closing['opening_balance'],2,',','.'),
                'Entradas'=>'R$ '.number_format((float)$closing['income_total'],2,',','.'),
                'Saídas'=>'R$ '.number_format((float)$closing['expense_total'],2,',','.'),
                'Saldo final'=>'R$ '.number_format((float)$closing['closing_balance'],2,',','.'),
                'Responsável'=>$closing['closed_by_name']?:'Não informado',
                'Fechado em'=>date('d/m/Y H:i',strtotime((string)$closing['closed_at'])),
            ],
            'columns'=>['Data','Tipo','Categoria','Descrição','Favorecido/Pagador','Valor'],
            'rows'=>$rows,
            'receipt'=>[
                'signature_label'=>'Tesoureiro / Responsável pelo fechamento',
                'church_address'=>$church['address']??null,
                'church_phone'=>$church['phone']??null,
            ],
        ];
    }

    private function receipt(array $church,string $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM finance_receipts WHERE id=? AND church_id=? LIMIT 1');
        $q->execute([$id,$church['id']]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('Recibo não encontrado.');

        $amount='R$ '.number_format((float)$r['amount'],2,',','.');
        $statement='Recebemos de '.$r['payer_name'].' a importância de '.$amount
            .' referente a '.$r['description'].'.';

        return [
            'title'=>'RECIBO Nº '.str_pad((string)$r['receipt_number'],6,'0',STR_PAD_LEFT),
            'subtitle'=>$church['name'],
            'summary'=>[
                'Recebemos de'=>$r['payer_name'],
                'Documento'=>$r['payer_document']?:'—',
                'Valor'=>$amount,
                'Data'=>date('d/m/Y',strtotime((string)$r['received_at'])),
                'Forma de pagamento'=>$r['payment_method']?:'Não informada',
            ],
            'columns'=>['Declaração'],
            'rows'=>[[$statement],[$r['notes']?:'']],
            'receipt'=>[
                'statement'=>$statement,
                'signature_label'=>'Tesouraria / Responsável da igreja',
                'church_address'=>$church['address']??null,
                'church_phone'=>$church['phone']??null,
            ],
        ];
    }

    private function slots(array $clock): array
    {
        $start=max(0,min(23,(int)$clock['start_hour']));
        $rawEnd=(int)$clock['end_hour'];
        $end=$rawEnd===24?24:max(0,min(23,$rawEnd));
        $duration=max(1,(int)$clock['slot_duration_minutes']);
        $period=$end>$start?($end-$start)*60:(24-$start+$end)*60;
        if($period===0)$period=1440;
        $count=(int)floor($period/$duration);
        $out=[];
        for($i=0;$i<$count;$i++){
            $minutes=($start*60+$i*$duration)%1440;
            $out[]=sprintf('%02d:%02d',(int)floor($minutes/60),$minutes%60);
        }
        return $out;
    }
}
