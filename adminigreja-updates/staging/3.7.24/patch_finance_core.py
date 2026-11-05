def replace_once(t,a,b,label):
 if t.count(a)!=1:raise RuntimeError('finance 3.7.24 '+label+': '+str(t.count(a)))
 return t.replace(a,b,1)

def patch_core(source):
 t=source.decode('utf8')
 t=replace_once(t,"require_once __DIR__.'/lib/ExpenseReceiptService.php';","require_once __DIR__.'/lib/ExpenseReceiptService.php';\nrequire_once __DIR__.'/lib/TreasurySmartEntry.php';",'loader')
 t=replace_once(t,'ExpenseReceiptService::ensureSchema($pdo);','ExpenseReceiptService::ensureSchema($pdo);\nTreasurySmartEntry::ensureSchema($pdo);','schema')
 t=replace_once(t,"$amount=finance_money_input($_POST['amount']??0);\n            $date=(string)($_POST['transaction_date']??date('Y-m-d'));\n            $desc=trim((string)($_POST['description']??''));\n            $category=trim((string)($_POST['category']??''));","$amount=TreasurySmartEntry::amount((string)($_POST['amount']??''));\n            $date=(string)($_POST['transaction_date']??date('Y-m-d'));\n            $category=trim((string)($_POST['category']??''));\n            $desc=TreasurySmartEntry::normalizeDescription($direction,$category,(string)($_POST['description']??''));",'entry amount and description')
 t=replace_once(t,"if($amount<=0||$desc===''||$category==='')throw new RuntimeException('Informe valor, categoria e descrição.');","if($amount<=0)throw new InvalidArgumentException('Informe valor positivo.');",'amount validation')
 return t.encode('utf8')
