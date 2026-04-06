<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/backlog_setup.php';

exigir_login();
exigir_manager();

$pdo = db();
$usuario = usuario_logado();
garantir_tabelas_backlogs($pdo);
garantir_acesso_portal_alunos($pdo);

$turmaId = (int) ($_POST['turma_id'] ?? ($_GET['turma_id'] ?? 0));

function redirecionar_manager(string $tipo, string $mensagem, int $turmaId): void
{
    $scrollY = max(0, (int) ($_POST['_scroll_y'] ?? 0));

    $params = [
        $tipo => $mensagem,
        'turma_id' => $turmaId,
    ];

    if ($scrollY > 0) {
        $params['scroll'] = (string) $scrollY;
    }

    header('Location: /manager?' . http_build_query($params));
    exit;
}

$turmas = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC')->fetchAll();
$turmaIds = array_map(static fn (array $item): int => (int) $item['id'], $turmas);

if ($turmaId <= 0 && !empty($turmaIds)) {
    $turmaId = $turmaIds[0];
}

if ($turmaId > 0 && !in_array($turmaId, $turmaIds, true)) {
    $turmaId = !empty($turmaIds) ? $turmaIds[0] : 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = trim((string) ($_POST['acao'] ?? ''));

    try {
        if ($acao === 'criar_aluno_manager') {
            $nomeAluno = trim((string) ($_POST['nome_aluno'] ?? ''));
            if ($turmaId <= 0 || $nomeAluno === '') {
                throw new RuntimeException('Informe turma e nome do aluno.');
            }

            $stmt = $pdo->prepare('INSERT INTO alunos (turma_id, nome) VALUES (:turma_id, :nome)');
            $stmt->execute([
                'turma_id' => $turmaId,
                'nome' => $nomeAluno,
            ]);

            garantir_acesso_portal_alunos($pdo);
            redirecionar_manager('ok', 'Aluno cadastrado com sucesso.', $turmaId);
        }

        if ($acao === 'criar_disciplina_manager') {
            $nomeDisciplina = trim((string) ($_POST['nome_disciplina'] ?? ''));
            if ($turmaId <= 0 || $nomeDisciplina === '') {
                throw new RuntimeException('Informe turma e nome da materia.');
            }

            $stmt = $pdo->prepare('INSERT INTO disciplinas (turma_id, nome) VALUES (:turma_id, :nome)');
            $stmt->execute([
                'turma_id' => $turmaId,
                'nome' => $nomeDisciplina,
            ]);

            redirecionar_manager('ok', 'Materia cadastrada com sucesso.', $turmaId);
        }

        if ($acao !== '') {
            throw new RuntimeException('Acao invalida.');
        }
    } catch (Throwable $e) {
        $mensagem = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Nao foi possivel concluir a operacao.';

        redirecionar_manager('erro', $mensagem, $turmaId);
    }
}

$ok = trim((string) ($_GET['ok'] ?? ''));
$erro = trim((string) ($_GET['erro'] ?? ''));
$scrollRestaure = max(0, (int) ($_GET['scroll'] ?? 0));

$resumoTurmasStmt = $pdo->query(
    'SELECT
        t.id,
        t.nome,
        t.ano_letivo,
        COUNT(DISTINCT a.id) AS total_alunos,
        COUNT(DISTINCT d.id) AS total_disciplinas
     FROM turmas t
     LEFT JOIN alunos a ON a.turma_id = t.id
     LEFT JOIN disciplinas d ON d.turma_id = t.id
     GROUP BY t.id, t.nome, t.ano_letivo
     ORDER BY t.ano_letivo DESC, t.nome ASC'
);
$resumoTurmas = $resumoTurmasStmt->fetchAll();

