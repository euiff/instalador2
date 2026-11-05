"""Cumulative 3.7.22: money belongs to the cashbox selected by the posting area,
never to the donor's member/department associations.
Every replacement has a strict anchor to avoid changing unseen legacy modules.
"""
def once(t,old,new,why):
    count=t.count(old)
    if count!=1:raise RuntimeError("Scope patch "+why+": anchor count="+str(count))
    return t.replace(old,new,1)

def finance_page(source:bytes)->bytes:
    t=source.decode("utf-8")
    t=once(t,"require_once __DIR__.'/lib/FinanceOperations.php';",
       "require_once __DIR__.'/lib/FinanceOperations.php';\nrequire_once __DIR__.'/lib/GeneralTreasuryAccountScope.php';",
       "finance helper include")
    # Do not apply this check to reporting, consolidation, closing/reconciliation:
    # General cashier reporting may display every cashbox, but POSTING uses
    # only accounts with department_id IS NULL.
    old="if($accountId&&!finance_account_for($pdo,$cid,$accountId))throw new RuntimeException('Conta financeira inválida.');"
    if t.count(old)!=4:raise RuntimeError("Unexpected old general cashier account checks: "+str(t.count(old)))
    t=t.replace(old,"GeneralTreasuryAccountScope::assertGeneral($pdo,$cid,$accountId);",3)
    old="$departmentId=$accountId?(finance_account_for($pdo,$cid,$accountId)['department_id']??null):null;"
    if t.count(old)!=2:raise RuntimeError("Unexpected entry and receipt department assignments")
    t=t.replace(old,"$departmentId=null; // General cashier postings never inherit the donor's department.",2)
    t=once(t,
        "if($p['status']==='paid')throw new RuntimeException('Esta conta já está paga.');",
        "if($p['status']==='paid')throw new RuntimeException('Esta conta já está paga.');\n"
        "                GeneralTreasuryAccountScope::assertGeneral($pdo,$cid,(string)($p['account_id']??''));",
        "payable payment must not move a department cashbox from general")

    t=once(t,
        "$q->execute([$cid]);$accounts=$q->fetchAll();",
        "$q->execute([$cid]);$accounts=$q->fetchAll();\n"
        "$generalAccounts=array_values(array_filter($accounts,"
        "static fn(array $a)=>empty($a['department_id'])));",
        "general-only form accounts")

    # Explicitly select the only three General cashier money-in/money-out
    # forms. Keep other account dropdowns intact for consolidated reporting.
    old='<label>Conta</label><select class="select" name="account_id"><option value="">Sem conta específica</option><?php foreach($accounts as $a):?>'
    if t.count(old)!=2:raise RuntimeError("Entry and donation receipt account selectors changed")
    t=t.replace(old,'<label>Conta geral</label><select class="select" name="account_id"><option value="">Caixa geral sem conta específica</option><?php foreach($generalAccounts as $a):?>',2)
    old='<label>Conta prevista</label><select class="select" name="account_id"><option value="">Definir depois</option><?php foreach($accounts as $a):?>'
    t=once(t,old,
        '<label>Conta geral prevista</label><select class="select" name="account_id"><option value="">Definir depois</option><?php foreach($generalAccounts as $a):?>',
        "new payable accounts")

    t=once(t,
      '<p class="muted">Entrada ou saída manual com comprovante/cupom opcional.</p>',
      '<p class="muted"><strong>Este lançamento pertence à Tesouraria Geral.</strong> '
      'A congregação ou o departamento do membro contribuinte não altera o destino da oferta/dízimo. '
      'Para lançar no caixa de Jovens, Louvor ou outro ministério, use <a href="/department-treasury.php">Tesourarias dos Departamentos</a>.</p>',
      "general posting explanation")
    old='<small>Vincule uma entrada para que ela apareça no portal pessoal deste membro.</small>'
    if t.count(old)!=2:raise RuntimeError("General contribution member explanation changed")
    t=t.replace(old,'<small>O vínculo identifica quem contribuiu e mostra a entrada no Portal do Membro. <strong>Não transfere o valor para o departamento do membro.</strong></small>',2)
    t=once(t,'<p>Livro financeiro do período</p>',
        '<p>Consolidado da igreja: Tesouraria Geral + caixas departamentais, sem duplicação. '
        'As entradas do Geral não pertencem ao departamento do membro contribuinte.</p>',
        "general ledger consolidated label")
    return t.encode("utf-8")

