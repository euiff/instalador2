<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GroupedFinanceService.php';
require_once __DIR__.'/lib/UploadService.php';

$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');
$cid=(string)$church['id'];
function grouped_reply(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    if(empty($_POST)&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)
        grouped_reply(['ok'=>false,'message'=>'Os documentos ultrapassaram o limite de upload da hospedagem. Envie arquivos menores.'],413);
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='stage_item_file'){
            $stager=new GroupedFinanceStage($pdo,__DIR__);
            // Periodic cleanup of abandoned files without touching archived receipts.
            if(random_int(1,20)===1)$stager->prune($cid);
            $result=$stager->stage($cid,(string)$auth->requireLogin()['id'],$_FILES['document_file']??[]);
            grouped_reply(['ok'=>true]+$result);
        }
        if($action!=='create_group')
            throw new InvalidArgumentException('Ação inválida.');
        $result=(new GroupedFinanceService($pdo,$auth,$cid))->create($_POST,$_FILES);
        grouped_reply(['ok'=>true]+$result);
    }catch(Throwable $e){
        error_log('Igreja Master grouped finance failed: '.get_class($e).' '.$e->getMessage());
        grouped_reply(['ok'=>false,'message'=>$e instanceof PDOException
            ?'Falha ao gravar o lançamento agrupado no banco; confira o log PHP da hospedagem. Nenhuma movimentação foi confirmada.'
            :$e->getMessage()],422);
    }
}

