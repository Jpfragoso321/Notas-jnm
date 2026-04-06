<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/avaliacao_config.php';
require_once __DIR__ . '/../src/publicacao_notas.php';
require_once __DIR__ . '/../src/professor_disciplinas.php';
require_once __DIR__ . '/../src/simple_pdf.php';

exigir_login();

$usuario = usuario_logado();
$pdo = db();
$configAvaliacao = obter_config_avaliacao($pdo);
$configPublicacao = obter_config_publicacao_notas($pdo);
$modoPublicacao = (string) ($configPublicacao['modo_publicacao'] ?? 'manual');

$turmaId = (int) ($_GET['turma_id'] ?? 0);
$tipo = trim((string) ($_GET['tipo'] ?? 'turma'));
$alunoId = (int) ($_GET['aluno_id'] ?? 0);
$etapa = trim((string) ($_GET['etapa'] ?? '1_bimestre'));
$disciplinasSelecionadas = array_values(array_unique(array_map('intval', (array) ($_GET['disciplina_ids'] ?? []))));
$perfilAtual = perfil_usuario($usuario);
$ehProfessor = $perfilAtual === 'professor';

$etapasPermitidas = ['1_bimestre', '2_bimestre', '3_bimestre', '4_bimestre'];
if ($turmaId <= 0 || !in_array($tipo, ['turma', 'aluno'], true) || !in_array($etapa, $etapasPermitidas, true)) {
    http_response_code(400);
    echo 'Parametros invalidos para exportacao.';
    exit;
}

if (!usuario_eh_manager($usuario, true)) {
    $acessoStmt = $pdo->prepare('SELECT 1 FROM professor_turma WHERE professor_id = :professor_id AND turma_id = :turma_id LIMIT 1');
    $acessoStmt->execute(['professor_id' => $usuario['id'], 'turma_id' => $turmaId]);
    if (!$acessoStmt->fetch()) {
        http_response_code(403);
        echo 'Acesso negado para exportar boletim desta turma.';
        exit;
    }
}

$turmaStmt = $pdo->prepare('SELECT id, nome, ano_letivo FROM turmas WHERE id = :id');
$turmaStmt->execute(['id' => $turmaId]);
$turma = $turmaStmt->fetch();

if (!$turma) {
    http_response_code(404);
    echo 'Turma nao encontrada.';
    exit;
}

if ($ehProfessor) {
    garantir_tabela_professor_disciplina($pdo);
}

$disciplinasStmt = $pdo->prepare('SELECT id, nome FROM disciplinas WHERE turma_id = :turma_id ORDER BY nome');
$disciplinasStmt->execute(['turma_id' => $turmaId]);
$disciplinas = $disciplinasStmt->fetchAll();

if (!$disciplinas) {
    http_response_code(400);
    echo 'Nao ha disciplinas cadastradas para a turma.';
    exit;
}

$mapaPublicadas = $modoPublicacao === 'manual' ? mapa_disciplinas_publicadas($pdo, $turmaId) : [];
$disciplinasMap = [];
foreach ($disciplinas as $disciplina) {
    $disciplinaPk = (int) $disciplina['id'];
    if ($modoPublicacao === 'manual' && !isset($mapaPublicadas[$disciplinaPk][$etapa])) {
        continue;
    }

    $disciplinasMap[$disciplinaPk] = (string) $disciplina['nome'];
}

if ($ehProfessor) {
    $disciplinasProfessorStmt = $pdo->prepare(
        'SELECT disciplina_id
         FROM professor_disciplina
         WHERE professor_id = :professor_id'
    );
    $disciplinasProfessorStmt->execute(['professor_id' => (int) ($usuario['id'] ?? 0)]);

    $permitidasProfessor = [];
    foreach ($disciplinasProfessorStmt->fetchAll() as $row) {
        $permitidasProfessor[(int) $row['disciplina_id']] = true;
    }

    $disciplinasMap = array_intersect_key($disciplinasMap, $permitidasProfessor);
}

if ($tipo === 'aluno' && !$ehProfessor && $disciplinasSelecionadas) {
    $selecionadasValidas = [];
    foreach ($disciplinasSelecionadas as $disciplinaSelecionadaId) {
        if (isset($disciplinasMap[$disciplinaSelecionadaId])) {
            $selecionadasValidas[] = $disciplinaSelecionadaId;
        }
    }

    if (!$selecionadasValidas) {
        http_response_code(400);
        echo 'Selecione ao menos uma materia valida para o boletim do aluno.';
        exit;
    }

    $disciplinasMap = array_intersect_key($disciplinasMap, array_flip($selecionadasValidas));
}

