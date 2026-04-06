<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabelas_publicacao_notas(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS configuracao_publicacao_notas (
                id INTEGER PRIMARY KEY,
                modo_publicacao TEXT NOT NULL DEFAULT 'manual' CHECK (modo_publicacao IN ('manual', 'automatica')),
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec("INSERT OR IGNORE INTO configuracao_publicacao_notas (id, modo_publicacao) VALUES (1, 'manual')");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notas_publicadas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turma_id INTEGER NOT NULL,
                disciplina_id INTEGER NOT NULL,
                etapa TEXT NOT NULL,
                publicado_por INTEGER NULL,
                publicado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (turma_id, disciplina_id, etapa),
                FOREIGN KEY (turma_id) REFERENCES turmas(id),
                FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
                FOREIGN KEY (publicado_por) REFERENCES usuarios(id)
            )"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS configuracao_publicacao_notas (
            id TINYINT PRIMARY KEY,
            modo_publicacao VARCHAR(20) NOT NULL DEFAULT 'manual',
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $pdo->exec(
        "INSERT INTO configuracao_publicacao_notas (id, modo_publicacao)
         VALUES (1, 'manual')
         ON DUPLICATE KEY UPDATE id = id"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notas_publicadas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            turma_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            etapa VARCHAR(30) NOT NULL,
            publicado_por INT NULL,
            publicado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notas_publicadas (turma_id, disciplina_id, etapa),
            CONSTRAINT fk_np_turma FOREIGN KEY (turma_id) REFERENCES turmas(id),
            CONSTRAINT fk_np_disciplina FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
            CONSTRAINT fk_np_usuario FOREIGN KEY (publicado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function obter_config_publicacao_notas(PDO $pdo): array
{
    garantir_tabelas_publicacao_notas($pdo);

    $row = $pdo->query('SELECT modo_publicacao FROM configuracao_publicacao_notas WHERE id = 1 LIMIT 1')->fetch();
    $modo = is_array($row) ? (string) ($row['modo_publicacao'] ?? 'manual') : 'manual';
    if (!in_array($modo, ['manual', 'automatica'], true)) {
        $modo = 'manual';
    }

    return [
        'modo_publicacao' => $modo,
    ];
}

function salvar_config_publicacao_notas(PDO $pdo, string $modo): void
{
    garantir_tabelas_publicacao_notas($pdo);

    $modo = trim($modo);
    if (!in_array($modo, ['manual', 'automatica'], true)) {
        throw new RuntimeException('Modo de publicacao invalido.');
    }

    if (usando_sqlite($pdo)) {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracao_publicacao_notas (id, modo_publicacao)
             VALUES (1, :modo_publicacao)
             ON CONFLICT(id) DO UPDATE SET
                modo_publicacao = excluded.modo_publicacao,
                atualizado_em = CURRENT_TIMESTAMP"
        );
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracao_publicacao_notas (id, modo_publicacao)
             VALUES (1, :modo_publicacao)
             ON DUPLICATE KEY UPDATE
                modo_publicacao = VALUES(modo_publicacao),
                atualizado_em = CURRENT_TIMESTAMP"
        );
    }

    $stmt->execute(['modo_publicacao' => $modo]);
}

function publicar_notas_etapa(PDO $pdo, int $turmaId, int $disciplinaId, string $etapa, ?int $usuarioId = null): void
{
    garantir_tabelas_publicacao_notas($pdo);

    if ($turmaId <= 0 || $disciplinaId <= 0 || trim($etapa) === '') {
        throw new RuntimeException('Parametros invalidos para publicacao.');
    }

    if (usando_sqlite($pdo)) {
        $stmt = $pdo->prepare(
            "INSERT INTO notas_publicadas (turma_id, disciplina_id, etapa, publicado_por)
             VALUES (:turma_id, :disciplina_id, :etapa, :publicado_por)
             ON CONFLICT(turma_id, disciplina_id, etapa) DO UPDATE SET
                publicado_por = excluded.publicado_por,
                publicado_em = CURRENT_TIMESTAMP"
        );
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO notas_publicadas (turma_id, disciplina_id, etapa, publicado_por)
             VALUES (:turma_id, :disciplina_id, :etapa, :publicado_por)
             ON DUPLICATE KEY UPDATE
                publicado_por = VALUES(publicado_por),
                publicado_em = CURRENT_TIMESTAMP"
        );
    }

    $stmt->execute([
        'turma_id' => $turmaId,
        'disciplina_id' => $disciplinaId,
        'etapa' => trim($etapa),
        'publicado_por' => $usuarioId,
    ]);
}

function despublicar_notas_etapa(PDO $pdo, int $turmaId, int $disciplinaId, string $etapa): void
{
    garantir_tabelas_publicacao_notas($pdo);

    $stmt = $pdo->prepare(
        'DELETE FROM notas_publicadas
         WHERE turma_id = :turma_id
           AND disciplina_id = :disciplina_id
           AND etapa = :etapa'
    );

    $stmt->execute([
        'turma_id' => $turmaId,
        'disciplina_id' => $disciplinaId,
        'etapa' => trim($etapa),
    ]);
}

function notas_publicadas_info(PDO $pdo, int $turmaId, int $disciplinaId, string $etapa): ?array
{
    garantir_tabelas_publicacao_notas($pdo);

    $stmt = $pdo->prepare(
        'SELECT np.id, np.publicado_por, np.publicado_em, u.nome AS publicado_por_nome
         FROM notas_publicadas np
         LEFT JOIN usuarios u ON u.id = np.publicado_por
         WHERE np.turma_id = :turma_id
           AND np.disciplina_id = :disciplina_id
           AND np.etapa = :etapa
         LIMIT 1'
    );

    $stmt->execute([
        'turma_id' => $turmaId,
        'disciplina_id' => $disciplinaId,
        'etapa' => trim($etapa),
    ]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function notas_estao_publicadas(PDO $pdo, int $turmaId, int $disciplinaId, string $etapa): bool
{
    return notas_publicadas_info($pdo, $turmaId, $disciplinaId, $etapa) !== null;
}

function mapa_disciplinas_publicadas(PDO $pdo, int $turmaId): array
{
    garantir_tabelas_publicacao_notas($pdo);

    $stmt = $pdo->prepare(
        'SELECT disciplina_id, etapa
         FROM notas_publicadas
         WHERE turma_id = :turma_id'
    );
    $stmt->execute(['turma_id' => $turmaId]);

    $mapa = [];
    foreach ($stmt->fetchAll() as $row) {
        $disciplinaId = (int) ($row['disciplina_id'] ?? 0);
        $etapa = (string) ($row['etapa'] ?? '');
        if ($disciplinaId <= 0 || $etapa === '') {
            continue;
        }

        if (!isset($mapa[$disciplinaId])) {
            $mapa[$disciplinaId] = [];
        }

        $mapa[$disciplinaId][$etapa] = true;
    }

    return $mapa;
}

function exportacao_boletim_permitida(PDO $pdo, int $turmaId): bool
{
    $config = obter_config_publicacao_notas($pdo);
    if ($config['modo_publicacao'] === 'automatica') {
        return true;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM notas_publicadas WHERE turma_id = :turma_id LIMIT 1');
    $stmt->execute(['turma_id' => $turmaId]);

    return (bool) $stmt->fetch();
}