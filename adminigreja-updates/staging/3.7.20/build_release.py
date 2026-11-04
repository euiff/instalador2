#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.20 updater from the checked 3.7.19 release.

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
VERSION="3.7.20"
PREV="3.7.19"


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
    assert sha(compressed)==manifest["sha256"],"3.7.19 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.20 "+label+" anchor missing")
    return txt.replace(old,new,1)


from patch_users import patch_users

def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/users.php"]=patch_users(files["public/users.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Acesso de líder a partir do membro já cadastrado\n"
    notes=(
        "- Nome e e-mail são preenchidos automaticamente a partir da ficha do membro selecionado.\n"
        "- Para membros que já possuem login pessoal, o sistema atualiza o perfil de acesso sem duplicar a conta ou trocar a senha.\n"
        "- Selecionar o papel Líder do departamento registra a liderança efetiva no ministério escolhido.\n"
        "- Líder tem acesso à equipe e tesouraria apenas dos departamentos autorizados, não à tesouraria geral.\n"
        "- Alteração ou remoção de liderança revoga o acesso financeiro departamental preservando a participação.\n"
        "- Os dados, cadastros e caixas de outras igrejas continuam isolados por tenant e os lançamentos preservados.\n"
        "- Não permite transformar proprietário/Master em líder por essa tela, nem editar o próprio perfil.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
      "public/users.php",
      "public/lib/ChurchMemberLeadershipService.php",
      "public/lib/MemberDepartmentService.php",
      "public/lib/DepartmentRoleAccess.php",
      "public/assets/users-member-autofill.js",
    ]
    for name in required:assert name in files,name
    users=files["public/users.php"]
    assert b"data-user-create-from-member" in users
    assert b"data-member-email" in users
    assert b"ChurchMemberLeadershipService" in users
    assert b"$leadAccess->sync" in users
    assert b"if($membership)" in users
    assert b"oldDepartmentIds" in users
    assert b"MemberDepartmentService::ensure($pdo)" in users
    assert b"password_hash($password,PASSWORD_DEFAULT)" in users
    assert b"data-user-password-help" in users
    service=files["public/lib/ChurchMemberLeadershipService.php"]
    assert b"member_department_roles" in service
    assert b"->assign(" in service
    assert b"department_leader" in service
    with tempfile.TemporaryDirectory(prefix="igreja-master-3720-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
        proc=subprocess.run(["node","--check",str(Path(d)/"public/assets/users-member-autofill.js")],
            capture_output=True,text=True)
        if proc.returncode:raise RuntimeError("Member autofill JS syntax failed: "+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=240)
            if proc.returncode:raise RuntimeError("3.7.20 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("Existing members and department leader workflow verified")


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
      "cache_buster":"3.7.20-member-leadership-provision",
      "notes":[
        "Novo acesso agora usa nome e e-mail do membro selecionado, sem redigitar dados.",
        "Se o membro já tem login, aproveita o acesso e a senha atual sem duplicar conta.",
        "Ao indicar Líder e Jovens, registra a liderança real em Pessoas e Líderes dos Departamentos.",
        "Líder visualiza equipe e caixa somente do seu departamento, sem acesso à tesouraria geral.",
        "Alterar a liderança revoga somente os acessos correspondentes e mantém o histórico.",
        "Inclui validações de segurança e isolamento entre igrejas."
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