if (!$disciplinasMap) {
    http_response_code(409);
    echo 'Nenhuma disciplina publicada para o boletim desta etapa.';
    exit;
}

$params = ['turma_id' => $turmaId];
$sqlAlunos = 'SELECT id, nome FROM alunos WHERE turma_id = :turma_id';

if ($tipo === 'aluno') {
    if ($alunoId <= 0) {
        http_response_code(400);
        echo 'Aluno invalido para exportacao.';
        exit;
    }

    $sqlAlunos .= ' AND id = :aluno_id';
    $params['aluno_id'] = $alunoId;
}

$sqlAlunos .= ' ORDER BY nome';
$alunosStmt = $pdo->prepare($sqlAlunos);
$alunosStmt->execute($params);
$alunos = $alunosStmt->fetchAll();

if (!$alunos) {
    http_response_code(404);
    echo 'Nenhum aluno encontrado para exportacao.';
    exit;
}

if ($modoPublicacao === 'manual') {
    $notasStmt = $pdo->prepare(
        'SELECT n.aluno_id, n.disciplina_id,
                n.componente1, n.componente1_status,
                n.componente2, n.componente2_status,
                n.componente3, n.componente3_status,
                n.componente4, n.componente4_status,
                n.componente5, n.componente5_status,
                n.media
         FROM notas n
         INNER JOIN notas_publicadas np
             ON np.turma_id = n.turma_id
            AND np.disciplina_id = n.disciplina_id
            AND np.etapa = n.etapa
         WHERE n.turma_id = :turma_id
           AND n.etapa = :etapa
         ORDER BY n.aluno_id, n.disciplina_id'
    );
} else {
    $notasStmt = $pdo->prepare(
        'SELECT aluno_id, disciplina_id,
                componente1, componente1_status,
                componente2, componente2_status,
                componente3, componente3_status,
                componente4, componente4_status,
                componente5, componente5_status,
                media
         FROM notas
         WHERE turma_id = :turma_id
           AND etapa = :etapa
         ORDER BY aluno_id, disciplina_id'
    );
}
$notasStmt->execute(['turma_id' => $turmaId, 'etapa' => $etapa]);

$notasMap = [];
foreach ($notasStmt->fetchAll() as $nota) {
    $alunoKey = (int) $nota['aluno_id'];
    $discKey = (int) $nota['disciplina_id'];

    if (!isset($disciplinasMap[$discKey])) {
        continue;
    }

    $notasMap[$alunoKey][$discKey] = [
        'componente1' => (float) ($nota['componente1'] ?? 0),
        'componente1_status' => (string) ($nota['componente1_status'] ?? 'normal'),
        'componente2' => (float) ($nota['componente2'] ?? 0),
        'componente2_status' => (string) ($nota['componente2_status'] ?? 'normal'),
        'componente3' => (float) ($nota['componente3'] ?? 0),
        'componente3_status' => (string) ($nota['componente3_status'] ?? 'normal'),
        'componente4' => (float) ($nota['componente4'] ?? 0),
        'componente4_status' => (string) ($nota['componente4_status'] ?? 'normal'),
        'componente5' => (float) ($nota['componente5'] ?? 0),
        'componente5_status' => (string) ($nota['componente5_status'] ?? 'normal'),
        'media' => (float) ($nota['media'] ?? 0),
    ];
}

function limpar_nome_arquivo(string $texto): string
{
    $texto = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', '_', $texto) ?? 'arquivo';
    return trim($texto, '_');
}

function resumir_texto_pdf(string $texto, int $limite): string
{
    $texto = trim((string) $texto);
    if ($limite < 4 || strlen($texto) <= $limite) {
        return $texto;
    }

    return substr($texto, 0, $limite - 3) . '...';
}

function etapa_para_titulo(string $etapa): string
{
    $mapa = [
        '1_bimestre' => '1o BIMESTRE',
        '2_bimestre' => '2o BIMESTRE',
        '3_bimestre' => '3o BIMESTRE',
        '4_bimestre' => '4o BIMESTRE',
    ];

    return $mapa[$etapa] ?? 'BIMESTRE';
}

