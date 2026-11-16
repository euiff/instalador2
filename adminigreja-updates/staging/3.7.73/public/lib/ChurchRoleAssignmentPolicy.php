<?php
declare(strict_types=1);

/**
 * Master is a PLATFORM identity (user_roles.super_admin), never a selectable
 * church role. Churches can only delegate Pastor and lower responsibilities.
 * Old church "admin" accounts remain for Master-led review, but that legacy
 * label cannot be newly created or re-granted to a church user.
 */
final class ChurchRoleAssignmentPolicy
{
    public const CHURCH_ROLES = [
        'pastor','secretary','treasurer','accountant',
        'department_leader','group_leader','teacher','kids_worker','member',
    ];

    public static function assignable(string $actorRole,bool $isMaster): array
    {
        return ($isMaster || in_array($actorRole,['owner','pastor'],true))
            ? self::CHURCH_ROLES : [];
    }

    public static function assertAssignable(string $targetRole,string $actorRole,bool $isMaster): void
    {
        if(!in_array($targetRole,self::assignable($actorRole,$isMaster),true))
            throw new RuntimeException('Este perfil não pode ser concedido. Administrador é exclusivo do Master da plataforma; a igreja pode designar Pastor e cargos inferiores.');
    }

    public static function mayManageExisting(
        string $actorRole,bool $isMaster,string $existingRole,
        bool $targetIsOwner,bool $targetIsSelf
    ): bool {
        if($targetIsOwner)return false;
        if($targetIsSelf&&!$isMaster)return false;
        if($isMaster)return true; // Master can correct legacy church-admin roles.
        if(!in_array($actorRole,['owner','pastor'],true))return false;
        return in_array($existingRole,self::CHURCH_ROLES,true);
    }
}
