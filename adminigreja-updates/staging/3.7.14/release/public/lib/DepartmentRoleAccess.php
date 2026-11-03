<?php
declare(strict_types=1);

/** Authorization from explicit roles within each department, never from mere membership. */
final class DepartmentRoleAccess
{
    private static function query(PDO $pdo,string $sql,array $args):PDOStatement {
        $q=$pdo->prepare($sql);$q->execute($args);return $q;
    }

    /**
     * Compatibility with already-authorized old department_leader logins which
     * have no linked personal member. Once a personal member is linked, the
     * explicit member_department_roles assignment becomes mandatory.
     */
    private static function legacy(PDO $pdo,string $churchId,string $userId,?string $departmentId=null):bool
    {
        $sql="SELECT 1 FROM church_members cm
             JOIN department_access x ON x.church_id=cm.church_id AND x.user_id=cm.user_id
             JOIN ministry_departments d ON d.church_id=x.church_id AND d.id=x.department_id AND d.active=1
             WHERE cm.church_id=? AND cm.user_id=? AND cm.person_id IS NULL
               AND cm.church_role='department_leader'";
        $params=[$churchId,$userId];
        if($departmentId!==null){$sql.=' AND x.department_id=?';$params[]=$departmentId;}
        return (bool)self::query($pdo,$sql.' LIMIT 1',$params)->fetchColumn();
    }

    private static function legacyList(PDO $pdo,string $churchId,string $userId):array
    {
        return self::query($pdo,"SELECT d.id,d.name,d.active FROM church_members cm
           JOIN department_access x ON x.church_id=cm.church_id AND x.user_id=cm.user_id
           JOIN ministry_departments d ON d.church_id=x.church_id AND d.id=x.department_id AND d.active=1
           WHERE cm.church_id=? AND cm.user_id=? AND cm.person_id IS NULL
             AND cm.church_role='department_leader' ORDER BY d.name",
           [$churchId,$userId])->fetchAll(PDO::FETCH_ASSOC);
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
        try{
            if(self::query($pdo,$sql,$args)->fetchColumn())return true;
            return self::legacy($pdo,$churchId,$userId,$departmentId);
        }catch(PDOException $e){
            error_log('Department scope unavailable: '.$e->getCode());
            try{return self::legacy($pdo,$churchId,$userId,$departmentId);}
            catch(PDOException $ignored){return false;}
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
        try{
            if(self::query($pdo,$sql,$args)->fetchColumn())return true;
            return self::legacy($pdo,$churchId,$userId,$departmentId);
        }catch(PDOException $e){
            error_log('Department leadership unavailable: '.$e->getCode());
            try{return self::legacy($pdo,$churchId,$userId,$departmentId);}
            catch(PDOException $ignored){return false;}
        }
    }

    public static function listFinancialDepartments(PDO $pdo,string $churchId,string $userId):array
    {
        try{
            $rows=self::query($pdo,
                "SELECT DISTINCT d.id,d.name,d.active FROM church_members cm
                 JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id AND m.active=1
                 JOIN member_department_roles r ON r.member_id=m.id AND r.church_id=m.church_id
                      AND r.role_name IN ('leader','treasurer')
                 JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id AND d.active=1
                 WHERE cm.church_id=? AND cm.user_id=? ORDER BY d.name",
                [$churchId,$userId])->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $e){$rows=[];}
        foreach(self::legacyList($pdo,$churchId,$userId) as $row)$rows[]=$row;
        $unique=[];foreach($rows as $row)$unique[$row['id']]=$row;
        uasort($unique,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        return array_values($unique);
    }

    public static function listLedDepartments(PDO $pdo,string $churchId,string $userId):array
    {
        try{
            $rows=self::query($pdo,
                "SELECT DISTINCT d.id,d.name,d.active FROM church_members cm
                 JOIN members m ON m.id=cm.person_id AND m.church_id=cm.church_id AND m.active=1
                 JOIN member_department_roles r ON r.member_id=m.id AND r.church_id=m.church_id
                      AND r.role_name='leader'
                 JOIN ministry_departments d ON d.id=r.department_id AND d.church_id=r.church_id AND d.active=1
                 WHERE cm.church_id=? AND cm.user_id=? ORDER BY d.name",
                [$churchId,$userId])->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $e){$rows=[];}
        foreach(self::legacyList($pdo,$churchId,$userId) as $row)$rows[]=$row;
        $unique=[];foreach($rows as $row)$unique[$row['id']]=$row;
        uasort($unique,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        return array_values($unique);
    }

}
