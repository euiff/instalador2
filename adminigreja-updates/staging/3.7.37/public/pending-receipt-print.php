<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/PendingFinanceReceipt.php';
$auth->requireAbility('finance');
$church=$auth->currentChurch();if(!$church)redirect('/logout.php');
// A tabela é criada pela migração 051; não executar DDL durante visualização.
$draft=(new PendingFinanceReceipt($pdo,(string)$church['id'],(string)$auth->requireLogin()['id']))
    ->find(trim((string)($_GET['id']??'')));
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recibo provisório - <?=e($church['name'])?></title><style>
body{margin:20px auto;max-width:760px;font:16px Arial,sans-serif;color:#12385e;background:white;padding:20px}
h1{text-align:center;font-size:23px;color:#073e7e}section{border:1px solid #bfd2e2;padding:28px;border-radius:10px;line-height:2}
p{text-align:justify}.signature{margin-top:75px;text-align:center}
.signature hr{width:65%;border:0;border-top:1px solid #345}.warning{color:#a36500;font-weight:bold}
button{border:0;background:#0b53a2;color:white;padding:13px;border-radius:7px}
@media print{button{display:none}body{padding:0;margin:0}section{border:0}.warning{display:block}}
</style></head><body>
<button onclick="window.print()">Imprimir para assinatura</button>
<section><h1>RECIBO PROVISÓRIO</h1>
<p><strong><?=e($church['name'])?></strong></p>
<p>Recebemos de <strong><?=e($draft['payer_name'])?></strong>
a quantia de <strong>R$ <?=number_format((float)$draft['amount'],2,',','.')?></strong>,
referente a <strong><?=e($draft['description'])?></strong>.</p>
<p>Data: <?=e(date('d/m/Y',strtotime($draft['received_at'])))?></p>
<p class="warning">Recibo provisório. Não contabilizado até assinatura e conferência pela Tesouraria.</p>
<div class="signature"><hr>Assinatura de quem entregou o valor</div>
<p style="font-size:12px;text-align:center">Referência interna: <?=e($draft['id'])?></p>
</section></body></html>
