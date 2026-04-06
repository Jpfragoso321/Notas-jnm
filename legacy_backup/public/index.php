<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

if (usuario_logado()) {
    header('Location: ' . pagina_inicial_por_perfil(usuario_logado()));
    exit;
}

$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');

    if ($usuario === '' || $senha === '') {
        $erro = 'Preencha usuario e senha.';
    } elseif (!autenticar($usuario, $senha)) {
        $erro = 'Usuario ou senha invalidos.';
    } else {
        header('Location: ' . pagina_inicial_por_perfil(usuario_logado()));
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="login-page">
<main class="login-shell">
    <div class="login-orb login-orb-a" aria-hidden="true"></div>
    <div class="login-orb login-orb-b" aria-hidden="true"></div>

    <div class="login-card card">
        <div class="login-brand">Portal ECMNM</div>
        <h1>Painel Escolar</h1>
        <p class="login-subtitle">Acesso de professores e administracao</p>

        <?php if ($erro): ?>
            <p class="error"><?php echo htmlspecialchars($erro); ?></p>
        <?php endif; ?>

        <form method="post" class="login-form">
            <label for="usuario">Usuario (ex: email)</label>
            <input id="usuario" name="usuario" autocomplete="username" required>

            <label for="senha">Senha</label>
            <input id="senha" name="senha" type="password" autocomplete="current-password" required>

            <button type="submit" class="login-btn btn-icon" data-icon="login">Entrar</button>
        </form>

        <p class="link-line"><small>Se esquecer a senha, solicite alteracao ao administrador.</small></p>
    </div>
</main>
<footer class="site-footer login-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>


