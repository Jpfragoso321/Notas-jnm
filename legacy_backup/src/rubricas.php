<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabelas_rubricas(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rubricas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turma_id INTEGER NOT NULL,
                disciplina_id INTEGER NOT NULL,
                titulo TEXT NOT NULL,
                descricao TEXT NULL,
                criado_por INTEGER NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (turma_id) REFERENCES turmas(id),
                FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
                FOREIGN KEY (criado_por) REFERENCES usuarios(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rubrica_criterios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rubrica_id INTEGER NOT NULL,
                criterio TEXT NOT NULL,
                nivel_1 TEXT NOT NULL,
                nivel_2 TEXT NOT NULL,
                nivel_3 TEXT NOT NULL,
                nivel_4 TEXT NOT NULL,
                peso REAL NOT NULL DEFAULT 1.0,
                ordem INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (rubrica_id) REFERENCES rubricas(id)
            )"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rubricas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            turma_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            titulo VARCHAR(150) NOT NULL,
            descricao TEXT NULL,
            criado_por INT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_rub_turma FOREIGN KEY (turma_id) REFERENCES turmas(id),
            CONSTRAINT fk_rub_disciplina FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
            CONSTRAINT fk_rub_usuario FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rubrica_criterios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rubrica_id INT NOT NULL,
            criterio VARCHAR(180) NOT NULL,
            nivel_1 VARCHAR(255) NOT NULL,
            nivel_2 VARCHAR(255) NOT NULL,
            nivel_3 VARCHAR(255) NOT NULL,
            nivel_4 VARCHAR(255) NOT NULL,
            peso DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            ordem INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_rc_rubrica FOREIGN KEY (rubrica_id) REFERENCES rubricas(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}