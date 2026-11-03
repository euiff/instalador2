#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.13 updater from the checked 3.7.12 release.

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
VERSION="3.7.13"
PREV="3.7.12"


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
    assert sha(compressed)==manifest["sha256"],"3.7.12 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.13 "+label+" anchor missing")
    return txt.replace(old,new,1)


def modify_view(source:bytes)->bytes:
    txt=source.decode("utf-8")
    nav_anchor="$nav['master-members.php'] = ['master-members','Gerenciar membros','♙'];"
    txt=once(txt,nav_anchor,nav_anchor+"\n            $nav['master-congregations.php'] = ['master-congregations','Congregações em massa','▦'];","Master nav")
    block="'master-members.php'=>['master-members','Gerenciar membros','♙'],"
    txt=once(txt,block,block+"\n                    'master-congregations.php'=>['master-congregations','Congregações em massa','▦'],","Master links")
    txt=once(txt,"'master-enuves.php','master-members.php'","'master-enuves.php','master-members.php','master-congregations.php'","Master group")
    anchor="'department-treasury.php' => ['department-treasury','Tesourarias dos Departamentos','◈','department_treasury'],"
    if anchor not in txt:raise RuntimeError("department treasury navigation missing")
    txt=txt.replace(anchor,anchor+"\n            'department-people.php' => ['department-people','Pessoas e Lideranças','♙','secretary'],",1)
    txt=once(txt,"'department-treasury.php','department-cash.php'",
             "'department-people.php','department-treasury.php','department-cash.php'","department menu")
    anchor="'users.php' =>"
    if anchor not in txt:raise RuntimeError("user management menu missing")
    first=txt.index(anchor)
    txt=txt[:first]+" 'member-portal-access.php' => ['member-portal-access','Ativar Portal do Membro','♙','manage_users'],\n            "+txt[first:]
    txt=once(txt,"'users.php','settings.php'","'member-portal-access.php','users.php','settings.php'","portal menu")
    return txt.encode("utf-8")


def modify_members(source:bytes)->bytes:
    txt=source.decode("utf-8")
    txt=once(txt,"require_once __DIR__.'/lib/MemberDirectoryService.php';",
       "require_once __DIR__.'/lib/MemberDirectoryService.php';\nrequire_once __DIR__.'/lib/MemberDepartmentService.php';\nMemberDepartmentService::ensure($pdo);\n$memberDepartments=new MemberDepartmentService($pdo,$auth,$cid,(string)$auth->requireLogin()['id']);",
       "members service")
    txt=once(txt,"if ($name === '' || $phone === '') throw new InvalidArgumentException('Nome e telefone são obrigatórios.');",
       "if ($name === '') throw new InvalidArgumentException('Informe o nome completo do membro.');",
       "phone optional")
    txt=once(txt,"'department'=>trim((string)($_POST['department'] ?? '')) ?: null,",
      "'department'=>null,","deprecate free department text")
    anchor="            if ($id !== '') {\n                $sql = 'UPDATE members"
    if anchor not in txt:raise RuntimeError("save transaction anchor")
    prep="""            $departmentId=trim((string)($_POST['department_id']??''));
            if($departmentId!==''){
                $targetDepartment=$memberDepartments->department($departmentId);
                if(empty($targetDepartment['active']))throw new InvalidArgumentException('Departamento inativo.');
                $data['department']=$targetDepartment['name'];
            }
            $pdo->beginTransaction();
            try{
            if ($id !== '') {
                $sql = 'UPDATE members"""
    txt=txt.replace(anchor,prep,1)
    txt=once(txt,"                $sql = 'INSERT INTO members(","                $id=app_uuid();\n                $sql = 'INSERT INTO members(","created ID")
    txt=once(txt,"$q->execute([app_uuid(),$cid,$data['congregation_id']","$q->execute([$id,$cid,$data['congregation_id']","persist ID")
    anchor="                $success = 'Membro cadastrado com sucesso.';\n            }\n        }\n    } catch (Throwable $e) { $error = $e->getMessage(); }"
    after="""                $success = 'Membro cadastrado com sucesso.';
            }
            if($departmentId!==''){
                $previousRole='participant';
                foreach($memberDepartments->forMember($id) as $currentLink)if($currentLink['department_id']===$departmentId){
                    if($currentLink['role_name']==='leader')$previousRole='leader';
                }
                if($previousRole==='leader'&&!$auth->can('manage_users')){
                    // Non-admin editors preserve the previously approved leader scope.
                }else $memberDepartments->assign($id,$departmentId,$previousRole);
            }
            $pdo->commit();
            }catch(Throwable $failure){if($pdo->inTransaction())$pdo->rollBack();throw $failure;}
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }"""
    txt=once(txt,anchor,after,"transaction end")
    old="""<div class="field"><label>Telefone *</label><input class="input" name="phone" required value="<?=e($edit['phone'] ?? '')?>"></div>"""
    new="""<div class="field"><label>Telefone</label><input class="input" name="phone" value="<?=e($edit['phone'] ?? '')?>" placeholder="Pode cadastrar sem telefone"></div>"""
    txt=once(txt,old,new,"phone form")
    old="""<div class="field"><label>Função / Cargo</label><input class="input" name="role" list="church-positions" value="<?=e($edit['role'] ?? '')?>"></div>"""
    new="""<div class="field"><label>Função / Cargo</label>
    <select class="select" name="role"><option value="">Selecione um cargo</option>
    <?php $roleOptions=array_values(array_unique(array_filter(array_merge(['Membro'],array_column($positions,'name'),[(string)($edit['role']??'')]))));
    foreach($roleOptions as $positionName):?><option value="<?=e($positionName)?>" <?=($edit['role']??'')===$positionName?'selected':''?>><?=e($positionName)?></option><?php endforeach;?></select></div>"""
    txt=once(txt,old,new,"role dropdown")
    old="""<div class="field"><label>Departamento</label><input class="input" name="department" value="<?=e($edit['department'] ?? '')?>"></div>"""
    new="""<div class="field"><label>Departamento</label>
    <select class="select" name="department_id"><option value="">Nenhum departamento / selecionar depois</option>
    <?php foreach($directoryDepartments as $d):if(empty($d['active'])&&($edit['department']??'')!==$d['name'])continue;?>
    <option value="<?=e($d['id'])?>" <?=($edit['department']??'')===$d['name']?'selected':''?>>
    <?=e($d['name'])?><?=empty($d['active'])?' (inativo)':''?></option><?php endforeach;?></select>
    <small>Selecione da lista salva no sistema. Para vários ministérios ou liderança, use <a href="/department-people.php">Pessoas e Lideranças</a>.</small></div>"""
    txt=once(txt,old,new,"department dropdown")
    anchor="""<datalist id="church-positions">"""
    if anchor not in txt:raise RuntimeError("positions anchor missing")
    txt=txt.replace(anchor,"<p class=\\"muted\\">O cargo do cadastro não libera funções administrativas; o perfil de login é configurado separadamente.</p>\\n"+anchor,1)
    return txt.encode('utf-8')


