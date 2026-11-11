<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$auth->requireAbility('department_team_manage');
$church=$auth->currentChurch();if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$error=null;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try {
        verify_csrf();
        $userId=trim((string)($_POST['user_id']??''));
        $departmentId=trim((string)($_POST['department_id']??''));
        $action=trim((string)($_POST['action']??''));
        $check=$pdo->prepare('SELECT cm.user_id,cm.church_role,u.full_name,u.email FROM church_members cm JOIN users u ON u.id=cm.user_id WHERE cm.church_id=? AND cm.user_id=? AND u.active=1 LIMIT 1');
        $check->execute([$cid,$userId]);$user=$check->fetch(PDO::FETCH_ASSOC);
        if(!$user)throw new RuntimeException('Escolha uma pessoa que já tenha login nesta igreja.');
        if(in_array($user['church_role'],['owner','admin','pastor','super_admin'],true))
            throw new RuntimeException('O acesso de pastor ou administrador não pode ser transferido nesta tela.');
        if($action==='transfer'){
            $dept=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND id=? AND active=1');
            $dept->execute([$cid,$departmentId]);$selected=$dept->fetch(PDO::FETCH_ASSOC);
            if(!$selected)throw new RuntimeException('Selecione um departamento ativo.');
            $pdo->beginTransaction();
            try {
                // Substituição explícita: a pessoa deixa o departamento anterior e
                // recebe acesso APENAS ao departamento escolhido.
                $pdo->prepare('DELETE FROM department_access WHERE church_id=? AND user_id=?')->execute([$cid,$userId]);
                $pdo->prepare('INSERT INTO department_access(church_id,user_id,department_id) VALUES(?,?,?)')
                    ->execute([$cid,$userId,$departmentId]);
                $pdo->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)')
                    ->execute([app_uuid(),$auth->requireLogin()['id'],$cid,'department_finance_scope_transferred',
                        json_encode(['target_user_id'=>$userId,'department_id'=>$departmentId],JSON_THROW_ON_ERROR)]);
                $pdo->commit();
            } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Acesso departamental transferido para '.$selected['name'].'. O vínculo antigo foi retirado.');
        }elseif($action==='remove'){
            $pdo->prepare('DELETE FROM department_access WHERE church_id=? AND user_id=? AND department_id=?')
                ->execute([$cid,$userId,$departmentId]);
            flash('success','Acesso a este departamento retirado.');
        }else throw new RuntimeException('Selecione uma operação válida.');
        redirect('/department-finance-team.php');
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$q=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$departments=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare("SELECT cm.user_id,cm.church_role,u.full_name,u.email,m.name AS member_name
 FROM church_members cm JOIN users u ON u.id=cm.user_id
 LEFT JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id
 WHERE cm.church_id=? AND u.active=1 AND cm.church_role NOT IN ('owner','admin','pastor','super_admin')
 ORDER BY COALESCE(m.name,u.full_name,u.email)");
$q->execute([$cid]);$users=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT a.user_id,a.department_id,d.name department_name,u.full_name,u.email
 FROM department_access a INNER JOIN ministry_departments d ON d.id=a.department_id AND d.church_id=a.church_id
 INNER JOIN users u ON u.id=a.user_id WHERE a.church_id=? ORDER BY u.full_name,d.name');
$q->execute([$cid]);$assignments=$q->fetchAll(PDO::FETCH_ASSOC);
View::header('Responsáveis por departamento',$auth,'department-treasury.php');
?>
<div class="module-hero"><h1>Escolher tesoureiro do departamento</h1><p>Selecione uma pessoa com login e diga de qual departamento ela cuidará. É possível transferir de Ação Social para Cantina sem recriar o usuário.</p></div>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?><?php View::flash();?>
<div class="grid two-col">
<section class="card card-pad">
<h2>Transferir responsável — 2 passos</h2>
<form method="post" onsubmit="return confirm('Trocar o departamento desta pessoa? Os acessos departamentais anteriores serão retirados, sem apagar lançamentos.')">
<?=csrf_field()?><input type="hidden" name="action" value="transfer">
<div class="field"><label>1. Quem vai cuidar do caixa?</label><select class="select" name="user_id" required>
<option value="">Escolha alguém com login já criado</option>
<?php foreach($users as $u):?><option value="<?=e($u['user_id'])?>"><?=e(($u['member_name']?:$u['full_name']?:$u['email']).' · '.$u['email'])?></option><?php endforeach?>
</select><small>Não é necessário cadastrar a mesma pessoa novamente.</small></div>
<div class="field"><label>2. Qual departamento correto?</label><select class="select" name="department_id" required><option value="">Selecione o destino</option>
<?php foreach($departments as $d):?><option value="<?=e($d['id'])?>"><?=e($d['name'])?></option><?php endforeach?>
</select></div>
<p class="muted">Ao confirmar, os vínculos antigos de acesso departamental desta pessoa são substituídos. Os registros financeiros de Ação Social continuam em Ação Social.</p>
<button class="btn btn-primary" type="submit">Salvar e transferir departamento</button>
</form>
</section>
<section class="card card-pad"><h2>Quem tem acesso a cada departamento</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Pessoa</th><th>Departamento</th><th>Ação</th></tr></thead><tbody>
<?php foreach($assignments as $a):?><tr><td><?=e($a['full_name']?:$a['email'])?></td><td><?=e($a['department_name'])?></td><td>
<form method="post" onsubmit="return confirm('Retirar este acesso?')"><?=csrf_field()?>
<input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?=e($a['user_id'])?>"><input type="hidden" name="department_id" value="<?=e($a['department_id'])?>">
<button class="btn btn-light">Retirar acesso</button></form></td></tr><?php endforeach?>
<?php if(!$assignments):?><tr><td colspan="3">Nenhuma pessoa vinculada. Utilize o formulário ao lado.</td></tr><?php endif?>
</tbody></table></div>
<p class="muted">Pastores e administradores mantêm as permissões gerais. Esta tela altera apenas os vínculos de acesso departamental.</p>
<a class="btn btn-light" href="/department-treasury.php">Voltar às tesourarias</a>
</section>
</div>
<?php View::footer();?>
