<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/ReportService.php';
require_once __DIR__.'/lib/SimplePdf.php';

$user=$auth->requireLogin();
$church=$auth->currentChurch();
if(!$church)redirect('/logout.php');

$type=trim((string)($_GET['type']??''));
$id=trim((string)($_GET['id']??''));
$format=(string)($_GET['format']??'print');

$ability=match($type){
    'finance','finance_closing','receipt'=>'finance',
    'prayer_clock','raffle'=>'communications',
    'event','service'=>'secretary',
    default=>'dashboard',
};
$auth->requireAbility($ability);

try{
    $report=(new ReportService($pdo))->build(
        $church,
        $type,
        $id,
        $_GET['from']??null,
        $_GET['to']??null
    );
}catch(Throwable $e){
    http_response_code(404);
    exit(e($e->getMessage()));
}

if($format==='pdf'){
    $pdf=new SimplePdf($report['title'],$church['name']);
    $pdf->heading($report['title']);
    $pdf->line($report['subtitle'],11,true);
    $pdf->spacer(5);
    foreach($report['summary'] as $label=>$value)$pdf->line($label.': '.$value,10,$label==='Resultado');
    $pdf->separator();
    $pdf->line(implode(' | ',$report['columns']),9,true);
    $pdf->separator();

    foreach($report['rows'] as $row){
        $parts=[];
        foreach($row as $cell)$parts[]=trim((string)$cell);
        $pdf->line(implode(' | ',$parts),9,false,4);
    }
    if(!empty($report['receipt'])){
        $pdf->spacer(34);
        $pdf->line('_______________________________________________',10);
        $pdf->line((string)$report['receipt']['signature_label'],9,true);
        if(!empty($report['receipt']['church_address']))$pdf->line((string)$report['receipt']['church_address'],8);
        if(!empty($report['receipt']['church_phone']))$pdf->line('Telefone: '.(string)$report['receipt']['church_phone'],8);
    }
    $pdf->spacer();
    $pdf->line('Emitido em '.date('d/m/Y H:i').' pelo Igreja Master.',8);
    $pdf->download($report['title'].'.pdf');
}

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($report['title'])?></title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#172033;margin:0;background:#f2f3f5}.sheet{max-width:1000px;margin:28px auto;background:#fff;padding:34px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{font-size:24px;margin:0 0 4px}.sub{color:#667085;margin-bottom:22px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:18px 0}.summary div{border:1px solid #e3e6eb;border-radius:9px;padding:10px}.summary span{display:block;font-size:11px;color:#667085;margin-bottom:4px}.summary strong{font-size:13px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:8px;border-bottom:1px solid #e6e8eb;text-align:left;vertical-align:top}th{background:#f6f7f8}.toolbar{max-width:1000px;margin:20px auto 0;display:flex;gap:8px}.btn{border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;background:#172033;color:#fff}.btn.alt{background:#e6a100}.foot{font-size:10px;color:#707885;margin-top:24px;text-align:center}@media(max-width:700px){.summary{grid-template-columns:1fr 1fr}.sheet{margin:0;border-radius:0;padding:18px}}@media print{body{background:#fff}.toolbar{display:none}.sheet{box-shadow:none;margin:0;max-width:none;padding:0}.summary div{break-inside:avoid}tr{break-inside:avoid}}
</style>
</head>
<body>
<div class="toolbar">
<button class="btn" onclick="window.print()">🖨️ Imprimir</button>
<a class="btn alt" href="?<?=e(http_build_query(array_merge($_GET,['format'=>'pdf'])))?>">⬇ PDF</a>
<button class="btn" onclick="window.close()">Fechar</button>
</div>
<main class="sheet">
<h1><?=e($report['title'])?></h1>
<div class="sub"><?=e($report['subtitle'])?></div>
<div class="summary"><?php foreach($report['summary'] as $label=>$value):?><div><span><?=e((string)$label)?></span><strong><?=e((string)$value)?></strong></div><?php endforeach?></div>
<table><thead><tr><?php foreach($report['columns'] as $col):?><th><?=e((string)$col)?></th><?php endforeach?></tr></thead><tbody>
<?php foreach($report['rows'] as $row):?><tr><?php foreach($row as $cell):?><td><?=e((string)$cell)?></td><?php endforeach?></tr><?php endforeach?>
<?php if(!$report['rows']):?><tr><td colspan="<?=count($report['columns'])?>">Nenhum registro.</td></tr><?php endif?>
</tbody></table>
<?php if(!empty($report['receipt'])):?><div style="margin-top:70px;max-width:360px;text-align:center"><div style="border-top:1px solid #172033;padding-top:7px;font-size:12px;font-weight:700"><?=e((string)$report['receipt']['signature_label'])?></div><?php if(!empty($report['receipt']['church_address'])):?><div style="font-size:10px;color:#667085;margin-top:5px"><?=e((string)$report['receipt']['church_address'])?></div><?php endif?><?php if(!empty($report['receipt']['church_phone'])):?><div style="font-size:10px;color:#667085"><?=e((string)$report['receipt']['church_phone'])?></div><?php endif?></div><?php endif?>
<div class="foot">Emitido em <?=date('d/m/Y H:i')?> · Igreja Master</div>
</main>
</body></html>
