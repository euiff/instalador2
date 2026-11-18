<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceFiscalScan.php';
function checkLocal(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException('LOCAL OCR PARSER FAILED: '.$msg);
}
$e2e='E12345678'.str_repeat('A',23);
$pix="Banco Exemplo\nComprovante de transferencia PIX\nData da transacao: 17/11/2026\nValor enviado: R$ 65,40\n".
     "EndToEnd ID: ".$e2e."\nCPF da pessoa: 123.456.789-00";
$p=FinanceFiscalScan::parse($pix);
checkLocal($p['document_number']===$e2e,'Pix E2E recognized');
checkLocal($p['bank_reference_type']==='pix_e2e','real Pix bank identifier type');
checkLocal($p['amount']==='65.40','Pix monetary amount');
checkLocal($p['date']==='2026-11-17','Pix transaction date');
checkLocal($p['document_type']==='proof','Pix is bank proof, not a tax invoice');
checkLocal(empty($p['category']),'never fabricate accounting category');
checkLocal(!empty($p['warnings']),'manual verification warnings displayed');

$pixUnlabeled="PIX enviado em 17/11/2026\nValor Pix: 100,00\n".$e2e;
$p=FinanceFiscalScan::parse($pixUnlabeled);
checkLocal($p['bank_reference']===$e2e,'Pix E2E detected even without visible label');

$bank="Banco Realizado\nComprovante de transferencia\nRealizado em: 05/11/2026\n".
"Valor da transferencia: R$ 1.234,56\nAutenticacao bancaria: AB20261105-98765";
$p=FinanceFiscalScan::parse($bank);
checkLocal($p['bank_reference']==='AB20261105-98765','bank authentication preserved');
checkLocal($p['amount']==='1234.56','Brazilian currency amount with thousands');
checkLocal($p['date']==='2026-11-05','bank transaction date');

$invoice="MERCADO DE TESTE LTDA\nCNPJ: 12.345.678/0001-95\nDANFE NFC-E\n".
"Emissao: 12/11/2026 13:44\nNOTA n: 123456\nVALOR TOTAL: R$ 87,90";
$p=FinanceFiscalScan::parse($invoice);
checkLocal($p['document_type']==='fiscal_coupon','fiscal receipt type');
checkLocal($p['amount']==='87.90','fiscal total');
checkLocal($p['date']==='2026-11-12','fiscal emission date');
checkLocal($p['issuer_tax_id']==='12345678000195','issuer CNPJ from text');
checkLocal($p['document_number']==='123456','fiscal coupon number');
checkLocal($p['category']===null,'do not guess finance category from merchant name');

$weak=FinanceFiscalScan::parse("Imagem com texto confuso\nInformacoes sem valor nem data");
checkLocal($weak['amount']===null,'no invented amount');
checkLocal($weak['date']===null,'no invented date');
checkLocal($weak['bank_reference']===null,'no invented bank reference');
checkLocal($weak['confidence']==='low','low confidence on incomplete receipt');
$sha=hash('sha256','conteudo exato do arquivo pdf');
$internal=FinanceFiscalScan::fileReference($sha);
checkLocal((bool)$internal && FinanceFiscalScan::isFileReference($internal),'deterministic local document ID');
checkLocal($internal===FinanceFiscalScan::fileReference($sha),'same document same ID');
checkLocal($internal!==FinanceFiscalScan::fileReference(hash('sha256','outra foto')),'different bytes different ID');

echo "PASS: local no-credit parser for Pix E2E, bank authentication, NFC-e, Brazilian money and dates, no guessed amounts/categories, deterministic file IDs.\n";
