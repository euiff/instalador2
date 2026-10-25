<?php

declare(strict_types=1);

final class LegacyDataMigrator
{
    public function __construct(private PDO $pdo) {}

    public function migrate(array $renamedTables): array
    {
        $stats = [];
        $churchTable = $renamedTables['churches'] ?? null;
        $userTable = $renamedTables['users'] ?? null;
        $memberTable = $renamedTables['members'] ?? null;
        $eventTable = $renamedTables['events'] ?? null;

        if ($churchTable && $this->tableExists($churchTable)) $stats['churches'] = $this->migrateChurches($churchTable);
        if ($userTable && $this->tableExists($userTable)) $stats['users'] = $this->migrateUsers($userTable);
        if ($memberTable && $this->tableExists($memberTable)) $stats['members'] = $this->migrateMembers($memberTable);
        if ($eventTable && $this->tableExists($eventTable)) $stats['events'] = $this->migrateEvents($eventTable);
        if ($this->hasColumns('accounts',['id','church_id','name','account_type','opening_balance','active','created_at']))
            $stats['finance_accounts'] = $this->migrateAccounts();
        if ($this->hasColumns('transactions',['id','church_id','account_id','member_id','direction','income_kind','category','description','amount','transaction_date','status','created_by','receipt_path','receipt_name','receipt_mime','receipt_size','created_at']))
            $stats['finance_entries'] = $this->migrateEntries();
        if ($this->hasColumns('payables',['id','church_id','supplier','description','amount','due_date','status','paid_at','created_at']))
            $stats['finance_payables'] = $this->migratePayables();

        return $stats;
    }

    private function migrateChurches(string $table): int
    {
        $t = $this->id($table);
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'church',CAST(id AS CHAR),UUID() FROM {$t}");

        return $this->pdo->exec("INSERT IGNORE INTO churches(
            id,name,slug,phone,email,plan,monthly_fee,legacy_due_date,subscription_status,subscription_ends_at,max_users,active,ai_enabled,created_at
        )
        SELECT m.new_id,l.name,CONCAT('igreja-migrada-',l.id),NULLIF(l.phone,''),NULLIF(l.email,''),
            CASE
                WHEN LOWER(l.plan) LIKE '%enterprise%' OR LOWER(l.plan) LIKE '%premium%' THEN 'enterprise'
                WHEN LOWER(l.plan) LIKE '%pro%' THEN 'pro'
                WHEN LOWER(l.plan) LIKE '%free%' OR LOWER(l.plan) LIKE '%grat%' THEN 'free'
                ELSE 'basic'
            END,
            COALESCE(l.monthly_fee,0),l.due_date,
            CASE WHEN l.status='suspended' THEN 'suspended' ELSE 'active' END,
            CASE WHEN l.due_date IS NULL THEN NULL ELSE CONCAT(l.due_date,' 23:59:59') END,
            l.max_users,
            CASE WHEN l.status='suspended' THEN 0 ELSE 1 END,
            1,
            COALESCE(l.created_at,NOW())
        FROM {$t} l
        INNER JOIN legacy_entity_map m ON m.entity_type='church' AND m.legacy_id=CAST(l.id AS CHAR)");
    }

