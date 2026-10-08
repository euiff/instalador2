<?php

declare(strict_types=1);

final class Schema
{
    public function __construct(private PDO $pdo, private string $databaseDir) {}

    public function migrate(): array
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $files = glob(rtrim($this->databaseDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            $q = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration=?');
            $q->execute([$name]);
            if ($q->fetchColumn()) continue;

            $sql = (string)file_get_contents($file);
            $this->executeScript($sql);
            $i = $this->pdo->prepare('INSERT INTO schema_migrations(migration) VALUES(?)');
            $i->execute([$name]);
            $applied[] = $name;
        }

        return $applied;
    }

    private function executeScript(string $sql): void
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) continue;
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);

        $buffer = '';
        $quote = null;
        $len = strlen($sql);
        for ($i=0; $i<$len; $i++) {
            $c = $sql[$i];
            $prev = $i > 0 ? $sql[$i-1] : '';
            if (($c === "'" || $c === '"') && $prev !== '\\') {
                if ($quote === null) $quote = $c;
                elseif ($quote === $c) $quote = null;
            }
            if ($c === ';' && $quote === null) {
                if (trim($buffer) !== '') $this->execStatement(trim($buffer));
                $buffer = '';
            } else {
                $buffer .= $c;
            }
        }
        if (trim($buffer) !== '') $this->execStatement(trim($buffer));
    }

    private function execStatement(string $sql): void
    {
        try{
            $this->pdo->exec($sql);
        }catch(PDOException $e){
            $driverCode=(int)($e->errorInfo[1]??0);
            $normalized=strtoupper(preg_replace('/\s+/',' ',trim($sql))??trim($sql));

            $safeDuplicateColumn=
                $driverCode===1060 &&
                str_starts_with($normalized,'ALTER TABLE ') &&
                str_contains($normalized,' ADD COLUMN ');

            $safeDuplicateIndex=
                $driverCode===1061 &&
                str_starts_with($normalized,'ALTER TABLE ') &&
                (str_contains($normalized,' ADD INDEX ')||str_contains($normalized,' ADD KEY ')||str_contains($normalized,' ADD UNIQUE '));

            if($safeDuplicateColumn||$safeDuplicateIndex)return;
            throw $e;
        }
    }
}
