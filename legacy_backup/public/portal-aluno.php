<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/backlog_setup.php';
require_once __DIR__ . '/../src/avaliacao_config.php';

$pdo = db();
garantir_tabelas_backlogs($pdo);
garantir_acesso_portal_alunos($pdo);
$config = obter_config_avaliacao($pdo);

$codigo = trim((string) ($_POST['codigo_acesso'] ?? ''));
$pin = trim((string) ($_POST['pin_acesso'] ?? ''));
$erro = '';
$aluno = null;
$notas = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $stmt = $pdo->prepare(
        'SELECT a.id, a.nome, t.nome AS turma_nome, t.ano_letivo
         FROM aluno_acesso_portal ap
         INNER JOIN alunos a ON a.id = ap.aluno_id
         INNER JOIN turmas t ON t.id = a.turma_id
         WHERE ap.codigo_acesso = :codigo AND ap.pin_acesso = :pin
         LIMIT 1'
    );
    $stmt->execute([
        'codigo' => $codigo,
        'pin' => $pin,
    ]);
    $aluno = $stmt->fetch();

    if (!$aluno) {
        $erro = 'Codigo ou PIN invalido.';
    } else {
        $stmtNotas = $pdo->prepare(
            'SELECT d.nome AS disciplina_nome, n.etapa, n.media
             FROM notas n
             INNER JOIN disciplinas d ON d.id = n.disciplina_id
             WHERE n.aluno_id = :aluno_id
             ORDER BY d.nome ASC, n.etapa ASC'
        );
        $stmtNotas->execute(['aluno_id' => (int) $aluno['id']]);
        $notas = $stmtNotas->fetchAll();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel do Aluno - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="app-page login-page">
<main class="login-shell">
    <div class="login-orb login-orb-a" aria-hidden="true"></div>
    <div class="login-orb login-orb-b" aria-hidden="true"></div>

    <div class="login-card card" style="max-width: 920px;">
        <div class="login-brand">Portal ECMNM</div>
        <h1 style="margin: 6px 0 0;">Painel de responsavel/aluno</h1>
        <p class="login-subtitle">Acompanhe boletim e historico</p>

        <form method="post" class="grid grid-3">
            <div>
                <label>Codigo de acesso</label>
                <input name="codigo_acesso" value="<?php echo htmlspecialchars($codigo); ?>" required>
            </div>
            <div>
                <label>PIN</label>
                <input name="pin_acesso" type="password" value="<?php echo htmlspecialchars($pin); ?>" required>
            </div>
            <div><button type="submit">Entrar</button></div>
        </form>

        <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>

        <?php if ($aluno): ?>
            <hr style="margin: 18px 0; border-color: var(--border);">
            <p><strong>Aluno:</strong> <?php echo htmlspecialchars((string) $aluno['nome']); ?> | <strong>Turma:</strong> <?php echo htmlspecialchars((string) $aluno['turma_nome']); ?> | <strong>Ano:</strong> <?php echo (int) $aluno['ano_letivo']; ?></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Disciplina</th><th>Etapa</th><th>Media</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$notas): ?>
                        <tr><td colspan="4">Sem notas lancadas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($notas as $n): ?>
                            <?php $m = (float) ($n['media'] ?? 0); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $n['disciplina_nome']); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('_', ' ', (string) $n['etapa'])); ?></td>
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
</main>
<footer class="site-footer login-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>
