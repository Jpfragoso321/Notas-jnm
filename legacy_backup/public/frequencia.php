<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
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

garantir_tabelas_backlogs($pdo);
if ($ehProfessor) {
    garantir_tabela_professor_disciplina($pdo);
}

$turmaId = (int) ($_POST['turma_id'] ?? ($_GET['turma_id'] ?? 0));
$etapa = trim((string) ($_POST['etapa'] ?? ($_GET['etapa'] ?? '1_bimestre')));
$etapas = ['1_bimestre','2_bimestre','3_bimestre','4_bimestre'];
if (!in_array($etapa, $etapas, true)) {
    $etapa = '1_bimestre';
}

if ($ehGestao) {
    $turmasStmt = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY nome ASC');
    $turmas = $turmasStmt->fetchAll();
} else {
    $turmasStmt = $pdo->prepare(
        'SELECT t.id, t.nome, t.ano_letivo
         FROM professor_turma pt
         INNER JOIN turmas t ON t.id = pt.turma_id
         WHERE pt.professor_id = :professor_id
         ORDER BY t.nome ASC'
    );
    $turmasStmt->execute(['professor_id' => (int) ($usuario['id'] ?? 0)]);
    $turmas = $turmasStmt->fetchAll();
}

$turmasIds = array_map(static fn (array $t): int => (int) $t['id'], $turmas);
if ($turmaId <= 0 && $turmasIds) {
    $turmaId = $turmasIds[0];
}
if ($turmaId > 0 && !in_array($turmaId, $turmasIds, true)) {
    http_response_code(403);
    echo 'Acesso negado para esta turma.';
    exit;
}

$disciplinas = [];
$disciplinasPermitidasIds = [];
$alunos = [];
$resumoMensal = [];

if ($turmaId > 0) {
    if ($ehProfessor) {
        $stmtDisc = $pdo->prepare(
            'SELECT d.id, d.nome
             FROM professor_disciplina pd
             INNER JOIN disciplinas d ON d.id = pd.disciplina_id
             WHERE pd.professor_id = :professor_id
               AND d.turma_id = :turma_id
             ORDER BY d.nome ASC'
        );
        $stmtDisc->execute([
            'professor_id' => (int) ($usuario['id'] ?? 0),
            'turma_id' => $turmaId,
        ]);
    } else {
        $stmtDisc = $pdo->prepare('SELECT id, nome FROM disciplinas WHERE turma_id = :turma_id ORDER BY nome ASC');
        $stmtDisc->execute(['turma_id' => $turmaId]);
    }
    $disciplinas = $stmtDisc->fetchAll();
    $disciplinasPermitidasIds = array_map(static fn (array $d): int => (int) $d['id'], $disciplinas);

    if ($ehProfessor && $disciplinas === []) {
        http_response_code(403);
        echo 'Acesso negado: voce nao possui materias vinculadas nesta turma.';
        exit;
    }

    $stmtAlunos = $pdo->prepare('SELECT id, nome FROM alunos WHERE turma_id = :turma_id ORDER BY nome ASC');
    $stmtAlunos->execute(['turma_id' => $turmaId]);
    $alunos = $stmtAlunos->fetchAll();

    $stmtResumo = $pdo->prepare(
        'SELECT a.nome AS aluno_nome,
                SUM(CASE WHEN fr.status = "falta" THEN 1 ELSE 0 END) AS faltas,
                SUM(CASE WHEN fr.status = "justificada" THEN 1 ELSE 0 END) AS justificadas,
                SUM(CASE WHEN fr.status = "atraso" THEN 1 ELSE 0 END) AS atrasos,
                COUNT(fr.id) AS registros
         FROM frequencia_registros fr
         INNER JOIN frequencia_aulas fa ON fa.id = fr.aula_id
         INNER JOIN alunos a ON a.id = fr.aluno_id
         WHERE fa.turma_id = :turma_id
           AND substr(fa.data_aula, 1, 7) = :mes_ref
         GROUP BY a.id, a.nome
         ORDER BY a.nome ASC'
    );
    $stmtResumo->execute([
        'turma_id' => $turmaId,
        'mes_ref' => date('Y-m'),
    ]);
    $resumoMensal = $stmtResumo->fetchAll();
}

