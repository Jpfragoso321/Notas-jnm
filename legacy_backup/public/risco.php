<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/risk.php';
require_once __DIR__ . '/../src/avaliacao_config.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';

exigir_login();

$pdo = db();
$usuario = usuario_logado();
$perfilAtual = perfil_usuario($usuario);
$ehProfessor = $perfilAtual === 'professor';

$etapas = ['1_bimestre', '2_bimestre', '3_bimestre', '4_bimestre'];
$turmaId = (int) ($_POST['turma_id'] ?? ($_GET['turma_id'] ?? 0));
$etapa = trim((string) ($_POST['etapa'] ?? ($_GET['etapa'] ?? '1_bimestre')));
$msg = '';
$erro = '';

if (!in_array($etapa, $etapas, true)) {
    $etapa = '1_bimestre';
}

$configRisco = obter_config_risco($pdo);
$configAvaliacao = obter_config_avaliacao($pdo);
$casasDecimais = (int) $configAvaliacao['casas_decimais'];
$stepNota = passo_nota($configAvaliacao);

if (usuario_eh_manager($usuario, true)) {
    $turmasStmt = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC');
    $turmasPermitidas = $turmasStmt->fetchAll();
} else {
    $turmasStmt = $pdo->prepare(
        'SELECT t.id, t.nome, t.ano_letivo
         FROM professor_turma pt
         INNER JOIN turmas t ON t.id = pt.turma_id
         WHERE pt.professor_id = :professor_id
         ORDER BY t.ano_letivo DESC, t.nome ASC'
    );
    $turmasStmt->execute(['professor_id' => (int) ($usuario['id'] ?? 0)]);
    $turmasPermitidas = $turmasStmt->fetchAll();
}

$turmaIds = array_map(static fn (array $turma): int => (int) $turma['id'], $turmasPermitidas);

if ($turmaId <= 0 && !empty($turmaIds)) {
    $turmaId = $turmaIds[0];
}

if ($turmaId > 0 && !in_array($turmaId, $turmaIds, true)) {
    http_response_code(403);
    echo 'Acesso negado para esta turma.';
    exit;
}

$disciplinasPermitidasIds = [];
if ($ehProfessor && $turmaId > 0) {
    garantir_tabela_professor_disciplina($pdo);

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['acao'] ?? '') === 'salvar_indicadores') {
    if ($turmaId <= 0) {
        $erro = 'Selecione uma turma valida para salvar os indicadores.';
    } else {
        $alunoIdsStmt = $pdo->prepare('SELECT id FROM alunos WHERE turma_id = :turma_id');
        $alunoIdsStmt->execute(['turma_id' => $turmaId]);
        $idsValidos = [];
        foreach ($alunoIdsStmt->fetchAll() as $linhaId) {
            $idsValidos[(int) $linhaId['id']] = true;
        }

        if (usando_sqlite($pdo)) {
            $upsert = $pdo->prepare(
                'INSERT INTO aluno_risco_indicadores (
                    aluno_id,
                    turma_id,
                    etapa,
                    faltas_percentual,
                    atrasos_qtd
                 ) VALUES (
                    :aluno_id,
                    :turma_id,
                    :etapa,
                    :faltas_percentual,
                    :atrasos_qtd
                 )
                 ON CONFLICT(aluno_id, turma_id, etapa) DO UPDATE SET
                    faltas_percentual = excluded.faltas_percentual,
                    atrasos_qtd = excluded.atrasos_qtd,
                    atualizado_em = CURRENT_TIMESTAMP'
            );
        } else {
            $upsert = $pdo->prepare(
                'INSERT INTO aluno_risco_indicadores (
                    aluno_id,
                    turma_id,
                    etapa,
                    faltas_percentual,
                    atrasos_qtd
                 ) VALUES (
                    :aluno_id,
                    :turma_id,
                    :etapa,
                    :faltas_percentual,
                    :atrasos_qtd
                 )
                 ON DUPLICATE KEY UPDATE
                    faltas_percentual = VALUES(faltas_percentual),
                    atrasos_qtd = VALUES(atrasos_qtd),
                    atualizado_em = CURRENT_TIMESTAMP'
            );
        }

        $indicadoresPost = $_POST['indicadores'] ?? [];
        $linhasSalvas = 0;
        $linhasInvalidas = 0;

        foreach ($indicadoresPost as $alunoId => $dados) {
            $alunoId = (int) $alunoId;
            if ($alunoId <= 0 || !isset($idsValidos[$alunoId]) || !is_array($dados)) {
                continue;
            }

            $faltasRaw = trim((string) ($dados['faltas_percentual'] ?? ''));
            $atrasosRaw = trim((string) ($dados['atrasos_qtd'] ?? ''));

            if ($faltasRaw === '' && $atrasosRaw === '') {
                continue;
            }

            $faltas = $faltasRaw === '' ? 0.0 : (float) str_replace(',', '.', $faltasRaw);
            $atrasos = $atrasosRaw === '' ? 0 : (int) $atrasosRaw;

            if ($faltas < 0 || $faltas > 100 || $atrasos < 0 || $atrasos > 100) {
                $linhasInvalidas++;
                continue;
            }

            $upsert->execute([
                'aluno_id' => $alunoId,
                'turma_id' => $turmaId,
                'etapa' => $etapa,
                'faltas_percentual' => $faltas,
                'atrasos_qtd' => $atrasos,
            ]);

            $linhasSalvas++;
        }

        if ($linhasInvalidas > 0) {
            $erro = 'Algumas linhas foram ignoradas. Verifique faltas (0 a 100) e atrasos (0 a 100).';
        } else {
            $msg = $linhasSalvas > 0
                ? 'Indicadores de risco salvos com sucesso.'
                : 'Nenhuma alteracao para salvar.';
        }
    }
}

