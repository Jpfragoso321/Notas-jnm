<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function etapas_avaliacao_padrao(): array
{
    return [
        '1_bimestre' => ['nome' => '1 bimestre', 'ordem' => 1],
        '2_bimestre' => ['nome' => '2 bimestre', 'ordem' => 2],
        '3_bimestre' => ['nome' => '3 bimestre', 'ordem' => 3],
        '4_bimestre' => ['nome' => '4 bimestre', 'ordem' => 4],
    ];
}

function faixas_periodo_ano(int $ano): array
{
    return [
        '1_bimestre' => ['abertura' => sprintf('%04d-02-01', $ano), 'fechamento' => sprintf('%04d-04-30', $ano)],
        '2_bimestre' => ['abertura' => sprintf('%04d-05-01', $ano), 'fechamento' => sprintf('%04d-06-30', $ano)],
        '3_bimestre' => ['abertura' => sprintf('%04d-08-01', $ano), 'fechamento' => sprintf('%04d-09-30', $ano)],
        '4_bimestre' => ['abertura' => sprintf('%04d-10-01', $ano), 'fechamento' => sprintf('%04d-12-15', $ano)],
    ];
}

function garantir_periodos_avaliacao(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS periodos_avaliacao (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                codigo TEXT NOT NULL UNIQUE,
                nome TEXT NOT NULL,
                ordem INTEGER NOT NULL,
                data_abertura TEXT NOT NULL,
                data_fechamento TEXT NOT NULL,
                ativo INTEGER NOT NULL DEFAULT 1,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS periodos_avaliacao (
                id INT AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(50) NOT NULL UNIQUE,
                nome VARCHAR(100) NOT NULL,
                ordem INT NOT NULL,
                data_abertura DATE NOT NULL,
                data_fechamento DATE NOT NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    $quantidade = (int) $pdo->query('SELECT COUNT(1) FROM periodos_avaliacao')->fetchColumn();
    if ($quantidade > 0) {
        return;
    }

    $ano = (int) date('Y');
    $faixas = faixas_periodo_ano($ano);
    $padrao = etapas_avaliacao_padrao();

    $insert = $pdo->prepare(
        'INSERT INTO periodos_avaliacao (codigo, nome, ordem, data_abertura, data_fechamento, ativo)
         VALUES (:codigo, :nome, :ordem, :data_abertura, :data_fechamento, :ativo)'
    );

    foreach ($padrao as $codigo => $meta) {
        $insert->execute([
            'codigo' => $codigo,
            'nome' => $meta['nome'],
            'ordem' => $meta['ordem'],
            'data_abertura' => $faixas[$codigo]['abertura'],
            'data_fechamento' => $faixas[$codigo]['fechamento'],
            'ativo' => 1,
        ]);
    }
}

function obter_periodos_avaliacao(PDO $pdo): array
{
    garantir_periodos_avaliacao($pdo);

    $stmt = $pdo->query(
        'SELECT id, codigo, nome, ordem, data_abertura, data_fechamento, ativo
         FROM periodos_avaliacao
         ORDER BY ordem ASC, id ASC'
    );

    $saida = [];
    foreach ($stmt->fetchAll() as $linha) {
        $saida[(string) $linha['codigo']] = [
            'id' => (int) $linha['id'],
            'codigo' => (string) $linha['codigo'],
            'nome' => (string) $linha['nome'],
            'ordem' => (int) $linha['ordem'],
            'data_abertura' => (string) $linha['data_abertura'],
            'data_fechamento' => (string) $linha['data_fechamento'],
            'ativo' => ((int) $linha['ativo']) === 1 ? 1 : 0,
        ];
    }

    return $saida;
}

function salvar_periodos_avaliacao(PDO $pdo, array $payload): void
{
    garantir_periodos_avaliacao($pdo);
    $periodos = obter_periodos_avaliacao($pdo);

    $update = $pdo->prepare(
        'UPDATE periodos_avaliacao
         SET data_abertura = :data_abertura,
             data_fechamento = :data_fechamento,
             ativo = :ativo
         WHERE id = :id'
    );

    foreach ($periodos as $codigo => $periodo) {
        $dados = $payload[$codigo] ?? [];
        $abertura = trim((string) ($dados['data_abertura'] ?? $periodo['data_abertura']));
        $fechamento = trim((string) ($dados['data_fechamento'] ?? $periodo['data_fechamento']));
        $ativo = isset($dados['ativo']) ? 1 : 0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $abertura) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechamento)) {
            throw new RuntimeException('Datas invalidas nos periodos de avaliacao.');
        }

        if (strcmp($abertura, $fechamento) > 0) {
            throw new RuntimeException('A data de abertura deve ser menor ou igual a data de fechamento.');
        }

        $update->execute([
            'id' => $periodo['id'],
            'data_abertura' => $abertura,
            'data_fechamento' => $fechamento,
            'ativo' => $ativo,
        ]);
    }
}

function status_periodo_avaliacao(array $periodos, string $codigo, ?DateTimeImmutable $agora = null): array
{
    $agora = $agora ?? new DateTimeImmutable('now');
    $hoje = $agora->format('Y-m-d');

    $periodo = $periodos[$codigo] ?? null;
    if (!is_array($periodo)) {
        return [
            'encontrado' => false,
            'aberto' => false,
            'motivo' => 'Periodo nao configurado.',
            'periodo' => null,
        ];
    }

    if ((int) $periodo['ativo'] !== 1) {
        return [
            'encontrado' => true,
            'aberto' => false,
            'motivo' => 'Periodo inativo.',
            'periodo' => $periodo,
        ];
    }

    $abertura = (string) $periodo['data_abertura'];
    $fechamento = (string) $periodo['data_fechamento'];
    $aberto = strcmp($hoje, $abertura) >= 0 && strcmp($hoje, $fechamento) <= 0;

    return [
        'encontrado' => true,
        'aberto' => $aberto,
        'motivo' => $aberto
            ? 'Periodo aberto para lancamento.'
            : 'Periodo fora da janela de lancamento.',
        'periodo' => $periodo,
    ];
}