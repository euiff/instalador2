#!/usr/bin/env python3
"""Build a complete Igreja Master 3.7.14 updater from the checked 3.7.13 release.

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
VERSION="3.7.14"
PREV="3.7.13"


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
    assert sha(compressed)==manifest["sha256"],"3.7.13 compressed package integrity mismatch"
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
    if old not in txt:raise RuntimeError("3.7.14 "+label+" anchor missing")
    return txt.replace(old,new,1)


def modify_view(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="'department-people.php' => ['department-people','Pessoas e Lideranças','♙','secretary'],"
    addition="'department-team.php' => ['department-team','Equipe e Tesoureiro do Departamento','♙','department_team_manage'],"
    txt=once(txt,anchor,anchor+"\n            "+addition,"navigation item")
    anchor="'department-people.php','department-treasury.php','department-cash.php'"
    txt=once(txt,anchor,
        "'department-people.php','department-team.php','department-treasury.php','department-cash.php'",
        "finance navigation group")
    return txt.encode("utf-8")


def modify_auth(source:bytes)->bytes:
    txt=source.decode("utf-8")
    match=re.search(r'(?m)^(?:final\s+)?class Auth\b',txt)
    if not match:raise RuntimeError("Auth class declaration missing")
    txt=txt[:match.start()]+"require_once __DIR__.'/DepartmentRoleAccess.php';\n\n"+txt[match.start():]
    anchor="        $role=$this->churchRole();"
    rules="""        $role=$this->churchRole();
        // Explicit department functions control scoped finance access. A member
        // who is only a participant cannot see or post to any departmental box.
        if(in_array($ability,['department_portal','department_treasury','department_team_manage'],true)
            && in_array($role,['member','department_leader','group_leader','teacher','kids_worker'],true)){
            $cid=(string)($membership['church_id']??'');
            return $ability==='department_team_manage'
                ?DepartmentRoleAccess::isLeader($this->pdo,$cid,$userId)
                :DepartmentRoleAccess::hasFinancialScope($this->pdo,$cid,$userId);
        }
"""
    txt=once(txt,anchor,rules,"scoped department permission")
    return txt.encode('utf-8')


def modify_treasury_service(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="require_once __DIR__.'/FinanceOperations.php';"
    txt=once(txt,anchor,anchor+"\nrequire_once __DIR__.'/DepartmentRoleAccess.php';","treasury helper")
    anchor="$this->canPost=in_array($role,['owner','admin','pastor','treasurer','department_leader','super_admin'],true);"
    txt=once(txt,anchor,
        "$this->canPost=$role!=='accountant' && ($this->fullAccess || DepartmentRoleAccess::hasFinancialScope($pdo,$churchId,$userId));",
        "treasury canPost")
    start=txt.index("        return $this->query(\n",txt.index("public function departments():array"))
    end=txt.index("    }\n",start)
    txt=txt[:start]+"""        return DepartmentRoleAccess::listFinancialDepartments($this->pdo,$this->churchId,$this->userId);
