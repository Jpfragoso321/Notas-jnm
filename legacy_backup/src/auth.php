<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function usuario_logado(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function perfil_usuario(?array $usuario = null): string
{
    $usuario = $usuario ?? usuario_logado();
    if (!is_array($usuario)) {
        return '';
    }

    return (string) ($usuario['perfil'] ?? '');
}

function nome_perfil_exibicao(string $perfil): string
{
    return match ($perfil) {
        'admin' => 'Administrador',
        'diretora' => 'Diretora',
        'coordenacao_pedagogica' => 'Coordenacao Pedagogica',
        'secretario' => 'Secretaria',
        default => 'Professor',
    };
}

function perfis_manager(): array
{
    return ['diretora', 'coordenacao_pedagogica', 'secretario'];
}

function usuario_eh_admin(?array $usuario = null): bool
{
    $usuario = $usuario ?? usuario_logado();
    return is_array($usuario) && (string) ($usuario['perfil'] ?? '') === 'admin';
}

function usuario_eh_manager(?array $usuario = null, bool $incluirAdmin = true): bool
{
    $usuario = $usuario ?? usuario_logado();
    if (!is_array($usuario)) {
        return false;
    }

    $perfil = (string) ($usuario['perfil'] ?? '');
    if ($incluirAdmin && $perfil === 'admin') {
        return true;
    }

    return in_array($perfil, perfis_manager(), true);
}

function usuario_tem_permissao(string $permissao, ?array $usuario = null): bool
{
    $perfil = perfil_usuario($usuario);
    if ($perfil === '') {
        return false;
    }

    $mapa = [
        'admin.painel' => ['admin'],
        'manager.dashboard' => ['admin', 'diretora', 'coordenacao_pedagogica', 'secretario'],
        'boletim.exportar' => ['admin', 'diretora', 'coordenacao_pedagogica', 'secretario', 'professor'],
        'risco.visualizar' => ['admin', 'diretora', 'coordenacao_pedagogica', 'secretario', 'professor'],
        'notas.lancar' => ['admin', 'diretora', 'coordenacao_pedagogica', 'secretario', 'professor'],
        'aluno.gerenciar' => ['admin', 'diretora', 'coordenacao_pedagogica', 'secretario'],
        'disciplina.gerenciar' => ['admin', 'diretora', 'coordenacao_pedagogica', 'secretario'],
    ];

    $perfisPermitidos = $mapa[$permissao] ?? [];
    return in_array($perfil, $perfisPermitidos, true);
}

function exigir_permissao(string $permissao, string $mensagem = 'Acesso negado.'): void
{
    if (!usuario_tem_permissao($permissao)) {
        http_response_code(403);
        echo $mensagem;
        exit;
    }
}

function exigir_login(): void
{
    if (!usuario_logado()) {
        header('Location: /');
        exit;
    }
}

function exigir_admin(): void
{
    exigir_permissao('admin.painel', 'Acesso restrito para administradores.');
}

function exigir_manager(): void
{
    exigir_permissao('manager.dashboard', 'Acesso restrito para perfis de gestao.');
}

function autenticar(string $usuario, string $senha): bool
{
    $sql = 'SELECT id, nome, usuario, senha, perfil FROM usuarios WHERE usuario = :usuario LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(['usuario' => $usuario]);
    $registro = $stmt->fetch();

    if (!$registro) {
        return false;
    }

    $senhaValida = false;
    $senhaBanco = (string) $registro['senha'];

    if (str_starts_with($senhaBanco, '$2y$') || str_starts_with($senhaBanco, '$argon2')) {
        $senhaValida = password_verify($senha, $senhaBanco);
    } else {
        // Compatibilidade de bootstrap para seed inicial.
        $senhaValida = hash_equals($senhaBanco, $senha);

        if ($senhaValida) {
            $novoHash = password_hash($senha, PASSWORD_DEFAULT);
            $update = db()->prepare('UPDATE usuarios SET senha = :senha WHERE id = :id');
            $update->execute(['senha' => $novoHash, 'id' => $registro['id']]);
        }
    }

    if (!$senhaValida) {
        return false;
    }

    $_SESSION['usuario'] = [
        'id' => (int) $registro['id'],
        'nome' => $registro['nome'],
        'usuario' => $registro['usuario'],
        'perfil' => $registro['perfil'],
    ];

    return true;
}

function pagina_inicial_por_perfil(array $usuario): string
{
    if (usuario_eh_admin($usuario)) {
        return '/admin';
    }

    if (usuario_eh_manager($usuario, false)) {
        return '/manager';
    }

    return '/dashboard';
}

function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}