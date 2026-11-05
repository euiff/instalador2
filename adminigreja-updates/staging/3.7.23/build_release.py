#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.23 updater from the checked 3.7.22 release.

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
VERSION="3.7.23"
PREV="3.7.22"


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
    assert sha(compressed)==manifest["sha256"],"3.7.22 compressed package integrity mismatch"
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


from patch_payments import patch_payments

def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/payments.php"]=patch_payments(files["public/payments.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Auditoria geral, resumo PIX e proteção contra duplicidade\n"
    notes=(
       "- Auditoria automatizada de todas as 52 rotas PHP da atualização, incluindo verificações de permissão e CSRF.\n"
       "- Resumo da tela Pagamentos PIX agora calcula corretamente os totais confirmado, pendente e registrado, inclusive com mais de mil pagamentos.\n"
       "- Pagamento PIX de outra igreja não pode ser editado, e reconfirmação não duplica movimento no livro financeiro.\n"
       "- Validação de tipos de pagamentos e limites monetários nas operações manuais.\n"
       "- Testes cumulativos de membros, liderança, tesourarias, contabilidade, notas/recibos PDF, eventos, cursos, portais e isolamento entre igrejas.\n"
       "- Testes não executam envios WhatsApp/e-mail nem acessam a hospedagem real; a inspeção de núcleo legado requer ambiente de homologação.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=["public/payments.php","public/lib/PixPaymentSummary.php","public/finance.php",
       "public/lib/ExpenseReceiptPdf.php","public/lib/AccountingService.php",
       "public/portal.php","public/members.php","public/users.php","public/lib/Auth.php",
       "public/department-treasury.php","public/master.php","public/events.php",
       "public/visitors.php","public/pastoral-agenda.php","public/system-course.php",
       "public/kids.php","public/learning.php"]
    for name in required:assert name in files,name
    page=files["public/payments.php"]
    assert page.startswith(b"<?php") and b"require __DIR__" in page and b"REQUEST_METHOD" in page
    assert b"View::header('Pagamentos PIX'" in page and b"View::footer()" in page
    assert "Pagamento não encontrado nesta igreja".encode("utf8") in page
    assert b"PixPaymentSummary::totals" in page
    assert b"$pending=$summary[" in page
    assert b"pixEntryIds" in page
    assert b"FOR UPDATE" in page
    summary=files["public/lib/PixPaymentSummary.php"]
    assert b"quantity" in summary
    assert b"COALESCE(SUM" in summary
    assert b"church_id=?" in summary
    py=__import__('json')
    for k in ['public/data/system-course.json','public/data/system-course-images.json']:
        py.loads(files[k].decode('utf8'))
    with tempfile.TemporaryDirectory(prefix="igreja-master-3723-") as d:
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
                env=env,capture_output=True,text=True,timeout=240)
            if proc.returncode:raise RuntimeError("3.7.23 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("JS syntax checked",sum(name.endswith(".js") for name in files),"files")
    print("Security and workflow checks against 52 PHP routes and synthetic church fixtures passed")


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
      "cache_buster":"3.7.23-pix-workflow-full-audit",
      "notes":[
        "Auditoria geral dos módulos com dados fictícios e testes cumulativos das versões anteriores.",
        "Pagamentos PIX: corrigidos valores dos indicadores Registrado, Confirmado e Pendente.",
        "Indicadores PIX agora incluem todos os registros filtrados, não apenas as primeiras 1.000 linhas.",
        "Reconfirmação de pagamento PIX não cria lançamentos duplicados no caixa geral.",
        "Somente pagamentos da própria igreja podem ser editados; reforçada validação de valores e tipos.",
        "Regressões de membros, líderes, caixas, relatórios, notas, recibos PDF, contador, portais e cursos.",
        "WhatsApp, e-mail, navegador real e instalação cPanel requerem testes separados de homologação."
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
