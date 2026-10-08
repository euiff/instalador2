<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MemberDocumentService.php';

$auth->requireAbility('secretary');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
$service=new MemberDocumentService($pdo,__DIR__);

$qtext=trim((string)($_GET['q']??''));
$params=[$cid];
$sql='SELECT id,name,phone,cpf,is_baptized,role,department FROM members WHERE church_id=? AND active=1';
if($qtext!==''){
    $sql.=' AND (name LIKE ? OR phone LIKE ? OR cpf LIKE ?)';
    $like='%'.$qtext.'%';$params[]=$like;$params[]=$like;$params[]=$like;
}
$sql.=' ORDER BY name LIMIT 150';
$q=$pdo->prepare($sql);$q->execute($params);$members=$q->fetchAll();

$selected=null;
if(!empty($_GET['member_id']))$selected=$service->member($cid,(string)$_GET['member_id']);

$q=$pdo->prepare('SELECT d.*,m.name member_name FROM issued_documents d INNER JOIN members m ON m.id=d.member_id WHERE d.church_id=? ORDER BY d.issued_at DESC LIMIT 100');
$q->execute([$cid]);$issued=$q->fetchAll();

View::header('Documentos','documents',$auth,$church);
?>
<div class="module-hero">
  <div>
    <span class="eyebrow">Secretaria</span>
    <h1>Documentos Eclesiásticos</h1>
    <p>Gere certificados, carteirinhas, cartas e declarações oficiais com código de verificação.</p>
  </div>
  <div class="hero-stat"><strong><?=count($issued)?></strong><span>emissões recentes</span></div>
</div>
<?php View::flash(); ?>

<div class="grid two-col modern-grid">
<section class="card form-card">
  <div class="section-title"><span>🔎</span><div><h2>Escolher membro</h2><p>Pesquise por nome, telefone ou CPF.</p></div></div>
  <form method="get" class="toolbar">
    <input class="input search" name="q" value="<?=e($qtext)?>" placeholder="Buscar membro">
    <button class="btn btn-primary">Buscar</button>
  </form>
  <div class="member-picker">
    <?php foreach($members as $m):?>
      <a class="member-pick <?=$selected&&$selected['id']===$m['id']?'active':''?>" href="/documents.php?member_id=<?=e($m['id'])?><?=($qtext!==''?'&q='.rawurlencode($qtext):'')?>">
        <span class="avatar"><?=e(mb_strtoupper(mb_substr($m['name'],0,1)))?></span>
        <span><strong><?=e($m['name'])?></strong><small><?=e($m['phone']?:'Sem telefone')?> · <?=e($m['role']?:'Membro')?></small></span>
      </a>
    <?php endforeach?>
    <?php if(!$members):?><div class="empty">Nenhum membro encontrado.</div><?php endif?>
  </div>
</section>

<section class="card form-card">
  <div class="section-title"><span>📄</span><div><h2>Gerar documento</h2><p><?=$selected?'Documento para '.e($selected['name']):'Selecione um membro ao lado.'?></p></div></div>
  <?php if($selected):?>
    <div class="document-grid">
      <?php foreach($service->types($selected) as $key=>[$prefix,$label]):?>
        <div class="document-card">
          <span class="doc-code"><?=e($prefix)?></span>
          <strong><?=e($label)?></strong>
          <p>PDF oficial com código de autenticidade.</p>
          <div class="doc-actions">
            <a class="btn btn-primary" href="/member-document-pdf.php?id=<?=e($selected['id'])?>&type=<?=e($key)?>">Baixar PDF</a>
            <a class="btn btn-light" target="_blank" href="/member-document.php?id=<?=e($selected['id'])?>&type=<?=e($key)?>">Visualizar / Imprimir</a>
          </div>
        </div>
      <?php endforeach?>
    </div>
  <?php else:?>
    <div class="empty">Escolha um membro para liberar os tipos de documento.</div>
  <?php endif?>
</section>
</div>

<section class="card card-pad" style="margin-top:18px">
  <div class="page-head"><div><h2 style="font-size:16px">Histórico de documentos emitidos</h2><p>Últimas emissões com código de verificação.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Membro</th><th>Tipo</th><th>Código</th><th>Status</th></tr></thead><tbody>
    <?php foreach($issued as $d):?>
      <tr><td><?=e(date('d/m/Y H:i',strtotime($d['issued_at'])))?></td><td><?=e($d['member_name'])?></td><td><?=e($d['document_type'])?></td><td><code><?=e($d['verification_code'])?></code></td><td><span class="badge <?=$d['revoked_at']?'off':'ok'?>"><?=$d['revoked_at']?'Revogado':'Válido'?></span></td></tr>
    <?php endforeach?>
    <?php if(!$issued):?><tr><td colspan="5"><div class="empty">Nenhum documento emitido.</div></td></tr><?php endif?>
  </tbody></table></div>
</section>
<?php View::footer(); ?>
