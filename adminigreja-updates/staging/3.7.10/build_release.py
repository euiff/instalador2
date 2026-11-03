#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.10 updater from the checked 3.7.9 release.

Run only in the GitHub Actions staging pipeline; no production DB access.
"""
from __future__ import annotations
from pathlib import Path
from io import BytesIO
import base64, gzip, hashlib, json, re, subprocess, tarfile, tempfile, sys, os

ROOT=Path(__file__).resolve().parents[2]
STAGING=Path(__file__).resolve().parent
OUT=ROOT
VERSION="3.7.10"
PREV="3.7.9"


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
    assert sha(compressed)==manifest["sha256"],"3.7.9 compressed package integrity mismatch"
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


def modify_view(source:bytes)->bytes:
    txt=source.decode("utf-8")
    item="            'department-treasury.php' => ['department-treasury','Tesourarias dos Departamentos','◈','department_treasury'],\n"
    if "'department-treasury.php' =>" not in txt:
        target="            'department-cash.php' =>"
        if target not in txt:raise RuntimeError("Cannot locate department-cash menu")
        txt=txt.replace(target,item+target,1)
    group="'Financeiro'=>"
    lines=txt.splitlines(keepends=True)
    found=False
    for i,line in enumerate(lines):
        if group in line and "'department-cash.php'" in line:
            if "'department-treasury.php'" not in line:
                lines[i]=line.replace("'department-cash.php'","'department-treasury.php','department-cash.php'",1)
            found=True
    if not found:raise RuntimeError("Cannot locate financial navigation group")
    return "".join(lines).encode("utf-8")


def modify_auth(source:bytes)->bytes:
    txt=source.decode("utf-8")
    old="|| ($role==='department_leader' && $ability==='department_portal')"
    new="|| ($role==='department_leader' && $ability==='department_portal')\n                || ($role==='department_leader' && $ability==='department_treasury')"
    if old not in txt and new not in txt:raise RuntimeError("Cannot locate restricted department leader abilities")
    txt=txt.replace(old,new,1)
    old="if($role==='accountant')return $ability==='accounting_portal';"
    new="if($role==='accountant')return in_array($ability,['accounting_portal','department_treasury'],true);"
    if old not in txt and new not in txt:raise RuntimeError("Cannot locate restricted accountant permissions")
    txt=txt.replace(old,new,1)
    anchor="if($ability==='accounting_portal')return in_array($role,['owner','admin','pastor','treasurer'],true);"
    extension="if($ability==='department_treasury')return in_array($role,['owner','admin','pastor','treasurer'],true);"
    if anchor not in txt:raise RuntimeError("Cannot locate accounting permission matrix")
    if extension not in txt:txt=txt.replace(anchor,anchor+"\n        "+extension,1)
    assert "department_treasury" in txt
    return txt.encode("utf-8")


def modify_dashboard(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="require __DIR__.'/lib/dashboard-care.php';"
    add="if($auth->can('finance'))require __DIR__.'/lib/dashboard-department-treasury.php';"
    if anchor not in txt:raise RuntimeError("Dashboard insertion point missing")
    if add not in txt:txt=txt.replace(anchor,anchor+"\n"+add,1)
    return txt.encode("utf-8")


def modify_portal_home(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="if($auth->can('department_portal'))"
    if anchor not in txt:raise RuntimeError("Department leader quick link missing")
    if 'Abrir tesouraria do departamento' not in txt:
        lines=txt.splitlines(keepends=True)
        found=False
        for i,line in enumerate(lines):
            if anchor in line and 'Caixa do Departamento' in line:
                extra="      <?php if($auth->can('department_treasury')):?><a href='/department-treasury.php'><strong>Abrir tesouraria do departamento</strong><small>Caixa, lançamentos e comprovantes</small></a><?php endif?>\\n"
                lines.insert(i+1,extra)
                found=True
                break
        if not found:raise RuntimeError("Portal home department link insertion failed")
        txt="".join(lines)
    return txt.encode("utf-8")


def modify_accounting_service(source:bytes)->bytes:
    txt=source.decode("utf-8")
    old="e.document_number,e.payment_method,e.counterparty,a.name account_name\n            FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id\n            WHERE e.church_id=?"
    new="e.document_number,e.payment_method,e.counterparty,a.name account_name,d.name department_name\n            FROM finance_entries e LEFT JOIN finance_accounts a ON a.id=e.account_id AND a.church_id=e.church_id\n            LEFT JOIN ministry_departments d ON d.church_id=e.church_id AND d.id=COALESCE(e.department_id,a.department_id)\n            WHERE e.church_id=?"
    if old not in txt and new not in txt:raise RuntimeError("Accountant ledger join mismatch")
    txt=txt.replace(old,new,1)
    return txt.encode("utf-8")


def modify_accounting_page(source:bytes)->bytes:
    txt=source.decode("utf-8")
    if "accounting-departments.php" in txt:return source
    marker='<section class="card card-pad acc-section" id="accounting-requests">'
    if marker not in txt:raise RuntimeError("Cannot insert departmental accountant breakdown")
    txt=txt.replace(marker,"<?php require __DIR__.'/lib/accounting-departments.php'; ?>\n"+marker,1)
    return txt.encode("utf-8")


def modify_accounting_package(source:bytes)->bytes:
    txt=source.decode("utf-8")
    inc="require_once __DIR__.'/lib/AccountingService.php';"
    if inc not in txt:raise RuntimeError("Accountant package bootstrap mismatch")
    ext="require_once __DIR__.'/lib/DepartmentTreasuryService.php';"
    if ext not in txt:txt=txt.replace(inc,inc+"\n"+ext,1)
    anchor="$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');"
    if anchor not in txt:raise RuntimeError("Cannot find accountant package period")
    addition="""$departmentService=new DepartmentTreasuryService($pdo,$auth,(string)$church['id'],(string)$user['id']);
    $departmentRows=$departmentService->summary($start,(new DateTimeImmutable($start))->format('Y-m-t'));"""
    if addition not in txt:txt=txt.replace(anchor,anchor+"\n    "+addition,1)
    old="'Conta','Contraparte','Método','Documento','Valor','Situação','ID'"
    new="'Conta','Departamento','Contraparte','Método','Documento','Valor','Situação','ID'"
    if old not in txt and new not in txt:raise RuntimeError("Cannot locate accountant CSV columns")
    txt=txt.replace(old,new,1)
    old="$e['description'],$e['category'],$e['account_name']??'',\n         $e['counterparty']"
    new="$e['description'],$e['category'],$e['account_name']??'',$e['department_name']??'',\n         $e['counterparty']"
    if old not in txt and new not in txt:raise RuntimeError("Accountant CSV rows do not match")
    txt=txt.replace(old,new,1)
    target="$zip->addFromString('contas-a-pagar.csv'"
    if target not in txt:raise RuntimeError("Cannot insert accountant department breakdown CSV")
    add="""$zip->addFromString('resumo-departamentos.csv',$csv(
       ['Departamento','Saldo anterior','Entradas do departamento (já no geral)','Saídas do departamento (já no geral)','Saldo final','Despesas sem comprovante'],
       array_map(static fn($d)=>[$d['name'],FinanceOperations::decimal($d['opening_cents']),
           FinanceOperations::decimal($d['income_cents']),FinanceOperations::decimal($d['expense_cents']),
           FinanceOperations::decimal($d['closing_cents']),$d['missing_proofs']],$departmentRows)));
    $zip->addFromString('caixas-departamentais.csv',$csv(
       ['Departamento','Situação','Saldo anterior','Entradas','Saídas','Saldo final'],
       array_map(static fn($d)=>[$d['name'],!empty($d['active'])?'Ativo':'Inativo',
           FinanceOperations::decimal($d['opening_cents']),FinanceOperations::decimal($d['income_cents']),
           FinanceOperations::decimal($d['expense_cents']),FinanceOperations::decimal($d['closing_cents'])],$departmentRows)));
    """
    if "resumo-departamentos.csv" not in txt:txt=txt.replace(target,add+target,1)
    readme='"Dados pessoais e financeiros: uso restrito ao contador autorizado.'
    note='"Os departamentos já estão inclusos nos totais do livro caixa. Não somar novamente entradas departamentais às entradas gerais.\\n".'
    # append without disturbing original text semantics
    if "Os departamentos já estão inclusos" not in txt:
        txt=txt.replace('        "Dados pessoais e financeiros: uso restrito ao contador autorizado.', '        "Os departamentos já estão inclusos nos totais do livro caixa. Não somar novamente.\\\\n".\n        "Dados pessoais e financeiros: uso restrito ao contador autorizado.',1)
    return txt.encode("utf-8")

def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/lib/Auth.php"]=modify_auth(files["public/lib/Auth.php"])
    files["public/dashboard.php"]=modify_dashboard(files["public/dashboard.php"])
    files["public/portal-home.php"]=modify_portal_home(files["public/portal-home.php"])
    files["public/lib/AccountingService.php"]=modify_accounting_service(files["public/lib/AccountingService.php"])
    files["public/accounting.php"]=modify_accounting_page(files["public/accounting.php"])
    files["public/accounting-package.php"]=modify_accounting_package(files["public/accounting-package.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Tesourarias departamentais integradas\n"
    notes=(
        "- Cada departamento utiliza um caixa próprio com acesso do líder vinculado.\n"
        "- Os lançamentos departamentais são gravados diretamente no livro caixa geral, uma única vez.\n"
        "- Pastor e tesouraria geral têm visão consolidada, com saldos por departamento.\n"
        "- Contador recebe discriminação dos departamentos e de suas despesas no pacote do mês.\n"
        "- Líder registra despesas mediante comprovante privado e pode exportar seu livro caixa.\n"
        "- Reusa tabelas existentes: atualização sem migração destrutiva.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    must=["public/department-treasury.php","public/lib/DepartmentTreasuryService.php",
          "public/lib/accounting-departments.php","public/lib/dashboard-department-treasury.php"]
    for name in must:assert name in files,name
    auth=files["public/lib/Auth.php"]
    assert b"department_treasury" in auth
    assert b"'department-treasury.php' =>" in files["public/lib/View.php"]
    assert b"department-treasury.php" in files["public/portal-home.php"]
    assert b"dashboard-department-treasury.php" in files["public/dashboard.php"]
    assert b"accounting-departments.php" in files["public/accounting.php"]
    assert b"resumo-departamentos.csv" in files["public/accounting-package.php"]
    assert b"department_name" in files["public/lib/AccountingService.php"]
    assert b"requireAbility('department_treasury')" in files["public/department-treasury.php"]
    assert b"COALESCE(e.department_id,a.department_id)" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"'department'" in files["public/lib/DepartmentTreasuryService.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3710-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                result=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if result.returncode:raise RuntimeError(name+" "+result.stdout+result.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            tests=STAGING/"tests"/"department_mysql.php"
            result=subprocess.run(["php",str(tests)],env=env,capture_output=True,text=True,timeout=110)
            if result.returncode:raise RuntimeError("Department ledger integration failed:\n"+result.stdout+"\n"+result.stderr)
            print(result.stdout.strip())
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
      "published_at":"2026-11-02T23:35:00-03:00",
      "cache_buster":"3.7.10-department-treasury",
      "notes":[
        "Tesourarias individuais para cada departamento, com acesso apenas aos líderes autorizados.",
        "Entradas e saídas dos departamentos integradas uma só vez na tesouraria geral.",
        "Pastor e tesoureiro visualizam saldos, movimentações e pendências por departamento.",
        "Portal do Contador recebe relatórios departamentais e categorias no pacote mensal.",
        "Anexos obrigatórios para despesas lançadas por líderes; documentos arquivados.",
        "Atualização cumulativa que preserva os módulos e lançamentos anteriores."
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
