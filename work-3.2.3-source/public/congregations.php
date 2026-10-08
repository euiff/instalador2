<?php
require __DIR__.'/bootstrap.php';

$user=$auth->requireAbility('secretary');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $id=trim((string)($_POST['id']??''));
        $action=(string)($_POST['action']??'save');

        if($action==='toggle'){
            $q=$pdo->prepare('UPDATE congregations SET active=IF(active=1,0,1) WHERE id=? AND church_id=?');
            $q->execute([$id,$cid]);
            flash('success','Status da congregação atualizado.');
            redirect('/congregations.php');
        }

        $name=trim((string)($_POST['name']??''));
        if($name==='')throw new RuntimeException('Informe o nome da congregação.');

        $vals=[
            trim((string)($_POST['address']??''))?:null,
            trim((string)($_POST['phone']??''))?:null,
            trim((string)($_POST['pastor_name']??''))?:null,
            trim((string)($_POST['pix_key']??''))?:null,
            trim((string)($_POST['schedule_text']??''))?:null,
            trim((string)($_POST['daily_devotional_time']??''))?:null,
            (int)($_POST['sort_order']??0),
        ];

        if($id!==''){
            $q=$pdo->prepare('UPDATE congregations SET name=?,address=?,phone=?,pastor_name=?,pix_key=?,schedule_text=?,daily_devotional_time=?,sort_order=? WHERE id=? AND church_id=?');
            $q->execute([$name,...$vals,$id,$cid]);
            flash('success','Congregação atualizada com sucesso.');
        }else{
            $id=app_uuid();
            $q=$pdo->prepare('INSERT INTO congregations(id,church_id,name,address,phone,pastor_name,pix_key,schedule_text,daily_devotional_time,sort_order,active) VALUES(?,?,?,?,?,?,?,?,?,?,1)');
            $q->execute([$id,$cid,$name,...$vals]);
            flash('success','Congregação cadastrada com sucesso.');
        }
        redirect('/congregations.php?edit='.rawurlencode($id).'#editor');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect('/congregations.php'.(!empty($_POST['id'])?'?edit='.rawurlencode((string)$_POST['id']).'#editor':'?new=1#editor'));
    }
}

$edit=null;
if(!empty($_GET['edit'])){
    $q=$pdo->prepare('SELECT * FROM congregations WHERE id=? AND church_id=?');
    $q->execute([(string)$_GET['edit'],$cid]);
    $edit=$q->fetch()?:null;
}
$showEditor=$edit||isset($_GET['new']);

