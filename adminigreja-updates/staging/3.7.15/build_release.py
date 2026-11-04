#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.15 updater from the checked 3.7.14 release.

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
VERSION="3.7.15"
PREV="3.7.14"


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
    assert sha(compressed)==manifest["sha256"],"3.7.14 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.15 "+label+" anchor missing")
    return txt.replace(old,new,1)


def modify_view(source:bytes)->bytes:
    txt=source.decode("utf-8")
    head_anchor='<script src="/assets/management-3711.js?v=3711" defer></script>'
    if txt.count(head_anchor)!=1:raise RuntimeError("Unexpected shared head layout; refuse unsafe asset injection")
    addon='''<link rel="stylesheet" href="/assets/password-visibility.css?v=3715">
<script src="/assets/password-visibility.js?v=3715" defer></script>'''
    txt=txt.replace(head_anchor,head_anchor+"\n"+addon,1)
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
    heading=f"## {VERSION} — Mostrar ou ocultar senha com botão de olho\n"
    notes=(
        "- Um botão de olho acessível aparece dentro dos campos de senha dos formulários do sistema.\n"
        "- Funciona inclusive em janelas flutuantes carregadas após abrir a página.\n"
        "- Clicar revela ou oculta a senha, preservando o valor digitado, sem enviar nem armazenar dados.\n"
        "- Aplicado no layout autenticado de usuários, Master e Portal do Membro.\n"
        "- Não modifica autenticação, contas, dados pessoais ou registros financeiros.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
        "public/assets/password-visibility.js",
        "public/assets/password-visibility.css",
        "public/lib/View.php",
        "public/member-portal-access.php",
        "public/users.php",
    ]
    for p in required:assert p in files,"Missing "+p
    header=files["public/lib/View.php"]
    assert header.count(b"/assets/password-visibility.js?v=3715")==1
    assert header.count(b"/assets/password-visibility.css?v=3715")==1
    assert b'type="password"' in files["public/users.php"]
    assert b'type="password"' in files["public/member-portal-access.php"]
    js=files["public/assets/password-visibility.js"]
    assert b"aria-pressed" in js and b"Mostrar senha" in js and b"Ocultar senha" in js
    assert b"MutationObserver" in js and b"button.type = 'button'" in js
    with tempfile.TemporaryDirectory(prefix="igreja-master-3715-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                result=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if result.returncode:raise RuntimeError("PHP syntax failed "+name+": "+result.stdout+result.stderr)
        js_path=str(Path(d)/"public/assets/password-visibility.js")
        check=subprocess.run(["node","--check",js_path],capture_output=True,text=True)
        if check.returncode:raise RuntimeError("Password icon JavaScript syntax failed "+check.stderr)
        test=subprocess.run(["node",str(STAGING/"tests"/"password_visibility.cjs"),js_path],
            capture_output=True,text=True,timeout=30)
        if test.returncode:raise RuntimeError("Password eye browser-smoke test failed\n"+test.stdout+"\n"+test.stderr)
        print(test.stdout.strip())
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            oldtest=STAGING.parent/"3.7.14"/"tests"/"integration.php"
            result=subprocess.run(["php",str(oldtest)],env=env,capture_output=True,text=True,timeout=170)
            if result.returncode:raise RuntimeError("Cumulative system regression failed\n"+result.stdout+"\n"+result.stderr)
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
      "cache_buster":"3.7.15-password-eye",
      "notes":[
        "Olho de mostrar/ocultar senha nos formulários autenticados do Igreja Master.",
        "Funciona também em janelas flutuantes e campos adicionados após carregar a página.",
        "Acessível pelo teclado e leitores de tela; preserva o que foi digitado.",
        "Não altera contas, login, senhas gravadas, permissões ou dados financeiros.",
        "Atualização cumulativa 3.7.15 com regressões de versões anteriores."
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
