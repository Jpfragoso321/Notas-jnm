<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

$legacyBase = base_path('legacy_backup' . DIRECTORY_SEPARATOR . 'public');

$renderLegacy = function (string $script) use ($legacyBase): Response {
    $target = $legacyBase . DIRECTORY_SEPARATOR . $script . '.php';

    if (!is_file($target)) {
        abort(404, 'Pagina nao encontrada.');
    }

    ob_start();
    require $target;
    $content = ob_get_clean() ?: '';

    return response($content);
};

$renderAsset = function (string $filename, string $contentType) use ($legacyBase): Response {
    $target = $legacyBase . DIRECTORY_SEPARATOR . $filename;

    if (!is_file($target)) {
        abort(404, 'Arquivo nao encontrado.');
    }

    $content = file_get_contents($target);
    return response($content, 200, ['Content-Type' => $contentType]);
};

Route::get('/style.css', fn () => $renderAsset('style.css', 'text/css; charset=UTF-8'));
Route::get('/theme.js', fn () => $renderAsset('theme.js', 'application/javascript; charset=UTF-8'));

Route::any('/', fn () => $renderLegacy('index'));
Route::any('/dashboard', fn () => $renderLegacy('dashboard'));
Route::any('/admin', fn () => $renderLegacy('admin'));
Route::any('/manager', fn () => $renderLegacy('manager'));
Route::any('/turma', fn () => $renderLegacy('turma'));
Route::any('/risco', fn () => $renderLegacy('risco'));
Route::any('/boletim_pdf', fn () => $renderLegacy('boletim_pdf'));
Route::any('/logout', fn () => $renderLegacy('logout'));

Route::any('/frequencia', fn () => $renderLegacy('frequencia'));
Route::any('/historico', fn () => $renderLegacy('historico'));
Route::any('/conselho', fn () => $renderLegacy('conselho'));
Route::any('/notificacoes', fn () => $renderLegacy('notificacoes'));
Route::any('/auditoria', fn () => $renderLegacy('auditoria'));
Route::any('/relatorios', fn () => $renderLegacy('relatorios'));
Route::any('/backup', fn () => $renderLegacy('backup'));
Route::any('/backup-download', fn () => $renderLegacy('backup-download'));
Route::any('/portal-aluno', fn () => $renderLegacy('portal-aluno'));
Route::any('/rubricas', fn () => $renderLegacy('rubricas'));
Route::any('/analytics-avancado', fn () => $renderLegacy('analytics-avancado'));