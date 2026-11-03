<?php
declare(strict_types=1);
/** Explicit staff activation: one member -> one church membership -> private portal. */
final class MemberPortalAccessService {
 public function __construct(private PDO $pdo,private Auth $auth,private string $cid,private string $actor){
  if(!$auth->can('manage_users')||($auth->currentChurch()['id']??'')!==$cid)
    throw new RuntimeException('Somente gestores autorizados podem criar acessos de membros.');
 }
 private function q(string $sql,array $args=[]):PDOStatement{
  $s=$this->pdo->prepare($sql);$s->execute($args);return $s;
 }
 public function member(string $id):array {
  $x=$this->q('SELECT id,name,email,phone,active FROM members WHERE church_id=? AND id=?',[$this->cid,$id])
     ->fetch(PDO::FETCH_ASSOC);
  if(!$x)throw new InvalidArgumentException('Pessoa não encontrada na igreja.');
  return $x;
 }
 public function access(string $id):?array {
  $this->member($id);
  $x=$this->q('SELECT cm.id,cm.church_role,u.email,u.active FROM church_members cm
    JOIN users u ON u.id=cm.user_id WHERE cm.church_id=? AND cm.person_id=? LIMIT 1',
    [$this->cid,$id])->fetch(PDO::FETCH_ASSOC);
  return $x?:null;
 }
 public function activate(string $memberId,string $email,string $newPassword):array {
  $member=$this->member($memberId);
  if(!$member['active'])throw new InvalidArgumentException('Ative o cadastro antes de criar acesso.');
  $email=mb_strtolower(trim($email));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Informe e-mail válido do membro.');
  $stored=mb_strtolower(trim((string)($member['email']??'')));
  if($stored!==''&&$stored!==$email)
    throw new InvalidArgumentException('O e-mail precisa ser igual ao cadastrado. Corrija o e-mail do membro antes de ativar.');
  $this->pdo->beginTransaction();
  try{
   $existing=$this->q('SELECT cm.user_id,u.email FROM church_members cm JOIN users u ON u.id=cm.user_id
       WHERE cm.church_id=? AND cm.person_id=? FOR UPDATE',[$this->cid,$memberId])->fetch(PDO::FETCH_ASSOC);
   if($existing)throw new RuntimeException('Este membro já tem acesso. Use Usuários e permissões para gerenciar.');
   $church=$this->q('SELECT max_users FROM churches WHERE id=?',[$this->cid])->fetch(PDO::FETCH_ASSOC);
   if(!$church)throw new RuntimeException('Igreja não encontrada.');
   if($church['max_users']!==null){
    $total=(int)$this->q('SELECT COUNT(*) FROM church_members WHERE church_id=?',[$this->cid])->fetchColumn();
    if($total>=(int)$church['max_users'])throw new RuntimeException('Limite de acessos do plano atingido.');
   }
   $user=$this->q('SELECT id,active FROM users WHERE email=? LIMIT 1',[$email])->fetch(PDO::FETCH_ASSOC);
   if($user){
    if(!$user['active'])throw new RuntimeException('Este e-mail corresponde a uma conta desativada.');
    $uid=(string)$user['id'];
    if($this->auth->isSuperAdmin($uid))throw new RuntimeException('A conta Master não pode ser vinculada como membro.');
    if($this->q('SELECT 1 FROM church_members WHERE church_id=? AND user_id=?',[$this->cid,$uid])->fetchColumn())
       throw new RuntimeException('Este e-mail já possui outro acesso nesta igreja. Confira Usuários e Permissões.');
    $created=false;
   }else{
    if(strlen($newPassword)<12)throw new InvalidArgumentException('Para novo usuário, use senha inicial com pelo menos 12 caracteres.');
    $uid=app_uuid();$created=true;
    $this->q('INSERT INTO users(id,email,password_hash,full_name,active) VALUES(?,?,?,?,1)',
      [$uid,$email,password_hash($newPassword,PASSWORD_DEFAULT),$member['name']]);
    $this->q('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)',
      [app_uuid(),$uid,$member['name']]);
   }
   $this->q("INSERT INTO church_members(id,church_id,user_id,person_id,is_owner,church_role,permissions)
     VALUES(?,?,?,?,0,'member',NULL)",[app_uuid(),$this->cid,$uid,$memberId]);
   if($stored==='')$this->q('UPDATE members SET email=? WHERE church_id=? AND id=?',[$email,$this->cid,$memberId]);
   $this->q('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details)
      VALUES(?,?,?,?,?)',[app_uuid(),$this->actor,$this->cid,'member_portal_access_created',
      json_encode(['member_id'=>$memberId,'existing_login'=>!$created],JSON_THROW_ON_ERROR)]);
   $this->pdo->commit();
   return ['email'=>$email,'new_user'=>$created];
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
 }
}
