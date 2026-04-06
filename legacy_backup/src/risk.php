<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabelas_risco(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
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
            "INSERT OR IGNORE INTO configuracao_risco (
                id,
                peso_media,
                peso_faltas,
                peso_atrasos,
                limiar_media,
                limiar_faltas_percentual,
                limiar_atrasos,
                limiar_score_moderado,
                limiar_score_alto
            ) VALUES (1, 0.60, 0.25, 0.15, 5.00, 15.00, 3, 40.00, 70.00)"
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

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS configuracao_risco (
            id TINYINT PRIMARY KEY,
            peso_media DECIMAL(5,2) NOT NULL DEFAULT 0.60,
            peso_faltas DECIMAL(5,2) NOT NULL DEFAULT 0.25,
            peso_atrasos DECIMAL(5,2) NOT NULL DEFAULT 0.15,
            limiar_media DECIMAL(4,2) NOT NULL DEFAULT 5.00,
            limiar_faltas_percentual DECIMAL(5,2) NOT NULL DEFAULT 15.00,
            limiar_atrasos INT NOT NULL DEFAULT 3,
            limiar_score_moderado DECIMAL(5,2) NOT NULL DEFAULT 40.00,
            limiar_score_alto DECIMAL(5,2) NOT NULL DEFAULT 70.00,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $pdo->exec(
        "INSERT INTO configuracao_risco (
            id,
            peso_media,
            peso_faltas,
            peso_atrasos,
            limiar_media,
            limiar_faltas_percentual,
            limiar_atrasos,
            limiar_score_moderado,
            limiar_score_alto
        ) VALUES (1, 0.60, 0.25, 0.15, 5.00, 15.00, 3, 40.00, 70.00)
        ON DUPLICATE KEY UPDATE id = id"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aluno_risco_indicadores (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            aluno_id INT NOT NULL,
            turma_id INT NOT NULL,
            etapa VARCHAR(30) NOT NULL,
            faltas_percentual DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            atrasos_qtd INT NOT NULL DEFAULT 0,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_aluno_risco (aluno_id, turma_id, etapa),
            CONSTRAINT fk_ari_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id),
            CONSTRAINT fk_ari_turma FOREIGN KEY (turma_id) REFERENCES turmas(id)
        )"
    );
}

