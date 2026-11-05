#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.24 updater from the checked 3.7.23 release.

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
VERSION="3.7.24"
PREV="3.7.23"


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
    assert sha(compressed)==manifest["sha256"],"3.7.23 compressed package integrity mismatch"
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


from patch_finance_core import patch_core
from patch_finance_accounts import patch_accounts
from patch_finance_form import patch_form
from patch_finance_accounts_ui import patch_accounts_ui
from patch_finance_footer import patch_footer, expose_ocr_loader
from patch_department_service import patch_department_service
from patch_department_page import patch_department_page
from patch_accountant import patch_accountant

def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()

    files["public/finance.php"]=patch_footer(
        patch_accounts_ui(patch_form(patch_accounts(patch_core(files["public/finance.php"])))))
    files["public/lib/DepartmentTreasuryService.php"]=patch_department_service(
        files["public/lib/DepartmentTreasuryService.php"])
    files["public/department-treasury.php"]=patch_department_page(files["public/department-treasury.php"])
    files["public/accounting.php"]=patch_accountant(files["public/accounting.php"])
    files["public/assets/accounting-ocr.js"]=expose_ocr_loader(files["public/assets/accounting-ocr.js"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Tesouraria Inteligente: oito melhorias\n"
    notes=(
        "- Editar contas bancárias e caixa geral preservando histórico e saldo inicial após fechamento.\n"
        "- Categoria selecionável com busca e filtro entrada/saída, inclusive em celulares.\n"
        "- Valores em reais com vírgula decimal e teclado numérico mobile.\n"
        "- Dízimo/Oferta recebem descrição padrão e dízimos exigem vínculo do membro contribuinte.\n"
        "- Formulários separados por tipo, com contato rápido, centro de custo, anexo e recibo assinado.\n"
        "- Tesourarias dos ministérios preservam seus próprios caixas sem duplicar lançamentos na geral.\n"
        "- Leitura OCR de fotos sugere fornecedor, data, valor, categoria, produtos; revisão é obrigatória.\n"
        "- Produtos classificados e comprovante original disponibilizados ao contador por período.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
        "public/finance.php",
        "public/department-treasury.php",
        "public/lib/TreasurySmartEntry.php",
        "public/lib/DepartmentTreasuryService.php",
        "public/database/047_finance_entry_items.sql",
        "public/finance-contact-create.php",
        "public/finance-parse.php",
        "public/finance-ocr-items-csv.php",
        "public/accounting.php",
        "public/assets/treasury-smart.js",
        "public/assets/treasury-ocr.js",
        "public/assets/treasury-smart.css",
    ]
    for p in required:assert p in files,p
    assert b"data-smart-treasury" in files["public/finance.php"]
    assert b"data-smart-treasury" in files["public/department-treasury.php"]
    assert b"account_update" in files["public/finance.php"]
    assert b"TreasurySmartEntry::validateMember" in files["public/finance.php"]
    assert b"TreasurySmartEntry::validateMember" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"TreasurySmartEntry::saveItems" in files["public/finance.php"]
    assert b"TreasurySmartEntry::saveItems" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"window.IgrejaOCRLoad=loadTesseract;" in files["public/assets/accounting-ocr.js"]
    assert b"verify_csrf()" in files["public/finance-parse.php"]
    assert b"verify_csrf()" in files["public/finance-contact-create.php"]
    assert b"accounting_portal" in files["public/finance-ocr-items-csv.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3724-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
            if name.endswith(".js"):
                proc=subprocess.run(["node","--check",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("JS syntax failed "+name+": "+proc.stdout+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=260)
            if proc.returncode:raise RuntimeError("3.7.24 regression:\n"+proc.stdout+"\n"+proc.stderr)
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
      "cache_buster":"3.7.24-tesouraria-inteligente",
      "notes":[
        "Contas gerais: edição de nome/tipo e saldo inicial protegido após movimentações.",
        "Categorias pesquisáveis em listas para Entrada/Saída e teclado numérico para valores em reais.",
        "Dízimos/Ofertas com descrição automática, dízimos exigem membro contribuinte.",
        "Formulários simplificados por tipo, contato rápido, centro de custos, anexo e recibo assinado.",
        "Mesmas melhorias na tesouraria de cada departamento, sem duplicação no caixa geral.",
        "Foto de nota/cupom: OCR opcional sugere data, categoria, fornecedor, valor e produtos.",
        "Produtos conferidos vinculados ao documento original, consulta e exportação para o contador.",
        "Permissões por igreja e testes cumulativos antes da publicação."
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
