<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupBroadcastService.php';

$user=$auth->requireAbility('communications');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];

$service=new GroupBroadcastService($pdo);
$filterCongregation=trim((string)($_GET['congregation_id']??''));
if($filterCongregation!==''){
    $q=$pdo->prepare('SELECT 1 FROM congregations WHERE id=? AND church_id=?');
    $q->execute([$filterCongregation,$cid]);
    if(!$q->fetchColumn())$filterCongregation='';
}
$purposes=[
    'announcements'=>'Comunicados',
    'events'=>'Eventos e convites',
    'raffles'=>'Rifas',
    'prayer_clock'=>'Relógio de oração',
    'polls'=>'Enquetes',
    'devotional'=>'Devocional diário',
];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');

        if($action==='sync'){
            $result=$service->syncEvolution($church);
            flash('success','Evolution sincronizada: '.$result['found'].' retorno(s), '.$result['created'].' novo(s), '.$result['updated'].' atualizado(s)'.(!empty($result['ignored'])?', '.$result['ignored'].' duplicado(s)/inválido(s) ignorado(s)':'').'.');
            redirect('/groups.php');
        }

        if($action==='save'){
            $id=trim((string)($_POST['id']??''));
            $name=trim((string)($_POST['name']??''));
            $jid=$service->normalizeGroupJid((string)($_POST['evolution_group_jid']??''));
            $congregationId=trim((string)($_POST['congregation_id']??''))?:null;
            $invite=trim((string)($_POST['invite_link']??''))?:null;
            $responsible=trim((string)($_POST['responsible_name']??''))?:null;
            $description=trim((string)($_POST['description']??''))?:null;
            $selectedPurposes=array_values(array_intersect(array_keys($purposes),array_map('strval',$_POST['purposes']??[])));

            if($name==='')throw new RuntimeException('Informe o nome do grupo.');

            if($jid!==''){
                $dupe=$pdo->prepare('SELECT id,name FROM church_groups WHERE church_id=? AND evolution_group_jid=? AND id<>? LIMIT 1');
                $dupe->execute([$cid,$jid,$id]);
                if($existing=$dupe->fetch()){
                    throw new RuntimeException('Este grupo já está cadastrado como “'.$existing['name'].'”. Edite o cadastro existente em vez de criar outro.');
                }
            }

            if($congregationId){
                $q=$pdo->prepare('SELECT 1 FROM congregations WHERE id=? AND church_id=?');
                $q->execute([$congregationId,$cid]);
                if(!$q->fetchColumn())throw new RuntimeException('Congregação inválida.');
            }

            if($id!==''){
                $q=$pdo->prepare('UPDATE church_groups SET congregation_id=?,name=?,group_type="whatsapp",evolution_group_jid=?,invite_link=?,responsible_name=?,description=?,purposes=?,active=? WHERE id=? AND church_id=?');
                $q->execute([$congregationId,$name,$jid?:null,$invite,$responsible,$description,json_encode($selectedPurposes,JSON_UNESCAPED_UNICODE),isset($_POST['active'])?1:0,$id,$cid]);
                flash('success','Grupo atualizado.');
            }else{
                $q=$pdo->prepare('INSERT INTO church_groups(id,church_id,congregation_id,name,group_type,evolution_group_jid,invite_link,responsible_name,description,purposes,active) VALUES(?,?,?,?,"whatsapp",?,?,?,?,?,1)');
                $q->execute([app_uuid(),$cid,$congregationId,$name,$jid?:null,$invite,$responsible,$description,json_encode($selectedPurposes,JSON_UNESCAPED_UNICODE)]);
                flash('success','Grupo cadastrado.');
            }
            redirect('/groups.php');
        }

        if($action==='toggle'){
            $id=(string)($_POST['id']??'');
            $q=$pdo->prepare('UPDATE church_groups SET active=IF(active=1,0,1) WHERE id=? AND church_id=?');
            $q->execute([$id,$cid]);
            flash('success','Status do grupo atualizado.');
            redirect('/groups.php');
        }

        if($action==='test'){
            $id=(string)($_POST['id']??'');
            $result=$service->sendIds($church,[$id],'✅ Teste do Igreja Master: este grupo está conectado à Evolution API.');
            if((int)$result['sent']<1){
                $error=$result['errors'][0]['error']??'Não foi possível enviar.';
                throw new RuntimeException($error);
            }
            flash('success','Mensagem de teste enviada para o grupo.');
            redirect('/groups.php');
        }
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect('/groups.php'.(!empty($_POST['id'])?'?edit='.rawurlencode((string)$_POST['id']):''));
    }
}

