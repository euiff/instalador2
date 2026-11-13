<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';
require_once __DIR__.'/lib/PortalService.php';
require_once __DIR__.'/lib/FinanceOperations.php';
require_once __DIR__.'/lib/FinancePeriodGuard.php';
require_once __DIR__.'/lib/FinanceFiscalScan.php';
require_once __DIR__.'/lib/FinanceManualAttachments.php';
require_once __DIR__.'/lib/GeneralTreasuryAccountScope.php';
require_once __DIR__.'/lib/ExpenseReceiptService.php';
require_once __DIR__.'/lib/DepartmentTreasuryService.php';
require_once __DIR__.'/lib/PendingFinanceReceipt.php';

// Arquivos selecionados no Android são enviados por formulário AJAX.
 // Esta tela retorna JSON nas ações de lançamento/anexo, sem seguir um
 // redirecionamento HTML que poderia produzir falso erro HTTP 404.
$financeAjaxRequest=(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest')
    &&in_array((string)($_POST['action']??''),['entry','attach_document','receipt_sign_pending','payable'],true);
function finance_ajax_confirm(array $payload,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$uploader=new UploadService(__DIR__);
$financeOps=new FinanceOperations($pdo,$auth,$cid);
$financeRegistry=new RegistryService($pdo,$auth,$cid);
ExpenseReceiptService::ensureSchema($pdo);
// Recibos pendentes devem ser provisionados pela atualização, não por DDL em
// cada requisição financeira. Uma tabela indisponível nunca bloqueia o livro-caixa.
$pendingFinanceReceipts=new PendingFinanceReceipt($pdo,$cid,(string)$auth->requireLogin()['id']);
$pendingReceiptReady=true;
try {
    $pdo->query('SELECT id FROM finance_receipt_drafts LIMIT 0');
} catch(Throwable $schemaError) {
    $pendingReceiptReady=false;
    error_log('Igreja Master pending receipts schema unavailable: '.$schemaError->getMessage());
}

// Detect oversized multipart payload before CSRF validation can fail silently.
if(($_SERVER['REQUEST_METHOD']??'')==='POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0){
    $limit=UploadService::financeLimits();
    if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest')
        finance_ajax_confirm(['ok'=>false,'message'=>'A hospedagem não recebeu o arquivo. Limite post_max_size: '.$limit['post_max_size'].'.'],413);
    flash('error','O servidor não recebeu o formulário. Verifique o tamanho do arquivo e o limite post_max_size ('.$limit['post_max_size'].') da hospedagem. Abra o diagnóstico de uploads na Tesouraria.');
    redirect('/finance.php');
}
$error=flash('error');
$success=flash('success');
$from=(string)($_GET['from']??date('Y-m-01'));
$to=(string)($_GET['to']??date('Y-m-d'));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-d');

function finance_money_input(mixed $value): float {
    $s=preg_replace('/[^0-9,.\-]/','',(string)$value)??'0';
    if(str_contains($s,','))$s=str_replace('.','',$s);
    return (float)str_replace(',','.',$s);
}
function finance_account_for(PDO $pdo,string $cid,?string $id): ?array {
    if(!$id)return null;
    $q=$pdo->prepare('SELECT * FROM finance_accounts WHERE id=? AND church_id=? AND active=1 LIMIT 1');
    $q->execute([$id,$cid]);
    return $q->fetch()?:null;
}
function finance_document_type(string $value): string {
    $allowed=['fiscal_coupon','invoice','receipt','proof','contract','other'];
    return in_array($value,$allowed,true)?$value:'proof';
}
function finance_attach_document(
    PDO $pdo,
    UploadService $uploader,
    array $file,
    string $cid,
    ?string $entryId,
    ?string $payableId,
    string $type,
    ?string $number,
    ?string $issuedAt,
    ?string $notes,
    ?string $userId
): ?string {
    $sha=null;
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK && is_file((string)($file['tmp_name']??'')))
        $sha=hash_file('sha256',(string)$file['tmp_name']);
    $key=FinanceFiscalScan::key44((string)$number);
    $stored=$uploader->financeDocument($file,$cid);
    if(!$stored)return null;
    $id=app_uuid();
    try{
        $q=$pdo->prepare('INSERT INTO finance_documents(id,church_id,entry_id,payable_id,document_type,document_number,issued_at,original_name,storage_path,mime_type,file_size,notes,created_by_user_id,file_sha256,fiscal_access_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([
            $id,$cid,$entryId,$payableId,finance_document_type($type),$number?:null,$issuedAt?:null,
            $stored['original_name'],$stored['path'],$stored['mime'],$stored['size'],$notes?:null,$userId,
            $sha?:null,$key
        ]);
    }catch(Throwable $failure){
        // Do not keep orphaned physical files if the record is rejected by
        // the unique fiscal key or another database constraint.
        if(!empty($stored['absolute'])&&is_file($stored['absolute']))@unlink($stored['absolute']);
        throw $failure;
    }
    return $id;
}

