<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceFiscalScan.php';

function ok(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('PIX OCR FAIL: '.$message);
}
$e2e='E12345678'.str_repeat('A',23); // synthetic, not user's real bank ID
$ocrWithWrongFirstLetter="Comprovante de envio Pix\nRS 2.172,03\n".
"Transferido em 18/11/2026 as 09:55:45\n".
"parcela emprestimo idbcb\n".
"Comprovante para simples\nconferencia gerado em\n18/11/2026 as 18:40:47\n".
"Recebedor\nNome\nIGREJA EXEMPLO RECEBEDORA\nCPF/CNPJ\n**.***.9.629/0001-**\n".
"Instituicao\nBANCO DO RECEBEDOR\n".
"Pagador\nNome\nIGREJA EXEMPLO - CAMPO BELO,\nCATALAO-GO\n".
"CPF/CNPJ\n**.***.1.989/0001-**\nInstituicao\nBANCO DO PAGADOR\n".
"ID da Transagao:\n£".substr($e2e,1)."\n";
$fields=FinanceFiscalScan::parse($ocrWithWrongFirstLetter);
ok($fields['direction']==='expense','sent pix is an expense');
ok($fields['document_type']==='proof','bank Pix is proof, not NFC-e or invoice');
ok($fields['amount']==='2172.03','standalone amount is read correctly');
ok($fields['date']==='2026-11-18','transfer date instead of issued PDF date');
ok($fields['payment_time']==='09:55:45','time of payment not PDF creation');
ok($fields['issuer']==='IGREJA EXEMPLO RECEBEDORA','beneficiary not bank header');
ok(str_contains($fields['payer'],'CAMPO BELO'),'payer retained including second line');
ok($fields['receiver']==='IGREJA EXEMPLO RECEBEDORA','receiver extracted');
ok($fields['payment_method']==='PIX manual','method maps to existing church dropdown');
ok($fields['issuer_tax_id']===null,'masked CNPJ is never presented as valid');
ok($fields['bank_reference']===$e2e,'recover £ misread as initial E only near ID label');
ok($fields['document_number']===$e2e,'precise E2E used for duplicate detection');
ok($fields['bank_reference_type']==='pix_e2e','strong Pix ID recognised');
ok(str_contains($fields['description'],'parcela emprestimo idbcb'),'free text memo preserved');
ok($fields['category']===null,'no guessed accounting category from memo');
ok(str_contains(implode(' | ',$fields['warnings']),'corrigida'),'OCR correction disclosed');

$clean=str_replace('£'.substr($e2e,1),$e2e,$ocrWithWrongFirstLetter);
$clean="SICOOB\n".$clean;
$p=FinanceFiscalScan::parse($clean);
ok($p['bank_reference']===$e2e,'valid ID recognized exactly');
ok($p['bank_name']==='Sicoob','named source bank recognized only if present');
ok(!str_contains(implode(' | ',$p['warnings']),'corrigida'),'no correction warning for strict E2E');

$otherLabelOrder=str_replace("ID da Transagao:\n".$e2e,$e2e."\nID da Transacao:",$clean);
$p=FinanceFiscalScan::parse($otherLabelOrder);
ok($p['bank_reference']===$e2e,'OCR layout may place ID above its label');

$wrongId=str_replace('£'.substr($e2e,1),'£'.substr($e2e,1,18),$ocrWithWrongFirstLetter);
$p=FinanceFiscalScan::parse($wrongId);
ok($p['bank_reference']===null,'truncated Pix ID is not fabricated');
ok($p['document_number']===null,'bad reference must use file hash not invented bank ID');
ok($p['amount']==='2172.03','amount still recognised without ID');
ok($p['direction']==='expense','expense classification not lost with missing ID');

$received="Comprovante de recebimento Pix\nR$ 72,90\nRecebido em 17/11/2026 as 10:42:00\n".
"Recebedor\nNome\nIGREJA DESTINO\nCPF/CNPJ\n**\n".
"Pagador\nNome\nJOAO SILVA\nCPF/CNPJ\n**\n".
"ID da Transacao:\n".$e2e;
$p=FinanceFiscalScan::parse($received);
ok($p['direction']==='income','received Pix is income');
ok($p['issuer']==='JOAO SILVA','payer is the counterparty for income');
ok($p['amount']==='72.90','received Pix amount');
ok($p['date']==='2026-11-17','received Pix date');

// Keep the established generic parser for actual fiscal coupons and NFC-e.
$receipt="MERCADO EXEMPLO LTDA\nCNPJ: 12.345.678/0001-95\nDANFE NFC-e\n".
"Emissao: 15/11/2026\nNOTA n: 123456\nVALOR TOTAL: R$ 87,90";
$p=FinanceFiscalScan::parse($receipt);
ok($p['document_type']==='fiscal_coupon','NFC-e still uses existing parser');
ok($p['amount']==='87.90','NFC-e value preserved');
ok($p['date']==='2026-11-15','NFC-e date preserved');

echo "PASS actual Sicoob layout mirrored: £/E Pix ID, bank logos, amount R$ 2.172,03, Pix time vs PDF creation, payee/payer, sent/received, masked CNPJ, missing ID and fiscal coupon regressions.\n";
