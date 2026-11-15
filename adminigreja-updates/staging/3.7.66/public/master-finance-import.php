<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MasterFinanceImport.php';

$user=$auth->requireRole('super_admin');
$masterId=(string)$user['id'];
$cid=trim((string)($_POST['church_id']??$_GET['church']??''));
if(!preg_match('/^[0-9a-f-]{36}$/iD',$cid))redirect('/master.php');
$churchQ=$pdo->prepare('SELECT id,name FROM churches WHERE id=? LIMIT 1');
$churchQ->execute([$cid]);
$church=$churchQ->fetch(PDO::FETCH_ASSOC);
if(!$church)redirect('/master.php');
$importer=new MasterFinanceImport($pdo,dirname(__DIR__));
$error=null;
$success=flash('success');
$_SESSION['_csrf']??=bin2hex(random_bytes(32));

function master_import_money(int $cents): string {
    return 'R$ '.number_format($cents/100,2,',','.');
}
function master_import_preview_session(string $churchId,string $masterId): ?array {
    $staged=$_SESSION['master_fin_import']??null;
    if(!is_array($staged)
        || (string)($staged['church_id']??'')!==$churchId
        || (string)($staged['master_id']??'')!==$masterId
        || time()-(int)($staged['created']??0)>3600)
        return null;
    return $staged;
}
function master_import_suggest(array $source,array $accounts): string {
    // Default to faithful creation of the original accounting structure.
    // Exact matching and ministry classification happen inside the transaction.
    return 'auto';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='preview'){
            $previous=$_SESSION['master_fin_import']??null;
            if(is_array($previous) && !empty($previous['path'])
                && is_file((string)$previous['path'])
                && str_starts_with(basename((string)$previous['path']),'finance-')) {
                @unlink((string)$previous['path']);
            }
            unset($_SESSION['master_fin_import']);
            $upload=$importer->stageUpload($_FILES['finance_backup']??[]);
            $_SESSION['master_fin_import']=[
                'church_id'=>$cid, 'master_id'=>$masterId,
                'created'=>time(), 'path'=>$upload['path'], 'hash'=>$upload['hash'],
                'filename'=>$upload['name'],
            ];
            $success='Arquivo recebido para conferência. Nenhum lançamento foi importado nem apagado.';
        } elseif($action==='confirm'){
            $stage=master_import_preview_session($cid,$masterId);
            if(!$stage)throw new RuntimeException('O prazo da conferência expirou. Envie novamente a planilha.');
            $map=$_POST['mapping']??null;
            if(!is_array($map))throw new InvalidArgumentException('Indique os caixas de destino para a importação.');
            if((string)($_POST['ack_import']??'')!=='1')
                throw new RuntimeException('Confirme que verificou a importação antes de gravar.');
            $data=$importer->parse((string)$stage['path'],(string)$stage['filename']);
            if($data['zero_lines'] && (string)($_POST['ack_zero']??'')!=='1')
                throw new RuntimeException('Confirme o tratamento dos registros com valor zero.');
            if($data['rounded_value_lines'] && (string)($_POST['ack_rounded']??'')!=='1')
                throw new RuntimeException('Confirme a normalização dos valores do Excel para centavos.');
            if($data['missing_description_lines'] && (string)($_POST['ack_missing_description']??'')!=='1')
                throw new RuntimeException('Confirme os registros sem descrição no backup de origem.');
            if($data['unassigned_lines'] && (string)($_POST['ack_unknown']??'')!=='1')
                throw new RuntimeException('Confirme o destino dos registros sem caixa identificado (#N/A).');
            $result=$importer->import(
                $cid,$masterId,(string)$stage['path'],(string)$stage['filename'],
                (string)$stage['hash'],$map,(string)($_POST['confirm_church']??'')
            );
            unset($_SESSION['master_fin_import']);
            flash('success','Importação concluída: '.$result['entries'].' lançamentos; '.$result['accounts_created'].' caixas criados; '.$result['departments_created'].' departamentos criados; '.$result['categories_created'].' categorias contábeis criadas e '.$result['categories_updated'].' atualizadas; '.$result['members_linked'].' contribuições vinculadas a membros cadastrados. Total de entradas: '.master_import_money($result['income_cents']).'; saídas: '.master_import_money($result['expense_cents']).'. Ação registrada na auditoria Master.');
            redirect('/master-finance-import.php?church='.rawurlencode($cid).'&imported=1');
        } elseif($action==='cancel'){
            $stage=master_import_preview_session($cid,$masterId);
            if($stage && !empty($stage['path']) && is_file((string)$stage['path']))@unlink((string)$stage['path']);
            unset($_SESSION['master_fin_import']);
            $success='Conferência cancelada. Nada foi gravado na Tesouraria.';
        } else {
            throw new InvalidArgumentException('Ação desconhecida.');
        }
    }catch(Throwable $e){
        // Do not display internal SQLSTATE details, tenant UUIDs or index keys
        // to church users. Full exception remains in private PHP server logs.
        if($e instanceof PDOException){
            $error='O banco encontrou uma restrição de cadastro financeiro. Nenhum lançamento novo foi gravado. Confira se a versão do sistema foi atualizada e tente novamente; se persistir, encaminhe este aviso ao suporte.';
        }else{
            $error=$e->getMessage();
        }
        error_log('MasterFinanceImport: '.$e->getMessage());
    }
}
$accountQ=$pdo->prepare('SELECT a.id,a.name,a.department_id,COALESCE(d.name,"Tesouraria Geral") department_name
    FROM finance_accounts a
    LEFT JOIN ministry_departments d ON d.id=a.department_id AND d.church_id=a.church_id
    WHERE a.church_id=? AND a.active=1 ORDER BY a.department_id IS NOT NULL,a.name');
$accountQ->execute([$cid]);
$accounts=$accountQ->fetchAll(PDO::FETCH_ASSOC);
$departmentQ=$pdo->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1 ORDER BY name');
$departmentQ->execute([$cid]);
$departments=$departmentQ->fetchAll(PDO::FETCH_ASSOC);
$countQ=$pdo->prepare('SELECT COUNT(*) FROM finance_entries WHERE church_id=?');
$countQ->execute([$cid]);
$existing=(int)$countQ->fetchColumn();
$stage=master_import_preview_session($cid,$masterId);
$preview=null;
if($stage){
    try{
        $path=(string)$stage['path'];
        if(!is_file($path)||!hash_equals((string)$stage['hash'],hash_file('sha256',$path)))
            throw new RuntimeException('O arquivo da prévia não está mais disponível. Reenvie.');
        $preview=$importer->parse($path,(string)$stage['filename']);
    }catch(Throwable $e){$error=$e->getMessage();$preview=null;unset($_SESSION['master_fin_import']);}
}
View::header('Master · Importar lançamentos',$auth,'master.php');
?>
<style>
.im-migrate-hero{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:18px}
.im-migrate-hero h1{font-size:23px;margin:0 0 6px}
.im-migrate-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0 20px}
.im-migrate-summary>div{border:1px solid var(--line,#dce3ec);background:var(--card-bg,#fff);padding:14px;border-radius:12px}
.im-migrate-summary small{display:block;color:var(--muted,#64748b);font-weight:700;margin-bottom:6px}
.im-migrate-summary strong{font-size:20px}
.im-migrate-upload{border:2px dashed #84b5d7;border-radius:14px;padding:20px;background:var(--card-bg,#fff)}
.im-migrate-upload input[type=file]{display:block;width:100%;max-width:100%;margin-top:10px;padding:14px;border:1px solid #8aafc5;border-radius:10px;background:var(--card-bg,#fff);color:var(--text,#223047)}
.im-migrate-controls{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:16px}
.im-migrate-warning{padding:14px;border:1px solid #e5bd75;background:#fff8e5;color:#583a12;border-radius:12px;margin:13px 0}
.im-migrate-warning strong{display:block;margin-bottom:5px}
.im-migrate-account-table{width:100%;border-collapse:collapse}
.im-migrate-account-table th,.im-migrate-account-table td{padding:10px;border-bottom:1px solid var(--line,#dce3ec);vertical-align:middle;text-align:left}
.im-migrate-account-table select{min-width:240px;max-width:450px;width:100%}
.im-migrate-ack{padding:14px;background:var(--card-bg,#fff);border:1px solid var(--line,#dce3ec);border-radius:12px;margin:14px 0}
.im-migrate-ack label{display:flex;gap:9px;align-items:flex-start;margin:8px 0}
.im-migrate-ack input[type=checkbox]{margin-top:3px}
.im-migrate-sample{overflow:auto;max-width:100%}
.im-migrate-sample table{width:100%;min-width:680px;border-collapse:collapse}
.im-migrate-sample td,.im-migrate-sample th{padding:8px 10px;border-bottom:1px solid var(--line,#dce3ec);text-align:left}
@media(max-width:800px){.im-migrate-summary{grid-template-columns:1fr 1fr}.im-migrate-hero h1{font-size:20px}.im-migrate-upload{padding:13px}.im-migrate-account-table select{min-width:180px}}
</style>
<div class="im-migrate-hero">
 <div>
  <h1>Transferir lançamentos financeiros</h1>
  <p>Ferramenta exclusiva do Master: importar backup Excel ou CSV do sistema antigo para <strong><?=e($church['name'])?></strong>.</p>
 </div>
 <a class="btn btn-light" href="/master.php?church=<?=e($cid)?>#finance-import">Voltar ao Master</a>
</div>
<?php if($error):?><div class="alert alert-error" role="alert"><?=e($error)?></div><?php endif?>
<?php if($success):?><div class="alert alert-success" role="status"><?=e($success)?></div><?php endif?>
<section class="card card-pad">
 <h2 style="font-size:18px">1. Igreja e arquivo de origem</h2>
 <p><strong>Destino: <?=e($church['name'])?></strong> · <?=$existing?> lançamento(s) atualmente no sistema.</p>
 <p>Somente a igreja selecionada será afetada. Esta ferramenta <strong>não zera lançamentos</strong>; para evitar duplicações, exige que a igreja já esteja sem movimentos antes da confirmação. Membros e contas existentes são preservados. Na confirmação, o sistema cria automaticamente os caixas de origem, os departamentos identificados e as categorias contábeis do backup, sem precisar cadastrar cada item manualmente.</p>
 <?php if($existing>0):?>
 <div class="im-migrate-warning"><strong>Importação bloqueada enquanto houver lançamentos</strong>
  Faça backup e use a função <a href="/master.php?church=<?=e($cid)?>#financial-reset">Zerar somente lançamentos</a> no Master. Confira antes de apagar os dados de teste. A prévia do Excel ainda pode ser carregada sem alterar nada.</div>
 <?php endif?>
 <form method="post" enctype="multipart/form-data" class="im-migrate-upload">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="preview">
  <input type="hidden" name="church_id" value="<?=e($cid)?>">
  <label for="legacy-finance-backup"><strong>Escolher backup financeiro (.xlsx ou .csv)</strong></label>
  <input id="legacy-finance-backup" type="file" name="finance_backup" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
  <p class="muted">Máximo: 15 MB e 30 mil lançamentos. Colunas: Entrada/Saída, descrição, data, valor, conta/caixa, membro, documento/Pix, pagamento e lançamento contábil. O arquivo fica em armazenamento privado, fora da pasta pública do site.</p>
  <button class="btn btn-primary" type="submit">Ler planilha e conferir dados (sem gravar)</button>
 </form>
</section>

<?php if($preview):?>
<section class="card card-pad" style="margin-top:18px">
 <h2 style="font-size:18px">2. Conferência antes de importar</h2>
 <p>Arquivo: <strong><?=e($preview['filename'])?></strong> · Período de <?=e(date('d/m/Y',strtotime($preview['first_date'])))?> a <?=e(date('d/m/Y',strtotime($preview['last_date'])))?>.</p>
 <div class="im-migrate-summary">
  <div><small>Movimentações</small><strong><?=e((string)$preview['count'])?></strong></div>
  <div><small>Entradas (<?=e((string)$preview['count_income'])?>)</small><strong><?=e(master_import_money((int)$preview['income_cents']))?></strong></div>
  <div><small>Saídas (<?=e((string)$preview['count_expense'])?>)</small><strong><?=e(master_import_money((int)$preview['expense_cents']))?></strong></div>
  <div><small>Saldo líquido histórico</small><strong><?=e(master_import_money((int)($preview['income_cents']-$preview['expense_cents'])))?></strong></div>
 </div>
 <?php if($preview['errors']):?>
 <div class="im-migrate-warning"><strong>Há <?=count($preview['errors'])?> linha(s) inválida(s). A importação está bloqueada.</strong>
  <?php foreach(array_slice($preview['errors'],0,12) as $issue):?><p>Linha <?=e((string)$issue['line'])?>: <?=e($issue['reason'])?></p><?php endforeach?>
 </div>
 <?php endif?>
 <?php if($preview['rounded_value_lines']):?>
 <div class="im-migrate-warning"><strong><?=count($preview['rounded_value_lines'])?> registro(s) com precisão interna do Excel</strong>
  Esses valores serão normalizados para duas casas decimais (centavos), por exemplo 129.91999999999999 → R$ 129,92. Não são novos lançamentos; os valores contábeis serão preservados como moeda. Linhas: <?=e(implode(', ',array_slice($preview['rounded_value_lines'],0,20)))?><?=count($preview['rounded_value_lines'])>20?'...':''?>.</div>
 <?php endif?>
 <?php if($preview['missing_description_lines']):?>
 <div class="im-migrate-warning"><strong><?=count($preview['missing_description_lines'])?> lançamento(s) sem descrição no backup</strong>
  Serão mantidos com o identificador “SEM DESCRIÇÃO NO BACKUP (LINHA ...)” e uma anotação de que o campo original estava vazio, sem inventar uma finalidade para a movimentação. Linhas: <?=e(implode(', ',array_slice($preview['missing_description_lines'],0,20)))?><?=count($preview['missing_description_lines'])>20?'...':''?>.</div>
 <?php endif?>
 <?php if($preview['zero_lines']):?>
 <div class="im-migrate-warning"><strong><?=count($preview['zero_lines'])?> registro(s) de R$ 0,00</strong>
  Serão preservados como histórico, sem efeito no saldo. Linhas: <?=e(implode(', ',array_slice($preview['zero_lines'],0,20)))?><?=count($preview['zero_lines'])>20?'...':''?>.</div>
 <?php endif?>
 <?php if($preview['unassigned_lines']):?>
 <div class="im-migrate-warning"><strong><?=count($preview['unassigned_lines'])?> registro(s) com caixa #N/A</strong>
  Os registros serão direcionados automaticamente a um <strong>caixa provisório de conferência</strong>, separado dos demais, a menos que o Master escolha outro destino. A origem continuará identificada nas observações. Linhas: <?=e(implode(', ',array_slice($preview['unassigned_lines'],0,20)))?><?=count($preview['unassigned_lines'])>20?'...':''?>.</div>
 <?php endif?>
 <p>Contribuintes com nome informado: <strong><?=e((string)$preview['with_names'])?></strong> movimentações. O sistema vinculará somente nomes que correspondam a um único membro cadastrado nesta igreja; os demais nomes continuarão registrados no campo de contribuinte/fornecedor sem criar membros automaticamente.</p>
 <details><summary>Ver amostra dos primeiros 12 lançamentos</summary>
  <div class="im-migrate-sample"><table><thead><tr><th>Linha</th><th>Data</th><th>Tipo</th><th>Descrição</th><th>Valor</th><th>Caixa original</th></tr></thead><tbody>
  <?php foreach($preview['sample'] as $item):?><tr>
   <td><?=e((string)$item['line'])?></td>
   <td><?=e(date('d/m/Y',strtotime($item['date'])))?></td>
   <td><?=$item['direction']==='income'?'Entrada':'Saída'?></td>
   <td><?=e($item['description'])?></td>
   <td><?=e(master_import_money((int)$item['amount_cents']))?></td>
   <td><?=e($item['account'])?></td>
  </tr><?php endforeach?>
  </tbody></table></div>
 </details>
</section>
<section class="card card-pad" style="margin-top:18px">
 <h2 style="font-size:18px">3. Organização automática do backup</h2>
 <p>O importador criará ou reutilizará <strong><?=e((string)(count($preview['accounts'])-count(array_filter($preview['accounts'],fn($a)=>!empty($a['unidentified'])))))?> caixas identificados</strong>, preservando o nome e o código de cada um. Também recriará os departamentos/ministérios identificáveis pelos códigos <strong>1.x e 2.x</strong> e as categorias contábeis reais do Excel, sem misturá-los com outras igrejas.</p>
 <div class="im-migrate-summary">
  <div><small>Caixas de origem</small><strong><?=e((string)count($preview['accounts']))?></strong></div>
  <div><small>Categorias contábeis</small><strong><?=e((string)count($preview['categories']))?></strong></div>
  <div><small>Departamentos identificados</small><strong><?=e((string)count($preview['suggested_departments']))?></strong></div>
  <div><small>Origem #N/A</small><strong><?=e((string)count($preview['unassigned_lines']))?></strong></div>
 </div>
 <div class="im-migrate-ack">
  <strong>Categorias que serão criadas ou reutilizadas:</strong>
  <ul>
   <?php foreach($preview['categories'] as $cat):?>
    <li><?=e($cat['name'])?> — <?=e((string)$cat['count'])?> lançamento(s); <?=((int)$cat['income']>0 && (int)$cat['expense']>0)?'Entradas e saídas':((int)$cat['income']>0?'Entradas':'Saídas')?></li>
   <?php endforeach?>
  </ul>
  <strong>Departamentos sugeridos pela identificação do backup:</strong>
  <p><?=e(implode(' · ',$preview['suggested_departments'])?:'Nenhum código de departamento encontrado.')?></p>
  <p class="muted">Não serão criados líderes, senhas ou vínculos de membros a departamentos, porque a planilha financeira não informa essas funções.</p>
 </div>
 <p class="muted">A correspondência automática preserva os códigos e nomes do backup. A versão 3.7.66 também corrige a regra antiga que impedia mais de um caixa diferente no mesmo departamento, sem modificar contas e saldos já existentes. O Master pode alterar qualquer destino antes de confirmar.</p>
 <form method="post" onsubmit="return confirm('Confirma transferir os lancamentos somente para a igreja selecionada? A gravacao sera auditada.');">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="confirm">
  <input type="hidden" name="church_id" value="<?=e($cid)?>">
  <details class="im-migrate-advanced">
   <summary style="cursor:pointer;font-weight:750;padding:12px;border:1px solid #b9d3e9;border-radius:11px">Conferir ou alterar o destino de cada caixa (<?=count($preview['accounts'])?>)</summary>
  <div class="table-wrap"><table class="im-migrate-account-table">
  <thead><tr><th>Caixa no backup</th><th>Lançamentos</th><th>Valores</th><th>Destino nesta igreja</th></tr></thead>
  <tbody>
  <?php foreach($preview['accounts'] as $source):
   $suggested=master_import_suggest($source,$accounts);
  ?>
  <tr>
   <td><strong><?=e($source['name'])?></strong><?php if($source['unidentified']):?><p style="color:#b45309;margin:3px 0">Origem não identificada: escolha o destino!</p><?php endif?></td>
   <td><?=e((string)$source['count'])?></td>
   <td><small>Entradas: <?=e(master_import_money((int)$source['income_cents']))?></small><div><small>Saídas: <?=e(master_import_money((int)$source['expense_cents']))?></small></div></td>
   <td>
    <label class="muted" for="map-<?=e(substr($source['key'],0,12))?>">Caixa de destino</label>
    <select class="select" id="map-<?=e(substr($source['key'],0,12))?>" name="mapping[<?=e($source['key'])?>]" required>
     <option value="auto" <?=$suggested==='auto'?'selected':''?>><?=e($source['unidentified']?'Automático: criar caixa provisório para conferência':(!empty($source['suggested_department'])?'Automático: criar/reutilizar no departamento '.$source['suggested_department']:'Automático: criar/reutilizar na Tesouraria Geral'))?></option>
     <option value="">Selecione manualmente...</option>
     <optgroup label="Caixas existentes na igreja">
      <?php foreach($accounts as $account):$value='existing:'.$account['id'];?>
      <option value="<?=e($value)?>" <?=$suggested===$value?'selected':''?>><?=e($account['department_name'].' — '.$account['name'])?></option>
      <?php endforeach?>
     </optgroup>
     <optgroup label="Criar caixa com o nome do backup">
      <option value="new:general" <?=$suggested==='new:general'?'selected':''?>>Criar na Tesouraria Geral</option>
      <?php foreach($departments as $dept):$value='new:dep:'.$dept['id'];?>
      <option value="<?=e($value)?>"><?=e('Criar no departamento: '.$dept['name'])?></option>
      <?php endforeach?>
     </optgroup>
    </select>
   </td>
  </tr>
  <?php endforeach?>
  </tbody></table></div>
  </details>
  <div class="im-migrate-ack">
   <label><input type="checkbox" name="ack_import" value="1" required> Conferi a criação automática de caixas, categorias e departamentos, e autorizo importar exclusivamente para <strong><?=e($church['name'])?></strong>.</label>
   <?php if($preview['zero_lines']):?><label><input type="checkbox" name="ack_zero" value="1" required> Entendi que os <?=count($preview['zero_lines'])?> registros com valor zero serão preservados sem movimentar saldo.</label><?php endif?>
   <?php if($preview['rounded_value_lines']):?><label><input type="checkbox" name="ack_rounded" value="1" required> Conferi os <?=count($preview['rounded_value_lines'])?> valores do Excel que serão arredondados corretamente para centavos.</label><?php endif?>
   <?php if($preview['missing_description_lines']):?><label><input type="checkbox" name="ack_missing_description" value="1" required> Autorizo preservar <?=count($preview['missing_description_lines'])?> lançamentos sem descrição original, identificados claramente para conferência posterior.</label><?php endif?>
   <?php if($preview['unassigned_lines']):?><label><input type="checkbox" name="ack_unknown" value="1" required> Conferi a separação dos <?=count($preview['unassigned_lines'])?> lançamentos sem caixa identificado (#N/A), que ficarão em caixa provisório próprio se eu mantiver a opção automática.</label><?php endif?>
  </div>
  <div class="field">
   <label for="master-import-confirm-name"><strong>Para confirmar, digite exatamente o nome da igreja:</strong> <?=e($church['name'])?></label>
   <input class="input" id="master-import-confirm-name" name="confirm_church" autocomplete="off" required placeholder="Nome completo da igreja">
  </div>
  <p class="muted">O sistema executará uma transação única: ou todos os registros válidos serão gravados com a conferência de totais e auditoria, ou nenhum lançamento novo será salvo. O arquivo não contém PDFs/fotos dos comprovantes; referências textuais serão preservadas.</p>
  <div class="im-migrate-controls">
   <button class="btn btn-primary" type="submit" <?=($existing>0||$preview['errors'])?'disabled':''?>>Confirmar e importar <?=e((string)$preview['count'])?> lançamentos</button>
  </div>
 </form>
 <form method="post" style="margin-top:12px">
  <?=csrf_field()?>
  <input type="hidden" name="church_id" value="<?=e($cid)?>">
  <input type="hidden" name="action" value="cancel">
  <button type="submit" class="btn btn-light">Cancelar esta conferência</button>
 </form>
</section>
<?php endif?>
<?php View::footer(); ?>
