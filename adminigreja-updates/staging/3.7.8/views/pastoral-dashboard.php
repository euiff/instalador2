<?php
declare(strict_types=1);
/**
 * Staging view. Requires prevalidated variables:
 * $summary (scoped counts), $listing (scoped rows),
 * $csrfToken (server-generated), $canManage (bool), $baseUrl (trusted local path).
 * Caller MUST authenticate and authorize BEFORE including this view.
 */
$h=static fn(mixed $x):string=>htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$summary=$summary ?? ['total'=>0,'active'=>0,'overdue'=>0,'closed'=>0];
$listing=$listing ?? ['rows'=>[],'total'=>0,'page'=>1,'page_size'=>20];
$canManage=(bool)($canManage ?? false);
$baseUrl=(string)($baseUrl ?? 'pastoral.php');
$csrfToken=(string)($csrfToken ?? '');
?>
<section class="pastoral" aria-labelledby="pastoral-title">
<style>
.pastoral{font-family:system-ui,sans-serif;color:var(--text,#18243a);max-width:1250px;margin:0 auto;padding:20px}
.pastoral *{box-sizing:border-box}.pastoral h1{font-size:1.65rem;margin:0}
.pastoral .sub{color:var(--muted,#64748b);margin:6px 0 20px}
.pastoral .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0}
.pastoral .card{border:1px solid #dbe3ee;border-radius:14px;padding:18px;background:#fff}
.pastoral .card strong{display:block;font-size:1.75rem}.pastoral .card span{font-size:.9rem;color:#475569}
.pastoral .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:18px 0}
.pastoral label{display:grid;gap:5px;font-size:.86rem}.pastoral input,.pastoral select{border:1px solid #cbd5e1;padding:10px;border-radius:9px;min-width:120px}
.pastoral button,.pastoral .link{background:#234f9a;color:#fff;padding:10px 16px;border:0;border-radius:9px;cursor:pointer;text-decoration:none}
.pastoral button:focus-visible,.pastoral input:focus-visible,.pastoral select:focus-visible{outline:3px solid #f59e0b}
.pastoral .scroll{overflow-x:auto;border:1px solid #dbe3ee;border-radius:14px}
.pastoral table{width:100%;border-collapse:collapse;min-width:750px;background:#fff}
.pastoral th,.pastoral td{text-align:left;padding:13px 12px;border-bottom:1px solid #e2e8f0;font-size:.92rem}
.pastoral th{background:#f1f5f9}.pastoral .empty{padding:28px;text-align:center}
.pastoral .pager{display:flex;gap:12px;align-items:center;margin-top:16px}.pastoral .pager a{color:#234f9a}
.pastoral dialog{border:0;border-radius:18px;box-shadow:0 20px 70px #0004;max-width:520px;width:95%;padding:22px}
.pastoral dialog::backdrop{background:#10182890}.pastoral .actions{display:flex;gap:10px;justify-content:end;margin-top:15px}
@media(prefers-color-scheme:dark){.pastoral .card,.pastoral table{color:#18243a}}
</style>
<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
 <div><h1 id="pastoral-title">Acompanhamento pastoral</h1><p class="sub">Visitas, acolhimentos e retornos da sua igreja.</p></div>
 <?php if($canManage): ?><button type="button" onclick="document.getElementById('pastoral-new').showModal()">+ Novo acompanhamento</button><?php endif; ?>
</div>
<div class="cards" aria-label="Resumo do acompanhamento">
 <?php foreach(['total'=>'Todos','active'=>'Em andamento','overdue'=>'Retornos atrasados','closed'=>'Concluídos'] as $key=>$label): ?>
 <div class="card"><strong><?= $h($summary[$key]??0) ?></strong><span><?= $h($label) ?></span></div>
 <?php endforeach; ?>
</div>
<form method="get" action="<?= $h($baseUrl) ?>" class="filters">
 <label>Buscar acompanhamento<input type="search" name="q" maxlength="180" value="<?= $h($_GET['q']??'') ?>" placeholder="Assunto"></label>
 <label>Situação<select name="status">
 <?php foreach([''=>'Todas','open'=>'Aberto','scheduled'=>'Agendado','in_progress'=>'Em andamento','closed'=>'Concluído','cancelled'=>'Cancelado'] as $value=>$label): ?>
 <option value="<?= $h($value) ?>" <?= ($_GET['status']??'')===$value?'selected':'' ?>><?= $h($label) ?></option>
 <?php endforeach; ?>
 </select></label>
 <button type="submit">Filtrar</button>
</form>
<div class="scroll"><table><thead><tr><th>Assunto</th><th>Situação</th><th>Prioridade</th><th>Responsável</th><th>Próximo retorno</th></tr></thead><tbody>
<?php foreach($listing['rows'] as $item): ?>
<tr><td><?= $h($item['subject']) ?></td><td><?= $h($item['status']) ?></td><td><?= $h($item['priority']) ?></td><td><?= $h($item['assigned_user_id']??'—') ?></td><td><?= $h($item['next_contact_at']??'Não agendado') ?></td></tr>
<?php endforeach; ?>
<?php if(empty($listing['rows'])): ?><tr><td colspan="5" class="empty">Nenhum acompanhamento encontrado.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="pager">
 <span><?= $h($listing['total']) ?> registros</span>
 <?php $page=max(1,(int)($listing['page']??1));$size=max(1,(int)($listing['page_size']??20)); ?>
 <?php if($page>1): ?><a href="<?= $h($baseUrl.'?'.http_build_query(['page'=>$page-1,'status'=>$_GET['status']??'','q'=>$_GET['q']??''])) ?>">Anterior</a><?php endif; ?>
 <span>Página <?= $h($page) ?></span>
 <?php if($page*$size<(int)$listing['total']): ?><a href="<?= $h($baseUrl.'?'.http_build_query(['page'=>$page+1,'status'=>$_GET['status']??'','q'=>$_GET['q']??''])) ?>">Próxima</a><?php endif; ?>
</div>
<?php if($canManage): ?>
<dialog id="pastoral-new" aria-labelledby="pastoral-dialog-title">
 <form method="post" action="<?= $h($baseUrl) ?>">
 <h2 id="pastoral-dialog-title">Novo acompanhamento</h2>
 <input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
 <input type="hidden" name="action" value="create">
 <p><label>Assunto<input name="subject" required maxlength="180" style="width:100%" placeholder="Ex.: visita de acolhimento"></label></p>
 <p class="sub">Os registros são restritos aos usuários autorizados da igreja.</p>
 <div class="actions"><button type="button" style="background:#64748b" onclick="document.getElementById('pastoral-new').close()">Cancelar</button><button type="submit">Salvar</button></div>
 </form>
</dialog>
<?php endif; ?>
</section>
