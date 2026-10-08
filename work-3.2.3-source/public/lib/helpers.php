<?php

declare(strict_types=1);

function app_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function slugify(string $text): string
{
    $text = trim(mb_strtolower($text, 'UTF-8'));
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
    } else {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    }
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
    return trim($text, '-') ?: 'igreja';
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function null_if_blank(mixed $value): mixed
{
    if ($value === null) return null;
    if (is_string($value)) {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
    return $value;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">';
}

function verify_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '');
    $session = (string)($_SESSION['_csrf'] ?? '');
    if ($session === '' || $token === '' || !hash_equals($session, $token)) {
        http_response_code(419);
        exit('Sessão expirada ou requisição inválida. Atualize a página e tente novamente.');
    }
}

function csrf_check(): void
{
    verify_csrf();
}

function flash(string $type, ?string $message = null): ?string
{
    $_SESSION['_flash'] ??= [];
    if ($message !== null) {
        $_SESSION['_flash'][$type] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$type] ?? null;
    unset($_SESSION['_flash'][$type]);
    return is_string($value) ? $value : null;
}
