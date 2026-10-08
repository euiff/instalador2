<?php
require __DIR__ . '/bootstrap.php';
$auth->requireAbility('secretary');
$church = $auth->currentChurch();
if (!$church) redirect('/logout.php');
$cid = $church['id'];
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
            if ($name === '' || $phone === '') throw new InvalidArgumentException('Nome e telefone são obrigatórios.');
            $congregationId = trim((string)($_POST['congregation_id'] ?? '')) ?: null;
            if ($congregationId) {
                $qc = $pdo->prepare('SELECT 1 FROM congregations WHERE id=? AND church_id=?'); $qc->execute([$congregationId,$cid]);
                if (!$qc->fetchColumn()) throw new RuntimeException('Congregação inválida.');
            }
            $data = [
                'congregation_id'=>$congregationId,'name'=>$name,'phone'=>$phone,
                'email'=>trim((string)($_POST['email'] ?? '')) ?: null,
                'birth_date'=>trim((string)($_POST['birth_date'] ?? '')) ?: null,
                'cpf'=>trim((string)($_POST['cpf'] ?? '')) ?: null,
                'rg'=>trim((string)($_POST['rg'] ?? '')) ?: null,
                'role'=>trim((string)($_POST['role'] ?? '')) ?: null,
                'department'=>trim((string)($_POST['department'] ?? '')) ?: null,
                'membership_date'=>trim((string)($_POST['membership_date'] ?? '')) ?: null,
                'marital_status'=>trim((string)($_POST['marital_status'] ?? '')) ?: null,
                'naturalness'=>trim((string)($_POST['naturalness'] ?? '')) ?: null,
                'address_city'=>trim((string)($_POST['address_city'] ?? '')) ?: null,
                'address_state'=>trim((string)($_POST['address_state'] ?? '')) ?: null,
                'notes'=>trim((string)($_POST['notes'] ?? '')) ?: null,
                'is_baptized'=>isset($_POST['is_baptized']) ? 1 : 0,
            ];
            if ($id !== '') {
                $sql = 'UPDATE members SET congregation_id=?,name=?,phone=?,email=?,birth_date=?,cpf=?,rg=?,role=?,department=?,membership_date=?,marital_status=?,naturalness=?,address_city=?,address_state=?,notes=?,is_baptized=? WHERE id=? AND church_id=?';
                $q = $pdo->prepare($sql);
                $q->execute([$data['congregation_id'],$data['name'],$data['phone'],$data['email'],$data['birth_date'],$data['cpf'],$data['rg'],$data['role'],$data['department'],$data['membership_date'],$data['marital_status'],$data['naturalness'],$data['address_city'],$data['address_state'],$data['notes'],$data['is_baptized'],$id,$cid]);
                $success = 'Cadastro atualizado com sucesso.';
            } else {
                $sql = 'INSERT INTO members(id,church_id,congregation_id,name,phone,email,birth_date,cpf,rg,role,department,membership_date,marital_status,address_city,address_state,notes,is_baptized,registration_status,registration_step,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'approved\',\'complete\',1)';
                $q = $pdo->prepare($sql);
                $q->execute([app_uuid(),$cid,$data['congregation_id'],$data['name'],$data['phone'],$data['email'],$data['birth_date'],$data['cpf'],$data['rg'],$data['role'],$data['department'],$data['membership_date'],$data['marital_status'],$data['naturalness'],$data['address_city'],$data['address_state'],$data['notes'],$data['is_baptized']]);
                $success = 'Membro cadastrado com sucesso.';
            }
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$edit = null;
if (!empty($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM members WHERE id=? AND church_id=? LIMIT 1'); $q->execute([(string)$_GET['edit'],$cid]); $edit = $q->fetch() ?: null;
}
$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'active');
$filterCongregation=trim((string)($_GET['congregation_id']??''));
$sql = 'SELECT m.*,c.name congregation_name FROM members m LEFT JOIN congregations c ON c.id=m.congregation_id WHERE m.church_id=?';
$params = [$cid];
if ($status === 'active') $sql .= ' AND m.active=1'; elseif ($status === 'inactive') $sql .= ' AND m.active=0';
if ($filterCongregation!==''){$sql.=' AND m.congregation_id=?';$params[]=$filterCongregation;}
if ($search !== '') { $sql .= ' AND (m.name LIKE ? OR m.phone LIKE ? OR m.email LIKE ? OR m.cpf LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like); }
$sql .= ' ORDER BY m.name LIMIT 500';
$q = $pdo->prepare($sql); $q->execute($params); $members = $q->fetchAll();
$q = $pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY sort_order,name'); $q->execute([$cid]); $congregations=$q->fetchAll();

View::header('Membros', $auth, 'members.php');
?>
<?php if ($error): ?><div class="alert alert-error"><?=e($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
<div class="grid two-col">
<section class="card card-pad">
  <div class="page-head" style="margin-bottom:14px"><div><h1 style="font-size:16px">Lista de membros</h1><p><?=count($members)?> resultado(s)</p></div><a class="btn btn-primary" href="members.php#cadastro">+ Novo membro</a></div>
  <form method="get" class="toolbar"><input class="input search" name="q" value="<?=e($search)?>" placeholder="Buscar por nome, telefone, e-mail ou CPF"><select class="select" name="congregation_id" style="width:auto"><option value="">Todas as congregações</option><?php foreach($congregations as $cg):?><option value="<?=e($cg['id'])?>" <?=$filterCongregation===$cg['id']?'selected':''?>><?=e($cg['name'])?></option><?php endforeach?></select><select class="select" name="status" style="width:auto"><option value="active" <?=$status==='active'?'selected':''?>>Ativos</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Inativos</option><option value="all" <?=$status==='all'?'selected':''?>>Todos</option></select><button class="btn btn-light">Buscar</button><?php if($filterCongregation!==''||$search!==''):?><a class="btn btn-light" href="/members.php">Limpar</a><?php endif?></form>
  <div class="table-wrap"><table class="table"><thead><tr><th>Membro</th><th>Telefone</th><th>Congregação</th><th>Função</th><th>Status</th><th></th></tr></thead><tbody>
  <?php if (!$members): ?><tr><td colspan="6"><div class="empty">Nenhum membro encontrado.</div></td></tr><?php endif; ?>
  <?php foreach($members as $m): ?><tr><td><a href="member-profile.php?id=<?=e($m['id'])?>"><strong><?=e($m['name'])?></strong></a><?php if($m['email']): ?><div class="muted" style="font-size:10px"><?=e($m['email'])?></div><?php endif; ?></td><td><?=e($m['phone'])?></td><td><?=e($m['congregation_name'] ?: 'Sede/Não informada')?></td><td><?=e($m['role'] ?: '—')?></td><td><span class="badge <?=$m['active']?'ok':'off'?>"><?=$m['active']?'Ativo':'Inativo'?></span></td><td><div class="actions"><a class="btn btn-light" style="padding:7px 9px" href="member-profile.php?id=<?=e($m['id'])?>">Perfil</a><a class="btn btn-light" style="padding:7px 9px" href="?edit=<?=e($m['id'])?>#cadastro">Editar</a></div></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<section class="card card-pad" id="cadastro">
  <div class="page-head" style="margin-bottom:14px"><div><h1 style="font-size:16px"><?=$edit?'Editar membro':'Novo membro'?></h1><p>Informações principais do cadastro</p></div><?php if($edit): ?><a class="btn btn-light" href="members.php#cadastro">Cancelar</a><?php endif; ?></div>
  <form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id'] ?? '')?>"><input type="hidden" name="action" value="save">
  <div class="form-grid">
    <div class="field full"><label>Nome completo *</label><input class="input" name="name" required value="<?=e($edit['name'] ?? '')?>"></div>
    <div class="field"><label>Telefone *</label><input class="input" name="phone" required value="<?=e($edit['phone'] ?? '')?>"></div>
    <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?=e($edit['email'] ?? '')?>"></div>
    <div class="field"><label>Nascimento</label><input class="input" type="date" name="birth_date" value="<?=e($edit['birth_date'] ?? '')?>"></div>
    <div class="field"><label>Data de membresia</label><input class="input" type="date" name="membership_date" value="<?=e($edit['membership_date'] ?? '')?>"></div>
    <div class="field"><label>CPF</label><input class="input" name="cpf" value="<?=e($edit['cpf'] ?? '')?>"></div>
    <div class="field"><label>RG</label><input class="input" name="rg" value="<?=e($edit['rg'] ?? '')?>"></div>
    <div class="field full"><label>Congregação</label><select class="select" name="congregation_id"><option value="">Sede / Não informada</option><?php foreach($congregations as $c): ?><option value="<?=e($c['id'])?>" <?=((string)($edit['congregation_id']??$filterCongregation))===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Função / Cargo</label><input class="input" name="role" value="<?=e($edit['role'] ?? '')?>"></div>
    <div class="field"><label>Departamento</label><input class="input" name="department" value="<?=e($edit['department'] ?? '')?>"></div>
    <div class="field"><label>Estado civil</label><input class="input" name="marital_status" value="<?=e($edit['marital_status'] ?? '')?>"></div>
    <div class="field"><label>Naturalidade</label><input class="input" name="naturalness" value="<?=e($edit['naturalness'] ?? '')?>" placeholder="Ex.: Catalão - GO"></div>
    <div class="field"><label>Cidade</label><input class="input" name="address_city" value="<?=e($edit['address_city'] ?? '')?>"></div>
    <div class="field"><label>Estado</label><input class="input" name="address_state" value="<?=e($edit['address_state'] ?? '')?>"></div>
    <div class="field" style="display:flex;align-items:end"><label style="display:flex;gap:8px;align-items:center;margin-bottom:11px"><input type="checkbox" name="is_baptized" value="1" <?=!empty($edit['is_baptized'])?'checked':''?>> Batizado(a)</label></div>
    <div class="field full"><label>Observações</label><textarea class="textarea" name="notes"><?=e($edit['notes'] ?? '')?></textarea></div>
  </div><button class="btn btn-primary" style="width:100%;margin-top:14px" type="submit"><?=$edit?'Salvar alterações':'Cadastrar membro'?></button></form>
  <?php if($edit): ?><form method="post" style="margin-top:9px" onsubmit="return confirm('Deseja realmente alterar o status deste membro?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($edit['id'])?>"><input type="hidden" name="action" value="toggle"><button class="btn <?=$edit['active']?'btn-danger':'btn-light'?>" style="width:100%"><?=$edit['active']?'Desativar membro':'Reativar membro'?></button></form><?php endif; ?>
</section>
</div>
<?php View::footer(); ?>