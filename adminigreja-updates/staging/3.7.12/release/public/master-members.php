<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MasterMemberService.php';
require_once __DIR__.'/lib/EnuvesImportService.php';
$user=$auth->requireRole('super_admin');
EnuvesImportService::ensureSchema($pdo);
$manager=new MasterMemberService($pdo,$auth,(string)$user['id']);
$cid=trim((string)($_GET['church']??$_POST['church_id']??''));
$q=$pdo->query('SELECT id,name FROM churches ORDER BY name');
$churches=$q->fetchAll(PDO::FETCH_ASSOC);
if($cid==='')foreach($churches as $row)if(mb_strtolower(trim($row['name']))==='igreja de deus campo belo'){$cid=$row['id'];break;}
$churchName='';
foreach($churches as $row)if($row['id']===$cid){$churchName=$row['name'];break;}
$search=trim((string)($_GET['q']??''));
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        if($churchName==='')throw new InvalidArgumentException('Igreja de destino inválida.');
        $action=(string)($_POST['action']??'');
        if($action==='purge' && ($_POST['purge_confirmation']??'')!=='EXCLUIR')
            throw new InvalidArgumentException('Para exclusão definitiva digite EXCLUIR.');
        $manager->change($cid,(string)($_POST['member_id']??''),$action,
            (string)($_POST['confirm_name']??''),(string)($_POST['reason']??''));
        flash('success',match($action){
            'archive'=>'Membro arquivado. Histórico e lançamentos preservados.',
            'restore'=>'Cadastro reativado.',
            default=>'Cadastro excluído definitivamente após verificação de vínculos.'
        });
        redirect('/master-members.php?church='.rawurlencode($cid).'#people');
    }catch(Throwable $e){
        error_log('master member management: '.get_class($e).' '.$e->getMessage());
        $error=$e instanceof PDOException?'O banco impediu a operação; nenhum histórico foi removido.':$e->getMessage();
    }
}
$listing=$churchName!==''?$manager->members($cid,$search,(int)($_GET['page']??1))
    :['rows'=>[],'page'=>1,'pages'=>1,'total'=>0];