function obter_config_risco(?PDO $pdo = null): array
{
    $pdo = $pdo instanceof PDO ? $pdo : db();

    try {
        garantir_tabelas_risco($pdo);

        $stmt = $pdo->query('SELECT * FROM configuracao_risco WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();

        if (!$row) {
            return config_risco_padrao();
        }

        return [
            'peso_media' => (float) $row['peso_media'],
            'peso_faltas' => (float) $row['peso_faltas'],
            'peso_atrasos' => (float) $row['peso_atrasos'],
            'limiar_media' => (float) $row['limiar_media'],
            'limiar_faltas_percentual' => (float) $row['limiar_faltas_percentual'],
            'limiar_atrasos' => (int) $row['limiar_atrasos'],
            'limiar_score_moderado' => (float) $row['limiar_score_moderado'],
            'limiar_score_alto' => (float) $row['limiar_score_alto'],
        ];
    } catch (Throwable $e) {
        return config_risco_padrao();
    }
}

function salvar_config_risco(PDO $pdo, array $dados): void
{
    garantir_tabelas_risco($pdo);

    $pesoMedia = (float) ($dados['peso_media'] ?? 0.60);
    $pesoFaltas = (float) ($dados['peso_faltas'] ?? 0.25);
    $pesoAtrasos = (float) ($dados['peso_atrasos'] ?? 0.15);
    $limiarMedia = (float) ($dados['limiar_media'] ?? 5.00);
    $limiarFaltas = (float) ($dados['limiar_faltas_percentual'] ?? 15.00);
    $limiarAtrasos = (int) ($dados['limiar_atrasos'] ?? 3);
    $limiarModerado = (float) ($dados['limiar_score_moderado'] ?? 40.00);
    $limiarAlto = (float) ($dados['limiar_score_alto'] ?? 70.00);

    if ($pesoMedia < 0 || $pesoFaltas < 0 || $pesoAtrasos < 0) {
        throw new RuntimeException('Pesos de risco nao podem ser negativos.');
    }

    if (($pesoMedia + $pesoFaltas + $pesoAtrasos) <= 0) {
        throw new RuntimeException('A soma dos pesos de risco deve ser maior que zero.');
    }

    if ($limiarMedia <= 0 || $limiarMedia > 10) {
        throw new RuntimeException('Limiar de media deve estar entre 0.01 e 10.');
    }

    if ($limiarFaltas < 0 || $limiarFaltas > 100) {
        throw new RuntimeException('Limiar de faltas deve estar entre 0 e 100.');
    }

    if ($limiarAtrasos < 0 || $limiarAtrasos > 100) {
        throw new RuntimeException('Limiar de atrasos deve estar entre 0 e 100.');
    }

    if ($limiarModerado < 0 || $limiarModerado > 100 || $limiarAlto < 0 || $limiarAlto > 100) {
        throw new RuntimeException('Limiares de score devem estar entre 0 e 100.');
    }

    if ($limiarModerado > $limiarAlto) {
        throw new RuntimeException('Limiar moderado nao pode ser maior que limiar alto.');
    }

    if (usando_sqlite($pdo)) {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracao_risco (
                id,
                peso_media,
                peso_faltas,
                peso_atrasos,
                limiar_media,
                limiar_faltas_percentual,
                limiar_atrasos,
                limiar_score_moderado,
                limiar_score_alto
            ) VALUES (
                1,
                :peso_media,
                :peso_faltas,
                :peso_atrasos,
                :limiar_media,
                :limiar_faltas_percentual,
                :limiar_atrasos,
                :limiar_score_moderado,
                :limiar_score_alto
            )
            ON CONFLICT(id) DO UPDATE SET
                peso_media = excluded.peso_media,
                peso_faltas = excluded.peso_faltas,
                peso_atrasos = excluded.peso_atrasos,
                limiar_media = excluded.limiar_media,
                limiar_faltas_percentual = excluded.limiar_faltas_percentual,
                limiar_atrasos = excluded.limiar_atrasos,
                limiar_score_moderado = excluded.limiar_score_moderado,
                limiar_score_alto = excluded.limiar_score_alto"
        );
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracao_risco (
                id,
                peso_media,
                peso_faltas,
                peso_atrasos,
                limiar_media,
                limiar_faltas_percentual,
                limiar_atrasos,
                limiar_score_moderado,
                limiar_score_alto
            ) VALUES (
                1,
                :peso_media,
                :peso_faltas,
                :peso_atrasos,
                :limiar_media,
                :limiar_faltas_percentual,
                :limiar_atrasos,
                :limiar_score_moderado,
                :limiar_score_alto
            )
            ON DUPLICATE KEY UPDATE
                peso_media = VALUES(peso_media),
                peso_faltas = VALUES(peso_faltas),
                peso_atrasos = VALUES(peso_atrasos),
                limiar_media = VALUES(limiar_media),
                limiar_faltas_percentual = VALUES(limiar_faltas_percentual),
                limiar_atrasos = VALUES(limiar_atrasos),
                limiar_score_moderado = VALUES(limiar_score_moderado),
                limiar_score_alto = VALUES(limiar_score_alto)"
        );
    }

    $stmt->execute([
        'peso_media' => $pesoMedia,
        'peso_faltas' => $pesoFaltas,
        'peso_atrasos' => $pesoAtrasos,
        'limiar_media' => $limiarMedia,
        'limiar_faltas_percentual' => $limiarFaltas,
        'limiar_atrasos' => $limiarAtrasos,
        'limiar_score_moderado' => $limiarModerado,
        'limiar_score_alto' => $limiarAlto,
    ]);
}

