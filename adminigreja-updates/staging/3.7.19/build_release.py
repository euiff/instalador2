#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.19 updater from the checked 3.7.18 release.

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
VERSION="3.7.19"
PREV="3.7.18"


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
    assert sha(compressed)==manifest["sha256"],"3.7.18 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.19 "+label+" anchor missing")
    return txt.replace(old,new,1)


def replace_once(text:str,old:str,new:str,label:str)->str:
    if text.count(old)!=1:raise RuntimeError(f"Unsafe 3.7.19 {label}: anchor count {text.count(old)}")
    return text.replace(old,new,1)


def modify_auth(source:bytes)->bytes:
    text=source.decode("utf-8")
    anchor="    public function __construct(private PDO $pdo) {}"
    patch="""    private static bool $loginPasswordAssetsAttached=false;

    public function __construct(private PDO $pdo)
    {
        self::attachLoginPasswordAssets();
    }

    /**
     * The historic /login page lives outside the incremental update bundle
     * and does not use the logged-in View::header layout. Attach the existing
     * show/hide script only on the public login route, without changing
     * authentication, hashing, CSRF, sessions, or the login form markup.
     */
    private static function attachLoginPasswordAssets():void
    {
        if(self::$loginPasswordAssetsAttached)return;
        $route=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        if(!is_string($route)||!preg_match('#^/login(?:\\\\.php)?/?$#iD',$route))return;
        self::$loginPasswordAssetsAttached=true;
        ob_start(static function(string $markup):string{
            if(stripos($markup,'password-visibility.js')!==false)return $markup;
            $head=strpos(strtolower($markup),'</head>');
            $body=strpos(strtolower($markup),'</body>');
            $assets='<link rel="stylesheet" href="/assets/password-visibility.css?v=3719">'
                .'<script src="/assets/password-visibility.js?v=3719" defer></script>';
            if($head!==false)return substr_replace($markup,$assets.$markup[$head],$head,1);
            if($body!==false)return substr_replace($markup,$assets.$markup[$body],$body,1);
            return $markup;
        });
    }
"""
    # Avoid fragile indexing of a single char; original closing tag stays intact.
    patch=patch.replace("$assets.$markup[$head],$head,1","$assets,$head,0")
    patch=patch.replace("$assets.$markup[$body],$body,1","$assets,$body,0")
    text=replace_once(text,anchor,patch,"public login asset hook")
    return text.encode("utf-8")


def modify_system_course(source:bytes)->bytes:
    text=source.decode("utf-8")
    anchor="<figcaption><strong><?=e($photo['title'])?></strong><?=e($photo['description'])?></figcaption>"
    patch="""<figcaption>
    <strong><?=e($photo['title'])?></strong>
    <?=e($photo['description'])?>
    <?php if(!empty($photo['is_illustration'])):?>
      <span style="display:block;color:#8a5a22;margin-top:4px">Ilustração da interface para orientação. Após importar as capturas reais aprovadas, elas aparecerão automaticamente aqui.</span>
    <?php else:?>
      <span style="display:block;color:#24784b;margin-top:4px">Captura real do sistema, com dados pessoais ocultados e ações destacadas.</span>
    <?php endif;?>
    </figcaption>"""
    text=replace_once(text,anchor,patch,"screenshot and fallback caption")
    return text.encode("utf-8")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/Auth.php"]=modify_auth(files["public/lib/Auth.php"])
    files["public/system-course.php"]=modify_system_course(files["public/system-course.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Olho na senha do login e guias visuais automáticos\n"
    notes=(
        "- O botão de olho agora é injetado também na tela pública /login, não apenas nos formulários autenticados.\n"
        "- O login original e suas regras de autenticação, senha e sessões são preservados.\n"
        "- Todas as 17 telas do curso têm uma ilustração segura com botão destacado mesmo antes da importação do ZIP.\n"
        "- A primeira aula mostra imagem; as capturas reais aprovadas substituem automaticamente a ilustração quando importadas.\n"
        "- Imagens, aulas e progressos continuam isolados e protegidos por permissões.\n"
        "- Importante: o endereço HTTPS precisa ser ativado na hospedagem para proteger as senhas.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    required=[
      "public/lib/Auth.php",
      "public/lib/SystemCourseIllustration.php",
      "public/lib/SystemCourseImageService.php",
      "public/data/system-course-images.json",
      "public/system-course-image.php",
      "public/system-course.php",
      "public/assets/password-visibility.js",
      "public/assets/password-visibility.css",
    ]
    for name in required:assert name in files,name
    auth=files["public/lib/Auth.php"]
    assert b"attachLoginPasswordAssets" in auth
    assert b"$route=parse_url" in auth
    assert b"/assets/password-visibility.js?v=3719" in auth
    assert b"/assets/password-visibility.css?v=3719" in auth
    assert b"password_verify" in auth and b"session_regenerate_id" in auth
    assert b"SystemCourseIllustration::svg" in files["public/system-course-image.php"]
    assert b"Ilustra\xc3\xa7\xc3\xa3o da interface" in files["public/system-course.php"]
    catalog=json.loads(files["public/data/system-course-images.json"].decode("utf-8"))
    imgs=catalog["images"];assert len(imgs)==17
    first=next(x for x in imgs if x["id"]=="01-painel-inicial")
    assert "primeiros-passos-01" in first.get("additional_lessons",[])
    assert all(re.fullmatch("[0-9a-f]{64}",img["sha256"]) for img in imgs)
    with tempfile.TemporaryDirectory(prefix="igreja-master-3719-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax failed "+name+": "+proc.stdout+proc.stderr)
        proc=subprocess.run(["node","--check",str(Path(d)/"public/assets/password-visibility.js")],
            capture_output=True,text=True)
        if proc.returncode:raise RuntimeError("Eye-button JS syntax failed: "+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=240)
            if proc.returncode:raise RuntimeError("3.7.19 regression:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
    print("PHP syntax checked",sum(name.endswith(".php") for name in files),"files")
    print("Login eye + first-lesson image and 17 automatic visual guides verified")


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
      "cache_buster":"3.7.19-login-eye-auto-guides",
      "notes":[
        "Botão de olho para revelar e ocultar senha agora também na tela de login.",
        "Login original, autenticação, permissões e sessões preservados.",
        "17 imagens ilustrativas com setas e botões destacados exibidas sem importar ZIP.",
        "Primeira aula do curso também possui imagem explicativa.",
        "Fotos reais anonimizadas, quando importadas, substituem automaticamente as ilustrações.",
        "Proteção por login e regras de acesso preservadas. Configure HTTPS no cPanel para segurança."
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
