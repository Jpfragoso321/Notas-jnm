<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicDir = __DIR__;

$requestedPath = $publicDir . $uri;
if ($uri !== '/' && is_file($requestedPath)) {
    return false;
}

if ($uri === '/' || $uri === '') {
    require $publicDir . '/index.php';
    return true;
}

$rota = trim($uri, '/');

if (str_ends_with($rota, '.php')) {
    $arquivoDireto = $publicDir . '/' . $rota;
    if (is_file($arquivoDireto)) {
        require $arquivoDireto;
        return true;
    }
}

$arquivoPhp = $publicDir . '/' . $rota . '.php';
if (is_file($arquivoPhp)) {
    require $arquivoPhp;
    return true;
}

http_response_code(404);
echo 'Pagina nao encontrada.';
return true;
