<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/avaliacao_config.php';
require_once __DIR__ . '/../src/avaliacao_periodos.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';
require_once __DIR__ . '/../src/publicacao_notas.php';

exigir_login();

$usuario = usuario_logado();
$turmaId = (int) ($_GET['turma_id'] ?? 0);
$etapa = trim($_POST['etapa'] ?? ($_GET['etapa'] ?? '1_bimestre'));
$disciplinaId = (int) ($_POST['disciplina_id'] ?? ($_GET['disciplina_id'] ?? 0));
$msg = '';
$erro = '';

$configAvaliacao = obter_config_avaliacao(db());
$casasDecimais = (int) $configAvaliacao['casas_decimais'];
$stepNota = passo_nota($configAvaliacao);
$modoRotuloMap = [
    'comercial' => 'Comercial (half-up)',
    'cima' => 'Sempre para cima',
    'baixo' => 'Sempre para baixo',
];
$modoRotulo = $modoRotuloMap[$configAvaliacao['modo_arredondamento']] ?? 'Comercial (half-up)';
$notaMinimaAprovacao = 5.0;
$perfilAtual = perfil_usuario($usuario);
$ehProfessor = $perfilAtual === 'professor';
$periodosAvaliacao = obter_periodos_avaliacao(db());
$statusPeriodoAtual = status_periodo_avaliacao($periodosAvaliacao, $etapa);
$periodoAberto = (bool) ($statusPeriodoAtual['aberto'] ?? false);

$componentes = [
    ['campo' => 'componente1', 'status' => 'componente1_status', 'rotulo' => 'CAED'],
    ['campo' => 'componente2', 'status' => 'componente2_status', 'rotulo' => 'Trabalho'],
    ['campo' => 'componente3', 'status' => 'componente3_status', 'rotulo' => 'Teste'],
    ['campo' => 'componente4', 'status' => 'componente4_status', 'rotulo' => 'Prova'],
    ['campo' => 'componente5', 'status' => 'componente5_status', 'rotulo' => 'Recup. Paralela'],
];

if ($turmaId <= 0) {
    header('Location: /dashboard');
    exit;
}

if (!usuario_eh_manager($usuario, true)) {
    $acessoStmt = db()->prepare('SELECT 1 FROM professor_turma WHERE professor_id = :professor_id AND turma_id = :turma_id LIMIT 1');
    $acessoStmt->execute(['professor_id' => $usuario['id'], 'turma_id' => $turmaId]);
    if (!$acessoStmt->fetch()) {
        http_response_code(403);
        echo 'Acesso negado para esta turma.';
        exit;
    }
}

$turmaStmt = db()->prepare('SELECT id, nome, ano_letivo FROM turmas WHERE id = :id');
$turmaStmt->execute(['id' => $turmaId]);
$turma = $turmaStmt->fetch();
if (!$turma) {
    header('Location: /dashboard');
    exit;
}
if ($ehProfessor) {
    garantir_tabela_professor_disciplina(db());
    $disciplinasStmt = db()->prepare(
        'SELECT d.id, d.nome
         FROM professor_disciplina pd
         INNER JOIN disciplinas d ON d.id = pd.disciplina_id
         WHERE pd.professor_id = :professor_id
           AND d.turma_id = :turma_id
         ORDER BY d.nome'
    );
    $disciplinasStmt->execute([
        'professor_id' => (int) ($usuario['id'] ?? 0),
        'turma_id' => $turmaId,
    ]);
} else {
    $disciplinasStmt = db()->prepare('SELECT id, nome FROM disciplinas WHERE turma_id = :turma_id ORDER BY nome');
    $disciplinasStmt->execute(['turma_id' => $turmaId]);
}
$disciplinas = $disciplinasStmt->fetchAll();

if ($ehProfessor && !$disciplinas) {
    http_response_code(403);
    echo 'Acesso negado: voce nao possui materias vinculadas nesta turma.';
    exit;
}

$disciplinasPermitidasIds = array_map(static fn (array $item): int => (int) $item['id'], $disciplinas);
if ($disciplinaId > 0 && !in_array($disciplinaId, $disciplinasPermitidasIds, true)) {
    if ($ehProfessor) {
        http_response_code(403);
        echo 'Acesso negado para esta disciplina.';
        exit;
    }

    $disciplinaId = 0;
}

if ($disciplinaId === 0 && $disciplinas) {
    $disciplinaId = (int) $disciplinas[0]['id'];
}

$configPublicacao = obter_config_publicacao_notas(db());
$modoPublicacao = (string) ($configPublicacao['modo_publicacao'] ?? 'manual');
$publicadoAtualInfo = $disciplinaId > 0 ? notas_publicadas_info(db(), $turmaId, $disciplinaId, $etapa) : null;
$publicadoAtual = $modoPublicacao === 'automatica' ? true : $publicadoAtualInfo !== null;

