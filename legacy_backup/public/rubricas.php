<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rubricas.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';

exigir_login();

$pdo = db();
$usuario = usuario_logado();
$perfilAtual = perfil_usuario($usuario);
$ehProfessor = $perfilAtual === 'professor';
$ehGestao = usuario_eh_manager($usuario, true);

if (!$ehGestao && !usuario_tem_permissao('notas.lancar', $usuario)) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

garantir_tabelas_rubricas($pdo);
if ($ehProfessor) {
    garantir_tabela_professor_disciplina($pdo);
}

$turmaId = (int) ($_POST['turma_id'] ?? ($_GET['turma_id'] ?? 0));
$disciplinaId = (int) ($_POST['disciplina_id'] ?? ($_GET['disciplina_id'] ?? 0));
$msg = '';
$erro = '';

if ($ehGestao) {
    $turmas = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC')->fetchAll();
} else {
    $stmtTurmas = $pdo->prepare(
        'SELECT t.id, t.nome, t.ano_letivo
         FROM professor_turma pt
         INNER JOIN turmas t ON t.id = pt.turma_id
         WHERE pt.professor_id = :professor_id
         ORDER BY t.ano_letivo DESC, t.nome ASC'
    );
    $stmtTurmas->execute(['professor_id' => (int) ($usuario['id'] ?? 0)]);
    $turmas = $stmtTurmas->fetchAll();
}

$turmaIds = array_map(static fn (array $t): int => (int) $t['id'], $turmas);
if ($turmaId <= 0 && $turmas) {
    $turmaId = (int) $turmas[0]['id'];
}
if ($turmaId > 0 && !in_array($turmaId, $turmaIds, true)) {
    http_response_code(403);
    echo 'Acesso negado para esta turma.';
    exit;
}

$disciplinas = [];
if ($turmaId > 0) {
    if ($ehProfessor) {
        $discStmt = $pdo->prepare(
            'SELECT d.id, d.nome
             FROM professor_disciplina pd
             INNER JOIN disciplinas d ON d.id = pd.disciplina_id
             WHERE pd.professor_id = :professor_id
               AND d.turma_id = :turma_id
             ORDER BY d.nome ASC'
        );
        $discStmt->execute([
            'professor_id' => (int) ($usuario['id'] ?? 0),
            'turma_id' => $turmaId,
        ]);
    } else {
        $discStmt = $pdo->prepare('SELECT id, nome FROM disciplinas WHERE turma_id = :turma_id ORDER BY nome ASC');
        $discStmt->execute(['turma_id' => $turmaId]);
    }
    $disciplinas = $discStmt->fetchAll();
}

if ($ehProfessor && !$disciplinas) {
    http_response_code(403);
    echo 'Acesso negado: voce nao possui materias vinculadas nesta turma.';
    exit;
}

