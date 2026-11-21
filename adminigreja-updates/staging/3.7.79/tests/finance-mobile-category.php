<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceEntryPresentation.php';
function check(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException('Finance visual labels: '.$msg);}
function entry(string $direction,string $category,?string $member=null,?string $party=null):array {
  return ['direction'=>$direction,'category'=>$category,'member_name'=>$member,
    'counterparty'=>$party,'description'=>'Exemplo de lançamento'];
}
$tithe=FinanceEntryPresentation::classify(entry('income','Dízimos','Maria Oliveira'));
check($tithe['type']==='DÍZIMO','category Dízimos must be prominent DÍZIMO');
check($tithe['type_class']==='tithe','tithe CSS theme');
check($tithe['person_label']==='Dizimista','dizimista visibly identified');
check($tithe['person']==='Maria Oliveira','saved member shown');
check($tithe['member_linked']===true,'member linkage indicated only if present');

$anonymous=FinanceEntryPresentation::classify(entry('income','Dízimo',null));
check($anonymous['type']==='DÍZIMO','dízimo still identified even without a member');
check($anonymous['person']==='Não informado','anonymous tithe not attributed to someone');
check($anonymous['member_linked']===false,'never fabricate membership');
$namedUnlinked=FinanceEntryPresentation::classify(entry('income','Dízimo',null,'Jose de Souza'));
check($namedUnlinked['person']==='Jose de Souza','manual contributor preserved');
check($namedUnlinked['member_linked']===false,'manual typed contributor is not linked member');

$offer=FinanceEntryPresentation::classify(entry('income','Ofertas',null,'Família'));
check($offer['type']==='OFERTA','offer not confused with tithe');
check($offer['type_class']==='offering','offering prominent CSS');
check($offer['person_label']==='Contribuinte','do not call offering a tithe');

$expense=FinanceEntryPresentation::classify(entry('expense','Material de limpeza',null,'Fornecedor Exemplo'));
check($expense['type']==='SAÍDA','expense always shown as exit');
check($expense['subtype']==='Material de limpeza','specific expense category prominent');
check($expense['category_label']==='Tipo da saída','user sees what kind of expense');
check($expense['person_label']==='Favorecido','expense recipient');
check($expense['person']==='Fornecedor Exemplo','recipient visibly printed');
$fake=FinanceEntryPresentation::classify(entry('expense','Dízimos',null,'Nome'));
check($fake['type']==='SAÍDA','saved expense direction wins over category text');
$unrelated=FinanceEntryPresentation::classify(entry('income','Contribuições gerais',null,'Alguém'));
check($unrelated['type']==='ENTRADA','do not guess tithe or offering from ordinary income');
$empty=FinanceEntryPresentation::classify(entry('expense','',null,null));
check($empty['subtype']==='Despesa sem categoria','display blank expense category safely');
check($empty['person']==='Não informado','missing counterparty not guessed');
echo "PASS explicit financial labels: Dizimo identified/unlinked, Oferta, Saida subtype, contributor, saved category and no invented ties.\n";