$turma = null;
$alunos = [];
$resumo = ['Alto' => 0, 'Moderado' => 0, 'Baixo' => 0];

if ($turmaId > 0) {
    foreach ($turmasPermitidas as $turmaItem) {
        if ((int) $turmaItem['id'] === $turmaId) {
            $turma = $turmaItem;
            break;
        }
    }

    $sqlMedias = 'SELECT aluno_id, AVG(media) AS media_etapa
                  FROM notas
                  WHERE turma_id = :turma_id AND etapa = :etapa';
    $paramsMedias = ['turma_id' => $turmaId, 'etapa' => $etapa];

    if ($ehProfessor) {
        $sqlMedias .= ' AND disciplina_id IN (
                            SELECT d.id
                            FROM professor_disciplina pd
                            INNER JOIN disciplinas d ON d.id = pd.disciplina_id
                            WHERE pd.professor_id = :professor_id
                              AND d.turma_id = :turma_id_prof
                        )';
        $paramsMedias['professor_id'] = (int) ($usuario['id'] ?? 0);
        $paramsMedias['turma_id_prof'] = $turmaId;
    }

    $sqlMedias .= ' GROUP BY aluno_id';
    $mediasStmt = $pdo->prepare($sqlMedias);
    $mediasStmt->execute($paramsMedias);

    $mapaMedia = [];
    foreach ($mediasStmt->fetchAll() as $mediaLinha) {
        $mapaMedia[(int) $mediaLinha['aluno_id']] = (float) $mediaLinha['media_etapa'];
    }

    $alunosStmt = $pdo->prepare(
        'SELECT
            a.id,
            a.nome,
            COALESCE(ari.faltas_percentual, 0.00) AS faltas_percentual,
            COALESCE(ari.atrasos_qtd, 0) AS atrasos_qtd
         FROM alunos a
         LEFT JOIN aluno_risco_indicadores ari
             ON ari.aluno_id = a.id
            AND ari.turma_id = :turma_id_join
            AND ari.etapa = :etapa
         WHERE a.turma_id = :turma_id_where
         ORDER BY a.nome ASC'
    );
    $alunosStmt->execute([
        'turma_id_join' => $turmaId,
        'turma_id_where' => $turmaId,
        'etapa' => $etapa,
    ]);
    $alunos = $alunosStmt->fetchAll();

    foreach ($alunos as $index => $aluno) {
        $alunoId = (int) $aluno['id'];
        $mediaEtapa = $mapaMedia[$alunoId] ?? 0.0;
        $faltas = (float) $aluno['faltas_percentual'];
        $atrasos = (int) $aluno['atrasos_qtd'];
        $risco = calcular_risco_aluno($mediaEtapa, $faltas, $atrasos, $configRisco);

        $alunos[$index]['media_etapa'] = $mediaEtapa;
        $alunos[$index]['risco_score'] = (float) $risco['score'];
        $alunos[$index]['risco_nivel'] = (string) $risco['nivel'];
        $alunos[$index]['gatilho_media'] = $risco['gatilhos']['media'] ? 1 : 0;
        $alunos[$index]['gatilho_faltas'] = $risco['gatilhos']['faltas'] ? 1 : 0;
        $alunos[$index]['gatilho_atrasos'] = $risco['gatilhos']['atrasos'] ? 1 : 0;

        if (isset($resumo[$alunos[$index]['risco_nivel']])) {
            $resumo[$alunos[$index]['risco_nivel']]++;
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics de Risco - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
    <script>
        (function () {
            const turmaId = '<?php echo (int) $turmaId; ?>';
            const pendingKey = 'risco_scroll_pending_' + turmaId;
            if (window.sessionStorage && sessionStorage.getItem(pendingKey) === '1') {
                document.documentElement.classList.add('restore-scroll-pending');
            }
        })();
    </script>
</head>
<body class="app-page risco-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin: 0;">Analytics de Risco</h1>
            <small>Acompanhe alunos em risco por media, faltas e atrasos.</small>
        </div>
        <div class="actions-row">
            <?php if ($turmaId > 0): ?>
                <a class="btn btn-secondary btn-icon" data-icon="edit_note" href="/turma?turma_id=<?php echo $turmaId; ?>">Lancar notas</a>
            <?php endif; ?>
            <a class="btn btn-icon" data-icon="arrow_back" href="/dashboard">Voltar ao dashboard</a>
        </div>
    </div>

    <?php if ($ok = trim((string) ($_GET['ok'] ?? ''))): ?>
        <p class="ok"><?php echo htmlspecialchars($ok); ?></p>
    <?php endif; ?>
    <?php if ($erro): ?>
        <p class="error"><?php echo htmlspecialchars($erro); ?></p>
    <?php endif; ?>
    <?php if ($msg): ?>
        <p class="ok"><?php echo htmlspecialchars($msg); ?></p>
    <?php endif; ?>

    <div class="card">
        <h2>Filtros</h2>
        <form method="get" class="grid grid-3">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <?php foreach ($turmasPermitidas as $turmaOpcao): ?>
                        <option value="<?php echo (int) $turmaOpcao['id']; ?>" <?php echo (int) $turmaOpcao['id'] === $turmaId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($turmaOpcao['nome']); ?> (<?php echo (int) $turmaOpcao['ano_letivo']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Etapa</label>
                <select name="etapa" required>
                    <?php foreach ($etapas as $etapaOpcao): ?>
                        <option value="<?php echo htmlspecialchars($etapaOpcao); ?>" <?php echo $etapa === $etapaOpcao ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(str_replace('_', ' ', $etapaOpcao)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Aplicar filtros</button>
            </div>
        </form>
    </div>

    <?php if (!$turmasPermitidas): ?>
        <div class="card">
            <p>Nenhuma turma vinculada ao seu usuario.</p>
        </div>
    <?php elseif ($turmaId <= 0 || !$turma): ?>
        <div class="card">
            <p>Selecione uma turma para continuar.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Resumo de risco da turma <?php echo htmlspecialchars($turma['nome']); ?></h2>
            <div class="grid grid-3">
                <div class="risk-summary risk-high">
                    <strong>Alto</strong>
                    <div><?php echo (int) $resumo['Alto']; ?> aluno(s)</div>
                </div>
                <div class="risk-summary risk-moderate">
                    <strong>Moderado</strong>
                    <div><?php echo (int) $resumo['Moderado']; ?> aluno(s)</div>
                </div>
                <div class="risk-summary risk-low">
                    <strong>Baixo</strong>
                    <div><?php echo (int) $resumo['Baixo']; ?> aluno(s)</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Indicadores e calculo de risco</h2>
            <p><small>Media usa a media das disciplinas da etapa. Configure pesos e limiares no painel admin.</small></p>

            <form method="post">
                <input type="hidden" name="acao" value="salvar_indicadores">
                <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                <input type="hidden" name="etapa" value="<?php echo htmlspecialchars($etapa); ?>">

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Aluno</th>
                            <th>Media etapa</th>
                            <th>Faltas (%)</th>
                            <th>Atrasos</th>
                            <th>Score</th>
                            <th>Nivel</th>
                            <th>Gatilhos</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($alunos as $aluno): ?>
                            <?php
                            $gatilhos = [];
                            if ((int) $aluno['gatilho_media'] === 1) {
                                $gatilhos[] = 'Media';
                            }
                            if ((int) $aluno['gatilho_faltas'] === 1) {
                                $gatilhos[] = 'Faltas';
                            }
                            if ((int) $aluno['gatilho_atrasos'] === 1) {
                                $gatilhos[] = 'Atrasos';
                            }
                            $nivel = (string) $aluno['risco_nivel'];
                            $badgeClass = 'risk-badge risk-low';
                            if ($nivel === 'Alto') {
                                $badgeClass = 'risk-badge risk-high';
                            } elseif ($nivel === 'Moderado') {
                                $badgeClass = 'risk-badge risk-moderate';
                            }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $aluno['nome']); ?></td>
                                <td><?php echo htmlspecialchars(formatar_nota((float) $aluno['media_etapa'], $configAvaliacao)); ?></td>
                                <td>
                                    <input
                                        class="nota-input"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="<?php echo $stepNota; ?>"
                                        name="indicadores[<?php echo (int) $aluno['id']; ?>][faltas_percentual]"
                                        value="<?php echo htmlspecialchars(number_format((float) $aluno['faltas_percentual'], $casasDecimais, '.', '')); ?>"
                                    >
                                </td>
                                <td>
                                    <input
                                        class="nota-input"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="1"
                                        name="indicadores[<?php echo (int) $aluno['id']; ?>][atrasos_qtd]"
                                        value="<?php echo (int) $aluno['atrasos_qtd']; ?>"
                                    >
                                </td>
                                <td><?php echo htmlspecialchars(number_format((float) $aluno['risco_score'], 2, '.', '')); ?></td>
                                <td><span class="<?php echo $badgeClass; ?>"><?php echo htmlspecialchars($nivel); ?></span></td>
                                <td><?php echo $gatilhos ? htmlspecialchars(implode(', ', $gatilhos)) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p style="margin-top: 16px;">
                    <button type="submit">Salvar indicadores</button>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>
<script>
    (function () {
        const turmaId = '<?php echo (int) $turmaId; ?>';
        const scrollKey = 'risco_scroll_' + turmaId;
        const pendingKey = 'risco_scroll_pending_' + turmaId;

        const restore = () => {
            try {
                if (!window.sessionStorage) {
                    return;
                }

                if (sessionStorage.getItem(pendingKey) !== '1') {
                    document.documentElement.classList.remove('restore-scroll-pending');
                    return;
                }

                const raw = sessionStorage.getItem(scrollKey);
                const y = raw ? parseInt(raw, 10) : 0;
                if (!Number.isNaN(y) && y > 0) {
                    window.scrollTo(0, Math.max(0, y));
                }
            } catch (e) {
            } finally {
                try {
                    sessionStorage.removeItem(pendingKey);
                } catch (e) {
                }
                requestAnimationFrame(() => {
                    document.documentElement.classList.remove('restore-scroll-pending');
                });
            }
        };

        const formsPost = document.querySelectorAll('form[method="post"]');
        formsPost.forEach((form) => {
            form.addEventListener('submit', () => {
                try {
                    if (!window.sessionStorage) {
                        return;
                    }

                    sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
                    sessionStorage.setItem(pendingKey, '1');
                } catch (e) {
                }
            });
        });

        restore();
    })();
</script><footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>

</body>
</html>


