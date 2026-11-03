<?php
declare(strict_types=1);

/** Authorization from explicit roles within each department, never from mere membership. */
final class DepartmentRoleAccess
{
    private static function query(PDO $pdo,string $sql,array $args):PDOStatement {
        $q=$pdo->prepare($sql);$q->execute($args);return $q;
    }

    public static function hasFinancialScope(PDO $pdo,string $churchId,string $userId,?string $departmentId=null):bool
    {
        if($churchId===''||$userId==='')return false;
        $sql="SELECT 1 FROM church_members cm
            INNER JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id AND m.active=1
            INNER JOIN member_department_roles r ON r.member_id=m.id AND r.church_id=m.church_id
               AND r.role_name IN ('leader','treasurer')
            INNER JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id AND d.active=1
            WHERE cm.church_id=? AND cm.user_id=?";
        $args=[$churchId,$userId];
        if($departmentId!==null){$sql.=' AND r.department_id=?';$args[]=$departmentId;}
        $sql.=' LIMIT 1';
        try{return (bool)self::query($pdo,$sql,$args)->fetchColumn();}
        catch(PDOException $e){
            // If the new table hasn't been installed yet, fail closed.
            error_log('Department scope unavailable: '.$e->getCode());return false;
        }
    }

    public static function isLeader(PDO $pdo,string $churchId,string $userId,?string $departmentId=null):bool
    {
        if($churchId===''||$userId==='')return false;
        $sql="SELECT 1 FROM church_members cm
            INNER JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id AND m.active=1
            INNER JOIN member_department_roles r ON r.member_id=m.id AND r.church_id=m.church_id AND r.role_name='leader'
            INNER JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id AND d.active=1
            WHERE cm.church_id=? AND cm.user_id=?";
        $args=[$churchId,$userId];
        if($departmentId!==null){$sql.=' AND r.department_id=?';$args[]=$departmentId;}
        $sql.=' LIMIT 1';
        try{return (bool)self::query($pdo,$sql,$args)->fetchColumn();}
        catch(PDOException $e){error_log('Department leadership unavailable: '.$e->getCode());return false;}
    }

    public static function listFinancialDepartments(PDO $pdo,string $churchId,string $userId):array
    {
        return self::query($pdo,
            "SELECT DISTINCT d.id,d.name,d.active
             FROM church_members cm
             JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id AND m.active=1
             JOIN member_department_roles r ON r.member_id=m.id AND r.church_id=m.church_id
               AND r.role_name IN ('leader','treasurer')
             JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id AND d.active=1
             WHERE cm.church_id=? AND cm.user_id=? ORDER BY d.name",
            [$churchId,$userId])->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listLedDepartments(PDO $pdo,string $churchId,string $userId):array
    {
        return self::query($pdo,
            "SELECT DISTINCT d.id,d.name,d.active
             FROM church_members cm
             JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id AND m.active=1
             JOIN member_department_roles r ON r.member_id=m.id AND r.church_id=m.church_id AND r.role_name='leader'
             JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id AND d.active=1
             WHERE cm.church_id=? AND cm.user_id=? ORDER BY d.name",
            [$churchId,$userId])->fetchAll(PDO::FETCH_ASSOC);
    }
}