$edicaoBloqueadaPublicacao = $modoPublicacao === 'manual' && $publicadoAtual;


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (isset($_POST['salvar_notas']) || isset($_POST['limpar_disciplina']) || isset($_POST['publicar_notas']) || isset($_POST['despublicar_notas']))) {
    $disciplinaCarregada = (int) ($_POST['disciplina_carregada'] ?? $disciplinaId);
    $etapaCarregada = trim((string) ($_POST['etapa_carregada'] ?? $etapa));

    $isAcaoPublicacao = isset($_POST['publicar_notas']) || isset($_POST['despublicar_notas']);

    if ($disciplinaCarregada !== $disciplinaId || $etapaCarregada !== $etapa) {
        $erro = 'Disciplina/etapa alterada antes de salvar. Aguarde a recarga da pagina e tente novamente.';
    } elseif ($disciplinaId <= 0) {
        $erro = 'Selecione uma disciplina.';
    } elseif ($isAcaoPublicacao) {
        if ($modoPublicacao !== 'manual') {
            $erro = 'Publicacao manual esta desativada. Troque o modo no painel admin para usar publicar/despublicar.';
        } elseif (isset($_POST['publicar_notas'])) {
            publicar_notas_etapa(db(), $turmaId, $disciplinaId, $etapa, (int) ($usuario['id'] ?? 0));
            $msg = 'Notas publicadas para boletim.';
        } else {
            despublicar_notas_etapa(db(), $turmaId, $disciplinaId, $etapa);
            $msg = 'Publicacao removida para esta disciplina/etapa.';
        }
    } elseif ($edicaoBloqueadaPublicacao) {
        $erro = 'Esta disciplina/etapa ja foi publicada. Despublique para editar ou limpar as notas.';
    } elseif (!$periodoAberto) {
        $erro = 'Periodo fechado para lancamento nesta etapa. Ajuste o calendario no painel admin.';
    } elseif (isset($_POST['limpar_disciplina'])) {
        $limparStmt = db()->prepare(
            'DELETE FROM notas
             WHERE turma_id = :turma_id AND disciplina_id = :disciplina_id AND etapa = :etapa'
        );
        $limparStmt->execute([
            'turma_id' => $turmaId,
            'disciplina_id' => $disciplinaId,
            'etapa' => $etapa,
        ]);
        if ($modoPublicacao === 'manual') {
            despublicar_notas_etapa(db(), $turmaId, $disciplinaId, $etapa);
        }
        $msg = 'Notas da disciplina/etapa atual foram limpas.';
    } else {
        $existentesStmt = db()->prepare(
            'SELECT aluno_id
             FROM notas
             WHERE turma_id = :turma_id AND disciplina_id = :disciplina_id AND etapa = :etapa'
        );
        $existentesStmt->execute([
            'turma_id' => $turmaId,
            'disciplina_id' => $disciplinaId,
            'etapa' => $etapa,
        ]);
        $notasExistentes = [];
        foreach ($existentesStmt->fetchAll() as $linhaExistente) {
            $notasExistentes[(int) $linhaExistente['aluno_id']] = true;
        }

        $deleteNota = db()->prepare(
            'DELETE FROM notas
             WHERE aluno_id = :aluno_id AND turma_id = :turma_id AND disciplina_id = :disciplina_id AND etapa = :etapa'
        );

        if (usando_sqlite(db())) {
            $upsert = db()->prepare(
                'INSERT INTO notas (
                    aluno_id, turma_id, disciplina_id, etapa,
                    componente1, componente1_status,
                    componente2, componente2_status,
                    componente3, componente3_status,
                    componente4, componente4_status,
                    componente5, componente5_status,
                    media_base,
                    peso_diferenciado,
                    justificativa_peso,
                    media_ajustada_peso,
                    recuperacao_nota,
                    recuperacao_aplicada,
                    media
                 ) VALUES (
                    :aluno_id, :turma_id, :disciplina_id, :etapa,
                    :componente1, :componente1_status,
                    :componente2, :componente2_status,
                    :componente3, :componente3_status,
                    :componente4, :componente4_status,
                    :componente5, :componente5_status,
                    :media_base,
                    :peso_diferenciado,
                    :justificativa_peso,
                    :media_ajustada_peso,
                    :recuperacao_nota,
                    :recuperacao_aplicada,
                    :media
                 )'
            );
        } else {
            $upsert = db()->prepare(
                'INSERT INTO notas (
                    aluno_id, turma_id, disciplina_id, etapa,
                    componente1, componente1_status,
                    componente2, componente2_status,
                    componente3, componente3_status,
                    componente4, componente4_status,
                    componente5, componente5_status,
                    media_base,
                    peso_diferenciado,
                    justificativa_peso,
                    media_ajustada_peso,
                    recuperacao_nota,
                    recuperacao_aplicada,
                    media
                 ) VALUES (
                    :aluno_id, :turma_id, :disciplina_id, :etapa,
                    :componente1, :componente1_status,
                    :componente2, :componente2_status,
                    :componente3, :componente3_status,
                    :componente4, :componente4_status,
                    :componente5, :componente5_status,
                    :media_base,
                    :peso_diferenciado,
                    :justificativa_peso,
                    :media_ajustada_peso,
                    :recuperacao_nota,
                    :recuperacao_aplicada,
                    :media
                 )
                 ON DUPLICATE KEY UPDATE
                    componente1 = VALUES(componente1),
                    componente1_status = VALUES(componente1_status),
                    componente2 = VALUES(componente2),
                    componente2_status = VALUES(componente2_status),
                    componente3 = VALUES(componente3),
                    componente3_status = VALUES(componente3_status),
                    componente4 = VALUES(componente4),
                    componente4_status = VALUES(componente4_status),
                    componente5 = VALUES(componente5),
                    componente5_status = VALUES(componente5_status),
                    media_base = VALUES(media_base),
                    peso_diferenciado = VALUES(peso_diferenciado),
                    justificativa_peso = VALUES(justificativa_peso),
                    media_ajustada_peso = VALUES(media_ajustada_peso),
                    recuperacao_nota = VALUES(recuperacao_nota),
                    recuperacao_aplicada = VALUES(recuperacao_aplicada),
                    media = VALUES(media),
                    atualizado_em = CURRENT_TIMESTAMP'
            );
        }

        $notasPost = $_POST['notas'] ?? [];
        $dispensasPost = $_POST['dispensas'] ?? [];
        $recuperacaoPost = $_POST['recuperacao'] ?? [];
        $pesosPost = $_POST['peso_diferenciado'] ?? [];
        $justificativasPost = $_POST['justificativa_peso'] ?? [];
        $linhasInvalidas = 0;

        foreach ($notasPost as $alunoId => $linha) {
            $alunoId = (int) $alunoId;
            if ($alunoId <= 0 || !is_array($linha)) {
                continue;
            }

            $valores = [];
            $statuses = [];
            $temRegistro = false;
            $invalida = false;
            $soma = 0.0;
            $divisor = 0;

            foreach ($componentes as $comp) {
                $campo = $comp['campo'];
                $campoStatus = $comp['status'];

                $raw = trim((string) ($linha[$campo] ?? ''));
                if ($raw !== '') {
                    $temRegistro = true;
                }

                $dispensado = isset($dispensasPost[$alunoId][$campo]) && (string) $dispensasPost[$alunoId][$campo] === '1';
                if ($dispensado) {
                    $temRegistro = true;
                    $valores[$campo] = 0.0;
                    $statuses[$campoStatus] = 'dispensado';
                    continue;
                }

                $valor = $raw === '' ? 0.0 : (float) str_replace(',', '.', $raw);
                if ($valor < 0 || $valor > 10) {
                    $invalida = true;
                    break;
                }

                $valores[$campo] = $valor;
                $statuses[$campoStatus] = 'normal';
                $soma += $valor;
                $divisor++;
            }

            if ($invalida) {
                $linhasInvalidas++;
                continue;
            }

            $recRaw = trim((string) ($recuperacaoPost[$alunoId] ?? ''));
            $recuperacaoNota = null;
            if ($recRaw !== '') {
                $temRegistro = true;
            }

            $pesoRaw = trim((string) ($pesosPost[$alunoId] ?? '1'));
            $peso = $pesoRaw === '' ? 1.0 : (float) str_replace(',', '.', $pesoRaw);
            if ($peso < 0.5 || $peso > 2.0) {
                $linhasInvalidas++;
                continue;
            }
            $peso = aplicar_arredondamento($peso, $configAvaliacao);

            $justificativa = trim((string) ($justificativasPost[$alunoId] ?? ''));
            if (abs($peso - 1.0) > 0.0001 && $justificativa === '') {
                $linhasInvalidas++;
                continue;
            }
            if (abs($peso - 1.0) <= 0.0001) {
                $justificativa = '';
            }

            if (!$temRegistro && abs($peso - 1.0) <= 0.0001) {
                if (isset($notasExistentes[$alunoId])) {
                    $deleteNota->execute([
                        'aluno_id' => $alunoId,
                        'turma_id' => $turmaId,
                        'disciplina_id' => $disciplinaId,
                        'etapa' => $etapa,
                    ]);
                    unset($notasExistentes[$alunoId]);
                }
                continue;
            }

            $mediaBaseRaw = $divisor > 0 ? ($soma / $divisor) : 0.0;
            $mediaBase = aplicar_arredondamento($mediaBaseRaw, $configAvaliacao);
            $mediaAjustadaPeso = aplicar_arredondamento(min(10.0, $mediaBase * $peso), $configAvaliacao);

            if ($recRaw !== '') {
                $recuperacaoNota = (float) str_replace(',', '.', $recRaw);
                if ($recuperacaoNota < 0 || $recuperacaoNota > 10) {
                    $linhasInvalidas++;
                    continue;
                }

                if ($mediaAjustadaPeso >= $notaMinimaAprovacao) {
                    $linhasInvalidas++;
                    continue;
                }

                $recuperacaoNota = aplicar_arredondamento($recuperacaoNota, $configAvaliacao);
            }

            $mediaFinal = $mediaAjustadaPeso;
            $recuperacaoAplicada = 0;

            if ($recuperacaoNota !== null) {
                $mediaFinal = aplicar_arredondamento(max($mediaAjustadaPeso, $recuperacaoNota), $configAvaliacao);
                $recuperacaoAplicada = $recuperacaoNota > $mediaAjustadaPeso ? 1 : 0;
            }

            $paramsUpsert = [
                'aluno_id' => $alunoId,
                'turma_id' => $turmaId,
                'disciplina_id' => $disciplinaId,
                'etapa' => $etapa,
                'componente1' => $valores['componente1'],
                'componente1_status' => $statuses['componente1_status'],
                'componente2' => $valores['componente2'],
                'componente2_status' => $statuses['componente2_status'],
                'componente3' => $valores['componente3'],
                'componente3_status' => $statuses['componente3_status'],
                'componente4' => $valores['componente4'],
                'componente4_status' => $statuses['componente4_status'],
                'componente5' => $valores['componente5'],
                'componente5_status' => $statuses['componente5_status'],
                'media_base' => $mediaBase,
                'peso_diferenciado' => $peso,
                'justificativa_peso' => $justificativa !== '' ? substr($justificativa, 0, 255) : null,
                'media_ajustada_peso' => $mediaAjustadaPeso,
                'recuperacao_nota' => $recuperacaoNota,
                'recuperacao_aplicada' => $recuperacaoAplicada,
                'media' => $mediaFinal,
            ];

            if (usando_sqlite(db())) {
                $deleteNota->execute([
                    'aluno_id' => $alunoId,
                    'turma_id' => $turmaId,
                    'disciplina_id' => $disciplinaId,
                    'etapa' => $etapa,
                ]);
            }

            $upsert->execute($paramsUpsert);
        }

        if ($linhasInvalidas > 0) {
            $erro = 'Algumas linhas foram ignoradas. Verifique notas (0 a 10), peso (0.5 a 2.0), justificativa obrigatoria quando peso for diferente de 1.00 e recuperacao permitida apenas para media abaixo de 5.00.';
        } else {
            if ($modoPublicacao === 'automatica') {
                publicar_notas_etapa(db(), $turmaId, $disciplinaId, $etapa, (int) ($usuario['id'] ?? 0));
                $msg = 'Notas salvas e publicadas automaticamente.';
            } else {
                $msg = 'Notas salvas com sucesso.';
            }
        }
    }
}

