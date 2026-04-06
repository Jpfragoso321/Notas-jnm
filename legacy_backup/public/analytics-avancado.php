<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/risk.php';
require_once __DIR__ . '/../src/avaliacao_config.php';
require_once __DIR__ . '/../src/backlog_setup.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';

exigir_login();

$pdo = db();
$usuario = usuario_logado();
$perfilAtual = perfil_usuario($usuario);
$ehProfessor = $perfilAtual === 'professor';
$ehGestao = usuario_eh_manager($usuario, true);

if (!$ehGestao && !usuario_tem_permissao('notas.lancar', $usuario)) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

garantir_tabelas_risco($pdo);
garantir_tabelas_backlogs($pdo);
if ($ehProfessor) {
    garantir_tabela_professor_disciplina($pdo);
}
$config = obter_config_risco($pdo);
$configAvaliacao = obter_config_avaliacao($pdo);

$turmaId = (int) ($_GET['turma_id'] ?? 0);
$etapa = trim((string) ($_GET['etapa'] ?? '1_bimestre'));
$etapas = ['1_bimestre', '2_bimestre', '3_bimestre', '4_bimestre'];
if (!in_array($etapa, $etapas, true)) {
    $etapa = '1_bimestre';
}

if ($ehGestao) {
    $turmas = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC')->fetchAll();
} else {
    $stmtTurmas = $pdo->prepare(
        'SELECT t.id, t.nome, t.ano_letivo
         FROM professor_turma pt
         INNER JOIN turmas t ON t.id = pt.turma_id
         WHERE pt.professor_id = :professor_id
         ORDER BY t.ano_letivo DESC, t.nome ASC'
    );
    $stmtTurmas->execute(['professor_id' => (int) ($usuario['id'] ?? 0)]);
    $turmas = $stmtTurmas->fetchAll();
}

$turmasIds = array_map(static fn (array $t): int => (int) $t['id'], $turmas);
if ($turmaId <= 0 && $turmas) {
    $turmaId = (int) $turmas[0]['id'];
}
if ($turmaId > 0 && !in_array($turmaId, $turmasIds, true)) {
    http_response_code(403);
    echo 'Acesso negado para esta turma.';
    exit;
}

$etapaOrdem = array_flip($etapas);
$etapaAnterior = null;
if (isset($etapaOrdem[$etapa]) && $etapaOrdem[$etapa] > 0) {
    $etapaAnterior = $etapas[$etapaOrdem[$etapa] - 1];
}

$ranking = [];
$resumo = ['Baixo' => 0, 'Moderado' => 0, 'Alto' => 0];
$mediaScore = 0.0;
$turmaNome = '-';
foreach ($turmas as $turmaItem) {
    if ((int) ($turmaItem['id'] ?? 0) === $turmaId) {
        $turmaNome = (string) ($turmaItem['nome'] ?? '-');
        break;
    }
}

$disciplinasPermitidasIds = [];
if ($ehProfessor && $turmaId > 0) {
    $stmtPerm = $pdo->prepare(
        'SELECT d.id
         FROM professor_disciplina pd
         INNER JOIN disciplinas d ON d.id = pd.disciplina_id
         WHERE pd.professor_id = :professor_id
           AND d.turma_id = :turma_id'
    );
    $stmtPerm->execute([
        'professor_id' => (int) ($usuario['id'] ?? 0),
        'turma_id' => $turmaId,
    ]);
    $disciplinasPermitidasIds = array_map(static fn (array $r): int => (int) $r['id'], $stmtPerm->fetchAll());

    if ($disciplinasPermitidasIds === []) {
        http_response_code(403);
        echo 'Acesso negado: voce nao possui materias vinculadas nesta turma.';
        exit;
    }
}

