<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabela_professor_disciplina(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS professor_disciplina (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                professor_id INTEGER NOT NULL,
                disciplina_id INTEGER NOT NULL,
                UNIQUE (professor_id, disciplina_id),
                FOREIGN KEY (professor_id) REFERENCES usuarios(id),
                FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id)
            )"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS professor_disciplina (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professor_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            UNIQUE KEY uq_professor_disciplina (professor_id, disciplina_id),
            CONSTRAINT fk_pd_professor FOREIGN KEY (professor_id) REFERENCES usuarios(id),
            CONSTRAINT fk_pd_disciplina FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id)
        )"
    );
}

function normalizar_ids_int(array $ids): array
{
    $saida = [];

    foreach ($ids as $id) {
        $valor = (int) $id;
        if ($valor > 0) {
            $saida[$valor] = $valor;
        }
    }

    return array_values($saida);
}

function filtrar_disciplinas_existentes(PDO $pdo, array $disciplinasIds): array
{
    $disciplinasIds = normalizar_ids_int($disciplinasIds);
    if ($disciplinasIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($disciplinasIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM disciplinas WHERE id IN ($placeholders)");
    $stmt->execute($disciplinasIds);

    $validas = [];
    foreach ($stmt->fetchAll() as $row) {
        $validas[] = (int) $row['id'];
    }

    return normalizar_ids_int($validas);
}

function sincronizar_professor_disciplinas(PDO $pdo, int $professorId, array $disciplinasIds): void
{
    garantir_tabela_professor_disciplina($pdo);

    if ($professorId <= 0) {
        throw new RuntimeException('Professor invalido para vinculo de disciplina.');
    }

    $disciplinasIds = filtrar_disciplinas_existentes($pdo, $disciplinasIds);
    if ($disciplinasIds === []) {
        throw new RuntimeException('Selecione ao menos uma disciplina valida para o professor.');
    }

    $iniciouTransacao = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $iniciouTransacao = true;
    }

    try {
        $pdo->prepare('DELETE FROM professor_disciplina WHERE professor_id = :professor_id')->execute(['professor_id' => $professorId]);

        if (usando_sqlite($pdo)) {
            $insert = $pdo->prepare(
                'INSERT INTO professor_disciplina (professor_id, disciplina_id)
                 VALUES (:professor_id, :disciplina_id)
                 ON CONFLICT(professor_id, disciplina_id) DO NOTHING'
            );
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO professor_disciplina (professor_id, disciplina_id)
                 VALUES (:professor_id, :disciplina_id)
                 ON DUPLICATE KEY UPDATE professor_id = VALUES(professor_id)'
            );
        }

        foreach ($disciplinasIds as $disciplinaId) {
            $insert->execute([
                'professor_id' => $professorId,
                'disciplina_id' => $disciplinaId,
            ]);
        }

        $placeholders = implode(',', array_fill(0, count($disciplinasIds), '?'));
        $sqlTurmas = "SELECT DISTINCT turma_id FROM disciplinas WHERE id IN ($placeholders)";
        $stmtTurmas = $pdo->prepare($sqlTurmas);
        $stmtTurmas->execute($disciplinasIds);

        $turmasIds = [];
        foreach ($stmtTurmas->fetchAll() as $row) {
            $turmasIds[] = (int) $row['turma_id'];
        }

        $turmasIds = normalizar_ids_int($turmasIds);

        $pdo->prepare('DELETE FROM professor_turma WHERE professor_id = :professor_id')->execute(['professor_id' => $professorId]);

        if ($turmasIds !== []) {
            if (usando_sqlite($pdo)) {
                $insertTurma = $pdo->prepare(
                    'INSERT INTO professor_turma (professor_id, turma_id)
                     VALUES (:professor_id, :turma_id)
                     ON CONFLICT(professor_id, turma_id) DO NOTHING'
                );
            } else {
                $insertTurma = $pdo->prepare(
                    'INSERT INTO professor_turma (professor_id, turma_id)
                     VALUES (:professor_id, :turma_id)
                     ON DUPLICATE KEY UPDATE professor_id = VALUES(professor_id)'
                );
            }

            foreach ($turmasIds as $turmaId) {
                $insertTurma->execute([
                    'professor_id' => $professorId,
                    'turma_id' => $turmaId,
                ]);
            }
        }

        if ($iniciouTransacao) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($iniciouTransacao && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function limpar_vinculos_professor_disciplina(PDO $pdo, int $professorId): void
{
    garantir_tabela_professor_disciplina($pdo);

    if ($professorId <= 0) {
        return;
    }

    $pdo->prepare('DELETE FROM professor_disciplina WHERE professor_id = :professor_id')->execute(['professor_id' => $professorId]);
    $pdo->prepare('DELETE FROM professor_turma WHERE professor_id = :professor_id')->execute(['professor_id' => $professorId]);
}

function mapa_disciplinas_por_professor(PDO $pdo): array
{
    garantir_tabela_professor_disciplina($pdo);

    $stmt = $pdo->query('SELECT professor_id, disciplina_id FROM professor_disciplina ORDER BY professor_id, disciplina_id');
    $mapa = [];

    foreach ($stmt->fetchAll() as $row) {
        $professorId = (int) $row['professor_id'];
        $disciplinaId = (int) $row['disciplina_id'];

        if (!isset($mapa[$professorId])) {
            $mapa[$professorId] = [];
        }

        $mapa[$professorId][] = $disciplinaId;
    }

    foreach ($mapa as $professorId => $ids) {
        $mapa[$professorId] = normalizar_ids_int($ids);
    }

    return $mapa;
}
