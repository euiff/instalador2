<?php
require __DIR__ . '/bootstrap.php';
$auth->requireAbility('secretary');
$church = $auth->currentChurch();
if (!$church) redirect('/logout.php');
$cid = $church['id'];
require_once __DIR__.'/lib/RegistryService.php';
require_once __DIR__.'/lib/MemberDirectoryService.php';
require_once __DIR__.'/lib/MemberDepartmentService.php';
MemberDepartmentService::ensure($pdo);
$memberDepartments=new MemberDepartmentService($pdo,$auth,$cid,(string)$auth->requireLogin()['id']);
$directory=new MemberDirectoryService($pdo,$auth,$cid);
$registry=new RegistryService($pdo,$auth,$cid);
$personCategories=$registry->all('person_category',true);$families=$registry->all('family',true);$positions=$registry->all('position');$customFields=$registry->all('custom_field');
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$error = null; $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
        $action = (string)($_POST['action'] ?? 'save');
        $id = trim((string)($_POST['id'] ?? ''));
        if ($action === 'toggle') {
            $q = $pdo->prepare('UPDATE members SET active=IF(active=1,0,1) WHERE id=? AND church_id=?');
            $q->execute([$id,$cid]);
            $success = 'Status do membro atualizado.';
        } else {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Informe o nome completo do membro.');
            $congregationId = trim((string)($_POST['congregation_id'] ?? '')) ?: null;
            if ($congregationId) {
                $qc = $pdo->prepare('SELECT 1 FROM congregations WHERE id=? AND church_id=?'); $qc->execute([$congregationId,$cid]);
                if (!$qc->fetchColumn()) throw new RuntimeException('Congregação inválida.');
            }
            $existing=[];
            if($id!==''){
                $q=$pdo->prepare('SELECT * FROM members WHERE church_id=? AND id=?');$q->execute([$cid,$id]);$existing=$q->fetch();
                if(!$existing)throw new RuntimeException('Membro não encontrado nesta igreja.');
            }
            $links=[];
            foreach(['category_id'=>'person_category','family_id'=>'family'] as $key=>$kind){
                $value=trim((string)($_POST[$key]??''))?:null;
                if($value)$registry->find($kind,$value,$value!==($existing[$key]??null));
                $links[$key]=$value;
            }
            $inputExtras=$_POST['extra']??[];if(!is_array($inputExtras))throw new InvalidArgumentException('Campos adicionais inválidos.');
            $extras=json_encode($registry->attributes($inputExtras,json_decode($existing['extra_fields']??'{}',true)?:[]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $data = [
                'congregation_id'=>$congregationId,'name'=>$name,'phone'=>$phone,
                'email'=>trim((string)($_POST['email'] ?? '')) ?: null,
                'birth_date'=>trim((string)($_POST['birth_date'] ?? '')) ?: null,
                'cpf'=>trim((string)($_POST['cpf'] ?? '')) ?: null,
                'rg'=>trim((string)($_POST['rg'] ?? '')) ?: null,
                'role'=>trim((string)($_POST['role'] ?? '')) ?: null,
                'department'=>null,
                'membership_date'=>trim((string)($_POST['membership_date'] ?? '')) ?: null,
                'marital_status'=>trim((string)($_POST['marital_status'] ?? '')) ?: null,
                'naturalness'=>trim((string)($_POST['naturalness'] ?? '')) ?: null,
                'address_city'=>trim((string)($_POST['address_city'] ?? '')) ?: null,
                'address_state'=>trim((string)($_POST['address_state'] ?? '')) ?: null,
                'notes'=>trim((string)($_POST['notes'] ?? '')) ?: null,
                'is_baptized'=>isset($_POST['is_baptized']) ? 1 : 0,
            ];
            $departmentId=trim((string)($_POST['department_id']??''));
            if($departmentId!==''){
                $targetDepartment=$memberDepartments->department($departmentId);
                if(empty($targetDepartment['active']))throw new InvalidArgumentException('Departamento inativo.');
                $data['department']=$targetDepartment['name'];
            }
            $pdo->beginTransaction();
            try{
            if ($id !== '') {
                $sql = 'UPDATE members SET congregation_id=?,name=?,phone=?,email=?,birth_date=?,cpf=?,rg=?,role=?,department=?,membership_date=?,marital_status=?,naturalness=?,address_city=?,address_state=?,notes=?,is_baptized=?,category_id=?,family_id=?,extra_fields=? WHERE id=? AND church_id=?';
                $q = $pdo->prepare($sql);
                $q->execute([$data['congregation_id'],$data['name'],$data['phone'],$data['email'],$data['birth_date'],$data['cpf'],$data['rg'],$data['role'],$data['department'],$data['membership_date'],$data['marital_status'],$data['naturalness'],$data['address_city'],$data['address_state'],$data['notes'],$data['is_baptized'],$links['category_id'],$links['family_id'],$extras,$id,$cid]);
                $success = 'Cadastro atualizado com sucesso.';
            } else {
                $id=app_uuid();
                $sql = 'INSERT INTO members(id,church_id,congregation_id,name,phone,email,birth_date,cpf,rg,role,department,membership_date,marital_status,naturalness,address_city,address_state,notes,is_baptized,category_id,family_id,extra_fields,registration_status,registration_step,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'approved\',\'complete\',1)';
                $q = $pdo->prepare($sql);
                $q->execute([$id,$cid,$data['congregation_id'],$data['name'],$data['phone'],$data['email'],$data['birth_date'],$data['cpf'],$data['rg'],$data['role'],$data['department'],$data['membership_date'],$data['marital_status'],$data['naturalness'],$data['address_city'],$data['address_state'],$data['notes'],$data['is_baptized'],$links['category_id'],$links['family_id'],$extras]);
                $success = 'Membro cadastrado com sucesso.';
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
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$edit = null;
if (!empty($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM members WHERE id=? AND church_id=? LIMIT 1'); $q->execute([(string)$_GET['edit'],$cid]); $edit = $q->fetch() ?: null;
}
$directoryError=null;
try{$listing=$directory->page($_GET,max(1,(int)($_GET['page']??1)));}
catch(InvalidArgumentException $e){$directoryError=$e->getMessage();http_response_code(400);$listing=['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'filters'=>$directory->filters([])];}
$members=$listing['rows'];$filters=$listing['filters'];
$canAssignSystemRoles=$auth->can('manage_users');
$memberAccessRoles=[];
if($members){
    $memberIds=array_values(array_unique(array_map(
        static fn(array $member):string=>(string)$member['id'],$members
    )));
    $placeholders=implode(',',array_fill(0,count($memberIds),'?'));
    $q=$pdo->prepare("SELECT person_id,church_role,is_owner FROM church_members
        WHERE church_id=? AND person_id IN ($placeholders)");
    $q->execute(array_merge([(string)$cid],$memberIds));
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $linked){
        $memberAccessRoles[(string)$linked['person_id']]=!empty($linked['is_owner'])
            ?'owner':(string)$linked['church_role'];
    }
}
$memberAccessNames=[
    'owner'=>'Responsável pela igreja',
    'admin'=>'Administrador',
    'pastor'=>'Pastor',
    'treasurer'=>'Tesoureiro Geral',
    'accountant'=>'Contador',
    'secretary'=>'Secretária',
    'department_leader'=>'Líder de departamento',
    'group_leader'=>'Líder de grupo/célula',
    'teacher'=>'Professor',
    'kids_worker'=>'Equipe Kids',
    'member'=>'Membro (portal)',
];
$search=$filters['q'];$status=$filters['status'];$filterCongregation=$filters['congregation_id'];$filterCategory=$filters['category_id'];$filterFamily=$filters['family_id'];
$filterDepartment=$filters['department_id'];$filterBirthMonth=$filters['birth_month'];$filterBaptism=$filters['baptism'];
if(($_GET['export']??'')==='csv'&&!$directoryError){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="lista-de-membros.csv"');
    $out=fopen('php://output','w');$directory->export($filters,$out);fclose($out);exit;
}
$q=$pdo->prepare('SELECT id,name,active FROM congregations WHERE church_id=? ORDER BY active DESC,sort_order,name');$q->execute([$cid]);$congregations=$q->fetchAll();
$q=$pdo->prepare('SELECT id,name,active FROM ministry_departments WHERE church_id=? ORDER BY active DESC,name');$q->execute([$cid]);$directoryDepartments=$q->fetchAll();
$printUrl='/members-print.php?'.http_build_query($filters);

View::header('Membros', $auth, 'members.php');
?>
<style>.member-directory-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.member-directory-filters .input,.member-directory-filters .select{width:100%!important;min-width:0}@media(max-width:700px){.member-directory-filters{grid-template-columns:1fr}}</style>
<?php if($directoryError):?><div class="alert alert-error" role="alert"><?=e($directoryError)?></div><?php endif?>
<?php if ($error): ?><div class="alert alert-error"><?=e($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
<div class="grid two-col">
<section class="card card-pad">
  <div class="page-head" style="margin-bottom:14px"><div><h1 style="font-size:16px">Lista de membros</h1><p><?=$listing['total']?> resultado(s) · página <?=$listing['page']?> de <?=$listing['pages']?></p></div><div class="actions"><a class="btn btn-primary" href="members.php#cadastro">+ Novo membro</a><?php if($canAssignSystemRoles):?><a class="btn btn-light" href="/users.php#definir-cargo-acesso">Definir cargos e acessos</a><?php endif?></div></div>
  <?php if($canAssignSystemRoles):?>
  <p class="muted">Para tornar um membro Tesoureiro Geral, Contador, Pastor, Secretária ou líder, use <strong>Definir cargo / acesso</strong> na linha da pessoa. O cadastro do membro será reaproveitado, sem duplicação.</p>
  <?php endif?>
  <form method="get" class="toolbar member-directory-filters"><input class="input search" name="q" value="<?=e($search)?>" placeholder="Buscar por nome, telefone, e-mail ou CPF"><select class="select" name="congregation_id" style="width:auto"><option value="">Todas as congregações</option><?php foreach($congregations as $cg):?><option value="<?=e($cg['id'])?>" <?=$filterCongregation===$cg['id']?'selected':''?>><?=e($cg['name'])?><?=$cg['active']?'':' (inativa)'?></option><?php endforeach?></select><select class="select" name="status" style="width:auto"><option value="active" <?=$status==='active'?'selected':''?>>Ativos</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Inativos</option><option value="all" <?=$status==='all'?'selected':''?>>Todos</option></select><select class="select" name="category_id"><option value="">Todas as categorias</option><?php foreach($personCategories as $c):?><option value="<?=e($c['id'])?>" <?=$filterCategory===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select><select class="select" name="family_id"><option value="">Todas as famílias</option><?php foreach($families as $f):?><option value="<?=e($f['id'])?>" <?=$filterFamily===$f['id']?'selected':''?>><?=e($f['name'])?></option><?php endforeach?></select><select class="select" name="department_id" aria-label="Departamento"><option value="">Todos os departamentos</option><?php foreach($directoryDepartments as $d):?><option value="<?=e($d['id'])?>" <?=$filterDepartment===$d['id']?'selected':''?>><?=e($d['name'])?><?=$d['active']?'':' (inativo)'?></option><?php endforeach?></select><select class="select" name="birth_month" aria-label="Mês de aniversário"><option value="">Aniversariantes: todos os meses</option><?php foreach([1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'] as $n=>$label):?><option value="<?=$n?>" <?=$filterBirthMonth===(string)$n?'selected':''?>><?=e($label)?></option><?php endforeach?></select><select class="select" name="baptism" aria-label="Batismo"><?php foreach(['all'=>'Batismo: todos','yes'=>'Batizados','no'=>'Não informado / não batizados'] as $key=>$label):?><option value="<?=e($key)?>" <?=$filterBaptism===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><button class="btn btn-primary">Buscar</button><button class="btn btn-light" name="export" value="csv">Exportar resultados CSV</button><a class="btn btn-light" href="<?=e($printUrl)?>" target="_blank" rel="noopener">Imprimir resultados / PDF</a><a class="btn btn-light" href="/members.php">Limpar filtros</a></form>
  <p class="muted">O filtro de departamento considera os participantes vinculados e o departamento informado no cadastro. CSV e impressão incluem todos os resultados dos filtros.</p><div class="table-wrap"><table class="table"><thead><tr><th>Membro</th><th>Telefone</th><th>Congregação</th><th>Função</th><th>Categoria / família</th><th>Status</th><th></th></tr></thead><tbody>
  <?php if (!$members): ?><tr><td colspan="7"><div class="empty">Nenhum membro encontrado.</div></td></tr><?php endif; ?>
  <?php foreach($members as $m): ?><tr><td><a href="member-profile.php?id=<?=e($m['id'])?>"><strong><?=e($m['name'])?></strong></a><?php if($m['email']): ?><div class="muted" style="font-size:10px"><?=e($m['email'])?></div><?php endif; ?></td><td><?=e($m['phone'])?></td><td><?=e($m['congregation_name'] ?: 'Sede/Não informada')?></td><td><?=e($m['role'] ?: '—')?><?php if(isset($memberAccessRoles[(string)$m['id']])):?><div style="font-size:10px;margin-top:4px"><span class="badge ok">Acesso: <?=e($memberAccessNames[$memberAccessRoles[(string)$m['id']]]??$memberAccessRoles[(string)$m['id']])?></span></div><?php endif?></td><td><?=e($m['person_category_name']?:'—')?><div class="muted"><?=e($m['family_name']?:'')?></div></td><td><span class="badge <?=$m['active']?'ok':'off'?>"><?=$m['active']?'Ativo':'Inativo'?></span></td><td><div class="actions"><a class="btn btn-light" style="padding:7px 9px" href="member-profile.php?id=<?=e($m['id'])?>">Perfil</a><a class="btn btn-light" style="padding:7px 9px" href="?edit=<?=e($m['id'])?>#cadastro">Editar</a><?php if($canAssignSystemRoles && !empty($m['active'])):?><a class="btn btn-primary" style="padding:7px 9px" href="/users.php?member=<?=e(rawurlencode((string)$m['id']))?>#definir-cargo-acesso">Definir cargo / acesso</a><?php endif?></div></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <nav class="actions" aria-label="Páginas da lista de membros" style="margin-top:16px"><?php foreach(['Anterior'=>-1,'Próxima'=>1] as $label=>$offset):$next=$listing['page']+$offset;if($next<1||$next>$listing['pages'])continue;?><a class="btn btn-light" href="?<?=e(http_build_query([...$filters,'page'=>$next]))?>"><?=e($label)?></a><?php endforeach?><span>Página <?=$listing['page']?> de <?=$listing['pages']?> · <?=$listing['total']?> membros</span></nav>
</section>
<section class="card card-pad" id="cadastro">
  <div class="page-head" style="margin-bottom:14px"><div><h1 style="font-size:16px"><?=$edit?'Editar membro':'Novo membro'?></h1><p>Informações principais do cadastro</p></div><?php if($edit): ?><a class="btn btn-light" href="members.php#cadastro">Cancelar</a><?php endif; ?></div>
  <form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id'] ?? '')?>"><input type="hidden" name="action" value="save">
  <div class="form-grid">
    <div class="field full"><label>Nome completo *</label><input class="input" name="name" required value="<?=e($edit['name'] ?? '')?>"></div>
    <div class="field"><label>Telefone</label><input class="input" name="phone" value="<?=e($edit['phone'] ?? '')?>" placeholder="Pode cadastrar sem telefone"></div>
    <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?=e($edit['email'] ?? '')?>"></div>
    <div class="field"><label>Nascimento</label><input class="input" type="date" name="birth_date" value="<?=e($edit['birth_date'] ?? '')?>"></div>
    <div class="field"><label>Data de membresia</label><input class="input" type="date" name="membership_date" value="<?=e($edit['membership_date'] ?? '')?>"></div>
    <div class="field"><label>CPF</label><input class="input" name="cpf" value="<?=e($edit['cpf'] ?? '')?>"></div>
    <div class="field"><label>RG</label><input class="input" name="rg" value="<?=e($edit['rg'] ?? '')?>"></div>
    <div class="field full"><label>Congregação</label><select class="select" name="congregation_id"><option value="">Sede / Não informada</option><?php foreach($congregations as $c): ?><option value="<?=e($c['id'])?>" <?=((string)($edit['congregation_id']??$filterCongregation))===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Função / Cargo</label>
    <select class="select" name="role"><option value="">Selecione um cargo</option>
    <?php $roleOptions=array_values(array_unique(array_filter(array_merge(['Membro'],array_column($positions,'name'),[(string)($edit['role']??'')]))));
    foreach($roleOptions as $positionName):?><option value="<?=e($positionName)?>" <?=($edit['role']??'')===$positionName?'selected':''?>><?=e($positionName)?></option><?php endforeach;?></select><small class="muted">Este é o cargo registrado no cadastro. Para liberar login e permissões de Tesoureiro Geral, Contador, Pastor ou Secretária, use <strong>Definir cargo / acesso</strong> na lista de membros.</small></div>
    <div class="field"><label>Departamento</label>
    <select class="select" name="department_id"><option value="">Nenhum departamento / selecionar depois</option>
    <?php foreach($directoryDepartments as $d):if(empty($d['active'])&&($edit['department']??'')!==$d['name'])continue;?>
    <option value="<?=e($d['id'])?>" <?=($edit['department']??'')===$d['name']?'selected':''?>>
    <?=e($d['name'])?><?=empty($d['active'])?' (inativo)':''?></option><?php endforeach;?></select>
    <small>Selecione da lista salva no sistema. Para vários ministérios ou liderança, use <a href="/department-people.php">Pessoas e Lideranças</a>.</small></div>
    <div class="field"><label>Estado civil</label><input class="input" name="marital_status" value="<?=e($edit['marital_status'] ?? '')?>"></div>
    <div class="field"><label>Naturalidade</label><input class="input" name="naturalness" value="<?=e($edit['naturalness'] ?? '')?>" placeholder="Ex.: Catalão - GO"></div>
    <div class="field"><label>Cidade</label><input class="input" name="address_city" value="<?=e($edit['address_city'] ?? '')?>"></div>
    <div class="field"><label>Estado</label><input class="input" name="address_state" value="<?=e($edit['address_state'] ?? '')?>"></div>
    <div class="field" style="display:flex;align-items:end"><label style="display:flex;gap:8px;align-items:center;margin-bottom:11px"><input type="checkbox" name="is_baptized" value="1" <?=!empty($edit['is_baptized'])?'checked':''?>> Batizado(a)</label></div>
    <?php foreach(['category_id'=>['Categoria de pessoa',$personCategories],'family_id'=>['Família',$families]] as $key=>[$label,$options]):?><div class="field"><label><?=e($label)?></label><select class="select" name="<?=e($key)?>"><option value="">Não informar</option><?php foreach($options as $option):if(!$option['active']&&($edit[$key]??'')!==$option['id'])continue;?><option value="<?=e($option['id'])?>" <?=($edit[$key]??'')===$option['id']?'selected':''?>><?=e($option['name'])?><?=$option['active']?'':' (arquivado)'?></option><?php endforeach?></select></div><?php endforeach?>
    <?php $extraValues=json_decode($edit['extra_fields']??'{}',true)?:[];foreach($customFields as $field):$spec=$field['data'];$value=$extraValues[$field['id']]??'';$required=!empty($spec['required']);?><div class="field"><label><?=e($field['name'])?><?=$required?' *':''?></label><?php if($spec['type']==='choice'):?><select class="select" name="extra[<?=e($field['id'])?>]" <?=$required?'required':''?>><option value="">Selecione</option><?php foreach($spec['options'] as $option):?><option value="<?=e($option)?>" <?=$value===$option?'selected':''?>><?=e($option)?></option><?php endforeach?></select><?php else:?><input class="input" type="<?=$spec['type']==='date'?'date':'text'?>" name="extra[<?=e($field['id'])?>]" value="<?=e($value)?>" maxlength="2000" <?=$required?'required':''?>><?php endif?></div><?php endforeach?>
    <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"><?=e($edit['notes'] ?? '')?></textarea></div>
  </div><button class="btn btn-primary" style="width:100%;margin-top:14px" type="submit"><?=$edit?'Salvar alterações':'Cadastrar membro'?></button></form>
  <?php if($edit): ?><form method="post" style="margin-top:9px" onsubmit="return confirm('Deseja realmente alterar o status deste membro?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id'])?>"><input type="hidden" name="action" value="toggle"><button class="btn <?=$edit['active']?'btn-danger':'btn-light'?>" style="width:100%"><?=$edit['active']?'Desativar membro':'Reativar membro'?></button></form><?php endif; ?>
</section>
</div>
<p class="muted">O cargo do cadastro não libera funções administrativas; o perfil de login é configurado separadamente.</p>\n<datalist id="church-positions"><?php foreach($positions as $position):?><option value="<?=e($position['name'])?>"><?php endforeach?></datalist>
<?php View::footer(); ?>