function normalizar_texto_pdf(string $texto): string
{
    $texto = mb_strtoupper(trim($texto), 'UTF-8');
    $texto = str_replace(
        ['ÃƒÂ', 'Ãƒâ‚¬', 'Ãƒâ€š', 'ÃƒÆ’', 'Ãƒâ€°', 'ÃƒÅ ', 'ÃƒÂ', 'Ãƒâ€œ', 'Ãƒâ€', 'Ãƒâ€¢', 'ÃƒÅ¡', 'Ãƒâ€¡'],
        ['A', 'A', 'A', 'A', 'E', 'E', 'I', 'O', 'O', 'O', 'U', 'C'],
        $texto
    );
    $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
    return $texto;
}
function valor_componente_pdf(?array $nota, string $campoValor, string $campoStatus, array $configAvaliacao): string
{
    if (!is_array($nota)) {
        return '';
    }

    $status = (string) ($nota[$campoStatus] ?? 'normal');
    if ($status === 'dispensado') {
        return 'D';
    }

    $valor = (float) ($nota[$campoValor] ?? 0);
    return formatar_nota(aplicar_arredondamento($valor, $configAvaliacao), $configAvaliacao);
}

function x_texto_central(float $x, float $w, string $texto, int $fontSize): float
{
    $aprox = strlen($texto) * ($fontSize * 0.45);
    return $x + max(2.0, ($w - $aprox) / 2.0);
}

function obter_professores_turma(PDO $pdo, int $turmaId): string
{
    $stmt = $pdo->prepare(
        'SELECT u.nome
         FROM professor_turma pt
         INNER JOIN usuarios u ON u.id = pt.professor_id
         WHERE pt.turma_id = :turma_id
         ORDER BY u.nome'
    );
    $stmt->execute(['turma_id' => $turmaId]);

    $nomes = [];
    foreach ($stmt->fetchAll() as $row) {
        $nome = trim((string) ($row['nome'] ?? ''));
        if ($nome !== '') {
            $nomes[] = $nome;
        }
    }

    if (!$nomes) {
        return '';
    }

    return implode(', ', $nomes);
}

$ordemReferencia = [
    'LINGUA PORTUGUESA',
    'MATEMATICA',
    'CIENCIAS',
    'HISTORIA',
    'GEOGRAFIA',
];

$disciplinasOrdenadas = [];
$disciplinasPendentes = $disciplinasMap;
foreach ($ordemReferencia as $ref) {
    $refNorm = normalizar_texto_pdf($ref);
    foreach ($disciplinasPendentes as $idDisc => $nomeDisc) {
        $nomeNorm = normalizar_texto_pdf((string) $nomeDisc);
        if ($nomeNorm === $refNorm || str_contains($nomeNorm, $refNorm) || str_contains($refNorm, $nomeNorm)) {
            $disciplinasOrdenadas[$idDisc] = $nomeDisc;
            unset($disciplinasPendentes[$idDisc]);
            break;
        }
    }
}
foreach ($disciplinasPendentes as $idDisc => $nomeDisc) {
    $disciplinasOrdenadas[$idDisc] = $nomeDisc;
}
$disciplinasMap = $disciplinasOrdenadas;
$mediaGeralAluno = [];
foreach ($alunos as $aluno) {
    $alunoPk = (int) $aluno['id'];
    $soma = 0.0;
    $qtd = 0;

    foreach ($disciplinasMap as $discId => $_discNome) {
        if (!isset($notasMap[$alunoPk][$discId])) {
            continue;
        }

        $media = aplicar_arredondamento((float) $notasMap[$alunoPk][$discId]['media'], $configAvaliacao);
        $soma += $media;
        $qtd++;
    }

    $mediaGeralAluno[$alunoPk] = $qtd > 0 ? aplicar_arredondamento($soma / $qtd, $configAvaliacao) : null;
}

$pdf = new SimplePdf(842, 595);
$pageNumber = 0;

$pageWidth = 842.0;
$left = 20.0;
$right = 20.0;
$tableTopY = 470.0;
$header1H = 22.0;
$header2H = 22.0;
$rowH = 12.2;
$maxRows = 28;

