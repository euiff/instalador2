<?php
declare(strict_types=1);
require __DIR__.'/../release/public/lib/ReceiptParser.php';
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
$sample="MERCADO CENTRAL LTDA\nCNPJ: 04.252.011/0001-10\nDATA: 02/11/2026\nDETERGENTE NEUTRO 5,90\nPAPEL A4 23,00\nVALOR TOTAL R$ 28,90\n";
$r=ReceiptParser::parse($sample);
$check($r['cnpj']==='04252011000110','CNPJ valido');
$check($r['issued_on']==='2026-11-02','data');
$check($r['total']==='28.90','total do cupom');
$check(count($r['items'])>=2,'linhas de itens');
$check(ReceiptParser::suggest('detergente neutro')['category']==='Material de limpeza','categoria de limpeza');
$check(ReceiptParser::suggest('Papel A4')['category']==='Material de escritório','categoria de escritorio');
$check(ReceiptParser::amount('1.234,56')==='1234.56','numero pt-BR');
$check(ReceiptParser::amount('valor errado')===null,'nao inventar numeros');
$check(ReceiptParser::parse('')['total']==='','ausencia de dados nao deve produzir total');
$xml='<?xml version="1.0" encoding="UTF-8"?><NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.str_repeat('1',44).'"><ide><nNF>874</nNF><dhEmi>2026-11-02T12:30:00-03:00</dhEmi></ide><emit><xNome>Loja de teste</xNome><CNPJ>04252011000110</CNPJ></emit><det nItem="1"><prod><xProd>DETERGENTE</xProd><vProd>10.00</vProd></prod></det><total><ICMSTot><vNF>10.00</vNF></ICMSTot></total></infNFe></NFe>';
if(class_exists('DOMDocument')){
    $x=ReceiptParser::parseXml($xml);
    $check($x['supplier']==='Loja de teste','fornecedor do XML');
    $check($x['number']==='874'&&$x['total']==='10.00','NF-e XML');
    $check(count($x['items'])===1,'itens XML');
}
echo "PASS OCR, categories, BRL, XML, validations\n";
