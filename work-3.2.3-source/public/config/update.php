<?php
return [
    // Canal público de atualização. Não exige token, Terminal nem SSH no cPanel.
    'channel_url' => 'https://raw.githubusercontent.com/euiff/instalador2/main/adminigreja-updates/latest.json',
    'channel_base_url' => 'https://raw.githubusercontent.com/euiff/instalador2/main/adminigreja-updates/',
    'channel_label' => 'Igreja Master · canal público validado',
    'backup_dir' => __DIR__ . '/../storage/backups',

    // Compatibilidade de emergência com releases antigos privados.
    'repository' => 'euiff/faith-flow-pix',
    'branch' => 'php-mysql',
    'repo_path' => 'php',
    'token' => getenv('GITHUB_UPDATE_TOKEN') ?: '',
    'manifest' => 'updates/manifest.json',
];
