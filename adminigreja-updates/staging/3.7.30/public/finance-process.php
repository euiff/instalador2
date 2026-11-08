<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/FinanceGovernanceService.php';

$user=$auth->requireLogin();
$church=$auth->currentChurch();
if (!$church||(!$auth->can('finance')&&!$auth->can('accounting_portal'))) {
    http_response_code(403);exit('Acesso restrito à Tesouraria e à Contabilidade.');
}
$cid=(string)$church['id'];
$svc=new FinanceGovernanceService($pdo,$auth,$cid);
$canEdit=$auth->can('finance');$canAdmin=$auth->can('settings');
// Popula opções padrão apenas na primeira abertura pela administração.
// A rotina usa INSERT IGNORE: não altera cadastros já personalizados.
if($canAdmin && count($svc->options('finance_governance_documents_catalog'))===0)$svc->seed();
$error=null;
$entryId=trim((string)($_GET['entry']??$_POST['entry_id']??''));
$competence=trim((string)($_GET['competence']??date('Y-m')));

if (($_GET['export']??'')==='csv') {
    $id=trim((string)($_GET['batch']??''));
    [$batch,$rows]=$svc->exportBatch($id);
    if ($batch['status']==='draft' && !$canEdit) {
        http_response_code(403);exit('Lote ainda não enviado ao contador.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="igreja-master-'.preg_replace('/[^a-zA-Z0-9_-]/','-',(string)$batch['code']).'.csv"');
    header('Cache-Control: no-store, private');echo "\xEF\xBB\xBF";
    $stream=fopen('php://output','wb');
    fputcsv($stream,['Número','Competência','Data','Tipo','Categoria','Histórico','Valor bruto','Taxas','Valor líquido','Conta / Caixa','Forma de pagamento','Documento','Chave fiscal','Referência extrato','Data conciliação'],';');
    foreach($rows as $r)fputcsv($stream,[$r['sequence_label'],$r['competence'],$r['transaction_date'],
        $r['direction'],$r['category'],$r['financial_history'],$r['gross_amount'],$r['fees_amount'],
        $r['net_amount'],$r['account_name'],$r['payment_method'],$r['document_number'],
        $r['access_key'],$r['bank_reference'],$r['bank_date']],';');
    fclose($stream);exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if (!$canEdit && $action!=='')throw new RuntimeException('Conta de consulta não pode alterar lançamentos.');
        if ($action==='save') {
            $svc->save($entryId,$_POST);flash('success','Classificação, taxas, documentos e histórico salvos. Valor líquido calculado automaticamente.');
        }elseif($action==='reconcile'){
            $svc->reconcile($entryId,$_POST);flash('success','Conciliação concluída após conferir valor líquido, conta, data e evidência.');
        }elseif($action==='approve'){
            $svc->approve($entryId,(string)($_POST['approval_notes']??''));flash('success','Aprovação registrada com usuário e data automática.');
        }elseif($action==='finalize'){
            $svc->finalize($entryId);flash('success','Contribuição contabilizada uma única vez no caixa selecionado.');
        }elseif($action==='pending'){
            $svc->pending($entryId,(string)($_POST['pending_reason_id']??''),(string)($_POST['pending_notes']??''));flash('success','Pendência registrada com justificativa.');
        }elseif($action==='create_batch'){
            $batch=$svc->createBatch((string)($_POST['competence']??''),(string)($_POST['notes']??''));
            flash('success','Lote contábil criado. Confira antes de enviar ao contador.');
        }elseif($action==='send_batch'){
            $svc->sendBatch((string)($_POST['batch_id']??''));flash('success','Lote enviado à contabilidade e lançamentos arquivados com histórico.');
        }elseif($action==='add_rule'){
            $svc->addRule($_POST);flash('success','Regra de aprovação cadastrada.');
        }elseif($action==='seed'){
            $svc->seed();flash('success','Tipos de documento e motivos padrão cadastrados para esta igreja.');
        }elseif($action==='authorize'){
            if (!$canAdmin)throw new RuntimeException('Somente administração pode autorizar aprovadores.');
            $uid=(string)($_POST['user_id']??'');
            $q=$pdo->prepare('SELECT user_id FROM church_members WHERE church_id=? AND user_id=?');
            $q->execute([$cid,$uid]);
            if (!$q->fetchColumn())throw new InvalidArgumentException('Usuário não está cadastrado nesta igreja.');
            $start=trim((string)($_POST['starts_on']??''))?:null;
            $end=trim((string)($_POST['ends_on']??''))?:null;
            if($start!==null && !DateTimeImmutable::createFromFormat('!Y-m-d',$start))
                throw new InvalidArgumentException('Data inicial inválida.');
            if($end!==null && !DateTimeImmutable::createFromFormat('!Y-m-d',$end))
                throw new InvalidArgumentException('Data final inválida.');
            if($start!==null&&$end!==null&&$end<$start)
                throw new InvalidArgumentException('Vigência final antes da inicial.');
            $pdo->prepare('INSERT INTO finance_governance_authorized(church_id,user_id,starts_on,ends_on,active,can_approve)
              VALUES(?,?,?,?,1,1) ON DUPLICATE KEY UPDATE starts_on=VALUES(starts_on),ends_on=VALUES(ends_on),active=1,can_approve=1')
              ->execute([$cid,$uid,$start,$end]);
            flash('success','Aprovador habilitado para esta igreja.');
        }elseif($action==='catalog'){
            if (!$canAdmin)throw new RuntimeException('Somente administração pode alterar os cadastros.');
            $kind=(string)($_POST['kind']??'');
            $name=trim((string)($_POST['name']??''));
            $code=preg_replace('/[^a-z0-9_-]/','-',strtolower(trim((string)($_POST['code']??''))))??'';
            if($name===''||mb_strlen($name)>180||$code===''||strlen($code)>40)
                throw new InvalidArgumentException('Informe código e nome válidos para a lista.');
            if($kind==='document'){
                $pdo->prepare('INSERT INTO finance_governance_documents_catalog(id,church_id,code,name,requires_number,requires_date,requires_file,requires_access_key)
                  VALUES(?,?,?,?,?,?,?,?)')->execute([app_uuid(),$cid,$code,$name,!empty($_POST['requires_number'])?1:0,
                  !empty($_POST['requires_date'])?1:0,!empty($_POST['requires_file'])?1:0,!empty($_POST['requires_access_key'])?1:0]);
            }elseif($kind==='reason'){
                $pdo->prepare('INSERT INTO finance_governance_reasons(id,church_id,code,name) VALUES(?,?,?,?)')
                  ->execute([app_uuid(),$cid,$code,$name]);
            }elseif($kind==='gl'){
                $nature=(string)($_POST['nature']??'income');
                if(!in_array($nature,['income','expense','asset','liability'],true))throw new InvalidArgumentException('Natureza inválida.');
                $pdo->prepare('INSERT INTO finance_governance_gl_accounts(id,church_id,code,name,nature)
                  VALUES(?,?,?,?,?)')->execute([app_uuid(),$cid,$code,$name,$nature]);
            }else throw new InvalidArgumentException('Lista inválida.');
            flash('success','Novo cadastro de apoio salvo na igreja.');
        }else throw new InvalidArgumentException('Ação não reconhecida.');
        redirect('/finance-process.php'.($entryId!==''?'?entry='.rawurlencode($entryId):''));
    }catch(Throwable $e){$error=$e->getMessage();}
}
$pending=$pdo->prepare("SELECT e.id,e.description,e.amount,e.transaction_date,
  COALESCE(g.stage,'received') stage FROM finance_entries e
  LEFT JOIN finance_governance_entries g ON g.entry_id=e.id AND g.church_id=e.church_id
  WHERE e.church_id=? AND e.source='member_qr' AND e.status='received'
  ORDER BY e.created_at DESC LIMIT 100");
$pending->execute([$cid]);$queue=$pending->fetchAll(PDO::FETCH_ASSOC);
$recent=$svc->recent(70);
if ($entryId===''&&$queue)$entryId=(string)$queue[0]['id'];
$current=null;
if($entryId!==''){
 try{$current=$svc->full($entryId);}catch(Throwable $e){$error=$e->getMessage();}
}
$accounts=$svc->options('finance_accounts');
$docTypes=$svc->options('finance_governance_documents_catalog');
$reasons=$svc->options('finance_governance_reasons');
$glAccounts=$svc->options('finance_governance_gl_accounts');
$q=$pdo->prepare("SELECT name FROM church_registers WHERE church_id=? AND kind='finance_category' AND active=1 ORDER BY name");
$q->execute([$cid]);$categories=$q->fetchAll(PDO::FETCH_COLUMN);
$q=$pdo->prepare("SELECT id,name FROM church_registers WHERE church_id=? AND kind='cost_center' AND active=1 ORDER BY name");
$q->execute([$cid]);$costCenters=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$departments=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT id,name FROM contribution_campaigns WHERE church_id=? AND active=1 AND start_date<=CURDATE() AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY name');
$q->execute([$cid]);$campaigns=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT u.id,u.full_name FROM church_members cm JOIN users u ON u.id=cm.user_id WHERE cm.church_id=? ORDER BY u.full_name');
$q->execute([$cid]);$approvers=$q->fetchAll(PDO::FETCH_ASSOC);
$rules=$svc->rules();$batches=$svc->batches();
$q=$pdo->prepare('SELECT code,name FROM finance_governance_status_catalog WHERE church_id=? AND active=1');
$q->execute([$cid]);$statuses=$q->fetchAll(PDO::FETCH_KEY_PAIR);

View::header('Conferência financeira completa',$auth,'finance-process.php');
?>
<nav class="cb-finance-tabs" aria-label="Áreas da Tesouraria">
 <a href="/finance-process.php" aria-current="page"><span aria-hidden="true">▣</span> Lançamento</a>
 <a href="/contribution-review.php"><span aria-hidden="true">⌕</span> Consulta</a>
 <a href="/finance-control.php"><span aria-hidden="true">▥</span> Relatórios</a>
 <a href="/finance-catalogs.php"><span aria-hidden="true">⚙</span> Configurações</a>
</nav>
<div class="module-toolbar">
 <div class="module-title"><h1>Lançamento Financeiro · Tesouraria</h1>
 <p>Classificação, comprovantes, valores líquidos, conciliação, aprovação e prestação de contas em um único lançamento.</p></div>
 <div class="actions"><a class="btn btn-light" href="/contribution-review.php">Lançamentos rápidos</a>
 <a class="btn btn-light" href="/finance.php">Tesouraria geral</a></div>
</div>
<?php View::flash();?>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif?>
<div class="finance-gov-layout">
 <section class="card card-pad">
  <h2>Contribuições para conferir <span class="badge"><?=count($queue)?></span></h2>
  <?php if(!$queue):?><p class="muted">Nenhuma contribuição nova pendente.</p><?php endif?>
  <div class="finance-gov-queue">
  <?php foreach($queue as $item):?>
  <a class="finance-gov-link <?=$entryId===$item['id']?'selected':''?>" href="/finance-process.php?entry=<?=e(rawurlencode($item['id']))?>">
   <span><strong><?=e($item['description'])?></strong><small><?=e(date('d/m/Y',strtotime($item['transaction_date'])))?> · <?=e($statuses[$item['stage']]??$item['stage'])?></small></span>
   <strong>R$ <?=number_format((float)$item['amount'],2,',','.')?></strong>
  </a><?php endforeach?>
  </div>
  <h3>Registros acompanhados</h3><div class="finance-gov-queue">
   <?php foreach($recent as $item):?>
   <a class="finance-gov-link <?=$entryId===$item['entry_id']?'selected':''?>" href="?entry=<?=e(rawurlencode($item['entry_id']))?>">
    <span><strong><?=e($item['sequence_label']?:'Sem numeração')?> · <?=e($item['category'])?></strong><small><?=e($statuses[$item['stage']]??$item['stage'])?> · <?=e($item['competence'])?></small></span>
    <strong>R$ <?=number_format((float)$item['net_amount'],2,',','.')?></strong>
   </a><?php endforeach?>
  </div>
 </section>
 <section class="card card-pad">
 <?php if($current):?>
  <div class="finance-gov-heading">
    <div><span class="eyebrow">Nº <?=e($current['sequence_label']?:'Aguardando conferência')?></span>
    <h2><?=e($current['financial_history'])?></h2>
    <p class="muted">ID único: <?=e($current['entry_id'])?></p></div>
    <span class="finance-gov-status"><?=e($statuses[$current['stage']]??$current['stage'])?></span>
  </div>
  <div class="finance-gov-money">
    <div><small>Bruto</small><strong>R$ <?=number_format((float)$current['gross_amount'],2,',','.')?></strong></div>
    <div><small>Taxas / descontos</small><strong>R$ <?=number_format((float)$current['fees_amount'],2,',','.')?></strong></div>
    <div><small>Líquido</small><strong>R$ <?=number_format((float)$current['net_amount'],2,',','.')?></strong></div>
  </div>
  <?php if($canEdit && in_array($current['stage'],['received','reviewing','pending'],true) && $current['ledger_status']==='received'):?>
  <form method="post" class="finance-gov-form" id="finance-gov-classification">
    <?=csrf_field()?><input type="hidden" name="entry_id" value="<?=e($current['entry_id'])?>"><input type="hidden" name="action" value="save">
    <h3>1. Identificação e classificação</h3>
    <div class="form-grid">
      <div class="field"><label>Competência *</label><input class="input" type="month" name="competence" value="<?=e($current['competence'])?>" required></div>
      <div class="field"><label>Categoria financeira *</label><input class="input" name="category" value="<?=e($current['category'])?>" list="finance-gov-categories" maxlength="160" required>
      <datalist id="finance-gov-categories"><?php foreach($categories as $c):?><option value="<?=e($c)?>"><?php endforeach?></datalist>
      <small class="muted">Selecione uma categoria ativa; o valor atual é preservado para lançamentos antigos.</small></div>
      <div class="field"><label>Conta bancária / caixa *</label><select class="select" name="account_id" required>
        <option value="">Selecionar conta cadastrada</option>
        <?php foreach($accounts as $a):?><option value="<?=e($a['id'])?>" <?=$current['account_id']===$a['id']?'selected':''?>><?=e($a['name'])?> (<?=e($a['account_type'])?>)</option><?php endforeach?>
      </select></div>
      <div class="field"><label>Plano de contas do contador</label><select class="select" name="gl_account_id"><option value="">Não classificar</option>
        <?php foreach($glAccounts as $g):?><option value="<?=e($g['id'])?>" <?=$current['gl_account_id']===$g['id']?'selected':''?>><?=e($g['code'].' · '.$g['name'])?></option><?php endforeach?></select></div>
      <div class="field"><label>Centro de custo</label><select class="select" name="cost_center_id"><option value="">Não informar</option>
        <?php foreach($costCenters as $c):?><option value="<?=e($c['id'])?>" <?=$current['cost_center_id']===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></div>
      <div class="field"><label>Departamento / Ministério</label><select class="select" name="department_id"><option value="">Tesouraria geral</option>
        <?php foreach($departments as $d):?><option value="<?=e($d['id'])?>" <?=$current['department_id']===$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach?></select></div>
      <div class="field"><label>Projeto / Campanha vigente</label><select class="select" name="campaign_id"><option value="">Sem campanha</option>
        <?php foreach($campaigns as $c):?><option value="<?=e($c['id'])?>" <?=$current['campaign_id']===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></div>
      <div class="field full"><label>Histórico contábil *</label><input class="input" name="financial_history" value="<?=e($current['financial_history'])?>" maxlength="255" required></div>
    </div>
    <h3>2. Valores — líquido calculado automaticamente</h3>
    <div class="form-grid">
      <div class="field"><label>Valor bruto (R$) *</label><input class="input" name="gross_amount" inputmode="decimal" value="<?=e(number_format((float)$current['gross_amount'],2,',','.'))?>" required></div>
      <div class="field"><label>Taxas / descontos (R$)</label><input class="input" name="fees_amount" inputmode="decimal" value="<?=e(number_format((float)$current['fees_amount'],2,',','.'))?>" required></div>
      <div class="field"><label>Valor líquido (R$)</label><output id="finance-gov-net">R$ <?=number_format((float)$current['net_amount'],2,',','.')?></output><small class="muted">Bruto menos taxas, calculado no servidor.</small></div>
      <div class="field"><label>ID Pix / autenticação</label><input class="input" name="payment_reference" maxlength="190" value="<?=e($current['payment_reference']??'')?>"></div>
    </div>
    <h3>3. Documento fiscal / comprovante</h3>
    <div class="form-grid">
     <div class="field"><label>Tipo de documento</label><select class="select" name="document_type_id" id="finance-gov-document">
      <option value="">Selecione um tipo ativo</option>
      <?php foreach($docTypes as $doc):?><option value="<?=e($doc['id'])?>" <?=$current['document_type_id']===$doc['id']?'selected':''?>
       data-number="<?=(int)$doc['requires_number']?>" data-date="<?=(int)$doc['requires_date']?>" data-key="<?=(int)$doc['requires_access_key']?>"><?=e($doc['name'])?></option><?php endforeach?></select></div>
     <div class="field"><label>Nº documento</label><input class="input" name="document_number" maxlength="100" value="<?=e($current['document_number']??'')?>"></div>
     <div class="field"><label>Emissão</label><input type="date" class="input" name="document_date" value="<?=e($current['document_date']??'')?>"></div>
     <div class="field"><label>Chave fiscal NF-e (44 dígitos)</label><input class="input" name="access_key" inputmode="numeric" maxlength="44" value="<?=e($current['access_key']??'')?>"></div>
     <div class="field full"><label>Observação do documento</label><input class="input" name="document_note" maxlength="500" value="<?=e($current['document_note']??'')?>"></div>
     <div class="field full"><label>Justificativa de ajuste do bruto / competência</label><textarea class="textarea" name="change_reason" maxlength="1000" rows="2" placeholder="Obrigatória quando valor bruto ou competência forem alterados"></textarea></div>
    </div>
    <p class="muted">Comprovantes anexados: <?=intval($current['proof_count'])?>. Para enviar outro arquivo, use o módulo Tesouraria / Comprovantes.</p>
    <button class="btn btn-primary" type="submit">Salvar e iniciar conferência</button>
  </form>
  <?php endif?>
  <?php if($canEdit && $current['stage']==='reviewing'):?>
  <form method="post" class="finance-gov-form">
    <?=csrf_field()?><input type="hidden" name="entry_id" value="<?=e($current['entry_id'])?>"><input type="hidden" name="action" value="reconcile">
    <h3>4. Conciliação com extrato / caixa</h3>
    <p class="muted">Verifique a conta, a data, o valor líquido calculado e a evidência antes de marcar como conciliado.</p>
    <div class="form-grid">
      <div class="field"><label>Referência do extrato *</label><input class="input" name="bank_reference" maxlength="190" required value="<?=e($current['payment_reference']??'')?>"></div>
      <div class="field"><label>Data localizada *</label><input type="date" class="input" name="bank_date" value="<?=e($current['transaction_date'])?>" required></div>
      <div class="field"><label>Valor localizado no extrato (R$) *</label><input class="input" name="bank_amount" inputmode="decimal" required value="<?=e(number_format((float)$current['net_amount'],2,',','.'))?>"></div>
      <div class="field full"><label>Justificativa / evidência</label><textarea class="textarea" name="evidence_note" maxlength="500" rows="2" placeholder="Obrigatório caso não haja comprovante digital ou existam datas divergentes"></textarea></div>
    </div>
    <div class="finance-gov-checks">
      <label><input type="checkbox" name="is_located" value="1" required> Localizado no extrato ou caixa</label>
      <label><input type="checkbox" name="is_proven" value="1" required> Documentação / evidência conferida</label>
    </div>
    <button class="btn btn-primary" type="submit">Conciliar lançamento</button>
  </form>
  <?php endif?>
  <?php if($canEdit && in_array($current['stage'],['reconciled','approval_pending'],true)):?>
  <form method="post" class="finance-gov-form">
   <?=csrf_field()?><input type="hidden" name="entry_id" value="<?=e($current['entry_id'])?>"><input type="hidden" name="action" value="approve">
   <h3>5. Aprovação — usuário autenticado</h3><p>O aprovador é registrado pelo login; uma segunda aprovação, quando exigida, deve ser feita por outra pessoa.</p>
   <div class="field"><label>Observação da aprovação</label><textarea class="textarea" name="approval_notes" rows="2" maxlength="1000"></textarea></div>
   <button class="btn btn-primary" type="submit">Registrar minha aprovação</button>
  </form>
  <?php endif?>
  <?php if($canEdit && $current['stage']==='approved'):?>
   <form method="post" class="finance-gov-form" onsubmit="return confirm('Confirmar a contabilização definitiva no caixa selecionado?');">
     <?=csrf_field()?><input type="hidden" name="entry_id" value="<?=e($current['entry_id'])?>"><input type="hidden" name="action" value="finalize">
     <h3>6. Contabilização</h3><p>Os valores bruto e taxas continuarão no histórico; somente o valor líquido será registrado no saldo do caixa.</p>
     <button class="btn btn-primary" type="submit">Finalizar conferência e contabilizar</button>
   </form>
  <?php endif?>
  <?php if($canEdit && in_array($current['stage'],['received','reviewing','pending'],true)):?>
    <form method="post" class="finance-gov-form">
     <?=csrf_field()?><input type="hidden" name="entry_id" value="<?=e($current['entry_id'])?>"><input type="hidden" name="action" value="pending">
     <h3>Devolver / registrar pendência</h3>
     <div class="field"><label>Motivo padrão *</label><select class="select" name="pending_reason_id" required><option value="">Escolha o motivo</option>
       <?php foreach($reasons as $r):?><option value="<?=e($r['id'])?>"><?=e($r['name'])?></option><?php endforeach?></select></div>
     <div class="field"><label>Justificativa *</label><textarea class="textarea" name="pending_notes" maxlength="1000" required rows="2"></textarea></div>
     <button class="btn btn-light" type="submit">Registrar pendência</button>
    </form>
  <?php endif?>
  <h3>Histórico e aprovações</h3>
  <div class="finance-gov-history">
   <?php foreach($current['approvals'] as $a):?><p><strong>Etapa <?=(int)$a['approval_level']?>:</strong> <?=e($a['approver']?:'Usuário')?> · <?=e($a['created_at'])?></p><?php endforeach?>
   <?php foreach($current['history'] as $h):?><p><?=e($h['created_at'])?> · <strong><?=e($h['action'])?></strong> — <?=e($h['actor']?:'Sistema')?><?=!empty($h['reason'])?' · '.e($h['reason']):''?></p><?php endforeach?>
   <?php if(!$current['history']):?><p class="muted">Ainda não existem alterações registradas.</p><?php endif?>
  </div>
 <?php else:?>
 <h2>Selecione um lançamento</h2><p class="muted">A Tesouraria utiliza o mesmo número de lançamento criado pelo membro, sem duplicar o registro.</p>
 <?php endif?>
 </section>
</div>

<section class="card card-pad finance-gov-section">
  <div class="page-head"><div><h2>7. Lotes e envio à Contabilidade</h2><p>Os lotes reúnem somente lançamentos contabilizados; após o envio, os registros ficam arquivados para auditoria.</p></div></div>
  <?php if($canEdit):?>
  <form method="post" class="finance-gov-inline">
    <?=csrf_field()?><input type="hidden" name="action" value="create_batch">
    <div class="field"><label>Competência</label><input class="input" name="competence" type="month" value="<?=e($competence)?>" required></div>
    <div class="field"><label>Observação</label><input class="input" name="notes" maxlength="1000" placeholder="Documentos incluídos"></div>
    <button class="btn btn-primary" type="submit">Criar lote</button>
  </form>
  <?php endif?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Protocolo</th><th>Competência</th><th>Itens</th><th>Status</th><th>Conferência</th></tr></thead><tbody>
    <?php foreach($batches as $batch):?><tr>
       <td><?=e($batch['code'])?></td><td><?=e($batch['competence'])?></td>
       <td><?=(int)$batch['entry_count']?></td><td><?=e($batch['status'])?></td>
       <td><a class="btn btn-light" href="/finance-process.php?export=csv&amp;batch=<?=e(rawurlencode($batch['id']))?>">Exportar CSV</a>
       <?php if($canEdit && $batch['status']==='draft'):?>
       <form method="post" style="display:inline" onsubmit="return confirm('Enviar este lote ao contador e arquivar os lançamentos?');">
         <?=csrf_field()?><input type="hidden" name="action" value="send_batch"><input type="hidden" name="batch_id" value="<?=e($batch['id'])?>">
         <button class="btn btn-primary" type="submit">Enviar ao contador</button>
       </form><?php endif?></td>
    </tr><?php endforeach?>
    <?php if(!$batches):?><tr><td colspan="5">Nenhum lote criado até o momento.</td></tr><?php endif?>
  </tbody></table></div>
</section>
<?php if($canAdmin):?>
<section class="card card-pad finance-gov-section" id="governance-catalogs">
 <h2>Configurações contábeis desta igreja</h2>
 <p class="muted">As regras e listas são particulares à igreja e não afetam outros clientes. Somente itens ativos são selecionáveis.</p>
 <form method="post" class="finance-gov-inline">
 <?=csrf_field()?><input type="hidden" name="action" value="seed">
 <button class="btn btn-light" type="submit">Preparar tipos de documento e motivos padrão</button></form>
 <h3>Alçadas e dupla aprovação</h3>
 <form method="post" class="finance-gov-inline">
  <?=csrf_field()?><input type="hidden" name="action" value="add_rule">
  <div class="field"><label>Nome da regra</label><input class="input" name="name" required maxlength="120" placeholder="Ex.: acima de R$ 1.000"></div>
  <div class="field"><label>Valor mínimo R$</label><input class="input" name="from_amount" inputmode="decimal" value="0,00" required></div>
  <div class="field"><label>Valor máximo (opcional)</label><input class="input" name="to_amount" inputmode="decimal"></div>
  <div class="field"><label>Aprovações</label><select class="select" name="required_approvals"><option value="1">Uma aprovação</option><option value="2">Duas pessoas diferentes</option></select></div>
  <label><input type="checkbox" name="separate_reviewer" value="1"> Aprovador diferente do conferente</label>
  <button class="btn btn-primary" type="submit">Criar regra de alçada</button>
 </form>
 <div class="table-wrap"><table class="table"><thead><tr><th>Regra</th><th>Faixa</th><th>Aprovações</th><th>Segregação</th></tr></thead><tbody>
  <?php foreach($rules as $rule):?><tr><td><?=e($rule['name'])?></td><td>R$ <?=number_format((float)$rule['from_amount'],2,',','.')?> — <?=e($rule['to_amount']??'Sem máximo')?></td><td><?=(int)$rule['required_approvals']?></td><td><?=$rule['separate_reviewer']?'Obrigatória':'Opcional'?></td></tr><?php endforeach?>
 </tbody></table></div>
 <h3>Pessoas autorizadas para aprovação</h3>
 <form method="post" class="finance-gov-inline">
  <?=csrf_field()?><input type="hidden" name="action" value="authorize">
  <div class="field"><label>Usuário cadastrado</label><select class="select" name="user_id" required><option value="">Selecione um usuário</option>
    <?php foreach($approvers as $a):?><option value="<?=e($a['id'])?>"><?=e($a['full_name'])?></option><?php endforeach?></select></div>
  <div class="field"><label>Início (opcional)</label><input type="date" class="input" name="starts_on"></div>
  <div class="field"><label>Fim (opcional)</label><input type="date" class="input" name="ends_on"></div>
  <button class="btn btn-primary" type="submit">Autorizar usuário</button>
 </form>
 <h3>Tipos de documento, motivos e plano de contas</h3>
 <form method="post" class="finance-gov-inline">
   <?=csrf_field()?><input type="hidden" name="action" value="catalog">
   <div class="field"><label>Cadastro</label><select class="select" name="kind"><option value="document">Tipo de documento</option><option value="reason">Motivo de pendência</option><option value="gl">Conta contábil</option></select></div>
   <div class="field"><label>Código *</label><input class="input" name="code" maxlength="40" required placeholder="Ex.: tarifa_bancaria"></div>
   <div class="field"><label>Descrição *</label><input class="input" name="name" maxlength="180" required></div>
   <div class="field"><label>Natureza (plano de contas)</label><select class="select" name="nature"><option value="income">Receita</option><option value="expense">Despesa</option><option value="asset">Ativo</option><option value="liability">Passivo</option></select></div>
   <label><input type="checkbox" name="requires_number"> Exige número</label>
   <label><input type="checkbox" name="requires_date"> Exige data</label>
   <label><input type="checkbox" name="requires_file"> Exige arquivo</label>
   <label><input type="checkbox" name="requires_access_key"> Exige chave NF-e</label>
   <button class="btn btn-primary" type="submit">Adicionar cadastro</button>
 </form>
</section>
<?php endif?>
<script src="/assets/finance-governance-3727.js?v=1" defer></script>
<?php View::footer();?>
