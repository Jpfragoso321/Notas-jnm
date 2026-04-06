<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db_driver(): string
{
    return strtolower(DB_DRIVER) === 'sqlite' ? 'sqlite' : 'mysql';
}

function usando_sqlite(?PDO $pdo = null): bool
{
    if ($pdo instanceof PDO) {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    return db_driver() === 'sqlite';
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (db_driver() === 'sqlite') {
        $dbPath = SQLITE_PATH;
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        inicializar_sqlite_se_necessario($pdo);

        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function inicializar_sqlite_se_necessario(PDO $pdo): void
{
    if (!usando_sqlite($pdo)) {
        return;
    }

    $existe = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'usuarios' LIMIT 1")->fetch();
    if ($existe) {
        migrar_perfis_usuarios_sqlite($pdo);
        return;
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                usuario TEXT NOT NULL UNIQUE,
                senha TEXT NOT NULL,
                perfil TEXT NOT NULL DEFAULT 'professor' CHECK (perfil IN ('admin', 'professor', 'diretora', 'coordenacao_pedagogica', 'secretario')),
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS configuracao_avaliacao (
                id INTEGER PRIMARY KEY,
                modo_arredondamento TEXT NOT NULL DEFAULT 'comercial' CHECK (modo_arredondamento IN ('comercial', 'cima', 'baixo')),
                casas_decimais INTEGER NOT NULL DEFAULT 2,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS turmas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                ano_letivo INTEGER NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS professor_turma (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                professor_id INTEGER NOT NULL,
                turma_id INTEGER NOT NULL,
                UNIQUE (professor_id, turma_id),
                FOREIGN KEY (professor_id) REFERENCES usuarios(id),
                FOREIGN KEY (turma_id) REFERENCES turmas(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS alunos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turma_id INTEGER NOT NULL,
                nome TEXT NOT NULL,
                FOREIGN KEY (turma_id) REFERENCES turmas(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS disciplinas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turma_id INTEGER NOT NULL,
                nome TEXT NOT NULL,
                FOREIGN KEY (turma_id) REFERENCES turmas(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                aluno_id INTEGER NOT NULL,
                turma_id INTEGER NOT NULL,
                disciplina_id INTEGER NOT NULL,
                etapa TEXT NOT NULL,
                componente1 REAL NOT NULL DEFAULT 0.00,
                componente1_status TEXT NOT NULL DEFAULT 'normal' CHECK (componente1_status IN ('normal', 'dispensado')),
                componente2 REAL NOT NULL DEFAULT 0.00,
                componente2_status TEXT NOT NULL DEFAULT 'normal' CHECK (componente2_status IN ('normal', 'dispensado')),
                componente3 REAL NOT NULL DEFAULT 0.00,
                componente3_status TEXT NOT NULL DEFAULT 'normal' CHECK (componente3_status IN ('normal', 'dispensado')),
                componente4 REAL NOT NULL DEFAULT 0.00,
                componente4_status TEXT NOT NULL DEFAULT 'normal' CHECK (componente4_status IN ('normal', 'dispensado')),
                componente5 REAL NOT NULL DEFAULT 0.00,
                componente5_status TEXT NOT NULL DEFAULT 'normal' CHECK (componente5_status IN ('normal', 'dispensado')),
                media_base REAL NOT NULL DEFAULT 0.00,
                peso_diferenciado REAL NOT NULL DEFAULT 1.00,
                justificativa_peso TEXT NULL,
                media_ajustada_peso REAL NOT NULL DEFAULT 0.00,
                recuperacao_nota REAL NULL,
                recuperacao_aplicada INTEGER NOT NULL DEFAULT 0,
                media REAL NOT NULL DEFAULT 0.00,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (aluno_id, turma_id, disciplina_id, etapa),
                FOREIGN KEY (aluno_id) REFERENCES alunos(id),
                FOREIGN KEY (turma_id) REFERENCES turmas(id),
                FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id)
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS configuracao_risco (
                id INTEGER PRIMARY KEY,
                peso_media REAL NOT NULL DEFAULT 0.60,
                peso_faltas REAL NOT NULL DEFAULT 0.25,
                peso_atrasos REAL NOT NULL DEFAULT 0.15,
                limiar_media REAL NOT NULL DEFAULT 5.00,
                limiar_faltas_percentual REAL NOT NULL DEFAULT 15.00,
                limiar_atrasos INTEGER NOT NULL DEFAULT 3,
                limiar_score_moderado REAL NOT NULL DEFAULT 40.00,
                limiar_score_alto REAL NOT NULL DEFAULT 70.00,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS aluno_risco_indicadores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                aluno_id INTEGER NOT NULL,
                turma_id INTEGER NOT NULL,
                etapa TEXT NOT NULL,
                faltas_percentual REAL NOT NULL DEFAULT 0.00,
                atrasos_qtd INTEGER NOT NULL DEFAULT 0,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (aluno_id, turma_id, etapa),
                FOREIGN KEY (aluno_id) REFERENCES alunos(id),
                FOREIGN KEY (turma_id) REFERENCES turmas(id)
            )"
        );

        $pdo->exec("INSERT OR IGNORE INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais) VALUES (1, 'comercial', 2)");
        $pdo->exec("INSERT OR IGNORE INTO configuracao_risco (id, peso_media, peso_faltas, peso_atrasos, limiar_media, limiar_faltas_percentual, limiar_atrasos, limiar_score_moderado, limiar_score_alto) VALUES (1, 0.60, 0.25, 0.15, 5.00, 15.00, 3, 40.00, 70.00)");

        $pdo->exec("INSERT OR IGNORE INTO usuarios (nome, usuario, senha, perfil) VALUES ('Administrador', 'joaopaulofragoso7@gmail.com', '@Caca060405@', 'admin')");
        $pdo->exec("INSERT OR IGNORE INTO usuarios (nome, usuario, senha, perfil) VALUES ('Joao Professor', 'prof.joao', '123456', 'professor')");

        $pdo->exec("INSERT OR IGNORE INTO turmas (id, nome, ano_letivo) VALUES (1, '6A', 2026)");
        $pdo->exec("INSERT OR IGNORE INTO turmas (id, nome, ano_letivo) VALUES (2, '7B', 2026)");

        $pdo->exec("INSERT OR IGNORE INTO professor_turma (professor_id, turma_id) SELECT id, 1 FROM usuarios WHERE usuario = 'prof.joao'");
        $pdo->exec("INSERT OR IGNORE INTO professor_turma (professor_id, turma_id) SELECT id, 2 FROM usuarios WHERE usuario = 'prof.joao'");

        $pdo->exec("INSERT OR IGNORE INTO disciplinas (turma_id, nome) VALUES (1, 'Matematica')");
        $pdo->exec("INSERT OR IGNORE INTO disciplinas (turma_id, nome) VALUES (1, 'Portugues')");
        $pdo->exec("INSERT OR IGNORE INTO disciplinas (turma_id, nome) VALUES (2, 'Matematica')");
        $pdo->exec("INSERT OR IGNORE INTO disciplinas (turma_id, nome) VALUES (2, 'Ciencias')");

        $pdo->exec("INSERT OR IGNORE INTO alunos (turma_id, nome) VALUES (1, 'Ana Silva')");
        $pdo->exec("INSERT OR IGNORE INTO alunos (turma_id, nome) VALUES (1, 'Bruno Costa')");
        $pdo->exec("INSERT OR IGNORE INTO alunos (turma_id, nome) VALUES (1, 'Carla Lima')");
        $pdo->exec("INSERT OR IGNORE INTO alunos (turma_id, nome) VALUES (2, 'Daniel Rocha')");
        $pdo->exec("INSERT OR IGNORE INTO alunos (turma_id, nome) VALUES (2, 'Elaine Souza')");
        $pdo->exec("INSERT OR IGNORE INTO alunos (turma_id, nome) VALUES (2, 'Felipe Santos')");

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function migrar_perfis_usuarios_sqlite(PDO $pdo): void
{
    if (!usando_sqlite($pdo)) {
        return;
    }

    $sqlTabela = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'usuarios' LIMIT 1")->fetchColumn();
    if (!is_string($sqlTabela) || $sqlTabela === '') {
        return;
    }

    if (
        strpos($sqlTabela, 'diretora') !== false
        && strpos($sqlTabela, 'coordenacao_pedagogica') !== false
        && strpos($sqlTabela, 'secretario') !== false
    ) {
        return;
    }

    try {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();

        $pdo->exec(
            "CREATE TABLE usuarios_novo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                usuario TEXT NOT NULL UNIQUE,
                senha TEXT NOT NULL,
                perfil TEXT NOT NULL DEFAULT 'professor' CHECK (perfil IN ('admin', 'professor', 'diretora', 'coordenacao_pedagogica', 'secretario')),
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "INSERT INTO usuarios_novo (id, nome, usuario, senha, perfil, criado_em)
             SELECT id, nome, usuario, senha, perfil, criado_em
             FROM usuarios"
        );

        $pdo->exec('DROP TABLE usuarios');
        $pdo->exec('ALTER TABLE usuarios_novo RENAME TO usuarios');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}