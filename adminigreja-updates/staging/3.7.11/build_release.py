#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.11 updater from the checked 3.7.10 release.

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
VERSION="3.7.11"
PREV="3.7.10"


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
    assert sha(compressed)==manifest["sha256"],"3.7.10 compressed package integrity mismatch"
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
    item="            'management-center.php' => ['management-center','Central de Gestão 360','◈','dashboard'],\n"
    if "'management-center.php' =>" not in txt:
        target="            'dashboard.php' =>"
        if target not in txt:raise RuntimeError("Main dashboard link missing")
        txt=txt.replace(target,item+target,1)
    lines=txt.splitlines(keepends=True)
    group_found=False
    for i,line in enumerate(lines):
        if "'Visão geral'=>" in line and "'dashboard.php'" in line:
            if "'management-center.php'" not in line:
                lines[i]=line.replace("'dashboard.php'","'management-center.php','dashboard.php'",1)
            group_found=True
            break
    if not group_found:raise RuntimeError("Main navigation group missing")
    txt="".join(lines)
    head='<link rel="stylesheet" href="/assets/management.css?v=360">'
    new_head='<link rel="stylesheet" href="/assets/management-3711.css?v=3711">'
    if head not in txt:raise RuntimeError("Existing UI stylesheet include missing")
    if new_head not in txt:txt=txt.replace(head,head+"\n"+new_head,1)
    script='<script src="/assets/management.js?v=360" defer></script>'
    new_script='<script src="/assets/management-3711.js?v=3711" defer></script>'
    if script not in txt:raise RuntimeError("Existing UI javascript include missing")
    if new_script not in txt:txt=txt.replace(script,script+"\n"+new_script,1)
    return txt.encode("utf-8")


def modify_dashboard(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="<?php require __DIR__.'/lib/dashboard-care.php';"
    if anchor not in txt:raise RuntimeError("Dashboard widget anchor missing")
    if "Central de Gestão 360" not in txt:
        card="""<section class="im-dashboard-cta" aria-label="Central de Gestão 360">
  <div><strong>Conheça a Central de Gestão 360</strong><p>Encontre os módulos, acompanhe pendências e veja o que precisa de atenção em um único lugar.</p></div>
  <a class="btn btn-primary" href="/management-center.php">Abrir Central 360</a>
</section>
"""
        txt=txt.replace(anchor,card+anchor,1)
    return txt.encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/dashboard.php"]=modify_dashboard(files["public/dashboard.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Central de Gestão 360 e interface moderna\n"
    notes=(
        "- Nova central visual com indicadores, pendências, atalhos autorizados e agenda próxima.\n"
        "- Gráfico acessível de entradas e saídas dos últimos meses sem duplicação de departamentos.\n"
        "- Ações rápidas e busca por funções no painel, inclusive Ctrl+K no computador.\n"
        "- Visual responsivo para navegação e cadastros com cartões, tabelas e modais mais legíveis.\n"
        "- Preserva todos os módulos, permissões de pastores, líderes, membros e contadores.\n"
        "- Atualização apenas de interface e leituras de dados: não altera a estrutura financeira.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    must=["public/management-center.php","public/lib/ManagementOverviewService.php",
          "public/assets/management-3711.css","public/assets/management-3711.js"]
    for name in must:assert name in files,"Missing new feature "+name
    assert b"requireAbility('dashboard')" in files["public/management-center.php"]
    assert b"currentChurch()" in files["public/management-center.php"]
    assert b"'management-center.php' =>" in files["public/lib/View.php"]
    assert b"management-center.php" in files["public/dashboard.php"]
    assert b"management-3711.css" in files["public/lib/View.php"]
    assert b"management-3711.js" in files["public/lib/View.php"]
    assert b"im-app-grid" in files["public/management-center.php"]
    assert b"getelementbyid" not in files["public/assets/management-3711.js"].lower() or b"getElementById" in files["public/assets/management-3711.js"]
    assert b"church_id=?" in files["public/lib/ManagementOverviewService.php"]
    assert b"status='posted'" in files["public/lib/ManagementOverviewService.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3711-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                result=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if result.returncode:raise RuntimeError("PHP syntax invalid "+name+" "+result.stdout+result.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            tests=STAGING/"tests"/"overview_mysql.php"
            result=subprocess.run(["php",str(tests)],env=env,capture_output=True,text=True,timeout=120)
            if result.returncode:raise RuntimeError("Central 360 integration failed:\n"+result.stdout+"\n"+result.stderr)
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
      "published_at":datetime.now(timezone.utc).isoformat(),
      "cache_buster":"3.7.11-central-360-ui",
      "notes":[
        "Central de Gestão 360 com painéis modernos, indicadores e prioridades da igreja.",
        "Atalhos para membros, ministérios, grupos, finanças, Kids, eventos e contador segundo as permissões.",
        "Busca rápida de módulos e atalho Ctrl+K para localizar funções.",
        "Gráficos de entradas e saídas com dados reais do livro caixa sem duplicação de departamentos.",
        "Layout responsivo, modais e cadastros mais fáceis de utilizar.",
        "Atualização cumulativa e não destrutiva, mantendo dados e funcionalidades anteriores."
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