$idxW = 20.0;
$nomeW = 240.0;
$totalCols = ['MB', 'FRQ', 'FLT'];
$totalSubW = 15.0;
$totalW = count($totalCols) * $totalSubW;
$usableW = $pageWidth - $left - $right - $idxW - $nomeW - $totalW;
$disciplinaMinSubW = 11.0;
$maxDisciplinasPorPagina = (int) floor($usableW / (6.0 * $disciplinaMinSubW));

if ($maxDisciplinasPorPagina < 1) {
    http_response_code(500);
    echo 'Layout de impressao invalido para esta configuracao.';
    exit;
}

$disciplinasIds = array_keys($disciplinasMap);
$disciplinasChunks = array_chunk($disciplinasIds, $maxDisciplinasPorPagina);
$alunosChunks = array_chunk($alunos, $maxRows);
$professorLinha = $tipo === 'turma' ? '' : resumir_texto_pdf(obter_professores_turma($pdo, $turmaId), 72);
$bimestreTitulo = etapa_para_titulo($etapa);

$drawHeaderAndFooter = static function (int $page) use (
    $pdf,
    $pageWidth,
    $left,
    $right,
    $turma,
    $professorLinha,
    $bimestreTitulo,
    &$pageNumber
): void {
    $pageNumber++;

    $pdf->text($page, $left, 565, 'PREFEITURA MUNICIPAL DE NATIVIDADE', 11, 'bold', [0, 0, 0]);
    $pdf->text($page, $left, 552, 'VOLTANDO A SORRIR!', 8, 'regular', [0, 0, 0]);

    $pdf->text($page, $pageWidth - 215, 575, 'REPUBLICA FEDERATIVA DO BRASIL', 6, 'regular', [0, 0, 0]);
    $pdf->text($page, $pageWidth - 215, 567, 'ESTADO DO RIO DE JANEIRO', 6, 'regular', [0, 0, 0]);
    $pdf->text($page, $pageWidth - 215, 559, 'PREFEITURA MUNICIPAL DE NATIVIDADE', 6, 'regular', [0, 0, 0]);
    $pdf->text($page, $pageWidth - 215, 551, 'SECRETARIA MUNICIPAL DE EDUCACAO', 6, 'regular', [0, 0, 0]);

    $titulo = 'REGISTRO DE NOTAS - ' . $bimestreTitulo;
    $pdf->text($page, x_texto_central(0, $pageWidth, $titulo, 13), 546, $titulo, 13, 'bold', [0, 0, 0]);

    $subtitulo = 'ESCOLA CRECHE MUNICIPAL NORBERTO MARQUES - ENSINO FUNDAMENTAL - I SEGMENTO';
    $pdf->text($page, x_texto_central(0, $pageWidth, $subtitulo, 9), 532, $subtitulo, 9, 'bold', [0, 0, 0]);

    $linhaInfo = 'Professor(a): ' . ($professorLinha !== '' ? $professorLinha : '________________________')
        . '    Ano de Escolaridade: ______    Turma: ' . (string) $turma['nome']
        . '    Ano Letivo: ' . (string) $turma['ano_letivo'];
    $pdf->text($page, $left, 516, resumir_texto_pdf($linhaInfo, 172), 8, 'regular', [0, 0, 0]);

    $pdf->text($page, $pageWidth - $right - 58, 516, 'Pag. ' . $pageNumber, 8, 'regular', [0, 0, 0]);

    $pdf->text($page, $left + 2, 66, 'Observacao: (*) Aluno NEE', 8, 'regular', [0, 0, 0]);

    $linhaY = 42.0;
    $pdf->line($page, 75, $linhaY, 240, $linhaY, [0, 0, 0], 0.8);
    $pdf->line($page, 340, $linhaY, 505, $linhaY, [0, 0, 0], 0.8);
    $pdf->line($page, 605, $linhaY, 770, $linhaY, [0, 0, 0], 0.8);

    $pdf->text($page, 145, 26, 'Professor', 9, 'regular', [0, 0, 0]);
    $pdf->text($page, 390, 26, 'Coordenador Pedagogico', 9, 'regular', [0, 0, 0]);
    $pdf->text($page, 660, 26, 'Inspetor Educacional', 9, 'regular', [0, 0, 0]);

    $rodape = 'Secretaria Municipal de Educacao - Avenida Mauro Alves Ribeiro Junior - Balneario, Natividade/RJ CEP: 28.380-000';
    $pdf->text($page, x_texto_central(0, $pageWidth, $rodape, 7), 12, $rodape, 7, 'regular', [0, 0, 0]);
};

