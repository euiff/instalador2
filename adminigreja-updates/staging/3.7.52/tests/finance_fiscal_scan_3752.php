<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceFiscalScan.php';
function eq($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.' failed: '.var_export($actual,true).' != '.var_export($expected,true));
}
$base='52'.'2611'.'12345678000190'.'65'.'001'.'000000123'.'1'.'12345678';
if(strlen($base)!==43)throw new RuntimeException('Test key base invalid');
$sum=0;$weight=2;
for($i=42;$i>=0;$i--){$sum+=(int)$base[$i]*$weight;$weight=$weight===9?2:$weight+1;}
$digit=11-($sum%11);if($digit>=10)$digit=0;
$key=$base.(string)$digit;
eq(FinanceFiscalScan::key44($key),$key,'NFC-e key 44');
$receipt="MERCADO SAO JOSE\nCNPJ: 12.345.678/0001-90\nDOCUMENTO AUXILIAR NFC-E\nDATA DE EMISSAO 12/11/2026\nNUMERO NFCe: 123\nTOTAL R$ 34,90\nCHAVE DE ACESSO\n$key";
$f=FinanceFiscalScan::parse($receipt);
eq($f['access_key'],$key,'access_key');
eq($f['issuer_tax_id'],'12345678000190','cnpj');
eq($f['date'],'2026-11-12','date');
eq($f['amount'],'34.90','amount');
eq($f['short_number'],'123','short number');
eq($f['document_number'],$key,'document number');
eq(FinanceFiscalScan::money('1.234,56'),'1234.56','currency');
eq(FinanceFiscalScan::money('15,90'),'15.90','currency 2');
eq(FinanceFiscalScan::money('1.234.567,80'),'1234567.80','currency thousands');
eq(FinanceFiscalScan::key44(str_repeat('1',44)),null,'invalid key');
$noAmount=FinanceFiscalScan::parse("MERCADO\nCNPJ: 12.345.678/0001-90\nNOTA EMISSAO 11/11/2026");
eq($noAmount['amount'],null,'no invented amount');
eq(FinanceFiscalScan::parse('Nota fiscal, sem data')['date'],null,'no invented date');
echo "OK FinanceFiscalScan parsing, totals, identifiers and unknowns\n";
