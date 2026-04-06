<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/auditoria.php';

exigir_login();
exigir_manager();

$pdo = db();
$usuario = usuario_logado();

$limite = max(20, min(300, (int) ($_GET['limite'] ?? 120)));
$eventos = listar_auditoria($pdo, $limite);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditoria Avancada - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Auditoria de notas</h1>
            <small>Quem alterou, quando e detalhes antes/depois.</small>
        </div>
        <div class="actions-row"><a class="btn" href="/manager">Voltar</a></div>
    </div>

    <div class="card">
        <h2>Eventos</h2>
        <form method="get" class="grid grid-2">
            <div>
                <label>Limite</label>
                <input type="number" name="limite" min="20" max="300" value="<?php echo $limite; ?>">
            </div>
            <div><button type="submit">Atualizar</button></div>
        </form>

        <div class="table-wrap" style="margin-top:12px;">
            <table>
                <thead><tr><th>Data</th><th>Usuario</th><th>Modulo</th><th>Acao</th><th>Resultado</th><th>Detalhes</th></tr></thead>
                <tbody>
                <?php if (!$eventos): ?>
                    <tr><td colspan="6">Sem eventos.</td></tr>
                <?php else: ?>
                    <?php foreach ($eventos as $e): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $e['criado_em']); ?></td>
                            <td><?php echo htmlspecialchars((string) ($e['usuario_nome'] ?? 'Sistema')); ?> (<?php echo htmlspecialchars((string) ($e['usuario_perfil'] ?? '-')); ?>)</td>
                            <td><?php echo htmlspecialchars((string) $e['modulo']); ?></td>
                            <td><?php echo htmlspecialchars((string) $e['acao']); ?></td>
                            <td><?php echo htmlspecialchars((string) $e['resultado']); ?></td>
                            <td><small><?php echo htmlspecialchars((string) ($e['detalhes'] ?? '')); ?></small></td>
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
