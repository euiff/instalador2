#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.18 updater from the checked 3.7.17 release.

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
VERSION="3.7.18"
PREV="3.7.17"


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
    assert sha(compressed)==manifest["sha256"],"3.7.17 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.18 "+label+" anchor missing")
    return txt.replace(old,new,1)


def replace_once(txt:str,old:str,new:str,label:str)->str:
    if txt.count(old)!=1:raise RuntimeError(f"Unexpected {label}, got {txt.count(old)} anchors")
    return txt.replace(old,new,1)


def replace_once(text:str,old:str,new:str,label:str)->str:
    if text.count(old)!=1:raise RuntimeError(f"Unsafe 3.7.18 {label}: anchor count {text.count(old)}")
    return text.replace(old,new,1)


def modify_system_course(source:bytes)->bytes:
    text=source.decode("utf-8")
    text=replace_once(text,
        "require_once __DIR__.'/lib/SystemCourseService.php';",
        "require_once __DIR__.'/lib/SystemCourseService.php';\nrequire_once __DIR__.'/lib/SystemCourseImageService.php';",
        "image service import")
    text=replace_once(text,
        "$course=new SystemCourseService($pdo,$auth,$cid,(string)$user['id']);",
        "$course=new SystemCourseService($pdo,$auth,$cid,(string)$user['id']);\n$imageService=new SystemCourseImageService();",
        "image service instance")
    text=replace_once(text,
        "$lesson=$lessonEntry['lesson']??null;",
        "$lesson=$lessonEntry['lesson']??null;\n$lessonScreens=$lessonSlug!==''?$imageService->forLesson($lessonSlug):[];",
        "lesson image map")
    css="""
.sys-course-photo{padding:12px;margin:13px 0;border:1px solid #d9e5eb;border-radius:13px;background:#f6fafc}
.sys-course-photo img{display:block;max-width:100%;width:100%;height:auto;border-radius:8px;box-shadow:0 5px 20px #163b5822}
.sys-course-photo figcaption{margin-top:10px;color:#4d687b;font-size:12px;line-height:1.6}
.sys-course-photo strong{display:block;color:#16476b}
"""
    text=replace_once(text,
       "@media(max-width:910px){.sys-columns{grid-template-columns:1fr}.sys-sidebar{position:static;max-height:300px}}",
       css+"@media(max-width:910px){.sys-columns{grid-template-columns:1fr}.sys-sidebar{position:static;max-height:300px}}",
       "responsive tutorial image CSS")
    photos="""<?php if($lessonScreens):?>
<div class="sys-screens" aria-label="Telas ilustrativas desta aula">
<?php foreach($lessonScreens as $photo):?>
<figure class="sys-course-photo">
  <img src="/system-course-image.php?img=<?=rawurlencode($photo['id'])?>" loading="lazy"
       width="1360" height="720" alt="<?=e($photo['title'].'. '.$photo['description'])?>">
  <figcaption><strong><?=e($photo['title'])?></strong><?=e($photo['description'])?></figcaption>
</figure>
<?php endforeach;?>
</div>
<?php endif;?>
<ol class="sys-steps">"""
    text=replace_once(text,'<ol class="sys-steps">',photos,"annotated screenshot in lesson")
    return text.encode("utf-8")


def modify_master(source:bytes)->bytes:
    text=source.decode("utf-8")
    anchor='<a class="btn btn-light" href="/system-course.php">Curso Oficial do Sistema</a>'
    if anchor not in text:raise RuntimeError("Master shortcut to course missing")
    text=text.replace(anchor,anchor+
      '<a class="btn btn-light" href="/master-system-course-images.php">Imagens do Curso</a>',1)
    return text.encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/system-course.php"]=modify_system_course(files["public/system-course.php"])
    files["public/master.php"]=modify_master(files["public/master.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Curso por igreja com 17 telas reais anonimizadas\n"
    notes=(
        "- Retiradas todas as aulas específicas do Master: treinamento destinado apenas a igrejas e clientes.\n"
        "- 25 módulos e 144 aulas com instruções de cadastro, finanças, departamentos, contabilidade e atividades.\n"
        "- 17 telas reais preparadas e anonimizadas, com marcações de cliques e referências às aulas correspondentes.\n"
        "- Importação única do ZIP aprovado no Master — Imagens do Curso; nenhuma foto com dados originais é aceita.\n"
        "- Mesmas telas podem ser usadas por igrejas atuais e novas, sem cadastrar cursos individualmente.\n"
        "- Imagens só são acessíveis após autenticação e autorização de leitura do curso.\n"
        "- Mantidos módulos antigos, controles financeiros, permissões e progresso individual já concluído.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
      "public/system-course.php",
      "public/data/system-course.json",
      "public/data/system-course-images.json",
      "public/lib/SystemCourseImageService.php",
      "public/system-course-image.php",
      "public/master-system-course-images.php",
      "public/master.php",
    ]
    for name in required:assert name in files,name
    c=json.loads(files["public/data/system-course.json"].decode("utf-8"))
    modules=c["modules"]
    lessons=[l for m in modules for l in m["lessons"]]
    assert len(modules)==25,f"Expected 25 customer modules, got {len(modules)}"
    assert len(lessons)==144,f"Expected 144 customer lessons, got {len(lessons)}"
    assert not any(m["slug"] in ("master","migra-enuves") for m in modules)
    assert not any(l.get("permission")=="master" or (l.get("route") or "").startswith("/master")
                   for l in lessons),"Master-only lessons must be removed"
    lessonSlugs={l["slug"] for l in lessons}
    assert len(lessonSlugs)==len(lessons)
    imgs=json.loads(files["public/data/system-course-images.json"].decode("utf-8"))
    assert imgs["scope"]=="client-churches-only"
    assert len(imgs["images"])==17
    ids=set()
    for img in imgs["images"]:
        assert img["id"] not in ids
        assert img["lesson_slug"] in lessonSlugs
        assert img["file"]==img["id"]+".png"
        assert re.fullmatch("[0-9a-f]{64}",img["sha256"])
        ids.add(img["id"])
    assert b"/system-course-image.php?img=" in files["public/system-course.php"]
    assert b"system_course" in files["public/system-course-image.php"]
    assert b"isSuperAdmin" in files["public/master-system-course-images.php"]
    assert b"hash_equals" in files["public/lib/SystemCourseImageService.php"]
    assert b"verify_csrf()" in files["public/master-system-course-images.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3718-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
               env=env,capture_output=True,text=True,timeout=230)
            if proc.returncode:raise RuntimeError("3.7.18 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("Customer course confirmed",len(modules),"modules",len(lessons),"lessons",len(ids),"illustrated screens")


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
      "cache_buster":"3.7.18-client-course-images",
      "notes":[
        "Curso Tutorial exclusivo das igrejas e clientes: removidos conteúdos internos do Master.",
        "25 módulos, 144 aulas passo a passo, com exemplos de funções da igreja.",
        "Suporte a 17 imagens reais com dados ocultos e botões indicados por números.",
        "Master importa as imagens aprovadas uma única vez para todas as igrejas.",
        "Arquivos exibidos somente para usuários autenticados do curso; upload exige aprovação SHA-256.",
        "Preserva progresso dos alunos e todas as funcionalidades anteriores da plataforma."
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
