#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.12 updater from the checked 3.7.11 release.

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
VERSION="3.7.12"
PREV="3.7.11"


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
    assert sha(compressed)==manifest["sha256"],"3.7.11 compressed package integrity mismatch"
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
    master_link="$nav['updates.php'] = ['updates','Atualizações','↻'];"
    links="\n            $nav['master-enuves.php'] = ['master-enuves','Importar Enuves','⇪'];\n            $nav['master-members.php'] = ['master-members','Gerenciar membros','♙'];"
    if master_link not in txt:raise RuntimeError("Master navigation link missing")
    if "$nav['master-enuves.php']" not in txt:
        txt=txt.replace(master_link,master_link+links,1)
    block="'updates.php'=>['updates','Atualizações','↻'],"
    adds="\n                    'master-enuves.php'=>['master-enuves','Importar Enuves','⇪'],\n                    'master-members.php'=>['master-members','Gerenciar membros','♙'],"
    if block not in txt:raise RuntimeError("Master-only navigation block missing")
    if "'master-enuves.php'=>[" not in txt:
        txt=txt.replace(block,block+adds,1)
    group="'Administração'=>"
    lines=txt.splitlines(keepends=True)
    found=False
    for i,line in enumerate(lines):
        if group in line and "'updates.php'" in line:
            if "'master-enuves.php'" not in line:
                lines[i]=line.replace("'updates.php'","'updates.php','master-enuves.php','master-members.php'",1)
            found=True
            break
    if not found:raise RuntimeError("Master administration menu section missing")
    return "".join(lines).encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Migração Enuves, departamentos e gestão de membros pelo Master\n"
    notes=(
        "- Importação de pessoas por planilha XLSX do Enuves, com prévia e confirmação explícita da igreja.\n"
        "- Protege membros existentes: evita duplicidade por ID externo, CPF ou nome+nascimento; sem sobrescrever dados existentes.\n"
        "- Preserva registros ativos e arquivados, telefones, endereços, batismo e informações complementares.\n"
        "- Sugere e cria departamentos a partir de interesses, sem inventar líderes ou dar acesso a áreas privadas.\n"
        "- Master pode designar líderes explicitamente e arquivar/restaurar membros com trilha de auditoria.\n"
        "- Exclusão definitiva é bloqueada quando existem vínculos, históricos, registros financeiros ou login.\n"
        "- Migração 042 aditiva, sem alterar os registros anteriores nem misturar igrejas.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    needed=["public/master-enuves.php","public/master-members.php",
       "public/lib/EnuvesXlsxReader.php","public/lib/EnuvesImportMap.php",
       "public/lib/EnuvesImportService.php","public/lib/EnuvesLeadershipService.php",
       "public/lib/MasterMemberService.php","public/database/042_enuves_migration.sql"]
    for path in needed:assert path in files,"Missing "+path
    view=files["public/lib/View.php"]
    assert b"master-enuves.php" in view and b"master-members.php" in view
    assert b"requireRole('super_admin')" in files["public/master-enuves.php"]
    assert b"requireRole('super_admin')" in files["public/master-members.php"]
    assert b"verify_csrf()" in files["public/master-enuves.php"]
    assert b"verify_csrf()" in files["public/master-members.php"]
    assert b"church_id=?" in files["public/lib/MasterMemberService.php"]
    assert b"FROM members WHERE church_id=?" in files["public/lib/EnuvesImportService.php"]
    assert b"CREATE TABLE IF NOT EXISTS enuves_import_people" in files["public/database/042_enuves_migration.sql"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3712-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                result=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if result.returncode:raise RuntimeError("Invalid PHP "+name+" "+result.stdout+result.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            result=subprocess.run(["php",str(STAGING/"tests"/"import_mysql.php")],
                env=env,capture_output=True,text=True,timeout=145)
            if result.returncode:raise RuntimeError("Enuves migration test failed:\n"+result.stdout+"\n"+result.stderr)
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
      "cache_buster":"3.7.12-master-enuves-migration",
      "notes":[
        "Importar membros do Enuves pelo painel Master, com prévia e seleção da igreja.",
        "Proteção contra duplicidade, 193 cadastros compatíveis e preservação de arquivados.",
        "Departamentos criados a partir de interesses voluntários; líderes somente com indicação explícita.",
        "Designação de líderes e vinculação de login já autorizado para o departamento.",
        "Arquivar e restaurar membros; excluir definitivamente somente sem vínculos e com confirmação.",
        "Atualização cumulativa 3.7.12; dados e tesouraria das igrejas preservados."
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
