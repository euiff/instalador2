<?php
declare(strict_types=1);

/**
 * A church login belongs to a global user account; role assignment must
 * never silently replace the password of a pre-existing account.
 */
final class ChurchStaffCredentialService
{
    public static function preventIgnoredPassword(bool $existing,string $newPassword): void
    {
        if($existing && $newPassword!=='')
            throw new RuntimeException(
                'Este e-mail já possui login. A senha informada não foi gravada: '.
                'para manter a conta existente, deixe o campo Senha inicial vazio '.
                'e depois use Redefinir senha em Usuários e permissões.'
            );
    }

    /** Change ONLY the password of a member already linked to this church.
     * A global login shared with another church cannot be reset by one
     * tenant's staff manager.
     */
    public function __construct(private PDO $pdo) {}

    public function reset(
        Auth $auth,string $churchId,string $membershipId,string $actorId,
        string $newPassword,string $confirmPassword,bool $acknowledged
    ): string {
        $church=$auth->currentChurch();
        if(!$auth->can('manage_users')||!$church||
            (string)$church['id']!==$churchId||
            (string)$auth->requireLogin()['id']!==$actorId)
            throw new RuntimeException('Você não tem autorização para redefinir a senha desta igreja.');
        if(!$acknowledged)throw new InvalidArgumentException('Confirme que deseja redefinir a senha desta pessoa.');
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$membershipId))
            throw new InvalidArgumentException('Escolha um acesso válido da igreja.');
        if(strlen($newPassword)<8)
            throw new InvalidArgumentException('Defina uma nova senha com pelo menos 8 caracteres.');
        if(!hash_equals($newPassword,$confirmPassword))
            throw new InvalidArgumentException('A confirmação da senha é diferente. Digite a mesma senha nos dois campos.');
        if(strlen($newPassword)>1024)
            throw new InvalidArgumentException('Senha longa demais.');

        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare(
                'SELECT cm.user_id,cm.church_role,cm.is_owner,cm.person_id,'.
                'u.email,u.active FROM church_members cm '.
                'JOIN users u ON u.id=cm.user_id '.
                'WHERE cm.id=? AND cm.church_id=? LIMIT 1 FOR UPDATE'
            );
            $q->execute([$membershipId,$churchId]);
            $membership=$q->fetch(PDO::FETCH_ASSOC);
            if(!$membership || !(int)$membership['active'])
                throw new RuntimeException('Login não encontrado ou desativado nesta igreja.');
            $targetId=(string)$membership['user_id'];
            if(!empty($membership['is_owner']) || $targetId===$actorId)
                throw new RuntimeException('A senha do responsável ou da própria conta não pode ser redefinida por esta função.');
            if($auth->isSuperAdmin($targetId))
                throw new RuntimeException('A senha do administrador Master não pode ser redefinida aqui.');

            $isMaster=$auth->isSuperAdmin();
            $actorRole=$auth->churchRole();
            $allowed=($isMaster||$actorRole==='owner')
                ?['admin','pastor','secretary','treasurer','accountant','department_leader','group_leader','teacher','kids_worker','member']
                :['secretary','treasurer','accountant','department_leader','group_leader','teacher','kids_worker','member'];
            if(!in_array((string)$membership['church_role'],$allowed,true))
                throw new RuntimeException('Você não tem autorização para redefinir a senha desse perfil.');

            // A global account shared across tenants must not have its
            // password changed by one church manager without consent.
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM church_members WHERE user_id=? AND church_id<>?');
            $q->execute([$targetId,$churchId]);
            if((int)$q->fetchColumn()>0)
                throw new RuntimeException(
                    'Este login também é usado em outra igreja. Não é seguro alterar '.
                    'a senha global aqui. Utilize a recuperação individual da conta.'
                );

            $hash=password_hash($newPassword,PASSWORD_DEFAULT);
            if($hash===false||!password_verify($newPassword,$hash))
                throw new RuntimeException('Não foi possível preparar a nova senha.');
            $this->pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND active=1')
                ->execute([$hash,$targetId]);
            // No plaintext password, hash or temporary credential in audit.
            $this->pdo->prepare(
                'INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)'
            )->execute([
                app_uuid(),$actorId,$churchId,'church_staff_password_reset',
                json_encode([
                    'target_user_id'=>$targetId,
                    'member_id'=>$membership['person_id'],
                    'church_role'=>$membership['church_role']
                ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
            ]);
            $this->pdo->commit();
            return (string)$membership['email'];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
