<?php
declare(strict_types=1);
require_once __DIR__.'/ReceiptParser.php';
require_once __DIR__.'/UploadService.php';

final class AccountingService
{
    private bool $manager;
    private bool $accountant;
    public function __construct(private PDO $pdo,private Auth $auth,private string $churchId,private string $userId)
    {
        if($churchId===''||$userId===''||($auth->currentChurch()['id']??'')!==$churchId||!$auth->can('accounting_portal'))
            throw new RuntimeException('Acesso negado à contabilidade desta igreja.');
        $role=$auth->churchRole();
        $this->manager=in_array($role,['owner','admin','pastor','treasurer'],true)||$auth->isMasterSupportMode();
        $this->accountant=$role==='accountant';
    }

    public function isManager():bool{return $this->manager;}
    public function isAccountant():bool{return $this->accountant;}
    private function manage():void{if(!$this->manager)throw new RuntimeException('Somente pastor ou tesoureiro pode preparar documentos e movimentações.');}
    private function review():void{if(!$this->accountant)throw new RuntimeException('Somente o perfil Contador pode revisar a prestação de contas.');}
    private function q(string $sql,array $args=[]):PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}

    public static function ensureSchema(PDO $pdo):void
    {
        try{$pdo->query('SELECT id FROM accounting_documents LIMIT 0');$pdo->query('SELECT id FROM accounting_requests LIMIT 0');$pdo->query('SELECT id FROM accounting_periods LIMIT 0');$pdo->query('SELECT id FROM accounting_audit LIMIT 0');return;}
        catch(PDOException $e){
            if(!in_array((string)$e->getCode(),['42S02','HY000'],true)&&!str_contains($e->getMessage(),'1146'))throw $e;
        }
        $sql=file_get_contents(dirname(__DIR__).'/database/041_accounting_portal.sql');
        if(!$sql)throw new RuntimeException('Migração contábil indisponível.');
        foreach(explode(';',$sql) as $stmt)if(trim($stmt)!=='')$pdo->exec($stmt);
    }

    public static function period(mixed $raw):string
    {
        $s=trim((string)$raw);
        if(!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$s))throw new InvalidArgumentException('Competência inválida.');
        return $s;
    }

    public static function date(mixed $raw,bool $required=false):?string
    {
        $s=trim((string)$raw);
        if($s===''){if($required)throw new InvalidArgumentException('Informe a data.');return null;}
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
        if(!$d||$d->format('Y-m-d')!==$s)throw new InvalidArgumentException('Data inválida.');
        return $s;
    }

    private static function assertFinancePeriodOpen(PDO $pdo,string $churchId,string $issued):void
    {
        $file=__DIR__.'/FinancePeriodGuard.php';
        if(!class_exists('FinancePeriodGuard',false)&&is_file($file))require_once $file;
        if(!class_exists('FinancePeriodGuard',false))throw new RuntimeException('Bloqueio de período financeiro indisponível. Não é seguro lançar a despesa.');
        FinancePeriodGuard::assertOpen($pdo,$churchId,$issued);
    }

    private function audit(string $action,?string $docId=null,?string $period=null):void
    {
        $this->q('INSERT INTO accounting_audit(id,church_id,actor_user_id,document_id,reference_period,action) VALUES(?,?,?,?,?,?)',
            [app_uuid(),$this->churchId,$this->userId,$docId,$period,$action]);
    }

    public function categoryNames():array
    {
        $rows=$this->q("SELECT name FROM church_registers WHERE church_id=? AND kind='finance_category' AND active=1 ORDER BY name",
            [$this->churchId])->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map('strval',$rows)));
    }

    public function accounts():array
    {
        return $this->q("SELECT id,name,account_type FROM finance_accounts WHERE church_id=? AND active=1 ORDER BY name",[$this->churchId])->fetchAll(PDO::FETCH_ASSOC);
    }

    private function filename(string $filename):string
    {
        $name=basename(str_replace('\\','/',$filename));
        $name=trim(preg_replace('/[\x00-\x1F\x7F]/','',$name)??'');
        return mb_substr($name?:'documento',0,220);
    }

    private function safeUpload(array $file):array
    {
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Anexe a foto, PDF ou XML do documento.');
        $tmp=(string)($file['tmp_name']??'');
        $size=(int)($file['size']??0);
        if($tmp===''||!is_uploaded_file($tmp)||$size<=0||$size>12*1024*1024)throw new InvalidArgumentException('Arquivo inválido ou maior que 12 MB.');
        $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $name=$this->filename((string)($file['name']??''));
        $xml=in_array($mime,['application/xml','text/xml','text/plain'],true)&&str_ends_with(strtolower($name),'.xml');
        if($xml && !class_exists('DOMDocument'))throw new RuntimeException('A extensão PHP XML precisa estar habilitada para ler notas XML.');
        if($xml){
            $bytes=file_get_contents($tmp);
            if(!is_string($bytes)||strlen($bytes)>8*1024*1024)throw new InvalidArgumentException('XML inválido ou maior que 8 MB.');
            $parsed=ReceiptParser::parseXml($bytes,$this->categoryNames());
            // Store as XML only after successful strict parsing.
            $base=__DIR__.'/../storage/private/finance';
            $dir=$base.'/'.preg_replace('/[^A-Za-z0-9_-]/','',$this->churchId);
            if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar o arquivo privado.');
            if(!is_file($base.'/.htaccess'))file_put_contents($base.'/.htaccess',"Require all denied\nDeny from all\n",LOCK_EX);
            $filename=bin2hex(random_bytes(16)).'.xml';$dest=$dir.'/'.$filename;
            if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Falha ao salvar XML.');
            @chmod($dest,0640);
            return ['path'=>'storage/private/finance/'.basename($dir).'/'.$filename,'absolute'=>$dest,
                'original_name'=>$name,'mime'=>'application/xml','size'=>$size,'sha256'=>hash_file('sha256',$dest),'parsed'=>$parsed];
        }
        if(!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true))throw new InvalidArgumentException('Use JPG, PNG, WEBP, PDF ou XML.');
        if(str_starts_with($mime,'image/')&&!@getimagesize($tmp))throw new InvalidArgumentException('Imagem inválida.');
        if($mime==='application/pdf'){
            $h=fopen($tmp,'rb');$prefix=fread($h,5);fclose($h);
            if($prefix!=='%PDF-')throw new InvalidArgumentException('PDF inválido.');
        }
        $uploader=new UploadService(dirname(__DIR__));
        $saved=$uploader->financeDocument($file,$this->churchId);
        if(!$saved)throw new RuntimeException('Arquivo não recebido.');
        $saved['sha256']=hash_file('sha256',$saved['absolute']);
        $saved['parsed']=null;
        return $saved;
    }

    private function normalizeItems(mixed $value):array
    {
        if(is_string($value)){
            if(strlen($value)>60000)throw new InvalidArgumentException('Itens em excesso.');
            $value=json_decode($value,true);
        }
        if(!is_array($value))return [];
        if(count($value)>200)throw new InvalidArgumentException('Limite de 200 itens por documento.');
        $result=[];
        foreach($value as $v){
            if(!is_array($v))continue;
            $name=mb_substr(trim((string)($v['name']??'')),0,160);
            $amount=ReceiptParser::amount((string)($v['amount']??''));
            $category=mb_substr(trim((string)($v['category']??'')),0,160);
            if($name===''||$amount===null)continue;
            $result[]=['name'=>$name,'amount'=>$amount,'category'=>$category];
        }
        return $result;
    }

    public function upload(array $input,array $file):string
    {
        $this->manage();
        $kind=(string)($input['document_kind']??'fiscal_coupon');
        if(!in_array($kind,['fiscal_coupon','invoice','proof','receipt','bank_statement','payroll','contract','other'],true))
            throw new InvalidArgumentException('Tipo de documento inválido.');
        $period=self::period($input['reference_period']??date('Y-m'));
        $stored=$this->safeUpload($file);
        try{
            $existing=$this->q('SELECT id FROM accounting_documents WHERE church_id=? AND sha256=?',[$this->churchId,$stored['sha256']])->fetchColumn();
            if($existing)throw new InvalidArgumentException('Este documento já foi enviado para a igreja. Abra o arquivo existente para evitar duplicidade.');
            $parsed=$stored['parsed'];
            $raw=trim((string)($input['ocr_text']??''));
            if($parsed===null && $raw!=='')$parsed=ReceiptParser::parse(mb_substr($raw,0,25000),$this->categoryNames());
            $parsed??=[];
            $pick=static function(string $key,string $fallback='')use($input):string{
                $value=trim((string)($input[$key]??''));return $value!==''?$value:$fallback;
            };
            $supplier=mb_substr(trim((string)$pick('supplier_name',(string)($parsed['supplier']??''))),0,190);
            $cnpj=preg_replace('/\D/','',(string)$pick('supplier_cnpj',(string)($parsed['cnpj']??'')))??'';
            if($cnpj!==''&&strlen($cnpj)!==14)throw new InvalidArgumentException('CNPJ deve ter 14 dígitos.');
            $number=mb_substr(trim((string)$pick('document_number',(string)($parsed['number']??''))),0,60);
            $key=preg_replace('/\D/','',(string)$pick('access_key',(string)($parsed['access_key']??'')))??'';
            if($key!==''&&strlen($key)!==44)throw new InvalidArgumentException('Chave de acesso deve ter 44 dígitos.');
            $issued=self::date($pick('issued_on',(string)($parsed['issued_on']??'')));
            $amount=ReceiptParser::amount((string)$pick('total',(string)($parsed['total']??'')));
            $suggested=mb_substr((string)($parsed['category']??''),0,160);
            $confirmed=mb_substr(trim((string)($input['confirmed_category']??'')),0,160);
            $items=$this->normalizeItems(trim((string)($input['items_json']??''))!==''?$input['items_json']:($parsed['items']??[]));
            $approved=!empty($input['confirmed']);
            // Raw OCR is a hint; never silently post an unapproved receipt.
            $ready=$approved && ($kind==='bank_statement'||$kind==='contract'||$kind==='payroll'||$kind==='other'||
                ($issued!==null&&$amount!==null&&$confirmed!==''));
            $status=$ready?'ready':'draft';
            $accountId=trim((string)($input['account_id']??''))?:null;
            if($accountId && !$this->q("SELECT id FROM finance_accounts WHERE church_id=? AND id=? AND active=1",[$this->churchId,$accountId])->fetchColumn())
                throw new InvalidArgumentException('Conta financeira não pertence à igreja.');
            $post=!empty($input['post_expense']);
            if($post && (!$ready||$amount===null||(float)$amount<=0||$issued===null||$confirmed===''||$kind==='bank_statement'||$kind==='payroll'||$kind==='contract'))
                throw new InvalidArgumentException('Para lançar despesa, confira o valor, a data, a categoria e confirme o comprovante.');
            if($post){
                self::assertFinancePeriodOpen($this->pdo,$this->churchId,$issued);
            }

            $id=app_uuid();$entryId=null;$financeDocId=null;
            $this->pdo->beginTransaction();
            try{
                if($post){
                    $entryId=app_uuid();
                    $description=mb_substr('Documento '.($number?:$kind).' — '.($supplier?:'Fornecedor não identificado'),0,255);
                    $q=$this->pdo->prepare("INSERT INTO finance_entries
                        (id,church_id,account_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,document_number,notes)
                        VALUES (?,?,?,'expense',?,?,?,?, 'posted','manual',?,?,?,?)");
                    $q->execute([$entryId,$this->churchId,$accountId,$confirmed,$description,$amount,$issued,$this->userId,$supplier?:null,$number?:null,'Lançado após conferência de documento fiscal '.$id]);
                    $financeDocId=app_uuid();
                    $q=$this->pdo->prepare('INSERT INTO finance_documents
                        (id,church_id,entry_id,payable_id,document_type,document_number,issued_at,original_name,storage_path,mime_type,file_size,notes,created_by_user_id)
                        VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,?)');
                    $q->execute([$financeDocId,$this->churchId,$entryId,
                        $kind==='fiscal_coupon'?'fiscal_coupon':($kind==='invoice'?'invoice':'proof'),$number?:null,$issued,$stored['original_name'],
                        $stored['path'],$stored['mime'],$stored['size'],'Documento contábil '.$id,$this->userId]);
                }
                $q=$this->pdo->prepare('INSERT INTO accounting_documents
                    (id,church_id,finance_entry_id,finance_document_id,document_kind,reference_period,supplier_name,supplier_cnpj,
                    document_number,access_key,issued_on,total,suggested_category,confirmed_category,item_details_json,ocr_excerpt,
                    original_name,storage_path,mime_type,file_size,sha256,status,created_by_user_id)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$id,$this->churchId,$entryId,$financeDocId,$kind,$period,$supplier?:null,$cnpj?:null,
                    $number?:null,$key?:null,$issued,$amount,$suggested?:null,$confirmed?:null,
                    json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    $raw!==''?mb_substr($raw,0,4000):null,$stored['original_name'],$stored['path'],$stored['mime'],$stored['size'],$stored['sha256'],$status,$this->userId]);
                $this->audit($post?'document_posted':'document_uploaded',$id,$period);
                $this->pdo->commit();
            }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
            return $id;
        }catch(Throwable $e){
            @unlink($stored['absolute']);
            throw $e;
        }
    }

    public function confirmDocument(string $id,array $input):void
    {
        $this->manage();
        $doc=$this->document($id);
        if($doc['finance_entry_id'])throw new RuntimeException('Documento já vinculado a lançamento. Faça correções pela tesouraria, preservando o histórico.');
        $name=mb_substr(trim((string)($input['supplier_name']??$doc['supplier_name']??'')),0,190);
        $cnpj=preg_replace('/\D/','',(string)($input['supplier_cnpj']??$doc['supplier_cnpj']??''))??'';
        if($cnpj!==''&&strlen($cnpj)!==14)throw new InvalidArgumentException('CNPJ inválido.');
        $number=mb_substr(trim((string)($input['document_number']??$doc['document_number']??'')),0,60);
        $issued=self::date($input['issued_on']??$doc['issued_on']??'');
        $amount=ReceiptParser::amount((string)($input['total']??$doc['total']??''));
        $category=mb_substr(trim((string)($input['confirmed_category']??$doc['confirmed_category']??'')),0,160);
        $items=$this->normalizeItems($input['items_json']??$doc['item_details_json']??[]);
        $needsFields=in_array($doc['document_kind'],['fiscal_coupon','invoice','receipt','proof'],true);
        if($needsFields&&($issued===null||$amount===null||$category===''))throw new InvalidArgumentException('Preencha data, valor e categoria antes de confirmar.');
        $post=!empty($input['post_expense']);
        if($post&&(!$needsFields||(float)$amount<=0))throw new InvalidArgumentException('Somente despesas comprovadas podem ser lançadas.');
        $accountId=trim((string)($input['account_id']??''))?:null;
        if($accountId&&!$this->q('SELECT 1 FROM finance_accounts WHERE church_id=? AND id=? AND active=1',[$this->churchId,$accountId])->fetchColumn())
            throw new InvalidArgumentException('Conta financeira inválida.');
        if($post){
            self::assertFinancePeriodOpen($this->pdo,$this->churchId,$issued);
        }
        $this->pdo->beginTransaction();
        try{
            $entryId=null;$financeDocId=null;
            if($post){
                $entryId=app_uuid();
                $description=mb_substr('Documento '.($number?:$doc['document_kind']).' — '.($name?:'Fornecedor'),0,255);
                $this->q("INSERT INTO finance_entries
                    (id,church_id,account_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,document_number,notes)
                    VALUES (?,?,?,'expense',?,?,?,?, 'posted','manual',?,?,?,?)",
                    [$entryId,$this->churchId,$accountId,$category,$description,$amount,$issued,$this->userId,$name?:null,$number?:null,'Documento contábil '.$id]);
                $financeDocId=app_uuid();
                $this->q('INSERT INTO finance_documents
                    (id,church_id,entry_id,payable_id,document_type,document_number,issued_at,original_name,storage_path,mime_type,file_size,notes,created_by_user_id)
                    VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,?)',
                    [$financeDocId,$this->churchId,$entryId,
                    $doc['document_kind']==='fiscal_coupon'?'fiscal_coupon':($doc['document_kind']==='invoice'?'invoice':'proof'),$number?:null,$issued,$doc['original_name'],
                    $doc['storage_path'],$doc['mime_type'],$doc['file_size'],'Documento contábil '.$id,$this->userId]);
            }
            $this->q("UPDATE accounting_documents SET supplier_name=?,supplier_cnpj=?,document_number=?,issued_on=?,total=?,
                confirmed_category=?,item_details_json=?,status='ready',review_note=NULL,reviewed_by_user_id=NULL,reviewed_at=NULL,
                finance_entry_id=?,finance_document_id=? WHERE church_id=? AND id=?",
                [$name?:null,$cnpj?:null,$number?:null,$issued,$amount,$category?:null,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    $entryId,$financeDocId,$this->churchId,$id]);
            $this->audit($post?'confirm_and_post':'confirm_document',$id,$doc['reference_period']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function documents(string $period,int $limit=500):array
    {
        $period=self::period($period);$limit=max(1,min(1000,$limit));
        return $this->q('SELECT d.id,d.finance_entry_id,d.document_kind,d.reference_period,d.supplier_name,d.supplier_cnpj,
            d.document_number,d.access_key,d.issued_on,d.total,d.confirmed_category,d.suggested_category,d.item_details_json,
            d.original_name,d.mime_type,d.file_size,d.status,d.review_note,d.created_at,u.full_name uploaded_by
            FROM accounting_documents d LEFT JOIN users u ON u.id=d.created_by_user_id
            WHERE d.church_id=? AND d.reference_period=? ORDER BY d.created_at DESC LIMIT '.$limit,
            [$this->churchId,$period])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function document(string $id):array
    {
        $q=$this->q('SELECT * FROM accounting_documents WHERE church_id=? AND id=? LIMIT 1',[$this->churchId,$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Documento não encontrado nesta igreja.');
        return $row;
    }

    public function reviewDocument(string $id,string $status,string $note=''):void
    {
        $this->review();
        if(!in_array($status,['reviewed','needs_fix'],true))throw new InvalidArgumentException('Revisão inválida.');
        $note=mb_substr(trim($note),0,2000);
        if($status==='needs_fix'&&$note==='')throw new InvalidArgumentException('Explique o que precisa ser corrigido.');
        $doc=$this->document($id);
        if($status==='reviewed' && !in_array($doc['status'],['ready','reviewed'],true))
            throw new InvalidArgumentException('O documento ainda não foi conferido pelo pastor ou tesoureiro.');
        $this->pdo->beginTransaction();
        try{
            $this->q('UPDATE accounting_documents SET status=?,review_note=?,reviewed_by_user_id=?,reviewed_at=NOW() WHERE church_id=? AND id=?',
                [$status,$note?:null,$this->userId,$this->churchId,$id]);
            $this->audit($status,$id,$doc['reference_period']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function entries(string $period):array
    {
        $period=self::period($period);
        $start=$period.'-01';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        return $this->q('SELECT e.id,e.transaction_date,e.direction,e.category,e.description,e.amount,e.status,
            e.document_number,e.payment_method,e.counterparty,a.name account_name
            FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id
            WHERE e.church_id=? AND e.transaction_date>=? AND e.transaction_date<? ORDER BY e.transaction_date,e.id',
            [$this->churchId,$start,$end])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function legacyDocuments(string $period):array
    {
        $start=$period.'-01';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        return $this->q('SELECT d.id,d.entry_id,d.document_type,d.document_number,d.issued_at,
            d.original_name,d.mime_type,d.file_size,d.storage_path
            FROM finance_documents d LEFT JOIN finance_entries e ON e.id=d.entry_id AND e.church_id=d.church_id
            WHERE d.church_id=? AND ((e.transaction_date>=? AND e.transaction_date<?) OR
                (d.entry_id IS NULL AND d.issued_at>=? AND d.issued_at<?))
            ORDER BY d.issued_at,d.id',[$this->churchId,$start,$end,$start,$end])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function legacyDocument(string $id):array
    {
        $row=$this->q('SELECT * FROM finance_documents WHERE church_id=? AND id=?',[$this->churchId,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Comprovante não encontrado.');
        return $row;
    }

    public function requests(string $period):array
    {
        return $this->q('SELECT r.*,u.full_name requested_by FROM accounting_requests r LEFT JOIN users u ON u.id=r.requested_by_user_id
            WHERE r.church_id=? AND r.reference_period=? ORDER BY r.status ASC,r.created_at DESC',
            [$this->churchId,self::period($period)])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function requestDocument(string $period,string $title,string $details):void
    {
        $this->review();$period=self::period($period);
        $title=mb_substr(trim($title),0,190);$details=mb_substr(trim($details),0,2000);
        if($title==='')throw new InvalidArgumentException('Descreva o documento faltante.');
        $this->pdo->beginTransaction();
        try{
            $this->q('INSERT INTO accounting_requests(id,church_id,reference_period,title,details,requested_by_user_id)
                VALUES(?,?,?,?,?,?)',[app_uuid(),$this->churchId,$period,$title,$details,$this->userId]);
            $this->audit('request_document',null,$period);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function respondRequest(string $requestId,string $docId):void
    {
        $this->manage();$doc=$this->document($docId);
        $this->pdo->beginTransaction();
        try{
            $req=$this->q("SELECT id,reference_period,status FROM accounting_requests WHERE church_id=? AND id=? FOR UPDATE",
                [$this->churchId,$requestId])->fetch(PDO::FETCH_ASSOC);
            if(!$req||$req['status']!=='open'||$req['reference_period']!==$doc['reference_period'])
                throw new InvalidArgumentException('Solicitação ou competência inválida.');
            $this->q("UPDATE accounting_requests SET status='responded',responded_document_id=?,resolved_at=NOW() WHERE church_id=? AND id=?",
                [$docId,$this->churchId,$requestId]);
            $this->audit('answer_request',$docId,$req['reference_period']);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function summary(string $period):array
    {
        $period=self::period($period);$start=$period.'-01';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $fin=$this->q("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
            COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense,
            COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' AND
              NOT EXISTS(SELECT 1 FROM finance_documents d WHERE d.church_id=finance_entries.church_id AND d.entry_id=finance_entries.id)
              AND NOT EXISTS(SELECT 1 FROM accounting_documents a WHERE a.church_id=finance_entries.church_id AND a.finance_entry_id=finance_entries.id)
            THEN 1 ELSE 0 END),0) expense_without_proof
            FROM finance_entries WHERE church_id=? AND transaction_date>=? AND transaction_date<?",
            [$this->churchId,$start,$end])->fetch(PDO::FETCH_ASSOC);
        $count=$this->q('SELECT COUNT(*) documents,
            COALESCE(SUM(status IN (\'draft\',\'needs_fix\')),0) pending_review,
            COALESCE(SUM(document_kind=\'bank_statement\'),0) bank_statements
            FROM accounting_documents WHERE church_id=? AND reference_period=?',
            [$this->churchId,$period])->fetch(PDO::FETCH_ASSOC);
        $open=(int)$this->q("SELECT COUNT(*) FROM accounting_requests WHERE church_id=? AND reference_period=? AND status='open'",
            [$this->churchId,$period])->fetchColumn();
        $periodState=$this->q('SELECT status,submitted_at,reviewed_at,notes FROM accounting_periods WHERE church_id=? AND reference_period=?',
            [$this->churchId,$period])->fetch(PDO::FETCH_ASSOC);
        return array_merge($fin?:[],$count?:[],['open_requests'=>$open,'period_status'=>$periodState['status']??'open','period_info'=>$periodState?:null]);
    }

    public function periodStatus(string $period,string $action,string $note=''):void
    {
        $period=self::period($period);$note=mb_substr(trim($note),0,1800);
        if($action==='submit')$this->manage();
        elseif($action==='review')$this->review();
        else throw new InvalidArgumentException('Situação inválida.');
        $this->pdo->beginTransaction();
        try{
            $this->q("INSERT INTO accounting_periods(id,church_id,reference_period,status)
                VALUES(?,?,?,'open') ON DUPLICATE KEY UPDATE id=id",[app_uuid(),$this->churchId,$period]);
            $q=$this->q('SELECT status FROM accounting_periods WHERE church_id=? AND reference_period=? FOR UPDATE',[$this->churchId,$period]);
            $current=(string)$q->fetchColumn();
            if($action==='review'){
                if($current!=='submitted')throw new RuntimeException('O pastor ou tesoureiro precisa enviar a competência primeiro.');
                $counts=$this->summary($period);
                if((int)$counts['open_requests']>0)throw new RuntimeException('Há documentos solicitados ainda pendentes.');
                if((int)$counts['pending_review']>0)throw new RuntimeException('Há documentos aguardando correção ou conferência.');
                if((int)$counts['expense_without_proof']>0)throw new RuntimeException('Há despesas sem comprovante anexado.');
                $this->q("UPDATE accounting_periods SET status='reviewed',reviewed_by_user_id=?,reviewed_at=NOW(),notes=?
                    WHERE church_id=? AND reference_period=?",[$this->userId,$note?:null,$this->churchId,$period]);
            }else{
                $this->q("UPDATE accounting_periods SET status='submitted',submitted_by_user_id=?,submitted_at=NOW(),
                    reviewed_by_user_id=NULL,reviewed_at=NULL,notes=? WHERE church_id=? AND reference_period=?",
                    [$this->userId,$note?:null,$this->churchId,$period]);
            }
            $this->audit('period_'.$action,null,$period);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function storedFile(string $path):string
    {
        // No arbitrary client path. Caller obtains path only from tenant-scoped DB row.
        $prefix='storage/private/finance/'.preg_replace('/[^A-Za-z0-9_-]/','',$this->churchId).'/';
        if(!str_starts_with($path,$prefix)||str_contains($path,'..')||str_contains($path,'\\')||str_contains($path,"\0"))
            throw new RuntimeException('Local de arquivo não autorizado.');
        $root=realpath(__DIR__.'/../'.$prefix);
        $file=realpath(__DIR__.'/../'.$path);
        if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))throw new RuntimeException('Arquivo não encontrado.');
        return $file;
    }
}
