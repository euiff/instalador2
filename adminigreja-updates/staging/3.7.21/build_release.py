#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.21 updater from the checked 3.7.20 release.

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
VERSION="3.7.21"
PREV="3.7.20"


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
    assert sha(compressed)==manifest["sha256"],"3.7.20 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.21 "+label+" anchor missing")
    return txt.replace(old,new,1)


def patch_member_department(source:bytes)->bytes:
    text=source.decode("utf-8")
    old="""$cols=$this->q("SELECT COLUMN_NAME,COLUMN_DEFAULT,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS"""
    new="""$cols=$this->q("SELECT COLUMN_NAME,COLUMN_DEFAULT,IS_NULLABLE,EXTRA,DATA_TYPE FROM information_schema.COLUMNS"""
    if text.count(old)!=1:raise RuntimeError("Original ministry_members column inspection changed")
    text=text.replace(old,new,1)
    old="""        if(in_array('active',$available,true))$fields['active']=1;
        if(in_array('created_by_user_id',$available,true))"""
    new="""        if(in_array('active',$available,true))$fields['active']=1;
        // Some live installations have joined_on DATE/DATETIME NOT NULL
        // without a default. Always supply the creation date when present.
        // Reuse the database's own clock to avoid UTC/server timezone drift.
        if(in_array('joined_on',$available,true)){
            $column=null;
            foreach($cols as $col){
                if((string)$col['COLUMN_NAME']==='joined_on'){$column=$col;break;}
            }
            $type=strtolower((string)($column['DATA_TYPE']??'date'));
            $expression=in_array($type,['datetime','timestamp'],true)
                ?'CURRENT_TIMESTAMP()':'CURRENT_DATE()';
            // The SQL expression is entirely hardcoded, never user input.
            // Use a SELECT from the database to normalize the correct type.
            $fields['joined_on']=$this->q('SELECT '.$expression)->fetchColumn();
        }
        if(in_array('created_by_user_id',$available,true))"""
    if text.count(old)!=1:raise RuntimeError("Original ministry member insert changed")
    text=text.replace(old,new,1)
    return text.encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    files["public/lib/MemberDepartmentService.php"]=patch_member_department(
        files["public/lib/MemberDepartmentService.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Corrige vínculo de líder em ministérios com joined_on obrigatório\n"
    notes=(
        "- Corrige SQLSTATE HY000 1364: joined_on não possuía valor ao vincular líder de departamento.\n"
        "- Preenche automaticamente a data de inclusão no cadastro de participantes (DATE ou DATETIME) quando a coluna existe.\n"
        "- Preserva compatibilidade com bancos antigos que não possuem joined_on, sem alterar esquema nem apagar registros.\n"
        "- Mantém vínculo de membro, cargo de líder e acesso financeiro limitado ao departamento escolhido.\n"
        "- A gravação dos vínculos continua transacional: se falhar, alterações são desfeitas.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
      "public/lib/MemberDepartmentService.php",
      "public/lib/ChurchMemberLeadershipService.php",
      "public/users.php",
    ]
    for name in required:assert name in files,name
    code=files["public/lib/MemberDepartmentService.php"]
    assert b"joined_on" in code
    assert b"CURRENT_TIMESTAMP()" in code
    assert b"CURRENT_DATE()" in code
    assert b"DATA_TYPE FROM information_schema.COLUMNS" in code
    assert b"SELECT 1 FROM ministry_members" in code
    assert b"member_department_roles" in code
    with tempfile.TemporaryDirectory(prefix="igreja-master-3721-") as d:
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
            if proc.returncode:raise RuntimeError("3.7.21 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("Joined_on strict DATE and DATETIME compatible department insert verified")


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
      "cache_buster":"3.7.21-ministry-member-joined-on",
      "notes":[
        "Corrigido erro SQLSTATE[HY000] 1364: campo joined_on sem valor ao salvar líder de departamento.",
        "A data de vínculo é inserida automaticamente, respeitando colunas DATE e DATETIME do banco.",
        "Compatível com igrejas antigas e novas, sem mexer nos cadastros existentes.",
        "Liderança vinculada ao departamento escolhido com acesso financeiro limitado ao próprio ministério.",
        "Operação transacional e isolamento por igreja preservados."
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
