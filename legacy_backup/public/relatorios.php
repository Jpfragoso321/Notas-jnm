<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/avaliacao_config.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';

exigir_login();

$pdo = db();
$usuario = usuario_logado();
$perfilAtual = perfil_usuario($usuario);
$ehProfessor = $perfilAtual === 'professor';
$ehGestao = usuario_eh_manager($usuario, true);

if (!$ehGestao && !$ehProfessor) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

if ($ehProfessor) {
    garantir_tabela_professor_disciplina($pdo);
}

$etapa = trim((string) ($_GET['etapa'] ?? '1_bimestre'));
$etapas = ['1_bimestre','2_bimestre','3_bimestre','4_bimestre'];
if (!in_array($etapa, $etapas, true)) {
    $etapa = '1_bimestre';
}

$config = obter_config_avaliacao($pdo);
$notaCorte = 5.0;

$sql = 'SELECT
        t.id AS turma_id,
        t.nome AS turma_nome,
        d.nome AS disciplina_nome,
        COUNT(n.id) AS total_lancados,
        SUM(CASE WHEN n.media >= :nota_corte THEN 1 ELSE 0 END) AS aprovados,
        SUM(CASE WHEN n.media < :nota_corte THEN 1 ELSE 0 END) AS reprovados,
        AVG(n.media) AS media_turma
     FROM notas n
     INNER JOIN turmas t ON t.id = n.turma_id
     INNER JOIN disciplinas d ON d.id = n.disciplina_id
     WHERE n.etapa = :etapa';
$params = ['nota_corte' => $notaCorte, 'etapa' => $etapa];

if ($ehProfessor) {
    $sql .= ' AND EXISTS (
                SELECT 1
                FROM professor_turma pt
                WHERE pt.professor_id = :professor_id_turma
                  AND pt.turma_id = n.turma_id
             )
             AND EXISTS (
                SELECT 1
                FROM professor_disciplina pd
                WHERE pd.professor_id = :professor_id_disc
                  AND pd.disciplina_id = n.disciplina_id
             )';
    $params['professor_id_turma'] = (int) ($usuario['id'] ?? 0);
    $params['professor_id_disc'] = (int) ($usuario['id'] ?? 0);
}

$sql .= ' GROUP BY t.id, t.nome, d.nome
          ORDER BY t.nome ASC, d.nome ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$linhas = $stmt->fetchAll();

$linkPainel = $ehGestao ? '/manager' : '/dashboard';
$rotuloPainel = $ehGestao ? 'Dashboard manager' : 'Dashboard';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relatorios Gerenciais - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Relatorios gerenciais</h1>
            <small>Ola, <?php echo htmlspecialchars((string) $usuario['nome']); ?>. Etapa: <?php echo htmlspecialchars(str_replace('_', ' ', $etapa)); ?></small>
        </div>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?php echo $linkPainel; ?>"><?php echo htmlspecialchars($rotuloPainel); ?></a>
            <a class="btn" href="/dashboard">Dashboard</a>
        </div>
    </div>

    <div class="card">
        <h2>Filtros</h2>
        <form method="get" class="grid grid-3">
            <div>
                <label>Etapa</label>
                <select name="etapa">
                    <?php foreach ($etapas as $op): ?>
                        <option value="<?php echo $op; ?>" <?php echo $op === $etapa ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $op)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Aplicar</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Aprovados x Reprovados por turma/disciplina</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Turma</th>
                    <th>Disciplina</th>
                    <th>Total lancados</th>
                    <th>Aprovados</th>
                    <th>Reprovados</th>
                    <th>Media</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$linhas): ?>
                    <tr><td colspan="6">Sem dados para esta etapa.</td></tr>
                <?php else: ?>
                    <?php foreach ($linhas as $l): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $l['turma_nome']); ?></td>
                            <td><?php echo htmlspecialchars((string) $l['disciplina_nome']); ?></td>
                            <td><?php echo (int) $l['total_lancados']; ?></td>
                            <td><?php echo (int) $l['aprovados']; ?></td>
                            <td><?php echo (int) $l['reprovados']; ?></td>
                            <td><?php echo htmlspecialchars(formatar_nota((float) ($l['media_turma'] ?? 0), $config)); ?></td>
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
