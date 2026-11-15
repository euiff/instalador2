<?php
declare(strict_types=1);
require dirname(__DIR__).'/public/lib/MasterFinanceImport.php';
/**
 * Real headings from basededados-idb-cb.xlsx — do not store confidential
 * transaction rows, only the public-style column names for regression tests.
 */
$headers=[
    'type',
    'DESCRIÇÃO DO LANÇAMENTO',
    'DATA',
    'VALOR',
    'CONTA/CAIXA DESTINO',
    'MEMBRO CONTRIBUINTE',
    'Nº documento / cupom / referência Pix',
    'Forma de pagamento',
    'Lançamento Contábil *',
];
$checks=[
    'DESCRIÇÃO DO LANÇAMENTO'=>'descricaodolancamento',
    'CONTA/CAIXA DESTINO'=>'contacaixadestino',
    'Nº documento / cupom / referência Pix'=>'nodocumentocupomreferenciapix',
    'Lançamento Contábil *'=>'lancamentocontabil',
    'Saída'=>'saida',
    'DÍZIMOS'=>'dizimos',
];
foreach($checks as $raw=>$expected){
    $actual=MasterFinanceImport::normalized($raw);
    if($actual!==$expected)throw new RuntimeException(
        'Normalization mismatch for "'.$raw.'": got '.$actual.', expected '.$expected
    );
}
$rows=[
    ['Saída','Compra histórica','44557','1,88','1.1 - DPTO HOMEM','','sistema-antigo','Outro','OUTRAS DESPESAS'],
    ['Entrada','Dízimo','44544','200','3.2 - DÍZIMOS - CX IGREJA','Pessoa Exemplo','sistema-antigo','Outro','DÍZIMOS']
];
$tmp=tempnam(sys_get_temp_dir(),'finance-headers-');
if($tmp===false)throw new RuntimeException('Could not create test file');
$csv=$tmp.'.csv';
rename($tmp,$csv);
try{
    $fp=fopen($csv,'wb');
    fputcsv($fp,$headers,';');
    foreach($rows as $row)fputcsv($fp,$row,';');
    fclose($fp);
    $r=(new ReflectionClass(MasterFinanceImport::class))->newInstanceWithoutConstructor();
    $parsed=$r->parse($csv,'basededados-idb-cb.csv');
    if($parsed['count']!==2||$parsed['count_income']!==1||$parsed['count_expense']!==1)
        throw new RuntimeException('CSV regression fixture did not produce two valid entries');
    if($parsed['rows'][0]['category']!=='OUTRAS DESPESAS'
        ||$parsed['rows'][1]['person']!=='Pessoa Exemplo'
        ||$parsed['rows'][0]['date']!=='2021-12-27'
        ||$parsed['rows'][1]['date']!=='2021-12-14')
        throw new RuntimeException('Column data alignment, value or Excel date was lost');
    if($parsed['income_cents']!==20000 || $parsed['expense_cents']!==188)
        throw new RuntimeException('Monetary totals differ');
    echo "PASS: Portuguese source headers, UTF-8 accents and two accounting rows parse with locale-independent normalization.\n";
}finally{ @unlink($csv); }