$edit=null;
if(!empty($_GET['edit'])){
    $q=$pdo->prepare('SELECT * FROM church_groups WHERE id=? AND church_id=?');
    $q->execute([(string)$_GET['edit'],$cid]);
    $edit=$q->fetch()?:null;
}
$editPurposes=$edit?json_decode((string)($edit['purposes']??'[]'),true):[];
if(!is_array($editPurposes))$editPurposes=[];

$congs=$pdo->prepare('SELECT id,name FROM congregations WHERE church_id=? AND active=1 ORDER BY sort_order,name');
$congs->execute([$cid]);$congs=$congs->fetchAll();

$rows=$service->list($cid,null,null,false);
if($filterCongregation!==''){
    $rows=array_values(array_filter($rows,fn($r)=>(string)($r['congregation_id']??'')===$filterCongregation));
}
$activeCount=count(array_filter($rows,fn($r)=>(int)$r['active']===1));
$connectedCount=count(array_filter($rows,fn($r)=>trim((string)($r['evolution_group_jid']??''))!==''));
$purposeCount=[];
foreach(array_keys($purposes) as $p){
    $purposeCount[$p]=count(array_filter($rows,function($r)use($p){
        $list=json_decode((string)($r['purposes']??'[]'),true);
        return is_array($list)&&in_array($p,$list,true);
    }));
}

View::header('Grupos da Igreja','groups',$auth,$church);
?>
<div class="page-head groups-page-head">
  <div>
    <h1>Grupos da Igreja</h1>
    <p>Cadastre os grupos do WhatsApp e escolha quais recebem convites, rifas, oração, enquetes e comunicados.</p>
  </div>
  <div class="actions">
    <?php if($filterCongregation!==''):?><a class="btn btn-light" href="/groups.php">Ver todos</a><?php endif?>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sync"><button class="btn btn-light">↻ Buscar grupos da Evolution</button></form>
    <a class="btn btn-primary" href="/groups.php?new=1<?=$filterCongregation!==''?'&congregation_id='.rawurlencode($filterCongregation):''?>#group-form">+ Novo grupo</a>
  </div>
</div>
<?php View::flash(); ?>

<div class="grid stats group-stats">
  <div class="card stat"><div class="stat-label">Grupos cadastrados</div><div class="stat-value"><?=count($rows)?></div><div class="stat-foot">Todos os destinos da igreja</div></div>
  <div class="card stat"><div class="stat-label">Ativos</div><div class="stat-value"><?=$activeCount?></div><div class="stat-foot">Disponíveis para automações</div></div>
  <div class="card stat"><div class="stat-label">Com JID Evolution</div><div class="stat-value"><?=$connectedCount?></div><div class="stat-foot">Prontos para envio</div></div>
  <div class="card stat"><div class="stat-label">Eventos</div><div class="stat-value"><?=$purposeCount['events']?></div><div class="stat-foot">Recebem convites e lembretes</div></div>
</div>