if ($turmaId > 0) {
    $filtroNotasProfessor = '';
    $filtroFrequenciaProfessor = '';
    $params = [
        'turma_id_nota' => $turmaId,
        'etapa_nota' => $etapa,
        'turma_id_freq' => $turmaId,
        'etapa_freq' => $etapa,
        'turma_id_aluno' => $turmaId,
    ];

    if ($ehProfessor) {
        $filtroNotasProfessor = ' AND n.disciplina_id IN (
                SELECT d.id
                FROM professor_disciplina pd
                INNER JOIN disciplinas d ON d.id = pd.disciplina_id
                WHERE pd.professor_id = :professor_id_nota
                  AND d.turma_id = :turma_id_prof_nota
            )';
        $filtroFrequenciaProfessor = ' AND fa.disciplina_id IN (
                SELECT d.id
                FROM professor_disciplina pd
                INNER JOIN disciplinas d ON d.id = pd.disciplina_id
                WHERE pd.professor_id = :professor_id_freq
                  AND d.turma_id = :turma_id_prof_freq
            )';
        $params['professor_id_nota'] = (int) ($usuario['id'] ?? 0);
        $params['turma_id_prof_nota'] = $turmaId;
        $params['professor_id_freq'] = (int) ($usuario['id'] ?? 0);
        $params['turma_id_prof_freq'] = $turmaId;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id AS aluno_id, a.nome AS aluno_nome,
                COALESCE(AVG(n.media), 0) AS media_etapa,
                COALESCE(SUM(CASE WHEN fr.status = "falta" THEN 1 ELSE 0 END), 0) AS faltas,
                COALESCE(SUM(CASE WHEN fr.status = "atraso" THEN 1 ELSE 0 END), 0) AS atrasos,
                COUNT(fr.id) AS total_chamadas
         FROM alunos a
         LEFT JOIN notas n
            ON n.aluno_id = a.id AND n.turma_id = :turma_id_nota AND n.etapa = :etapa_nota' . $filtroNotasProfessor . '
         LEFT JOIN frequencia_registros fr ON fr.aluno_id = a.id
         LEFT JOIN frequencia_aulas fa
            ON fa.id = fr.aula_id AND fa.turma_id = :turma_id_freq AND fa.etapa = :etapa_freq' . $filtroFrequenciaProfessor . '
         WHERE a.turma_id = :turma_id_aluno
         GROUP BY a.id, a.nome
         ORDER BY a.nome ASC'
    );
    $stmt->execute($params);

    $mediasAnteriores = [];
    if ($etapaAnterior !== null) {
        $sqlAnterior = 'SELECT aluno_id, AVG(media) AS media_anterior
                        FROM notas
                        WHERE turma_id = :turma_id AND etapa = :etapa';
        $paramsAnterior = [
            'turma_id' => $turmaId,
            'etapa' => $etapaAnterior,
        ];

        if ($ehProfessor) {
            $sqlAnterior .= ' AND disciplina_id IN (
                                SELECT d.id
                                FROM professor_disciplina pd
                                INNER JOIN disciplinas d ON d.id = pd.disciplina_id
                                WHERE pd.professor_id = :professor_id
                                  AND d.turma_id = :turma_id_prof
                             )';
            $paramsAnterior['professor_id'] = (int) ($usuario['id'] ?? 0);
            $paramsAnterior['turma_id_prof'] = $turmaId;
        }

        $sqlAnterior .= ' GROUP BY aluno_id';
        $antStmt = $pdo->prepare($sqlAnterior);
        $antStmt->execute($paramsAnterior);
        foreach ($antStmt->fetchAll() as $linha) {
            $mediasAnteriores[(int) $linha['aluno_id']] = (float) ($linha['media_anterior'] ?? 0);
        }
    }

    foreach ($stmt->fetchAll() as $linha) {
        $media = (float) ($linha['media_etapa'] ?? 0);
        $faltas = (int) ($linha['faltas'] ?? 0);
        $atrasos = (int) ($linha['atrasos'] ?? 0);
        $totalChamadas = max(1, (int) ($linha['total_chamadas'] ?? 0));
        $faltasPercentual = ($faltas / $totalChamadas) * 100;

        $calculo = calcular_risco_aluno($media, $faltasPercentual, $atrasos, $config);
        $nivel = (string) ($calculo['nivel'] ?? 'Baixo');
        if (!isset($resumo[$nivel])) {
            $resumo[$nivel] = 0;
        }
        $resumo[$nivel]++;

        $alunoId = (int) $linha['aluno_id'];
        $mediaAnterior = $mediasAnteriores[$alunoId] ?? null;
        $tendencia = '-';
        if ($mediaAnterior !== null) {
            $delta = round($media - $mediaAnterior, 2);
            if ($delta > 0.09) {
                $tendencia = 'Melhorou +' . number_format($delta, 2, ',', '.');
            } elseif ($delta < -0.09) {
                $tendencia = 'Piorou ' . number_format($delta, 2, ',', '.');
            } else {
                $tendencia = 'Estavel';
            }
        }

        $score = (float) ($calculo['score'] ?? 0);
        $mediaScore += $score;
        $ranking[] = [
            'aluno_nome' => (string) $linha['aluno_nome'],
            'media' => $media,
            'faltas_percentual' => $faltasPercentual,
            'atrasos' => $atrasos,
            'score' => $score,
            'nivel' => $nivel,
            'tendencia' => $tendencia,
        ];
    }

    usort($ranking, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return strcmp((string) $a['aluno_nome'], (string) $b['aluno_nome']);
        }

        return $a['score'] < $b['score'] ? 1 : -1;
    });
}

