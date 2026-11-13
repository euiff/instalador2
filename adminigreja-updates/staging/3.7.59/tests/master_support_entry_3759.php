<?php
declare(strict_types=1);

/** Role/UI guard regression checks without a production database or user records. */
class Auth
{
    public function __construct(private bool $master,private bool $support,private ?array $church=null) {}
    public function isSuperAdmin():bool{return $this->master;}
    public function isMasterSupportMode():bool{return $this->support;}
    public function currentChurch():?array{return $this->church;}
    public function requireLogin():array{return ['id'=>'11111111-1111-1111-1111-111111111111'];}
}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf_field():string{return '<input name="_csrf" value="test" type="hidden">';}
require __DIR__.'/../public/lib/MasterSupportFinanceUi.php';
require __DIR__.'/../public/lib/MasterSingleFinanceEntry.php';
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException('FAIL: '.$what);}
$church=['id'=>'22222222-2222-2222-2222-222222222222','name'=>'Igreja Teste'];
$master=new Auth(true,true,$church);
check(MasterSupportFinanceUi::allowed($master,$church),'Master support allowed');
check(!MasterSupportFinanceUi::allowed(new Auth(false,true,$church),$church),'Church user cannot see Master button');
check(!MasterSupportFinanceUi::allowed(new Auth(true,false,$church),$church),'Platform Master without support context cannot see church controls');
check(!MasterSupportFinanceUi::allowed(new Auth(true,true,['id'=>'33333333-3333-3333-3333-333333333333']),$church),'Wrong tenant is denied');
$posted=['id'=>'44444444-4444-4444-4444-444444444444','amount'=>'25.90',
 'description'=>'Teste seguro','transaction_date'=>'2026-11-13','status'=>'posted'];
ob_start();MasterSupportFinanceUi::exclusionForm($posted);$html=ob_get_clean();
check(str_contains($html,'Excluir lançamento (Master)'),'Active entry shows Master button');
check(str_contains($html,'name="reason"')&&str_contains($html,'name="acknowledge_exclusion"'),'Action requires reason and confirmation');
check(str_contains($html,'name="entry_id"')&&str_contains($html,'master_exclude_entry'),'Exact entry/action is specified');
$reversed=$posted;$reversed['status']='reversed';
ob_start();MasterSupportFinanceUi::exclusionForm($reversed);$hiddenHtml=ob_get_clean();
check(str_contains($hiddenHtml,'Ocultar estornado (Master)'),'Reversed entry has archive-only control');
$bad=$posted;$bad['status']='pending';
ob_start();MasterSupportFinanceUi::exclusionForm($bad);$other=ob_get_clean();
check($other==='','Other statuses cannot be excluded');
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Test requires PDO SQLite.');
$db=new PDO('sqlite::memory:');
$service=new MasterSingleFinanceEntry($db,sys_get_temp_dir());
$denied=false;
try{$service->exclude(new Auth(false,true,$church),$church['id'],$posted['id'],$posted['id'],
 'Lançamento em duplicidade','11111111-1111-1111-1111-111111111111');}
catch(RuntimeException $ex){$denied=str_contains($ex->getMessage(),'Somente o Master');}
check($denied,'Non-master rejected by service before any database query');
echo "OK Master support role gate, cross-tenant denial, active/reversed controls, confirmation and service protection\n";
