<?php
declare(strict_types=1);

final class EventRegistrationService
{
    public function __construct(private PDO $pdo,private string $cid) {}

    private function q(string $sql,array $params=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q;
    }

    public static function publicEvent(PDO $pdo,string $token): ?array
    {
        $q=$pdo->prepare("SELECT e.*,c.name church_name,c.pix_key,c.donation_merchant_name,c.donation_merchant_city,
          (SELECT COALESCE(SUM(1+er.guests_count),0) FROM event_registrations er
             WHERE er.church_id=e.church_id AND er.event_id=e.id AND er.registration_status<>'cancelled') registered_people
          FROM events e
          INNER JOIN churches c ON c.id=e.church_id
          WHERE e.registration_public_token=? AND e.registration_enabled=1 AND e.active=1 AND c.active=1
          LIMIT 1");
        $q->execute([$token]);
        return $q->fetch()?:null;
    }

    public static function registerPublic(PDO $pdo,array $event,array $input): array
    {
        $churchId=(string)$event['church_id'];
        $eventId=(string)$event['id'];
        $name=trim((string)($input['name']??''));
        $phone=trim((string)($input['phone']??''));
        $email=trim((string)($input['email']??''))?:null;
        $birth=trim((string)($input['birth_date']??''))?:null;
        $cpf=trim((string)($input['cpf']??''))?:null;
        $guests=max(0,min(20,(int)($input['guests_count']??0)));
        $method=(string)($input['payment_method']??'');
        $notes=trim((string)($input['notes']??''))?:null;

        if($name===''||$phone==='')throw new InvalidArgumentException('Informe nome e telefone.');
        if(!empty($event['registration_require_email'])&&!filter_var((string)$email,FILTER_VALIDATE_EMAIL)){
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }
        if(!empty($event['registration_require_document'])&&$cpf===null){
            throw new InvalidArgumentException('Informe o CPF/documento solicitado.');
        }
        if(!empty($event['registration_deadline'])&&strtotime((string)$event['registration_deadline'])<time()){
            throw new RuntimeException('O prazo de inscrições deste evento terminou.');
        }

        $fee=(float)($event['registration_fee']??0);
        $methods=json_decode((string)($event['registration_payment_methods']??'[]'),true);
        if(!is_array($methods))$methods=[];
        if($fee<=0){
            $method='free';
        }elseif(!in_array($method,$methods,true)){
            throw new InvalidArgumentException('Escolha uma forma de pagamento disponível.');
        }

        if(!empty($event['capacity'])){
            $q=$pdo->prepare("SELECT COALESCE(SUM(1+guests_count),0) FROM event_registrations
              WHERE church_id=? AND event_id=? AND registration_status<>'cancelled'");
            $q->execute([$churchId,$eventId]);
            $used=(int)$q->fetchColumn();
            $wanted=1+$guests;
            if($used+$wanted>(int)$event['capacity'])throw new RuntimeException('Não há vagas suficientes para esta inscrição.');
        }

        $q=$pdo->prepare("SELECT id FROM event_registrations
          WHERE church_id=? AND event_id=? AND phone=? AND registration_status<>'cancelled' LIMIT 1");
        $q->execute([$churchId,$eventId,$phone]);
        if($q->fetchColumn())throw new RuntimeException('Já existe uma inscrição ativa para este telefone.');

        $id=app_uuid();
        $amount=$fee*(1+$guests);
        $registrationStatus=$fee<=0?'confirmed':'pending';
        $paymentStatus=$fee<=0?'waived':'pending';

        $q=$pdo->prepare("INSERT INTO event_registrations(
          id,church_id,event_id,name,phone,email,birth_date,cpf,guests_count,notes,
          registration_status,payment_status,payment_method,amount_due,amount_paid,confirmed_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)");
        $q->execute([
            $id,$churchId,$eventId,$name,$phone,$email,$birth,$cpf,$guests,$notes,
            $registrationStatus,$paymentStatus,$method,$amount,
            $registrationStatus==='confirmed'?date('Y-m-d H:i:s'):null
        ]);

        return [
            'id'=>$id,
            'registration_status'=>$registrationStatus,
            'payment_status'=>$paymentStatus,
            'payment_method'=>$method,
            'amount_due'=>$amount,
            'people'=>1+$guests,
        ];
    }

    public function event(string $eventId): array
    {
        $q=$this->q("SELECT e.*,
          (SELECT COALESCE(SUM(1+er.guests_count),0) FROM event_registrations er WHERE er.church_id=e.church_id AND er.event_id=e.id AND er.registration_status<>'cancelled') registered_people,
          (SELECT COALESCE(SUM(er.amount_paid),0) FROM event_registrations er WHERE er.church_id=e.church_id AND er.event_id=e.id AND er.payment_status='paid') received_total
          FROM events e WHERE e.id=? AND e.church_id=? LIMIT 1",[$eventId,$this->cid]);
        $row=$q->fetch();
        if(!$row)throw new RuntimeException('Evento não encontrado.');
        return $row;
    }

    public function registrations(?string $eventId=null): array
    {
        $sql="SELECT er.*,e.title event_title,e.event_date
          FROM event_registrations er
          INNER JOIN events e ON e.id=er.event_id AND e.church_id=er.church_id
          WHERE er.church_id=?";
        $params=[$this->cid];
        if($eventId){$sql.=" AND er.event_id=?";$params[]=$eventId;}
        $sql.=" ORDER BY er.created_at DESC";
        return $this->q($sql,$params)->fetchAll();
    }

    public function updateStatus(string $id,string $registrationStatus,string $paymentStatus,float $amountPaid=0,?string $reference=null): void
    {
        if(!in_array($registrationStatus,['pending','confirmed','cancelled','waitlist'],true))throw new InvalidArgumentException('Status da inscrição inválido.');
        if(!in_array($paymentStatus,['unpaid','pending','paid','waived','refunded'],true))throw new InvalidArgumentException('Status do pagamento inválido.');
        $amountPaid=max(0,$amountPaid);
        if($paymentStatus==='paid'&&$registrationStatus==='pending')$registrationStatus='confirmed';
        $confirmed=$registrationStatus==='confirmed'?date('Y-m-d H:i:s'):null;

        $q=$this->q('UPDATE event_registrations SET registration_status=?,payment_status=?,amount_paid=?,payment_reference=?,confirmed_at=? WHERE id=? AND church_id=?',[
            $registrationStatus,$paymentStatus,$amountPaid,$reference,$confirmed,$id,$this->cid
        ]);
        if($q->rowCount()===0&&!$this->q('SELECT 1 FROM event_registrations WHERE id=? AND church_id=?',[$id,$this->cid])->fetchColumn()){
            throw new RuntimeException('Inscrição não encontrada.');
        }
    }

    public function stats(string $eventId): array
    {
        $event=$this->event($eventId);
        $rows=$this->registrations($eventId);
        $confirmed=0;$pending=0;$cancelled=0;$paid=0.0;$due=0.0;$people=0;
        foreach($rows as $r){
            if($r['registration_status']==='confirmed')$confirmed++;
            elseif($r['registration_status']==='cancelled')$cancelled++;
            else $pending++;
            if($r['registration_status']!=='cancelled')$people+=1+(int)$r['guests_count'];
            $paid+=(float)$r['amount_paid'];
            if($r['registration_status']!=='cancelled')$due+=(float)$r['amount_due'];
        }
        return [
            'event'=>$event,'rows'=>$rows,'confirmed'=>$confirmed,'pending'=>$pending,'cancelled'=>$cancelled,
            'people'=>$people,'paid'=>$paid,'due'=>$due,'available'=>!empty($event['capacity'])?max(0,(int)$event['capacity']-$people):null
        ];
    }
}