$alunosStmt = db()->prepare('SELECT id, nome FROM alunos WHERE turma_id = :turma_id ORDER BY nome');
$alunosStmt->execute(['turma_id' => $turmaId]);
$alunos = $alunosStmt->fetchAll();

$mapaNotas = [];
if ($disciplinaId > 0) {
    $notasStmt = db()->prepare(
        'SELECT
            aluno_id,
            componente1, componente1_status,
            componente2, componente2_status,
            componente3, componente3_status,
            componente4, componente4_status,
            componente5, componente5_status,
            media_base,
            peso_diferenciado,
            justificativa_peso,
            media_ajustada_peso,
            recuperacao_nota,
            recuperacao_aplicada,
            media
         FROM notas
         WHERE turma_id = :turma_id AND disciplina_id = :disciplina_id AND etapa = :etapa'
    );
    $notasStmt->execute(['turma_id' => $turmaId, 'disciplina_id' => $disciplinaId, 'etapa' => $etapa]);
    foreach ($notasStmt->fetchAll() as $linha) {
        $mapaNotas[(int) $linha['aluno_id']] = $linha;
    }
}

$publicadoAtualInfo = $disciplinaId > 0 ? notas_publicadas_info(db(), $turmaId, $disciplinaId, $etapa) : null;
$publicadoAtual = $modoPublicacao === 'automatica' ? true : $publicadoAtualInfo !== null;