function finance_person(PDO $pdo,string $cid,array $input): ?string {
    $id=trim((string)($input['member_id']??''))?:null;
    if($id){
        $q=$pdo->prepare('SELECT id FROM members WHERE church_id=? AND id=?');$q->execute([$cid,$id]);
        if(!$q->fetchColumn())throw new RuntimeException('Membro não encontrado nesta igreja.');
    }
    return $id;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='reverse_entry'){
            $id=trim((string)($_POST['id']??''));
            $reason=trim((string)($_POST['reason']??''));
            if($id===''||$reason==='')throw new RuntimeException('Informe o motivo do estorno.');

            $q=$pdo->prepare("SELECT id,status,transaction_date FROM finance_entries WHERE id=? AND church_id=? LIMIT 1");
            $q->execute([$id,$cid]);$entry=$q->fetch();
            if(!$entry)throw new RuntimeException('Lançamento não encontrado.');
            if($entry['status']!=='posted')throw new RuntimeException('Este lançamento já não está ativo.');
            FinancePeriodGuard::assertOpen($pdo,$cid,$entry['transaction_date']);

            $q=$pdo->prepare("UPDATE finance_entries SET status='reversed',reversed_at=NOW(),reversal_reason=?,reversed_by_user_id=? WHERE id=? AND church_id=? AND status='posted'");
            $q->execute([$reason,$_SESSION['user_id']??null,$id,$cid]);
            if($q->rowCount()!==1)throw new RuntimeException('Não foi possível estornar o lançamento.');

            flash('success','Lançamento estornado sem apagar o histórico.');
            redirect('/finance.php');
        }

        if($action==='account'){
            $name=trim((string)($_POST['name']??''));
            $type=(string)($_POST['account_type']??'cash');
            $opening=finance_money_input($_POST['opening_balance']??0);
            if($name==='')throw new RuntimeException('Informe o nome da conta.');
            if(!in_array($type,['cash','bank'],true))throw new RuntimeException('Tipo de conta inválido.');
            $pdo->prepare('INSERT INTO finance_accounts(id,church_id,name,account_type,opening_balance,active) VALUES(?,?,?,?,?,1)')
                ->execute([app_uuid(),$cid,$name,$type,$opening]);
            flash('success','Conta financeira criada.');
            redirect('/finance.php');
        }

        if($action==='account_status'){
            $id=trim((string)($_POST['id']??''));
            $enable=(string)($_POST['enable']??'0')==='1';
            if($id==='')throw new InvalidArgumentException('Selecione a conta.');
            $row=$pdo->prepare('SELECT id,name,department_id,active FROM finance_accounts WHERE church_id=? AND id=? LIMIT 1');
            $row->execute([$cid,$id]);$account=$row->fetch();
            if(!$account)throw new RuntimeException('Conta não encontrada na igreja.');
            if(!empty($account['department_id'])&&!$auth->can('department_treasury'))
                throw new RuntimeException('Você não pode administrar contas deste departamento.');
            // Exclusão lógica: vínculos de lançamentos, recibos e conciliações permanecem íntegros.
            $pdo->prepare('UPDATE finance_accounts SET active=? WHERE church_id=? AND id=?')
                ->execute([$enable?1:0,$cid,$id]);
            flash('success',$enable?'Conta reativada.':'Conta retirada das opções de lançamento. Histórico preservado.');
            redirect('/finance.php');
        }

        if(in_array($action,['receipt_create_pending','receipt_sign_pending','receipt_approve_pending','receipt_cancel_pending'],true)&&!$pendingReceiptReady)
            throw new RuntimeException('Recibos provisórios aguardam instalação da atualização do banco de dados. Os lançamentos normais continuam disponíveis.');

        if($action==='receipt_create_pending'){
            $payer=trim((string)($_POST['payer_name']??''));
            $amount=finance_money_input($_POST['amount']??0);
            $date=(string)($_POST['received_at']??date('Y-m-d'));
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            GeneralTreasuryAccountScope::assertGeneral($pdo,$cid,$accountId);
            if($accountId&&finance_account_for($pdo,$cid,$accountId)===null)
                throw new RuntimeException('Selecione uma conta ativa.');
            $memberId=finance_person($pdo,$cid,$_POST);
            $refs=$financeOps->references($_POST);
            $id=$pendingFinanceReceipts->create($_POST,$memberId,$accountId,$refs,number_format($amount,2,'.',''),$date);
            flash('success','Recibo criado sem lançamento no caixa. Imprima ou solicite assinatura na tela.');
            redirect('/finance.php?pending_receipt='.rawurlencode($id).'#pending-receipts');
        }
        if($action==='receipt_sign_pending'){
            $id=(string)($_POST['id']??'');
            $method=(string)($_POST['signature_method']??'paper');
            $pendingFinanceReceipts->sign($id,$_FILES['signed_file']??[],$uploader,$method);
            flash('success','Assinatura recebida. O valor ainda NÃO foi lançado. Confira e confirme.');
            if($financeAjaxRequest)finance_ajax_confirm([
                'ok'=>true,'document_attached'=>true,'view_url'=>'/finance.php#pending-receipts'
            ]);
            redirect('/finance.php#pending-receipts');
        }
        if($action==='receipt_approve_pending'){
            $id=(string)($_POST['id']??'');
            $receiptId=$pendingFinanceReceipts->approve($id);
            flash('success','Recibo assinado conferido e lançado uma única vez no caixa.');
            redirect('/finance.php?receipt='.rawurlencode($receiptId).'#pending-receipts');
        }
        if($action==='receipt_cancel_pending'){
            $pendingFinanceReceipts->cancel((string)($_POST['id']??''));
            flash('success','Recibo provisório cancelado; nenhum valor foi lançado.');
            redirect('/finance.php#pending-receipts');
        }

        if($action==='entry'){
            // Evita contabilizar duas vezes se o celular repetir o envio após
            // uma resposta HTTP 404, queda de conexão ou navegação interrompida.
            $requestKey=trim((string)($_POST['request_key']??''));
            if(!preg_match('/^[a-f0-9]{64}$/D',$requestKey))
                throw new InvalidArgumentException('Reabra o formulário para registrar com segurança.');
            $existing=$pdo->prepare('SELECT id FROM finance_entries WHERE church_id=? AND request_key=? LIMIT 1');
            $existing->execute([$cid,$requestKey]);
            if($entryAlready=(string)($existing->fetchColumn()?:'')){
                $q=$pdo->prepare('SELECT COUNT(*) FROM finance_documents WHERE church_id=? AND entry_id=?');
                $q->execute([$cid,$entryAlready]);
                if($financeAjaxRequest)finance_ajax_confirm([
                    'ok'=>true,'already_registered'=>true,'entry_id'=>$entryAlready,
                    'document_attached'=>(int)$q->fetchColumn()>0,
                    'message'=>'Este lançamento já estava registrado. Não foi duplicado.',
                    'view_url'=>'/finance-entry-edit.php?id='.rawurlencode($entryAlready)
                ]);
                flash('success','Este lançamento já estava registrado. Nenhum valor foi duplicado.');
                redirect('/finance-entry-edit.php?id='.rawurlencode($entryAlready));
            }
            if((string)($_POST['document_expected']??'')==='1'
                &&(int)($_FILES['document_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
                throw new RuntimeException('O comprovante selecionado não chegou ao servidor. Nada foi lançado; selecione o arquivo novamente.');
            $direction=(string)($_POST['direction']??'income');
            if(!in_array($direction,['income','expense'],true))throw new RuntimeException('Tipo inválido.');
            $amount=finance_money_input($_POST['amount']??0);
            $date=(string)($_POST['transaction_date']??date('Y-m-d'));
            $desc=trim((string)($_POST['description']??''));
            $category=trim((string)($_POST['category']??''));
            // Validação também no servidor, impedindo texto livre ou categorias
            // pertencentes a outra igreja (a lista HTML não é um controle de acesso).
            $validCategory=false;
            foreach($financeRegistry->all('finance_category') as $register){
                $registeredName=trim((string)($register['name']??''));
                $registeredDirection=(string)($register['data']['direction']??'both');
                if($category!=='' && mb_strtolower($registeredName,'UTF-8')===mb_strtolower($category,'UTF-8')
                    && in_array($registeredDirection,['both',$direction],true)){
                    $category=$registeredName;
                    $validCategory=true;
                    break;
                }
            }
            if(!$validCategory)throw new InvalidArgumentException('Selecione um lançamento contábil cadastrado nesta igreja.');
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            $counterparty=trim((string)($_POST['counterparty']??''))?:null;
            $paymentMethod=trim((string)($_POST['payment_method']??''))?:null;
            $documentNumber=trim((string)($_POST['document_number']??''))?:null;
            $notes=trim((string)($_POST['notes']??''))?:null;
            $attachment=$_FILES['document_file']??[];
            $fileHash=null;
            if((int)($attachment['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK
                && is_file((string)($attachment['tmp_name']??'')))
                $fileHash=hash_file('sha256',(string)$attachment['tmp_name']);
            $duplicates=FinanceFiscalScan::duplicates($pdo,$cid,[
                'document_number'=>$documentNumber,'amount'=>(string)$amount,
                'date'=>$date,'issuer'=>(string)$counterparty,'file_hash'=>$fileHash
            ]);
            foreach($duplicates as $dupe){
                if(!empty($dupe['blocking'])||($dupe['reason']??'')==='Mesmo arquivo já anexado')
                    throw new InvalidArgumentException('Documento fiscal já registrado nesta igreja. Consulte o lançamento anterior antes de enviar novamente.');
            }
            // Arquivos extras NÃO são lidos pela IA. São apenas provas
            // vinculadas a esta movimentação, sem novos valores ou categorias.
            $manualAttachmentService=new FinanceManualAttachments($pdo,__DIR__);
            $extraPrepared=$manualAttachmentService->prepare(
                $cid,(string)$auth->requireLogin()['id'],
                (string)($_POST['extra_document_tokens']??''),$attachment
            );
            $extraType=(string)($_POST['extra_document_type']??'proof');
            // Regras da lista de sugestões: dízimo identificado exige vínculo de membro;
            // o dízimo anônimo permanece permitido e não deve ser associado a terceiros.
            $identifiedTithe=($direction==='income' && preg_match('/d[ií]zimo/iu',$category)
                && (string)($_POST['tithe_identified']??'')==='1');
            if($identifiedTithe && trim((string)($_POST['member_id']??''))===''){
                throw new InvalidArgumentException('Dízimo identificado: selecione o membro contribuinte antes de registrar.');
            }
            $usesBankReference=$direction==='income' && preg_match('/pix|transfer[êe]ncia/iu',$paymentMethod);
            if($usesBankReference && $documentNumber===null){
                throw new InvalidArgumentException('Informe o ID Pix, autenticação ou referência bancária da entrada.');
            }
            // A conta define o caixa de destino. Caixas departamentais utilizam
            // o mesmo serviço e as permissões da Tesouraria dos Departamentos.
            $selectedAccount=finance_account_for($pdo,$cid,$accountId);
            if($accountId!==null&&$selectedAccount===null)
                throw new InvalidArgumentException('Conta financeira inexistente ou inativa nesta igreja.');
            if(!empty($selectedAccount['department_id'])){
                if(!$auth->can('department_treasury'))
                    throw new RuntimeException('Seu perfil não pode movimentar caixas departamentais.');
                $departmentTreasury=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$auth->requireLogin()['id']);
                $departmentId=(string)$selectedAccount['department_id'];
                $entryId=$departmentTreasury->createEntry($departmentId,$_POST,$_FILES['document_file']??[],$extraPrepared);
                flash('success','Movimentação registrada no caixa do departamento e consolidada na igreja, sem duplicidade.');
                if($financeAjaxRequest){
                    $q=$pdo->prepare('SELECT COUNT(*) FROM finance_documents WHERE church_id=? AND entry_id=?');
                    $q->execute([$cid,$entryId]);
                    finance_ajax_confirm([
                        'ok'=>true,'entry_id'=>$entryId,'document_attached'=>(int)$q->fetchColumn()>0,
                        'message'=>'Movimentação registrada no departamento.',
                        'view_url'=>'/finance-entry-edit.php?id='.rawurlencode($entryId)
                    ]);
                }
                if($direction==='expense'&&in_array((string)($_POST['proof_method']??'upload'),['receipt_screen','receipt_print'],true))
                    redirect('/expense-receipt.php?entry='.rawurlencode($entryId));
                redirect('/finance.php');
            }
            GeneralTreasuryAccountScope::assertGeneral($pdo,$cid,$accountId);
            if($amount<=0||$desc===''||$category==='')throw new RuntimeException('Informe valor, categoria e descrição.');

            $proofMethod=(string)($_POST['proof_method']??'upload');
            $generateReceipt=$direction==='expense' && in_array($proofMethod,['receipt_print','receipt_screen'],true);
            if($generateReceipt){
                ExpenseReceiptService::validateInput($_POST);
                if((int)($_FILES['document_file']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK)
                    throw new InvalidArgumentException('Escolha anexar documento OU gerar recibo, não as duas opções.');
            }
            $financialRefs=$financeOps->references($_POST);
            $memberId=finance_person($pdo,$cid,$_POST);
            if($memberId&&$direction!=='income')throw new RuntimeException('O vínculo pessoal é usado somente para contribuições de entrada.');
            PortalService::date($date);
            FinancePeriodGuard::assertOpen($pdo,$cid,$date);
            $departmentId=null; // General cashier postings never inherit the donor's department.
            $entryId=app_uuid();
            $pdo->beginTransaction();
            try{
            $q=$pdo->prepare("INSERT INTO finance_entries(id,church_id,account_id,member_id,department_id,cost_center_id,contact_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,payment_method,document_number,notes,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'posted','manual',?,?,?,?,?,?)");
            $q->execute([$entryId,$cid,$accountId,$memberId,$departmentId,$financialRefs['cost_center_id'],$financialRefs['contact_id'],$direction,$category,$desc,$amount,$date,$_SESSION['user_id']??null,$counterparty,$paymentMethod,$documentNumber,$notes,$requestKey]);

            if($generateReceipt){
                $service=new ExpenseReceiptService($pdo,$auth,$cid,(string)($auth->requireLogin()['id']));
                $service->create($entryId,$_POST);
            }else{
                finance_attach_document(
                    $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,null,
                    (string)($_POST['document_type']??'proof'),$documentNumber,$date,$notes,$_SESSION['user_id']??null
                );
            }
            $manualAttachmentService->insert($cid,$entryId,(string)$auth->requireLogin()['id'],$extraPrepared,$extraType);
            $pdo->commit();
            $manualAttachmentService->consume($extraPrepared);
            }catch(Throwable $failure){if($pdo->inTransaction())$pdo->rollBack();throw $failure;}
            if($financeAjaxRequest){
                $q=$pdo->prepare('SELECT COUNT(*) FROM finance_documents WHERE church_id=? AND entry_id=?');
                $q->execute([$cid,$entryId]);
                finance_ajax_confirm([
                    'ok'=>true,'entry_id'=>$entryId,'document_attached'=>(int)$q->fetchColumn()>0,
                    'message'=>($direction==='income'?'Entrada':'Saída').' registrada com sucesso.',
                    'view_url'=>'/finance-entry-edit.php?id='.rawurlencode($entryId)
                ]);
            }
            flash('success',($direction==='income'?'Entrada':'Saída').' registrada no livro financeiro.'.
                ($generateReceipt?' Recibo vinculado à despesa.':''));
            if($generateReceipt)redirect('/expense-receipt.php?entry='.rawurlencode($entryId));
            redirect('/finance.php');
        }

        if($action==='edit_entry_details'){
            $id=trim((string)($_POST['entry_id']??''));
            $description=trim((string)($_POST['description']??''));
            $category=trim((string)($_POST['category']??''));
            if($id===''||$description===''||$category===''||mb_strlen($description)>255||mb_strlen($category)>160)
                throw new InvalidArgumentException('Preencha categoria e descrição válidas.');
            $q=$pdo->prepare('SELECT transaction_date,status FROM finance_entries WHERE id=? AND church_id=? LIMIT 1');
            $q->execute([$id,$cid]);$entry=$q->fetch();
            if(!$entry||$entry['status']!=='posted')throw new RuntimeException('Movimentação não encontrada ou estornada.');
            FinancePeriodGuard::assertOpen($pdo,$cid,(string)$entry['transaction_date']);
            $pdo->prepare("UPDATE finance_entries SET category=?,description=? WHERE church_id=? AND id=? AND status='posted'")
                ->execute([$category,$description,$cid,$id]);
            $pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                ->execute([app_uuid(),$_SESSION['user_id']??null,$cid,'finance_entry_details_edited',
                    json_encode(['entry_id'=>$id],JSON_THROW_ON_ERROR)]);
            flash('success','Categoria e descrição salvas sem alterar valores nem saldos.');
            redirect('/finance.php');
        }

        if($action==='attach_document'){
            $entryId=trim((string)($_POST['entry_id']??''))?:null;
            $payableId=trim((string)($_POST['payable_id']??''))?:null;
            if(!$entryId&&!$payableId)throw new RuntimeException('Selecione o lançamento ou a conta a pagar.');
            if($entryId){
                $q=$pdo->prepare('SELECT 1 FROM finance_entries WHERE id=? AND church_id=?');$q->execute([$entryId,$cid]);
                if(!$q->fetchColumn())throw new RuntimeException('Lançamento inválido.');
            }
            if($payableId){
                $q=$pdo->prepare('SELECT 1 FROM finance_payables WHERE id=? AND church_id=?');$q->execute([$payableId,$cid]);
                if(!$q->fetchColumn())throw new RuntimeException('Conta a pagar inválida.');
            }
            $docId=finance_attach_document(
                $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,$payableId,
                (string)($_POST['document_type']??'proof'),
                trim((string)($_POST['document_number']??''))?:null,
                trim((string)($_POST['issued_at']??''))?:null,
                trim((string)($_POST['notes']??''))?:null,
                $_SESSION['user_id']??null
            );
            if(!$docId)throw new RuntimeException('Selecione um arquivo para anexar.');
            if($financeAjaxRequest){
                finance_ajax_confirm([
                    'ok'=>true,'document_id'=>$docId,'entry_id'=>$entryId,
                    'message'=>'Documento anexado e confirmado no banco de dados.',
                    'view_url'=>$entryId?'/finance-entry-edit.php?id='.rawurlencode($entryId):'/finance.php'
                ]);
            }
            flash('success','Documento arquivado com segurança.');
            redirect('/finance.php');
        }

        // A antiga ação emitia e contabilizava imediatamente: proibida neste fluxo.
        if($action==='receipt')throw new InvalidArgumentException('Atualize o formulário e crie um recibo pendente para assinatura.');

        if($action==='payable'){
            $supplier=trim((string)($_POST['supplier']??''));
            $desc=trim((string)($_POST['description']??''));
            $amount=finance_money_input($_POST['amount']??0);
            $due=(string)($_POST['due_date']??'');
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            $documentNumber=trim((string)($_POST['document_number']??''))?:null;
            $notes=trim((string)($_POST['notes']??''))?:null;
            GeneralTreasuryAccountScope::assertGeneral($pdo,$cid,$accountId);
            if($supplier===''||$desc===''||$amount<=0||$due==='')throw new RuntimeException('Preencha fornecedor, descrição, valor e vencimento.');

            $payableId=app_uuid();
            $pdo->beginTransaction();
            try{
                $pdo->prepare("INSERT INTO finance_payables(id,church_id,account_id,supplier,description,amount,due_date,status,document_number,notes) VALUES(?,?,?,?,?,?,?,'open',?,?)")
                    ->execute([$payableId,$cid,$accountId,$supplier,$desc,$amount,$due,$documentNumber,$notes]);
                $documentId=finance_attach_document(
                    $pdo,$uploader,$_FILES['document_file']??[],$cid,null,$payableId,
                    (string)($_POST['document_type']??'invoice'),$documentNumber,null,$notes,$_SESSION['user_id']??null
                );
                if((string)($_POST['document_expected']??'')==='1'&&!$documentId)
                    throw new RuntimeException('O comprovante selecionado não chegou ao servidor.');
                $pdo->commit();
            }catch(Throwable $failure){if($pdo->inTransaction())$pdo->rollBack();throw $failure;}

            flash('success','Conta a pagar cadastrada.');
            if($financeAjaxRequest)finance_ajax_confirm([
                'ok'=>true,'document_attached'=>$documentId!==null,'view_url'=>'/finance.php'
            ]);
            redirect('/finance.php');
        }

        if($action==='mark_paid'){
            $id=(string)($_POST['id']??'');
            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare("SELECT * FROM finance_payables WHERE id=? AND church_id=? FOR UPDATE");$q->execute([$id,$cid]);$p=$q->fetch();
                if(!$p)throw new RuntimeException('Conta a pagar não encontrada.');
                $paidDate=(string)($_POST['paid_at']??date('Y-m-d'));
                FinancePeriodGuard::assertOpen($pdo,$cid,$paidDate);
                $oldEntry=$pdo->prepare('SELECT transaction_date FROM finance_entries WHERE church_id=? AND payable_id=?');$oldEntry->execute([$cid,$id]);$oldDate=$oldEntry->fetchColumn();
                if($oldDate)FinancePeriodGuard::assertOpen($pdo,$cid,(string)$oldDate);
                if($p['status']==='paid')throw new RuntimeException('Esta conta já está paga.');
                GeneralTreasuryAccountScope::assertGeneral($pdo,$cid,(string)($p['account_id']??''));
                $pdo->prepare("UPDATE finance_payables SET status='paid',paid_at=? WHERE id=? AND church_id=?")->execute([$paidDate,$id,$cid]);
                $entryId=app_uuid();
                $q=$pdo->prepare("INSERT INTO finance_entries(id,church_id,account_id,direction,category,description,amount,transaction_date,status,source,payable_id,created_by_user_id,counterparty,document_number,notes)
                    VALUES(?,?,?,'expense','Contas a pagar',?,?,?,'posted','payable',?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),description=VALUES(description),amount=VALUES(amount),transaction_date=VALUES(transaction_date),status='posted',counterparty=VALUES(counterparty),document_number=VALUES(document_number),notes=VALUES(notes)");
                $q->execute([$entryId,$cid,$p['account_id']??null,$p['supplier'].' - '.$p['description'],$p['amount'],$paidDate,$p['id'],$_SESSION['user_id']??null,$p['supplier'],$p['document_number']??null,$p['notes']??null]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Conta paga e saída registrada no livro financeiro.');
            redirect('/finance.php');
        }

        if($action==='budget'){
            require_once __DIR__.'/lib/BudgetService.php';
            (new BudgetService($pdo,$auth,$cid))->save($_POST);
            flash('success','Orçamento salvo.');
            redirect('/finance-budget.php');
        }

        if($action==='close_period'){
            $periodType=(string)($_POST['period_type']??'daily');
            if(!in_array($periodType,['daily','monthly'],true))throw new RuntimeException('Tipo de fechamento inválido.');
            $reference=(string)($_POST['reference_date']??date('Y-m-d'));
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reference))throw new RuntimeException('Data de fechamento inválida.');
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            if($accountId&&!finance_account_for($pdo,$cid,$accountId))throw new RuntimeException('Conta financeira inválida.');
            $notes=trim((string)($_POST['notes']??''))?:null;

            $periodStart=$periodType==='monthly'?date('Y-m-01',strtotime($reference)):$reference;
            $periodEnd=$periodType==='monthly'?date('Y-m-t',strtotime($reference)):$reference;

            if($accountId){
                $q=$pdo->prepare('SELECT opening_balance FROM finance_accounts WHERE id=? AND church_id=?');
                $q->execute([$accountId,$cid]);
                $opening=(float)$q->fetchColumn();

                $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) FROM finance_entries WHERE church_id=? AND account_id=? AND transaction_date<?");
                $q->execute([$cid,$accountId,$periodStart]);
                $opening+=(float)$q->fetchColumn();

                $q=$pdo->prepare("SELECT
                    COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
                    COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense
                    FROM finance_entries WHERE church_id=? AND account_id=? AND transaction_date BETWEEN ? AND ?");
                $q->execute([$cid,$accountId,$periodStart,$periodEnd]);
            }else{
                $q=$pdo->prepare('SELECT COALESCE(SUM(opening_balance),0) FROM finance_accounts WHERE church_id=? AND active=1');
                $q->execute([$cid]);
                $opening=(float)$q->fetchColumn();

                $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) FROM finance_entries WHERE church_id=? AND transaction_date<?");
                $q->execute([$cid,$periodStart]);
                $opening+=(float)$q->fetchColumn();

                $q=$pdo->prepare("SELECT
                    COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
                    COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense
                    FROM finance_entries WHERE church_id=? AND transaction_date BETWEEN ? AND ?");
                $q->execute([$cid,$periodStart,$periodEnd]);
            }

            $totals=$q->fetch()?:['income'=>0,'expense'=>0];
            $incomeTotal=(float)$totals['income'];
            $expenseTotal=(float)$totals['expense'];
            $closingBalance=$opening+$incomeTotal-$expenseTotal;

            $pdo->beginTransaction();
            try{
                $lock=$pdo->prepare('SELECT id FROM churches WHERE id=? FOR UPDATE');
                $lock->execute([$cid]);
                if(!$lock->fetchColumn())throw new RuntimeException('Igreja não encontrada.');

                $sql='SELECT id FROM finance_closings WHERE church_id=? AND period_type=? AND period_start=? AND period_end=? AND ';
                $params=[$cid,$periodType,$periodStart,$periodEnd];
                if($accountId){$sql.='account_id=?';$params[]=$accountId;}else{$sql.='account_id IS NULL';}
                $q=$pdo->prepare($sql.' LIMIT 1');
                $q->execute($params);
                $existingId=$q->fetchColumn();

                if($existingId){
                    $q=$pdo->prepare('UPDATE finance_closings SET opening_balance=?,income_total=?,expense_total=?,closing_balance=?,notes=?,closed_by_user_id=?,closed_at=NOW() WHERE id=? AND church_id=?');
                    $q->execute([$opening,$incomeTotal,$expenseTotal,$closingBalance,$notes,$_SESSION['user_id']??null,$existingId,$cid]);
                    $closingId=(string)$existingId;
                }else{
                    $closingId=app_uuid();
                    $q=$pdo->prepare('INSERT INTO finance_closings(id,church_id,account_id,period_type,period_start,period_end,opening_balance,income_total,expense_total,closing_balance,notes,closed_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                    $q->execute([$closingId,$cid,$accountId,$periodType,$periodStart,$periodEnd,$opening,$incomeTotal,$expenseTotal,$closingBalance,$notes,$_SESSION['user_id']??null]);
                }
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

            flash('success','Fechamento '.($periodType==='monthly'?'mensal':'diário').' salvo. Saldo final: R$ '.number_format($closingBalance,2,',','.').'.');
            redirect('/finance.php?closing='.rawurlencode($closingId));
        }

        if($action==='reconcile'){
            $accountId=(string)($_POST['account_id']??'');
            $account=finance_account_for($pdo,$cid,$accountId);
            $date=(string)($_POST['reference_date']??date('Y-m-d'));
            $statement=finance_money_input($_POST['statement_balance']??0);
            if(!$account)throw new RuntimeException('Selecione uma conta financeira.');
            $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) FROM finance_entries WHERE church_id=? AND account_id=? AND transaction_date<=?");
            $q->execute([$cid,$accountId,$date]);
            $system=(float)$account['opening_balance']+(float)$q->fetchColumn();
            $diff=$statement-$system;
            $status=abs($diff)<0.005?'reconciled':'open';
            $q=$pdo->prepare('INSERT INTO finance_reconciliations(id,church_id,account_id,reference_date,statement_balance,system_balance,difference_amount,status) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE statement_balance=VALUES(statement_balance),system_balance=VALUES(system_balance),difference_amount=VALUES(difference_amount),status=VALUES(status)');
            $q->execute([app_uuid(),$cid,$accountId,$date,$statement,$system,$diff,$status]);
            flash('success',$status==='reconciled'?'Conciliação fechou sem diferença.':'Conciliação salva com diferença de R$ '.number_format($diff,2,',','.').'.');
            redirect('/finance.php');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($financeAjaxRequest){
            error_log('Igreja Master financeiro AJAX: '.get_class($e).' '.$e->getMessage());
            $message=$e instanceof PDOException
                ?'O servidor não conseguiu gravar os dados no banco. Consulte o log de erros PHP.'
                :$e->getMessage();
            finance_ajax_confirm(['ok'=>false,'message'=>$message],422);
        }
        Ui::rememberPost('finance.php');
        flash('error',$e->getMessage());
        redirect('/finance.php');
    }
}

$q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense
FROM finance_entries WHERE church_id=? AND transaction_date BETWEEN ? AND ?");
$q->execute([$cid,$from,$to]);$tot=$q->fetch()?:['income'=>0,'expense'=>0];

$q=$pdo->prepare("SELECT COALESCE(SUM(opening_balance),0) FROM finance_accounts WHERE church_id=? AND active=1");
$q->execute([$cid]);$opening=(float)$q->fetchColumn();

$q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) FROM finance_entries WHERE church_id=? AND transaction_date<?");
$q->execute([$cid,$from]);$opening+=(float)$q->fetchColumn();

$income=(float)$tot['income'];$expense=(float)$tot['expense'];$closing=$opening+$income-$expense;

$q=$pdo->prepare('SELECT * FROM finance_accounts WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$accounts=$q->fetchAll();
$q=$pdo->prepare('SELECT * FROM finance_accounts WHERE church_id=? AND active=0 ORDER BY name');
$q->execute([$cid]);$inactiveAccounts=$q->fetchAll();
$generalAccounts=array_values(array_filter($accounts,static fn(array $a)=>empty($a['department_id'])));
$departmentAccountGroups=[];
$departmentLabels=[];
if($auth->can('department_treasury')){
    $departmentTreasury=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$auth->requireLogin()['id']);
    if($departmentTreasury->mayPost()){
        foreach($departmentTreasury->departments() as $dep){
            if(!empty($dep['active'])){
                $departmentLabels[(string)$dep['id']]=(string)$dep['name'];
                $departmentAccountGroups[(string)$dep['id']]=[];
            }
        }
        foreach($accounts as $account){
            $depId=(string)($account['department_id']??'');
            if($depId!==''&&array_key_exists($depId,$departmentAccountGroups))
                $departmentAccountGroups[$depId][]=$account;
        }
        $departmentAccountGroups=array_filter($departmentAccountGroups,static fn(array $group)=>count($group)>0);
    }
}

$q=$pdo->prepare('SELECT e.*,a.name account_name,m.name member_name,er.id expense_receipt_id,er.status expense_receipt_status,(SELECT COUNT(*) FROM finance_documents d WHERE d.church_id=e.church_id AND d.entry_id=e.id) document_count FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id LEFT JOIN members m ON m.id=e.member_id AND m.church_id=e.church_id LEFT JOIN expense_receipts er ON er.church_id=e.church_id AND er.entry_id=e.id WHERE e.church_id=? AND e.transaction_date BETWEEN ? AND ?
  AND NOT (e.status=\'reversed\' AND COALESCE(e.reversal_reason,\'\') LIKE \'[EXCLUSAO MASTER]%\')
  ORDER BY e.transaction_date DESC,e.created_at DESC LIMIT 500');
$q->execute([$cid,$from,$to]);$entries=$q->fetchAll();

$q=$pdo->prepare("SELECT p.*,a.name account_name,(SELECT COUNT(*) FROM finance_documents d WHERE d.payable_id=p.id) document_count FROM finance_payables p LEFT JOIN finance_accounts a ON a.id=p.account_id WHERE p.church_id=? AND p.status='open' ORDER BY p.due_date LIMIT 100");
$q->execute([$cid]);$payables=$q->fetchAll();

$byear=(int)substr($from,0,4);$bmonth=(int)substr($from,5,2);
$q=$pdo->prepare('SELECT * FROM finance_budgets WHERE church_id=? AND year=? AND month=? ORDER BY category');
$q->execute([$cid,$byear,$bmonth]);$budgets=$q->fetchAll();

$q=$pdo->prepare('SELECT r.*,a.name account_name FROM finance_reconciliations r INNER JOIN finance_accounts a ON a.id=r.account_id WHERE r.church_id=? ORDER BY r.reference_date DESC,r.created_at DESC LIMIT 10');
$q->execute([$cid]);$reconciliations=$q->fetchAll();

$q=$pdo->prepare('SELECT f.*,a.name account_name,u.full_name closed_by_name FROM finance_closings f LEFT JOIN finance_accounts a ON a.id=f.account_id LEFT JOIN users u ON u.id=f.closed_by_user_id WHERE f.church_id=? ORDER BY f.period_end DESC,f.closed_at DESC LIMIT 30');
$q->execute([$cid]);$closings=$q->fetchAll();

$q=$pdo->prepare('SELECT d.*,e.description entry_description,p.supplier payable_supplier FROM finance_documents d LEFT JOIN finance_entries e ON e.id=d.entry_id LEFT JOIN finance_payables p ON p.id=d.payable_id WHERE d.church_id=? ORDER BY d.created_at DESC LIMIT 80');
$q->execute([$cid]);$documents=$q->fetchAll();

$q=$pdo->prepare('SELECT * FROM finance_receipts WHERE church_id=? ORDER BY receipt_number DESC LIMIT 80');
$q->execute([$cid]);$receipts=$q->fetchAll();
$pendingReceipts=$pendingReceiptReady?$pendingFinanceReceipts->list():[];

// O lançamento agrupado movimenta o caixa uma única vez, mas o relatório
// por categoria distribui o total pelos itens sem contar o pai de novo.
$q=$pdo->prepare("SELECT x.direction,x.category,SUM(x.amount) total FROM (
    SELECT e.direction,e.category,e.amount FROM finance_entries e
      WHERE e.church_id=? AND e.status='posted' AND e.transaction_date BETWEEN ? AND ?
        AND COALESCE(e.is_grouped,0)=0
    UNION ALL
    SELECT e.direction,i.category,i.amount FROM finance_entry_items i
      INNER JOIN finance_entries e ON e.id=i.entry_id AND e.church_id=i.church_id
      WHERE e.church_id=? AND e.status='posted' AND e.transaction_date BETWEEN ? AND ?
        AND e.is_grouped=1
) x GROUP BY x.direction,x.category ORDER BY x.direction,total DESC");
$q->execute([$cid,$from,$to,$cid,$from,$to]);$categoryTotals=$q->fetchAll();

$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$contributionMembers=$q->fetchAll();

// A igreja controla integralmente os lançamentos contábeis disponíveis.
// Nenhuma categoria do sistema é adicionada automaticamente às opções.
$registeredCategories=$financeRegistry->all('finance_category');
$uniqueCategories=[];
foreach($registeredCategories as $register){
    $name=trim(preg_replace('/\\s+/u',' ',(string)($register['name']??''))??'');
    if($name==='')continue;
    $key=mb_strtolower($name,'UTF-8');
    if(!isset($uniqueCategories[$key]))$uniqueCategories[$key]=[
        'name'=>$name,
        'direction'=>(string)($register['data']['direction']??'both'),
    ];
}
$categories=array_values($uniqueCategories);
$costCenters=$financeRegistry->all('cost_center');$financeContacts=$financeRegistry->all('contact');
$methods=['Dinheiro','PIX manual','Transferência','Cartão','Boleto','Cheque','Outro'];
$docLabels=['fiscal_coupon'=>'Cupom fiscal','invoice'=>'Nota fiscal','receipt'=>'Recibo','proof'=>'Comprovante','contract'=>'Contrato','other'=>'Outro'];

View::header('Tesouraria',$auth,'finance.php');
?>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif?><?php if($success):?><div class="alert alert-success" role="status"><?=e($success)?></div><?php endif?>
<?php View::flash(); ?>
<div class="card card-pad" style="margin-bottom:12px"><strong>Notas fiscais e recibos</strong> — problemas ao anexar? <a class="btn btn-light" href="/finance-upload-check.php">Verificar tamanho, pasta e permissões de upload</a></div>
<div class="portal-tabs"><a href="/finance-control.php">Resumo financeiro</a><a href="/finance-control.php?view=receivables">Valores a receber</a><a href="/finance-control.php?view=report">Relatório por centro de custos</a><a href="/registers.php?kind=finance_category">Cadastros financeiros</a></div>

<div class="module-hero">
  <div><span class="eyebrow">Gestão financeira</span><h1>Tesouraria</h1><p>Livro caixa, entradas, saídas, documentos fiscais, recibos numerados, contas a pagar, orçamento, conciliação e relatórios profissionais.</p></div>
  <div class="actions">
    <a class="btn btn-light" target="_blank" href="/report.php?type=finance&from=<?=e($from)?>&to=<?=e($to)?>&format=print">🖨️ Imprimir relatório</a>
    <a class="btn btn-primary" href="/report.php?type=finance&from=<?=e($from)?>&to=<?=e($to)?>&format=pdf">PDF do período</a>
    <a class="btn btn-primary" href="/report.php?type=tithes&from=<?=e($from)?>&to=<?=e($to)?>&format=pdf">PDF dos dizimistas</a>
  </div>
</div>

<div class="card card-pad" style="margin-bottom:16px">
<form method="get" class="toolbar">
  <div class="field"><label>De</label><input class="input" type="date" name="from" value="<?=e($from)?>"></div>
  <div class="field"><label>Até</label><input class="input" type="date" name="to" value="<?=e($to)?>"></div>
  <button class="btn btn-primary" style="margin-top:17px">Atualizar período</button>
</form>
</div>

<div class="grid stats">
  <div class="card stat"><div class="stat-label">Saldo inicial</div><div class="stat-value">R$ <?=number_format($opening,2,',','.')?></div><div class="stat-foot">Antes do período</div></div>
  <div class="card stat"><div class="stat-label">Entradas</div><div class="stat-value">R$ <?=number_format($income,2,',','.')?></div><div class="stat-foot"><?=date('d/m',strtotime($from))?> a <?=date('d/m',strtotime($to))?></div></div>
  <div class="card stat"><div class="stat-label">Saídas</div><div class="stat-value">R$ <?=number_format($expense,2,',','.')?></div><div class="stat-foot">Despesas lançadas</div></div>
  <div class="card stat"><div class="stat-label">Saldo final</div><div class="stat-value">R$ <?=number_format($closing,2,',','.')?></div><div class="stat-foot">Saldo contábil</div></div>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <div class="page-head"><div><h2 style="font-size:16px">Movimentações</h2><p>Consolidado da igreja: Tesouraria Geral + caixas departamentais, sem duplicação. As entradas do Geral não pertencem ao departamento do membro contribuinte.</p></div></div>
  <div class="im-tithe-mobile" aria-label="Movimentações com identificação do contribuinte">
    <?php foreach(array_slice($entries,0,100) as $r):?>
    <article class="im-tithe-item">
       <div class="im-tithe-item-head"><span><?=e(date('d/m/Y',strtotime($r['transaction_date'])))?> · <?=e($r['category'])?></span><strong><?=$r['direction']==='income'?'+':'−'?> R$ <?=number_format((float)$r['amount'],2,',','.')?></strong></div>
       <h3><?=e($r['description'])?></h3>
       <?php if($r['direction']==='income'):?>
         <div class="im-tithe-member <?=empty($r['member_name'])?'im-tithe-anonymous':''?>">Membro contribuinte: <?=e($r['member_name']?:'Não vinculado ao cadastro')?></div>
       <?php elseif(!empty($r['counterparty'])):?>
         <div class="im-tithe-member">Favorecido: <?=e($r['counterparty'])?></div>
       <?php endif?>
       <div class="im-tithe-item-foot"><span><?=e($r['account_name']?:'Conta não informada')?></span><span><?=e($r['status']==='posted'?'Ativo':'Estornado')?></span></div>
       <a class="btn btn-light" href="/finance-entry-edit.php?id=<?=e($r['id'])?>">Editar / Anexar comprovante</a>
    </article>
    <?php endforeach?>
  </div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Membro contribuinte</th><th>Favorecido/Pagador</th><th>Conta</th><th>Valor</th><th>Docs.</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($entries as $r):?>
    <tr>
      <td><?=date('d/m/Y',strtotime($r['transaction_date']))?></td>
      <td><span class="badge <?=$r['direction']==='income'?'ok':'off'?>"><?=e($r['direction']==='income'?'Entrada':'Saída')?></span></td>
      <td><?=e($r['category'])?></td>
      <td><strong><?=e($r['description'])?></strong><?php if($r['document_number']):?><div class="muted">Doc. <?=e($r['document_number'])?></div><?php endif?></td>
      <td><?=e($r['member_name']?:'—')?></td>
       <td><?=e($r['counterparty']?:'—')?></td>
      <td><?=e($r['account_name']?:'—')?></td>
      <td><strong><?=$r['direction']==='income'?'+':'-'?> R$ <?=number_format((float)$r['amount'],2,',','.')?></strong></td>
      <td><?=e((string)$r['document_count'])?></td>
      <td><?php if($r['status']==='posted'):?><span class="badge ok">Ativo</span><?php else:?><span class="badge off">Estornado</span><?php endif?></td>
      <td>
        <details class="im-entry-edit" id="finance-entry-<?=e($r['id'])?>">
          <summary class="btn btn-light">Ações do lançamento</summary><a class="btn btn-primary" href="/finance-entry-edit.php?id=<?=e($r['id'])?>">Editar / Anexar nota</a>
          <?php if($r['status']==='posted'):?>
          <form method="post" style="margin:8px 0;padding:10px;border:1px solid #d8e5f2;border-radius:8px;min-width:235px">
            <?=csrf_field()?><input type="hidden" name="action" value="edit_entry_details"><input type="hidden" name="entry_id" value="<?=e($r['id'])?>">
            <label>Categoria</label><input class="input" name="category" list="finance-categories" value="<?=e($r['category'])?>" required>
            <label>Descrição</label><input class="input" name="description" value="<?=e($r['description'])?>" required maxlength="255">
            <small class="muted">Para mudar valor, conta ou data, use estorno e novo lançamento.</small>
            <button type="submit" class="btn btn-primary">Salvar detalhes</button>
          </form>
          <?php endif?>
          <form method="post" enctype="multipart/form-data" style="margin:8px 0;padding:10px;border:1px solid #d8e5f2;border-radius:8px;min-width:235px">
            <?=csrf_field()?><input type="hidden" name="action" value="attach_document"><input type="hidden" name="entry_id" value="<?=e($r['id'])?>">
            <label>Tipo de documento</label><select class="select" name="document_type"><option value="invoice">Nota fiscal</option><option value="fiscal_coupon">Cupom</option><option value="receipt">Recibo</option><option value="proof">Comprovante</option></select>
            <label>Selecionar arquivo</label><input class="input" type="file" name="document_file" accept="image/*,.pdf,.jpg,.jpeg,.png,.webp,.heic" required>
            <small class="muted">Se o navegador mostrar ERR_UPLOAD_FILE_CHANGED, selecione novamente a foto ou o PDF antes de enviar.</small>
            <button type="submit" class="btn btn-primary">Anexar ao lançamento</button>
          </form>
        </details>
        <?php if($r['direction']==='expense'):?>
        <a class="btn btn-light" href="/expense-receipt.php?entry=<?=e($r['id'])?>"><?=e(!empty($r['expense_receipt_id'])?($r['expense_receipt_status']==='pending_signature'?'Recibo pendente':'Ver recibo'):'Gerar recibo')?></a>
        <?php endif;?>
        <?php if($r['status']==='posted'):?>
          <details>
            <summary class="btn btn-light" style="display:inline-flex;padding:7px 9px">Estornar</summary>
            <form method="post" style="margin-top:8px;min-width:240px">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="reverse_entry">
              <input type="hidden" name="id" value="<?=e($r['id'])?>">
              <input class="input" name="reason" required placeholder="Motivo do estorno">
              <button class="btn btn-danger" style="margin-top:6px;width:100%">Confirmar estorno</button>
            </form>
          </details>
        <?php else:?><small class="muted"><?=e($r['reversal_reason']?:'Estornado')?></small><?php endif?>
      </td>
    </tr>
  <?php endforeach?>
  <?php if(!$entries):?><tr><td colspan="10"><div class="empty">Nenhuma movimentação no período.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>

<style>
.fiscal-upload-first{border:1px solid #b5d4ec;background:#eff7ff;border-radius:16px;padding:18px;margin:0 0 20px}
.fiscal-upload-first h3,.fiscal-step-heading{color:#123c68;margin:0 0 8px;font-size:18px}
.fiscal-upload-first p{margin:6px 0 13px;line-height:1.5}
.fiscal-files{padding-left:22px;margin:10px 0;font-size:14px;overflow-wrap:anywhere}
.fiscal-review{background:#fff;border:1px solid #bed9ed;border-radius:10px;padding:12px}
.fiscal-scan-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin-top:10px}
.fiscal-scan-grid div{display:flex;flex-direction:column;gap:2px}.fiscal-scan-grid span{color:#55718c;font-size:12px}
.fiscal-duplicate-alert{border:1px solid #dea749;background:#fff4da;padding:12px;border-radius:10px;color:#624313}
.fiscal-block{background:#fff0ee;border-color:#d77e72;color:#842c29}
.fiscal-duplicate-alert a{font-weight:bold}.fiscal-step-heading{margin:14px 0}
@media(max-width:600px){.fiscal-upload-first{padding:12px}}
</style>
<div class="card card-pad" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
  <div><strong>Lançar vários cupons ou notas</strong><p class="muted" style="margin:3px 0">Selecione até 8 fotos ou PDFs. A IA lê cada documento e você registra todos de uma vez, em lançamentos separados.</p></div>
  <a class="btn btn-primary" href="/finance-batch.php">+ Lançar vários cupons</a>
</div>
<div class="card card-pad" style="margin:12px 0;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
  <div><strong>Um lançamento com várias ofertas ou cupons</strong><p class="muted" style="margin:3px 0">Registre várias ofertas ou despesas num único total, mantendo cada item e comprovante separados dentro do lançamento.</p></div>
  <a class="btn btn-primary" href="/finance-group.php">+ Criar lançamento agrupado</a>
</div>
<style>
.fiscal-manual-extra{background:var(--card-bg,#fff);border:1px solid #b8cddd;border-radius:14px;padding:16px;margin:20px 0 10px}
.fiscal-manual-extra h3{margin:0 0 8px;font-size:17px;color:var(--text,#1c405f)}
.fiscal-manual-extra p{margin:5px 0 12px;line-height:1.5}
.fiscal-extra-list{margin:12px 0;padding:0;list-style:none;display:grid;gap:8px}
.fiscal-extra-list li{padding:9px 10px;border:1px solid #d6e1eb;border-radius:9px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;overflow-wrap:anywhere}
.fiscal-extra-list small{color:var(--muted,#65778c)}
.fiscal-extra-list .extra-error{color:#ab2c22}
.fiscal-extra-list .extra-ready{color:#126741}
@media(max-width:600px){.fiscal-manual-extra{padding:12px}}
</style>
<section data-registry-form="finance-entry" class="card card-pad form-card">
  <div class="section-title"><span>＋</span><div><h2>Novo lançamento</h2><p>Registre entrada ou saída e anexe o documento correspondente.</p></div></div>
  <p class="muted"><strong>Escolha a conta que vai receber ou pagar o valor.</strong> A conta selecionada define se o lançamento pertence à Tesouraria Geral ou a um departamento. O vínculo do membro contribuinte, por si só, não muda o destino da oferta ou do dízimo. <a href="/department-treasury.php">Ver caixas dos departamentos</a>.</p>
  <form method="post" action="/finance.php" enctype="multipart/form-data" data-expense-receipt-form data-finance-attachment="1" data-fiscal-autofill="1">
    <?=csrf_field()?><input type="hidden" name="action" value="entry">
    <input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>">
    <section class="fiscal-upload-first" aria-label="Primeira etapa - cupom ou nota fiscal">
      <h3>1. Envie a foto do cupom ou nota fiscal</h3>
      <p>Escolha uma foto ou PDF. A <strong>IA fiscal</strong> analisa a imagem, preenche valor, data, fornecedor, número da nota e descrição quando estiverem legíveis, e verifica possíveis duplicidades. <strong>Nada é lançado antes de você conferir e clicar em Registrar.</strong></p><p class="muted" style="font-size:12px">Ao selecionar o comprovante, ele será enviado à API de IA configurada pelo Master exclusivamente para leitura. Campos não identificados ficam para preenchimento manual.</p>
      <div class="form-grid">
        <div class="field"><label>Tipo do documento</label><select class="select" name="document_type"><?php foreach($docLabels as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach?></select></div>
        <div class="field" data-receipt-upload><label>Foto, cupom ou PDF</label>
          <input class="input" type="file" name="document_file" accept="image/*,.pdf,application/pdf">
          <small>Foto, PDF, JPG, PNG, WEBP ou HEIC até 12 MB (conforme hospedagem).</small>
        </div>
      </div>
      <p data-fiscal-status role="status" aria-live="polite">Selecione a foto ou o PDF para iniciar a leitura por IA.</p>
      <button class="btn btn-light" type="button" data-fiscal-precise hidden>Reanalisar com maior precisão</button>
      <ul data-fiscal-files class="fiscal-files"><li>Nenhum arquivo selecionado.</li></ul>
      <div data-fiscal-duplicates class="fiscal-duplicate-alert" role="alert" hidden></div>
      <div data-fiscal-review class="fiscal-review" hidden></div>
      <small>OCR é uma sugestão: confira valores, datas e números. A leitura pode depender da conexão e da nitidez do documento.</small>
    </section>
    <h3 class="fiscal-step-heading">2. Confira os dados do lançamento</h3>
    <div class="form-grid">
      <div class="field"><label>Tipo</label><select class="select" name="direction" id="finance-entry-direction"><option value="income">Entrada</option><option value="expense">Saída</option></select><small class="muted">Escolha entrada ou saída. O formulário adapta as opções para cada operação.</small></div>
      <div class="field"><label>Data</label><input class="input" type="date" name="transaction_date" value="<?=date('Y-m-d')?>"></div>
      <div class="field full"><label>Conta / caixa de destino</label>
        <select class="select" name="account_id" id="finance-entry-account">
          <option value="">Tesouraria Geral — sem conta específica</option>
          <?php if($generalAccounts):?><optgroup label="Tesouraria Geral"><?php foreach($generalAccounts as $a):?>
            <option value="<?=e($a['id'])?>"><?=e($a['name'])?> (<?=e($a['account_type']==='bank'?'Banco':'Caixa')?>)</option>
          <?php endforeach?></optgroup><?php endif?>
          <?php foreach($departmentAccountGroups as $depId=>$depAccounts):?>
            <optgroup label="Departamento: <?=e($departmentLabels[$depId]??'Outro')?>"><?php foreach($depAccounts as $a):?>
              <option value="<?=e($a['id'])?>"><?=e($a['name'])?> (<?=e($a['account_type']==='bank'?'Banco':'Caixa')?>)</option>
            <?php endforeach?></optgroup>
          <?php endforeach?>
        </select>
        <small class="muted">As contas aparecem conforme seu acesso. Ao selecionar um caixa departamental, o movimento entra naquele departamento e aparece apenas uma vez no consolidado geral.</small>
      </div>
      <div class="field"><label for="finance-category-entry">Lançamento Contábil *</label>
        <select class="select" id="finance-category-entry" name="category" required>
          <option value="">Selecione um lançamento cadastrado pela igreja</option>
          <?php foreach($categories as $categoryOption):?>
          <option value="<?=e($categoryOption['name'])?>" data-direction="<?=e($categoryOption['direction'])?>"><?=e($categoryOption['name'])?></option>
          <?php endforeach?>
        </select>
        <small class="muted">Somente lançamentos criados por esta igreja aparecem aqui. <a href="/registers.php?kind=finance_category">Cadastrar ou gerenciar lançamentos contábeis</a>.</small>
      </div>
      <div class="field"><label>Valor *</label><input class="input" name="amount" inputmode="decimal" placeholder="0,00" required></div>
      
      <div class="field"><label>Contato cadastrado (opcional)</label><select class="select" name="contact_id"><option value="">Não informar</option><?php foreach($financeContacts as $option):?><option value="<?=e($option['id'])?>"><?=e($option['name'])?></option><?php endforeach?></select></div>
      <div class="field full" data-finance-member-field><label>Membro contribuinte (opcional)</label><select class="select" name="member_id"><option value="">Sem vínculo pessoal</option><?php foreach($contributionMembers as $member):?><option value="<?=e($member['id'])?>"><?=e($member['name'])?></option><?php endforeach?></select><small>O vínculo identifica quem contribuiu e mostra a entrada no Portal do Membro. <strong>Não transfere o valor para o departamento do membro.</strong></small><label class="finance-tithe-choice" data-finance-tithe-choice hidden><input type="checkbox" name="tithe_identified" value="1"> Este é um dízimo identificado (exige selecionar o membro acima)</label></div>
      <div class="field"><label>Favorecido / Pagador</label><input class="input" name="counterparty"></div>
      <div class="field"><label>Forma de pagamento</label><select class="select" name="payment_method"><option value="">Não informar</option><?php foreach($methods as $m):?><option><?=e($m)?></option><?php endforeach?></select></div>
      <div class="field"><label>Nº documento / cupom / referência Pix</label><input class="input" name="document_number" maxlength="190" placeholder="ID Pix, autenticação ou número do cupom"><small class="muted" data-finance-bank-reference-hint>Para entradas via Pix ou transferência, informe a referência da operação.</small></div>

       <div class="field full"><div class="expense-receipt-option" data-receipt-proof-choice hidden>
<label>Se não tiver foto ou nota, como deseja comprovar a despesa?
<select class="select" name="proof_method" data-receipt-method>
<option value="upload">Anexar foto, cupom ou nota fiscal</option>
<option value="receipt_screen">Gerar recibo e assinar agora na tela</option>
<option value="receipt_print">Gerar recibo para imprimir e assinar depois</option>
</select></label>
<p>Recibo impresso sem assinatura ficará pendente até ser assinado e anexado. O recibo não substitui nota fiscal legalmente obrigatória.</p>
<div class="expense-receipt-fields" data-receipt-details hidden>
<label>Nome de quem recebeu o pagamento *
<input class="input" type="text" name="recipient_name" maxlength="190" placeholder="Nome completo do recebedor"></label>
<label>CPF/CNPJ do recebedor (opcional)
<input class="input" type="text" name="recipient_document" maxlength="32" autocomplete="off"></label>
</div>
<div data-receipt-signature hidden>
<p>Peça à pessoa que recebeu o pagamento para assinar abaixo, usando o dedo ou mouse.</p>
<canvas data-receipt-canvas class="expense-sign-canvas" width="800" height="230" aria-label="Assinatura do recebedor"></canvas>
<input type="hidden" name="signature_data" data-signature-output>
<button class="btn btn-light" type="button" data-signature-clear>Limpar assinatura</button>
<label><input type="checkbox" name="signature_consent" value="1" data-signature-consent>
Sou a pessoa indicada como recebedora e confirmo o recebimento do valor.</label>
</div>
</div></div>
      <div class="field full"><label>Observações (opcional)</label><textarea class="textarea" name="notes"></textarea></div>
      <div class="field full"><label>Descrição do lançamento *</label><input class="input" name="description" required placeholder="Escreva o motivo desta entrada ou saída"><small class="muted">Último campo: confira as informações e descreva o lançamento.</small></div>
    </div>
    <?php $extraUploadPhpLimit=UploadService::financeLimits()['php'];
          $extraMaxFileBytes=min(12*1024*1024,$extraUploadPhpLimit!==null?max(1,(int)$extraUploadPhpLimit-131072):12*1024*1024); ?>
    <section class="fiscal-manual-extra" data-extra-attachments data-max-file-bytes="<?=e((string)$extraMaxFileBytes)?>">
      <h3>3. Documentos adicionais (anexo manual, sem IA)</h3>
      <p>Precisa guardar mais de uma nota ou comprovante desta mesma compra? Envie aqui os outros documentos. <strong>Não haverá nova leitura por IA nem outro lançamento ou valor no caixa.</strong></p>
      <div class="form-grid">
        <div class="field">
          <label>Tipo dos documentos adicionais</label>
          <select class="select" name="extra_document_type">
            <option value="invoice">Notas fiscais</option>
            <option value="fiscal_coupon">Cupons fiscais</option>
            <option value="proof" selected>Comprovantes e anexos</option>
            <option value="receipt">Recibos</option>
            <option value="contract">Contratos</option>
            <option value="other">Outros documentos</option>
          </select>
        </div>
        <div class="field">
          <label>Escolher outros arquivos (até 8)</label>
          <input class="input" type="file" data-extra-attachment-picker multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" aria-label="Adicionar mais notas e comprovantes a este lançamento">
          <small>PDF ou foto. Pode escolher vários juntos ou adicionar mais depois. Cada arquivo é enviado separadamente (limite atual: <?=e(number_format($extraMaxFileBytes/1048576,1,',','.'))?> MB por arquivo).</small>
        </div>
      </div>
      <input type="hidden" name="extra_document_tokens" value="[]">
      <ul class="fiscal-extra-list" data-extra-attachment-list><li>Nenhum documento adicional selecionado.</li></ul>
      <p class="muted" data-extra-attachment-status role="status" aria-live="polite"></p>
    </section>
    <button class="btn btn-primary" style="width:100%;margin-top:12px">Registrar lançamento</button>
    <p class="muted" data-upload-result role="status" aria-live="polite"></p>
  </form>
</section>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <h2 style="font-size:16px">Documentos fiscais e comprovantes</h2>
  <p class="muted">Arquivos ficam protegidos e vinculados ao lançamento ou conta a pagar.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Referência</th><th>Arquivo</th><th></th></tr></thead><tbody>
  <?php foreach($documents as $d):?>
    <tr><td><?=date('d/m/Y',strtotime($d['created_at']))?></td><td><?=e($docLabels[$d['document_type']]??$d['document_type'])?></td><td><?=e($d['entry_description']?:$d['payable_supplier']?:'—')?></td><td><?=e($d['original_name'])?></td><td><a class="btn btn-light" target="_blank" href="/finance-document.php?id=<?=e($d['id'])?>">Abrir</a></td></tr>
  <?php endforeach?>
  <?php if(!$documents):?><tr><td colspan="5"><div class="empty">Nenhum documento arquivado.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>

<section data-registry-form="finance-document" class="card card-pad">
  <h2 style="font-size:16px">Arquivar documento</h2>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="attach_document">
    <div class="form-grid">
      <div class="field full"><label>Vincular a lançamento</label><select class="select" name="entry_id"><option value="">Nenhum</option><?php foreach(array_slice($entries,0,100) as $e):?><option value="<?=e($e['id'])?>"><?=e(date('d/m/Y',strtotime($e['transaction_date'])).' · '.$e['description'].' · R$ '.number_format((float)$e['amount'],2,',','.'))?></option><?php endforeach?></select></div>
      <div class="field full"><label>Ou vincular a conta a pagar</label><select class="select" name="payable_id"><option value="">Nenhuma</option><?php foreach($payables as $p):?><option value="<?=e($p['id'])?>"><?=e($p['supplier'].' · '.$p['description'])?></option><?php endforeach?></select></div>
      <div class="field"><label>Tipo</label><select class="select" name="document_type"><?php foreach($docLabels as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach?></select></div>
      <div class="field"><label>Número</label><input class="input" name="document_number"></div>
      <div class="field"><label>Emissão</label><input class="input" type="date" name="issued_at"></div>
      <div class="field full"><label>Arquivo *</label><input class="input" type="file" name="document_file" required accept="application/pdf,image/jpeg,image/png,image/webp"></div>
      <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"></textarea></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:10px">Arquivar documento</button>
  </form>
</section>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <h2 style="font-size:16px">Recibos emitidos</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Nº</th><th>Data</th><th>Recebemos de</th><th>Valor</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($receipts as $r):?>
    <tr><td><?=str_pad((string)$r['receipt_number'],6,'0',STR_PAD_LEFT)?></td><td><?=date('d/m/Y',strtotime($r['received_at']))?></td><td><?=e($r['payer_name'])?></td><td>R$ <?=number_format((float)$r['amount'],2,',','.')?></td><td><a class="btn btn-light" target="_blank" href="/report.php?type=receipt&id=<?=e($r['id'])?>&format=print">Imprimir</a> <a class="btn btn-light" href="/report.php?type=receipt&id=<?=e($r['id'])?>&format=pdf">PDF</a></td></tr>
  <?php endforeach?>
  <?php if(!$receipts):?><tr><td colspan="5"><div class="empty">Nenhum recibo emitido.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>

<?php if(!$pendingReceiptReady):?><div class="alert alert-warning" role="alert">A área de recibos com assinatura ainda precisa da atualização do banco de dados. A Tesouraria normal continua funcionando.</div><?php endif?>
<section class="card card-pad" id="pending-receipts" style="margin-top:16px">
<h2>Recibos aguardando assinatura e conferência</h2>
<p class="muted">Assinatura na tela ou upload do papel assinado. A entrada só será criada após clicar em <strong>Conferir e lançar</strong>.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Recebemos de</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php foreach($pendingReceipts as $r):?>
<tr><td><?=e(date('d/m/Y',strtotime($r['received_at'])))?></td><td><?=e($r['payer_name'])?></td>
<td>R$ <?=number_format((float)$r['amount'],2,',','.')?></td>
<td><?=e(match($r['status']){'awaiting_signature'=>'Aguardando assinatura','awaiting_review'=>'Aguardando conferência','posted'=>'Conferido e lançado','cancelled'=>'Cancelado',default=>$r['status']})?></td>
<td>
<a class="btn btn-light" href="/pending-receipt-print.php?id=<?=e($r['id'])?>" target="_blank" rel="noopener">Imprimir</a>
<?php if($r['status']==='awaiting_signature'):?>
<details><summary class="btn btn-light">Anexar assinatura</summary>
<form method="post" enctype="multipart/form-data" data-pending-signature-form>
<?=csrf_field()?><input type="hidden" name="action" value="receipt_sign_pending"><input type="hidden" name="id" value="<?=e($r['id'])?>">
<label>Como foi assinado?</label><select name="signature_method"><option value="paper">Papel impresso e assinado (foto/PDF)</option><option value="screen">Assinatura feita na tela</option></select>
<label>Foto/PDF assinado</label><input type="file" name="signed_file" accept="image/png,image/jpeg,image/webp,application/pdf" required>
<details><summary>Assinar agora na tela</summary><canvas data-sign-canvas width="600" height="210" style="width:100%;touch-action:none;border:1px solid #b7d0e7;border-radius:8px;background:white"></canvas><button type="button" class="btn btn-light" data-sign-clear>Limpar assinatura</button><button type="button" class="btn btn-light" data-sign-use>Usar esta assinatura</button></details>
<button type="submit" class="btn btn-primary">Salvar assinatura para conferência</button></form></details>
<?php elseif($r['status']==='awaiting_review'):?>
<a class="btn btn-light" href="/pending-receipt-file.php?id=<?=e($r['id'])?>" target="_blank" rel="noopener">Ver recibo assinado</a>
<form method="post" onsubmit="return confirm('Assinatura conferida? Esta confirmação lançará o valor UMA VEZ na Tesouraria.')">
<?=csrf_field()?><input type="hidden" name="action" value="receipt_approve_pending"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-primary">Conferir e lançar</button></form>
<?php endif?>
<?php if(in_array($r['status'],['awaiting_signature','awaiting_review'],true)):?>
<form method="post" onsubmit="return confirm('Cancelar recibo não lançado?')"><?=csrf_field()?><input type="hidden" name="action" value="receipt_cancel_pending"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-light">Cancelar</button></form>
<?php endif?>
</td></tr><?php endforeach?>
<?php if(!$pendingReceipts):?><tr><td colspan="5">Nenhum recibo provisório.</td></tr><?php endif?>
</tbody></table></div>
</section>
<script src="/assets/pending-receipt-sign-3736.js?v=1" defer></script>
<?php if($pendingReceiptReady):?><section data-registry-form="finance-receipt" class="card card-pad">
  <h2 style="font-size:16px">Emitir recibo</h2>
  <p class="muted">O recibo será criado como pendente. Nenhum valor entra no caixa antes da assinatura, conferência e confirmação do tesoureiro.</p>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="receipt_create_pending">
    <div class="form-grid">
      <?php foreach(['cost_center_id'=>['Centro de custos',$costCenters],'contact_id'=>['Contato cadastrado',$financeContacts]] as $key=>[$label,$options]):?><div class="field"><label><?=e($label)?></label><select class="select" name="<?=e($key)?>"><option value="">Não informar</option><?php foreach($options as $option):?><option value="<?=e($option['id'])?>"><?=e($option['name'])?></option><?php endforeach?></select></div><?php endforeach?>
      <div class="field full"><label>Membro contribuinte (opcional)</label><select class="select" name="member_id"><option value="">Sem vínculo pessoal</option><?php foreach($contributionMembers as $member):?><option value="<?=e($member['id'])?>"><?=e($member['name'])?></option><?php endforeach?></select><small>O vínculo identifica quem contribuiu e mostra a entrada no Portal do Membro. <strong>Não transfere o valor para o departamento do membro.</strong></small></div>
      <div class="field full"><label>Recebemos de *</label><input class="input" name="payer_name" required></div>
      <div class="field"><label>CPF/CNPJ (opcional)</label><input class="input" name="payer_document"></div>
      <div class="field"><label>Valor *</label><input class="input" name="amount" required></div>
      <div class="field full"><label>Referente a *</label><input class="input" name="description" required placeholder="Ex.: Oferta para reforma do templo"></div>
      <div class="field"><label>Data</label><input class="input" type="date" name="received_at" value="<?=date('Y-m-d')?>"></div>
      <div class="field"><label>Forma de pagamento</label><select class="select" name="payment_method"><option value="">Não informar</option><?php foreach($methods as $m):?><option><?=e($m)?></option><?php endforeach?></select></div>
      <div class="field"><label>Categoria</label><input class="input" name="category" list="finance-categories" value="Ofertas"></div>
      <div class="field"><label>Conta geral</label><select class="select" name="account_id"><option value="">Caixa geral sem conta específica</option><?php foreach($generalAccounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div>
      <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"></textarea></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:10px">Criar recibo pendente (sem lançar valor)</button>
  </form>
</section>
<?php endif?>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <h2 style="font-size:16px">Contas a pagar</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Vencimento</th><th>Fornecedor</th><th>Conta</th><th>Valor</th><th>Docs.</th><th></th></tr></thead><tbody>
  <?php foreach($payables as $p):?>
    <tr><td><?=date('d/m/Y',strtotime($p['due_date']))?></td><td><strong><?=e($p['supplier'])?></strong><div class="muted"><?=e($p['description'])?></div></td><td><?=e($p['account_name']?:'—')?></td><td>R$ <?=number_format((float)$p['amount'],2,',','.')?></td><td><?=e((string)$p['document_count'])?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?=e($p['id'])?>"><input type="hidden" name="paid_at" value="<?=date('Y-m-d')?>"><button class="btn btn-light">Pagar</button></form></td></tr>
  <?php endforeach?>
  <?php if(!$payables):?><tr><td colspan="6"><div class="empty">Nenhuma conta em aberto.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>

<section data-registry-form="finance-payable" class="card card-pad">
  <h2 style="font-size:16px">Nova conta a pagar</h2>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="payable">
    <div class="form-grid">
      <div class="field full"><label>Fornecedor *</label><input class="input" name="supplier" required></div>
      <div class="field full"><label>Descrição *</label><input class="input" name="description" required></div>
      <div class="field"><label>Valor *</label><input class="input" name="amount" inputmode="decimal" required></div>
      <div class="field"><label>Vencimento *</label><input class="input" type="date" name="due_date" required></div>
      <div class="field"><label>Nº nota/cupom</label><input class="input" name="document_number"></div>
      <div class="field"><label>Conta geral prevista</label><select class="select" name="account_id"><option value="">Definir depois</option><?php foreach($generalAccounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div>
      <div class="field"><label>Tipo de documento</label><select class="select" name="document_type"><?php foreach($docLabels as $k=>$v):?><option value="<?=e($k)?>" <?=$k==='invoice'?'selected':''?>><?=e($v)?></option><?php endforeach?></select></div>
      <div class="field full"><label>Nota/cupom/comprovante</label><input class="input" type="file" name="document_file" accept="application/pdf,image/jpeg,image/png,image/webp"></div>
      <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"></textarea></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:10px">Cadastrar conta</button>
  </form>
</section>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <h2 style="font-size:16px">Caixa e contas bancárias</h2>
  <p class="muted">Para excluir uma conta cadastrada por engano, use <strong>Excluir/arquivar</strong>. Isso retira a conta de novos lançamentos sem apagar o histórico.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Conta</th><th>Destino</th><th>Tipo</th><th>Saldo inicial</th><th>Ações</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr>
    <td><strong><?=e($a['name'])?></strong></td>
    <td><?=e(empty($a['department_id'])?'Tesouraria Geral':($departmentLabels[(string)$a['department_id']]??'Departamento'))?></td>
    <td><?=e($a['account_type']==='bank'?'Banco':'Caixa')?></td>
    <td>R$ <?=number_format((float)$a['opening_balance'],2,',','.')?></td>
    <td><form method="post" onsubmit="return confirm('Excluir/arquivar esta conta? Os lançamentos antigos serão preservados.')"><?=csrf_field()?><input type="hidden" name="action" value="account_status"><input type="hidden" name="enable" value="0"><input type="hidden" name="id" value="<?=e($a['id'])?>"><button type="submit" class="btn btn-light">Excluir/arquivar</button></form></td>
  </tr><?php endforeach?></tbody></table></div>
  <?php if($inactiveAccounts):?><details style="margin-top:12px"><summary>Contas excluídas/arquivadas (<?=count($inactiveAccounts)?>)</summary>
  <div class="table-wrap"><table class="table"><thead><tr><th>Conta</th><th>Tipo</th><th>Ação</th></tr></thead><tbody>
  <?php foreach($inactiveAccounts as $a):?><tr><td><?=e($a['name'])?></td><td><?=e($a['account_type'])?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="account_status"><input type="hidden" name="enable" value="1"><input type="hidden" name="id" value="<?=e($a['id'])?>"><button class="btn btn-light">Reativar</button></form></td></tr><?php endforeach?>
  </tbody></table></div></details><?php endif?>
  <form data-registry-form="finance-account" method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="account"><div class="form-grid"><div class="field full"><label>Nome *</label><input class="input" name="name" placeholder="Caixa da igreja / Banco..." required></div><div class="field"><label>Tipo</label><select class="select" name="account_type"><option value="cash">Caixa</option><option value="bank">Banco</option></select></div><div class="field"><label>Saldo inicial</label><input class="input" name="opening_balance" value="0,00"></div></div><button class="btn btn-primary" style="margin-top:10px">Criar conta</button></form>
</section>

<section class="card card-pad">
  <h2 style="font-size:16px">Orçamento mensal</h2><p class="muted"><?=sprintf('%02d/%04d',$bmonth,$byear)?></p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Categoria</th><th>Planejado</th></tr></thead><tbody><?php foreach($budgets as $b):?><tr><td><?=e($b['category'])?></td><td>R$ <?=number_format((float)$b['planned_amount'],2,',','.')?></td></tr><?php endforeach?></tbody></table></div>
  <p><a class="btn btn-light" href="/finance-budget.php">Comparar orçamento e realizado</a></p><form data-registry-form="finance-budget" method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="budget"><input type="hidden" name="year" value="<?=$byear?>"><input type="hidden" name="month" value="<?=$bmonth?>"><div class="form-grid"><div class="field"><label>Categoria *</label><input class="input" name="category" list="finance-categories" required></div><div class="field"><label>Valor planejado *</label><input class="input" name="planned_amount" required></div></div><button class="btn btn-primary" style="margin-top:10px">Salvar orçamento</button></form>
</section>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <h2 style="font-size:16px">Conciliação bancária</h2>
  <form data-registry-form="finance-reconcile" method="post"><?=csrf_field()?><input type="hidden" name="action" value="reconcile"><div class="form-grid"><div class="field full"><label>Conta *</label><select class="select" name="account_id" required><option value="">Selecione</option><?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div><div class="field"><label>Data de referência</label><input class="input" type="date" name="reference_date" value="<?=date('Y-m-d')?>"></div><div class="field"><label>Saldo do extrato *</label><input class="input" name="statement_balance" required></div></div><button class="btn btn-primary" style="margin-top:10px">Conferir saldo</button></form>
  <h3 style="font-size:13px;margin-top:18px">Últimas conferências</h3>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Conta</th><th>Diferença</th><th>Status</th></tr></thead><tbody><?php foreach($reconciliations as $r):?><tr><td><?=date('d/m/Y',strtotime($r['reference_date']))?></td><td><?=e($r['account_name'])?></td><td>R$ <?=number_format((float)$r['difference_amount'],2,',','.')?></td><td><span class="badge <?=$r['status']==='reconciled'?'ok':''?>"><?=e($r['status']==='reconciled'?'Conciliado':'Revisar')?></span></td></tr><?php endforeach?></tbody></table></div>
</section>

<section class="card card-pad">
  <h2 style="font-size:16px">Fechamento de caixa</h2>
  <p class="muted">Congele uma fotografia do caixa por dia ou por mês. O sistema calcula saldo inicial, entradas, saídas e saldo final.</p>
  <form data-registry-form="finance-closing" method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="close_period">
    <div class="form-grid">
      <div class="field"><label>Período</label><select class="select" name="period_type"><option value="daily">Diário</option><option value="monthly">Mensal</option></select></div>
      <div class="field"><label>Data de referência</label><input class="input" type="date" name="reference_date" value="<?=date('Y-m-d')?>"></div>
      <div class="field full"><label>Conta</label><select class="select" name="account_id"><option value="">Consolidado · todas as contas</option><?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div>
      <div class="field full"><label>Observações</label><textarea class="textarea" name="notes" placeholder="Ex.: caixa conferido com tesoureiro e secretário."></textarea></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:10px">Fechar período</button>
  </form>

  <h3 style="font-size:13px;margin-top:18px">Últimos fechamentos</h3>
  <div class="table-wrap"><table class="table"><thead><tr><th>Período</th><th>Conta</th><th>Entradas</th><th>Saídas</th><th>Saldo final</th><th></th></tr></thead><tbody>
  <?php foreach($closings as $f):?>
    <tr>
      <td><strong><?=e($f['period_type']==='monthly'?'Mensal':'Diário')?></strong><div class="muted"><?=e(date('d/m/Y',strtotime($f['period_start'])).($f['period_end']!==$f['period_start']?' a '.date('d/m/Y',strtotime($f['period_end'])):''))?></div></td>
      <td><?=e($f['account_name']?:'Consolidado')?></td>
      <td>R$ <?=number_format((float)$f['income_total'],2,',','.')?></td>
      <td>R$ <?=number_format((float)$f['expense_total'],2,',','.')?></td>
      <td><strong>R$ <?=number_format((float)$f['closing_balance'],2,',','.')?></strong></td>
      <td style="white-space:nowrap"><a class="btn btn-light" target="_blank" href="/report.php?type=finance_closing&id=<?=e($f['id'])?>&format=print">Imprimir</a> <a class="btn btn-light" href="/report.php?type=finance_closing&id=<?=e($f['id'])?>&format=pdf">PDF</a></td>
    </tr>
  <?php endforeach?>
  <?php if(!$closings):?><tr><td colspan="6"><div class="empty">Nenhum fechamento realizado.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>

<section class="card card-pad">
  <h2 style="font-size:16px">Resumo por categoria</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Categoria</th><th>Total</th></tr></thead><tbody><?php foreach($categoryTotals as $r):?><tr><td><?=e($r['direction']==='income'?'Entrada':'Saída')?></td><td><?=e($r['category'])?></td><td>R$ <?=number_format((float)$r['total'],2,',','.')?></td></tr><?php endforeach?></tbody></table></div>
</section>
</div>

<datalist id="finance-categories"><?php foreach($categories as $c):?><option value="<?=e($c['name'])?>"><?php endforeach?></datalist>

<?php if(!empty($_GET['closing'])):?>
<script>
window.addEventListener('load',()=>{const id=<?=json_encode((string)$_GET['closing'])?>;if(id&&confirm('Fechamento salvo. Deseja abrir o relatório para imprimir agora?'))window.open('/report.php?type=finance_closing&id='+encodeURIComponent(id)+'&format=print','_blank')});
</script>
<?php endif?>

<?php if(!empty($_GET['receipt'])):?>
<script>
window.addEventListener('load',()=>{const id=<?=json_encode((string)$_GET['receipt'])?>;if(id&&confirm('Recibo emitido. Deseja abrir para imprimir agora?'))window.open('/report.php?type=receipt&id='+encodeURIComponent(id)+'&format=print','_blank')});
</script>
<?php endif?>

<script defer src="/assets/finance-assistant-3726.js?v=3.7.46"></script>
<script defer src="/assets/finance-ocr-3752.js?v=3.7.54"></script>
<script defer src="/assets/finance-manual-extras-3758.js?v=3.7.58"></script>
<?php View::footer();?>