function calcular_risco_aluno(float $media, float $faltasPercentual, int $atrasosQtd, array $config): array
{
    $pm = max(0.0, (float) $config['peso_media']);
    $pf = max(0.0, (float) $config['peso_faltas']);
    $pa = max(0.0, (float) $config['peso_atrasos']);

    $limiarMedia = max(0.01, (float) $config['limiar_media']);
    $limiarFaltas = max(0.01, (float) $config['limiar_faltas_percentual']);
    $limiarAtrasos = max(1, (int) $config['limiar_atrasos']);
    $limiarModerado = max(0.0, min(100.0, (float) $config['limiar_score_moderado']));
    $limiarAlto = max(0.0, min(100.0, (float) $config['limiar_score_alto']));

    if ($limiarModerado > $limiarAlto) {
        $tmp = $limiarModerado;
        $limiarModerado = $limiarAlto;
        $limiarAlto = $tmp;
    }

    $media = max(0.0, min(10.0, $media));
    $faltasPercentual = max(0.0, min(100.0, $faltasPercentual));
    $atrasosQtd = max(0, $atrasosQtd);

    $riscoMedia = clamp_risco(((($limiarMedia - $media) / $limiarMedia) * 100.0));
    $riscoFaltas = clamp_risco((($faltasPercentual / $limiarFaltas) * 100.0));
    $riscoAtrasos = clamp_risco(((($atrasosQtd * 1.0) / $limiarAtrasos) * 100.0));

    $somaPesos = $pm + $pf + $pa;
    if ($somaPesos <= 0) {
        $score = 0.0;
    } else {
        $score = (($riscoMedia * $pm) + ($riscoFaltas * $pf) + ($riscoAtrasos * $pa)) / $somaPesos;
    }

    $score = round(clamp_risco($score), 2);

    $nivel = 'Baixo';
    if ($score >= $limiarAlto) {
        $nivel = 'Alto';
    } elseif ($score >= $limiarModerado) {
        $nivel = 'Moderado';
    }

    $gatilhoMedia = $media < $limiarMedia;
    $gatilhoFaltas = $faltasPercentual >= $limiarFaltas;
    $gatilhoAtrasos = $atrasosQtd >= $limiarAtrasos;

    $gatilhos = 0;
    $gatilhos += $gatilhoMedia ? 1 : 0;
    $gatilhos += $gatilhoFaltas ? 1 : 0;
    $gatilhos += $gatilhoAtrasos ? 1 : 0;

    if ($gatilhos >= 2 && $nivel !== 'Alto') {
        $nivel = 'Alto';
    } elseif ($gatilhos >= 1 && $nivel === 'Baixo') {
        $nivel = 'Moderado';
    }

    return [
        'score' => $score,
        'nivel' => $nivel,
        'componentes' => [
            'media' => round($riscoMedia, 2),
            'faltas' => round($riscoFaltas, 2),
            'atrasos' => round($riscoAtrasos, 2),
        ],
        'gatilhos' => [
            'media' => $gatilhoMedia,
            'faltas' => $gatilhoFaltas,
            'atrasos' => $gatilhoAtrasos,
        ],
    ];
}

function config_risco_padrao(): array
{
    return [
        'peso_media' => 0.60,
        'peso_faltas' => 0.25,
        'peso_atrasos' => 0.15,
        'limiar_media' => 5.00,
        'limiar_faltas_percentual' => 15.00,
        'limiar_atrasos' => 3,
        'limiar_score_moderado' => 40.00,
        'limiar_score_alto' => 70.00,
    ];
}

function clamp_risco(float $valor): float
{
    if ($valor < 0.0) {
        return 0.0;
    }

    if ($valor > 100.0) {
        return 100.0;
    }

    return $valor;
}