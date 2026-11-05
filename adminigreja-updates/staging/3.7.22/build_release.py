#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.22 updater from the checked 3.7.21 release.

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
VERSION="3.7.22"
PREV="3.7.21"


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
    assert sha(compressed)==manifest["sha256"],"3.7.21 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.22 "+label+" anchor missing")
    return txt.replace(old,new,1)


from patch_finance import finance_page,finance_operations,finance_csv,finance_control,finance_import_page,accounting_service,department_report_page

def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    changes={
      "public/finance.php":finance_page,
      "public/lib/FinanceOperations.php":finance_operations,
      "public/lib/FinanceCsvImport.php":finance_csv,
      "public/finance-control.php":finance_control,
      "public/finance-import.php":finance_import_page,
      "public/lib/AccountingService.php":accounting_service,
      "public/department-report.php":department_report_page,
    }
    for name,transform in changes.items():
        files[name]=transform(files[name])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Dízimos e ofertas pertencem ao caixa que recebeu, não ao departamento do membro\n"
    notes=(
      "- Tesouraria Geral aceita apenas contas/caixas gerais para lançar entradas e saídas.\n"
      "- O departamento do membro contribuinte não altera o destino do dízimo ou da oferta.\n"
      "- A Tesouraria do Departamento registra as movimentações de Jovens, Louvor e outros setores apenas nos caixas do respectivo departamento.\n"
      "- O financeiro consolidado reúne Geral e departamentos, lendo cada lançamento apenas uma vez.\n"
      "- Também protegido: emissão de recibos, contas a pagar, recebimentos, importação CSV e despesa lançada por documento contábil.\n"
      "- Os lançamentos antigos permanecem intactos e podem ser auditados antes de eventual correção histórica.\n"
      "- A identificação do membro no portal de contribuições é independente do caixa que recebeu.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
      "public/lib/GeneralTreasuryAccountScope.php",
      "public/finance.php",
      "public/department-treasury.php",
      "public/lib/DepartmentTreasuryService.php",
      "public/lib/DepartmentReportService.php",
      "public/lib/FinanceCsvImport.php",
      "public/lib/FinanceOperations.php",
      "public/lib/AccountingService.php",
      "public/finance-control.php",
      "public/finance-import.php",
      "public/department-report.php",
    ]
    for name in required:assert name in files,name
    helper=files["public/lib/GeneralTreasuryAccountScope.php"]
    assert b"department_id" in helper
    assert b"AND id=? LIMIT 1" in helper
    assert b"Caixa" in helper or b"conta" in helper
    finance=files["public/finance.php"]
    assert b"GeneralTreasuryAccountScope::assertGeneral" in finance
    assert b"$generalAccounts" in finance
    assert b"$departmentId=null" in finance
    assert b"O v\xc3\xadnculo identifica" in finance
    assert b"Livro financeiro consolidado" not in finance  # design wording is in HTML
    assert b"Consolidado da igreja" in finance
    csv=files["public/lib/FinanceCsvImport.php"]
    assert b"generalAccount" in csv
    assert b"department_id IS NULL" in csv
    ops=files["public/lib/FinanceOperations.php"]
    assert b"generalAccount" in ops
    report=files["public/department-report.php"]
    assert b"Tesouraria Geral" in report
    with tempfile.TemporaryDirectory(prefix="igreja-master-3722-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=240)
            if proc.returncode:raise RuntimeError("3.7.22 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("General donor membership and ministry cashbox attribution verified")


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
      "cache_buster":"3.7.22-finance-general-vs-department",
      "notes":[
        "O dízimo ou oferta lançado na Tesouraria Geral pertence somente ao caixa Geral, mesmo se o membro pertence a Jovens ou outro departamento.",
        "Apenas lançamentos explícitos na Tesouraria dos Departamentos entram no caixa do ministério.",
        "Os formulários gerais não permitem escolher uma conta financeira de departamento.",
        "Regras aplicadas também a recibos, contas a pagar, recebíveis, importação CSV e despesas do portal contábil.",
        "O consolidado da igreja reúne uma única vez as movimentações gerais e departamentais.",
        "Todos os registros históricos são preservados; nenhuma movimentação existente é alterada."
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
