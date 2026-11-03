<?php
declare(strict_types=1);
/**
 * Self-contained database checks for the staging SQL/service.
 * Run only against a disposable test database with migration 040 applied.
 * Environment: TEST_PASTORAL_DSN, TEST_PASTORAL_USER, TEST_PASTORAL_PASSWORD.
 */
require dirname(__DIR__).'/lib/PastoralCareService.php';
require dirname(__DIR__).'/lib/PastoralCareQuery.php';

$dsn=getenv('TEST_PASTORAL_DSN') ?: '';
if ($dsn==='' || stripos($dsn,'test')===false) {
    fwrite(STDERR,"Set TEST_PASTORAL_DSN to a disposable database containing 'test' in its name.\n");
    exit(2);
}
$pdo=new PDO($dsn,getenv('TEST_PASTORAL_USER') ?: '',getenv('TEST_PASTORAL_PASSWORD') ?: '',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$svc=new PastoralCareService($pdo);$q=new PastoralCareQuery($pdo);
$churchA=901001;$churchB=901002;$actor=901003;
$pdo->beginTransaction();
try {
    $id=$svc->createCase($churchA,$actor,'Teste isolado');
    assert($id>0);
    assert(count($q->listing($churchA,null,'','Teste isolado')['rows'])>=1);
    assert($q->listing($churchB,null,'','Teste isolado')['total']===0);
    assert($svc->recordEvent($churchB,$actor,$id,'note','Vazamento')===false);
    assert($svc->recordEvent($churchA,$actor,$id,'note','Confidencial',true)===true);
    assert(count($q->caseEvents($churchA,$id,false))===0);
    assert(count($q->caseEvents($churchB,$id,true))===0);
    assert(count($q->caseEvents($churchA,$id,true))===1);
    assert($svc->schedule($churchB,$actor,$id,'2026-11-15 12:00:00')===false);
    assert($svc->schedule($churchA,$actor,$id,'2026-11-15 12:00:00')===true);
    echo "PASS: multi-tenant case access, confidential notes and scheduling\n";
} finally {
    $pdo->rollBack();
}
