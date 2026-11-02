<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceOperations.php';

final class FinanceCsvImport
{
    public function __construct(private PDO $pdo,private Auth $auth,private string $cid) {}
    private function guard(): void {if(!$this->auth->can('finance')||($this->auth->currentChurch()['id']??'')!==$this->cid)throw new RuntimeException('Acesso restrito à tesouraria.');}
    private function named(string $kind,string $name): ?string {
        if($name==='')return null;
        if($kind==='account'){$sql='SELECT id FROM finance_accounts WHERE church_id=? AND active=1 AND name=?';$params=[$this->cid,$name];}
        elseif($kind==='member'){$sql='SELECT id FROM members WHERE church_id=? AND active=1 AND name=?';$params=[$this->cid,$name];}
        else{$sql='SELECT id FROM church_registers WHERE church_id=? AND kind=? AND active=1 AND name=?';$params=[$this->cid,$kind,$name];}
        $q=$this->pdo->prepare($sql);$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)throw new InvalidArgumentException('Nome não encontrado ou ambíguo ('.$kind.'): '.$name);
        return (string)$ids[0];
    }
    public function preview(string $content): array {
        $this->guard();
        if($content===''||strlen($content)>1048576||!mb_check_encoding($content,'UTF-8'))throw new InvalidArgumentException('Use CSV UTF-8 de até 1 MB.');
        $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$content));rewind($stream);
        $headers=fgetcsv($stream,0,';','"','');
        if(!$headers)throw new InvalidArgumentException('Cabeçalho CSV ausente.');
        $normalize=static fn($name)=>strtr(mb_strtolower(trim((string)$name)),['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        $headers=array_map($normalize,$headers);
        if(count($headers)!==count(array_unique($headers)))throw new InvalidArgumentException('Há colunas repetidas no CSV.');
        foreach(['data','tipo','categoria','descricao','valor'] as $required)if(!in_array($required,$headers,true))throw new InvalidArgumentException('Coluna obrigatória ausente: '.$required);
        $rows=[];$skipped=0;$line=1;
        while(($values=fgetcsv($stream,0,';','"',''))!==false){
            $line++;if($line>1001)throw new InvalidArgumentException('Limite de 1.000 linhas por arquivo. Divida o arquivo em partes.');
            if(count($values)===1&&trim((string)$values[0])==='')continue;
            if(count($values)!==count($headers))throw new InvalidArgumentException('Quantidade de colunas inválida na linha '.$line);
            $record=array_combine($headers,array_map(fn($v)=>trim((string)$v),$values));
            if(in_array($normalize($record['situacao']??''),['reversed','cancelled','estornado','cancelado'],true)){$skipped++;continue;}
            try{
                $direction=match($normalize($record['tipo'])){'entrada','income'=>'income','saida','expense'=>'expense',default=>throw new InvalidArgumentException('Tipo deve ser Entrada ou Saída.')};
                $date=$record['data'];if(preg_match('/^\d{2}\/\d{2}\/\d{4}$/D',$date))$date=substr($date,6,4).'-'.substr($date,3,2).'-'.substr($date,0,2);PortalService::date($date);
                $description=$record['descricao'];$category=$record['categoria'];
                if($description===''||mb_strlen($description)>255||$category===''||mb_strlen($category)>160)throw new InvalidArgumentException('Descrição e categoria são obrigatórias.');
                $member=$this->named('member',$record['membro']??'');if($member&&$direction!=='income')throw new InvalidArgumentException('Vínculo de membro permitido somente em entradas.');
                $rows[]=['transaction_date'=>$date,'direction'=>$direction,'category'=>$category,'description'=>$description,'amount'=>PortalService::money($record['valor']),'account_id'=>$this->named('account',$record['conta']??''),'member_id'=>$member,'cost_center_id'=>$this->named('cost_center',$record['centro de custos']??'')];
            }catch(Throwable $e){throw new InvalidArgumentException('Linha '.$line.': '.$e->getMessage());}
        }
        fclose($stream);if(!$rows)throw new InvalidArgumentException('Nenhum lançamento ativo encontrado para importar.');
        return ['hash'=>hash('sha256',$content),'rows'=>$rows,'skipped'=>$skipped];
    }
    public function commit(array $preview): string {
        $this->guard();$rows=$preview['rows']??[];$hash=(string)($preview['hash']??'');
        if(!is_array($rows)||count($rows)<1||count($rows)>1000||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new InvalidArgumentException('Prévia inválida. Envie o arquivo novamente.');
        FinancePeriodGuard::hold($this->pdo,$this->cid);$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT id FROM finance_import_batches WHERE church_id=? AND file_hash=?');$q->execute([$this->cid,$hash]);if($q->fetchColumn())throw new RuntimeException('Este arquivo já foi importado nesta igreja.');
            $batch=app_uuid();$ops=new FinanceOperations($this->pdo,$this->auth,$this->cid);
            foreach($rows as $row){
                FinancePeriodGuard::assertOpen($this->pdo,$this->cid,(string)$row['transaction_date']);
                $account=$ops->account($row['account_id']??null);$ops->references(['cost_center_id'=>$row['cost_center_id']??null]);
                if(!empty($row['member_id'])){$q=$this->pdo->prepare('SELECT id FROM members WHERE church_id=? AND id=? AND active=1');$q->execute([$this->cid,$row['member_id']]);if(!$q->fetchColumn())throw new RuntimeException('Membro da prévia não está disponível nesta igreja.');}
                if(!in_array($row['direction'],['income','expense'],true))throw new RuntimeException('Tipo da prévia inválido.');
                if($row['direction']!=='income'&&!empty($row['member_id']))throw new RuntimeException('Vínculo pessoal inválido.');
                $amount=PortalService::money((string)$row['amount']);
                if(trim((string)$row['description'])===''||mb_strlen((string)$row['description'])>255||trim((string)$row['category'])===''||mb_strlen((string)$row['category'])>160)throw new RuntimeException('Descrição ou categoria da prévia inválida.');
                $q=$this->pdo->prepare("INSERT INTO finance_entries(id,church_id,account_id,member_id,department_id,cost_center_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,'posted','csv_import',?,?)");
                $q->execute([app_uuid(),$this->cid,$row['account_id'],$row['member_id'],$account['department_id']??null,$row['cost_center_id'],$row['direction'],$row['category'],$row['description'],$amount,$row['transaction_date'],$this->auth->requireLogin()['id'],hash('sha256',$batch.':'.count($rows).':'.json_encode($row).':'.random_bytes(16))]);
            }
            $this->pdo->prepare('INSERT INTO finance_import_batches(id,church_id,file_hash,row_count,created_by_user_id) VALUES(?,?,?,?,?)')->execute([$batch,$this->cid,$hash,count($rows),$this->auth->requireLogin()['id']]);
            $this->pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')->execute([app_uuid(),$this->auth->requireLogin()['id'],$this->cid,'finance_csv_imported',json_encode(['batch_id'=>$batch,'rows'=>count($rows)],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();return $batch;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
