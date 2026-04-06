<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/backlog_setup.php';

exigir_login();
exigir_manager();

$pdo = db();
$usuario = usuario_logado();
garantir_tabelas_backlogs($pdo);

$turmaId = (int) ($_POST['turma_id'] ?? ($_GET['turma_id'] ?? 0));
$etapa = trim((string) ($_POST['etapa'] ?? ($_GET['etapa'] ?? '1_bimestre')));
$etapas = ['1_bimestre','2_bimestre','3_bimestre','4_bimestre'];
if (!in_array($etapa, $etapas, true)) {
    $etapa = '1_bimestre';
}

$msg = '';
$erro = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['acao'] ?? '') === 'salvar_ata') {
    $titulo = trim((string) ($_POST['titulo'] ?? ''));
    $resumo = trim((string) ($_POST['resumo'] ?? ''));
    $decisoes = trim((string) ($_POST['decisoes'] ?? ''));

    if ($turmaId <= 0 || $titulo === '') {
        $erro = 'Informe turma e titulo da ata.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO conselho_atas (turma_id, etapa, titulo, resumo, decisoes, criado_por)
             VALUES (:turma_id, :etapa, :titulo, :resumo, :decisoes, :criado_por)'
        );
        $stmt->execute([
            'turma_id' => $turmaId,
            'etapa' => $etapa,
            'titulo' => $titulo,
            'resumo' => $resumo !== '' ? $resumo : null,
            'decisoes' => $decisoes !== '' ? $decisoes : null,
            'criado_por' => (int) ($usuario['id'] ?? 0),
        ]);
        $msg = 'Ata registrada com sucesso.';
    }
}

$turmas = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY nome ASC')->fetchAll();
$stmtList = $pdo->prepare(
    'SELECT c.id, c.titulo, c.etapa, c.resumo, c.decisoes, c.criado_em, t.nome AS turma_nome
     FROM conselho_atas c
     INNER JOIN turmas t ON t.id = c.turma_id
     WHERE (:turma_id = 0 OR c.turma_id = :turma_id)
       AND (:etapa = "" OR c.etapa = :etapa)
     ORDER BY c.id DESC'
);
$stmtList->execute([
    'turma_id' => $turmaId,
    'etapa' => $etapa,
]);
$atas = $stmtList->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conselho de Classe - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Conselho de classe</h1>
            <small>Atas e decisoes pedagogicas por turma/etapa.</small>
        </div>
        <div class="actions-row"><a class="btn" href="/manager">Voltar</a></div>
    </div>

    <?php if ($msg): ?><p class="ok"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>

    <div class="card">
        <h2>Nova ata</h2>
        <form method="post" class="grid grid-2">
            <input type="hidden" name="acao" value="salvar_ata">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <option value="">Selecione</option>
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
            <div class="grid-span-2">
                <label>Titulo</label>
                <input name="titulo" required>
            </div>
            <div class="grid-span-2">
                <label>Resumo</label>
                <textarea name="resumo" rows="3"></textarea>
            </div>
            <div class="grid-span-2">
                <label>Decisoes</label>
                <textarea name="decisoes" rows="4"></textarea>
            </div>
            <div><button type="submit">Salvar ata</button></div>
        </form>
    </div>

    <div class="card">
        <h2>Atas registradas</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Turma</th><th>Etapa</th><th>Titulo</th><th>Resumo</th><th>Decisoes</th></tr></thead>
                <tbody>
                <?php if (!$atas): ?>
                    <tr><td colspan="6">Nenhuma ata registrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($atas as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $a['criado_em']); ?></td>
                            <td><?php echo htmlspecialchars((string) $a['turma_nome']); ?></td>
                            <td><?php echo htmlspecialchars(str_replace('_', ' ', (string) $a['etapa'])); ?></td>
                            <td><?php echo htmlspecialchars((string) $a['titulo']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars((string) ($a['resumo'] ?? ''))); ?></td>
                            <td><?php echo nl2br(htmlspecialchars((string) ($a['decisoes'] ?? ''))); ?></td>
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
