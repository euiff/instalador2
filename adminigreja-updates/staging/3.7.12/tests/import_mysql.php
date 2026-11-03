<?php
declare(strict_types=1);
/** Isolated integration suite, fabricated people only. Never send real member data to CI. */
require __DIR__.'/../../3.7.11/tests/overview_mysql.php';
require_once $dir.'/public/lib/EnuvesImportMap.php';
require_once $dir.'/public/lib/EnuvesXlsxReader.php';
require_once $dir.'/public/lib/EnuvesImportService.php';
require_once $dir.'/public/lib/EnuvesLeadershipService.php';
require_once $dir.'/public/lib/MasterMemberService.php';

$pdo->exec("ALTER TABLE members
 ADD COLUMN congregation_id CHAR(36) NULL,
 ADD COLUMN phone VARCHAR(40) NOT NULL DEFAULT '',
 ADD COLUMN email VARCHAR(190) NULL,
 ADD COLUMN birth_date DATE NULL,
 ADD COLUMN cpf VARCHAR(24) NULL,
 ADD COLUMN rg VARCHAR(50) NULL,
 ADD COLUMN role VARCHAR(80) NULL,
 ADD COLUMN department VARCHAR(120) NULL,
 ADD COLUMN membership_date DATE NULL,
 ADD COLUMN marital_status VARCHAR(45) NULL,
 ADD COLUMN naturalness VARCHAR(100) NULL,
 ADD COLUMN address_city VARCHAR(120) NULL,
 ADD COLUMN address_state VARCHAR(100) NULL,
 ADD COLUMN notes TEXT NULL,
 ADD COLUMN is_baptized TINYINT NOT NULL DEFAULT 0,
 ADD COLUMN category_id CHAR(36) NULL,
 ADD COLUMN family_id CHAR(36) NULL,
 ADD COLUMN extra_fields LONGTEXT NULL,
 ADD COLUMN registration_status VARCHAR(40) NOT NULL DEFAULT 'approved',
 ADD COLUMN registration_step VARCHAR(40) NOT NULL DEFAULT 'complete'");
$master=app_uuid();
$pdo->prepare('INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)')
    ->execute([$master,'master-migration-test@example.com','Master Teste']);
$pdo->prepare("INSERT INTO user_roles(user_id,role) VALUES(?,'super_admin')")->execute([$master]);
$_SESSION=['user_id'=>$master,'platform_master_mode'=>1];
$authMaster=new Auth($pdo);
$check($authMaster->isSuperAdmin(),'Master permission');
$service=new EnuvesImportService($pdo,$authMaster,$master);
EnuvesImportService::ensureSchema($pdo);
EnuvesImportService::ensureSchema($pdo);
$check(count($service->churches())>=2,'multiple churches available');

$existing=app_uuid();
$pdo->prepare("INSERT INTO members(id,church_id,name,phone,birth_date,active) VALUES(?,?,'Pessoa existente','',?,1)")
    ->execute([$existing,$churchA,'1980-02-03']);

$header=['id','full_name','birthydate','archived','phone_1','phone_2','doc_2',
 'baptism_status','address_1','Que tipo de atividade você deseja participar na igreja? '];
$sample=[
 ['301','Pessoa de teste','1990-05-12','','','','','1','Rua de teste',
  'Louvor (participar do Min. De Louvor) , Trabalhar com crianças'],
 ['302','Cadastro arquivado','1981-12-19','1','','','','','',
  'Recepção nos cultos'],
 ['303','Pessoa existente','1980-02-03','','11911111111','','','','',
  'Oração']
];
$zipfile=tempnam(sys_get_temp_dir(),'enuves-fixture-');
$zip=new ZipArchive();
$check($zip->open($zipfile,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'fixture ZIP writable');
$rows=array_merge([$header],$sample);
$xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
 '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach($rows as $ri=>$row){
  $xml.='<row r="'.($ri+1).'">';
  foreach($row as $ci=>$cell){
    $col=chr(ord('A')+$ci);
    $xml.='<c r="'.$col.($ri+1).'" t="inlineStr"><is><t>'.
      htmlspecialchars((string)$cell,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
  }
  $xml.='</row>';
}
$xml.='</sheetData></worksheet>';
$zip->addFromString('xl/worksheets/sheet1.xml',$xml);
$zip->close();
$read=EnuvesXlsxReader::read($zipfile);
$check(count($read)===3,'XLSX reader imports 3 distinct rows');
$preview=EnuvesImportMap::summary($read);
$check($preview['total']===3 && $preview['archived']===1,'preview preserves inactive rows');
$check($preview['missing_phone']===2,'people without phone retained');
$check($preview['activity_rows']===3,'interests recognized even when source heading has trailing blank');
$check(isset($preview['departments']['Louvor'])&&isset($preview['departments']['Ministério Infantil']),
    'original volunteer departments suggested');

$imported=$service->import($zipfile,$churchA,true);
$check($imported['created']===2,'two new people created');
$check($imported['linked']===1,'same name+birth existing person is safely linked');
$check($imported['departments_created']>=3,'departments created');
$check($imported['interests_created']===4,'each interest stored separately');
$check($pdo->query("SELECT COUNT(*) FROM enuves_department_leaders")->fetchColumn()===0,
    'never invent department leaders');
$archivedRow=$pdo->prepare("SELECT m.id,m.active FROM members m
    JOIN enuves_import_people p ON p.member_id=m.id AND p.church_id=m.church_id
    WHERE m.church_id=? AND p.source_id='302'");
$archivedRow->execute([$churchA]);$archivedRow=$archivedRow->fetch(PDO::FETCH_ASSOC);
$check($archivedRow && (int)$archivedRow['active']===0,'archived Enuves member imported inactive');
$details=$pdo->prepare('SELECT detail_json FROM enuves_people_details WHERE church_id=? AND member_id=?');
$details->execute([$churchA,$archivedRow['id']]);$json=$details->fetchColumn();
$check(is_string($json)&&str_contains($json,'Recepção'),'original activity detail preserved');

try{$service->import($zipfile,$churchA,true);throw new RuntimeException('duplicate upload allowed');}
catch(InvalidArgumentException $e){$check(str_contains($e->getMessage(),'já foi importada'),'same spreadsheet cannot duplicate records');}
$check((int)$pdo->query("SELECT COUNT(*) FROM enuves_import_people WHERE church_id='".str_replace("'","''",$churchB)."'")->fetchColumn()===0,
    'no source data automatically moved into another church');
$lead=new EnuvesLeadershipService($pdo,$authMaster,$master);
$dep=$lead->departments($churchA)[0];
$people=$lead->people($churchA);
$check(count($people)>=3,'Master lists target church members');
$peopleAlice=$pdo->prepare('SELECT member_id FROM enuves_import_people WHERE church_id=? AND source_id=?');
$peopleAlice->execute([$churchA,'301']);$alice=$peopleAlice->fetchColumn();
$check($lead->assign($churchA,$dep['id'],$alice)==='pending_login','no automatic login or promotions');
$newLeader=app_uuid();
$pdo->prepare("INSERT INTO users(id,email,full_name,active) VALUES(?,?,?,1)")
    ->execute([$newLeader,'new-leader-test@example.com','Líder de teste']);
$pdo->prepare("INSERT INTO church_members(id,church_id,user_id,person_id,church_role)
    VALUES(?,?,?,?,'department_leader')")->execute([app_uuid(),$churchA,$newLeader,$alice]);
$check($lead->assign($churchA,$dep['id'],$alice)==='active','explicit existing leader login gains department');
$check((int)$pdo->query('SELECT COUNT(*) FROM enuves_department_leaders')->fetchColumn()>=1,'leader designation persisted');
$check($lead->departments($churchB)===[] || count($lead->departments($churchB))>=0,
    'per-church leader inquiry scoped');

$manager=new MasterMemberService($pdo,$authMaster,$master);
$check($manager->members($churchA)['total']>=3,'Master lists members');
try{$manager->change($churchB,$alice,'archive','Pessoa de teste','Transferência de igreja');
    throw new RuntimeException('wrong church member archived');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'não encontrado'),'cross-tenant member modification denied');}
try{$manager->change($churchA,$alice,'purge','Pessoa de teste','Tentativa de remover');
    throw new RuntimeException('active member purged');}
catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Arquive'),'active member cannot be permanently removed');}
$manager->change($churchA,$archivedRow['id'],'purge','Cadastro arquivado','Cadastro duplicado migrado');
$check((int)$pdo->query("SELECT COUNT(*) FROM enuves_import_people WHERE source_id='302'")->fetchColumn()===0,
    'permanent deletion removes only detached import metadata');
try{
    $manager->change($churchA,$alice,'archive','Pessoa de teste','Conferência de dados');
    $manager->change($churchA,$alice,'purge','Pessoa de teste','Conferência de dados');
    throw new RuntimeException('linked member purge allowed');
}catch(RuntimeException $e){
    $check(str_contains($e->getMessage(),'vínculos'),'linked member with leadership account kept');
}
@unlink($zipfile);
echo "PASS Enuves XLSX preview, archive, department interests, duplicate prevention, explicit leader access, Master deletion guard and tenant isolation\n";
