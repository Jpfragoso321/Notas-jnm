<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/avaliacao_config.php';
require_once __DIR__ . '/../src/risk.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';

exigir_login();
exigir_admin();

$usuario = usuario_logado();
$pdo = db();

function garantir_tabela_professor_materia(PDO $pdo): void
{
    if (usando_sqlite($pdo)) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS professor_materia (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                professor_id INTEGER NOT NULL,
                nome_materia TEXT NOT NULL,
                UNIQUE (professor_id, nome_materia),
                FOREIGN KEY (professor_id) REFERENCES usuarios(id)
            )"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS professor_materia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professor_id INT NOT NULL,
            nome_materia VARCHAR(120) NOT NULL,
            UNIQUE KEY uq_professor_materia (professor_id, nome_materia),
            CONSTRAINT fk_pm_professor FOREIGN KEY (professor_id) REFERENCES usuarios(id)
        )"
    );
}

function normalizar_materias(array $materias): array
{
    $saida = [];
    foreach ($materias as $materia) {
        $nome = trim((string) $materia);
        if ($nome === '') {
            continue;
        }

        $chave = strtolower($nome);
        if (!isset($saida[$chave])) {
            $saida[$chave] = $nome;
        }
    }

    return array_values($saida);
}

function sincronizar_professor_materias(PDO $pdo, int $professorId, array $materias): void
{
    garantir_tabela_professor_materia($pdo);

    if ($professorId <= 0) {
        throw new RuntimeException('Professor invalido para materias.');
    }

    $materias = normalizar_materias($materias);
    if ($materias === []) {
        throw new RuntimeException('Selecione ao menos uma materia para o professor.');
    }

    $pdo->prepare('DELETE FROM professor_materia WHERE professor_id = :professor_id')->execute([
        'professor_id' => $professorId,
    ]);

    if (usando_sqlite($pdo)) {
        $insert = $pdo->prepare(
            'INSERT INTO professor_materia (professor_id, nome_materia)
             VALUES (:professor_id, :nome_materia)
             ON CONFLICT(professor_id, nome_materia) DO NOTHING'
        );
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO professor_materia (professor_id, nome_materia)
             VALUES (:professor_id, :nome_materia)
             ON DUPLICATE KEY UPDATE nome_materia = VALUES(nome_materia)'
        );
    }

    foreach ($materias as $materia) {
        $insert->execute([
            'professor_id' => $professorId,
            'nome_materia' => $materia,
        ]);
    }
}

function limpar_professor_materias(PDO $pdo, int $professorId): void
{
    garantir_tabela_professor_materia($pdo);
    $pdo->prepare('DELETE FROM professor_materia WHERE professor_id = :professor_id')->execute([
        'professor_id' => $professorId,
    ]);
}

function sincronizar_professor_disciplina_na_turma(PDO $pdo, int $professorId, int $turmaId): void
{
    garantir_tabela_professor_disciplina($pdo);
    garantir_tabela_professor_materia($pdo);

    $materiasStmt = $pdo->prepare('SELECT nome_materia FROM professor_materia WHERE professor_id = :professor_id');
    $materiasStmt->execute(['professor_id' => $professorId]);
    $materias = normalizar_materias(array_map(static fn (array $row): string => (string) $row['nome_materia'], $materiasStmt->fetchAll()));

    $pdo->prepare(
        'DELETE FROM professor_disciplina
         WHERE professor_id = :professor_id
           AND disciplina_id IN (SELECT id FROM disciplinas WHERE turma_id = :turma_id)'
    )->execute([
        'professor_id' => $professorId,
        'turma_id' => $turmaId,
    ]);

    if ($materias === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($materias), '?'));
    $sql = "SELECT id FROM disciplinas WHERE turma_id = ? AND nome IN ($placeholders)";
    $params = array_merge([$turmaId], $materias);
    $disciplinasStmt = $pdo->prepare($sql);
    $disciplinasStmt->execute($params);

    $disciplinasIds = array_map(static fn (array $row): int => (int) $row['id'], $disciplinasStmt->fetchAll());
    if ($disciplinasIds === []) {
        return;
    }

    if (usando_sqlite($pdo)) {
        $insert = $pdo->prepare(
            'INSERT INTO professor_disciplina (professor_id, disciplina_id)
             VALUES (:professor_id, :disciplina_id)
             ON CONFLICT(professor_id, disciplina_id) DO NOTHING'
        );
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO professor_disciplina (professor_id, disciplina_id)
             VALUES (:professor_id, :disciplina_id)
             ON DUPLICATE KEY UPDATE professor_id = VALUES(professor_id)'
        );
    }

    foreach ($disciplinasIds as $disciplinaId) {
        $insert->execute([
            'professor_id' => $professorId,
            'disciplina_id' => $disciplinaId,
        ]);
    }
}

function sincronizar_professor_disciplinas_em_turmas_vinculadas(PDO $pdo, int $professorId): void
{
    $stmt = $pdo->prepare('SELECT turma_id FROM professor_turma WHERE professor_id = :professor_id');
    $stmt->execute(['professor_id' => $professorId]);
    foreach ($stmt->fetchAll() as $row) {
        sincronizar_professor_disciplina_na_turma($pdo, $professorId, (int) $row['turma_id']);
    }
}

function obter_materias_professor_post(): array
{
    if (isset($_POST['materias_professor']) && is_array($_POST['materias_professor'])) {
        return (array) $_POST['materias_professor'];
    }

    $materiaSingular = trim((string) ($_POST['materia_professor'] ?? ''));
    if ($materiaSingular !== '') {
        return [$materiaSingular];
    }

    return [];
}

