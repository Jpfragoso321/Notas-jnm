<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/backlog_setup.php';

exigir_login();

$pdo = db();
$usuario = usuario_logado();
garantir_tabelas_backlogs($pdo);

$perfil = (string) ($usuario['perfil'] ?? 'professor');
$ehGestao = usuario_eh_manager($usuario) || usuario_eh_admin($usuario);
$msg = '';
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'criar_notificacao' && $ehGestao) {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $mensagem = trim((string) ($_POST['mensagem'] ?? ''));
        $perfilDestino = trim((string) ($_POST['perfil_destino'] ?? ''));

        if ($titulo === '' || $mensagem === '') {
            $erro = 'Preencha titulo e mensagem.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO notificacoes_internas (titulo, mensagem, perfil_destino, usuario_destino_id, criado_por)
                 VALUES (:titulo, :mensagem, :perfil_destino, NULL, :criado_por)'
            );
            $stmt->execute([
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'perfil_destino' => $perfilDestino !== '' ? $perfilDestino : null,
                'criado_por' => (int) ($usuario['id'] ?? 0),
            ]);
            $msg = 'Notificacao criada.';
        }
    }

    if ($acao === 'marcar_lida') {
        $idNot = (int) ($_POST['notificacao_id'] ?? 0);
        if ($idNot > 0) {
            if (usando_sqlite($pdo)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO notificacoes_lidas (notificacao_id, usuario_id)
                     VALUES (:notificacao_id, :usuario_id)
                     ON CONFLICT(notificacao_id, usuario_id) DO NOTHING'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO notificacoes_lidas (notificacao_id, usuario_id)
                     VALUES (:notificacao_id, :usuario_id)
                     ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id)'
                );
            }
            $stmt->execute([
                'notificacao_id' => $idNot,
                'usuario_id' => (int) ($usuario['id'] ?? 0),
            ]);
            $msg = 'Notificacao marcada como lida.';
        }
    }
}

$stmtNot = $pdo->prepare(
    'SELECT n.id, n.titulo, n.mensagem, n.perfil_destino, n.criado_em,
            CASE WHEN nl.id IS NULL THEN 0 ELSE 1 END AS lida
     FROM notificacoes_internas n
     LEFT JOIN notificacoes_lidas nl ON nl.notificacao_id = n.id AND nl.usuario_id = :usuario_id
     WHERE (n.perfil_destino IS NULL OR n.perfil_destino = "" OR n.perfil_destino = :perfil)
     ORDER BY n.id DESC'
);
$stmtNot->execute([
    'usuario_id' => (int) ($usuario['id'] ?? 0),
    'perfil' => $perfil,
]);
$notificacoes = $stmtNot->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notificacoes Internas - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Notificacoes internas</h1>
            <small>Comunicados da escola sem uso de e-mail.</small>
        </div>
        <div class="actions-row"><a class="btn" href="/dashboard">Voltar</a></div>
    </div>

    <?php if ($msg): ?><p class="ok"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>

    <?php if ($ehGestao): ?>
        <div class="card">
            <h2>Nova notificacao</h2>
            <form method="post" class="grid grid-3">
                <input type="hidden" name="acao" value="criar_notificacao">
                <div>
                    <label>Titulo</label>
                    <input name="titulo" required>
                </div>
                <div>
                    <label>Perfil destino (opcional)</label>
                    <select name="perfil_destino">
                        <option value="">Todos</option>
                        <option value="professor">Professor</option>
                        <option value="diretora">Diretora</option>
                        <option value="coordenacao_pedagogica">Coordenacao Pedagogica</option>
                        <option value="secretario">Secretarios</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="grid-span-3">
                    <label>Mensagem</label>
                    <textarea name="mensagem" rows="3" required></textarea>
                </div>
                <div><button type="submit">Publicar</button></div>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Caixa de entrada</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Titulo</th><th>Mensagem</th><th>Status</th><th>Acao</th></tr></thead>
                <tbody>
                <?php if (!$notificacoes): ?>
                    <tr><td colspan="5">Sem notificacoes.</td></tr>
                <?php else: ?>
                    <?php foreach ($notificacoes as $n): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $n['criado_em']); ?></td>
                            <td><?php echo htmlspecialchars((string) $n['titulo']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars((string) $n['mensagem'])); ?></td>
                            <td><?php echo (int) $n['lida'] === 1 ? 'Lida' : 'Nova'; ?></td>
                            <td>
                                <?php if ((int) $n['lida'] === 0): ?>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="acao" value="marcar_lida">
                                        <input type="hidden" name="notificacao_id" value="<?php echo (int) $n['id']; ?>">
                                        <button type="submit">Marcar lida</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
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
