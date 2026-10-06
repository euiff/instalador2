<?php
declare(strict_types=1);

// AdminIgreja - front controller.
// Quando o visitante ainda não entrou e abre a raiz, exibe a página comercial.
// Rotas do sistema continuam em /?r=...
try {
    $route = isset($_GET['r']) ? trim((string)$_GET['r']) : '';
    $hasSessionCookie = !empty($_COOKIE['adminigreja_sid']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === '' && !$hasSessionCookie) {
        require __DIR__ . '/public/landing.php';
        exit;
    }

    require __DIR__ . '/public/index.php';
} catch (Throwable $e) {
    $code = strtoupper(substr(hash('sha256', get_class($e).'|'.$e->getMessage().'|'.$e->getFile().'|'.$e->getLine()), 0, 10));
    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
    @file_put_contents($logDir.'/runtime-error.log', '['.date('c').'] '.$code.' '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AdminIgreja</title><body style="font-family:Arial;background:#f3f6fa;color:#172033"><div style="max-width:720px;margin:60px auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px"><h2 style="color:#17324d">AdminIgreja</h2><p>O sistema encontrou uma falha de inicialização.</p><p><b>Código técnico:</b> '.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</p><p>Esse código não revela senha nem dados do banco.</p></div></body></html>';
}
