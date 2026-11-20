<?php
declare(strict_types=1);
$db=new PDO(
    'mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','mastertest',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$db->exec('CREATE DATABASE IF NOT EXISTS im_finance_mobile_3778 CHARACTER SET utf8mb4');
$db->exec('USE im_finance_mobile_3778');
$db->exec('DROP TABLE IF EXISTS finance_documents');
$db->exec('DROP TABLE IF EXISTS finance_entries');
$db->exec('CREATE TABLE finance_entries(id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,description VARCHAR(200))');
$db->exec('CREATE TABLE finance_documents(id CHAR(36) PRIMARY KEY,church_id CHAR(36) NOT NULL,entry_id CHAR(36),document_type VARCHAR(30),original_name VARCHAR(190),created_at DATETIME)');
function uid(int $number):string {return sprintf('12345678-0000-4000-8000-%012d',$number);}
function insist(bool $v,string $reason):void {
    if(!$v)throw new RuntimeException('MOBILE DOCUMENT PRIVACY FAILED: '.$reason);
}
$churchA=uid(1);$churchB=uid(2);
$entryA=uid(10);$entryA2=uid(11);$entryB=uid(12);
$entry=$db->prepare('INSERT INTO finance_entries VALUES (?,?,?)');
$entry->execute([$entryA,$churchA,'Compra da igreja A']);
$entry->execute([$entryA2,$churchA,'Sem anexo']);
$entry->execute([$entryB,$churchB,'Compra da igreja B']);
$attachment=$db->prepare('INSERT INTO finance_documents VALUES(?,?,?,?,?,?)');
$attachment->execute([uid(21),$churchA,$entryA,'invoice','nota 01.pdf','2026-11-19 10:00:00']);
$attachment->execute([uid(22),$churchA,$entryA,'proof','comprovante pix.png','2026-11-20 11:00:00']);
$attachment->execute([uid(23),$churchB,$entryB,'proof','outro templo.pdf','2026-11-20 12:00:00']);
// Even if a bad/old import links a document from church B to A's entry ID,
// the view must NEVER show its name or link in church A.
$attachment->execute([uid(24),$churchB,$entryA,'invoice','documento da outra igreja.pdf','2026-11-20 13:00:00']);
$entryIds=[$entryA,$entryA2];
$holders=implode(',',array_fill(0,count($entryIds),'?'));
$q=$db->prepare("SELECT id,entry_id,document_type,original_name,created_at
    FROM finance_documents WHERE church_id=? AND entry_id IN ($holders)
    ORDER BY created_at DESC,id DESC");
$q->execute(array_merge([$churchA],$entryIds));
$byEntry=[];
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $doc)
    $byEntry[$doc['entry_id']][]=$doc;
insist(count($byEntry[$entryA])===2,'two documents associated to one entry');
insist($byEntry[$entryA][0]['original_name']==='comprovante pix.png',
    'newest document must be primary mobile preview');
insist($byEntry[$entryA][1]['original_name']==='nota 01.pdf',
    'older document must remain viewable via list');
insist(empty($byEntry[$entryA2]),'entries without docs must have no preview link');
foreach($byEntry as $docs)foreach($docs as $doc){
    insist($doc['original_name']!=='documento da outra igreja.pdf',
        'cross-tenant document name leaked to current church');
    insist($doc['original_name']!=='outro templo.pdf','foreign document was leaked');
}
// Ensure a later church B query retrieves only B's own documents.
$q=$db->prepare('SELECT id FROM finance_documents WHERE church_id=? AND entry_id=?');
$q->execute([$churchB,$entryB]);
insist(count($q->fetchAll())===1,'second church has its own document accessible');
echo "PASS MySQL: 2 documents for one entry, 0 for another, newest first, cross-church docs are never indexed into current church.\n";
