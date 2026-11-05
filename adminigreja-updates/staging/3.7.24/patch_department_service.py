from patch_finance_core import replace_once

def patch_department_service(source):
    t=source.decode('utf8')
    t=replace_once(t,
      "require_once __DIR__.'/ExpenseReceiptService.php';",
      "require_once __DIR__.'/ExpenseReceiptService.php';\nrequire_once __DIR__.'/TreasurySmartEntry.php';",
      'department loader')
    t=replace_once(t,
      "        ExpenseReceiptService::ensureSchema($pdo);",
      "        ExpenseReceiptService::ensureSchema($pdo);\n        TreasurySmartEntry::ensureSchema($pdo);",
      'department schema')
    t=replace_once(t,
      "        if($category===''||$description==='')throw new InvalidArgumentException('Informe a categoria e a descrição do movimento.');",
      """        $memberId=TreasurySmartEntry::validMember($this->pdo,$this->churchId,
            (string)($data['member_id']??''));
        TreasurySmartEntry::validateMember($direction,$category,$memberId);
        $description=TreasurySmartEntry::normalizeDescription($direction,$category,$description);
        $contactId=TreasurySmartEntry::validReference($this->pdo,$this->churchId,'contact',
            (string)($data['contact_id']??''));
        $costCenterId=TreasurySmartEntry::validReference($this->pdo,$this->churchId,'cost_center',
            (string)($data['cost_center_id']??''));
        $notes=mb_substr(trim((string)($data['notes']??'')),0,4000);""",
      'department members, descriptions and references')
    t=replace_once(t,
      "        $hasFile=(int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;",
      """        $hasFile=(int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
        $items=TreasurySmartEntry::reviewedItems($data);
        if($items&&(!$hasFile||$direction!=='expense'))
            throw new InvalidArgumentException('Anexe a foto original da despesa e confirme a leitura dos produtos.');""",
      'department reviewed OCR')
    original='''                "INSERT INTO finance_entries
                    (id,church_id,account_id,department_id,direction,category,description,amount,transaction_date,
                     status,source,created_by_user_id,counterparty,payment_method,document_number,notes)
                 VALUES(?,?,?,?,?,?,?,?,?,'posted','department',?,?,?,?,?)",
                [$entryId,$this->churchId,$accountId,$departmentId,$direction,$category,$description,$money,$date,
                    $this->userId,$counterparty?:null,$payment?:null,$documentNumber?:null,'Lançamento pelo caixa departamental']'''
    updated='''                "INSERT INTO finance_entries
                    (id,church_id,account_id,department_id,member_id,cost_center_id,contact_id,direction,
                     category,description,amount,transaction_date,status,source,
                     created_by_user_id,counterparty,payment_method,document_number,notes)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'posted','department',?,?,?,?,?)",
                [$entryId,$this->churchId,$accountId,$departmentId,$memberId,$costCenterId,$contactId,
                    $direction,$category,$description,$money,$date,$this->userId,
                    $counterparty?:null,$payment?:null,$documentNumber?:null,$notes?:null]'''
    t=replace_once(t,original,updated,'department ledger extras without duplication')
    t=replace_once(t,
      "        $saved=null;$entryId=app_uuid();",
      "        $saved=null;$documentId=null;$entryId=app_uuid();",
      'receipt id')
    t=replace_once(t,
      '''                    [app_uuid(),$this->churchId,$entryId,
                        in_array((string)($data['document_type']??'proof'),''',
      '''                    [$documentId=app_uuid(),$this->churchId,$entryId,
                        in_array((string)($data['document_type']??'proof'),''',
      'department original receipt identifier')
    t=replace_once(t,
      '''            if($generateReceipt){
                $receipt=new ExpenseReceiptService''',
      '''            if($items)TreasurySmartEntry::saveItems($this->pdo,$this->churchId,
                $entryId,(string)$documentId,$this->userId,$items);
            if($generateReceipt){
                $receipt=new ExpenseReceiptService''',
      'department itemization')
    return t.encode('utf8')