function group_json(array $data,int $status=200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    if(empty($_POST)&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)
        group_json(['ok'=>false,'message'=>'Os arquivos excederam o limite post_max_size da hospedagem. Envie imagens menores ou menos arquivos.'],413);
    try{
        verify_csrf();
        if(($_POST['action']??'')!=='create_group')
            throw new InvalidArgumentException('Ação inválida.');
        $result=(new GroupedFinanceService($pdo,$auth,$cid))->create($_POST,$_FILES);
        group_json(['ok'=>true]+$result+['view_url'=>'/finance-entry-edit.php?id='.rawurlencode($result['entry_id'])]);
    }catch(Throwable $e){
        error_log('Igreja Master finance group failed: '.get_class($e).' '.$e->getMessage());
        $message=$e instanceof PDOException?'Não foi possível concluir o lançamento agrupado no banco. Nenhum valor foi contabilizado. Confira os logs do servidor.':$e->getMessage();
        group_json(['ok'=>false,'message'=>$message],422);
    }
}
$limits=UploadService::financeLimits();
$maxUpload=min(12*1024*1024,($limits['php']??null)!==null?max(1,(int)$limits['php']-131072):12*1024*1024);
$registry=new RegistryService($pdo,$auth,$cid);
$categories=$registry->all('finance_category');
$q=$pdo->prepare('SELECT id,name,department_id,account_type FROM finance_accounts WHERE church_id=? AND active=1 ORDER BY name');
$q->execute([$cid]);$accountRows=$q->fetchAll(PDO::FETCH_ASSOC);
$allowDepartments=false;
if($auth->can('department_treasury')){
    $dept=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$auth->requireLogin()['id']);
    $allowDepartments=$dept->mayPost();
}
$accounts=array_values(array_filter($accountRows,static fn(array $a)=>empty($a['department_id'])||$allowDepartments));
$q=$pdo->prepare('SELECT id,name FROM members WHERE church_id=? ORDER BY name LIMIT 1000');
$q->execute([$cid]);$members=$q->fetchAll(PDO::FETCH_ASSOC);
View::header('Lançamento agrupado',$auth,'finance.php');
?>
<style>
.gform{max-width:1000px;margin:0 auto 80px}
.gintro{border:1px solid #c0d7ea;border-radius:16px;padding:16px;background:#eff7ff;margin:12px 0}
.gintro h2{margin:0;color:#17466f;font-size:20px}
.gcontrols{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.gitem{border:1px solid #d2dce6;border-radius:14px;padding:15px;margin:12px 0;background:var(--card-bg,#fff)}
.ggrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.gfield{display:flex;flex-direction:column;gap:5px}
.gfield label{font-weight:600;font-size:13px}
.gfield input,.gfield textarea,.gfield select,.gcontrols input,.gcontrols select{width:100%;min-height:41px;padding:9px;border:1px solid #bdcbd8;border-radius:9px;background:var(--input-bg,#fff);color:inherit}
.gfield textarea{min-height:58px}
.grow{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.gresult{border:1px solid #aad0ac;background:#effbef;color:#14673a;padding:12px;border-radius:10px}
.gerror{border:1px solid #e6a59b;background:#fff0ee;color:#962c24;padding:12px;border-radius:10px}
.gsummary{position:sticky;bottom:9px;z-index:3;background:var(--card-bg,#fff);border:1px solid #cdd8e2;border-radius:12px;box-shadow:0 5px 18px #0002;padding:12px;margin:14px 0}
.gbuttons{display:flex;flex-wrap:wrap;gap:8px}
.gbuttons button{border:1px solid #abc7df;background:#f0f6fc;border-radius:9px;padding:10px;cursor:pointer}
.gbuttons button[type=submit]{background:#0b5aa0;color:#fff;border:0;font-weight:700}
.gitem-note{font-size:12px;color:#60748b}
@media(max-width:580px){.ggrid{grid-template-columns:1fr}.gitem{padding:12px}}
</style>
<div class="gform">
 <div class="page-head"><div><h1>Um lançamento com várias ofertas ou cupons</h1>
 <p>Uma única movimentação financeira com vários itens discriminados, cada um com valor e comprovante próprio quando houver.</p></div>
 <a class="btn btn-light" href="/finance.php">Voltar ao Financeiro</a></div>
 <details class="gintro">
  <summary style="cursor:pointer;font-weight:700">ⓘ Como funciona o lançamento agrupado</summary>
  <p>Exemplo: três ofertas de R$ 20,00, R$ 30,00 e R$ 50,00 formam <strong>um lançamento de R$ 100,00</strong>. Os três itens ficam separados nos detalhes, sem contar os valores duas vezes.</p>
  <p>Adicione de 2 a 12 itens. Cada item pode ter sua própria foto, cupom fiscal ou recibo. As fotos são enviadas uma por vez, antes de salvar, para respeitar os limites da hospedagem. A IA tenta preencher seus dados quando houver documento, mas você pode lançar ofertas sem anexo.</p>
 </details>
 <form data-grouped-finance data-max-upload="<?=e((string)$maxUpload)?>" method="post" action="/finance-group.php" enctype="multipart/form-data">
   <?=csrf_field()?>
   <input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>">
   <div class="card card-pad" style="margin:14px 0">
     <h2 style="font-size:17px">1. Dados do lançamento principal</h2>
     <div class="gcontrols">
       <div class="gfield"><label>Tipo</label><select data-group-direction><option value="income">Entrada / ofertas</option><option value="expense">Saída / despesas</option></select></div>
       <div class="gfield"><label>Data do lançamento</label><input data-group-date type="date" required value="<?=e(date('Y-m-d'))?>"></div>
       <div class="gfield"><label>Centro de custo (conta/caixa destino)</label><select data-group-account>
         <option value="">Tesouraria Geral — sem conta específica</option>
         <?php foreach($accounts as $a):?>
           <option value="<?=e($a['id'])?>"><?=e($a['name'])?><?=!empty($a['department_id'])?' · Departamento':''?></option>
         <?php endforeach?>
       </select></div>
       <div class="gfield"><label>Descrição principal</label><input data-group-description maxlength="255" value="Ofertas do dia" required></div>
     </div>
   </div>
   <section class="card card-pad">
     <div class="grow"><h2 style="font-size:17px">2. Itens e comprovantes</h2>
      <div class="gbuttons"><button type="button" data-add-item>+ Adicionar oferta ou cupom</button></div></div>
     <div data-group-items></div>
     <div class="gbuttons"><button type="button" data-add-item>+ Adicionar outro item</button></div>
   </section>
   <template data-group-template>
     <article class="gitem">
       <div class="grow"><strong data-number></strong><button type="button" class="btn btn-light" data-remove>Remover item</button></div>
       <div class="ggrid">
         <div class="gfield"><label>Lançamento contábil *</label><select data-field="category">
           <option value="">Selecione um cadastro da igreja</option>
           <?php foreach($categories as $c):?>
             <option value="<?=e($c['name'])?>" data-direction="<?=e($c['data']['direction']??'both')?>"><?=e($c['name'])?></option>
           <?php endforeach?>
         </select></div>
         <div class="gfield"><label>Valor deste item *</label><input data-field="amount" inputmode="decimal" placeholder="0,00"></div>
         <div class="gfield"><label>Contribuinte / fornecedor</label><input data-field="contributor" maxlength="190"></div>
         <div class="gfield"><label>Membro contribuinte (entrada)</label>
           <select data-field="member_id"><option value="">Sem vínculo de membro</option>
           <?php foreach($members as $m):?><option value="<?=e($m['id'])?>"><?=e($m['name'])?></option><?php endforeach?>
           </select></div>
         <div class="gfield"><label>Nº cupom / documento / Pix</label><input data-field="document_number" maxlength="190"></div>
         <div class="gfield"><label>Forma de pagamento</label><input data-field="payment_method" maxlength="60" value="PIX manual"></div>
         <div class="gfield"><label>Tipo do comprovante</label><select data-field="document_type">
          <option value="fiscal_coupon">Cupom fiscal</option><option value="invoice">Nota fiscal</option>
          <option value="receipt">Recibo</option><option value="proof">Comprovante</option><option value="other">Outro</option>
         </select></div>
         <div class="gfield"><label>Foto ou PDF (opcional)</label><input data-field="file" type="file" accept="image/*,.pdf,application/pdf">
         <small class="gitem-note">Ao selecionar, a IA poderá sugerir os dados do item.</small></div>
         <div class="gfield" style="grid-column:1/-1"><label>Descrição do item *</label><textarea data-field="description" maxlength="255"></textarea></div>
       </div>
       <p data-ai-message class="gitem-note" aria-live="polite"></p>
     </article>
   </template>
   <div class="gsummary">
     <div class="grow"><strong data-group-total>Total do lançamento: R$ 0,00</strong><span data-group-count>2 itens</span></div>
     <p class="gitem-note">Esse total é registrado UMA única vez no caixa. Os itens discriminados e anexos ficam vinculados ao lançamento principal.</p>
     <div data-group-status role="status" aria-live="polite"></div>
     <div class="gbuttons"><button type="submit" data-group-submit>Conferir e registrar lançamento agrupado</button></div>
   </div>
 </form>
</div>
<script defer src="/assets/finance-group-3757.js?v=3.7.57"></script>
<?php View::footer(); ?>