View::header('Gerenciar membros no Master',$auth,'master-members.php');
View::flash();
?>
<style>
.mm-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:end}
.mm-toolbar label,.mm-modal label{display:grid;gap:5px;min-width:195px}
.mm-table{overflow-x:auto}.mm-table table{min-width:700px;width:100%;border-collapse:collapse}
.mm-table td,.mm-table th{padding:11px;text-align:left;border-bottom:1px solid #e5ebef}
.mm-badge{display:inline-flex;border-radius:999px;padding:4px 9px;background:#eff7fa;color:#27627a;font-size:11px}
.mm-muted{background:#f7f4ef;color:#856c32}
.mm-modal{width:min(500px,calc(100vw - 24px));max-height:92vh;overflow:auto;padding:24px;border:0;border-radius:16px}
.mm-modal::backdrop{background:#0e273c99}
.mm-danger{background:#991b1b;color:#fff;border:0;padding:10px 15px;border-radius:9px;cursor:pointer}
.mm-info{padding:13px 15px;border-radius:12px;background:#fff9ed;border:1px solid #f1ddb5;margin:14px 0}
</style>
<section class="module-toolbar"><div class="module-title"><h1>Gerenciar membros no Master</h1>
<p>Arquive, restaure ou solicite exclusão definitiva, sem misturar cadastros de outras igrejas.</p></div>
<div class="actions"><a class="btn btn-light" href="/master-enuves.php?church=<?=rawurlencode($cid)?>">Importar Enuves</a><a class="btn btn-light" href="/master.php">Painel Master</a></div></section>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="card card-pad" id="people">
<form method="get" class="mm-toolbar">
<label>Igreja<select class="select" name="church" required>
<option value="">Selecione a igreja</option><?php foreach($churches as $c):?><option value="<?=e($c['id'])?>" <?=$c['id']===$cid?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></label>
<label>Buscar pelo nome ou telefone<input class="input" type="search" name="q" value="<?=e($search)?>" maxlength="100" placeholder="Nome do membro"></label>
<button class="btn btn-primary">Buscar membros</button></form>
<?php if($churchName!==''):?>
<div class="mm-info"><strong>Exclusão segura:</strong> arquivar retira o membro das listas de ativos, mantendo contribuições, registros e documentos. Exclusão definitiva exige que não exista nenhum vínculo com outros módulos e confirmação dupla.</div>
<p><?=e((string)$listing['total'])?> cadastros em <strong><?=e($churchName)?></strong></p>
<div class="mm-table"><table><thead><tr><th>Nome</th><th>Telefone</th><th>Situação</th><th>Ações Master</th></tr></thead><tbody>
<?php foreach($listing['rows'] as $m):?>
<tr><td><strong><?=e($m['name'])?></strong></td><td><?=e($m['phone'])?></td>
<td><span class="mm-badge <?=$m['active']?'':'mm-muted'?>"><?=$m['active']?'Ativo':'Arquivado'?></span></td>
<td><button type="button" class="btn btn-light mm-open" data-member-id="<?=e($m['id'])?>"
 data-member-name="<?=e($m['name'])?>" data-member-active="<?=$m['active']?'1':'0'?>">
 Gerenciar</button></td></tr>
<?php endforeach;?>
<?php if(!$listing['rows']):?><tr><td colspan="4">Nenhum cadastro encontrado.</td></tr><?php endif;?>
</tbody></table></div>
<nav class="actions" aria-label="Páginas" style="margin-top:12px">
<?php if($listing['page']>1):?><a class="btn btn-light" href="?<?=e(http_build_query(['church'=>$cid,'q'=>$search,'page'=>$listing['page']-1]))?>">Anterior</a><?php endif;?>
<span>Página <?=e((string)$listing['page'])?> de <?=e((string)$listing['pages'])?></span>
<?php if($listing['page']<$listing['pages']):?><a class="btn btn-light" href="?<?=e(http_build_query(['church'=>$cid,'q'=>$search,'page'=>$listing['page']+1]))?>">Próxima</a><?php endif;?>
</nav>
<?php endif;?>
</section>
<dialog class="mm-modal" id="mm-dialog" aria-labelledby="mm-title">
<h2 id="mm-title">Gerenciar cadastro</h2><p id="mm-description"></p>
<form method="post" class="mm-modal-form">
<?=csrf_field()?><input type="hidden" name="church_id" value="<?=e($cid)?>">
<input type="hidden" name="member_id" id="mm-id">
<label>Ação<select class="select" name="action" id="mm-action">
<option value="archive">Arquivar (recomendado)</option>
<option value="restore">Restaurar</option>
<option value="purge">Excluir definitivamente (somente sem vínculos)</option>
</select></label>
<p class="mm-info" id="mm-warning">Os lançamentos e históricos não serão excluídos pelo arquivamento.</p>
<label>Digite o nome completo do cadastro<input class="input" name="confirm_name" required autocomplete="off" placeholder="Confirme o nome exatamente"></label>
<label>Motivo da ação<textarea class="input" name="reason" rows="3" minlength="8" maxlength="500" required placeholder="Ex.: duplicidade durante a migração"></textarea></label>
<label id="mm-confirm-wrap" hidden>Digite EXCLUIR para confirmar a exclusão definitiva
<input class="input" name="purge_confirmation" id="mm-purge" autocomplete="off"></label>
<div class="actions" style="margin-top:16px">
<button type="button" class="btn btn-light" id="mm-close">Cancelar</button>
<button class="btn btn-primary" id="mm-submit" type="submit">Confirmar ação</button>
</div>
</form></dialog>
<script>
(()=>{
const dialog=document.getElementById('mm-dialog');
const action=document.getElementById('mm-action');
const confirmation=document.getElementById('mm-confirm-wrap');
const purge=document.getElementById('mm-purge');
const warning=document.getElementById('mm-warning');
const button=document.getElementById('mm-submit');
function render(){
 const permanent=action.value==='purge';
 confirmation.hidden=!permanent;
 purge.required=permanent;
 warning.textContent=permanent?'Somente cadastros arquivados, sem vínculo financeiro, pastoral, presença ou usuário podem ser apagados. Esta operação não pode ser desfeita.':
 'Arquivar ou restaurar não apaga histórico, vínculos nem comprovantes.';
 button.textContent=permanent?'Solicitar exclusão definitiva':'Confirmar ação';
 button.className=permanent?'mm-danger':'btn btn-primary';
}
document.querySelectorAll('.mm-open').forEach(b=>b.addEventListener('click',()=>{
 document.getElementById('mm-id').value=b.dataset.memberId;
 document.getElementById('mm-description').textContent=b.dataset.memberName+' — '+<?=json_encode($churchName,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
 action.value=b.dataset.memberActive==='1'?'archive':'restore';
 render();dialog.showModal();
}));
action.addEventListener('change',render);
document.getElementById('mm-close').addEventListener('click',()=>dialog.close());
})();
</script>
<?php View::footer();?>
