<?php

declare(strict_types=1);

/**
 * Gera o manifest de atualização a partir da pasta PHP.
 *
 * Uso:
 *   php tools/build-manifest.php 1.0.0 > updates/manifest.generated.json
 *
 * O arquivo config/database.php, uploads, backups e o próprio manifesto
 * nunca entram na distribuição automática.
 */
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$releaseRef = trim((string)($argv[2] ?? getenv('GITHUB_SHA') ?: ''));
if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
    fwrite(STDERR, "Informe uma versão SemVer. Ex.: php tools/build-manifest.php 1.0.0\n");
    exit(2);
}

$excludedExact = [
    'config/database.php',
    'updates/manifest.json',
    'updates/manifest.generated.json',
];
$excludedPrefixes = [
    'storage/',
    '.git/',
];

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $absolute = $file->getPathname();
    $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));

    if (in_array($relative, $excludedExact, true)) continue;
    $skip = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    $files[] = [
        'path' => $relative,
        'sha256' => hash_file('sha256', $absolute),
    ];
}

usort($files, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

$migrations = [];
$migrationDir = $root . '/updates/migrations';
if (is_dir($migrationDir)) {
    foreach (glob($migrationDir . '/*.sql') ?: [] as $path) {
        $migrations[] = basename($path);
    }
    sort($migrations, SORT_NATURAL);
}

$deleteList = [];
$deleteFile = $root . '/updates/delete-list.json';
if (is_file($deleteFile)) {
    $decoded = json_decode((string)file_get_contents($deleteFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('updates/delete-list.json deve conter uma lista JSON.');
    }
    foreach ($decoded as $path) {
        if (!is_string($path) || trim($path) === '') continue;
        $path = str_replace('\\', '/', trim($path));
        if (
            str_contains($path, '..') ||
            str_starts_with($path, '/') ||
            preg_match('#^(config/database\\.php|config/local\\.php|storage/|\\.env(?:\\.|$))#i', $path)
        ) {
            throw new RuntimeException('Caminho proibido na lista de exclusão: ' . $path);
        }
        $deleteList[] = $path;
    }
    $deleteList = array_values(array_unique($deleteList));
    sort($deleteList, SORT_NATURAL);
}

$manifest = [
    'version' => $version,
    'ref' => $releaseRef !== '' ? $releaseRef : null,
    'min_php' => '8.1',
    'generated_at' => gmdate(DATE_ATOM),
    'notes' => [
        'Atualização automática do Igreja Master PHP/MySQL.',
        'Backup realizado antes da troca de arquivos.',
        'Configuração local e uploads não são sobrescritos.',
    ],
    'files' => $files,
    'delete' => $deleteList,
    'migrations' => $migrations,
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
