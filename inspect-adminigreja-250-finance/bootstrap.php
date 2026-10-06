<?php
declare(strict_types=1);

// AdminIgreja é voltado inicialmente para igrejas no Brasil. Mantém datas de aniversário, relatórios e documentos no horário local brasileiro.
if (function_exists('date_default_timezone_set')) date_default_timezone_set('America/Sao_Paulo');

$root = dirname(__DIR__);
$dbConfig = $root . '/config/database.php';
$appConfig = $root . '/config/app.php';

if (!is_file($dbConfig) || !is_file($appConfig)) {
    if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'install.php') === false) {
        header('Location: /install.php');
        exit;
    }
}

// Arquivos de configuração são protegidos por .htaccess. 0644 evita falha de leitura
// em hospedagens LiteSpeed/cPanel que executam PHP com grupo diferente do gerenciador de arquivos.
if (is_file($dbConfig) && !is_readable($dbConfig)) @chmod($dbConfig, 0644);
if (is_file($appConfig) && !is_readable($appConfig)) @chmod($appConfig, 0644);
if (is_file($dbConfig)) @chmod($dbConfig, 0644);
if (is_file($appConfig)) @chmod($appConfig, 0644);

require_once __DIR__ . '/security.php';

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php-error.log');

security_headers();
start_secure_session();

if (!function_exists('app_config')) {
    function app_config(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $file = dirname(__DIR__) . '/config/app.php';
        if (!is_file($file) || !is_readable($file)) return $cfg = [];
        $loaded = require $file;
        return $cfg = is_array($loaded) ? $loaded : [];
    }
}
if (!function_exists('app_key')) {
    function app_key(): string { return (string)(app_config()['key'] ?? ''); }
}
if (!function_exists('app_key_bytes')) {
    function app_key_bytes(): string { return hash('sha256', app_key() ?: 'adminigreja', true); }
}

if (is_file($dbConfig)) {
    if (!is_readable($dbConfig)) throw new RuntimeException('Configuração do banco não pode ser lida.');
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/migrations.php';
    migrate_runtime_schema();
    require_once __DIR__ . '/updater.php';
    require_once __DIR__ . '/files.php';
}

if (!function_exists('redirect')) {
    function redirect(string $url): void { header('Location: '.$url); exit; }
}
if (!function_exists('audit')) {
    function audit(string $action, ?string $entity=null, ?int $entityId=null, array $meta=[]): void {
        if (!function_exists('db') || !function_exists('is_logged') || !is_logged()) return;
        try {
            q('INSERT INTO audit_logs(church_id,user_id,action,entity_type,entity_id,ip_hash,metadata_json) VALUES(?,?,?,?,?,?,?)', [
                church_id() ?: null,
                (int)(user()['id'] ?? 0) ?: null,
                $action,
                $entity,
                $entityId,
                client_ip_hash(app_key()),
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
            ]);
        } catch (Throwable $e) {
            error_log('audit: '.$e->getMessage());
        }
    }
}