def finance_operations(source:bytes)->bytes:
    t=source.decode("utf-8")
    t=once(t,"require_once __DIR__.'/RegistryService.php';",
       "require_once __DIR__.'/RegistryService.php';\nrequire_once __DIR__.'/GeneralTreasuryAccountScope.php';",
       "finance operations include")
    marker="    public function references(array $input): array {"
    insert="""    /**
     * Only used by general-treasury posting flows. Reporting still uses
     * account() so administrators can reconcile/read consolidated accounts.
     */
    public function generalAccount(?string $id):?array {
        $this->guard();
        return GeneralTreasuryAccountScope::assertGeneral($this->pdo,$this->cid,$id);
    }
"""
    t=once(t,marker,insert+marker,"general account method")
    t=once(t,
       "$account=trim((string)($input['account_id']??''))?:null;$this->account($account);",
       "$account=trim((string)($input['account_id']??''))?:null;$this->generalAccount($account);",
       "receivable creation only general")
    t=once(t,
       "$accountId=trim((string)($input['account_id']??''))?:($r['account_id']?:null);$account=$this->account($accountId);",
       "$accountId=trim((string)($input['account_id']??''))?:($r['account_id']?:null);$this->generalAccount($accountId);",
       "receivable payment only general")
    t=once(t,"$r['member_id'],$account['department_id']??null,$r['cost_center_id']",
        "$r['member_id'],null,$r['cost_center_id']",
        "receivable cannot inherit ministry through account or donor")
    return t.encode("utf-8")

def finance_csv(source:bytes)->bytes:
    t=source.decode("utf-8")
    t=once(t,
       "$sql='SELECT id FROM finance_accounts WHERE church_id=? AND active=1 AND name=?'",
       "$sql='SELECT id FROM finance_accounts WHERE church_id=? AND active=1 AND department_id IS NULL AND name=?'",
       "CSV name only general account")
    t=once(t,
       "$account=$ops->account($row['account_id']??null);$ops->references",
       "$ops->generalAccount($row['account_id']??null);$ops->references",
       "CSV revalidate tamper-proof general account")
    t=once(t,
       "$row['member_id'],$account['department_id']??null,$row['cost_center_id']",
       "$row['member_id'],null,$row['cost_center_id']",
       "CSV income never follows donor into department")
    return t.encode("utf-8")

def finance_control(source:bytes)->bytes:
    t=source.decode("utf-8")
    return once(t,
       "SELECT id,name FROM finance_accounts WHERE church_id=? AND active=1 ORDER BY name",
       "SELECT id,name FROM finance_accounts WHERE church_id=? AND active=1 AND department_id IS NULL ORDER BY name",
       "general receivables show only general accounts").encode("utf-8")

def finance_import_page(source:bytes)->bytes:
    t=source.decode("utf-8")
    return once(t,
       "<p>Envie um CSV, confira a prévia e confirme a gravação.</p>",
       "<p>Importações financeiras nesta área pertencem ao Geral, mesmo quando o membro contribuinte participa de Jovens ou outro ministério. Para movimentações de ministérios, utilize a tesouraria do próprio departamento.</p>",
       "CSV origin explanation").encode("utf-8")

def accounting_service(source:bytes)->bytes:
    t=source.decode("utf-8")
    t=once(t,"declare(strict_types=1);","declare(strict_types=1);\nrequire_once __DIR__.'/GeneralTreasuryAccountScope.php';","accounting posting scope import")
    # Accounting OCR may still review departmental documents, but if a
    # reviewer POSTs a new expense from the general portal, its cashbox must
    # be General. The department has a separate authorized posting flow.
    old="$post=!empty($input['post_expense']);"
    if t.count(old)!=2:raise RuntimeError("Unexpected two accounting document posting flows")
    t=t.replace(old,"$post=!empty($input['post_expense']);",2)
    t=once(t,
       "if($post && (!$ready||$amount===null",
       "if($post){GeneralTreasuryAccountScope::assertGeneral($this->pdo,$this->churchId,$accountId);}\n"
       "            if($post && (!$ready||$amount===null",
       "OCR posted expense general only")
    t=once(t,
       "if($post&&(!$needsFields||(float)$amount<=0))",
       "if($post&&(!$needsFields||(float)$amount<=0))",
       "accounting second check present")
    # Insert after a confirmed account ID, not before it is assigned:
    old="if($post){\n            self::assertFinancePeriodOpen($this->pdo,$this->churchId,$issued);"
    t=once(t,old,
       "if($post){\n            GeneralTreasuryAccountScope::assertGeneral($this->pdo,$this->churchId,$accountId);\n"
       "            self::assertFinancePeriodOpen($this->pdo,$this->churchId,$issued);",
       "second accounting posted expense general only")
    return t.encode("utf-8")

def department_report_page(source:bytes)->bytes:
    t=source.decode("utf-8")
    return once(t,
       "Confira os caixas dos ministérios e acompanhe o saldo de cada um no período.",
       "Aqui aparecem apenas movimentações registradas nos caixas dos ministérios. "
       "Ofertas e dízimos lançados na Tesouraria Geral pertencem ao Geral, "
       "mesmo quando o contribuinte participa do departamento. O consolidado da igreja soma tudo uma única vez.",
       "department report helps interpret cashbox origin").encode("utf-8")
