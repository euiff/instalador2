<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceOperations.php';
require_once __DIR__.'/RegistryService.php';
require_once __DIR__.'/DepartmentTreasuryService.php';
require_once __DIR__.'/FinancePeriodGuard.php';
require_once __DIR__.'/FinanceFiscalScan.php';
require_once __DIR__.'/UploadService.php';
require_once __DIR__.'/GroupedFinanceStage.php';

final class GroupedFinanceService
{
    public function __construct(private PDO $db,private Auth $auth,private string $cid) {}

    private function attachment(array $files,int $i): array
    {
        $out=[];
        foreach(['name','type','tmp_name','error','size'] as $field)
            $out[$field]=$files[$field][$i]??($field==='error'?UPLOAD_ERR_NO_FILE:null);
        return $out;
    }

    public function create(array $input,array $uploads): array
    {
        if(!$this->auth->can('finance')||($this->auth->currentChurch()['id']??'')!==$this->cid)
            throw new RuntimeException('Sem permissão para lançar nesta igreja.');
        $actor=(string)$this->auth->requireLogin()['id'];
        $requestKey=trim((string)($input['request_key']??''));
        if(!preg_match('/^[a-f0-9]{64}$/D',$requestKey))
            throw new InvalidArgumentException('Reabra o formulário para gerar a chave de segurança.');
        $lookup=$this->db->prepare('SELECT id FROM finance_entries WHERE church_id=? AND request_key=? LIMIT 1');
        $lookup->execute([$this->cid,$requestKey]);
        if($existing=$lookup->fetchColumn())
            return ['entry_id'=>(string)$existing,'already_registered'=>true];

        $items=json_decode((string)($input['items_json']??''),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($items)||!array_is_list($items)||count($items)<2||count($items)>12)
            throw new InvalidArgumentException('Informe de 2 a 12 itens no lançamento agrupado.');
        $direction=(string)($input['direction']??'expense');
        if(!in_array($direction,['expense','income'],true))
            throw new InvalidArgumentException('Escolha entrada ou saída.');
        $date=(string)($input['transaction_date']??'');
        PortalService::date($date);
        $description=mb_substr(trim((string)($input['description']??'')),0,255);
        if($description==='')throw new InvalidArgumentException('Informe a descrição do lançamento agrupado.');

        $accountId=trim((string)($input['account_id']??''))?:null;
        $departmentId=null;
        if($accountId!==null){
            $q=$this->db->prepare('SELECT id,department_id FROM finance_accounts WHERE church_id=? AND id=? AND active=1');
            $q->execute([$this->cid,$accountId]);$account=$q->fetch(PDO::FETCH_ASSOC);
            if(!$account)throw new InvalidArgumentException('A conta selecionada não existe ou está inativa.');
            if(!empty($account['department_id'])){
                if(!$this->auth->can('department_treasury'))
                    throw new RuntimeException('Seu perfil não pode movimentar caixas departamentais.');
                $service=new DepartmentTreasuryService($this->db,$this->auth,$this->cid,$actor);
                if(!$service->mayPost())throw new RuntimeException('Sem permissão para lançar neste departamento.');
                $allowed=false;
                foreach($service->departments() as $dep){
                    if((string)$dep['id']===(string)$account['department_id'] && !empty($dep['active']))$allowed=true;
                }
                if(!$allowed)throw new RuntimeException('Caixa de departamento não disponível para este usuário.');
                $departmentId=(string)$account['department_id'];
            }
        }
        // All active accounts are tenant-scoped above; department accounts additionally require scope permission.

        $registry=new RegistryService($this->db,$this->auth,$this->cid);
        $categoryNames=[];
        foreach($registry->all('finance_category') as $record){
            $name=trim((string)($record['name']??''));
            if($name!=='')$categoryNames[mb_strtolower($name,'UTF-8')]=[
                'name'=>$name,'direction'=>(string)($record['data']['direction']??'both')
            ];
        }
        $parsed=[];$totalCents=0;$hashes=[];$keys=[];$stageTokens=[];
        $stager=new GroupedFinanceStage($this->db,dirname(__DIR__));
        $files=$uploads['item_files']??[];
        foreach($items as $i=>$item){
            if(!is_array($item))throw new InvalidArgumentException('Item '.($i+1).' inválido.');
            $category=trim((string)($item['category']??''));
            $entryCat=$categoryNames[mb_strtolower($category,'UTF-8')]??null;
            if(!$entryCat||!in_array($entryCat['direction'],['both',$direction],true))
                throw new InvalidArgumentException('Item '.($i+1).': selecione uma categoria cadastrada para este tipo de movimentação.');
            $money=trim((string)($item['amount']??''));
            $money=str_replace(',','.',$money);
            if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$money))
                throw new InvalidArgumentException('Item '.($i+1).': valor monetário inválido.');
            $cents=FinanceOperations::cents($money);
            if($cents<=0)throw new InvalidArgumentException('Item '.($i+1).': informe valor positivo.');
            $totalCents+=$cents;
            if($totalCents>99999999999)throw new InvalidArgumentException('Total financeiro acima do limite.');
            $itemDescription=mb_substr(trim((string)($item['description']??'')),0,255);
            if($itemDescription==='')throw new InvalidArgumentException('Item '.($i+1).': informe uma descrição.');
            $contributor=mb_substr(trim((string)($item['contributor']??'')),0,190);
            $number=mb_substr(trim((string)($item['document_number']??'')),0,190);
            $method=mb_substr(trim((string)($item['payment_method']??'')),0,60);
            $memberId=trim((string)($item['member_id']??''))?:null;
            if($memberId!==null){
                if($direction!=='income')throw new InvalidArgumentException('Membros contribuintes só podem ser vinculados a entradas.');
                $q=$this->db->prepare('SELECT id FROM members WHERE church_id=? AND id=?');
                $q->execute([$this->cid,$memberId]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Membro selecionado não pertence a esta igreja.');
            }
            $file=$this->attachment($files,$i);
            $filePresent=(int)$file['error']===UPLOAD_ERR_OK;
            if(!$filePresent && (int)$file['error']!==UPLOAD_ERR_NO_FILE)
                throw new RuntimeException('Item '.($i+1).': arquivo não foi recebido corretamente.');
            $stageToken=trim((string)($item['staged_token']??''));
            if($stageToken!=='' && $filePresent)
                throw new InvalidArgumentException('Item '.($i+1).': documento enviado duas vezes.');
            $staged=null;$sha=null;$key=FinanceFiscalScan::key44($number);
            if($stageToken!==''){
                if(isset($stageTokens[$stageToken]))
                    throw new InvalidArgumentException('O mesmo anexo temporário foi escolhido duas vezes.');
                $stageTokens[$stageToken]=true;
                $staged=$stager->lookup($this->cid,$actor,$stageToken);
                $filePresent=true;
                $sha=$staged['file_sha256'];
            }elseif($filePresent){
                $tmp=(string)($file['tmp_name']??'');
                if($tmp===''||!is_uploaded_file($tmp))
                    throw new RuntimeException('Item '.($i+1).': anexo inválido.');
                $sha=hash_file('sha256',$tmp);
            }
            if($sha){
                if(isset($hashes[$sha]))throw new InvalidArgumentException('O mesmo documento foi selecionado em dois itens.');
                $hashes[$sha]=true;
            }
            if($key){
                if(isset($keys[$key]))throw new InvalidArgumentException('A mesma chave fiscal aparece duas vezes neste lançamento.');
                $keys[$key]=true;
            }
            if($filePresent||$number!==''){
                $dupes=FinanceFiscalScan::duplicates($this->db,$this->cid,[
                    'document_number'=>$number,'amount'=>FinanceOperations::decimal($cents),
                    'date'=>$date,'issuer'=>$contributor,'file_hash'=>$sha,
                ]);
                foreach($dupes as $dupe){
                    if(!empty($dupe['blocking'])||($dupe['reason']??'')==='Mesmo arquivo já anexado')
                        throw new InvalidArgumentException('Item '.($i+1).': cupom ou nota fiscal já registrado nesta igreja.');
                }
            }
            $type=(string)($item['document_type']??'proof');
            if(!in_array($type,['invoice','fiscal_coupon','receipt','proof','other'],true))$type='proof';
            $parsed[]=[
                'category'=>$entryCat['name'],'description'=>$itemDescription,
                'amount'=>FinanceOperations::decimal($cents),'contributor'=>$contributor?:null,
                'member_id'=>$memberId,'document_number'=>$number?:null,
                'payment_method'=>$method?:null,'file'=>$file,'file_present'=>$filePresent,
                'hash'=>$sha,'key'=>$key,'type'=>$type,'staged'=>$staged
            ];
        }
        FinancePeriodGuard::assertOpen($this->db,$this->cid,$date);
        $filesSaved=[];$stagesConsumed=[];
        $this->db->beginTransaction();
        try{
            // A repeated submission must return the existing parent, without
            // creating another group, even after a previous connection timeout.
            $q=$this->db->prepare('SELECT id FROM finance_entries WHERE church_id=? AND request_key=? FOR UPDATE');
            $q->execute([$this->cid,$requestKey]);
            if($already=$q->fetchColumn()){
                $this->db->commit();
                return ['entry_id'=>(string)$already,'already_registered'=>true];
            }
            $entryId=app_uuid();
            $sameCategory=count(array_unique(array_column($parsed,'category')))==1;
            $parentCategory=$sameCategory?$parsed[0]['category']:'Lançamento agrupado';
            $this->db->prepare("INSERT INTO finance_entries
                (id,church_id,account_id,member_id,department_id,direction,category,description,amount,transaction_date,
                 status,source,created_by_user_id,request_key,is_grouped)
                VALUES(?,?,?,?,?,?,?,?,?,?,'posted','grouped',?,?,1)")
                ->execute([$entryId,$this->cid,$accountId,null,$departmentId,$direction,$parentCategory,
                    $description,FinanceOperations::decimal($totalCents),$date,$actor,$requestKey]);
            $uploader=new UploadService(dirname(__DIR__));
            $itemIds=[];
            foreach($parsed as $i=>$item){
                $itemId=app_uuid();$itemIds[]=$itemId;
                $this->db->prepare('INSERT INTO finance_entry_items
                    (id,church_id,entry_id,item_index,category,description,amount,contributor,member_id,document_number,payment_method)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([
                        $itemId,$this->cid,$entryId,$i+1,$item['category'],$item['description'],$item['amount'],
                        $item['contributor'],$item['member_id'],$item['document_number'],$item['payment_method']]);
                if(!$item['file_present'])continue;
                if($item['staged']){
                    $stored=$item['staged'];
                    $stagesConsumed[]=$stored['token_path'];
                }else{
                    $stored=$uploader->financeDocument($item['file'],$this->cid);
                    if(!$stored)throw new RuntimeException('Item '.($i+1).': falha ao guardar documento.');
                    $filesSaved[]=$stored['absolute'];
                }
                $this->db->prepare('INSERT INTO finance_documents
                    (id,church_id,entry_id,entry_item_id,payable_id,document_type,document_number,issued_at,
                     original_name,storage_path,mime_type,file_size,notes,created_by_user_id,file_sha256,fiscal_access_key)
                    VALUES(?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([app_uuid(),$this->cid,$entryId,$itemId,$item['type'],$item['document_number'],$date,
                        $stored['original_name'],$stored['path'],$stored['mime'],$stored['size'],
                        'Documento do item '.($i+1),$actor,$item['hash'],$item['key']]);
            }
            $this->db->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                ->execute([app_uuid(),$actor,$this->cid,'finance_grouped_entry_created',
                    json_encode(['entry_id'=>$entryId,'items'=>$itemIds,'total'=>FinanceOperations::decimal($totalCents)],
                        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            $this->db->commit();
            foreach($stagesConsumed as $metaPath)$stager->consume($metaPath);
            return ['entry_id'=>$entryId,'already_registered'=>false,'item_count'=>count($parsed),
                'total'=>FinanceOperations::decimal($totalCents)];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            foreach($filesSaved as $file)if(is_file($file))@unlink($file);
            throw $e;
        }
    }
}
