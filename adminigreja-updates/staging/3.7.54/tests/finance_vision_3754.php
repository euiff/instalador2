<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceVisionReader.php';

function checkSame($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));
}

checkSame(FinanceVisionReader::validCnpj('11222333000181'),true,'CNPJ checksum valid');
checkSame(FinanceVisionReader::validCnpj('11222333000182'),false,'CNPJ checksum invalid');
checkSame(FinanceVisionReader::validCnpj('11111111111111'),false,'CNPJ repeated digits');

$data=FinanceVisionReader::normalize([
    'document_type'=>'fiscal_coupon',
    'issuer'=>'SUPERMERCADO BRASIL',
    'issuer_tax_id'=>'11.222.333/0001-81',
    'issue_date'=>'2026-11-12',
    'total_amount'=>'1.234,56',
    'short_number'=>'102030',
    'description'=>'Compra de material de limpeza',
    'category'=>'MATERIAIS',
    'payment_method'=>'PIX',
    'confidence'=>'high',
],['MATERIAIS','DÍZIMOS']);
checkSame($data['amount'],'1234.56','total');
checkSame($data['date'],'2026-11-12','date');
checkSame($data['issuer_tax_id'],'11222333000181','cnpj');
checkSame($data['document_number'],'102030','number');
checkSame($data['category'],'MATERIAIS','tenant category');
checkSame($data['confidence'],'high','confidence');

$invalid=FinanceVisionReader::normalize([
    'issuer'=>'ESTABELECIMENTO',
    'issuer_tax_id'=>'11111111111111',
    'issue_date'=>'2026-02-30',
    'total_amount'=>'20x,45',
    'access_key'=>str_repeat('1',44),
    'category'=>'NAO CADASTRADA',
    'confidence'=>'high'
],['OFERTAS']);
checkSame($invalid['amount'],null,'invalid amount not filled');
checkSame($invalid['date'],null,'invalid date not filled');
checkSame($invalid['issuer_tax_id'],null,'invalid cnpj not filled');
checkSame($invalid['access_key'],null,'invalid fiscal key not filled');
checkSame($invalid['category'],null,'unregistered category not invented');
checkSame($invalid['confidence'],'low','incomplete receipt low confidence');
checkSame(count($invalid['warnings'])>=2,true,'warning for invalid fields');

echo "OK Gemini fiscal result validation and tenant category controls\n";
