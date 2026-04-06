<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/config.php';

exigir_login();
exigir_admin();

$msg = '';
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'restaurar' && isset($_FILES['arquivo_db'])) {
        if (strtolower(DB_DRIVER) !== 'sqlite') {
            $erro = 'Restauracao automatica habilitada apenas para SQLite local.';
        } else {
            $tmp = (string) ($_FILES['arquivo_db']['tmp_name'] ?? '');
            if ($tmp === '' || !is_file($tmp)) {
                $erro = 'Arquivo invalido.';
            } else {
                $dest = SQLITE_PATH;
                if (move_uploaded_file($tmp, $dest)) {
                    $msg = 'Banco restaurado com sucesso. Recarregue a pagina.';
                } else {
                    $erro = 'Nao foi possivel restaurar o arquivo.';
                }
            }
        }
    }
}

$dbPath = SQLITE_PATH;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup e Restauracao - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Backup e restauracao</h1>
            <small>Operacao de seguranca do banco de dados.</small>
        </div>
        <div class="actions-row"><a class="btn" href="/admin">Voltar ao admin</a></div>
    </div>

    <?php if ($msg): ?><p class="ok"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>

    <div class="card">
        <h2>Backup</h2>
        <?php if (strtolower(DB_DRIVER) === 'sqlite' && is_file($dbPath)): ?>
            <p>Baixe uma copia do banco atual.</p>
            <a class="btn" href="/backup-download">Baixar backup (.sqlite)</a>
        <?php else: ?>
            <p>Backup rapido disponivel apenas para SQLite local.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Restaurar</h2>
        <form method="post" enctype="multipart/form-data" class="grid grid-2">
            <input type="hidden" name="acao" value="restaurar">
            <div>
                <label>Arquivo .sqlite</label>
                <input type="file" name="arquivo_db" accept=".sqlite,.db" required>
            </div>
            <div>
                <button type="submit" class="btn-danger" onclick="return confirm('Tem certeza? Esta operacao substitui o banco atual.');">Restaurar banco</button>
            </div>
        </form>
    </div>
</div>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>
