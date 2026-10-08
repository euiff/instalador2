<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/lib/LegacyDataMigrator.php';

/**
 * Migração segura do AdminIgreja legado para Igreja Master PHP/MySQL.
 * Aceita uma pasta de migrations .sql ou um arquivo .sql único.
 * Em falha, tenta remover apenas tabelas novas e restaurar as legacy_*.
 */
final class LegacyTakeover
{
    public function __construct(private PDO $pdo, private string $schemaSource) {}

    public function run(): array
    {
        $this->ensureMetadata();
        $key = 'faith_flow_php_mysql_takeover_v2';
        $q = $this->pdo->prepare('SELECT details FROM takeover_migrations WHERE migration_key=? LIMIT 1');
        $q->execute([$key]);
        $done = $q->fetchColumn();
        if ($done !== false) return ['already_applied'=>true,'details'=>json_decode((string)$done,true) ?: []];

        $files = $this->schemaFiles();
        if (!$files) throw new RuntimeException('Nenhuma migration SQL foi encontrada para o takeover.');

        $lock = $this->pdo->query("SELECT GET_LOCK('faith_flow_takeover',30)")->fetchColumn();
        if ((int)$lock !== 1) throw new RuntimeException('Não foi possível obter a trava exclusiva da migração.');

        $this->preflightDatabasePrivileges();

        $stamp = date('Ymd_His');
        $renamed = [];
        $preexisting = [];
        $legacyDetected = false;
        $schemaMigrationTablePreexisting=$this->tableExists('schema_migrations');
        $appliedSchemaMigrations=[];

        try {
            $schemaSql = '';
            foreach ($files as $file) {
                $sql = file_get_contents($file);
                if ($sql === false) throw new RuntimeException('Não foi possível ler '.basename($file).'.');
                $schemaSql .= "\n".$sql;
            }

            $newSchemaTables = $this->extractCreateTables($schemaSql);
            foreach ($newSchemaTables as $table) $preexisting[$table] = $this->tableExists($table);

            $legacyDetected=$this->detectLegacyDatabase();

            if ($legacyDetected) {
                foreach ($newSchemaTables as $table) {
                    if (!$this->tableExists($table)) continue;
                    $legacy = 'legacy_adminigreja_'.$stamp.'_'.$table;
                    $this->pdo->exec('RENAME TABLE '.$this->id($table).' TO '.$this->id($legacy));
                    $renamed[$table] = $legacy;
                }
            }

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ($files as $file) {
                $name=basename($file);
                $q=$this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration=?');
                $q->execute([$name]);
                if($q->fetchColumn()) continue;

                $sql = file_get_contents($file);
                if ($sql === false) throw new RuntimeException('Não foi possível ler '.$name.'.');
                $this->runSqlScript($sql);
                $i=$this->pdo->prepare('INSERT INTO schema_migrations(migration) VALUES(?)');
                $i->execute([$name]);
                $appliedSchemaMigrations[]=$name;
            }

            $created = [];
            foreach ($newSchemaTables as $table) {
                if ((!$preexisting[$table] || isset($renamed[$table])) && $this->tableExists($table)) $created[] = $table;
            }

            $importStats = $legacyDetected ? (new LegacyDataMigrator($this->pdo))->migrate($renamed) : [];

            $details = [
                'applied_at'=>date(DATE_ATOM),
                'legacy_detected'=>$legacyDetected,
                'renamed_tables'=>$renamed,
                'schema_files'=>array_map('basename',$files),
                'created_tables'=>array_values(array_unique($created)),
                'legacy_import'=>$importStats,
            ];
            $i = $this->pdo->prepare('INSERT INTO takeover_migrations(migration_key,details) VALUES(?,?)');
            $i->execute([$key,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            return ['already_applied'=>false,'details'=>$details];
        } catch (Throwable $e) {
            $migrationRollback=$this->rollbackMigrationHistory($appliedSchemaMigrations,$schemaMigrationTablePreexisting);
            $rollback = $this->rollbackAttempt($renamed,$preexisting);
            if(!$migrationRollback['ok']){
                $rollback['ok']=false;
                $rollback['errors']=array_merge($rollback['errors'],$migrationRollback['errors']);
            }
            $this->recordFailure($e->getMessage(),$rollback,$renamed,$files);
            throw new RuntimeException('Takeover interrompido: '.$e->getMessage().($rollback['ok']?' O estado anterior foi restaurado.':' A restauração automática precisa de revisão.'),0,$e);
        } finally {
            try { $this->pdo->query("SELECT RELEASE_LOCK('faith_flow_takeover')"); } catch (Throwable) {}
        }
    }

    private function detectLegacyDatabase(): bool
    {
        foreach (['accounts','visitors','transactions','payables','audit_logs'] as $marker) {
            if ($this->tableExists($marker)) return true;
        }

        $checks=[
            'users'=>[
                'legacy_columns'=>['church_id','name','role'],
                'new_id_type'=>['char','varchar'],
                'new_id_length'=>36,
            ],
            'churches'=>[
                'legacy_columns'=>['monthly_fee','due_date','max_users','status'],
                'new_id_type'=>['char','varchar'],
                'new_id_length'=>36,
            ],
            'members'=>[
                'legacy_columns'=>['status'],
                'new_id_type'=>['char','varchar'],
                'new_id_length'=>36,
            ],
            'events'=>[
                'legacy_columns'=>['attendance'],
                'new_id_type'=>['char','varchar'],
                'new_id_length'=>36,
            ],
        ];

        foreach($checks as $table=>$rule){
            if(!$this->tableExists($table)) continue;

            $columns=$this->tableColumns($table);
            foreach($rule['legacy_columns'] as $column){
                if(isset($columns[strtolower($column)])) return true;
            }

            $id=$columns['id']??null;
            if(!$id) continue;
            $type=strtolower((string)($id['data_type']??''));
            $length=(int)($id['character_maximum_length']??0);

            if(!in_array($type,$rule['new_id_type'],true)) return true;
            if($length!==$rule['new_id_length']) return true;
        }

        return false;
    }

    private function tableColumns(string $table): array
    {
        $q=$this->pdo->prepare('SELECT column_name,data_type,character_maximum_length,column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        $out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $out[strtolower((string)$row['column_name'])]=$row;
        }
        return $out;
    }

    private function preflightDatabasePrivileges(): void
    {
        $suffix=substr(hash('sha256',random_bytes(16)),0,12);
        $a='takeover_preflight_'.$suffix;
        $b=$a.'_renamed';

        try{
            $this->pdo->exec('CREATE TABLE '.$this->id($a).' (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $this->pdo->exec('RENAME TABLE '.$this->id($a).' TO '.$this->id($b));
            $this->pdo->exec('DROP TABLE '.$this->id($b));
        }catch(Throwable $e){
            try{
                if($this->tableExists($a))$this->pdo->exec('DROP TABLE '.$this->id($a));
                if($this->tableExists($b))$this->pdo->exec('DROP TABLE '.$this->id($b));
            }catch(Throwable){}
            throw new RuntimeException(
                'O usuário MySQL não possui todas as permissões necessárias para a migração (CREATE/RENAME/DROP): '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    private function schemaFiles(): array
    {
        if (is_file($this->schemaSource)) return [$this->schemaSource];
        if (!is_dir($this->schemaSource)) return [];
        $files = glob(rtrim($this->schemaSource,"/\\").'/*.sql') ?: [];
        usort($files,fn(string $a,string $b):int=>strnatcasecmp(basename($a),basename($b)));
        return $files;
    }

    private function rollbackMigrationHistory(array $migrations,bool $tablePreexisting): array
    {
        $errors=[];
        try{
            if($this->tableExists('schema_migrations')){
                if($migrations){
                    $placeholders=implode(',',array_fill(0,count($migrations),'?'));
                    $q=$this->pdo->prepare('DELETE FROM schema_migrations WHERE migration IN ('.$placeholders.')');
                    $q->execute(array_values($migrations));
                }
                if(!$tablePreexisting){
                    $this->pdo->exec('DROP TABLE schema_migrations');
                }
            }
        }catch(Throwable $e){
            $errors[]='schema_migrations: '.$e->getMessage();
        }
        return ['ok'=>$errors===[],'errors'=>$errors];
    }

    private function rollbackAttempt(array $renamed,array $preexisting): array
    {
        $errors=[];
        try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $e) { $errors[]=$e->getMessage(); }

        foreach (array_keys($renamed) as $table) {
            try { if ($this->tableExists($table)) $this->pdo->exec('DROP TABLE '.$this->id($table)); }
            catch (Throwable $e) { $errors[]='drop '.$table.': '.$e->getMessage(); }
        }
        foreach ($preexisting as $table=>$existed) {
            if ($existed || isset($renamed[$table])) continue;
            try { if ($this->tableExists($table)) $this->pdo->exec('DROP TABLE '.$this->id($table)); }
            catch (Throwable $e) { $errors[]='drop '.$table.': '.$e->getMessage(); }
        }
        foreach (array_reverse($renamed,true) as $original=>$legacy) {
            try {
                if ($this->tableExists($legacy) && !$this->tableExists($original))
                    $this->pdo->exec('RENAME TABLE '.$this->id($legacy).' TO '.$this->id($original));
            } catch (Throwable $e) { $errors[]='restore '.$original.': '.$e->getMessage(); }
        }
        try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) { $errors[]=$e->getMessage(); }
        return ['ok'=>$errors===[],'errors'=>$errors];
    }

    private function recordFailure(string $error,array $rollback,array $renamed,array $files): void
    {
        try {
            $details=json_encode(['failed_at'=>date(DATE_ATOM),'error'=>$error,'rollback'=>$rollback,'renamed_tables'=>$renamed,'schema_files'=>array_map('basename',$files)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $q=$this->pdo->prepare('INSERT INTO takeover_failures(details) VALUES(?)');
            $q->execute([$details]);
        } catch (Throwable) {}
    }

    private function ensureMetadata(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS takeover_migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration_key VARCHAR(190) NOT NULL UNIQUE,details LONGTEXT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS takeover_failures (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,details LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function tableExists(string $table): bool
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn()>0;
    }

    private function extractCreateTables(string $sql): array
    {
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',$sql,$m);
        return array_values(array_unique($m[1]??[]));
    }

    private function runSqlScript(string $sql): void
    {
        foreach ($this->splitStatements($sql) as $statement) {
            $statement=trim($statement);
            if ($statement!=='') $this->pdo->exec($statement);
        }
    }

    private function splitStatements(string $sql): array
    {
        $out=[];$buf='';$quote=null;$lineComment=false;$blockComment=false;$len=strlen($sql);
        for($i=0;$i<$len;$i++){
            $c=$sql[$i];$n=$i+1<$len?$sql[$i+1]:'';$prev=$i>0?$sql[$i-1]:'';
            if($lineComment){ if($c==="\n"){$lineComment=false;$buf.=$c;} continue; }
            if($blockComment){ if($c==='*'&&$n==='/'){$blockComment=false;$i++;} continue; }
            if($quote===null){
                if(($c==='-'&&$n==='-'&&($i+2>=$len||ctype_space($sql[$i+2])))||$c==='#'){$lineComment=true;if($c==='-')$i++;continue;}
                if($c==='/'&&$n==='*'){$blockComment=true;$i++;continue;}
            }
            if(($c==="'"||$c==='"'||$c===chr(96))&&$prev!=='\\'){
                if($quote===null)$quote=$c; elseif($quote===$c)$quote=null;
            }
            if($c===';'&&$quote===null){ if(trim($buf)!=='')$out[]=trim($buf); $buf=''; continue; }
            $buf.=$c;
        }
        if(trim($buf)!=='')$out[]=trim($buf);
        return $out;
    }

    private function id(string $identifier): string
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$identifier)) throw new InvalidArgumentException('Identificador SQL inválido.');
        return chr(96).$identifier.chr(96);
    }
}