"""+txt[end:]
    marker="$allowed=$this->query('SELECT 1 FROM department_access"
    start=txt.find(marker)
    if start<0:raise RuntimeError("Scoped department access anchor missing")
    end=txt.find("->fetchColumn();",start)
    if end<0:raise RuntimeError("Scoped department access end missing")
    txt=txt[:start]+"$allowed=DepartmentRoleAccess::hasFinancialScope($this->pdo,$this->churchId,$this->userId,$departmentId);"+txt[end+len("->fetchColumn();"):]
    txt=once(txt,"O contador possui acesso de consulta, não de movimentação.",
       "O contador ou seu perfil não pode lançar movimentações neste departamento.","treasury denial")
    return txt.encode("utf-8")


def modify_portal_service(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="require_once __DIR__.'/FinancePeriodGuard.php';"
    txt=once(txt,anchor,anchor+"\nrequire_once __DIR__.'/DepartmentRoleAccess.php';","portal helper")
    before="""            if($this->auth->churchRole()!=='department_leader') return [];
            $sql.=' AND EXISTS (SELECT 1 FROM department_access x WHERE x.church_id=d.church_id AND x.department_id=d.id AND x.user_id=?)';
            $params[]=$user['id'];"""
    after="""            if(!$this->auth->can('department_portal'))return [];
            $sql.=" AND EXISTS (
                SELECT 1 FROM member_department_roles r
                JOIN church_members cm ON cm.person_id=r.member_id AND cm.church_id=r.church_id
                JOIN members m ON m.id=r.member_id AND m.church_id=r.church_id AND m.active=1
                WHERE r.church_id=d.church_id AND r.department_id=d.id
                  AND r.role_name IN ('leader','treasurer') AND cm.user_id=?)";
            $params[]=$user['id'];"""
    txt=once(txt,before,after,"portal department scopes")
    txt=once(txt,
       "    public function createEntry(string $departmentId,array $input): string\n    {\n",
       "    public function createEntry(string $departmentId,array $input): string\n    {\n        if(!$this->auth->can('finance'))throw new RuntimeException('Use Tesouraria do Departamento e anexe comprovantes para despesas.');\n",
       "protect legacy portal entry")
    txt=once(txt,
       "    public function reverse(string $departmentId,string $entryId,string $reason): void\n    {\n",
       "    public function reverse(string $departmentId,string $entryId,string $reason): void\n    {\n        if(!$this->auth->can('finance'))throw new RuntimeException('Estornos precisam da tesouraria geral.');\n",
       "protect legacy portal reversal")
    return txt.encode("utf-8")


def modify_portal_page(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="if($error||!$department||$view!=='department')throw new RuntimeException('Selecione um departamento autorizado.');"
    txt=once(txt,anchor,anchor+"\n        if(!$auth->can('finance'))throw new RuntimeException('Use a Tesouraria do Departamento para lançamentos com comprovantes.');",
      "portal post guard")
    txt=once(txt,
      "<?php if($department&&!empty($department['account_id'])):?><button type=\"button\"",
      "<?php if($auth->can('finance')&&$department&&!empty($department['account_id'])):?><button type=\"button\"",
      "portal readonly button")
    txt=once(txt,
      "<?php if($department&&!empty($department['account_id'])):?>\n<dialog",
      "<?php if($auth->can('finance')&&$department&&!empty($department['account_id'])):?>\n<dialog",
      "portal readonly dialog")
    marker="<nav class=\"portal-tabs no-print\" aria-label=\"Áreas do portal\">"
    txt=once(txt,marker,marker+"\n<?php if($auth->can('department_treasury')):?><a href=\"/department-treasury.php\">Caixa com comprovantes</a><?php endif;?>",
      "portal treasury link")
    return txt.encode("utf-8")


def modify_member_department(source:bytes)->bytes:
    txt=source.decode("utf-8")
    txt=once(txt,
        "if(!in_array($role,['participant','leader'],true))",
        "if(!in_array($role,['participant','leader','treasurer'],true))",
        "department roles accepted")
    txt=once(txt,
        "if($role==='leader'&&!$this->auth->can('manage_users'))",
        "if(in_array($role,['leader','treasurer'],true)&&!$this->auth->can('manage_users'))",
        "admin leader and treasurer assignment")
    anchor="        $outer=$this->pdo->inTransaction();\n        if(!$outer)$this->pdo->beginTransaction();\n        try {"
    new="""        $existingRole=$this->q('SELECT role_name FROM member_department_roles WHERE church_id=? AND member_id=? AND department_id=?',
            [$this->cid,$memberId,$departmentId])->fetchColumn();
        if(in_array($existingRole,['leader','treasurer'],true)&&$existingRole!==$role&&!$this->auth->can('manage_users'))
            throw new RuntimeException('Somente o pastor ou administrador pode alterar uma função de liderança ou tesouraria.');
        $outer=$this->pdo->inTransaction();
        if(!$outer)$this->pdo->beginTransaction();
        try {"""
    txt=once(txt,anchor,new,"role demotion security")
    anchor="""            $active=$role==='leader'?$this->syncLeaderLogin($memberId,$departmentId):false;"""
    replacement="""            if($role==='leader')$this->syncLeaderLogin($memberId,$departmentId);
            $active=(bool)$this->q('SELECT 1 FROM church_members cm JOIN users u ON u.id=cm.user_id AND u.active=1
                WHERE cm.church_id=? AND cm.person_id=? LIMIT 1',[$this->cid,$memberId])->fetchColumn();"""
    txt=once(txt,anchor,replacement,"existing member login")
    txt=once(txt,"return $role==='leader'&& !$active?'pending_login':'saved';",
       "return in_array($role,['leader','treasurer'],true)&&!$active?'pending_login':'saved';",
       "leader treasurer status")
    txt=once(txt,"if($current==='leader'&&!$this->auth->can('manage_users'))",
       "if(in_array($current,['leader','treasurer'],true)&&!$this->auth->can('manage_users'))",
       "protect treasurer removal")
    return txt.encode("utf-8")


def modify_department_people(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor='<option value="leader">Líder do departamento</option>'
    txt=once(txt,anchor,anchor+"\n<?php if($auth->can('manage_users')):?><option value=\"treasurer\">Tesoureiro deste departamento (não da igreja)</option><?php endif;?>",
      "department role dropdown")
    txt=once(txt,
       "<?=e($row['role_name']==='leader'?'Líder':'Participante')?>",
       "<?=e(match($row['role_name']){'leader'=>'Líder','treasurer'=>'Tesoureiro departamental',default=>'Participante'})?>",
       "department role badge")
    txt=once(txt,
       "<?php if($row['role_name']==='leader'):?>",
       "<?php if(in_array($row['role_name'],['leader','treasurer'],true)):?>",
       "department role status")
    txt=once(txt,
       "<?=($row['user_id']&&$row['church_role']==='department_leader')?'Login de líder vinculado':'Pendente de login de líder'?>",
       "<?=!empty($row['user_id'])?'Login pessoal vinculado':'Pendente de login pessoal'?>",
       "department login status")
    txt=once(txt,
       'A indicação como líder não cria senha automaticamente. Para entrar no caixa e nos módulos de liderança, a pessoa precisa de um acesso com o perfil <strong>Líder de departamento</strong> em Usuários e Permissões.',
       'Participantes continuam apenas no Portal do Membro. Líder e tesoureiro departamental recebem acesso ao caixa somente quando têm login pessoal vinculado ao cadastro. Não recebem a tesouraria geral.',
       "explanation")
    return txt.encode("utf-8")


def modify_treasury_page(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor='<div class="actions">\n<?php if($service->mayPost()&&$selected):?>'
    txt=once(txt,anchor,
       '<div class="actions">\n<?php if($auth->can("department_team_manage")):?><a href="/department-team.php" class="btn btn-light">Equipe / Indicar tesoureiro</a><?php endif;?>\n<?php if($service->mayPost()&&$selected):?>',
       "treasury team shortcut")
    txt=once(txt,
       'O líder precisa anexar comprovante nas saídas. Os documentos ficam arquivados para a tesouraria geral e o contador.',
       'Líder e tesoureiro do departamento precisam anexar comprovante nas saídas. O pastor, a tesouraria geral e o contador acompanham pelo financeiro consolidado.',
       "treasurer proof explanation")
    return txt.encode("utf-8")


def modify_portal_home(source:bytes)->bytes:
    txt=source.decode("utf-8")
    anchor="<?php if($auth->can('department_treasury')):?>"
    if anchor not in txt:raise RuntimeError("Member portal scoped finance link missing")
    txt=txt.replace(anchor,"""<?php if($auth->can('department_team_manage')):?><a href="/department-team.php"><strong>Equipe do meu departamento</strong><small>Indicar tesoureiro entre os participantes</small></a><?php endif?>\n      """+anchor,1)
    return txt.encode('utf-8')


def modified_files(existing:dict[str,bytes])->dict[str,bytes]:
    files=dict(existing)
    for source in (STAGING/"release").rglob("*"):
        if source.is_file():
            rel=source.relative_to(STAGING/"release").as_posix()
            if not rel.startswith("public/"):raise RuntimeError("Unexpected staging path "+rel)
            files[rel]=source.read_bytes()
    files["public/lib/View.php"]=modify_view(files["public/lib/View.php"])
    files["public/lib/Auth.php"]=modify_auth(files["public/lib/Auth.php"])
    files["public/lib/DepartmentTreasuryService.php"]=modify_treasury_service(files["public/lib/DepartmentTreasuryService.php"])
    files["public/lib/PortalService.php"]=modify_portal_service(files["public/lib/PortalService.php"])
    files["public/portal.php"]=modify_portal_page(files["public/portal.php"])
    files["public/lib/MemberDepartmentService.php"]=modify_member_department(files["public/lib/MemberDepartmentService.php"])
    files["public/department-people.php"]=modify_department_people(files["public/department-people.php"])
    files["public/department-treasury.php"]=modify_treasury_page(files["public/department-treasury.php"])
    files["public/portal-home.php"]=modify_portal_home(files["public/portal-home.php"])
    files["public/members.php"]=modify_members(files["public/members.php"])
    files["public/member-profile.php"]=modify_member_profile(files["public/member-profile.php"])
    files["public/master-members.php"]=modify_master_members(files["public/master-members.php"])
    files["public/VERSION"]=(VERSION+"\n").encode()
    changelog=files.get("public/CHANGELOG.md",b"").decode("utf-8",errors="replace")
    heading=f"## {VERSION} — Participantes, líderes e tesoureiros de cada departamento\n"
    notes=(
        "- Participante do departamento mantém o portal pessoal e não visualiza seu caixa.\n"
        "- Líder designado recebe acesso exclusivamente aos ministérios que lidera, mesmo com login pessoal de membro.\n"
        "- Líder pode indicar e revogar tesoureiro entre participantes somente do seu departamento.\n"
        "- Tesoureiro departamental não recebe tesouraria geral, apenas caixas, relatórios e lançamentos do seu departamento.\n"
        "- Entradas e saídas continuam consolidadas na tesouraria geral e no contador sem duplicação.\n"
        "- Rota antiga de lançamentos do portal fica somente consulta para perfis departamentais; comprovantes seguem obrigatórios.\n"
        "- Isolamento por igreja, escopo por departamento e auditoria de delegações.\n\n"
    )
    if heading not in changelog:changelog=heading+notes+changelog
    files["public/CHANGELOG.md"]=changelog.encode("utf-8")
    return files


def lint_and_assert(files:dict[str,bytes]):
    needed=["public/department-team.php","public/lib/DepartmentDelegationService.php",
            "public/lib/DepartmentRoleAccess.php"]
    for path in needed:assert path in files,path
    assert b"verify_csrf()" in files["public/department-team.php"]
    assert b"member_department_roles" in files["public/lib/DepartmentRoleAccess.php"]
    assert b"department_team_manage" in files["public/lib/Auth.php"]
    assert b"DepartmentRoleAccess::hasFinancialScope" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"DepartmentRoleAccess::listFinancialDepartments" in files["public/lib/DepartmentTreasuryService.php"]
    assert b"department-team.php" in files["public/lib/View.php"]
    assert b"if(!$this->auth->can('finance'))" in files["public/lib/PortalService.php"]
    assert b"treasurer" in files["public/lib/MemberDepartmentService.php"]
    with tempfile.TemporaryDirectory(prefix="igreja-master-3714-") as d:
        for name,data in files.items():
            path=Path(d)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
            if name.endswith(".php"):
                proc=subprocess.run(["php","-l",str(path)],capture_output=True,text=True)
                if proc.returncode:raise RuntimeError("PHP syntax error "+name+" "+proc.stdout+proc.stderr)
        if os.getenv("TEST_ACCOUNTING_DSN"):
            env=dict(os.environ);env["ACCOUNTING_RELEASE_DIR"]=d
            proc=subprocess.run(["php",str(STAGING/"tests"/"integration.php")],
                env=env,capture_output=True,text=True,timeout=170)
            if proc.returncode:raise RuntimeError("3.7.14 suite:\n"+proc.stdout+"\n"+proc.stderr)
            print(proc.stdout.strip())
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
      "cache_buster":"3.7.14-department-leader-treasurer",
      "notes":[
        "Participante continua no Portal do Membro, sem acesso financeiro do departamento.",
        "Líder recebe acesso restrito somente aos departamentos que lidera.",
        "Líder pode indicar e remover tesoureiro entre participantes de sua equipe.",
        "Tesoureiro departamental movimenta só o caixa do departamento; não recebe tesouraria geral.",
        "Comprovante obrigatório nas despesas de perfis departamentais; consolidação geral mantida.",
        "Permissões por igreja e departamento verificadas por testes automatizados."
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
