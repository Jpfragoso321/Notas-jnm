<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/config.php';

exigir_login();
exigir_admin();

if (strtolower(DB_DRIVER) !== 'sqlite') {
    http_response_code(400);
    echo 'Download direto disponivel apenas para SQLite local.';
    exit;
}

$arquivo = SQLITE_PATH;
if (!is_file($arquivo)) {
    http_response_code(404);
    echo 'Arquivo de banco nao encontrado.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="backup_portal_ecmnm_' . date('Ymd_His') . '.sqlite"');
header('Content-Length: ' . (string) filesize($arquivo));
readfile($arquivo);
exit;