    private function migrateUsers(string $table): int
    {
        $t = $this->id($table);
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'user',CAST(id AS CHAR),UUID() FROM {$t}");

        $count = $this->pdo->exec("INSERT IGNORE INTO users(id,email,password_hash,full_name,active,created_at)
            SELECT m.new_id,LOWER(TRIM(l.email)),l.password_hash,l.name,l.active,COALESCE(l.created_at,NOW())
            FROM {$t} l
            INNER JOIN legacy_entity_map m ON m.entity_type='user' AND m.legacy_id=CAST(l.id AS CHAR)
            WHERE l.email IS NOT NULL AND TRIM(l.email)<>''");

        $this->pdo->exec("INSERT IGNORE INTO profiles(id,user_id,full_name,created_at)
            SELECT UUID(),m.new_id,l.name,COALESCE(l.created_at,NOW())
            FROM {$t} l
            INNER JOIN legacy_entity_map m ON m.entity_type='user' AND m.legacy_id=CAST(l.id AS CHAR)
            INNER JOIN users u ON u.id=m.new_id");

        $this->pdo->exec("INSERT IGNORE INTO user_roles(id,user_id,role)
            SELECT UUID(),m.new_id,'admin'
            FROM {$t} l
            INNER JOIN legacy_entity_map m ON m.entity_type='user' AND m.legacy_id=CAST(l.id AS CHAR)
            INNER JOIN users u ON u.id=m.new_id");

        $this->pdo->exec("INSERT IGNORE INTO user_roles(id,user_id,role)
            SELECT UUID(),m.new_id,'super_admin'
            FROM {$t} l
            INNER JOIN legacy_entity_map m ON m.entity_type='user' AND m.legacy_id=CAST(l.id AS CHAR)
            INNER JOIN users u ON u.id=m.new_id
            WHERE l.role='master' AND (
                l.password_hash LIKE '\$2y\$%' OR l.password_hash LIKE '\$2a\$%' OR
                l.password_hash LIKE '\$argon2i\$%' OR l.password_hash LIKE '\$argon2id\$%'
            )");

        $this->pdo->exec("INSERT IGNORE INTO church_members(id,church_id,user_id,is_owner,church_role,created_at)
            SELECT UUID(),
                COALESCE(cm.new_id,(SELECT new_id FROM legacy_entity_map WHERE entity_type='church' ORDER BY id LIMIT 1)),
                um.new_id,
                CASE WHEN l.role='master' THEN 1 ELSE 0 END,
                CASE l.role
                    WHEN 'master' THEN 'owner'
                    WHEN 'pastor' THEN 'pastor'
                    WHEN 'secretaria' THEN 'secretary'
                    WHEN 'tesouraria' THEN 'treasurer'
                    ELSE 'admin'
                END,
                COALESCE(l.created_at,NOW())
            FROM {$t} l
            INNER JOIN legacy_entity_map um ON um.entity_type='user' AND um.legacy_id=CAST(l.id AS CHAR)
            INNER JOIN users u ON u.id=um.new_id
            LEFT JOIN legacy_entity_map cm ON cm.entity_type='church' AND cm.legacy_id=CAST(l.church_id AS CHAR)
            WHERE COALESCE(cm.new_id,(SELECT new_id FROM legacy_entity_map WHERE entity_type='church' ORDER BY id LIMIT 1)) IS NOT NULL");

        return $count;
    }

    private function migrateMembers(string $table): int
    {
        $t = $this->id($table);
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'member',CAST(id AS CHAR),UUID() FROM {$t}");
        return $this->pdo->exec("INSERT IGNORE INTO members(
            id,church_id,name,phone,email,birth_date,registration_status,registration_step,active,created_at
        )
        SELECT mm.new_id,cm.new_id,l.name,COALESCE(l.phone,''),NULLIF(l.email,''),l.birth_date,
            'complete','legacy_import',CASE WHEN l.status='active' THEN 1 ELSE 0 END,COALESCE(l.created_at,NOW())
        FROM {$t} l
        INNER JOIN legacy_entity_map mm ON mm.entity_type='member' AND mm.legacy_id=CAST(l.id AS CHAR)
        INNER JOIN legacy_entity_map cm ON cm.entity_type='church' AND cm.legacy_id=CAST(l.church_id AS CHAR)");
    }

    private function migrateEvents(string $table): int
    {
        $t = $this->id($table);
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'event',CAST(id AS CHAR),UUID() FROM {$t}");
        return $this->pdo->exec("INSERT IGNORE INTO events(
            id,church_id,title,description,event_type,event_date,attendance_count,active,created_at
        )
        SELECT em.new_id,cm.new_id,l.title,NULLIF(l.notes,''),'legacy',l.event_date,COALESCE(l.attendance,0),1,COALESCE(l.created_at,NOW())
        FROM {$t} l
        INNER JOIN legacy_entity_map em ON em.entity_type='event' AND em.legacy_id=CAST(l.id AS CHAR)
        INNER JOIN legacy_entity_map cm ON cm.entity_type='church' AND cm.legacy_id=CAST(l.church_id AS CHAR)");
    }

    private function migrateAccounts(): int
    {
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'finance_account',CAST(id AS CHAR),UUID() FROM accounts");
        return $this->pdo->exec("INSERT IGNORE INTO finance_accounts(
            id,church_id,name,account_type,opening_balance,active,legacy_id,created_at
        )
        SELECT am.new_id,cm.new_id,a.name,a.account_type,a.opening_balance,a.active,a.id,COALESCE(a.created_at,NOW())
        FROM accounts a
        INNER JOIN legacy_entity_map am ON am.entity_type='finance_account' AND am.legacy_id=CAST(a.id AS CHAR)
        INNER JOIN legacy_entity_map cm ON cm.entity_type='church' AND cm.legacy_id=CAST(a.church_id AS CHAR)");
    }

    private function migrateEntries(): int
    {
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'finance_entry',CAST(id AS CHAR),UUID() FROM transactions");
        return $this->pdo->exec("INSERT IGNORE INTO finance_entries(
            id,church_id,account_id,member_id,direction,income_kind,category,description,amount,transaction_date,status,
            receipt_path,receipt_name,receipt_mime,receipt_size,source,legacy_id,created_by_user_id,created_at
        )
        SELECT tm.new_id,cm.new_id,am.new_id,mm.new_id,t.direction,t.income_kind,t.category,t.description,t.amount,t.transaction_date,t.status,
            t.receipt_path,t.receipt_name,t.receipt_mime,t.receipt_size,'legacy',t.id,um.new_id,COALESCE(t.created_at,NOW())
        FROM transactions t
        INNER JOIN legacy_entity_map tm ON tm.entity_type='finance_entry' AND tm.legacy_id=CAST(t.id AS CHAR)
        INNER JOIN legacy_entity_map cm ON cm.entity_type='church' AND cm.legacy_id=CAST(t.church_id AS CHAR)
        LEFT JOIN legacy_entity_map am ON am.entity_type='finance_account' AND am.legacy_id=CAST(t.account_id AS CHAR)
        LEFT JOIN legacy_entity_map mm ON mm.entity_type='member' AND mm.legacy_id=CAST(t.member_id AS CHAR)
        LEFT JOIN legacy_entity_map um ON um.entity_type='user' AND um.legacy_id=CAST(t.created_by AS CHAR)");
    }

    private function migratePayables(): int
    {
        $this->pdo->exec("INSERT IGNORE INTO legacy_entity_map(entity_type,legacy_id,new_id)
            SELECT 'finance_payable',CAST(id AS CHAR),UUID() FROM payables");
        return $this->pdo->exec("INSERT IGNORE INTO finance_payables(
            id,church_id,supplier,description,amount,due_date,status,paid_at,legacy_id,created_at
        )
        SELECT pm.new_id,cm.new_id,p.supplier,p.description,p.amount,p.due_date,p.status,p.paid_at,p.id,COALESCE(p.created_at,NOW())
        FROM payables p
        INNER JOIN legacy_entity_map pm ON pm.entity_type='finance_payable' AND pm.legacy_id=CAST(p.id AS CHAR)
        INNER JOIN legacy_entity_map cm ON cm.entity_type='church' AND cm.legacy_id=CAST(p.church_id AS CHAR)");
    }

    private function hasColumns(string $table,array $required): bool
    {
        if(!$this->tableExists($table))return false;
        $q=$this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        $columns=array_map('strtolower',array_column($q->fetchAll(PDO::FETCH_ASSOC),'column_name'));
        foreach($required as $column){
            if(!in_array(strtolower($column),$columns,true))return false;
        }
        return true;
    }

    private function tableExists(string $table): bool
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn()>0;
    }

    private function id(string $identifier): string
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$identifier)) throw new InvalidArgumentException('Identificador SQL inválido.');
        return chr(96).$identifier.chr(96);
    }
}
