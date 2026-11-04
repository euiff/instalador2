#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.16 updater from the checked 3.7.15 release.

Run only in the GitHub Actions staging pipeline; no production DB access.
"""
from __future__ import annotations
from pathlib import Path
from io import BytesIO
import base64, gzip, hashlib, json, re, subprocess, tarfile, tempfile, sys, os
from datetime import datetime, timezone

ROOT=Path(__file__).resolve().parents[2]
STAGING=Path(__file__).resolve().parent
OUT=ROOT
VERSION="3.7.16"
PREV="3.7.15"


def sha(b:bytes)->str:
    return hashlib.sha256(b).hexdigest()


def read_previous()->dict[str,bytes]:
    manifest=json.loads((OUT/"latest.json").read_text(encoding="utf-8"))
    # Build is intentionally idempotent; avoid rebuilding a published release.
    if manifest["version"]==VERSION:
        print("Already at",VERSION,"— release exists. Verify instead of rebuilding.")
        verify_release(manifest)
        return {}
    if manifest["version"]!=PREV:
        raise RuntimeError(f"Refusing to build from unknown version {manifest['version']}")
    encoded="".join((OUT/part).read_text(encoding="utf-8").strip() for part in manifest["parts"])
    compressed=base64.b64decode(encoded,validate=True)
    assert sha(compressed)==manifest["sha256"],"3.7.15 compressed package integrity mismatch"
    expected={entry["path"]:entry["sha256"] for entry in manifest["files"]}
    result={}
    with tarfile.open(fileobj=BytesIO(gzip.decompress(compressed)),mode="r:") as tf:
        for member in tf.getmembers():
            if not member.isfile():continue
            name=member.name.lstrip("./")
            if name in result:raise RuntimeError("Duplicate original path "+name)
            body=tf.extractfile(member).read()
            if name not in expected or sha(body)!=expected[name]:raise RuntimeError("Base file hash error "+name)
            result[name]=body
    if set(result)!=set(expected):raise RuntimeError("Incomplete base release")
    print("Verified original",PREV,"with",len(result),"files")
    return result


def verify_release(manifest:dict):
    encoded="".join((OUT/part).read_text(encoding="utf-8").strip() for part in manifest["parts"])
    compressed=base64.b64decode(encoded,validate=True)
    if sha(compressed)!=manifest["sha256"]:raise RuntimeError("Release archive SHA mismatch")
    expected={item["path"]:item["sha256"] for item in manifest["files"]}
    seen={}
    with tarfile.open(fileobj=BytesIO(gzip.decompress(compressed)),mode="r:") as tf:
        for item in tf.getmembers():
            if not item.isfile():continue
            name=item.name
            data=tf.extractfile(item).read()
            if name in seen or name not in expected or sha(data)!=expected[name]:
                raise RuntimeError("Release file mismatch "+name)
            seen[name]=data
    if set(seen)!=set(expected):raise RuntimeError("Manifest/file mismatch")
    print("Verified final",manifest["version"],len(seen),"files",len(compressed),"bytes")
    return seen


def once(txt:str,old:str,new:str,label:str)->str:
    if old not in txt:raise RuntimeError("3.7.16 "+label+" anchor missing")
    return txt.replace(old,new,1)


def replace_once(txt:str,old:str,new:str,label:str)->str:
    if txt.count(old)!=1:raise RuntimeError(f"Unsafe 3.7.16 {label}: expected one anchor, got {txt.count(old)}")
    return txt.replace(old,new,1)


RECEIPT_FIELDS='''<div class="expense-receipt-option" data-receipt-proof-choice hidden>
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
</div>'''


def modify_view(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor='<script src="/assets/password-visibility.js?v=3715" defer></script>'
    addon='''<link rel="stylesheet" href="/assets/expense-receipt.css?v=3716">
<script src="/assets/expense-receipt.js?v=3716" defer></script>'''
    txt=replace_once(txt,anchor,anchor+"\n"+addon,"shared receipt controls")
    return txt.encode("utf-8")


def modify_finance(source:bytes)->bytes:
    txt=source.decode("utf-8")
    txt=replace_once(txt,"require_once __DIR__.'/lib/FinanceOperations.php';",
        "require_once __DIR__.'/lib/FinanceOperations.php';\nrequire_once __DIR__.'/lib/ExpenseReceiptService.php';",
        "finance receipt dependency")
    txt=replace_once(txt,"$financeRegistry=new RegistryService($pdo,$auth,$cid);",
        "$financeRegistry=new RegistryService($pdo,$auth,$cid);\nExpenseReceiptService::ensureSchema($pdo);",
        "finance receipt schema")

    anchor="""            $financialRefs=$financeOps->references($_POST);"""
    prep="""            $proofMethod=(string)($_POST['proof_method']??'upload');
            $generateReceipt=$direction==='expense' && in_array($proofMethod,['receipt_print','receipt_screen'],true);
            if($generateReceipt){
                ExpenseReceiptService::validateInput($_POST);
                if((int)($_FILES['document_file']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK)
                    throw new InvalidArgumentException('Escolha anexar documento OU gerar recibo, não as duas opções.');
            }
            $financialRefs=$financeOps->references($_POST);"""
    if anchor not in txt:raise RuntimeError("General entry finance reference missing")
    txt=txt.replace(anchor,prep,1)  # First occurrence belongs to action=entry; later receipt action remains unchanged
    txt=replace_once(txt,"            $q=$pdo->prepare(\"INSERT INTO finance_entries(id,church_id,account_id,member_id,department_id,cost_center_id,contact_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,payment_method,document_number,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'posted','manual',?,?,?,?,?)\");",
        "            $pdo->beginTransaction();\n            try{\n            $q=$pdo->prepare(\"INSERT INTO finance_entries(id,church_id,account_id,member_id,department_id,cost_center_id,contact_id,direction,category,description,amount,transaction_date,status,source,created_by_user_id,counterparty,payment_method,document_number,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'posted','manual',?,?,?,?,?)\");",
        "transaction start")
    anchor="""            finance_attach_document(
                $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,null,
                (string)($_POST['document_type']??'proof'),$documentNumber,$date,$notes,$_SESSION['user_id']??null
            );

            flash('success',($direction==='income'?'Entrada':'Saída').' registrada no livro financeiro.');
            redirect('/finance.php');"""
    replacement="""            if($generateReceipt){
                $service=new ExpenseReceiptService($pdo,$auth,$cid,(string)($auth->requireLogin()['id']));
                $service->create($entryId,$_POST);
            }else{
                finance_attach_document(
                    $pdo,$uploader,$_FILES['document_file']??[],$cid,$entryId,null,
                    (string)($_POST['document_type']??'proof'),$documentNumber,$date,$notes,$_SESSION['user_id']??null
                );
            }
            $pdo->commit();
            }catch(Throwable $failure){if($pdo->inTransaction())$pdo->rollBack();throw $failure;}
            flash('success',($direction==='income'?'Entrada':'Saída').' registrada no livro financeiro.'.
                ($generateReceipt?' Recibo vinculado à despesa.':''));
            if($generateReceipt)redirect('/expense-receipt.php?entry='.rawurlencode($entryId));
            redirect('/finance.php');"""
    txt=replace_once(txt,anchor,replacement,"general expense receipt + transaction")
    orig="SELECT e.*,a.name account_name,(SELECT COUNT(*) FROM finance_documents d WHERE d.entry_id=e.id) document_count FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id WHERE e.church_id=?"
    new="SELECT e.*,a.name account_name,er.id expense_receipt_id,er.status expense_receipt_status,(SELECT COUNT(*) FROM finance_documents d WHERE d.church_id=e.church_id AND d.entry_id=e.id) document_count FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id LEFT JOIN expense_receipts er ON er.church_id=e.church_id AND er.entry_id=e.id WHERE e.church_id=?"
    txt=replace_once(txt,orig,new,"general ledger receipt link")
    anchor=r"(?m)^([ \t]*)<\?php if\(\$r\['status'\]==='posted'\):\?>"
    if len(re.findall(anchor,txt))!=1:raise RuntimeError("General ledger action cell missing")
    def receipt_action(m):
        indent=m.group(1)
        return indent+"""<?php if($r['direction']==='expense'):?>