$etapas = ['1_bimestre', '2_bimestre', '3_bimestre', '4_bimestre'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lancar Notas - Portal ECMNM</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/theme.js" defer></script>
    <script>
        (function () {
            try {
                const pendingKey = 'turma_scroll_pending_<?php echo (int) $turmaId; ?>';
                if (sessionStorage.getItem(pendingKey) === '1') {
                    document.documentElement.classList.add('restore-scroll-pending');
                }
            } catch (e) {
                // noop
            }
        })();
    </script>
</head>
<body class="app-page turma-page">
<div class="container">
    <div class="topbar">
        <div>
            <h1 style="margin:0;">Turma <?php echo htmlspecialchars($turma['nome']); ?></h1>
            <small>Ano letivo: <?php echo (int) $turma['ano_letivo']; ?></small>
        </div>
        <a class="btn btn-icon" data-icon="arrow_back" href="/dashboard">Voltar</a>
    </div>

    <div class="card">
        <h2>Exportar boletim PDF</h2>
        <div class="actions-row" style="flex-wrap: wrap;">
            <a class="btn btn-secondary btn-icon" data-icon="picture_as_pdf" href="/boletim_pdf?tipo=turma&turma_id=<?php echo $turmaId; ?>&etapa=<?php echo urlencode($etapa); ?>">Boletim da turma</a>
            <a class="btn btn-secondary btn-icon" data-icon="monitoring" href="/risco?turma_id=<?php echo $turmaId; ?>&etapa=<?php echo urlencode($etapa); ?>">Analytics de risco</a>
            <?php if (usuario_eh_manager($usuario, true)): ?>
                <a class="btn btn-secondary btn-icon" data-icon="insights" href="/analytics-avancado?turma_id=<?php echo $turmaId; ?>&etapa=<?php echo urlencode($etapa); ?>">Analytics avancado</a>
                <a class="btn btn-secondary btn-icon" data-icon="grading" href="/rubricas?turma_id=<?php echo $turmaId; ?>&disciplina_id=<?php echo $disciplinaId; ?>">Rubricas</a>
            <?php endif; ?>
            <form method="get" action="/boletim_pdf" class="inline-form">
                <input type="hidden" name="tipo" value="aluno">
                <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                <input type="hidden" name="etapa" value="<?php echo htmlspecialchars($etapa); ?>">

                <select name="aluno_id" required>
                    <option value="">Selecione um aluno</option>
                    <?php foreach ($alunos as $alunoExport): ?>
                        <option value="<?php echo (int) $alunoExport['id']; ?>"><?php echo htmlspecialchars($alunoExport['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$ehProfessor && $disciplinas): ?>
                    <div class="export-disciplinas">
                        <span>Materias no boletim:</span>
                        <div class="export-disciplinas-list">
                            <?php foreach ($disciplinas as $disciplinaExport): ?>
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="disciplina_ids[]" value="<?php echo (int) $disciplinaExport['id']; ?>" checked>
                                    <?php echo htmlspecialchars((string) $disciplinaExport['nome']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <button type="submit">Boletim por aluno</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Lancamento de notas</h2>
        <p><small>Arredondamento: <?php echo htmlspecialchars($modoRotulo); ?> (<?php echo $casasDecimais; ?> casas). Peso diferenciado: 0.5 a 2.0 com justificativa obrigatoria se diferente de 1.00. Recuperacao permitida apenas para media c/peso abaixo de <?php echo htmlspecialchars(number_format($notaMinimaAprovacao, 2, ',', '')); ?>.</small></p>
        <?php $periodoInfo = $statusPeriodoAtual['periodo'] ?? null; ?>
        <p><small>Periodo da etapa: <?php echo $periodoInfo ? htmlspecialchars((string) $periodoInfo['data_abertura']) . ' a ' . htmlspecialchars((string) $periodoInfo['data_fechamento']) : 'Nao configurado'; ?> | Status: <?php echo $periodoAberto ? 'Aberto' : 'Fechado'; ?></small></p>
        <?php if ($modoPublicacao === 'manual'): ?>
            <p><small>Publicacao do boletim: <?php echo $publicadoAtual ? 'Publicado' : 'Nao publicado'; ?><?php if ($publicadoAtualInfo && isset($publicadoAtualInfo['publicado_em'])): ?> (em <?php echo htmlspecialchars((string) $publicadoAtualInfo['publicado_em']); ?><?php if (!empty($publicadoAtualInfo['publicado_por_nome'])): ?> por <?php echo htmlspecialchars((string) $publicadoAtualInfo['publicado_por_nome']); ?><?php endif; ?>)<?php endif; ?>.</small></p>
        <?php else: ?>
            <p><small>Publicacao do boletim: Automatica (as notas salvas ja ficam disponiveis).</small></p>
        <?php endif; ?>

        <?php if ($erro): ?><p class="error"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>
        <?php if ($msg): ?><p class="ok"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>

        <form id="notas-form" method="post" autocomplete="off">
            <input type="hidden" name="turma_id" value="<?php echo $turmaId; ?>">
                <input type="hidden" name="etapa" value="<?php echo htmlspecialchars($etapa); ?>">

            <input type="hidden" name="disciplina_carregada" value="<?php echo $disciplinaId; ?>">
            <input type="hidden" name="etapa_carregada" value="<?php echo htmlspecialchars($etapa); ?>">
<label for="disciplina_id">Disciplina</label>
            <select id="disciplina_id" name="disciplina_id" required>
                <?php foreach ($disciplinas as $disciplina): ?>
                    <option value="<?php echo (int) $disciplina['id']; ?>" <?php echo $disciplinaId === (int) $disciplina['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($disciplina['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="etapa">Etapa</label>
            <select id="etapa" name="etapa">
                <?php foreach ($etapas as $opcao): ?>
                    <option value="<?php echo $opcao; ?>" <?php echo $etapa === $opcao ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(str_replace('_', ' ', $opcao)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($modoPublicacao === 'manual'): ?>
                <div class="actions-row" style="margin: 4px 0 12px; flex-wrap: wrap;">
                    <button type="submit" name="publicar_notas" value="1" class="btn btn-secondary" formnovalidate <?php echo $disciplinaId > 0 ? '' : 'disabled'; ?>>Publicar boletim desta disciplina/etapa</button>
                    <button type="submit" name="despublicar_notas" value="1" class="btn btn-danger" formnovalidate <?php echo $disciplinaId > 0 ? '' : 'disabled'; ?> onclick="return confirm('Despublicar esta disciplina/etapa para boletim?');">Despublicar</button>
                </div>
            <?php endif; ?>

            <div class="lote-toolbar">
                <strong>Lancamento em lote</strong>
                <div class="lote-controls">
                    <select id="lote_componente">
                        <?php foreach ($componentes as $comp): ?>
                            <option value="<?php echo htmlspecialchars($comp['campo']); ?>"><?php echo htmlspecialchars($comp['rotulo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input id="lote_nota" type="number" min="0" max="10" step="<?php echo $stepNota; ?>" placeholder="Nota">
                    <label class="dispensa-label lote-dispensa">
                        <input id="lote_dispensa" type="checkbox" value="1">
                        Marcar dispensa
                    </label>
                    <button type="button" id="aplicar_lote" class="btn btn-secondary">Aplicar em lote</button>
                    <button type="button" id="limpar_lote" class="btn btn-danger">Limpar componente</button>
                </div>
                <small id="lote_feedback"></small>
            </div>

            <div class="search-row">
                <label for="filtro_aluno">Pesquisar aluno</label>
                <input
                    id="filtro_aluno"
                    class="aluno-search"
                    type="search"
                    placeholder="Digite o nome do aluno para filtrar a tabela"
                    autocomplete="off"
                >
                <small id="filtro_resumo"></small>
            </div>

            <div class="table-mode-toolbar">
                <strong>Modo tabela para professores</strong>
                <button type="button" id="toggle-grade-compact" class="btn btn-secondary">Compactar linhas</button>
            </div>

            <div class="table-wrap table-wrap-grade">
                <table class="grade-notas">
                    <thead>
                    <tr>
                        <th>Aluno</th>
                        <?php foreach ($componentes as $comp): ?>
                            <th><?php echo htmlspecialchars($comp['rotulo']); ?></th>
                        <?php endforeach; ?>
                        <th>Media base</th>
                        <th>Peso</th>
                        <th>Justificativa</th>
                        <th>Media c/peso</th>
                        <th>Recuperacao</th>
                        <th>Final</th>
                        <th>Situacao</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($alunos as $aluno): ?>
                        <?php $notaLinha = $mapaNotas[(int) $aluno['id']] ?? []; ?>
                        <tr>
                            <td class="aluno-col"><?php echo htmlspecialchars($aluno['nome']); ?></td>
                            <?php foreach ($componentes as $comp): ?>
                                <?php
                                $campo = $comp['campo'];
                                $campoStatus = $comp['status'];
                                $statusAtual = (string) ($notaLinha[$campoStatus] ?? 'normal');
                                $dispensado = $statusAtual === 'dispensado';
                                $valorAtual = '';
                                if (!$dispensado && isset($notaLinha[$campo])) {
                                    $valorAtual = number_format((float) $notaLinha[$campo], $casasDecimais, '.', '');
                                }
                                ?>
                                <td>
                                    <div class="dispensa-box">
                                        <input
                                            class="nota-input"
                                            type="number"
                                            min="0"
                                            max="10"
                                            step="<?php echo $stepNota; ?>"
                                            name="notas[<?php echo (int) $aluno['id']; ?>][<?php echo $campo; ?>]"
                                            value="<?php echo htmlspecialchars($valorAtual); ?>"
                                        >
                                        <label class="dispensa-label">
                                            <input
                                                type="checkbox"
                                                name="dispensas[<?php echo (int) $aluno['id']; ?>][<?php echo $campo; ?>]"
                                                value="1"
                                                <?php echo $dispensado ? 'checked' : ''; ?>
                                            >
                                            Dispensa
                                        </label>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                            <td><?php echo htmlspecialchars(formatar_nota(isset($notaLinha['media_base']) ? (float) $notaLinha['media_base'] : null, $configAvaliacao)); ?></td>
                            <td>
                                <?php $pesoAtual = isset($notaLinha['peso_diferenciado']) ? (float) $notaLinha['peso_diferenciado'] : 1.0; ?>
                                <input
                                    class="nota-input"
                                    type="number"
                                    min="0.5"
                                    max="2"
                                    step="0.01"
                                    name="peso_diferenciado[<?php echo (int) $aluno['id']; ?>]"
                                    value="<?php echo htmlspecialchars(number_format($pesoAtual, 2, '.', '')); ?>"
                                >
                            </td>
                            <td>
                                <input
                                    class="justificativa-input"
                                    type="text"
                                    maxlength="255"
                                    name="justificativa_peso[<?php echo (int) $aluno['id']; ?>]"
                                    value="<?php echo htmlspecialchars((string) ($notaLinha['justificativa_peso'] ?? '')); ?>"
                                    placeholder="Obrigatoria se peso != 1.00"
                                >
                            </td>
                            <td><?php echo htmlspecialchars(formatar_nota(isset($notaLinha['media_ajustada_peso']) ? (float) $notaLinha['media_ajustada_peso'] : null, $configAvaliacao)); ?></td>
                            <td>
                                <?php
                                $mediaAjustadaLinha = isset($notaLinha['media_ajustada_peso']) ? (float) $notaLinha['media_ajustada_peso'] : null;
                                $podeRecuperacao = $mediaAjustadaLinha !== null && $mediaAjustadaLinha < $notaMinimaAprovacao;
                                $recVal = isset($notaLinha['recuperacao_nota']) ? (float) $notaLinha['recuperacao_nota'] : null;
                                ?>
                                <input
                                    class="nota-input"
                                    type="number"
                                    min="0"
                                    max="10"
                                    step="<?php echo $stepNota; ?>"
                                    name="recuperacao[<?php echo (int) $aluno['id']; ?>]"
                                    value="<?php echo $recVal !== null ? htmlspecialchars(number_format($recVal, $casasDecimais, '.', '')) : ''; ?>"
                                    <?php echo $podeRecuperacao ? '' : 'disabled'; ?>
                                    placeholder=""
                                    title="<?php echo $podeRecuperacao ? '' : 'Disponivel apenas para media abaixo de 5,00'; ?>"
                                >
                                <?php if (isset($notaLinha['recuperacao_aplicada']) && (int) $notaLinha['recuperacao_aplicada'] === 1): ?>
                                    <div><small class="badge-ok">Aplicada</small></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(formatar_nota(isset($notaLinha['media']) ? (float) $notaLinha['media'] : null, $configAvaliacao)); ?></td>
                            <td>
                                <?php
                                $mediaFinalLinha = isset($notaLinha['media']) ? (float) $notaLinha['media'] : null;
                                $mediaSituacao = $mediaFinalLinha;
                                if ($mediaSituacao === null && $mediaAjustadaLinha !== null) {
                                    $mediaSituacao = $mediaAjustadaLinha;
                                }

                                if ($mediaSituacao === null) {
                                    $situacaoTexto = 'Sem nota';
                                    $situacaoClasse = 'status-pendente';
                                } elseif ($mediaSituacao >= $notaMinimaAprovacao) {
                                    $situacaoTexto = 'Aprovado';
                                    $situacaoClasse = 'status-aprovado';
                                } else {
                                    $situacaoTexto = 'Reprovado';
                                    $situacaoClasse = 'status-reprovado';
                                }
                                ?>
                                <span class="status-badge <?php echo $situacaoClasse; ?>"><?php echo htmlspecialchars($situacaoTexto); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="submit" name="salvar_notas" value="1" formnovalidate <?php echo ($periodoAberto && !$edicaoBloqueadaPublicacao) ? '' : 'disabled'; ?>>Salvar notas</button>
                <button
                    type="submit"
                    name="limpar_disciplina" formnovalidate
                    value="1"
                    class="btn btn-danger" <?php echo ($periodoAberto && !$edicaoBloqueadaPublicacao) ? '' : 'disabled'; ?>
                    onclick="return confirm('Tem certeza que deseja limpar as notas desta disciplina e etapa?');"
                >
                    Limpar disciplina/etapa
                </button>
            </p>
        </form>
    </div>
</div>
<div id="page-loader" class="page-loader" aria-hidden="true">
    <div class="page-loader-box">
        <span class="page-loader-dot"></span>
        <span id="page-loader-text">Carregando...</span>
    </div>
</div>
<script>
    (function () {
        const filtro = document.getElementById('filtro_aluno');
        const resumo = document.getElementById('filtro_resumo');
        const linhas = Array.from(document.querySelectorAll('.grade-notas tbody tr'));
        const selectDisciplina = document.getElementById('disciplina_id');
        const selectEtapa = document.getElementById('etapa');
        const formNotas = document.getElementById('notas-form');
        const tableWrap = document.querySelector('.table-wrap-grade');
        const tableCompactBtn = document.getElementById('toggle-grade-compact');
        const loteComponente = document.getElementById('lote_componente');
        const loteNota = document.getElementById('lote_nota');
        const loteDispensa = document.getElementById('lote_dispensa');
        const loteAplicarBtn = document.getElementById('aplicar_lote');
        const loteLimparBtn = document.getElementById('limpar_lote');
        const loteFeedback = document.getElementById('lote_feedback');
        const loader = document.getElementById('page-loader');
        const loaderText = document.getElementById('page-loader-text');
        const turmaId = <?php echo (int) $turmaId; ?>;
        const casasDecimais = <?php echo (int) $casasDecimais; ?>;

        const scrollKey = 'turma_scroll_' + turmaId;
        const pendingKey = 'turma_scroll_pending_' + turmaId;

        const normalizar = (texto) => texto
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();

        const showLoading = (texto) => {
            if (!loader) {
                return;
            }

            if (loaderText && texto) {
                loaderText.textContent = texto;
            }

            loader.classList.add('is-active');
            loader.setAttribute('aria-hidden', 'false');
        };

        const saveScroll = () => {
            try {
                sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
            } catch (e) {
                // noop
            }
        };

        const markRestore = () => {
            try {
                sessionStorage.setItem(pendingKey, '1');
            } catch (e) {
                // noop
            }
            saveScroll();
        };

        const restoreScrollIfNeeded = () => {
            try {
                if (sessionStorage.getItem(pendingKey) !== '1') {
                    document.documentElement.classList.remove('restore-scroll-pending');
                    return;
                }

                const raw = sessionStorage.getItem(scrollKey);
                const y = raw === null ? 0 : parseInt(raw, 10);
                if (!Number.isNaN(y)) {
                    window.scrollTo(0, Math.max(0, y));
                }

                sessionStorage.removeItem(pendingKey);
                requestAnimationFrame(() => {
                    document.documentElement.classList.remove('restore-scroll-pending');
                });
            } catch (e) {
                document.documentElement.classList.remove('restore-scroll-pending');
            }
        };

        const configurarFiltroAluno = () => {
            if (!filtro || linhas.length === 0) {
                return;
            }

            const total = linhas.length;

            const atualizar = () => {
                const termo = normalizar(filtro.value || '');
                let visiveis = 0;

                linhas.forEach((linha) => {
                    const celulaAluno = linha.querySelector('.aluno-col');
                    const nomeAluno = normalizar(celulaAluno ? celulaAluno.textContent || '' : '');
                    const mostrar = termo === '' || nomeAluno.includes(termo);

                    linha.style.display = mostrar ? '' : 'none';
                    if (mostrar) {
                        visiveis += 1;
                    }
                });

                if (resumo) {
                    resumo.textContent = 'Mostrando ' + visiveis + ' de ' + total + ' alunos';
                }
            };

            filtro.addEventListener('input', atualizar);
            atualizar();
        };

        const configurarTrocaRapida = () => {
            if (!selectDisciplina || !selectEtapa) {
                return;
            }

            const recarregarNotas = () => {
                const params = new URLSearchParams();
                params.set('turma_id', String(turmaId));
                params.set('disciplina_id', selectDisciplina.value || '');
                params.set('etapa', selectEtapa.value || '');

                markRestore();
                showLoading('Carregando notas...');
                window.location.href = '/turma?' + params.toString();
            };

            selectDisciplina.addEventListener('change', recarregarNotas);
            selectEtapa.addEventListener('change', recarregarNotas);
        };

        const configurarSubmitForm = () => {
            if (!formNotas) {
                return;
            }

            formNotas.addEventListener('submit', () => {
                markRestore();
            });
        };
        const configurarModoTabela = () => {
            if (!tableWrap || !tableCompactBtn) {
                return;
            }

            const storageKey = 'turma_table_compact';

            const aplicarModo = (compacto) => {
                tableWrap.classList.toggle('is-compact', compacto);
                tableCompactBtn.textContent = compacto ? 'Expandir linhas' : 'Compactar linhas';
            };

            let compacto = false;
            try {
                compacto = sessionStorage.getItem(storageKey) === '1';
            } catch (e) {
                compacto = false;
            }

            aplicarModo(compacto);

            tableCompactBtn.addEventListener('click', () => {
                compacto = !compacto;
                aplicarModo(compacto);
                try {
                    sessionStorage.setItem(storageKey, compacto ? '1' : '0');
                } catch (e) {
                    // noop
                }
            });
        };
        const configurarLancamentoLote = () => {
            if (!loteComponente || !loteAplicarBtn || !loteLimparBtn || linhas.length === 0) {
                return;
            }

            const linhasVisiveis = () => linhas.filter((linha) => linha.style.display !== 'none');

            const definirFeedback = (texto) => {
                if (!loteFeedback) {
                    return;
                }

                loteFeedback.textContent = texto;
            };

            loteAplicarBtn.addEventListener('click', () => {
                const campo = loteComponente.value || '';
                if (campo === '') {
                    definirFeedback('Selecione um componente para aplicar em lote.');
                    return;
                }

                const marcarDispensa = Boolean(loteDispensa && loteDispensa.checked);
                const raw = (loteNota && loteNota.value ? loteNota.value : '').toString().replace(',', '.').trim();

                let valor = 0;
                if (!marcarDispensa) {
                    if (raw === '') {
                        definirFeedback('Informe uma nota entre 0 e 10 ou marque dispensa.');
                        return;
                    }

                    valor = Number.parseFloat(raw);
                    if (Number.isNaN(valor) || valor < 0 || valor > 10) {
                        definirFeedback('Nota invalida no lancamento em lote. Use valores de 0 a 10.');
                        return;
                    }
                }

                let afetados = 0;
                linhasVisiveis().forEach((linha) => {
                    const inputNota = linha.querySelector(`input[name^="notas["][name$="[${campo}]"]`);
                    const inputDispensa = linha.querySelector(`input[name^="dispensas["][name$="[${campo}]"]`);
                    if (!inputNota || !inputDispensa) {
                        return;
                    }

                    if (marcarDispensa) {
                        inputDispensa.checked = true;
                        inputNota.value = '';
                    } else {
                        inputDispensa.checked = false;
                        inputNota.value = valor.toFixed(casasDecimais);
                    }

                    afetados += 1;
                });

                definirFeedback('Lancamento em lote aplicado para ' + afetados + ' aluno(s) visiveis.');
            });

            loteLimparBtn.addEventListener('click', () => {
                const campo = loteComponente.value || '';
                if (campo === '') {
                    definirFeedback('Selecione um componente para limpar em lote.');
                    return;
                }

                let afetados = 0;
                linhasVisiveis().forEach((linha) => {
                    const inputNota = linha.querySelector(`input[name^="notas["][name$="[${campo}]"]`);
                    const inputDispensa = linha.querySelector(`input[name^="dispensas["][name$="[${campo}]"]`);
                    if (!inputNota || !inputDispensa) {
                        return;
                    }

                    inputNota.value = '';
                    inputDispensa.checked = false;
                    afetados += 1;
                });

                definirFeedback('Componente limpo para ' + afetados + ' aluno(s) visiveis.');
            });
        };

        restoreScrollIfNeeded();
        configurarFiltroAluno();
        configurarTrocaRapida();
        configurarSubmitForm();
        configurarModoTabela();
        configurarLancamentoLote();
    })();
</script>
<footer class="site-footer">Copyright &copy; <?php echo date('Y'); ?> Fragoso</footer>
</body>
</html>




