#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.8 updater from the checked 3.7.7 release.

Run only in the GitHub Actions staging pipeline; no production DB access.
"""
from __future__ import annotations
from pathlib import Path
from io import BytesIO
import base64, gzip, hashlib, json, re, subprocess, tarfile, tempfile, sys

ROOT=Path(__file__).resolve().parents[2]
STAGING=Path(__file__).resolve().parent
OUT=ROOT
VERSION="3.7.8"
PREV="3.7.7"


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
    assert sha(compressed)==manifest["sha256"],"3.7.7 compressed package integrity mismatch"
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
    if "'pastoral-agenda.php' =>" in txt:return source
    rule=r"(?m)^(\s*'visitors\.php'\s*=>[^\n]*\n)"
    entry="            'pastoral-agenda.php' => ['pastoral-agenda','Agenda pastoral','♡','pastoral'],\n"
    txt,n=re.subn(rule,lambda m:m.group(1)+entry,txt,count=1)
    if n!=1:raise RuntimeError("Sidebar injection point missing in View.php")
    # Add new link to the established People section while retaining the old portal.
    group_pat=r"(?m)^(\s*'Pessoas e cadastros'\s*=>[^\n]*)"
    def add_group(m):
        line=m.group(1)
        if "'pastoral-agenda.php'" not in line:
            line=line.replace("'pastoral-care.php'","'pastoral-care.php','pastoral-agenda.php'")
        if "'pastoral-agenda.php'" not in line:
            line=line.replace("'visitors.php'","'visitors.php','pastoral-agenda.php'")
        return line
    txt,n=re.subn(group_pat,add_group,txt,count=1)
    if n!=1:raise RuntimeError("People menu section missing")
    assert "'pastoral-agenda.php' =>" in txt
    assert "'pastoral-agenda.php'" in txt.split("'Pessoas e cadastros'",1)[1].split("\n",1)[0]
    return txt.encode("utf-8")


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
    heading=f"## {VERSION} — Agenda pastoral e retornos\n"
    notes=(
        "- Agenda pastoral por igreja, com cadastro em janela flutuante, prioridades e responsáveis.\n"
        "- Histórico confidencial de contatos, visitas e agendamentos de retorno.\n"
        "- Painel de pendências e atrasos, filtros, paginação e conclusão.\n"
        "- Migração 040 compatível com os UUIDs e permissões do sistema.\n"
        "- Preserva os módulos anteriores e mantém o Master independente.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    assert b"requireAbility('pastoral')" in files["public/pastoral-agenda.php"]
    assert b"currentChurch()" in files["public/pastoral-agenda.php"]
    assert b"verify_csrf()" in files["public/pastoral-agenda.php"]
    assert b" AND c.assigned_user_id=:actor" in files["public/lib/PastoralAgendaService.php"] or b"OR c.assigned_user_id=:actor" in files["public/lib/PastoralAgendaService.php"]
    assert b"church_id" in files["public/database/040_pastoral_agenda.sql"]
    assert b"CHAR(36)" in files["public/database/040_pastoral_agenda.sql"]
    assert b"'pastoral-agenda.php' =>" in files["public/lib/View.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-378-") as d:
        for path,body in files.items():
            if not path.endswith(".php"):continue
            full=Path(d)/path;full.parent.mkdir(parents=True,exist_ok=True);full.write_bytes(body)
            p=subprocess.run(["php","-l",str(full)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            if p.returncode:raise RuntimeError("PHP syntax invalid "+path+" "+p.stdout)
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
      "published_at":"2026-11-02T22:00:00-03:00",
      "cache_buster":"3.7.8-pastoral-agenda",
      "notes":[
        "Agenda pastoral: atendimentos, visitas e histórico de contatos por igreja.",
        "Retornos atrasados, filtros, paginação e indicação de responsáveis.",
        "Anotações confidenciais restritas aos usuários com permissão pastoral.",
        "Cadastro em janela flutuante e acompanhamento pelo menu Pessoas e cadastros.",
        "Migração 040: novas tabelas com UUIDs; preserva as versões anteriores."
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