"""+indent+"""<a class="btn btn-light" href="/expense-receipt.php?entry=<?=e($r['id'])?>"><?=e(!empty($r['expense_receipt_id'])?($r['expense_receipt_status']==='pending_signature'?'Recibo pendente':'Ver recibo'):'Gerar recibo')?></a>
"""+indent+"""<?php endif;?>
"""+m.group(0)
    txt=re.sub(anchor,receipt_action,txt,count=1)
    txt=replace_once(txt,
       """  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="entry">""",
       """  <form method="post" enctype="multipart/form-data" data-expense-receipt-form>
    <?=csrf_field()?><input type="hidden" name="action" value="entry">""",
        "general expense form selector")
    txt=replace_once(txt,
        '<div class="field full"><label>Cupom / nota / comprovante</label><input class="input" type="file" name="document_file" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="help-text">PDF, JPG, PNG ou WEBP até 12 MB.</small></div>',
        '<div class="field full" data-receipt-upload><label>Cupom / nota / comprovante</label><input class="input" type="file" name="document_file" accept="image/*,.pdf,application/pdf"><small class="help-text">Foto, PDF, JPG, PNG ou WEBP até 12 MB.</small></div>\n       <div class="field full">'+RECEIPT_FIELDS+'</div>',
        "general proof options")
    return txt.encode("utf-8")


def modify_department_service(source:bytes)->bytes:
    txt=source.decode("utf-8")
    txt=replace_once(txt,"require_once __DIR__.'/ReceiptParser.php';",
        "require_once __DIR__.'/ReceiptParser.php';\nrequire_once __DIR__.'/ExpenseReceiptService.php';",
        "department receipt dependency")
    anchor="""        $hasFile=(int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
        if($direction==='expense'&&!$hasFile&&!$this->fullAccess)
            throw new InvalidArgumentException('Anexe um comprovante (foto, PDF ou cupom) para registrar esta despesa.');"""
    replace="""        $hasFile=(int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
        $proofMethod=(string)($data['proof_method']??'upload');
        $generateReceipt=$direction==='expense'&&in_array($proofMethod,['receipt_screen','receipt_print'],true);
        if($generateReceipt){
            ExpenseReceiptService::validateInput($data);
            if($hasFile)throw new InvalidArgumentException('Escolha anexar comprovante OU gerar recibo.');
        }
        if($direction==='expense'&&!$hasFile&&!$generateReceipt&&!$this->fullAccess)
            throw new InvalidArgumentException('Anexe um comprovante ou gere um recibo para registrar esta despesa.');"""
    txt=replace_once(txt,anchor,replace,"department proof mode")
    anchor="""            $this->audit('department_cash_posted',["""
    repl="""            if($generateReceipt){
                $receipt=new ExpenseReceiptService($this->pdo,$this->auth,$this->churchId,$this->userId);
                $receipt->create($entryId,$data);
            }
            $this->audit('department_cash_posted',["""
    txt=replace_once(txt,anchor,repl,"department receipt record")
    txt=replace_once(txt,"'has_proof'=>(bool)$saved",
        "'has_proof'=>(bool)$saved||($generateReceipt&&$proofMethod==='receipt_screen')",
        "department receipt audit")
    pattern=r"(?m)^([ \t]*)EXISTS\(SELECT 1 FROM finance_documents fd WHERE fd\.church_id=e\.church_id AND fd\.entry_id=e\.id\) has_proof[ \t]*$"
    hits=list(re.finditer(pattern,txt))
    if len(hits)!=1:raise RuntimeError("Department ledger has_proof SQL anchor missing")
    m=hits[0]
    substitution=m.group(0)+""",
           (SELECT er.status FROM expense_receipts er WHERE er.church_id=e.church_id
               AND er.entry_id=e.id LIMIT 1) receipt_status"""
    txt=txt[:m.start()]+substitution+txt[m.end():]
    return txt.encode("utf-8")


def modify_department_page(source:bytes)->bytes:
    txt=source.decode("utf-8")
    txt=replace_once(txt,"$service=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);",
        "$service=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);\nExpenseReceiptService::ensureSchema($pdo);",
        "department schema")
    anchor="""            $service->createEntry($selectedId,$_POST,$_FILES['document_file']??[]);
            flash('success','Movimentação registrada na tesouraria do departamento e na geral, sem duplicidade.');"""
    repl="""            $entryId=$service->createEntry($selectedId,$_POST,$_FILES['document_file']??[]);
            flash('success','Movimentação registrada na tesouraria do departamento e na geral, sem duplicidade.');
            if(in_array((string)($_POST['proof_method']??''),['receipt_screen','receipt_print'],true)
               &&($_POST['direction']??'')==='expense')
                redirect('/expense-receipt.php?entry='.rawurlencode($entryId));"""
    txt=replace_once(txt,anchor,repl,"department receipt redirect")
    txt=replace_once(txt,"<td><?=$entry['has_proof']?'Anexado':'Pendente'?></td>",
        """<td><?=$entry['has_proof']?'Anexado':'Pendente'?>
        <?php if($entry['direction']==='expense'):?>
        <a class="btn btn-light" href="/expense-receipt.php?entry=<?=e($entry['id'])?>">
        <?=e(!empty($entry['receipt_status'])?($entry['receipt_status']==='pending_signature'?'Recibo: assinar':'Ver recibo'):'Gerar recibo')?></a><?php endif;?></td>""",
        "department ledger receipt button")
    txt=replace_once(txt,
        '<form method="post" enctype="multipart/form-data" id="dept-entry-form">',
        '<form method="post" enctype="multipart/form-data" id="dept-entry-form" data-expense-receipt-form>',
        "department expense form")
    txt=replace_once(txt,
        '<label class="wide">Foto, cupom ou PDF do comprovante<input type="file" name="document_file" id="dept-file" accept="image/*,.pdf,application/pdf" capture="environment"></label>',
        '<label class="wide" data-receipt-upload>Foto, cupom ou PDF do comprovante<input type="file" name="document_file" id="dept-file" accept="image/*,.pdf,application/pdf"></label>\n        <div class="wide">'+RECEIPT_FIELDS+'</div>',
        "department proof UI")
    return txt.encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/finance.php"]=modify_finance(files["public/finance.php"])
    files["public/lib/DepartmentTreasuryService.php"]=modify_department_service(files["public/lib/DepartmentTreasuryService.php"])
    files["public/department-treasury.php"]=modify_department_page(files["public/department-treasury.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Recibo de despesa com assinatura digitalizada ou impressão\n"
    notes=(
        "- Escolha entre anexo fiscal, recibo assinado na tela ou recibo para imprimir em despesas.\n"
        "- Recibo usa os dados reais da despesa e gera PDF com assinatura realizada pelo recebedor.\n"
        "- Recibo impresso não é prova final até a foto/PDF assinado ser anexado à mesma despesa.\n"
        "- Documento assinado fica protegido, visível para tesouraria geral e contador conforme suas permissões.\n"
        "- Lideranças e tesoureiros departamentais podem criar comprovantes somente de seus caixas.\n"
        "- Movimentações financeiras são mantidas no livro caixa existente sem duplicação.\n"
        "- Recibo não substitui nota fiscal legalmente exigida; assinatura em tela não autentica identidade automaticamente.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=["public/lib/ExpenseReceiptService.php","public/lib/ExpenseReceiptPdf.php",
      "public/expense-receipt.php","public/database/045_expense_receipts.sql",
      "public/assets/expense-receipt.js","public/assets/expense-receipt.css"]
    for p in required:assert p in files,p
    assert b"verify_csrf()" in files["public/expense-receipt.php"]
    assert b"ExpenseReceiptService::validateInput" in files["public/finance.php"]
    assert b"ExpenseReceiptService::validateInput" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"data-expense-receipt-form" in files["public/finance.php"]
    assert b"data-expense-receipt-form" in files["public/department-treasury.php"]
    assert b"receipt_status" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"receipt_screen" in files["public/lib/ExpenseReceiptService.php"]
    assert b"pending_signature" in files["public/database/045_expense_receipts.sql"]
    assert b"expense-receipt.js?v=3716" in files["public/lib/View.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3716-") as d:
        for name,data in files.items():
            p=Path(d)/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(p)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
        for name in ["public/assets/expense-receipt.js","public/assets/password-visibility.js"]:
            proc=subprocess.run(["node","--check",str(Path(d)/name)],capture_output=True,text=True)
            if proc.returncode:raise RuntimeError("JS syntax failed "+name+": "+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=190)
            if proc.returncode:raise RuntimeError("3.7.16 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")


def pack_release(files:dict[str,bytes]):
    fd=BytesIO()
    with tarfile.open(fileobj=fd,mode="w",format=tarfile.USTAR_FORMAT) as tf:
        for path,data in sorted(files.items()):
            if path.startswith("/") or ".." in path.split("/"):raise RuntimeError("Unsafe archive path")
            info=tarfile.TarInfo(path);info.size=len(data);info.mode=0o644;info.mtime=0
            tf.addfile(info,BytesIO(data))
    compressed=gzip.compress(fd.getvalue(),compresslevel=9,mtime=0)
    encoded=base64.b64encode(compressed).decode("ascii")
    chunks=[encoded[i:i+65000] for i in range(0,len(encoded),65000)]
    for i,chunk in enumerate(chunks,1):
        dest=OUT/"releases"/f"v{VERSION}.{i:02d}.b64.txt"
        dest.parent.mkdir(parents=True,exist_ok=True)
        dest.write_text(chunk+"\n",encoding="ascii")
    paths=[f"releases/v{VERSION}.{i:02d}.b64.txt" for i in range(1,len(chunks)+1)]
    manifest={
      "version":VERSION,"min_php":"8.1","encoding":"base64",
      "parts":paths,"sha256":sha(compressed),
      "published_at":datetime.now(timezone.utc).isoformat(),
      "cache_buster":"3.7.16-expense-receipts",
      "notes":[
        "Novo comprovante de despesa: anexar nota, gerar recibo e assinar na tela ou imprimir.",
        "Recibos em PDF com valor, motivo, recebedor, igreja, departamento e assinatura.",
        "Recibo impresso sem assinatura fica pendente; pode anexar foto/PDF assinado depois.",
        "Recibos assinados vinculados ao livro caixa e disponíveis para o contador.",
        "Mantém isolamento entre igrejas e departamentos, sem duplicar valores.",
        "Assinatura simples não substitui nota fiscal nem valida identidade automaticamente."
      ],
      "files":[{"path":name,"sha256":sha(data)} for name,data in sorted(files.items())]
    }
    (OUT/"latest.json").write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    verify_release(manifest)
    print("Built",VERSION,"file count",len(files),"parts",len(chunks))


if __name__=="__main__":
    old=read_previous()
    if old:
        files=modified_files(old)
        lint_and_assert(files)
        pack_release(files)
    else:
        m=json.loads((OUT/"latest.json").read_text())
        files=verify_release(m)
        lint_and_assert(files)