$disciplinasPermitidasIds = array_map(static fn (array $d): int => (int) $d['id'], $disciplinas);
if ($disciplinaId > 0 && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
    if ($ehProfessor) {
        http_response_code(403);
        echo 'Acesso negado para esta disciplina.';
        exit;
    }

    $disciplinaId = 0;
}
if ($disciplinaId <= 0 && $disciplinas) {
    $disciplinaId = (int) $disciplinas[0]['id'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    try {
        if ($acao === 'criar_rubrica') {
            $titulo = trim((string) ($_POST['titulo'] ?? ''));
            $descricao = trim((string) ($_POST['descricao'] ?? ''));
            if ($turmaId <= 0 || $disciplinaId <= 0 || $titulo === '') {
                throw new RuntimeException('Informe turma, disciplina e titulo da rubrica.');
            }
            if ($ehProfessor && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
                throw new RuntimeException('Acesso negado para criar rubrica nesta disciplina.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO rubricas (turma_id, disciplina_id, titulo, descricao, criado_por)
                 VALUES (:turma_id, :disciplina_id, :titulo, :descricao, :criado_por)'
            );
            $stmt->execute([
                'turma_id' => $turmaId,
                'disciplina_id' => $disciplinaId,
                'titulo' => $titulo,
                'descricao' => $descricao !== '' ? $descricao : null,
                'criado_por' => (int) ($usuario['id'] ?? 0),
            ]);

            $msg = 'Rubrica criada com sucesso.';
        } elseif ($acao === 'excluir_rubrica') {
            $rubricaId = (int) ($_POST['rubrica_id'] ?? 0);
            if ($rubricaId <= 0) {
                throw new RuntimeException('Rubrica invalida.');
            }

            $checkRubrica = $pdo->prepare('SELECT id FROM rubricas WHERE id = :id AND turma_id = :turma_id AND disciplina_id = :disciplina_id LIMIT 1');
            $checkRubrica->execute(['id' => $rubricaId, 'turma_id' => $turmaId, 'disciplina_id' => $disciplinaId]);
            if (!$checkRubrica->fetch()) {
                throw new RuntimeException('Rubrica nao encontrada para esta turma/disciplina.');
            }

            if ($ehProfessor && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
                throw new RuntimeException('Acesso negado para excluir rubrica nesta disciplina.');
            }

            $pdo->prepare('DELETE FROM rubrica_criterios WHERE rubrica_id = :rubrica_id')->execute(['rubrica_id' => $rubricaId]);
            $pdo->prepare('DELETE FROM rubricas WHERE id = :id')->execute(['id' => $rubricaId]);
            $msg = 'Rubrica removida.';
        } elseif ($acao === 'adicionar_criterio') {
            $rubricaId = (int) ($_POST['rubrica_id'] ?? 0);
            $criterio = trim((string) ($_POST['criterio'] ?? ''));
            $nivel1 = trim((string) ($_POST['nivel_1'] ?? ''));
            $nivel2 = trim((string) ($_POST['nivel_2'] ?? ''));
            $nivel3 = trim((string) ($_POST['nivel_3'] ?? ''));
            $nivel4 = trim((string) ($_POST['nivel_4'] ?? ''));
            $peso = (float) str_replace(',', '.', (string) ($_POST['peso'] ?? '1'));

            if ($rubricaId <= 0 || $criterio === '' || $nivel1 === '' || $nivel2 === '' || $nivel3 === '' || $nivel4 === '') {
                throw new RuntimeException('Preencha criterio e os 4 niveis.');
            }

            if ($peso <= 0) {
                throw new RuntimeException('Peso deve ser maior que zero.');
            }

            $checkRubrica = $pdo->prepare('SELECT id FROM rubricas WHERE id = :id AND turma_id = :turma_id AND disciplina_id = :disciplina_id LIMIT 1');
            $checkRubrica->execute(['id' => $rubricaId, 'turma_id' => $turmaId, 'disciplina_id' => $disciplinaId]);
            if (!$checkRubrica->fetch()) {
                throw new RuntimeException('Rubrica nao encontrada para esta turma/disciplina.');
            }

            if ($ehProfessor && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
                throw new RuntimeException('Acesso negado para editar rubrica nesta disciplina.');
            }

            $ordemStmt = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 AS prox FROM rubrica_criterios WHERE rubrica_id = :rubrica_id');
            $ordemStmt->execute(['rubrica_id' => $rubricaId]);
            $ordem = (int) (($ordemStmt->fetch()['prox'] ?? 1));

            $stmt = $pdo->prepare(
                'INSERT INTO rubrica_criterios (rubrica_id, criterio, nivel_1, nivel_2, nivel_3, nivel_4, peso, ordem)
                 VALUES (:rubrica_id, :criterio, :nivel_1, :nivel_2, :nivel_3, :nivel_4, :peso, :ordem)'
            );
            $stmt->execute([
                'rubrica_id' => $rubricaId,
                'criterio' => $criterio,
                'nivel_1' => $nivel1,
                'nivel_2' => $nivel2,
                'nivel_3' => $nivel3,
                'nivel_4' => $nivel4,
                'peso' => $peso,
                'ordem' => $ordem,
            ]);
            $msg = 'Criterio adicionado.';
        } elseif ($acao === 'excluir_criterio') {
            $criterioId = (int) ($_POST['criterio_id'] ?? 0);
            if ($criterioId <= 0) {
                throw new RuntimeException('Criterio invalido.');
            }

            $checkCriterio = $pdo->prepare(
                'SELECT rc.id
                 FROM rubrica_criterios rc
                 INNER JOIN rubricas r ON r.id = rc.rubrica_id
                 WHERE rc.id = :criterio_id
                   AND r.turma_id = :turma_id
                   AND r.disciplina_id = :disciplina_id
                 LIMIT 1'
            );
            $checkCriterio->execute([
                'criterio_id' => $criterioId,
                'turma_id' => $turmaId,
                'disciplina_id' => $disciplinaId,
            ]);
            if (!$checkCriterio->fetch()) {
                throw new RuntimeException('Criterio nao encontrado para esta turma/disciplina.');
            }

            if ($ehProfessor && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
                throw new RuntimeException('Acesso negado para excluir criterio nesta disciplina.');
            }

            $pdo->prepare('DELETE FROM rubrica_criterios WHERE id = :id')->execute(['id' => $criterioId]);
            $msg = 'Criterio removido.';
        }
    } catch (Throwable $e) {
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao salvar rubricas.';
    }
}

$disciplinaNomeAtiva = '-';
foreach ($disciplinas as $disciplinaItem) {
    if ((int) ($disciplinaItem['id'] ?? 0) === $disciplinaId) {
        $disciplinaNomeAtiva = (string) ($disciplinaItem['nome'] ?? '-');
        break;
    }
}

$rubricas = [];
if ($turmaId > 0 && $disciplinaId > 0) {
    $rubricasStmt = $pdo->prepare(
        'SELECT r.id, r.titulo, r.descricao, r.criado_em, u.nome AS criado_por_nome
         FROM rubricas r
         LEFT JOIN usuarios u ON u.id = r.criado_por
         WHERE r.turma_id = :turma_id AND r.disciplina_id = :disciplina_id
         ORDER BY r.id DESC'
    );
    $rubricasStmt->execute([
        'turma_id' => $turmaId,
        'disciplina_id' => $disciplinaId,
    ]);
    $rubricas = $rubricasStmt->fetchAll();
}

$criteriosPorRubrica = [];
$totalCriterios = 0;
if ($rubricas) {
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rubricas);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $critStmt = $pdo->prepare('SELECT * FROM rubrica_criterios WHERE rubrica_id IN (' . $placeholders . ') ORDER BY rubrica_id ASC, ordem ASC, id ASC');
    $critStmt->execute($ids);
    foreach ($critStmt->fetchAll() as $c) {
        $rid = (int) $c['rubrica_id'];
        if (!isset($criteriosPorRubrica[$rid])) {
            $criteriosPorRubrica[$rid] = [];
        }
        $criteriosPorRubrica[$rid][] = $c;
        $totalCriterios++;
    }
}

$linkPainel = $ehGestao ? '/manager' : '/dashboard';
$rotuloPainel = $ehGestao ? 'Dashboard manager' : 'Dashboard';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rubricas - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
</head>
<body class="app-page rubricas-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Rubricas por disciplina</h1>
            <small>Ola, <?php echo htmlspecialchars((string) ($usuario['nome'] ?? '')); ?>. Organize criterios, niveis e pesos de avaliacao.</small>
        </div>
        <div class="actions-row">
            <a class="btn btn-secondary btn-icon" data-icon="dashboard" href="<?php echo $linkPainel; ?>"><?php echo htmlspecialchars($rotuloPainel); ?></a>
            <a class="btn btn-icon" data-icon="home" href="/dashboard">Dashboard</a>
        </div>
    </div>

    <?php if ($msg): ?><p class="ok"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>

    <div class="rubricas-summary">
        <div class="rubricas-summary-item">
            <span>Rubricas</span>
            <strong><?php echo count($rubricas); ?></strong>
        </div>
        <div class="rubricas-summary-item">
            <span>Criterios</span>
            <strong><?php echo $totalCriterios; ?></strong>
        </div>
        <div class="rubricas-summary-item">
            <span>Disciplina ativa</span>
            <strong><?php echo htmlspecialchars($disciplinaNomeAtiva); ?></strong>
        </div>
    </div>

    <div class="card rubricas-filter-card">
        <h2>Filtro de trabalho</h2>
        <form method="get" class="grid grid-3 rubricas-filter-grid">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <?php foreach ($turmas as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $turmaId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $t['nome']); ?> (<?php echo (int) $t['ano_letivo']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Disciplina</label>
                <select name="disciplina_id" required>
                    <?php foreach ($disciplinas as $d): ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo (int) $d['id'] === $disciplinaId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $d['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rubricas-filter-action">
                <button type="submit" class="btn btn-icon" data-icon="filter_alt">Aplicar filtro</button>
            </div>
        </form>
    </div>

    <div class="card rubricas-create-card">
        <h2>Nova rubrica</h2>
        <form method="post" class="grid grid-3 rubricas-create-grid">
            <input type="hidden" name="acao" value="criar_rubrica">
            <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
            <input type="hidden" name="disciplina_id" value="<?php echo $disciplinaId; ?>">
            <div>
                <label>Titulo</label>
                <input name="titulo" required placeholder="Ex: Projeto bimestral de ciencias">
            </div>
            <div>
                <label>Descricao (opcional)</label>
                <input name="descricao" placeholder="Contexto da rubrica">
            </div>
            <div class="rubricas-filter-action">
                <button type="submit" class="btn btn-secondary btn-icon" data-icon="add_circle">Criar rubrica</button>
            </div>
        </form>
    </div>

    <?php if (!$rubricas): ?>
        <div class="card rubricas-empty-card">
            <h2>Nenhuma rubrica cadastrada</h2>
            <p><small>Crie a primeira rubrica para esta turma/disciplina e depois adicione criterios e niveis.</small></p>
        </div>
    <?php endif; ?>

    <?php foreach ($rubricas as $rubrica): ?>
        <?php $rubricaId = (int) $rubrica['id']; $criterios = $criteriosPorRubrica[$rubricaId] ?? []; ?>
        <div class="card rubrica-card">
            <div class="rubrica-head">
                <div>
                    <h2 style="margin:0;"><?php echo htmlspecialchars((string) $rubrica['titulo']); ?></h2>
                    <small>
                        <?php echo htmlspecialchars((string) ($rubrica['descricao'] ?? 'Sem descricao')); ?>
                        <?php if (!empty($rubrica['criado_por_nome'])): ?>
                            | Criado por <?php echo htmlspecialchars((string) $rubrica['criado_por_nome']); ?>
                        <?php endif; ?>
                    </small>
                </div>
                <form method="post" class="rubrica-delete-form">
                    <input type="hidden" name="acao" value="excluir_rubrica">
                    <input type="hidden" name="rubrica_id" value="<?php echo $rubricaId; ?>">
                    <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                    <input type="hidden" name="disciplina_id" value="<?php echo $disciplinaId; ?>">
                    <button type="submit" class="btn btn-danger btn-icon" data-icon="delete" onclick="return confirm('Excluir rubrica e criterios?');">Excluir rubrica</button>
                </form>
            </div>

            <div class="table-wrap rubrica-table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Criterio</th>
                        <th>Nivel 1</th>
                        <th>Nivel 2</th>
                        <th>Nivel 3</th>
                        <th>Nivel 4</th>
                        <th>Peso</th>
                        <th>Acoes</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$criterios): ?>
                        <tr><td colspan="7">Sem criterios cadastrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($criterios as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $c['criterio']); ?></td>
                                <td><?php echo htmlspecialchars((string) $c['nivel_1']); ?></td>
                                <td><?php echo htmlspecialchars((string) $c['nivel_2']); ?></td>
                                <td><?php echo htmlspecialchars((string) $c['nivel_3']); ?></td>
                                <td><?php echo htmlspecialchars((string) $c['nivel_4']); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float) $c['peso'], 2, ',', '.')); ?></td>
                                <td>
                                    <form method="post" class="rubrica-mini-form">
                                        <input type="hidden" name="acao" value="excluir_criterio">
                                        <input type="hidden" name="criterio_id" value="<?php echo (int) $c['id']; ?>">
                                        <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                                        <input type="hidden" name="disciplina_id" value="<?php echo $disciplinaId; ?>">
                                        <button type="submit" class="btn btn-danger btn-xs btn-icon" data-icon="delete_forever" onclick="return confirm('Excluir criterio?');">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="rubrica-subtitle">Adicionar criterio</h3>
            <form method="post" class="rubrica-criterio-form">
                <input type="hidden" name="acao" value="adicionar_criterio">
                <input type="hidden" name="rubrica_id" value="<?php echo $rubricaId; ?>">
                <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                <input type="hidden" name="disciplina_id" value="<?php echo $disciplinaId; ?>">
                <input name="criterio" placeholder="Criterio" required>
                <input name="nivel_1" placeholder="Nivel 1" required>
                <input name="nivel_2" placeholder="Nivel 2" required>
                <input name="nivel_3" placeholder="Nivel 3" required>
                <input name="nivel_4" placeholder="Nivel 4" required>
                <input name="peso" type="number" min="0.1" step="0.1" value="1" required>
                <button type="submit" class="btn btn-secondary btn-icon" data-icon="playlist_add">Adicionar</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>