$drawTabela = static function (int $page, array $chunkDisciplinas, array $chunkAlunos, int $indiceInicial) use (
    $pdf,
    $disciplinasMap,
    $notasMap,
    $mediaGeralAluno,
    $configAvaliacao,
    $left,
    $tableTopY,
    $header1H,
    $header2H,
    $rowH,
    $maxRows,
    $idxW,
    $nomeW,
    $totalCols,
    $totalSubW,
    $totalW,
    $usableW,
    $disciplinasChunks
): void {
    $qtdeDisciplinas = max(1, count($chunkDisciplinas));
    $discBlockW = $usableW / $qtdeDisciplinas;
    $subW = $discBlockW / 6.0;

    $x = $left;
    $tableBottomY = $tableTopY - $header1H - $header2H - ($maxRows * $rowH);

    $pdf->rect($page, $left, $tableBottomY, $idxW + $nomeW + ($discBlockW * $qtdeDisciplinas) + $totalW, $tableTopY - $tableBottomY, [1, 1, 1], [0, 0, 0], 0.8);

    $pdf->rect($page, $x, $tableTopY - $header1H - $header2H, $idxW, $header1H + $header2H, [0.98, 0.98, 0.98], [0, 0, 0], 0.8);
    $pdf->text($page, x_texto_central($x, $idxW, 'N', 8), $tableTopY - 25, 'N', 8, 'bold', [0, 0, 0]);
    $x += $idxW;

    $pdf->rect($page, $x, $tableTopY - $header1H - $header2H, $nomeW, $header1H + $header2H, [0.98, 0.98, 0.98], [0, 0, 0], 0.8);
    $pdf->text($page, x_texto_central($x, $nomeW, 'ALUNO (A)', 9), $tableTopY - 25, 'ALUNO (A)', 9, 'bold', [0, 0, 0]);
    $x += $nomeW;

    $componenteW = $discBlockW * $qtdeDisciplinas;
    $pdf->rect($page, $x, $tableTopY, $componenteW, 14.0, [0.95, 0.95, 0.95], [0, 0, 0], 0.8);
    $pdf->text($page, x_texto_central($x, $componenteW, 'COMPONENTE', 8), $tableTopY + 3, 'COMPONENTE', 8, 'bold', [0, 0, 0]);

    foreach ($chunkDisciplinas as $discId) {
        $discNome = strtoupper(resumir_texto_pdf((string) $disciplinasMap[$discId], 20));
        $pdf->rect($page, $x, $tableTopY - $header1H, $discBlockW, $header1H, [0.95, 0.95, 0.95], [0, 0, 0], 0.8);
        $pdf->text($page, x_texto_central($x, $discBlockW, $discNome, 8), $tableTopY - 15, $discNome, 8, 'bold', [0, 0, 0]);

        $subLabels = ['SOC', 'CAD', 'TES', 'PRO', 'REC', 'MB'];
        for ($i = 0; $i < 6; $i++) {
            $sx = $x + ($i * $subW);
            $pdf->rect($page, $sx, $tableTopY - $header1H - $header2H, $subW, $header2H, [0.98, 0.98, 0.98], [0, 0, 0], 0.6);
            $pdf->text($page, x_texto_central($sx, $subW, $subLabels[$i], 7), $tableTopY - $header1H - 14, $subLabels[$i], 7, 'bold', [0, 0, 0]);
        }

        $x += $discBlockW;
    }

    $pdf->rect($page, $x, $tableTopY - $header1H, $totalW, $header1H, [0.95, 0.95, 0.95], [0, 0, 0], 0.8);
    $pdf->text($page, x_texto_central($x, $totalW, 'TOTAL', 8), $tableTopY - 15, 'TOTAL', 8, 'bold', [0, 0, 0]);
    for ($i = 0; $i < count($totalCols); $i++) {
        $sx = $x + ($i * $totalSubW);
        $pdf->rect($page, $sx, $tableTopY - $header1H - $header2H, $totalSubW, $header2H, [0.98, 0.98, 0.98], [0, 0, 0], 0.6);
        $pdf->text($page, x_texto_central($sx, $totalSubW, $totalCols[$i], 7), $tableTopY - $header1H - 14, $totalCols[$i], 7, 'bold', [0, 0, 0]);
    }

    for ($row = 0; $row < $maxRows; $row++) {
        $y = $tableTopY - $header1H - $header2H - (($row + 1) * $rowH);
        $linha = $chunkAlunos[$row] ?? null;

        $xRow = $left;
        $pdf->rect($page, $xRow, $y, $idxW, $rowH, [1, 1, 1], [0, 0, 0], 0.5);
        $numero = $linha ? str_pad((string) ($indiceInicial + $row + 1), 2, '0', STR_PAD_LEFT) : '';
        if ($numero !== '') {
            $pdf->text($page, x_texto_central($xRow, $idxW, $numero, 8), $y + 3, $numero, 8, 'regular', [0, 0, 0]);
        }
        $xRow += $idxW;

        $pdf->rect($page, $xRow, $y, $nomeW, $rowH, [1, 1, 1], [0, 0, 0], 0.5);
        if ($linha) {
            $pdf->text($page, $xRow + 3, $y + 3, strtoupper(resumir_texto_pdf((string) $linha['nome'], 46)), 7, 'regular', [0, 0, 0]);
        }
        $xRow += $nomeW;

        foreach ($chunkDisciplinas as $discId) {
            $nota = $linha ? ($notasMap[(int) $linha['id']][$discId] ?? null) : null;

            $valores = [
                valor_componente_pdf($nota, 'componente1', 'componente1_status', $configAvaliacao),
                valor_componente_pdf($nota, 'componente2', 'componente2_status', $configAvaliacao),
                valor_componente_pdf($nota, 'componente3', 'componente3_status', $configAvaliacao),
                valor_componente_pdf($nota, 'componente4', 'componente4_status', $configAvaliacao),
                valor_componente_pdf($nota, 'componente5', 'componente5_status', $configAvaliacao),
                is_array($nota) ? formatar_nota(aplicar_arredondamento((float) $nota['media'], $configAvaliacao), $configAvaliacao) : '',
            ];

            for ($i = 0; $i < 6; $i++) {
                $sx = $xRow + ($i * $subW);
                $pdf->rect($page, $sx, $y, $subW, $rowH, [1, 1, 1], [0, 0, 0], 0.5);
                if ($valores[$i] !== '') {
                    $pdf->text($page, x_texto_central($sx, $subW, $valores[$i], 7), $y + 3, $valores[$i], 7, 'regular', [0, 0, 0]);
                }
            }

            $xRow += $discBlockW;
        }

        $totais = ['', '', ''];
        if ($linha) {
            $mediaAluno = $mediaGeralAluno[(int) $linha['id']] ?? null;
            $totais[0] = $mediaAluno !== null ? formatar_nota((float) $mediaAluno, $configAvaliacao) : '';
        }

        for ($i = 0; $i < count($totalCols); $i++) {
            $sx = $xRow + ($i * $totalSubW);
            $pdf->rect($page, $sx, $y, $totalSubW, $rowH, [1, 1, 1], [0, 0, 0], 0.5);
            if ($totais[$i] !== '') {
                $pdf->text($page, x_texto_central($sx, $totalSubW, $totais[$i], 7), $y + 3, $totais[$i], 7, 'regular', [0, 0, 0]);
            }
        }
    }

    if (count($disciplinasChunks) > 1) {
        $info = 'Disciplinas desta pagina: ' . count($chunkDisciplinas) . ' de ' . count($disciplinasMap);
        $pdf->text($page, $left, 78, $info, 7, 'regular', [0, 0, 0]);
    }
};

foreach ($disciplinasChunks as $disciplinasChunk) {
    $indiceInicial = 0;
    foreach ($alunosChunks as $alunosChunk) {
        $page = $pdf->addPage();
        $drawHeaderAndFooter($page);
        $drawTabela($page, $disciplinasChunk, $alunosChunk, $indiceInicial);
        $indiceInicial += count($alunosChunk);
    }
}

$pdfData = $pdf->output();

$turmaSlug = limpar_nome_arquivo((string) $turma['nome']);
$ano = (string) $turma['ano_letivo'];

if ($tipo === 'aluno') {
    $alunoNome = limpar_nome_arquivo((string) $alunos[0]['nome']);
    $fileName = "boletim_aluno_{$alunoNome}_{$turmaSlug}_{$ano}.pdf";
} else {
    $fileName = "boletim_turma_{$turmaSlug}_{$ano}.pdf";
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($pdfData));

echo $pdfData;
exit;
