#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.9 updater from the checked 3.7.8 release.

Run only in the GitHub Actions staging pipeline; no production DB access.
"""
from __future__ import annotations
from pathlib import Path
from io import BytesIO
import base64, gzip, hashlib, json, re, subprocess, tarfile, tempfile, sys, os

ROOT=Path(__file__).resolve().parents[2]
STAGING=Path(__file__).resolve().parent
OUT=ROOT
VERSION="3.7.9"
PREV="3.7.8"


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
    assert sha(compressed)==manifest["sha256"],"3.7.8 compressed package integrity mismatch"
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
    item="            'accounting.php' => ['accounting','Portal do Contador','▥','accounting_portal'],\n"
    assert "'finance.php' =>" in txt, "Financial sidebar item missing"
    if "'accounting.php' =>" not in txt:
        txt=txt.replace("            'finance.php' =>",item+"            'finance.php' =>",1)
    lines=txt.splitlines(keepends=True)
    matched=False
    for i,line in enumerate(lines):
        if "'Financeiro'=>" in line and "'finance.php'" in line:
            if "'accounting.php'" not in line:lines[i]=line.replace("'finance.php'","'accounting.php','finance.php'",1)
            matched=True
            break
    if not matched:raise RuntimeError("Financial sidebar group missing")
    txt="".join(lines)
    assert "'accounting.php' =>" in txt
    return txt.encode("utf-8")


def modify_auth(source:bytes)->bytes:
    txt=source.decode("utf-8")
    old="'kids_worker','member'],true)?$role:'';"
    new="'kids_worker','member','accountant'],true)?$role:'';"
    if old not in txt and new not in txt:raise RuntimeError("Unexpected Auth::churchRole roster")
    txt=txt.replace(old,new,1)
    guard="        if($role==='')return false;"
    if guard not in txt:raise RuntimeError("Cannot locate Auth role guard")
    account_guard="        if($role==='accountant')return $ability==='accounting_portal';\n"
    if account_guard not in txt:txt=txt.replace(guard,account_guard+guard,1)
    ability="        if($ability==='accounting_portal')return in_array($role,['owner','admin','pastor','treasurer'],true);\n"
    if ability not in txt:txt=txt.replace(guard,guard+"\n"+ability,1)
    # Restricted accountant cannot gain finance via the legacy JSON permissions override.
    assert txt.index("if($role==='accountant')")<txt.index("$raw=$membership")
    return txt.encode("utf-8")


def modify_users(source:bytes)->bytes:
    txt=source.decode("utf-8")
    label="    'accountant'=>'Contador (consulta e conferência)',\n"
    if label not in txt:
        marker="    'treasurer'=>'Tesouraria',\n"
        if marker not in txt:raise RuntimeError("Cannot locate user role label")
        txt=txt.replace(marker,marker+label,1)
    # Include accountant among roles that owners and admins can create, never permit account transfer to Master.
    target="'treasurer','department_leader'"
    if txt.count(target)<2:raise RuntimeError("Cannot locate permitted role lists")
    txt=txt.replace(target,"'treasurer','accountant','department_leader'")
    assert "'accountant'=>'Contador" in txt
    return txt.encode("utf-8")


def modify_dashboard(source:bytes)->bytes:
    txt=source.decode("utf-8")
    redirect="if($auth->churchRole()==='accountant')redirect('/accounting.php');\n"
    if redirect not in txt:
        anchor="if($auth->churchRole()==='teacher')redirect('/learning.php');\n"
        if anchor not in txt:raise RuntimeError("Dashboard role redirect missing")
        txt=txt.replace(anchor,anchor+redirect,1)
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
    files["public/users.php"]=modify_users(files["public/users.php"])
    files["public/dashboard.php"]=modify_dashboard(files["public/dashboard.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Portal do Contador e comprovantes por foto\n"
    notes=(
        "- Portal exclusivo do contador por igreja com acesso somente de consulta, análise de comprovantes e solicitações.\n"
        "- Notas, cupons, extratos, contratos e documentos organizados por competência em armazenamento privado.\n"
        "- Foto do cupom com OCR local opcional, leitura de XML NF-e, itens categorizados como sugestão e revisão humana.\n"
        "- Integração opcional com livro caixa após confirmação; lançamento automático sem aprovação bloqueado.\n"
        "- Pacote ZIP mensal com movimentações, comprovantes, pendências e metadados de auditoria.\n"
        "- Migração 041 aditiva, sem alteração nem remoção dos movimentos financeiros existentes.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    must_have=[
      "public/accounting.php","public/accounting-file.php","public/accounting-parse.php","public/accounting-package.php",
      "public/lib/AccountingService.php","public/lib/ReceiptParser.php","public/database/041_accounting_portal.sql",
      "public/assets/accounting-ocr.js"
    ]
    for path in must_have:
        assert path in files,"Missing "+path
    assert b"requireAbility('accounting_portal')" in files["public/accounting.php"]
    assert b"currentChurch()" in files["public/accounting.php"]
    assert b"verify_csrf()" in files["public/accounting.php"]
    assert b"if($role==='accountant')return $ability==='accounting_portal'" in files["public/lib/Auth.php"]
    assert b"'accountant'=>'Contador" in files["public/users.php"]
    assert b"'accounting.php' =>" in files["public/lib/View.php"]
    assert b"church_id CHAR(36)" in files["public/database/041_accounting_portal.sql"]
    assert b"NOT EXISTS(SELECT 1 FROM finance_documents" in files["public/lib/AccountingService.php"]
    assert b"hash_file('sha256'" in files["public/lib/AccountingService.php"]
    assert b"movimentacoes.csv" in files["public/accounting-package.php"]
    assert b"post_expense" in files["public/accounting.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-379-") as d:
        for path,body in files.items():
            if not path.endswith(".php"):continue
            full=Path(d)/path;full.parent.mkdir(parents=True,exist_ok=True);full.write_bytes(body)
            p=subprocess.run(["php","-l",str(full)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            if p.returncode:raise RuntimeError("PHP syntax invalid "+path+" "+p.stdout)
        try:
            p=subprocess.run(["php",str(STAGING/"tests"/"parse_samples.php")],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            if p.returncode:raise RuntimeError("Receipt parser tests failed: "+p.stdout)
            print(p.stdout.strip())
            if os.getenv("TEST_ACCOUNTING_DSN"):
                env=dict(os.environ)
                env["ACCOUNTING_RELEASE_DIR"]=d
                p=subprocess.run(["php",str(STAGING/"tests"/"mysql_integration.php")],
                    env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,timeout=90)
                if p.returncode:raise RuntimeError("Accounting DB integration failed: "+p.stdout)
                print(p.stdout.strip())
        except FileNotFoundError:raise RuntimeError("PHP binary missing")
    print("PHP syntax checked",sum(x.endswith(".php") for x in files),"files")


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
      "published_at":"2026-11-02T22:55:00-03:00",
      "cache_buster":"3.7.9-accounting-portal",
      "notes":[
        "Portal do Contador com login individual e acesso restrito à igreja.",
        "Organização mensal de notas, cupons, comprovantes, extratos e contratos.",
        "Leitura automática de foto (OCR no navegador) e XML NF-e, com revisão manual obrigatória.",
        "Sugestão de categorias para itens e lançamento opcional na tesouraria após confirmação.",
        "Pacote ZIP contábil mensal: entradas, saídas, anexos e pendências.",
        "Migração 041 aditiva; preserva o financeiro e as versões anteriores."
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