$msg = '';
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['acao'] ?? '') === 'salvar_frequencia') {
    $disciplinaId = (int) ($_POST['disciplina_id'] ?? 0);
    $dataAula = trim((string) ($_POST['data_aula'] ?? ''));
    $conteudo = trim((string) ($_POST['conteudo'] ?? ''));

    if ($turmaId <= 0 || $dataAula === '') {
        $erro = 'Selecione turma e data da aula.';
    } elseif ($ehProfessor && $disciplinaId > 0 && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
        $erro = 'Acesso negado para esta disciplina.';
    } elseif ($ehProfessor && $disciplinaId <= 0) {
        $erro = 'Professor deve selecionar uma disciplina vinculada para registrar chamada.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmtAula = $pdo->prepare(
                'INSERT INTO frequencia_aulas (turma_id, disciplina_id, etapa, data_aula, conteudo, criado_por)
                 VALUES (:turma_id, :disciplina_id, :etapa, :data_aula, :conteudo, :criado_por)'
            );
            $stmtAula->execute([
                'turma_id' => $turmaId,
                'disciplina_id' => $disciplinaId > 0 ? $disciplinaId : null,
                'etapa' => $etapa,
                'data_aula' => $dataAula,
                'conteudo' => $conteudo !== '' ? $conteudo : null,
                'criado_por' => (int) ($usuario['id'] ?? 0),
            ]);

            $aulaId = (int) $pdo->lastInsertId();

            if (usando_sqlite($pdo)) {
                $upsert = $pdo->prepare(
                    'INSERT INTO frequencia_registros (aula_id, aluno_id, status, observacao)
                     VALUES (:aula_id, :aluno_id, :status, :observacao)
                     ON CONFLICT(aula_id, aluno_id) DO UPDATE SET status = excluded.status, observacao = excluded.observacao'
                );
            } else {
                $upsert = $pdo->prepare(
                    'INSERT INTO frequencia_registros (aula_id, aluno_id, status, observacao)
                     VALUES (:aula_id, :aluno_id, :status, :observacao)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), observacao = VALUES(observacao)'
                );
            }

            $registros = (array) ($_POST['frequencia'] ?? []);
            foreach ($registros as $alunoId => $dado) {
                $aid = (int) $alunoId;
                if ($aid <= 0 || !is_array($dado)) {
                    continue;
                }

                $status = (string) ($dado['status'] ?? 'presente');
                if (!in_array($status, ['presente','falta','justificada','atraso'], true)) {
                    $status = 'presente';
                }

                $obs = trim((string) ($dado['obs'] ?? ''));
                $upsert->execute([
                    'aula_id' => $aulaId,
                    'aluno_id' => $aid,
                    'status' => $status,
                    'observacao' => $obs !== '' ? $obs : null,
                ]);
            }

            $pdo->commit();
            $msg = 'Frequencia salva com sucesso.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = 'Falha ao salvar frequencia.';
        }
    }
}

$linkPainel = $ehGestao ? '/manager' : '/dashboard';
$rotuloPainel = $ehGestao ? 'Dashboard manager' : 'Dashboard';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Frequencia por Aula - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Frequencia por aula</h1>
            <small>Chamada com relatorio mensal.</small>
        </div>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?php echo $linkPainel; ?>"><?php echo htmlspecialchars($rotuloPainel); ?></a>
            <a class="btn" href="/dashboard">Dashboard</a>
        </div>
    </div>

    <?php if ($msg): ?><p class="ok"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>

    <div class="card">
        <h2>Registrar chamada</h2>
        <form method="get" class="grid grid-3">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <?php foreach ($turmas as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $turmaId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $t['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Etapa</label>
                <select name="etapa">
                    <?php foreach ($etapas as $op): ?>
                        <option value="<?php echo $op; ?>" <?php echo $op === $etapa ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $op)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button type="submit">Aplicar</button></div>
        </form>

        <form method="post">
            <input type="hidden" name="acao" value="salvar_frequencia">
            <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
            <input type="hidden" name="etapa" value="<?php echo htmlspecialchars($etapa); ?>">
            <div class="grid grid-3" style="margin-top: 12px;">
                <div>
                    <label>Disciplina</label>
                    <select name="disciplina_id">
                        <option value="">(Opcional)</option>
                        <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo (int) $d['id']; ?>"><?php echo htmlspecialchars((string) $d['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Data da aula</label>
                    <input type="date" name="data_aula" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div>
                    <label>Conteudo</label>
                    <input name="conteudo" placeholder="Conteudo da aula">
                </div>
            </div>

            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <thead>
                    <tr>
                        <th>Aluno</th>
                        <th>Status</th>
                        <th>Observacao</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($alunos as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $a['nome']); ?></td>
                            <td>
                                <select name="frequencia[<?php echo (int) $a['id']; ?>][status]">
                                    <option value="presente">Presente</option>
                                    <option value="falta">Falta</option>
                                    <option value="justificada">Justificada</option>
                                    <option value="atraso">Atraso</option>
                                </select>
                            </td>
                            <td><input name="frequencia[<?php echo (int) $a['id']; ?>][obs]" placeholder="Opcional"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p style="margin-top:12px;"><button type="submit">Salvar chamada</button></p>
        </form>
    </div>

    <div class="card">
        <h2>Relatorio mensal (<?php echo date('m/Y'); ?>)</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Aluno</th>
                    <th>Registros</th>
                    <th>Faltas</th>
                    <th>Justificadas</th>
                    <th>Atrasos</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$resumoMensal): ?>
                    <tr><td colspan="5">Sem registros neste mes.</td></tr>
                <?php else: ?>
                    <?php foreach ($resumoMensal as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $r['aluno_nome']); ?></td>
                            <td><?php echo (int) $r['registros']; ?></td>
                            <td><?php echo (int) $r['faltas']; ?></td>
                            <td><?php echo (int) $r['justificadas']; ?></td>
                            <td><?php echo (int) $r['atrasos']; ?></td>
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
