<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/avaliacao_config.php';

exigir_login();

$pdo = db();
$usuario = usuario_logado();
$ehGestao = usuario_eh_manager($usuario) || usuario_eh_admin($usuario);

$alunoId = (int) ($_GET['aluno_id'] ?? 0);
$turmaId = (int) ($_GET['turma_id'] ?? 0);
$config = obter_config_avaliacao($pdo);

$turmas = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC')->fetchAll();
$alunos = [];
if ($turmaId > 0) {
    $stmt = $pdo->prepare('SELECT id, nome FROM alunos WHERE turma_id = :turma_id ORDER BY nome ASC');
    $stmt->execute(['turma_id' => $turmaId]);
    $alunos = $stmt->fetchAll();
}

$historico = [];
$alunoNome = '';
if ($alunoId > 0) {
    $stmtAluno = $pdo->prepare('SELECT a.nome, t.nome AS turma_nome, t.ano_letivo FROM alunos a INNER JOIN turmas t ON t.id = a.turma_id WHERE a.id = :id LIMIT 1');
    $stmtAluno->execute(['id' => $alunoId]);
    $rowAluno = $stmtAluno->fetch();
    if ($rowAluno) {
        $alunoNome = (string) $rowAluno['nome'];
    }

    $stmtHist = $pdo->prepare(
        'SELECT n.etapa, d.nome AS disciplina_nome, n.media
         FROM notas n
         INNER JOIN disciplinas d ON d.id = n.disciplina_id
         WHERE n.aluno_id = :aluno_id
         ORDER BY d.nome ASC, n.etapa ASC'
    );
    $stmtHist->execute(['aluno_id' => $alunoId]);
    $historico = $stmtHist->fetchAll();
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historico Anual - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Historico anual do aluno</h1>
            <small>Boletim consolidado por disciplina e etapa.</small>
        </div>
        <div class="actions-row">
            <a class="btn" href="/dashboard">Voltar</a>
        </div>
    </div>

    <div class="card">
        <h2>Selecionar aluno</h2>
        <form method="get" class="grid grid-3">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $turmaId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $t['nome']); ?> (<?php echo (int) $t['ano_letivo']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Aluno</label>
                <select name="aluno_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($alunos as $a): ?>
                        <option value="<?php echo (int) $a['id']; ?>" <?php echo (int) $a['id'] === $alunoId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $a['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Ver historico</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Resultado</h2>
        <?php if ($alunoNome === ''): ?>
            <p>Selecione um aluno para visualizar o historico.</p>
        <?php else: ?>
            <p><strong>Aluno:</strong> <?php echo htmlspecialchars($alunoNome); ?></p>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Disciplina</th>
                        <th>Etapa</th>
                        <th>Media</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$historico): ?>
                        <tr><td colspan="4">Sem notas registradas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historico as $h): ?>
                            <?php $m = (float) ($h['media'] ?? 0); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $h['disciplina_nome']); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('_', ' ', (string) $h['etapa'])); ?></td>
                                <td><?php echo htmlspecialchars(formatar_nota($m, $config)); ?></td>
                                <td><?php echo $m >= 5 ? 'Aprovado' : 'Reprovado'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>