$alunosTurma = [];
$disciplinasTurma = [];
$acessosPortalTurma = [];
if ($turmaId > 0) {
    $alunosStmt = $pdo->prepare('SELECT id, nome FROM alunos WHERE turma_id = :turma_id ORDER BY nome ASC');
    $alunosStmt->execute(['turma_id' => $turmaId]);
    $alunosTurma = $alunosStmt->fetchAll();

    $disciplinasStmt = $pdo->prepare('SELECT id, nome FROM disciplinas WHERE turma_id = :turma_id ORDER BY nome ASC');
    $disciplinasStmt->execute(['turma_id' => $turmaId]);
    $disciplinasTurma = $disciplinasStmt->fetchAll();

    $acessoStmt = $pdo->prepare(
        'SELECT a.nome AS aluno_nome, ap.codigo_acesso, ap.pin_acesso
         FROM aluno_acesso_portal ap
         INNER JOIN alunos a ON a.id = ap.aluno_id
         WHERE a.turma_id = :turma_id
         ORDER BY a.nome ASC'
    );
    $acessoStmt->execute(['turma_id' => $turmaId]);
    $acessosPortalTurma = $acessoStmt->fetchAll();
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Manager - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
    <script>
        (function () {
            window.__managerScrollFromServer = <?php echo $scrollRestaure; ?>;
            if (window.__managerScrollFromServer > 0) {
                document.documentElement.classList.add('restore-scroll-pending');
            }
        })();
    </script>
</head>
<body class="app-page manager-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin: 0;">Dashboard Manager</h1>
            <small>Ola, <?php echo htmlspecialchars((string) $usuario['nome']); ?>. Aqui voce cadastra alunos, materias e acompanha todas as turmas.</small>
        </div>
        <div class="actions-row">
            <?php if (usuario_eh_admin($usuario)): ?>
                <a class="btn btn-secondary btn-icon" data-icon="admin_panel_settings" href="/admin">Painel admin</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-icon" data-icon="groups" href="/frequencia">Frequencia</a>
            <a class="btn btn-secondary btn-icon" data-icon="history_edu" href="/historico">Historico</a>
            <a class="btn btn-secondary btn-icon" data-icon="fact_check" href="/conselho">Conselho</a>
            <a class="btn btn-secondary btn-icon" data-icon="bar_chart" href="/relatorios">Relatorios</a>
            <a class="btn btn-secondary btn-icon" data-icon="grading" href="/rubricas">Rubricas</a>
            <a class="btn btn-secondary btn-icon" data-icon="insights" href="/analytics-avancado">Analytics avancado</a>
            <a class="btn btn-secondary btn-icon" data-icon="notifications" href="/notificacoes">Notificacoes</a>
            <a class="btn btn-secondary btn-icon" data-icon="manage_search" href="/auditoria">Auditoria</a>
            <a class="btn btn-secondary btn-icon" data-icon="dashboard" href="/dashboard">Dashboard</a>
            <a class="btn btn-icon" data-icon="logout" href="/logout">Sair</a>
        </div>
    </div>

    <?php if ($ok): ?>
        <p class="ok"><?php echo htmlspecialchars($ok); ?></p>
    <?php endif; ?>
    <?php if ($erro): ?>
        <p class="error"><?php echo htmlspecialchars($erro); ?></p>
    <?php endif; ?>

    <div class="card">
        <h2>Visao geral de turmas</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Turma</th>
                    <th>Ano letivo</th>
                    <th>Total de alunos</th>
                    <th>Total de materias</th>
                    <th>Acoes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($resumoTurmas as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $item['nome']); ?></td>
                        <td><?php echo (int) $item['ano_letivo']; ?></td>
                        <td><?php echo (int) $item['total_alunos']; ?></td>
                        <td><?php echo (int) $item['total_disciplinas']; ?></td>
                        <td>
                            <a class="btn" href="/manager?turma_id=<?php echo (int) $item['id']; ?>">Gerenciar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Cadastro rapido (turma selecionada)</h2>
        <form method="get" class="grid grid-3">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo (int) $turma['id']; ?>" <?php echo (int) $turma['id'] === $turmaId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $turma['nome']); ?> (<?php echo (int) $turma['ano_letivo']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Selecionar turma</button>
            </div>
        </form>

        <div class="grid grid-3" style="margin-top: 12px;">
            <form method="post" class="card" style="margin: 0;">
                <input type="hidden" name="acao" value="criar_aluno_manager">
                <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                <h3 style="margin-top: 0;">Cadastrar aluno</h3>
                <label>Nome do aluno</label>
                <input name="nome_aluno" required>
                <button type="submit">Salvar aluno</button>
            </form>

            <form method="post" class="card" style="margin: 0;">
                <input type="hidden" name="acao" value="criar_disciplina_manager">
                <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                <h3 style="margin-top: 0;">Cadastrar materia</h3>
                <label>Nome da materia</label>
                <input name="nome_disciplina" required>
                <button type="submit">Salvar materia</button>
            </form>

            <div class="card" style="margin: 0;">
                <h3 style="margin-top: 0;">Resumo rapido</h3>
                <p><strong>Alunos:</strong> <?php echo count($alunosTurma); ?></p>
                <p><strong>Materias:</strong> <?php echo count($disciplinasTurma); ?></p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Painel responsavel/aluno</h2>
        <p><small>Acesso publico: <a href="/portal-aluno">/portal-aluno</a></small></p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Aluno</th><th>Codigo</th><th>PIN</th></tr></thead>
                <tbody>
                <?php if (!$acessosPortalTurma): ?>
                    <tr><td colspan="3">Sem alunos na turma selecionada.</td></tr>
                <?php else: ?>
                    <?php foreach ($acessosPortalTurma as $acesso): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $acesso['aluno_nome']); ?></td>
                            <td><?php echo htmlspecialchars((string) $acesso['codigo_acesso']); ?></td>
                            <td><?php echo htmlspecialchars((string) $acesso['pin_acesso']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Alunos da turma</h2>
            <?php if (!$alunosTurma): ?>
                <p>Nenhum aluno cadastrado.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($alunosTurma as $aluno): ?>
                        <li><?php echo htmlspecialchars((string) $aluno['nome']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Materias da turma</h2>
            <?php if (!$disciplinasTurma): ?>
                <p>Nenhuma materia cadastrada.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($disciplinasTurma as $disciplina): ?>
                        <li><?php echo htmlspecialchars((string) $disciplina['nome']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    (function () {
        const serverScroll = Number(window.__managerScrollFromServer || 0);

        const obterScrollYAtual = () => Math.max(0, Math.floor(window.scrollY || window.pageYOffset || 0));

        const salvarScrollNoFormulario = (form) => {
            let campo = form.querySelector('input[name="_scroll_y"]');
            if (!campo) {
                campo = document.createElement('input');
                campo.type = 'hidden';
                campo.name = '_scroll_y';
                form.appendChild(campo);
            }

            campo.value = String(obterScrollYAtual());
        };

        const limparScrollDaUrl = () => {
            if (serverScroll <= 0 || !window.history || typeof window.history.replaceState !== 'function') {
                return;
            }

            const url = new URL(window.location.href);
            if (!url.searchParams.has('scroll')) {
                return;
            }

            url.searchParams.delete('scroll');
            const query = url.searchParams.toString();
            const destino = url.pathname + (query ? '?' + query : '') + url.hash;
            window.history.replaceState({}, document.title, destino);
        };

        const restoreScrollIfNeeded = () => {
            if (serverScroll > 0) {
                window.scrollTo(0, serverScroll);
                limparScrollDaUrl();
            }

            requestAnimationFrame(() => {
                document.documentElement.classList.remove('restore-scroll-pending');
            });
        };

        const formsPost = document.querySelectorAll('form[method="post"]');
        formsPost.forEach((form) => {
            form.addEventListener('submit', () => {
                salvarScrollNoFormulario(form);
            });
        });

        restoreScrollIfNeeded();
    })();
</script>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>
