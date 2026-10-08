<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/UploadService.php';

$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$uploader=new UploadService(__DIR__);

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
    $stored=$uploader->financeDocument($file,$cid);
    if(!$stored)return null;
    $id=app_uuid();
    $q=$pdo->prepare('INSERT INTO finance_documents(id,church_id,entry_id,payable_id,document_type,document_number,issued_at,original_name,storage_path,mime_type,file_size,notes,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([
        $id,$cid,$entryId,$payableId,finance_document_type($type),$number?:null,$issuedAt?:null,
        $stored['original_name'],$stored['path'],$stored['mime'],$stored['size'],$notes?:null,$userId
    ]);
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

            $q=$pdo->prepare("SELECT id,status FROM finance_entries WHERE id=? AND church_id=? LIMIT 1");
            $q->execute([$id,$cid]);$entry=$q->fetch();
            if(!$entry)throw new RuntimeException('Lançamento não encontrado.');
            if($entry['status']!=='posted')throw new RuntimeException('Este lançamento já não está ativo.');

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

        if($action==='entry'){
            $direction=(string)($_POST['direction']??'income');
            if(!in_array($direction,['income','expense'],true))throw new RuntimeException('Tipo inválido.');
            $amount=finance_money_input($_POST['amount']??0);
            $date=(string)($_POST['transaction_date']??date('Y-m-d'));
            $desc=trim((string)($_POST['description']??''));
            $category=trim((string)($_POST['category']??''));
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            $counterparty=trim((string)($_POST['counterparty']??''))?:null;
            $paymentMethod=trim((string)($_POST['payment_method']??''))?:null;
            $documentNumber=trim((string)($_POST['document_number']??''))?:null;
            $notes=trim((string)($_POST['notes']??''))?:null;
            if($accountId&&!finance_account_for($pdo,$cid,$accountId))throw new RuntimeException('Conta financeira inválida.');
            if($amount<=0||$desc===''||$category==='')throw new RuntimeException('Informe valor, categoria e descrição.');

            $entryId=app_uuid();
            $q=$pdo->prepare("INSERT INTO finance_entries(id,church_id,account_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,payment_method,document_number,notes) VALUES(?,?,?,?,?,?,?,?,'posted','manual',?,?,?,?,?)");
            $q->execute([$entryId,$cid,$accountId,$direction,$category,$desc,$amount,$date,$_SESSION['user_id']??null,$counterparty,$paymentMethod,$documentNumber,$notes]);

            finance_attach_document(
                $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,null,
                (string)($_POST['document_type']??'proof'),$documentNumber,$date,$notes,$_SESSION['user_id']??null
            );

            flash('success',($direction==='income'?'Entrada':'Saída').' registrada no livro financeiro.');
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
            flash('success','Documento arquivado com segurança.');
            redirect('/finance.php');
        }

        if($action==='receipt'){
            $payer=trim((string)($_POST['payer_name']??''));
            $payerDoc=trim((string)($_POST['payer_document']??''))?:null;
            $amount=finance_money_input($_POST['amount']??0);
            $description=trim((string)($_POST['description']??''));
            $date=(string)($_POST['received_at']??date('Y-m-d'));
            $paymentMethod=trim((string)($_POST['payment_method']??''))?:null;
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            $category=trim((string)($_POST['category']??'Ofertas'))?:'Ofertas';
            $notes=trim((string)($_POST['notes']??''))?:null;
            if($payer===''||$amount<=0||$description==='')throw new RuntimeException('Informe nome, valor e motivo do recibo.');
            if($accountId&&!finance_account_for($pdo,$cid,$accountId))throw new RuntimeException('Conta financeira inválida.');

            $pdo->beginTransaction();
            try{
                // Serializa a numeração por igreja para impedir recibos duplicados
                // quando dois usuários emitem ao mesmo tempo.
                $lockChurch=$pdo->prepare('SELECT id FROM churches WHERE id=? FOR UPDATE');
                $lockChurch->execute([$cid]);
                if(!$lockChurch->fetchColumn())throw new RuntimeException('Igreja não encontrada.');

                $q=$pdo->prepare('SELECT COALESCE(MAX(receipt_number),0)+1 FROM finance_receipts WHERE church_id=?');
                $q->execute([$cid]);$number=(int)$q->fetchColumn();
                $entryId=app_uuid();
                $pdo->prepare("INSERT INTO finance_entries(id,church_id,account_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,payment_method,notes) VALUES(?,?,?,'income',?,?,?,?,'posted','receipt',?,?,?,?)")
                    ->execute([$entryId,$cid,$accountId,$category,$description,$amount,$date,$_SESSION['user_id']??null,$payer,$paymentMethod,$notes]);

                $receiptId=app_uuid();
                $pdo->prepare('INSERT INTO finance_receipts(id,church_id,receipt_number,finance_entry_id,payer_name,payer_document,amount,description,payment_method,received_at,notes,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$receiptId,$cid,$number,$entryId,$payer,$payerDoc,$amount,$description,$paymentMethod,$date,$notes,$_SESSION['user_id']??null]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

            flash('success','Recibo nº '.str_pad((string)$number,6,'0',STR_PAD_LEFT).' emitido e entrada registrada.');
            redirect('/finance.php?receipt='.rawurlencode($receiptId));
        }

        if($action==='payable'){
            $supplier=trim((string)($_POST['supplier']??''));
            $desc=trim((string)($_POST['description']??''));
            $amount=finance_money_input($_POST['amount']??0);
            $due=(string)($_POST['due_date']??'');
            $accountId=trim((string)($_POST['account_id']??''))?:null;
            $documentNumber=trim((string)($_POST['document_number']??''))?:null;
            $notes=trim((string)($_POST['notes']??''))?:null;
            if($accountId&&!finance_account_for($pdo,$cid,$accountId))throw new RuntimeException('Conta financeira inválida.');
            if($supplier===''||$desc===''||$amount<=0||$due==='')throw new RuntimeException('Preencha fornecedor, descrição, valor e vencimento.');

            $payableId=app_uuid();
            $pdo->prepare("INSERT INTO finance_payables(id,church_id,account_id,supplier,description,amount,due_date,status,document_number,notes) VALUES(?,?,?,?,?,?,?,'open',?,?)")
                ->execute([$payableId,$cid,$accountId,$supplier,$desc,$amount,$due,$documentNumber,$notes]);

            finance_attach_document(
                $pdo,$uploader,$_FILES['document_file']??[],$cid,null,$payableId,
                (string)($_POST['document_type']??'invoice'),$documentNumber,null,$notes,$_SESSION['user_id']??null
            );

            flash('success','Conta a pagar cadastrada.');
            redirect('/finance.php');
        }

        if($action==='mark_paid'){
            $id=(string)($_POST['id']??'');
            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare("SELECT * FROM finance_payables WHERE id=? AND church_id=? FOR UPDATE");$q->execute([$id,$cid]);$p=$q->fetch();
                if(!$p)throw new RuntimeException('Conta a pagar não encontrada.');
                $paidDate=(string)($_POST['paid_at']??date('Y-m-d'));
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
            $year=(int)($_POST['year']??date('Y'));
            $month=(int)($_POST['month']??date('n'));
            $category=trim((string)($_POST['category']??''));
            $amount=finance_money_input($_POST['planned_amount']??0);
            if($year<2020||$month<1||$month>12||$category===''||$amount<0)throw new RuntimeException('Orçamento inválido.');
            $q=$pdo->prepare('INSERT INTO finance_budgets(id,church_id,year,month,category,planned_amount) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE planned_amount=VALUES(planned_amount),updated_at=NOW()');
            $q->execute([app_uuid(),$cid,$year,$month,$category,$amount]);
            flash('success','Orçamento salvo.');
            redirect('/finance.php');
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

$q=$pdo->prepare('SELECT e.*,a.name account_name,(SELECT COUNT(*) FROM finance_documents d WHERE d.entry_id=e.id) document_count FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id WHERE e.church_id=? AND e.transaction_date BETWEEN ? AND ? ORDER BY e.transaction_date DESC,e.created_at DESC LIMIT 500');
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

$q=$pdo->prepare("SELECT direction,category,SUM(amount) total FROM finance_entries WHERE church_id=? AND status='posted' AND transaction_date BETWEEN ? AND ? GROUP BY direction,category ORDER BY direction, total DESC");
$q->execute([$cid,$from,$to]);$categoryTotals=$q->fetchAll();

$categories=['Dízimos','Ofertas','Missões','Campanhas','Doações','Eventos','Rifas','Ação social','Água','Energia','Internet','Aluguel','Material de limpeza','Manutenção','Construção','Som e mídia','Transporte','Alimentação','Material de escritório','Impostos e taxas','Contas a pagar','Outros'];
$methods=['Dinheiro','PIX manual','Transferência','Cartão','Boleto','Cheque','Outro'];
$docLabels=['fiscal_coupon'=>'Cupom fiscal','invoice'=>'Nota fiscal','receipt'=>'Recibo','proof'=>'Comprovante','contract'=>'Contrato','other'=>'Outro'];

View::header('Tesouraria',$auth,'finance.php');
?>
<?php View::flash(); ?>

<div class="module-hero">
  <div><span class="eyebrow">Gestão financeira</span><h1>Tesouraria</h1><p>Livro caixa, entradas, saídas, documentos fiscais, recibos numerados, contas a pagar, orçamento, conciliação e relatórios profissionais.</p></div>
  <div class="actions">
    <a class="btn btn-light" target="_blank" href="/report.php?type=finance&from=<?=e($from)?>&to=<?=e($to)?>&format=print">🖨️ Imprimir relatório</a>
    <a class="btn btn-primary" href="/report.php?type=finance&from=<?=e($from)?>&to=<?=e($to)?>&format=pdf">PDF do período</a>
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
  <div class="page-head"><div><h2 style="font-size:16px">Movimentações</h2><p>Livro financeiro do período</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Favorecido/Pagador</th><th>Conta</th><th>Valor</th><th>Docs.</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach($entries as $r):?>
    <tr>
      <td><?=date('d/m/Y',strtotime($r['transaction_date']))?></td>
      <td><span class="badge <?=$r['direction']==='income'?'ok':'off'?>"><?=e($r['direction']==='income'?'Entrada':'Saída')?></span></td>
      <td><?=e($r['category'])?></td>
      <td><strong><?=e($r['description'])?></strong><?php if($r['document_number']):?><div class="muted">Doc. <?=e($r['document_number'])?></div><?php endif?></td>
      <td><?=e($r['counterparty']?:'—')?></td>
      <td><?=e($r['account_name']?:'—')?></td>
      <td><strong><?=$r['direction']==='income'?'+':'-'?> R$ <?=number_format((float)$r['amount'],2,',','.')?></strong></td>
      <td><?=e((string)$r['document_count'])?></td>
      <td><?php if($r['status']==='posted'):?><span class="badge ok">Ativo</span><?php else:?><span class="badge off">Estornado</span><?php endif?></td>
      <td>
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

<section class="card card-pad form-card">
  <div class="section-title"><span>＋</span><div><h2>Novo lançamento</h2><p>Registre entrada ou saída e anexe o documento correspondente.</p></div></div>
  <p class="muted">Entrada ou saída manual com comprovante/cupom opcional.</p>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="entry">
    <div class="form-grid">
      <div class="field"><label>Tipo</label><select class="select" name="direction"><option value="income">Entrada</option><option value="expense">Saída</option></select></div>
      <div class="field"><label>Data</label><input class="input" type="date" name="transaction_date" value="<?=date('Y-m-d')?>"></div>
      <div class="field full"><label>Conta</label><select class="select" name="account_id"><option value="">Sem conta específica</option><?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div>
      <div class="field"><label>Categoria *</label><input class="input" name="category" list="finance-categories" required></div>
      <div class="field"><label>Valor *</label><input class="input" name="amount" inputmode="decimal" placeholder="0,00" required></div>
      <div class="field full"><label>Descrição *</label><input class="input" name="description" required></div>
      <div class="field"><label>Favorecido / Pagador</label><input class="input" name="counterparty"></div>
      <div class="field"><label>Forma de pagamento</label><select class="select" name="payment_method"><option value="">Não informar</option><?php foreach($methods as $m):?><option><?=e($m)?></option><?php endforeach?></select></div>
      <div class="field"><label>Nº documento / cupom</label><input class="input" name="document_number"></div>
      <div class="field"><label>Tipo do anexo</label><select class="select" name="document_type"><?php foreach($docLabels as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach?></select></div>
      <div class="field full"><label>Cupom / nota / comprovante</label><input class="input" type="file" name="document_file" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="help-text">PDF, JPG, PNG ou WEBP até 12 MB.</small></div>
      <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"></textarea></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:12px">Registrar lançamento</button>
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

<section class="card card-pad">
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

<section class="card card-pad">
  <h2 style="font-size:16px">Emitir recibo</h2>
  <p class="muted">Ao emitir, o valor também é lançado automaticamente como entrada.</p>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="receipt">
    <div class="form-grid">
      <div class="field full"><label>Recebemos de *</label><input class="input" name="payer_name" required></div>
      <div class="field"><label>CPF/CNPJ (opcional)</label><input class="input" name="payer_document"></div>
      <div class="field"><label>Valor *</label><input class="input" name="amount" required></div>
      <div class="field full"><label>Referente a *</label><input class="input" name="description" required placeholder="Ex.: Oferta para reforma do templo"></div>
      <div class="field"><label>Data</label><input class="input" type="date" name="received_at" value="<?=date('Y-m-d')?>"></div>
      <div class="field"><label>Forma de pagamento</label><select class="select" name="payment_method"><option value="">Não informar</option><?php foreach($methods as $m):?><option><?=e($m)?></option><?php endforeach?></select></div>
      <div class="field"><label>Categoria</label><input class="input" name="category" list="finance-categories" value="Ofertas"></div>
      <div class="field"><label>Conta</label><select class="select" name="account_id"><option value="">Sem conta específica</option><?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div>
      <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"></textarea></div>
    </div>
    <button class="btn btn-primary" style="width:100%;margin-top:10px">Emitir recibo e registrar entrada</button>
  </form>
</section>
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

<section class="card card-pad">
  <h2 style="font-size:16px">Nova conta a pagar</h2>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="payable">
    <div class="form-grid">
      <div class="field full"><label>Fornecedor *</label><input class="input" name="supplier" required></div>
      <div class="field full"><label>Descrição *</label><input class="input" name="description" required></div>
      <div class="field"><label>Valor *</label><input class="input" name="amount" inputmode="decimal" required></div>
      <div class="field"><label>Vencimento *</label><input class="input" type="date" name="due_date" required></div>
      <div class="field"><label>Nº nota/cupom</label><input class="input" name="document_number"></div>
      <div class="field"><label>Conta prevista</label><select class="select" name="account_id"><option value="">Definir depois</option><?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div>
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
  <div class="table-wrap"><table class="table"><thead><tr><th>Conta</th><th>Tipo</th><th>Saldo inicial</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr><td><strong><?=e($a['name'])?></strong></td><td><?=e($a['account_type']==='bank'?'Banco':'Caixa')?></td><td>R$ <?=number_format((float)$a['opening_balance'],2,',','.')?></td></tr><?php endforeach?></tbody></table></div>
  <form method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="account"><div class="form-grid"><div class="field full"><label>Nome *</label><input class="input" name="name" placeholder="Caixa da igreja / Banco..." required></div><div class="field"><label>Tipo</label><select class="select" name="account_type"><option value="cash">Caixa</option><option value="bank">Banco</option></select></div><div class="field"><label>Saldo inicial</label><input class="input" name="opening_balance" value="0,00"></div></div><button class="btn btn-primary" style="margin-top:10px">Criar conta</button></form>
</section>

<section class="card card-pad">
  <h2 style="font-size:16px">Orçamento mensal</h2><p class="muted"><?=sprintf('%02d/%04d',$bmonth,$byear)?></p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Categoria</th><th>Planejado</th></tr></thead><tbody><?php foreach($budgets as $b):?><tr><td><?=e($b['category'])?></td><td>R$ <?=number_format((float)$b['planned_amount'],2,',','.')?></td></tr><?php endforeach?></tbody></table></div>
  <form method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="budget"><input type="hidden" name="year" value="<?=$byear?>"><input type="hidden" name="month" value="<?=$bmonth?>"><div class="form-grid"><div class="field"><label>Categoria *</label><input class="input" name="category" list="finance-categories" required></div><div class="field"><label>Valor planejado *</label><input class="input" name="planned_amount" required></div></div><button class="btn btn-primary" style="margin-top:10px">Salvar orçamento</button></form>
</section>
</div>

<div class="grid two-col" style="margin-top:16px">
<section class="card card-pad">
  <h2 style="font-size:16px">Conciliação bancária</h2>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reconcile"><div class="form-grid"><div class="field full"><label>Conta *</label><select class="select" name="account_id" required><option value="">Selecione</option><?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>"><?=e($a['name'])?></option><?php endforeach?></select></div><div class="field"><label>Data de referência</label><input class="input" type="date" name="reference_date" value="<?=date('Y-m-d')?>"></div><div class="field"><label>Saldo do extrato *</label><input class="input" name="statement_balance" required></div></div><button class="btn btn-primary" style="margin-top:10px">Conferir saldo</button></form>
  <h3 style="font-size:13px;margin-top:18px">Últimas conferências</h3>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Conta</th><th>Diferença</th><th>Status</th></tr></thead><tbody><?php foreach($reconciliations as $r):?><tr><td><?=date('d/m/Y',strtotime($r['reference_date']))?></td><td><?=e($r['account_name'])?></td><td>R$ <?=number_format((float)$r['difference_amount'],2,',','.')?></td><td><span class="badge <?=$r['status']==='reconciled'?'ok':''?>"><?=e($r['status']==='reconciled'?'Conciliado':'Revisar')?></span></td></tr><?php endforeach?></tbody></table></div>
</section>

<section class="card card-pad">
  <h2 style="font-size:16px">Fechamento de caixa</h2>
  <p class="muted">Congele uma fotografia do caixa por dia ou por mês. O sistema calcula saldo inicial, entradas, saídas e saldo final.</p>
  <form method="post">
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

<datalist id="finance-categories"><?php foreach($categories as $c):?><option value="<?=e($c)?>"><?php endforeach?></datalist>

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

<?php View::footer();?>