<div class="groups-layout">
  <section class="groups-list">
    <?php if(!$rows):?>
      <div class="card empty-state">
        <div class="empty-icon">☏</div>
        <h2>Nenhum grupo cadastrado</h2>
        <p>Se a Evolution já está conectada, use <strong>Buscar grupos da Evolution</strong>. O sistema importa os grupos sem você precisar descobrir o JID manualmente.</p>
        <div class="actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sync"><button class="btn btn-primary">Buscar da Evolution</button></form></div>
      </div>
    <?php endif?>

    <?php foreach($rows as $row):
      $rowPurposes=json_decode((string)($row['purposes']??'[]'),true);
      if(!is_array($rowPurposes))$rowPurposes=[];
    ?>
      <article class="card group-card <?=$row['active']?'':'is-disabled'?>">
        <div class="group-card-main">
          <div class="group-avatar">☏</div>
          <div class="group-info">
            <div class="group-title-line">
              <h2><?=e($row['name'])?></h2>
              <span class="status-pill <?=$row['active']?'status-ok':'status-off'?>"><?=$row['active']?'Ativo':'Inativo'?></span>
            </div>
            <p><?=e($row['congregation_name']?:'Igreja geral / Matriz')?><?=!empty($row['responsible_name'])?' · Responsável: '.e($row['responsible_name']):''?></p>
            <div class="group-jid"><?=e($row['evolution_group_jid']?:'JID ainda não informado')?></div>
          </div>
        </div>
        <div class="purpose-chips">
          <?php foreach($rowPurposes as $purpose): if(isset($purposes[$purpose])):?>
            <span><?=e($purposes[$purpose])?></span>
          <?php endif; endforeach?>
          <?php if(!$rowPurposes):?><span class="chip-muted">Sem automações selecionadas</span><?php endif?>
        </div>
        <div class="group-card-actions">
          <a class="btn btn-light" href="/groups.php?edit=<?=e($row['id'])?>#group-form">Editar</a>
          <?php if(!empty($row['evolution_group_jid'])):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn btn-light">Enviar teste</button></form><?php endif?>
          <?php if(!empty($row['invite_link'])):?><a class="btn btn-light" target="_blank" rel="noopener" href="<?=e($row['invite_link'])?>">Abrir convite ↗</a><?php endif?>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn <?=$row['active']?'btn-danger':'btn-light'?>"><?=$row['active']?'Desativar':'Reativar'?></button></form>
        </div>
      </article>
    <?php endforeach?>
  </section>

  <aside class="card settings-section group-editor" id="group-form">
    <div class="settings-section-head">
      <div class="settings-icon">☏</div>
      <div><h2><?=$edit?'Editar grupo':'Novo grupo'?></h2><p>Vincule o grupo à congregação e escolha o que ele deve receber.</p></div>
    </div>
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
      <div class="form-grid">
        <div class="field full"><label>Nome do grupo *</label><input class="input" name="name" required value="<?=e($edit['name']??'')?>" placeholder="Ex.: Grupo Geral ICB Catalão"></div>
        <div class="field full"><label>Congregação</label><select class="select" name="congregation_id"><option value="">Igreja geral / Matriz</option><?php foreach($congs as $cg): $selectedCong=(string)($edit['congregation_id']??$filterCongregation);?><option value="<?=e($cg['id'])?>" <?=$selectedCong===$cg['id']?'selected':''?>><?=e($cg['name'])?></option><?php endforeach?></select></div>
        <div class="field full"><label>JID do grupo na Evolution</label><input class="input code-input" name="evolution_group_jid" value="<?=e($edit['evolution_group_jid']??'')?>" placeholder="120363xxxxxxxx@g.us"><small class="help-text">Use “Buscar grupos da Evolution” para preencher automaticamente.</small></div>
        <div class="field full"><label>Link de convite</label><input class="input" type="url" name="invite_link" value="<?=e($edit['invite_link']??'')?>" placeholder="https://chat.whatsapp.com/..."></div>
        <div class="field full"><label>Responsável</label><input class="input" name="responsible_name" value="<?=e($edit['responsible_name']??'')?>" placeholder="Nome do líder ou administrador do grupo"></div>
        <div class="field full"><label>Descrição</label><textarea class="textarea" name="description" placeholder="Finalidade do grupo e observações"><?=e($edit['description']??'')?></textarea></div>
      </div>

      <div class="purpose-selector">
        <h3>O que este grupo deve receber?</h3>
        <p>Você pode marcar várias opções.</p>
        <?php foreach($purposes as $key=>$label):?>
          <label class="purpose-option"><input type="checkbox" name="purposes[]" value="<?=e($key)?>" <?=in_array($key,$editPurposes,true)?'checked':''?>><span><strong><?=e($label)?></strong><small><?=match($key){'events'=>'Convites e lembretes de eventos','raffles'=>'Divulgação e lembretes de rifas','prayer_clock'=>'Convites e avisos do relógio de oração','polls'=>'Enquetes enviadas para o grupo','devotional'=>'Palavra/devocional automático','announcements'=>'Comunicados gerais da igreja',default=>''}?></small></span></label>
        <?php endforeach?>
      </div>

      <?php if($edit):?><label class="toggle-row" style="margin-top:14px"><input type="checkbox" name="active" <?=$edit['active']?'checked':''?>><span><strong>Grupo ativo</strong><small>Somente grupos ativos participam de automações.</small></span></label><?php endif?>

      <button class="btn btn-primary" style="width:100%;margin-top:16px"><?=$edit?'Salvar alterações':'Cadastrar grupo'?></button>
      <?php if($edit):?><a class="btn btn-light" style="width:100%;margin-top:8px" href="/groups.php">Cancelar edição</a><?php endif?>
    </form>
  </aside>
</div>
<?php View::footer(); ?>
