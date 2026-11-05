def replace_once(t,a,b,label):
 if t.count(a)!=1:raise RuntimeError('finance 3.7.24 '+label+': '+str(t.count(a)))
 return t.replace(a,b,1)

def patch_core(source):
 t=source.decode('utf8')
 t=replace_once(t,"require_once __DIR__.'/lib/ExpenseReceiptService.php';","require_once __DIR__.'/lib/ExpenseReceiptService.php';\nrequire_once __DIR__.'/lib/TreasurySmartEntry.php';",'loader')
 t=replace_once(t,'ExpenseReceiptService::ensureSchema($pdo);','ExpenseReceiptService::ensureSchema($pdo);\nTreasurySmartEntry::ensureSchema($pdo);','schema')
 t=replace_once(t,"$amount=finance_money_input($_POST['amount']??0);\n            $date=(string)($_POST['transaction_date']??date('Y-m-d'));\n            $desc=trim((string)($_POST['description']??''));\n            $category=trim((string)($_POST['category']??''));","$amount=TreasurySmartEntry::amount((string)($_POST['amount']??''));\n            $date=(string)($_POST['transaction_date']??date('Y-m-d'));\n            $category=trim((string)($_POST['category']??''));\n            $desc=TreasurySmartEntry::normalizeDescription($direction,$category,(string)($_POST['description']??''));",'entry amount and description')
 t=replace_once(t,"if($amount<=0||$desc===''||$category==='')throw new RuntimeException('Informe valor, categoria e descrição.');","if($amount<=0)throw new InvalidArgumentException('Informe valor positivo.');",'amount validation')
 t=replace_once(t,
  "            $financialRefs=$financeOps->references($_POST);\n            $memberId=finance_person($pdo,$cid,$_POST);",
  "            $financialRefs=$financeOps->references($_POST);\n            $memberId=finance_person($pdo,$cid,$_POST);\n            TreasurySmartEntry::validateMember($direction,$category,$memberId);\n            $items=TreasurySmartEntry::reviewedItems($_POST);\n            if($items&&($direction!=='expense'||(int)($_FILES['document_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK))\n                throw new InvalidArgumentException('Para salvar os produtos lidos, anexe a foto original da despesa.');",
  'tithe required and OCR human review')
 t=replace_once(t,
  "                finance_attach_document(\n                    $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,null,\n                    (string)($_POST['document_type']??'proof'),$documentNumber,$date,$notes,$_SESSION['user_id']??null\n                );",
  "                $documentId=finance_attach_document(\n                    $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,null,\n                    (string)($_POST['document_type']??'proof'),$documentNumber,$date,$notes,$_SESSION['user_id']??null\n                );\n                if($items)TreasurySmartEntry::saveItems($pdo,$cid,$entryId,(string)$documentId,(string)($_SESSION['user_id']??''),$items);",
  'itemization joins uploaded proof')
 t=replace_once(t,
  "$registeredCategories=$financeRegistry->all('finance_category');\n$categories=array_values(array_unique([...$categories,...array_column($registeredCategories,'name')]));",
  "$registeredCategories=$financeRegistry->all('finance_category');\n$categoryCatalog=TreasurySmartEntry::categories($pdo,$cid);\n$categories=array_column($categoryCatalog,'name');",
  'direction-specific categories')
 return t.encode('utf8')