$mediaScore = count($ranking) > 0 ? ($mediaScore / count($ranking)) : 0.0;
$topRisco = $ranking[0]['aluno_nome'] ?? '-';

$linkPainel = $ehGestao ? '/manager' : '/dashboard';
$rotuloPainel = $ehGestao ? 'Voltar' : 'Dashboard';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics Avancado - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page analytics-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Analytics avancado de risco</h1>
            <small>Turma <?php echo htmlspecialchars($turmaNome); ?> | Etapa <?php echo htmlspecialchars(str_replace('_', ' ', $etapa)); ?>.</small>
        </div>
        <div class="actions-row">
            <a class="btn btn-secondary btn-icon" data-icon="monitoring" href="/risco?turma_id=<?php echo $turmaId; ?>&etapa=<?php echo urlencode($etapa); ?>">Tela de risco</a>
            <a class="btn btn-icon" data-icon="arrow_back" href="<?php echo $linkPainel; ?>"><?php echo htmlspecialchars($rotuloPainel); ?></a>
        </div>
    </div>

    <div class="card analytics-filter-card">
        <h2>Filtros</h2>
        <form method="get" class="grid grid-3 analytics-filter-grid">
            <div>
                <label>Turma</label>
                <select name="turma_id">
                    <?php foreach ($turmas as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $turmaId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $t['nome']); ?> (<?php echo (int) $t['ano_letivo']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Etapa</label>
                <select name="etapa">
                    <?php foreach ($etapas as $e): ?>
                        <option value="<?php echo $e; ?>" <?php echo $e === $etapa ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $e)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="analytics-filter-action">
                <button type="submit" class="btn btn-icon" data-icon="tune">Aplicar</button>
            </div>
        </form>
    </div>

    <div class="analytics-kpis">
        <div class="analytics-kpi analytics-kpi-high">
            <span>Risco alto</span>
            <strong><?php echo (int) ($resumo['Alto'] ?? 0); ?></strong>
            <small>Necessita intervencao imediata</small>
        </div>
        <div class="analytics-kpi analytics-kpi-mid">
            <span>Risco moderado</span>
            <strong><?php echo (int) ($resumo['Moderado'] ?? 0); ?></strong>
            <small>Monitoramento continuo</small>
        </div>
        <div class="analytics-kpi analytics-kpi-low">
            <span>Risco baixo</span>
            <strong><?php echo (int) ($resumo['Baixo'] ?? 0); ?></strong>
            <small>Desempenho dentro do esperado</small>
        </div>
        <div class="analytics-kpi analytics-kpi-neutral">
            <span>Score medio</span>
            <strong><?php echo htmlspecialchars(number_format($mediaScore, 2, ',', '.')); ?></strong>
            <small>Media de risco da turma</small>
        </div>
        <div class="analytics-kpi analytics-kpi-neutral">
            <span>Maior alerta</span>
            <strong><?php echo htmlspecialchars((string) $topRisco); ?></strong>
            <small>Aluno no topo do ranking</small>
        </div>
    </div>

    <div class="card analytics-table-card">
        <h2>Ranking de risco</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Aluno</th>
                    <th>Score</th>
                    <th>Nivel</th>
                    <th>Media etapa</th>
                    <th>Faltas (%)</th>
                    <th>Atrasos</th>
                    <th>Tendencia</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$ranking): ?>
                    <tr><td colspan="8">Sem dados suficientes para a turma/etapa selecionada.</td></tr>
                <?php else: ?>
                    <?php foreach ($ranking as $idx => $r): ?>
                        <?php
                        $nivel = (string) $r['nivel'];
                        $classeNivel = 'status-pendente';
                        if ($nivel === 'Alto') {
                            $classeNivel = 'status-reprovado';
                        } elseif ($nivel === 'Moderado') {
                            $classeNivel = 'badge-warn';
                        } elseif ($nivel === 'Baixo') {
                            $classeNivel = 'status-aprovado';
                        }
                        ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><?php echo htmlspecialchars((string) $r['aluno_nome']); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float) $r['score'], 2, ',', '.')); ?></td>
                            <td><span class="status-badge <?php echo $classeNivel; ?>"><?php echo htmlspecialchars($nivel); ?></span></td>
                            <td><?php echo htmlspecialchars(formatar_nota((float) $r['media'], $configAvaliacao)); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float) $r['faltas_percentual'], 2, ',', '.')); ?></td>
                            <td><?php echo (int) $r['atrasos']; ?></td>
                            <td><?php echo htmlspecialchars((string) $r['tendencia']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>