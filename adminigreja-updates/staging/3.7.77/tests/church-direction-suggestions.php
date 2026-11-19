<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceFiscalScan.php';
require __DIR__.'/../public/lib/FinanceChurchPartyDirection.php';
function check(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('CHURCH DIRECTION FAILED: '.$message);
}

$church='Igreja De Deus - Campo Belo';
$receipt="SICOOB\nComprovante de envio Pix\nR$ 2.172,03\n".
"Transferido em 18/11/2026 às 09:55:45\nparcela emprestimo idbcb\n".
"Recebedor\nNome\nIGREJA DE DEUS NO BRASIL\nCPF/CNPJ\n**.***.9.629/0001-**\n".
"Pagador\nNome\nIGREJA DE DEUS NO BRASIL -\nCAMPO BELO, CATALAO-GO\nCPF/CNPJ\n**.***.1.989/0001-**\n".
"ID da Transacao\nE12345678".str_repeat("A",23);
$parsed=FinanceFiscalScan::parse($receipt);
check($parsed['direction']==='expense','Pix envio do Sicoob detected');
$mapped=FinanceChurchPartyDirection::apply($parsed,$church);
check($mapped['suggested_direction']==='expense','church as payer must be SAÍDA');
check($mapped['direction']==='expense','preselected expense');
check($mapped['direction_source']==='church_is_payer','classification reason from payer');
check(str_contains($mapped['direction_reason'],'PAGADORA'),'human explanation in Portuguese');
check($mapped['issuer']==='IGREJA DE DEUS NO BRASIL','counterparty is receiver, not the church paying');
check($mapped['direction_review_required']===true,'pastor/treasurer must review');
check($mapped['amount']==='2172.03','never modify financial amount');
check($mapped['date']==='2026-11-18','never modify transaction date');

$reversed="Comprovante de recebimento Pix\nR$ 50,00\nRecebido em 18/11/2026 às 15:40:00\n".
"Recebedor\nNome\nIGREJA DE DEUS NO BRASIL - CAMPO BELO, CATALAO-GO\n".
"CPF/CNPJ\n**\nPagador\nNome\nJOAO PEREIRA\nCPF/CNPJ\n**\n";
$other=FinanceChurchPartyDirection::apply(FinanceFiscalScan::parse($reversed),$church);
check($other['suggested_direction']==='income','church receiver must be ENTRADA');
check($other['direction']==='income','preselect income');
check($other['issuer']==='JOAO PEREIRA','counterparty is actual payer');
check($other['direction_source']==='church_is_receiver','income explanation');

$both=FinanceChurchPartyDirection::apply([
  'document_type'=>'proof','payer'=>'Igreja De Deus - Campo Belo',
  'receiver'=>'Igreja de Deus no Brasil - Campo Belo, Catalão-GO',
  'direction'=>'expense','warnings'=>[]
],$church);
check($both['suggested_direction']===null,'between two church accounts must not guess expense');
check($both['direction']===null,'internal transfer must require manual choice');
check($both['direction_source']==='both_parties_church','internal transfer classified as ambiguous');

$generic=FinanceChurchPartyDirection::apply([
  'document_type'=>'proof','payer'=>'IGREJA DE DEUS NO BRASIL',
  'receiver'=>'BANCO EXEMPLO','direction'=>'expense','warnings'=>[]
],$church);
check($generic['suggested_direction']===null,'generic receiver name cannot identify congregation');
check($generic['direction_source']==='party_unconfirmed','ambiguity noted');
check(!FinanceChurchPartyDirection::matches($church,'IGREJA DE DEUS NO BRASIL'),
    'must not match broad institution without Campo Belo');
check(!FinanceChurchPartyDirection::matches('Igreja Casa da Bênção Ouvidor','Igreja Casa da Bênção Catalão'),
    'same ministry in another city must not match');
check(!FinanceChurchPartyDirection::matches($church,'Igreja de Deus - Campo Azul'),
    'same ministry but another congregation must not match');
check(!FinanceChurchPartyDirection::matches('Igreja de Deus',$church),
    'generic church name without distinctive tokens is not auto matched');

$cross=FinanceChurchPartyDirection::apply($parsed,'Igreja Casa da Bênção Ouvidor');
check($cross['suggested_direction']===null,'receipt from another church does not affect this church');
check($cross['direction_source']==='party_unconfirmed','no cross-tenant direction suggestion');

$fiscal=FinanceChurchPartyDirection::apply([
    'document_type'=>'fiscal_coupon','payer'=>$church,'receiver'=>'Mercado do Centro',
    'direction'=>'expense','amount'=>'13.90'
],$church);
check(!isset($fiscal['suggested_direction']),'fiscal receipts retain pre-existing classification');
check($fiscal['direction']==='expense','fiscal direction preserved');

$contradictory=FinanceChurchPartyDirection::apply([
 'document_type'=>'proof','payer'=>$church,'receiver'=>'Joao Pereira',
 'direction'=>'income','warnings'=>[]
],$church);
check($contradictory['suggested_direction']==='expense','identity stronger than generic OCR label');
check(str_contains(implode(' ', $contradictory['warnings']),'parecia indicar entrada'),
    'conflicting proof label triggers explicit manual-review warning');

$missing=FinanceChurchPartyDirection::apply([
    'document_type'=>'proof','payer'=>null,'receiver'=>null,
    'direction'=>'income','warnings'=>[]
],$church);
check(!isset($missing['suggested_direction']),'no names means no fabricated church identification');
echo "PASS: bank Pix church payer=expense, church receiver=income, matching congregations only, internal transfer and conflicting documents require review, tenant isolation, fiscal receipts preserved.\n";
