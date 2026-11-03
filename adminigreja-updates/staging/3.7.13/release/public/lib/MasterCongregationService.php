<?php
declare(strict_types=1);
final class MasterCongregationService {
 public function __construct(private PDO $pdo,private Auth $auth,private string $actor){
  if(!$auth->isSuperAdmin()||$actor==='')throw new RuntimeException('Somente o Master pode transferir membros em massa.');
 }
 private function q(string $s,array $p=[]):PDOStatement {$x=$this->pdo->prepare($s);$x->execute($p);return $x;}
 public static function ensure(PDO $pdo):void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS master_congregation_moves (
    id CHAR(36) PRIMARY KEY,batch_id CHAR(36) NOT NULL,church_id CHAR(36) NOT NULL,
    member_id CHAR(36) NOT NULL,old_congregation_id CHAR(36) NULL,new_congregation_id CHAR(36) NOT NULL,
    created_by_user_id CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reverted_at DATETIME NULL,reverted_by_user_id CHAR(36) NULL,
    INDEX idx_bulk_congregation(church_id,batch_id),INDEX idx_bulk_member(church_id,member_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 public function churches():array {return $this->q('SELECT id,name FROM churches WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);}
 public function church(string $cid):array {
  $x=$this->q('SELECT id,name FROM churches WHERE id=? AND active=1',[$cid])->fetch(PDO::FETCH_ASSOC);
  if(!$x)throw new InvalidArgumentException('Igreja não encontrada.');return $x;
 }
 public function congregations(string $cid):array{
  $this->church($cid);
  return $this->q('SELECT id,name,active FROM congregations WHERE church_id=? ORDER BY active DESC,name',[$cid])->fetchAll(PDO::FETCH_ASSOC);
 }
 public function people(string $cid,string $term='',int $page=1):array{
  $this->church($cid);$term=trim($term);
  if(mb_strlen($term)>120)throw new InvalidArgumentException('Busca muito longa.');
  $where='m.church_id=?';$p=[$cid];
  if($term!==''){
   $where.=" AND (m.name LIKE ? ESCAPE '!' OR m.phone LIKE ? ESCAPE '!')";
   $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';$p[]=$like;$p[]=$like;
  }
  $total=(int)$this->q('SELECT COUNT(*) FROM members m WHERE '.$where,$p)->fetchColumn();
  $pages=max(1,(int)ceil($total/100));$page=min($pages,max(1,$page));
  $rows=$this->q('SELECT m.id,m.name,m.phone,m.congregation_id,
       COALESCE(c.name,\'Sede / Não informada\') congregation_name
       FROM members m LEFT JOIN congregations c ON c.church_id=m.church_id AND c.id=m.congregation_id
       WHERE '.$where.' ORDER BY m.name,m.id LIMIT 100 OFFSET '.(($page-1)*100),$p)->fetchAll(PDO::FETCH_ASSOC);
  $unassigned=(int)$this->q("SELECT COUNT(*) FROM members
      WHERE church_id=? AND (congregation_id IS NULL OR congregation_id='')",[$cid])->fetchColumn();
  return compact('rows','total','page','pages','unassigned');
 }
 private function audit(string $cid,string $name,array $data):void{
  $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)',[
     app_uuid(),$this->actor,$cid,$name,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
 }
 public function assign(string $cid,string $destId,string $mode,array $ids,string $churchName,string $congregationName):array{
  $church=$this->church($cid);
  $dest=$this->q('SELECT id,name FROM congregations WHERE church_id=? AND id=? AND active=1',
        [$cid,$destId])->fetch(PDO::FETCH_ASSOC);
  if(!$dest)throw new InvalidArgumentException('Congregação não encontrada na igreja.');
  if($church['name']!==trim($churchName)||$dest['name']!==trim($congregationName))
    throw new InvalidArgumentException('Digite exatamente a igreja e a congregação de destino.');
  if(!in_array($mode,['selected','unassigned'],true))throw new InvalidArgumentException('Modo inválido.');
  if($mode==='selected'){
    $ids=array_values(array_unique(array_map('strval',$ids)));
    if(!$ids||count($ids)>100)throw new InvalidArgumentException('Selecione 1 a 100 membros.');
    foreach($ids as $id)if(!preg_match('/^[a-f0-9-]{36}$/iD',$id))throw new InvalidArgumentException('Membro inválido.');
  }
  $this->pdo->beginTransaction();
  try{
   if($mode==='unassigned')
    $rows=$this->q("SELECT id,congregation_id FROM members WHERE church_id=? AND
        (congregation_id IS NULL OR congregation_id='') FOR UPDATE",[$cid])->fetchAll(PDO::FETCH_ASSOC);
   else{
    $bind=implode(',',array_fill(0,count($ids),'?'));
    $rows=$this->q("SELECT id,congregation_id FROM members WHERE church_id=?
        AND id IN ($bind) FOR UPDATE",[$cid,...$ids])->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==count($ids))throw new RuntimeException('Um membro não pertence a esta igreja.');
   }
   if(count($rows)>5000)throw new RuntimeException('Limite de 5.000 cadastros por ação.');
   $batch=app_uuid();$moved=0;
   foreach($rows as $row){
    if($row['congregation_id']===$destId)continue;
    $this->q('UPDATE members SET congregation_id=? WHERE church_id=? AND id=?',[$destId,$cid,$row['id']]);
    $this->q('INSERT INTO master_congregation_moves
      (id,batch_id,church_id,member_id,old_congregation_id,new_congregation_id,created_by_user_id)
      VALUES(?,?,?,?,?,?,?)',[app_uuid(),$batch,$cid,$row['id'],$row['congregation_id']?:null,$destId,$this->actor]);
    $moved++;
   }
   $this->audit($cid,'master_congregation_bulk_assigned',
     ['batch_id'=>$batch,'moved'=>$moved,'mode'=>$mode,'destination'=>$destId]);
   $this->pdo->commit();
   return ['batch'=>$batch,'moved'=>$moved,'congregation'=>$dest['name']];
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
 }
 public function batches(string $cid):array{
  $this->church($cid);
  return $this->q('SELECT x.batch_id,COUNT(*) moved,MIN(x.created_at) created_at,
    MAX(c.name) destination_name,SUM(x.reverted_at IS NOT NULL) reverted
    FROM master_congregation_moves x JOIN congregations c ON c.id=x.new_congregation_id
    AND c.church_id=x.church_id WHERE x.church_id=?
    GROUP BY x.batch_id ORDER BY created_at DESC LIMIT 12',[$cid])->fetchAll(PDO::FETCH_ASSOC);
 }
 public function undo(string $cid,string $batch):int {
  $this->church($cid);$this->pdo->beginTransaction();
  try{
   $rows=$this->q('SELECT * FROM master_congregation_moves WHERE church_id=?
      AND batch_id=? AND reverted_at IS NULL FOR UPDATE',[$cid,$batch])->fetchAll(PDO::FETCH_ASSOC);
   if(!$rows)throw new RuntimeException('Lote não encontrado ou já revertido.');
   $n=0;
   foreach($rows as $r){
    $current=$this->q('SELECT congregation_id FROM members WHERE church_id=? AND id=? FOR UPDATE',
       [$cid,$r['member_id']])->fetch(PDO::FETCH_ASSOC);
    if(!$current||$current['congregation_id']!==$r['new_congregation_id'])continue;
    $this->q('UPDATE members SET congregation_id=? WHERE church_id=? AND id=?',
       [$r['old_congregation_id']?:null,$cid,$r['member_id']]);
    $this->q('UPDATE master_congregation_moves SET reverted_at=NOW(),reverted_by_user_id=?
       WHERE church_id=? AND id=?',[$this->actor,$cid,$r['id']]);$n++;
   }
   $this->audit($cid,'master_congregation_bulk_undone',['batch_id'=>$batch,'restored'=>$n]);
   $this->pdo->commit();return $n;
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
 }
}