$q=$pdo->prepare('
    SELECT c.*,
      (SELECT COUNT(*) FROM members m WHERE m.congregation_id=c.id AND m.church_id=c.church_id AND m.active=1) members_count,
      (SELECT COUNT(*) FROM church_groups g WHERE g.congregation_id=c.id AND g.church_id=c.church_id) groups_count,
      (SELECT COUNT(*) FROM events e WHERE e.congregation_id=c.id AND e.church_id=c.church_id AND e.active=1 AND e.event_date>=NOW()) future_events
    FROM congregations c
    WHERE c.church_id=?
    ORDER BY c.sort_order,c.name
');
$q->execute([$cid]);
$rows=$q->fetchAll();

$activeCount=count(array_filter($rows,fn($r)=>(int)$r['active']===1));
$membersTotal=array_sum(array_map(fn($r)=>(int)$r['members_count'],$rows));
$groupsTotal=array_sum(array_map(fn($r)=>(int)$r['groups_count'],$rows));
$eventsTotal=array_sum(array_map(fn($r)=>(int)$r['future_events'],$rows));

View::header('Congregações','congregations',$auth,$church);
?>
<div class="page-head groups-page-head">
  <div>
    <h1>Congregações</h1>
    <p>Organize as unidades da igreja, responsáveis, horários, membros e grupos de comunicação.</p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="/groups.php">☏ Grupos da Igreja</a>
    <a class="btn btn-primary" href="/congregations.php?new=1#editor">+ Nova congregação</a>
  </div>
</div>
<?php View::flash(); ?>

<div class="congregation-summary">
  <div class="card stat"><div class="stat-label">Congregações</div><div class="stat-value"><?=count($rows)?></div><div class="stat-foot"><?=$activeCount?> ativa(s)</div></div>
  <div class="card stat"><div class="stat-label">Membros vinculados</div><div class="stat-value"><?=$membersTotal?></div><div class="stat-foot">Membros ativos nas unidades</div></div>
  <div class="card stat"><div class="stat-label">Grupos WhatsApp</div><div class="stat-value"><?=$groupsTotal?></div><div class="stat-foot">Destinos vinculados às unidades</div></div>
  <div class="card stat"><div class="stat-label">Próximos eventos</div><div class="stat-value"><?=$eventsTotal?></div><div class="stat-foot">Programações futuras</div></div>
</div>

<?php if(!$rows):?>
<section class="card empty-state">
  <div class="empty-icon">▣</div>
  <h2>Nenhuma congregação cadastrada</h2>
  <p>Cadastre a matriz, sede ou filiais. Depois você poderá vincular membros, eventos, grupos de WhatsApp e automações a cada unidade.</p>
  <div class="actions"><a class="btn btn-primary" href="/congregations.php?new=1#editor">Cadastrar primeira congregação</a></div>
</section>
<?php else:?>
<section class="congregation-board">
<?php foreach($rows as $row):?>
  <article class="card congregation-card <?=$row['active']?'':'is-disabled'?>">
    <div class="congregation-card-top">
      <div class="congregation-mark">▣</div>
      <div class="congregation-card-title">
        <div class="group-title-line">
          <h2><?=e($row['name'])?></h2>
          <span class="status-pill <?=$row['active']?'status-ok':'status-off'?>"><?=$row['active']?'Ativa':'Inativa'?></span>
        </div>
        <p><?=e($row['address']?:'Endereço ainda não informado')?></p>
      </div>
    </div>

    <div class="congregation-kpis">
      <div><span>Membros</span><strong><?=e((string)$row['members_count'])?></strong></div>
      <div><span>Grupos</span><strong><?=e((string)$row['groups_count'])?></strong></div>
      <div><span>Eventos</span><strong><?=e((string)$row['future_events'])?></strong></div>
    </div>

    <div class="congregation-meta">
      <div><strong>Pastor:</strong><span><?=e($row['pastor_name']?:'Não informado')?></span></div>
      <div><strong>Telefone:</strong><span><?=e($row['phone']?:'Não informado')?></span></div>
      <div><strong>Devocional:</strong><span><?=e(!empty($row['daily_devotional_time'])?substr((string)$row['daily_devotional_time'],0,5):'Horário padrão da igreja')?></span></div>
    </div>

    <?php if(!empty($row['schedule_text'])):?><div class="congregation-schedule"><?=nl2br(e($row['schedule_text']))?></div><?php endif?>

    <div class="congregation-actions">
      <a class="btn btn-light" href="/congregations.php?edit=<?=e($row['id'])?>#editor">Editar</a>
      <a class="btn btn-light" href="/members.php?congregation_id=<?=e($row['id'])?>">Ver membros</a>
      <a class="btn btn-light" href="/groups.php?congregation_id=<?=e($row['id'])?>">Ver grupos</a>
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($row['id'])?>"><button class="btn <?=$row['active']?'btn-danger':'btn-light'?>"><?=$row['active']?'Desativar':'Reativar'?></button></form>
    </div>
  </article>
<?php endforeach?>
</section>
<?php endif?>

<section class="card settings-section editor-shell <?=$showEditor?'':'is-hidden'?>" id="editor">
  <div class="settings-section-head">
    <div class="settings-icon">▣</div>
    <div class="grow"><h2><?=$edit?'Editar congregação':'Nova congregação'?></h2><p>Organize os dados da unidade. Grupos e automações ficam vinculados a ela sem misturar as congregações.</p></div>
    <?php if($showEditor):?><a class="btn btn-light" href="/congregations.php">Fechar</a><?php endif?>
  </div>

  <div class="editor-grid">
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
      <div class="form-grid">
        <div class="field full"><label>Nome da congregação *</label><input class="input" name="name" required value="<?=e($edit['name']??'')?>" placeholder="Ex.: ICB Catalão"></div>
        <div class="field full"><label>Endereço</label><input class="input" name="address" value="<?=e($edit['address']??'')?>" placeholder="Rua, número, bairro e cidade"></div>
        <div class="field"><label>Telefone</label><input class="input" name="phone" value="<?=e($edit['phone']??'')?>" placeholder="64 99999-9999"></div>
        <div class="field"><label>Pastor responsável</label><input class="input" name="pastor_name" value="<?=e($edit['pastor_name']??'')?>"></div>
        <div class="field"><label>Chave PIX</label><input class="input" name="pix_key" value="<?=e($edit['pix_key']??'')?>" placeholder="Opcional"></div>
        <div class="field"><label>Horário do devocional</label><input class="input" type="time" name="daily_devotional_time" value="<?=e(!empty($edit['daily_devotional_time'])?substr((string)$edit['daily_devotional_time'],0,5):'')?>"></div>
        <div class="field full"><label>Horários / cultos</label><textarea class="textarea" name="schedule_text" placeholder="Ex.: Domingo 19h&#10;Quarta 19h30&#10;Sexta 19h30"><?=e($edit['schedule_text']??'')?></textarea></div>
        <div class="field"><label>Ordem de exibição</label><input class="input" type="number" name="sort_order" value="<?=e((string)($edit['sort_order']??0))?>"></div>
      </div>
      <button class="btn btn-primary" style="margin-top:16px"><?=$edit?'Salvar alterações':'Cadastrar congregação'?></button>
    </form>

    <div class="editor-side-note">
      <h3>Grupos e automações</h3>
      <p>Depois de salvar a congregação, entre em <strong>Grupos da Igreja</strong> e vincule os grupos do WhatsApp. Você poderá marcar quais recebem eventos, rifas, relógio de oração, enquetes, devocionais e comunicados.</p>
      <a class="btn btn-light" style="margin-top:12px" href="/groups.php">Abrir Grupos da Igreja</a>
      <?php if($edit):?>
        <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
        <h3>Ações da unidade</h3>
        <div class="actions" style="margin-top:10px">
          <a class="btn btn-light" href="/members.php?congregation_id=<?=e($edit['id'])?>">Membros</a>
          <a class="btn btn-light" href="/events.php?congregation_id=<?=e($edit['id'])?>">Eventos</a>
          <a class="btn btn-light" href="/groups.php?congregation_id=<?=e($edit['id'])?>">Grupos</a>
        </div>
      <?php endif?>
    </div>
  </div>
</section>
<?php View::footer(); ?>
