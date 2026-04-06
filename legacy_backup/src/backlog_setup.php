<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabelas_backlogs(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS frequencia_aulas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turma_id INTEGER NOT NULL,
                disciplina_id INTEGER NULL,
                etapa TEXT NOT NULL,
                data_aula TEXT NOT NULL,
                conteudo TEXT NULL,
                criado_por INTEGER NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (turma_id) REFERENCES turmas(id),
                FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
                FOREIGN KEY (criado_por) REFERENCES usuarios(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS frequencia_registros (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                aula_id INTEGER NOT NULL,
                aluno_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'presente' CHECK (status IN ('presente','falta','justificada','atraso')),
                observacao TEXT NULL,
                UNIQUE (aula_id, aluno_id),
                FOREIGN KEY (aula_id) REFERENCES frequencia_aulas(id),
                FOREIGN KEY (aluno_id) REFERENCES alunos(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS conselho_atas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turma_id INTEGER NOT NULL,
                etapa TEXT NOT NULL,
                titulo TEXT NOT NULL,
                resumo TEXT NULL,
                decisoes TEXT NULL,
                criado_por INTEGER NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (turma_id) REFERENCES turmas(id),
                FOREIGN KEY (criado_por) REFERENCES usuarios(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notificacoes_internas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                titulo TEXT NOT NULL,
                mensagem TEXT NOT NULL,
                perfil_destino TEXT NULL,
                usuario_destino_id INTEGER NULL,
                criado_por INTEGER NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (usuario_destino_id) REFERENCES usuarios(id),
                FOREIGN KEY (criado_por) REFERENCES usuarios(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notificacoes_lidas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                notificacao_id INTEGER NOT NULL,
                usuario_id INTEGER NOT NULL,
                lida_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (notificacao_id, usuario_id),
                FOREIGN KEY (notificacao_id) REFERENCES notificacoes_internas(id),
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS aluno_acesso_portal (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                aluno_id INTEGER NOT NULL,
                codigo_acesso TEXT NOT NULL UNIQUE,
                pin_acesso TEXT NOT NULL,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (aluno_id) REFERENCES alunos(id)
            )"
        );

        // Higieniza dados antigos e garante unicidade por aluno para permitir upsert por aluno_id.
        $pdo->exec(
            "DELETE FROM aluno_acesso_portal
             WHERE id NOT IN (
                SELECT MIN(id)
                FROM aluno_acesso_portal
                GROUP BY aluno_id
             )"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_aluno_acesso_portal_aluno
             ON aluno_acesso_portal(aluno_id)"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS frequencia_aulas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            turma_id INT NOT NULL,
            disciplina_id INT NULL,
            etapa VARCHAR(40) NOT NULL,
            data_aula DATE NOT NULL,
            conteudo TEXT NULL,
            criado_por INT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_fa_turma FOREIGN KEY (turma_id) REFERENCES turmas(id),
            CONSTRAINT fk_fa_disciplina FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
            CONSTRAINT fk_fa_usuario FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS frequencia_registros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            aula_id INT NOT NULL,
            aluno_id INT NOT NULL,
            status ENUM('presente','falta','justificada','atraso') NOT NULL DEFAULT 'presente',
            observacao TEXT NULL,
            UNIQUE KEY uq_fr_aula_aluno (aula_id, aluno_id),
            CONSTRAINT fk_fr_aula FOREIGN KEY (aula_id) REFERENCES frequencia_aulas(id),
            CONSTRAINT fk_fr_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS conselho_atas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            turma_id INT NOT NULL,
            etapa VARCHAR(40) NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            resumo TEXT NULL,
            decisoes TEXT NULL,
            criado_por INT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ca_turma FOREIGN KEY (turma_id) REFERENCES turmas(id),
            CONSTRAINT fk_ca_usuario FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notificacoes_internas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(255) NOT NULL,
            mensagem TEXT NOT NULL,
            perfil_destino VARCHAR(50) NULL,
            usuario_destino_id INT NULL,
            criado_por INT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ni_usuario_destino FOREIGN KEY (usuario_destino_id) REFERENCES usuarios(id),
            CONSTRAINT fk_ni_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notificacoes_lidas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notificacao_id INT NOT NULL,
            usuario_id INT NOT NULL,
            lida_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_nl_notif_usuario (notificacao_id, usuario_id),
            CONSTRAINT fk_nl_notificacao FOREIGN KEY (notificacao_id) REFERENCES notificacoes_internas(id),
            CONSTRAINT fk_nl_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aluno_acesso_portal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            aluno_id INT NOT NULL,
            codigo_acesso VARCHAR(60) NOT NULL UNIQUE,
            pin_acesso VARCHAR(20) NOT NULL,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_aluno_acesso_portal_aluno (aluno_id),
            CONSTRAINT fk_ap_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function gerar_codigo_aluno_acesso(int $alunoId): string
{
    return 'AL' . str_pad((string) $alunoId, 5, '0', STR_PAD_LEFT);
}

function garantir_acesso_portal_alunos(PDO $pdo): void
{
    garantir_tabelas_backlogs($pdo);

    $alunos = $pdo->query('SELECT id FROM alunos')->fetchAll();
    if (!$alunos) {
        return;
    }

    if (usando_sqlite($pdo)) {
        $insert = $pdo->prepare(
            'INSERT INTO aluno_acesso_portal (aluno_id, codigo_acesso, pin_acesso)
             VALUES (:aluno_id, :codigo_acesso, :pin_acesso)
             ON CONFLICT(aluno_id) DO NOTHING'
        );
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO aluno_acesso_portal (aluno_id, codigo_acesso, pin_acesso)
             VALUES (:aluno_id, :codigo_acesso, :pin_acesso)
             ON DUPLICATE KEY UPDATE aluno_id = VALUES(aluno_id)'
        );
    }

    foreach ($alunos as $aluno) {
        $id = (int) $aluno['id'];
        $insert->execute([
            'aluno_id' => $id,
            'codigo_acesso' => gerar_codigo_aluno_acesso($id),
            'pin_acesso' => '1234',
        ]);
    }
}