def modify_member_profile(source:bytes)->bytes:
    txt=source.decode('utf-8')
    anchor="""<a class="btn btn-primary" href="members.php?edit=<?=e($m['id'])?>#cadastro">Editar cadastro</a>"""
    links="""<?php if($auth->can('manage_users')):?><a class="btn btn-light" href="/member-portal-access.php?member=<?=e($m['id'])?>">Ativar Portal do Membro</a><?php endif;?>
    <a class="btn btn-light" href="/department-people.php">Vincular departamento / liderança</a>"""
    return once(txt,anchor,anchor+links,"profile links")


def modify_master_members(source:bytes)->bytes:
    txt=source.decode('utf-8')
    anchor='<a class="btn btn-light" href="/master.php">Painel Master</a>'
    return once(txt,anchor,'<a class="btn btn-light" href="/master-congregations.php?church=<?=rawurlencode($cid)?>">Vincular congregação em massa</a>'+anchor,"bulk button")


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/members.php"]=modify_members(files["public/members.php"])
    files["public/member-profile.php"]=modify_member_profile(files["public/member-profile.php"])
    files["public/master-members.php"]=modify_master_members(files["public/master-members.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Departamentos, cargos, congregações em massa e Portal do Membro\n"
    notes=(
        "- Cadastro de membros com seleção dos departamentos e cargos existentes, sem texto livre.\n"
        "- Associação de participantes e líderes aos departamentos, com acesso de líder apenas por permissão autorizada.\n"
        "- Master vincula múltiplos membros à congregação correta, com confirmação, auditoria e desfazer lote.\n"
        "- Acesso pessoal do membro ativado pelo gestor e vinculado ao cadastro da igreja.\n"
        "- Portal do Membro mostra apenas o próprio histórico de contribuições e avisos publicados.\n"
        "- Registros antigos, dados de outras igrejas e livro caixa preservados.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    needed=["public/department-people.php","public/member-portal-access.php",
        "public/master-congregations.php","public/lib/MemberDepartmentService.php",
        "public/lib/MemberPortalAccessService.php","public/lib/MasterCongregationService.php",
        "public/database/043_member_departments.sql","public/database/044_master_congregation_moves.sql"]
    for p in needed:assert p in files,p
    for p in ["public/department-people.php","public/member-portal-access.php","public/master-congregations.php"]:
        assert b"verify_csrf()" in files[p],p+" missing csrf"
    assert b"name=\"department_id\"" in files["public/members.php"]
    assert b"array_column($positions,'name')" in files["public/members.php"]
    assert b"member-portal-access.php" in files["public/member-profile.php"]
    assert b"master-congregations.php" in files["public/master-members.php"]
    assert b"department-people.php" in files["public/lib/View.php"]
    assert b"master-congregations.php" in files["public/lib/View.php"]
    assert b"member-portal-access.php" in files["public/lib/View.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3713-") as d:
        for name,data in files.items():
            p=Path(d)/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(p)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError(name+" "+proc.stdout+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            r=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=150)
            if r.returncode:raise RuntimeError("3.7.13 regression: "+r.stdout+r.stderr)
            print(r.stdout.strip())
    print("PHP syntax checked",sum(p.endswith(".php") for p in files),"files")


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
      "cache_buster":"3.7.13-members-departments-portal",
      "notes":[
        "Cadastro de membros: listas reais de departamentos e cargos, em vez de texto livre.",
        "Nova tela Pessoas e Lideranças para vincular membro, ministério e líder com permissão.",
        "Master transfere membros em massa para congregações e pode desfazer lotes.",
        "Ativação orientada de login pessoal para consultar dízimos, ofertas e avisos.",
        "Permissões multi-igrejas e dados financeiros preservados."
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