function sincronizar_professores_da_turma(PDO $pdo, int $turmaId): void
{
    if ($turmaId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT professor_id FROM professor_turma WHERE turma_id = :turma_id');
    $stmt->execute(['turma_id' => $turmaId]);
    foreach ($stmt->fetchAll() as $row) {
        sincronizar_professor_disciplina_na_turma($pdo, (int) $row['professor_id'], $turmaId);
    }
}

function reconciliar_professor_disciplinas(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT id FROM usuarios WHERE perfil = 'professor'");
    foreach ($stmt->fetchAll() as $row) {
        sincronizar_professor_disciplinas_em_turmas_vinculadas($pdo, (int) $row['id']);
    }
}
function redirecionar_admin(string $tipo, string $mensagem): void
{
    $scrollY = max(0, (int) ($_POST['_scroll_y'] ?? 0));
    $params = [
        $tipo => $mensagem,
    ];

    if ($scrollY > 0) {
        $params['scroll'] = (string) $scrollY;
    }

    header('Location: /admin?' . http_build_query($params));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        switch ($acao) {
            case 'criar_usuario':
                $nome = trim((string) ($_POST['nome'] ?? ''));
                $login = trim((string) ($_POST['usuario'] ?? ''));
                $senha = (string) ($_POST['senha'] ?? '');
                $perfil = (string) ($_POST['perfil'] ?? 'professor');

                if ($nome === '' || $login === '' || $senha === '') {
                    throw new RuntimeException('Preencha nome, usuario e senha para criar o usuario.');
                }

                if (!in_array($perfil, ['admin', 'professor', 'diretora', 'coordenacao_pedagogica', 'secretario'], true)) {
                    throw new RuntimeException('Perfil invalido.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare('INSERT INTO usuarios (nome, usuario, senha, perfil) VALUES (:nome, :usuario, :senha, :perfil)');
                $stmt->execute([
                    'nome' => $nome,
                    'usuario' => $login,
                    'senha' => password_hash($senha, PASSWORD_DEFAULT),
                    'perfil' => $perfil,
                ]);

                $novoUsuarioId = (int) $pdo->lastInsertId();
                if ($perfil === 'professor') {
                    $materiasProfessor = obter_materias_professor_post();
                    sincronizar_professor_materias($pdo, $novoUsuarioId, $materiasProfessor);
                }

                $pdo->commit();

                redirecionar_admin('ok', 'Usuario criado com sucesso.');

            case 'atualizar_usuario':
                $id = (int) ($_POST['id'] ?? 0);
                $nome = trim((string) ($_POST['nome'] ?? ''));
                $login = trim((string) ($_POST['usuario'] ?? ''));
                $perfil = (string) ($_POST['perfil'] ?? 'professor');
                $novaSenha = (string) ($_POST['nova_senha'] ?? '');

                if ($id <= 0 || $nome === '' || $login === '') {
                    throw new RuntimeException('Dados invalidos para atualizar usuario.');
                }

                if (!in_array($perfil, ['admin', 'professor', 'diretora', 'coordenacao_pedagogica', 'secretario'], true)) {
                    throw new RuntimeException('Perfil invalido.');
                }

                if ($id === (int) $usuario['id'] && $perfil !== 'admin') {
                    throw new RuntimeException('Seu proprio usuario deve manter perfil admin.');
                }

                $pdo->beginTransaction();

                if ($novaSenha !== '') {
                    $stmt = $pdo->prepare('UPDATE usuarios SET nome = :nome, usuario = :usuario, perfil = :perfil, senha = :senha WHERE id = :id');
                    $stmt->execute([
                        'id' => $id,
                        'nome' => $nome,
                        'usuario' => $login,
                        'perfil' => $perfil,
                        'senha' => password_hash($novaSenha, PASSWORD_DEFAULT),
                    ]);
                } else {
                    $stmt = $pdo->prepare('UPDATE usuarios SET nome = :nome, usuario = :usuario, perfil = :perfil WHERE id = :id');
                    $stmt->execute([
                        'id' => $id,
                        'nome' => $nome,
                        'usuario' => $login,
                        'perfil' => $perfil,
                    ]);
                }

                if ($perfil === 'professor') {
                    $materiasProfessor = obter_materias_professor_post();
                    sincronizar_professor_materias($pdo, $id, $materiasProfessor);
                    sincronizar_professor_disciplinas_em_turmas_vinculadas($pdo, $id);
                } else {
                    limpar_professor_materias($pdo, $id);
                    limpar_vinculos_professor_disciplina($pdo, $id);
                }

                $pdo->commit();

                redirecionar_admin('ok', 'Usuario atualizado com sucesso.');

            case 'deletar_usuario':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Usuario invalido para exclusao.');
                }

                if ($id === (int) $usuario['id']) {
                    throw new RuntimeException('Nao e permitido excluir o proprio usuario logado.');
                }

                $pdo->beginTransaction();
                limpar_professor_materias($pdo, $id);
                limpar_vinculos_professor_disciplina($pdo, $id);
                $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);
                $pdo->commit();

                redirecionar_admin('ok', 'Usuario excluido com sucesso.');

            case 'criar_turma':
                $nome = trim((string) ($_POST['nome'] ?? ''));
                $ano = (int) ($_POST['ano_letivo'] ?? 0);

                if ($nome === '' || $ano <= 2000) {
                    throw new RuntimeException('Informe nome da turma e ano letivo valido.');
                }

                $stmt = $pdo->prepare('INSERT INTO turmas (nome, ano_letivo) VALUES (:nome, :ano_letivo)');
                $stmt->execute(['nome' => $nome, 'ano_letivo' => $ano]);

                redirecionar_admin('ok', 'Turma criada com sucesso.');

            case 'atualizar_turma':
                $id = (int) ($_POST['id'] ?? 0);
                $nome = trim((string) ($_POST['nome'] ?? ''));
                $ano = (int) ($_POST['ano_letivo'] ?? 0);

                if ($id <= 0 || $nome === '' || $ano <= 2000) {
                    throw new RuntimeException('Dados invalidos para atualizar turma.');
                }

                $stmt = $pdo->prepare('UPDATE turmas SET nome = :nome, ano_letivo = :ano_letivo WHERE id = :id');
                $stmt->execute(['id' => $id, 'nome' => $nome, 'ano_letivo' => $ano]);

                redirecionar_admin('ok', 'Turma atualizada com sucesso.');

            case 'deletar_turma':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Turma invalida para exclusao.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM notas WHERE turma_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM disciplinas WHERE turma_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM alunos WHERE turma_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM professor_turma WHERE turma_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM turmas WHERE id = :id')->execute(['id' => $id]);
                $pdo->commit();

                redirecionar_admin('ok', 'Turma excluida com sucesso.');

            case 'criar_disciplina':
                $turmaId = (int) ($_POST['turma_id'] ?? 0);
                $nome = trim((string) ($_POST['nome'] ?? ''));

                if ($turmaId <= 0 || $nome === '') {
                    throw new RuntimeException('Informe turma e nome da disciplina.');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO disciplinas (turma_id, nome) VALUES (:turma_id, :nome)');
                $stmt->execute(['turma_id' => $turmaId, 'nome' => $nome]);
                sincronizar_professores_da_turma($pdo, $turmaId);
                $pdo->commit();

                redirecionar_admin('ok', 'Disciplina criada com sucesso.');

            case 'atualizar_disciplina':
                $id = (int) ($_POST['id'] ?? 0);
                $turmaId = (int) ($_POST['turma_id'] ?? 0);
                $nome = trim((string) ($_POST['nome'] ?? ''));

                if ($id <= 0 || $turmaId <= 0 || $nome === '') {
                    throw new RuntimeException('Dados invalidos para atualizar disciplina.');
                }

                $stmtDisciplinaAtual = $pdo->prepare('SELECT turma_id FROM disciplinas WHERE id = :id LIMIT 1');
                $stmtDisciplinaAtual->execute(['id' => $id]);
                $disciplinaAtual = $stmtDisciplinaAtual->fetch();
                if (!$disciplinaAtual) {
                    throw new RuntimeException('Disciplina nao encontrada.');
                }

                $turmaAnteriorId = (int) $disciplinaAtual['turma_id'];

                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE disciplinas SET turma_id = :turma_id, nome = :nome WHERE id = :id');
                $stmt->execute(['id' => $id, 'turma_id' => $turmaId, 'nome' => $nome]);
                sincronizar_professores_da_turma($pdo, $turmaAnteriorId);
                if ($turmaAnteriorId !== $turmaId) {
                    sincronizar_professores_da_turma($pdo, $turmaId);
                }
                $pdo->commit();

                redirecionar_admin('ok', 'Disciplina atualizada com sucesso.');

            case 'deletar_disciplina':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Disciplina invalida para exclusao.');
                }

                $stmtDisciplinaAtual = $pdo->prepare('SELECT turma_id FROM disciplinas WHERE id = :id LIMIT 1');
                $stmtDisciplinaAtual->execute(['id' => $id]);
                $disciplinaAtual = $stmtDisciplinaAtual->fetch();
                if (!$disciplinaAtual) {
                    throw new RuntimeException('Disciplina nao encontrada.');
                }

                $turmaDisciplinaId = (int) $disciplinaAtual['turma_id'];

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM notas WHERE disciplina_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM disciplinas WHERE id = :id')->execute(['id' => $id]);
                sincronizar_professores_da_turma($pdo, $turmaDisciplinaId);
                $pdo->commit();

                redirecionar_admin('ok', 'Disciplina excluida com sucesso.');

            case 'criar_aluno':
                $turmaId = (int) ($_POST['turma_id'] ?? 0);
                $nome = trim((string) ($_POST['nome'] ?? ''));

                if ($turmaId <= 0 || $nome === '') {
                    throw new RuntimeException('Informe turma e nome do aluno.');
                }

                $stmt = $pdo->prepare('INSERT INTO alunos (turma_id, nome) VALUES (:turma_id, :nome)');
                $stmt->execute(['turma_id' => $turmaId, 'nome' => $nome]);

                redirecionar_admin('ok', 'Aluno criado com sucesso.');

            case 'atualizar_aluno':
                $id = (int) ($_POST['id'] ?? 0);
                $turmaId = (int) ($_POST['turma_id'] ?? 0);
                $nome = trim((string) ($_POST['nome'] ?? ''));

                if ($id <= 0 || $turmaId <= 0 || $nome === '') {
                    throw new RuntimeException('Dados invalidos para atualizar aluno.');
                }

                $stmt = $pdo->prepare('UPDATE alunos SET turma_id = :turma_id, nome = :nome WHERE id = :id');
                $stmt->execute(['id' => $id, 'turma_id' => $turmaId, 'nome' => $nome]);

                redirecionar_admin('ok', 'Aluno atualizado com sucesso.');

            case 'deletar_aluno':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Aluno invalido para exclusao.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM notas WHERE aluno_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM alunos WHERE id = :id')->execute(['id' => $id]);
                $pdo->commit();

                redirecionar_admin('ok', 'Aluno excluido com sucesso.');

            case 'vincular_professor_turma':
                $professorId = (int) ($_POST['professor_id'] ?? 0);
                $turmaId = (int) ($_POST['turma_id'] ?? 0);

                if ($professorId <= 0 || $turmaId <= 0) {
                    throw new RuntimeException('Selecione professor e turma para vincular.');
                }

                $stmtExiste = $pdo->prepare('SELECT 1 FROM professor_turma WHERE professor_id = :professor_id AND turma_id = :turma_id LIMIT 1');
                $stmtExiste->execute(['professor_id' => $professorId, 'turma_id' => $turmaId]);
                if ($stmtExiste->fetchColumn()) {
                    throw new RuntimeException('Este professor ja esta vinculado a essa turma.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare('INSERT INTO professor_turma (professor_id, turma_id) VALUES (:professor_id, :turma_id)');
                $stmt->execute(['professor_id' => $professorId, 'turma_id' => $turmaId]);
                sincronizar_professor_disciplina_na_turma($pdo, $professorId, $turmaId);

                $pdo->commit();

                redirecionar_admin('ok', 'Vinculo criado com sucesso.');

            case 'salvar_config_risco':
                salvar_config_risco($pdo, [
                    'peso_media' => (float) ($_POST['peso_media'] ?? 0.60),
                    'peso_faltas' => (float) ($_POST['peso_faltas'] ?? 0.25),
                    'peso_atrasos' => (float) ($_POST['peso_atrasos'] ?? 0.15),
                    'limiar_media' => (float) ($_POST['limiar_media'] ?? 5.00),
                    'limiar_faltas_percentual' => (float) ($_POST['limiar_faltas_percentual'] ?? 15.00),
                    'limiar_atrasos' => (int) ($_POST['limiar_atrasos'] ?? 3),
                    'limiar_score_moderado' => (float) ($_POST['limiar_score_moderado'] ?? 40.00),
                    'limiar_score_alto' => (float) ($_POST['limiar_score_alto'] ?? 70.00),
                ]);
                redirecionar_admin('ok', 'Configuracao de risco atualizada com sucesso.');

            case 'salvar_config_arredondamento':
                $modo = trim((string) ($_POST['modo_arredondamento'] ?? 'comercial'));
                $casas = (int) ($_POST['casas_decimais'] ?? 2);

                salvar_config_avaliacao($pdo, $modo, $casas);
                redirecionar_admin('ok', 'Configuracao de arredondamento atualizada com sucesso.');

            case 'reconstruir_vinculos_professor_disciplina':
                $pdo->beginTransaction();
                reconciliar_professor_disciplinas($pdo);
                $pdo->commit();

                redirecionar_admin('ok', 'Vinculos professor x disciplina reconstruidos com sucesso.');

            case 'remover_vinculo':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Vinculo invalido para exclusao.');
                }

                $stmtVinculo = $pdo->prepare('SELECT professor_id, turma_id FROM professor_turma WHERE id = :id LIMIT 1');
                $stmtVinculo->execute(['id' => $id]);
                $vinculoRemocao = $stmtVinculo->fetch();
                if (!$vinculoRemocao) {
                    throw new RuntimeException('Vinculo nao encontrado.');
                }

                garantir_tabela_professor_disciplina($pdo);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare('DELETE FROM professor_turma WHERE id = :id');
                $stmt->execute(['id' => $id]);

                $pdo->prepare(
                    'DELETE FROM professor_disciplina
                     WHERE professor_id = :professor_id
                       AND disciplina_id IN (SELECT id FROM disciplinas WHERE turma_id = :turma_id)'
                )->execute([
                    'professor_id' => (int) $vinculoRemocao['professor_id'],
                    'turma_id' => (int) $vinculoRemocao['turma_id'],
                ]);

                $pdo->commit();

                redirecionar_admin('ok', 'Vinculo removido com sucesso.');

            default:
                throw new RuntimeException('Acao invalida.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $mensagem = 'Nao foi possivel concluir a operacao.';
        if ($e instanceof RuntimeException) {
            $mensagem = $e->getMessage();
        }

        redirecionar_admin('erro', $mensagem);
    }
}

$ok = trim((string) ($_GET['ok'] ?? ''));
$erro = trim((string) ($_GET['erro'] ?? ''));
$scrollRestaure = max(0, (int) ($_GET['scroll'] ?? 0));


$usuarios = $pdo->query('SELECT id, nome, usuario, perfil, criado_em FROM usuarios ORDER BY perfil DESC, nome ASC')->fetchAll();
$professores = $pdo->query("SELECT id, nome, usuario FROM usuarios WHERE perfil = 'professor' ORDER BY nome ASC")->fetchAll();
$turmas = $pdo->query('SELECT id, nome, ano_letivo FROM turmas ORDER BY ano_letivo DESC, nome ASC')->fetchAll();

$disciplinas = $pdo->query(
    'SELECT d.id, d.nome, d.turma_id, t.nome AS turma_nome
     FROM disciplinas d
     INNER JOIN turmas t ON t.id = d.turma_id
     ORDER BY t.nome ASC, d.nome ASC'
)->fetchAll();

garantir_tabela_professor_materia($pdo);
$materiasCatalogo = $pdo->query('SELECT DISTINCT nome FROM disciplinas ORDER BY nome ASC')->fetchAll();
$materiasCatalogo = array_values(array_filter(array_map(static fn (array $row): string => trim((string) $row['nome']), $materiasCatalogo), static fn (string $nome): bool => $nome !== ''));

$materiasProfessorMap = [];
$materiasProfessorRows = $pdo->query('SELECT professor_id, nome_materia FROM professor_materia ORDER BY professor_id, nome_materia')->fetchAll();
foreach ($materiasProfessorRows as $row) {
    $profId = (int) $row['professor_id'];
    $nomeMateria = trim((string) $row['nome_materia']);
    if ($profId <= 0 || $nomeMateria === '') {
        continue;
    }

    if (!isset($materiasProfessorMap[$profId])) {
        $materiasProfessorMap[$profId] = [];
    }

    if (!in_array($nomeMateria, $materiasProfessorMap[$profId], true)) {
        $materiasProfessorMap[$profId][] = $nomeMateria;
    }
}

$alunos = $pdo->query(
    'SELECT a.id, a.nome, a.turma_id, t.nome AS turma_nome
     FROM alunos a
     INNER JOIN turmas t ON t.id = a.turma_id
     ORDER BY t.nome ASC, a.nome ASC'
)->fetchAll();

$vinculos = $pdo->query(
    'SELECT pt.id, pt.professor_id, pt.turma_id, u.nome AS professor_nome, t.nome AS turma_nome
     FROM professor_turma pt
     INNER JOIN usuarios u ON u.id = pt.professor_id
     INNER JOIN turmas t ON t.id = pt.turma_id
     ORDER BY u.nome ASC, t.nome ASC'
)->fetchAll();

$vinculosPorProfessor = [];
foreach ($vinculos as $vinculoItem) {
    $p = (int) $vinculoItem['professor_id'];
    $t = (int) $vinculoItem['turma_id'];
    if (!isset($vinculosPorProfessor[$p])) {
        $vinculosPorProfessor[$p] = [];
    }
    $vinculosPorProfessor[$p][] = $t;
}

$configRisco = obter_config_risco($pdo);
$configAvaliacao = obter_config_avaliacao($pdo);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
    <script>
        (function () {
            window.__adminScrollFromServer = <?php echo $scrollRestaure; ?>;
            if (window.__adminScrollFromServer > 0) {
                document.documentElement.classList.add('restore-scroll-pending');
            }
        })();
    </script>
</head>
<body class="app-page admin-page">
<div class="container admin-container">
    <div class="topbar admin-topbar">
        <div>
            <h1 style="margin: 0;">Painel Admin</h1>
            <small>Ola, <?php echo htmlspecialchars($usuario['nome']); ?>. Voce pode gerenciar toda a plataforma.</small>
        </div>
        <div class="actions-row">
            <a class="btn btn-secondary btn-icon" data-icon="groups" href="/frequencia">Frequencia</a>
            <a class="btn btn-secondary btn-icon" data-icon="history_edu" href="/historico">Historico</a>
            <a class="btn btn-secondary btn-icon" data-icon="fact_check" href="/conselho">Conselho</a>
            <a class="btn btn-secondary btn-icon" data-icon="bar_chart" href="/relatorios">Relatorios</a>
            <a class="btn btn-secondary btn-icon" data-icon="grading" href="/rubricas">Rubricas</a>
            <a class="btn btn-secondary btn-icon" data-icon="insights" href="/analytics-avancado">Analytics avancado</a>
            <a class="btn btn-secondary btn-icon" data-icon="notifications" href="/notificacoes">Notificacoes</a>
            <a class="btn btn-secondary btn-icon" data-icon="manage_search" href="/auditoria">Auditoria</a>
            <a class="btn btn-secondary btn-icon" data-icon="backup" href="/backup">Backup</a>
            <a class="btn btn-secondary" href="/dashboard">Ver dashboard</a>
            <a class="btn" href="/logout">Sair</a>
        </div>
    </div>

    <?php if ($ok): ?>
        <p class="ok"><?php echo htmlspecialchars($ok); ?></p>
    <?php endif; ?>
    <?php if ($erro): ?>
        <p class="error"><?php echo htmlspecialchars($erro); ?></p>
    <?php endif; ?>

    <div class="admin-metrics">
        <div class="admin-metric-card">
            <span>Usuarios</span>
            <strong><?php echo count($usuarios); ?></strong>
        </div>
        <div class="admin-metric-card">
            <span>Professores</span>
            <strong><?php echo count($professores); ?></strong>
        </div>
        <div class="admin-metric-card">
            <span>Turmas</span>
            <strong><?php echo count($turmas); ?></strong>
        </div>
        <div class="admin-metric-card">
            <span>Disciplinas</span>
            <strong><?php echo count($disciplinas); ?></strong>
        </div>
        <div class="admin-metric-card">
            <span>Alunos</span>
            <strong><?php echo count($alunos); ?></strong>
        </div>
        <div class="admin-metric-card">
            <span>Vinculos</span>
            <strong><?php echo count($vinculos); ?></strong>
        </div>
    </div>

    <div class="card">
        <h2>Usuarios</h2>
        <p><small>Para trocar a senha de qualquer usuario, preencha o campo "Nova senha (opcional)" e clique em Salvar.</small></p>
        <form method="post" class="grid grid-5">
            <input type="hidden" name="acao" value="criar_usuario">
            <div>
                <label>Nome</label>
                <input name="nome" required>
            </div>
            <div>
                <label>Usuario (login/email)</label>
                <input name="usuario" required>
            </div>
            <div>
                <label>Senha</label>
                <input name="senha" type="password" required>
            </div>
            <div>
                <label>Perfil</label>
                <select name="perfil" id="novo_usuario_perfil">
                    <option value="professor">Professor</option>
                    <option value="diretora">Diretora</option>
                    <option value="coordenacao_pedagogica">Coordenacao Pedagogica</option>
                    <option value="secretario">Secretarios</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div id="novo_usuario_materias_wrap">
                <label>Materias do professor</label>
                <select name="materia_professor" id="novo_usuario_materias">
                    <?php foreach ($materiasCatalogo as $materiaNome): ?>
                        <option value="<?php echo htmlspecialchars($materiaNome); ?>"><?php echo htmlspecialchars($materiaNome); ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Selecione as materias base do professor. Ao vincular turma, o sistema aplica automaticamente.</small>
            </div>
            <div>
                <button type="submit">Criar usuario</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Usuario</th>
                    <th>Perfil</th>
                    <th>Acoes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $item): ?>
                    <tr>
                        <td><?php echo (int) $item['id']; ?></td>
                        <td><?php echo htmlspecialchars($item['nome']); ?></td>
                        <td><?php echo htmlspecialchars($item['usuario']); ?></td>
                        <td><?php echo htmlspecialchars($item['perfil']); ?></td>
                        <td>
                            <form method="post" class="inline-form user-update-form">
                                <input type="hidden" name="acao" value="atualizar_usuario">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <input name="nome" value="<?php echo htmlspecialchars($item['nome']); ?>" required>
                                <input name="usuario" value="<?php echo htmlspecialchars($item['usuario']); ?>" required>
                                <select name="perfil" class="perfil-select">
                                    <option value="professor" <?php echo $item['perfil'] === 'professor' ? 'selected' : ''; ?>>Professor</option>
                                    <option value="diretora" <?php echo $item['perfil'] === 'diretora' ? 'selected' : ''; ?>>Diretora</option>
                                    <option value="coordenacao_pedagogica" <?php echo $item['perfil'] === 'coordenacao_pedagogica' ? 'selected' : ''; ?>>Coordenacao Pedagogica</option>
                                    <option value="secretario" <?php echo $item['perfil'] === 'secretario' ? 'selected' : ''; ?>>Secretarios</option>
                                    <option value="admin" <?php echo $item['perfil'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                                <div class="usuario-materias-wrap" data-visible="<?php echo $item['perfil'] === 'professor' ? '1' : '0'; ?>">
                                    <label>Materias do professor</label>
                                    <select name="materia_professor">
                                        <?php $materiasSelecionadas = $materiasProfessorMap[(int) $item['id']] ?? []; $materiaSelecionadaAtual = $materiasSelecionadas[0] ?? ''; ?>
                                        <?php foreach ($materiasCatalogo as $materiaNome): ?>
                                            <option value="<?php echo htmlspecialchars($materiaNome); ?>" <?php echo $materiaNome === $materiaSelecionadaAtual ? 'selected' : ''; ?>><?php echo htmlspecialchars($materiaNome); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input name="nova_senha" type="password" placeholder="Nova senha (opcional)">
                                <button type="submit">Salvar</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('Excluir este usuario?');">
                                <input type="hidden" name="acao" value="deletar_usuario">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <button type="submit" class="btn-danger">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Turmas</h2>
        <form method="post" class="grid grid-3">
            <input type="hidden" name="acao" value="criar_turma">
            <div>
                <label>Nome da turma</label>
                <input name="nome" placeholder="Ex: 8A" required>
            </div>
            <div>
                <label>Ano letivo</label>
                <input name="ano_letivo" type="number" min="2001" required>
            </div>
            <div>
                <button type="submit">Criar turma</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Ano</th>
                    <th>Acoes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($turmas as $item): ?>
                    <tr>
                        <td><?php echo (int) $item['id']; ?></td>
                        <td><?php echo htmlspecialchars($item['nome']); ?></td>
                        <td><?php echo (int) $item['ano_letivo']; ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="acao" value="atualizar_turma">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <input name="nome" value="<?php echo htmlspecialchars($item['nome']); ?>" required>
                                <input name="ano_letivo" type="number" min="2001" value="<?php echo (int) $item['ano_letivo']; ?>" required>
                                <button type="submit">Salvar</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('Excluir esta turma e dados relacionados?');">
                                <input type="hidden" name="acao" value="deletar_turma">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <button type="submit" class="btn-danger">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Disciplinas</h2>
        <form method="post" class="grid grid-3">
            <input type="hidden" name="acao" value="criar_disciplina">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo (int) $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Nome da disciplina</label>
                <input name="nome" required>
            </div>
            <div>
                <button type="submit">Criar disciplina</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Disciplina</th>
                    <th>Turma</th>
                    <th>Acoes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($disciplinas as $item): ?>
                    <tr>
                        <td><?php echo (int) $item['id']; ?></td>
                        <td><?php echo htmlspecialchars($item['nome']); ?></td>
                        <td><?php echo htmlspecialchars($item['turma_nome']); ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="acao" value="atualizar_disciplina">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <select name="turma_id" required>
                                    <?php foreach ($turmas as $turma): ?>
                                        <option value="<?php echo (int) $turma['id']; ?>" <?php echo (int) $item['turma_id'] === (int) $turma['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($turma['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="nome" value="<?php echo htmlspecialchars($item['nome']); ?>" required>
                                <button type="submit">Salvar</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('Excluir esta disciplina?');">
                                <input type="hidden" name="acao" value="deletar_disciplina">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <button type="submit" class="btn-danger">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Alunos</h2>
        <form method="post" class="grid grid-3">
            <input type="hidden" name="acao" value="criar_aluno">
            <div>
                <label>Turma</label>
                <select name="turma_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo (int) $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Nome do aluno</label>
                <input name="nome" required>
            </div>
            <div>
                <button type="submit">Criar aluno</button>
            </div>
        </form>

                <div class="admin-aluno-filtros">
            <div>
                <label for="filtro_aluno_turma">Filtrar turma</label>
                <select id="filtro_aluno_turma">
                    <option value="">Todas as turmas</option>
                    <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo (int) $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filtro_aluno_nome">Pesquisar por nome</label>
                <input id="filtro_aluno_nome" type="search" placeholder="Digite o nome do aluno" autocomplete="off">
            </div>
            <div class="admin-filtro-acoes">
                <button type="button" id="limpar_filtros_aluno" class="btn btn-secondary">Limpar filtros</button>
            </div>
        </div>
        <small id="filtro_aluno_resumo" class="admin-aluno-resumo"></small>
<div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Aluno</th>
                    <th>Turma</th>
                    <th>Acoes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($alunos as $item): ?>
                    <tr data-aluno-turma-id="<?php echo (int) $item['turma_id']; ?>" data-aluno-nome="<?php echo htmlspecialchars((string) $item['nome'], ENT_QUOTES, 'UTF-8'); ?>">
                        <td><?php echo (int) $item['id']; ?></td>
                        <td><?php echo htmlspecialchars($item['nome']); ?></td>
                        <td><?php echo htmlspecialchars($item['turma_nome']); ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="acao" value="atualizar_aluno">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <select name="turma_id" required>
                                    <?php foreach ($turmas as $turma): ?>
                                        <option value="<?php echo (int) $turma['id']; ?>" <?php echo (int) $item['turma_id'] === (int) $turma['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($turma['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="nome" value="<?php echo htmlspecialchars($item['nome']); ?>" required>
                                <button type="submit">Salvar</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('Excluir este aluno?');">
                                <input type="hidden" name="acao" value="deletar_aluno">
                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                <button type="submit" class="btn-danger">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Configuracao de Risco</h2>
        <p><small>Ajuste pesos e limiares para classificar alunos em risco Baixo, Moderado ou Alto.</small></p>
        <form method="post" class="grid grid-4">
            <input type="hidden" name="acao" value="salvar_config_risco">
            <div>
                <label>Peso media</label>
                <input type="number" name="peso_media" min="0" max="10" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['peso_media'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <label>Peso faltas</label>
                <input type="number" name="peso_faltas" min="0" max="10" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['peso_faltas'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <label>Peso atrasos</label>
                <input type="number" name="peso_atrasos" min="0" max="10" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['peso_atrasos'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <label>Limiar media</label>
                <input type="number" name="limiar_media" min="0.01" max="10" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['limiar_media'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <label>Limiar faltas (%)</label>
                <input type="number" name="limiar_faltas_percentual" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['limiar_faltas_percentual'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <label>Limiar atrasos</label>
                <input type="number" name="limiar_atrasos" min="0" max="100" step="1" value="<?php echo (int) $configRisco['limiar_atrasos']; ?>" required>
            </div>
            <div>
                <label>Score moderado</label>
                <input type="number" name="limiar_score_moderado" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['limiar_score_moderado'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <label>Score alto</label>
                <input type="number" name="limiar_score_alto" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $configRisco['limiar_score_alto'], 2, '.', '')); ?>" required>
            </div>
            <div>
                <button type="submit">Salvar risco</button>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>Configuracao de Arredondamento</h2>
        <p><small>Define como as medias sao arredondadas em todo o lancamento de notas.</small></p>
        <form method="post" class="grid grid-3">
            <input type="hidden" name="acao" value="salvar_config_arredondamento">
            <div>
                <label>Modo</label>
                <select name="modo_arredondamento" required>
                    <option value="comercial" <?php echo $configAvaliacao['modo_arredondamento'] === 'comercial' ? 'selected' : ''; ?>>Comercial (half-up)</option>
                    <option value="cima" <?php echo $configAvaliacao['modo_arredondamento'] === 'cima' ? 'selected' : ''; ?>>Sempre para cima</option>
                    <option value="baixo" <?php echo $configAvaliacao['modo_arredondamento'] === 'baixo' ? 'selected' : ''; ?>>Sempre para baixo</option>
                </select>
            </div>
            <div>
                <label>Casas decimais (0 a 2)</label>
                <input type="number" name="casas_decimais" min="0" max="2" value="<?php echo (int) $configAvaliacao['casas_decimais']; ?>" required>
            </div>
            <div>
                <button type="submit">Salvar configuracao</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Vinculo Professor x Turma</h2>
        <form method="post" class="inline-form" style="margin-bottom: 1rem;">
            <input type="hidden" name="acao" value="reconstruir_vinculos_professor_disciplina">
            <button type="submit">Reconstruir vinculos professor x materia</button>
        </form>
        <form method="post" class="grid grid-3">
            <input type="hidden" name="acao" value="vincular_professor_turma">
            <div>
                <label>Professor</label>
                <select name="professor_id" id="vinculo_professor_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($professores as $professor): ?>
                        <option value="<?php echo (int) $professor['id']; ?>">
                            <?php echo htmlspecialchars($professor['nome']); ?> (<?php echo htmlspecialchars($professor['usuario']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Turma</label>
                <select name="turma_id" id="vinculo_turma_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo (int) $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Vincular</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Professor</th>
                    <th>Turmas vinculadas</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $vinculosAgrupadosView = [];
                foreach ($vinculos as $vItem) {
                    $pid = (int) $vItem['professor_id'];
                    if (!isset($vinculosAgrupadosView[$pid])) {
                        $vinculosAgrupadosView[$pid] = [
                            'professor_nome' => (string) $vItem['professor_nome'],
                            'turmas' => [],
                        ];
                    }

                    $vinculosAgrupadosView[$pid]['turmas'][] = [
                        'id' => (int) $vItem['id'],
                        'turma_nome' => (string) $vItem['turma_nome'],
                    ];
                }
                ?>
                <?php foreach ($vinculosAgrupadosView as $grupo): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($grupo['professor_nome']); ?></td>
                        <td>
                            <div class="admin-vinculo-turmas-list">
                                <?php foreach ($grupo['turmas'] as $tv): ?>
                                    <form method="post" class="inline-form admin-vinculo-form" onsubmit="return confirm('Remover este vinculo?');">
                                        <input type="hidden" name="acao" value="remover_vinculo">
                                        <input type="hidden" name="id" value="<?php echo (int) $tv['id']; ?>">
                                        <span class="admin-vinculo-chip"><?php echo htmlspecialchars($tv['turma_nome']); ?></span>
                                        <button type="submit" class="btn-vinculo-remover" title="Desvincular" aria-label="Desvincular">
                                            <span aria-hidden="true">&#128465;</span>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    (function () {
        const serverScroll = Number(window.__adminScrollFromServer || 0);

        const filtroTurmaAluno = document.getElementById('filtro_aluno_turma');
        const filtroNomeAluno = document.getElementById('filtro_aluno_nome');
        const limparFiltrosAluno = document.getElementById('limpar_filtros_aluno');
        const resumoFiltroAluno = document.getElementById('filtro_aluno_resumo');
        const linhasAlunos = Array.from(document.querySelectorAll('tr[data-aluno-turma-id]'));

        const novoPerfilSelect = document.getElementById('novo_usuario_perfil');
        const novoMateriasWrap = document.getElementById('novo_usuario_materias_wrap');
        const novoMateriasSelect = document.getElementById('novo_usuario_materias');

        const formulariosAtualizarUsuario = Array.from(document.querySelectorAll('form.user-update-form'));

        const vinculoProfessorSelect = document.getElementById('vinculo_professor_id');
        const vinculoTurmaSelect = document.getElementById('vinculo_turma_id');
        const vinculosProfessorMap = <?php echo json_encode($vinculosPorProfessor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};

        const normalizar = (texto) => (texto || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();

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

        const configurarPersistenciaScroll = () => {
            const formsPost = document.querySelectorAll('form[method="post"]');
            formsPost.forEach((form) => {
                form.addEventListener('submit', () => {
                    salvarScrollNoFormulario(form);
                });
            });
        };

        const configurarFiltrosAlunos = () => {
            if (!filtroTurmaAluno || !filtroNomeAluno || linhasAlunos.length === 0) {
                if (resumoFiltroAluno) {
                    resumoFiltroAluno.textContent = 'Mostrando 0 de 0 alunos';
                }
                return;
            }

            const total = linhasAlunos.length;

            const aplicarFiltros = () => {
                const turmaId = (filtroTurmaAluno.value || '').trim();
                const termoNome = normalizar(filtroNomeAluno.value || '');
                let visiveis = 0;

                linhasAlunos.forEach((linha) => {
                    const nomeLinha = normalizar(linha.getAttribute('data-aluno-nome') || '');
                    const turmaLinha = (linha.getAttribute('data-aluno-turma-id') || '').trim();

                    const passaTurma = turmaId === '' || turmaLinha === turmaId;
                    const passaNome = termoNome === '' || nomeLinha.includes(termoNome);
                    const mostrar = passaTurma && passaNome;

                    linha.style.display = mostrar ? '' : 'none';
                    if (mostrar) {
                        visiveis += 1;
                    }
                });

                if (resumoFiltroAluno) {
                    resumoFiltroAluno.textContent = 'Mostrando ' + visiveis + ' de ' + total + ' alunos';
                }
            };

            filtroTurmaAluno.addEventListener('change', aplicarFiltros);
            filtroNomeAluno.addEventListener('input', aplicarFiltros);

            if (limparFiltrosAluno) {
                limparFiltrosAluno.addEventListener('click', () => {
                    filtroTurmaAluno.value = '';
                    filtroNomeAluno.value = '';
                    aplicarFiltros();
                });
            }

            aplicarFiltros();
        };

        const atualizarVisibilidadeMateriasNovoUsuario = () => {
            if (!novoPerfilSelect || !novoMateriasWrap) {
                return;
            }

            const ehProfessor = (novoPerfilSelect.value || '') === 'professor';
            novoMateriasWrap.style.display = ehProfessor ? '' : 'none';

            if (novoMateriasSelect) {
                novoMateriasSelect.disabled = !ehProfessor;
            }
        };

        const configurarCamposMateriasPorUsuario = () => {
            if (formulariosAtualizarUsuario.length === 0) {
                return;
            }

            formulariosAtualizarUsuario.forEach((form) => {
                const perfilSelect = form.querySelector('select[name="perfil"]');
                const materiasWrap = form.querySelector('.usuario-materias-wrap');
                const materiasSelect = form.querySelector('select[name="materia_professor"]');

                if (!perfilSelect || !materiasWrap || !materiasSelect) {
                    return;
                }

                const atualizar = () => {
                    const ehProfessor = (perfilSelect.value || '') === 'professor';
                    materiasWrap.style.display = ehProfessor ? '' : 'none';
                    materiasSelect.disabled = !ehProfessor;
                };

                perfilSelect.addEventListener('change', atualizar);
                atualizar();
            });
        };

        const configurarFiltroTurmasVinculo = () => {
            if (!vinculoProfessorSelect || !vinculoTurmaSelect) {
                return;
            }

            const opcoesOriginais = Array.from(vinculoTurmaSelect.options).map((opt) => ({
                value: opt.value,
                label: opt.textContent || '',
            }));

            const renderizarOpcoes = () => {
                const professorId = (vinculoProfessorSelect.value || '').trim();
                const turmasJaVinculadas = new Set((vinculosProfessorMap[professorId] || []).map((id) => String(id)));
                const valorAtual = (vinculoTurmaSelect.value || '').trim();

                vinculoTurmaSelect.innerHTML = '';

                opcoesOriginais.forEach((opcao) => {
                    if (opcao.value !== '' && turmasJaVinculadas.has(opcao.value)) {
                        return;
                    }

                    const option = document.createElement('option');
                    option.value = opcao.value;
                    option.textContent = opcao.label;
                    vinculoTurmaSelect.appendChild(option);
                });

                if (valorAtual && Array.from(vinculoTurmaSelect.options).some((opt) => opt.value === valorAtual)) {
                    vinculoTurmaSelect.value = valorAtual;
                } else {
                    vinculoTurmaSelect.value = '';
                }
            };

            vinculoProfessorSelect.addEventListener('change', renderizarOpcoes);
            renderizarOpcoes();
        };

        restoreScrollIfNeeded();
        configurarPersistenciaScroll();
        configurarFiltrosAlunos();
        atualizarVisibilidadeMateriasNovoUsuario();
        configurarCamposMateriasPorUsuario();
        configurarFiltroTurmasVinculo();

        if (novoPerfilSelect) {
            novoPerfilSelect.addEventListener('change', atualizarVisibilidadeMateriasNovoUsuario);
        }
    })();
</script>
<footer class="site-footer">Desenvolvido por SPKR</footer>
</body>
</html>




