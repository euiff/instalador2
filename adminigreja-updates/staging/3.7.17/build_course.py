#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.17 updater from the checked 3.7.16 release.

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
VERSION="3.7.17"
PREV="3.7.16"


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
    assert sha(compressed)==manifest["sha256"],"3.7.16 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.17 "+label+" anchor missing")
    return txt.replace(old,new,1)


def replace_once(txt:str,old:str,new:str,label:str)->str:
    if txt.count(old)!=1:raise RuntimeError(f"Unexpected {label}, got {txt.count(old)} anchors")
    return txt.replace(old,new,1)


def modify_auth(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="        $role=$this->churchRole();\n        // Explicit department functions"
    new="""        $role=$this->churchRole();
        // Official platform training is visible to every authenticated church role.
        // It does not grant permission to perform the actions described in lessons.
        if($ability==='system_course')return $role!=='';
        // Explicit department functions"""
    txt=replace_once(txt,anchor,new,"global manual ability")
    return txt.encode("utf-8")


def modify_view(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="'learning.php' => ['learning','Ensino e Turmas','▤','learning_portal'],"
    txt=replace_once(txt,anchor,anchor+"\n            'system-course.php' => ['system-course','Curso Oficial do Sistema','▤','system_course'],",
       "shared navigation item")
    anchor="'Ensino'=>['file',['learning.php','studies.php']]"
    txt=replace_once(txt,anchor,"'Ensino'=>['file',['system-course.php','learning.php','studies.php']]",
       "Ensino navigation group")
    return txt.encode("utf-8")


def modify_learning(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="require_once __DIR__.'/lib/MediaLibraryService.php';"
    txt=replace_once(txt,anchor,anchor+"\nrequire_once __DIR__.'/lib/SystemCourseService.php';","learning catalog import")
    anchor="$isManager=$service->isManager();"
    txt=replace_once(txt,anchor,anchor+"\n$officialTraining=new SystemCourseService($pdo,$auth,$cid,(string)$user['id']);",
      "learning official course instance")
    anchor='<nav class="learn-tabs">'
    hero="""<section class="card card-pad" style="margin-bottom:15px">
  <div class="page-head" style="gap:12px;align-items:center">
    <div>
      <span style="display:inline-block;background:#eaf5fb;color:#1f5d80;padding:5px 10px;border-radius:99px;font-size:11px;font-weight:800">CURSO OFICIAL • GRATUITO PARA TODAS AS IGREJAS</span>
      <h2 style="margin:9px 0 5px"><?=e($officialTraining->catalog()['title'])?></h2>
      <p><?=e($officialTraining->catalog()['subtitle'])?></p>
      <small><?=($officialTraining->moduleCount())?> módulos • <?=($officialTraining->lessonCount())?> aulas práticas • Conteúdo sempre disponível a novos clientes.</small>
    </div>
    <a class="btn btn-primary" href="/system-course.php">Começar o curso completo</a>
  </div>
</section>
<nav class="learn-tabs">
  <a href="/system-course.php">Curso Oficial do Sistema</a>"""
    txt=replace_once(txt,anchor,hero,"learning page course hero")
    marker="""  <?php foreach($courses as $course):?><tr>"""
    pinned="""  <tr><td><strong><?=e($officialTraining->catalog()['title'])?></strong><div class="muted">Manual oficial permanente da plataforma para todas as igrejas.</div></td>
    <td>Treinamento de uso do sistema</td>
    <td><?=($officialTraining->moduleCount())?> módulos / <?=($officialTraining->lessonCount())?> aulas</td>
    <td><span class="badge ok">Ativo • Geral</span></td>
    <td><a class="btn btn-primary" href="/system-course.php">Abrir aulas</a></td></tr>
  <?php foreach($courses as $course):?><tr>"""
    txt=replace_once(txt,marker,pinned,"official course pinned in course table")
    txt=replace_once(txt,"Cadastre o primeiro curso ou estudo.","Nenhum outro curso da igreja cadastrado.",
       "old empty catalog message")
    return txt.encode("utf-8")


def modify_master(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor='<a class="btn btn-light" href="/master-automations.php">Central de automações</a>'
    txt=replace_once(txt,anchor,
      '<a class="btn btn-light" href="/system-course.php">Curso Oficial do Sistema</a>'+anchor,
      "master course shortcut")
    return txt.encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/Auth.php"]=modify_auth(files["public/lib/Auth.php"])
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/learning.php"]=modify_learning(files["public/learning.php"])
    files["public/master.php"]=modify_master(files["public/master.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Curso oficial completo de uso do Igreja Master\n"
    notes=(
        "- Curso oficial permanente na área Ensino/Cursos, disponível em todas as igrejas atuais e novas.\n"
        "- 27 módulos e 164 aulas passo a passo: Master, pastor, secretaria, membros, líderes, tesoureiros e contador.\n"
        "- Cobre configurações, congregações, cadastros, departamentos, finanças, comprovantes, recibos, visitas, crianças, eventos, automações e migrações.\n"
        "- Campo de busca, navegação por módulo, atalhos condicionados à permissão e controle individual de progresso.\n"
        "- Progresso separado por igreja e usuário; conteúdo global sem duplicação de turmas ou dados de clientes.\n"
        "- Protege acesso a funções: curso educativo não concede permissões administrativas nem financeiras.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=["public/system-course.php","public/lib/SystemCourseService.php",
      "public/database/046_system_course_progress.sql","public/data/system-course.json",
      "public/learning.php","public/master.php"]
    for p in required:assert p in files,p
    catalog=json.loads(files["public/data/system-course.json"].decode("utf-8"))
    modules=catalog["modules"]
    assert len(modules)>=25,"Missing curriculum modules"
    lessons=[l for m in modules for l in m["lessons"]]
    assert len(lessons)>=150,"Missing curriculum lessons"
    slugs=[l["slug"] for l in lessons]
    assert len(slugs)==len(set(slugs)),"Duplicated lesson slugs"
    for m in modules:
        assert m["slug"] and m["title"] and m["summary"] and m["audience"]
        for lesson in m["lessons"]:
            assert len(lesson["steps"])>=3 and lesson["title"] and lesson["slug"].startswith(m["slug"]+"-")
    assert b"system_course" in files["public/lib/Auth.php"]
    assert b"system-course.php" in files["public/lib/View.php"]
    assert b"system-course.php" in files["public/learning.php"]
    assert b"system-course.php" in files["public/master.php"]
    assert b"verify_csrf()" in files["public/system-course.php"]
    assert b"ON DUPLICATE KEY UPDATE" in files["public/lib/SystemCourseService.php"]
    assert b"WHERE church_id=? AND user_id=?" in files["public/lib/SystemCourseService.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3717-") as d:
        for name,data in files.items():
            p=Path(d)/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(p)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"course_regression.php")],
                env=env,capture_output=True,text=True,timeout=210)
            if proc.returncode:raise RuntimeError("3.7.17 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("Official training curriculum verified",len(modules),"modules",len(lessons),"lessons")


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
      "cache_buster":"3.7.17-official-system-course",
      "notes":[
        "Curso Oficial do Igreja Master fixo em Ensino/Cursos para igrejas atuais e futuras.",
        "27 módulos e 164 aulas detalhadas sobre toda a plataforma e seus usuários.",
        "Tutoriais de cadastros, congregações, permissões, liderança, tesourarias, recibos, contador e comunicação.",
        "Busca por assunto, progresso por usuário e navegação por aulas com links de ações autorizadas.",
        "Master pode consultar o curso; novas igrejas acessam sem cadastro ou replicação manual.",
        "Isolamento por igreja preservado e permissões dos módulos não ampliadas pelo curso."
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
