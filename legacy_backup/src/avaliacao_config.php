<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function garantir_tabela_config_avaliacao(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS configuracao_avaliacao (
                id INTEGER PRIMARY KEY,
                modo_arredondamento TEXT NOT NULL DEFAULT 'comercial' CHECK (modo_arredondamento IN ('comercial', 'cima', 'baixo')),
                casas_decimais INTEGER NOT NULL DEFAULT 2,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec("INSERT OR IGNORE INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais) VALUES (1, 'comercial', 2)");
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS configuracao_avaliacao (
            id TINYINT PRIMARY KEY,
            modo_arredondamento ENUM('comercial', 'cima', 'baixo') NOT NULL DEFAULT 'comercial',
            casas_decimais TINYINT NOT NULL DEFAULT 2,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $pdo->exec(
        "INSERT INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais)
         VALUES (1, 'comercial', 2)
         ON DUPLICATE KEY UPDATE id = id"
    );
}

function obter_config_avaliacao(?PDO $pdo = null): array
{
    $pdo = $pdo instanceof PDO ? $pdo : db();

    try {
        garantir_tabela_config_avaliacao($pdo);

        $stmt = $pdo->query('SELECT modo_arredondamento, casas_decimais FROM configuracao_avaliacao WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();

        if (!$row) {
            return ['modo_arredondamento' => 'comercial', 'casas_decimais' => 2];
        }

        $modo = (string) ($row['modo_arredondamento'] ?? 'comercial');
        $casas = (int) ($row['casas_decimais'] ?? 2);

        if (!in_array($modo, ['comercial', 'cima', 'baixo'], true)) {
            $modo = 'comercial';
        }

        if ($casas < 0) {
            $casas = 0;
        }
        if ($casas > 2) {
            $casas = 2;
        }

        return [
            'modo_arredondamento' => $modo,
            'casas_decimais' => $casas,
        ];
    } catch (Throwable $e) {
        return ['modo_arredondamento' => 'comercial', 'casas_decimais' => 2];
    }
}

function salvar_config_avaliacao(PDO $pdo, string $modo, int $casas): void
{
    garantir_tabela_config_avaliacao($pdo);

    if (!in_array($modo, ['comercial', 'cima', 'baixo'], true)) {
        throw new RuntimeException('Modo de arredondamento invalido.');
    }

    if ($casas < 0 || $casas > 2) {
        throw new RuntimeException('Casas decimais devem ficar entre 0 e 2.');
    }

    if (usando_sqlite($pdo)) {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais)
             VALUES (1, :modo, :casas)
             ON CONFLICT(id) DO UPDATE SET
                modo_arredondamento = excluded.modo_arredondamento,
                casas_decimais = excluded.casas_decimais"
        );
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais)
             VALUES (1, :modo, :casas)
             ON DUPLICATE KEY UPDATE modo_arredondamento = VALUES(modo_arredondamento), casas_decimais = VALUES(casas_decimais)"
        );
    }

    $stmt->execute([
        'modo' => $modo,
        'casas' => $casas,
    ]);
}

function aplicar_arredondamento(float $valor, array $config): float
{
    $modo = (string) ($config['modo_arredondamento'] ?? 'comercial');
    $casas = (int) ($config['casas_decimais'] ?? 2);

    if ($casas < 0) {
        $casas = 0;
    }
    if ($casas > 2) {
        $casas = 2;
    }

    $fator = pow(10, $casas);

    if ($modo === 'cima') {
        return ceil($valor * $fator) / $fator;
    }

    if ($modo === 'baixo') {
        return floor($valor * $fator) / $fator;
    }

    return round($valor, $casas, PHP_ROUND_HALF_UP);
}

function formatar_nota(?float $valor, array $config): string
{
    if ($valor === null) {
        return '-';
    }

    $casas = (int) ($config['casas_decimais'] ?? 2);
    if ($casas < 0) {
        $casas = 0;
    }
    if ($casas > 2) {
        $casas = 2;
    }

    return number_format($valor, $casas, ',', '.');
}

function passo_nota(array $config): string
{
    $casas = (int) ($config['casas_decimais'] ?? 2);

    if ($casas <= 0) {
        return '1';
    }

    return '0.' . str_repeat('0', $casas - 1) . '1';
}