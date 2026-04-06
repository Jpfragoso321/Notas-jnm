<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';

exigir_login();

$usuario = usuario_logado();
$pdo = db();

$perfil = (string) ($usuario['perfil'] ?? 'professor');
$perfilExibicao = nome_perfil_exibicao($perfil);
$saudacao = 'Ola, ' . $perfilExibicao;
$ehAdmin = usuario_eh_admin($usuario);
$ehManager = usuario_eh_manager($usuario, false);
$mostrarAcoesAcademicas = !$ehManager;

if ($ehAdmin || $ehManager) {
    $sql = 'SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC';
    $stmt = $pdo->query($sql);
    $turmas = $stmt->fetchAll();
    $subtitulo = $ehAdmin
        ? 'Visao geral de todas as turmas'
        : 'Dashboard manager com visao de todas as turmas';
} else {
    $sql = 'SELECT t.id, t.nome, t.ano_letivo
            FROM professor_turma pt
            INNER JOIN turmas t ON t.id = pt.turma_id
            WHERE pt.professor_id = :professor_id
            ORDER BY t.ano_letivo DESC, t.nome ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['professor_id' => $usuario['id']]);
    $turmas = $stmt->fetchAll();
    $subtitulo = 'Turmas vinculadas ao seu usuario';
}

$disciplinasPorTurma = [];
if ($ehAdmin || $ehManager) {
    $disciplinasStmt = $pdo->query('SELECT id, turma_id, nome FROM disciplinas ORDER BY nome ASC');
    foreach ($disciplinasStmt->fetchAll() as $rowDisciplina) {
        $turmaDisciplinaId = (int) $rowDisciplina['turma_id'];
        if (!isset($disciplinasPorTurma[$turmaDisciplinaId])) {
            $disciplinasPorTurma[$turmaDisciplinaId] = [];
        }

        $disciplinasPorTurma[$turmaDisciplinaId][] = [
            'id' => (int) $rowDisciplina['id'],
            'nome' => (string) $rowDisciplina['nome'],
        ];
    }
} else {
    garantir_tabela_professor_disciplina($pdo);
    $disciplinasStmt = $pdo->prepare(
        'SELECT d.id, d.turma_id, d.nome
         FROM professor_disciplina pd
         INNER JOIN disciplinas d ON d.id = pd.disciplina_id
         WHERE pd.professor_id = :professor_id
         ORDER BY d.nome ASC'
    );
    $disciplinasStmt->execute(['professor_id' => (int) ($usuario['id'] ?? 0)]);
    foreach ($disciplinasStmt->fetchAll() as $rowDisciplina) {
        $turmaDisciplinaId = (int) $rowDisciplina['turma_id'];
        if (!isset($disciplinasPorTurma[$turmaDisciplinaId])) {
            $disciplinasPorTurma[$turmaDisciplinaId] = [];
        }

        $disciplinasPorTurma[$turmaDisciplinaId][] = [
            'id' => (int) $rowDisciplina['id'],
            'nome' => (string) $rowDisciplina['nome'],
        ];
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page dashboard-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin: 0;"><?php echo htmlspecialchars($saudacao); ?></h1>
            <small><?php echo htmlspecialchars($subtitulo); ?></small>
        </div>
        <div class="actions-row">
            <?php if ($ehAdmin): ?>
                <a class="btn btn-secondary btn-icon" data-icon="admin_panel_settings" href="/admin">Painel admin</a>
            <?php endif; ?>
            <?php if ($ehManager): ?>
                <a class="btn btn-secondary btn-icon" data-icon="dashboard" href="/manager">Dashboard manager</a>
            <?php endif; ?>
            <a class="btn btn-icon" data-icon="logout" href="/logout">Sair</a>
        </div>
    </div>

    <div class="card">
        <h2>Turmas</h2>

        <?php if (!$turmas): ?>
            <p>Nenhuma turma cadastrada ate o momento.</p>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($turmas as $turma): ?>
                    <div class="turma">
                        <strong><?php echo htmlspecialchars($turma['nome']); ?></strong>
                        <p><small>Ano letivo: <?php echo (int) $turma['ano_letivo']; ?></small></p>
                        <div class="actions-row" style="margin-top: 8px; flex-wrap: wrap;">
                            <?php if ($mostrarAcoesAcademicas): ?>
                                <a class="btn" href="/turma?turma_id=<?php echo (int) $turma['id']; ?>">Lancar notas</a>
                                <a class="btn btn-secondary" href="/boletim_pdf?tipo=turma&turma_id=<?php echo (int) $turma['id']; ?>">Boletim PDF</a>
                                <a class="btn btn-secondary" href="/risco?turma_id=<?php echo (int) $turma['id']; ?>&etapa=1_bimestre">Analytics de risco</a>
                            <?php else: ?>
                                <a class="btn" href="/manager?turma_id=<?php echo (int) $turma['id']; ?>">Gerenciar turma</a>
                                <a class="btn btn-secondary" href="/turma?turma_id=<?php echo (int) $turma['id']; ?>">Diario da turma</a>
                            <?php endif; ?>
                        </div>
                        <?php $materiasDaTurma = $disciplinasPorTurma[(int) $turma['id']] ?? []; ?>
                        <?php if ($materiasDaTurma): ?>
                            <p><small>Materias disponiveis:</small></p>
                            <div class="actions-row" style="margin-top: 6px; flex-wrap: wrap;">
                                <?php foreach ($materiasDaTurma as $materia): ?>
                                    <a class="btn btn-secondary" href="/turma?turma_id=<?php echo (int) $turma['id']; ?>&disciplina_id=<?php echo (int) $materia['id']; ?>&etapa=1_bimestre"><?php echo htmlspecialchars((string) $materia['nome']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($mostrarAcoesAcademicas): ?>
                            <p><small>Sem materias vinculadas ao seu usuario nesta turma.</small></p>
                        <?php else: ?>
                            <p><small>Nenhuma materia cadastrada nesta turma.</small></p>
                        <?php endif; ?>
                        </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>



