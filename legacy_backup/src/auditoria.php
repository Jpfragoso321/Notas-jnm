<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabela_auditoria(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS auditoria_eventos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario_id INTEGER NULL,
                usuario_nome TEXT NULL,
                usuario_perfil TEXT NULL,
                modulo TEXT NOT NULL,
                acao TEXT NOT NULL,
                resultado TEXT NOT NULL,
                detalhes TEXT NULL,
                ip_origem TEXT NULL,
                user_agent TEXT NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS auditoria_eventos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NULL,
                usuario_nome VARCHAR(200) NULL,
                usuario_perfil VARCHAR(100) NULL,
                modulo VARCHAR(100) NOT NULL,
                acao VARCHAR(120) NOT NULL,
                resultado VARCHAR(50) NOT NULL,
                detalhes TEXT NULL,
                ip_origem VARCHAR(80) NULL,
                user_agent VARCHAR(255) NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

function registrar_auditoria(PDO $pdo, ?array $usuario, string $modulo, string $acao, string $resultado, array $detalhes = []): void
{
    garantir_tabela_auditoria($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO auditoria_eventos (
            usuario_id,
            usuario_nome,
            usuario_perfil,
            modulo,
            acao,
            resultado,
            detalhes,
            ip_origem,
            user_agent
        ) VALUES (
            :usuario_id,
            :usuario_nome,
            :usuario_perfil,
            :modulo,
            :acao,
            :resultado,
            :detalhes,
            :ip_origem,
            :user_agent
        )'
    );

    $stmt->execute([
        'usuario_id' => is_array($usuario) ? (int) ($usuario['id'] ?? 0) : null,
        'usuario_nome' => is_array($usuario) ? (string) ($usuario['nome'] ?? '') : null,
        'usuario_perfil' => is_array($usuario) ? (string) ($usuario['perfil'] ?? '') : null,
        'modulo' => $modulo,
        'acao' => $acao,
        'resultado' => $resultado,
        'detalhes' => !empty($detalhes) ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
        'ip_origem' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function listar_auditoria(PDO $pdo, int $limite = 80): array
{
    garantir_tabela_auditoria($pdo);

    $limite = max(1, min(500, $limite));
    $sql = usando_sqlite($pdo)
        ? 'SELECT id, usuario_nome, usuario_perfil, modulo, acao, resultado, detalhes, criado_em FROM auditoria_eventos ORDER BY id DESC LIMIT ' . $limite
        : 'SELECT id, usuario_nome, usuario_perfil, modulo, acao, resultado, detalhes, criado_em FROM auditoria_eventos ORDER BY id DESC LIMIT ' . $limite;

    return $pdo->query($sql)->fetchAll();
}