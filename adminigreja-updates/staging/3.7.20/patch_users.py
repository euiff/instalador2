"""Safe, anchored transformation of the existing church user registration UI and route."""


def replace_once(text:str,old:str,new:str,label:str)->str:
    if text.count(old)!=1:
        raise RuntimeError(f"3.7.20 users.php mismatch: {label}, expected one anchor found {text.count(old)}")
    return text.replace(old,new,1)


def patch_users(source:bytes)->bytes:
    txt=source.decode("utf-8")
    txt=replace_once(txt,"require __DIR__.'/bootstrap.php';",
        "require __DIR__.'/bootstrap.php';\nrequire_once __DIR__.'/lib/ChurchMemberLeadershipService.php';",
        "loader")
    txt=replace_once(txt,
        "$isMaster=$auth->hasRole('super_admin',(string)$user['id']);",
        "$isMaster=$auth->hasRole('super_admin',(string)$user['id']);\n"
        "// Create required role table BEFORE any transaction (MariaDB DDL autocommits).\n"
        "MemberDepartmentService::ensure($pdo);\n"
        "$leadAccess=new ChurchMemberLeadershipService($pdo,$auth,$cid,(string)$user['id']);",
        "service init")
    txt=replace_once(txt,
        "    if($role==='department_leader'&&!$ids)throw new RuntimeException('Selecione pelo menos um departamento para o líder.');",
        "    if($role==='department_leader'&&!$personId)throw new RuntimeException('Selecione um membro cadastrado para nomear líder do departamento.');\n"
        "    if($role==='department_leader'&&!$ids)throw new RuntimeException('Selecione pelo menos um departamento para o líder.');",
        "leader personal membership required")
    txt=replace_once(txt,
        "$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1 ORDER BY name');$q->execute([$cid]);$portalPeople=$q->fetchAll();",
        """$q=$pdo->prepare('SELECT m.id,m.name,m.email,cm.user_id linked_user_id,u.email login_email
    FROM members m
    LEFT JOIN church_members cm ON cm.church_id=m.church_id AND cm.person_id=m.id
    LEFT JOIN users u ON u.id=cm.user_id
    WHERE m.church_id=? AND m.active=1 ORDER BY m.name');
$q->execute([$cid]);$portalPeople=$q->fetchAll();""",
        "member list with existing email")
    txt=replace_once(txt,
        '<option value="<?=e($p[\'id\'])?>" <?=$personId===$p[\'id\']?\'selected\':\'\'?>><?=e($p[\'name\'])?></option>',
        '<option value="<?=e($p[\'id\'])?>" '
        'data-member-name="<?=e($p[\'name\'])?>" '
        'data-member-email="<?=e((string)(($p[\'login_email\']??\'\')?:($p[\'email\']??\'\')))?>" '
        'data-member-login="<?=!empty($p[\'linked_user_id\'])?\'1\':\'0\'?>" '
        '<?=$personId===$p[\'id\']?\'selected\':\'\'?>><?=e($p[\'name\'])?></option>',
        "member-select automatic fields")

    begin=txt.index("        if($action==='create'){")
    end=txt.index("        if($action==='role'){",begin)
    new_create="""        if($action==='create'){
            $personId=trim((string)($_POST['person_id']??''));
            $name=trim((string)($_POST['full_name']??''));
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $password=(string)($_POST['password']??'');
            $role=(string)($_POST['church_role']??'secretary');
            if(!in_array($role,$allowed,true))throw new RuntimeException('Você não pode conceder esse perfil.');

            // When an existing member was selected, the trusted identity is
            // always the members row of THIS church, not retyped form fields.
            $linked=null;$memberInfo=null;
            if($personId!==''){
                $memberInfo=$leadAccess->member($personId);
                $linked=$leadAccess->linkedLogin($personId);
                $name=trim((string)$memberInfo['name']);
                $storedEmail=mb_strtolower(trim((string)($memberInfo['email']??'')));
                $email=$linked?mb_strtolower(trim((string)$linked['email'])):
                    ($storedEmail!==''?$storedEmail:$email);
            }
            if($role==='department_leader'&&$personId==='')
                throw new RuntimeException('Selecione o membro cadastrado para tornar líder.');
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))
                throw new RuntimeException('Informe nome e e-mail válido. Se o membro não tem e-mail, cadastre um endereço pessoal para criar o login.');

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id,full_name,active FROM users WHERE email=? LIMIT 1');
                $q->execute([$email]);$existing=$q->fetch(PDO::FETCH_ASSOC);
                if($linked && (!$existing||(string)$linked['user_id']!==(string)$existing['id'])){
                    throw new RuntimeException('O cadastro já possui outro login. Confira o e-mail e solicite correção antes de mudar a função.');
                }
                if($existing){
                    $targetUserId=(string)$existing['id'];
                    if(!$existing['active'])throw new RuntimeException('Este e-mail pertence a uma conta desativada.');
                    if($auth->isSuperAdmin($targetUserId))throw new RuntimeException('O Master não pode ser vinculado como usuário de igreja.');
                }else{
                    if(strlen($password)<8)throw new RuntimeException('Para um novo usuário, informe senha inicial com pelo menos 8 caracteres.');
                    $targetUserId=app_uuid();
                    $pdo->prepare('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)')
                        ->execute([$targetUserId,$email,password_hash($password,PASSWORD_DEFAULT),$name]);
                    $pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')
                        ->execute([app_uuid(),$targetUserId,$name]);
                }

                $check=$pdo->prepare('SELECT id,person_id,church_role,is_owner FROM church_members WHERE church_id=? AND user_id=? FOR UPDATE');
                $check->execute([$cid,$targetUserId]);$membership=$check->fetch(PDO::FETCH_ASSOC);
                $oldMemberId=null;$oldDeptIds=[];$upgraded=false;
                if($membership){
                    if($personId===''||(
                        (string)($membership['person_id']??'')!=='' &&
                        (string)$membership['person_id']!==$personId)){
                        throw new RuntimeException('Esta conta já pertence a um cadastro diferente desta igreja. Use a edição do acesso existente.');
                    }
                    if(!empty($membership['is_owner']))
                        throw new RuntimeException('Não é permitido alterar o acesso do proprietário nesta tela.');
                    if($targetUserId===(string)$user['id']&&!$isMaster)
                        throw new RuntimeException('Você não pode alterar seu próprio perfil de acesso.');
                    if(!$isMaster&&$currentRole!=='owner'&&!in_array((string)$membership['church_role'],$allowed,true))
                        throw new RuntimeException('Você não pode alterar esse perfil de acesso.');
                    $oldMemberId=(string)($membership['person_id']??'')?:null;
                    $oldDeptIds=$leadAccess->oldDepartmentIds($targetUserId);
                    $pdo->prepare('UPDATE church_members SET church_role=?,permissions=NULL WHERE id=? AND church_id=? AND is_owner=0')
                        ->execute([$role,(string)$membership['id'],$cid]);
                    $upgraded=true;
                }else{
                    $countQ=$pdo->prepare('SELECT COUNT(*) FROM church_members WHERE church_id=?');
                    $countQ->execute([$cid]);$currentUsers=(int)$countQ->fetchColumn();
                    $maxUsers=isset($church['max_users'])&&$church['max_users']!==null?(int)$church['max_users']:null;
                    if($maxUsers!==null&&$currentUsers>=$maxUsers)
                        throw new RuntimeException('Esta igreja atingiu o limite de usuários do plano.');

                    $pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role,permissions) VALUES(?,?,?,0,?,NULL)')
                        ->execute([app_uuid(),$cid,$targetUserId,$role]);
                }

                $scope=$_POST;
                $scope['person_id']=$personId;
                save_portal_scope($pdo,$cid,$targetUserId,$role,$scope);
                $ids=is_array($_POST['department_ids']??null)?$_POST['department_ids']:[];
                $leadAccess->sync($personId?:null,$role,$ids,$oldMemberId,$oldDeptIds);

                if($memberInfo && trim((string)($memberInfo['email']??''))===''){
                    $pdo->prepare('UPDATE members SET email=? WHERE id=? AND church_id=? AND active=1')
                        ->execute([$email,$personId,$cid]);
                }
                church_user_audit($pdo,(string)$user['id'],$cid,$upgraded?'church_member_login_promoted':'church_user_created',[
                    'target_user_id'=>$targetUserId,'member_id'=>$personId,
                    'church_role'=>$role,'departments'=>$role==='department_leader'?$ids:[],
                    'existing_login'=>(bool)$existing
                ]);
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            flash('success',$upgraded
              ?'Acesso existente atualizado. A liderança foi vinculada ao departamento escolhido, sem criar outro login ou trocar a senha.'
              :'Acesso criado e vínculos salvos. O líder já pode entrar no departamento autorizado com este login.');
            redirect('/users.php');
        }

"""
    txt=txt[:begin]+new_create+txt[end:]

    # Existing access editor must also keep the true department-leader record
    # synchronized with the leader scope, including revocation.
    txt=replace_once(txt,
        "            $pdo->beginTransaction();\n            try {\n            $pdo->prepare('UPDATE church_members SET church_role=?,permissions=NULL WHERE id=? AND church_id=?')",
        "            $oldMemberId=trim((string)($target['person_id']??''))?:null;\n"
        "            $oldDeptIds=$leadAccess->oldDepartmentIds((string)$target['user_id']);\n"
        "            $pdo->beginTransaction();\n            try {\n"
        "            $pdo->prepare('UPDATE church_members SET church_role=?,permissions=NULL WHERE id=? AND church_id=?')",
        "existing role previous-member snapshot")
    txt=replace_once(txt,
        "            save_portal_scope($pdo,$cid,(string)$target['user_id'],$role,$_POST);\n            church_user_audit(",
        "            save_portal_scope($pdo,$cid,(string)$target['user_id'],$role,$_POST);\n"
        "            $memberId=trim((string)($_POST['person_id']??''))?:null;\n"
        "            $newDeptIds=is_array($_POST['department_ids']??null)?$_POST['department_ids']:[];\n"
        "            $leadAccess->sync($memberId,$role,$newDeptIds,$oldMemberId,$oldDeptIds);\n"
        "            church_user_audit(",
        "existing role explicit leadership sync")

    txt=replace_once(txt,
        '<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">',
        '<form method="post" data-user-create-from-member="1"><?=csrf_field()?><input type="hidden" name="action" value="create">',
        "member autofill scoped creation form")
    txt=replace_once(txt,
        '      <?php portal_scope_fields($portalPeople,$portalDepartments);?>',
        '      <?php portal_scope_fields($portalPeople,$portalDepartments);?>\n'
        '      <div class="field full"><small class="muted" data-user-member-help>Escolha o cadastro do membro acima para usar seu nome e e-mail automaticamente.</small></div>',
        "portal person auto-fill hint")
    txt=replace_once(txt,
        '<small class="muted">Obrigatória somente quando o e-mail ainda não existe na plataforma.</small>',
        '<small class="muted" data-user-password-help>Obrigatória apenas para uma conta nova. Se já existe login, a senha atual não será alterada.</small>',
        "existing login password hint")
    txt=replace_once(txt,
        '<button class="btn btn-primary" style="width:100%;margin-top:14px">Criar usuário em <?=e($church[\'name\'])?></button>',
        '<button class="btn btn-primary" type="submit" style="width:100%;margin-top:14px">Criar ou atualizar acesso nesta igreja</button>',
        "create or upgrade call-to-action")
    txt=replace_once(txt,
        '<?php View::footer();?>',
        '<script src="/assets/users-member-autofill.js?v=3720" defer></script>\n<?php View::footer();?>',
        "autofill script")
    return txt.encode("utf-8")
