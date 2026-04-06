ALTER TABLE notas
    ADD COLUMN IF NOT EXISTS componente1 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS componente1_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS componente2 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS componente2_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS componente3 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS componente3_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS componente4 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS componente4_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS componente5 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS componente5_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS media_base DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS peso_diferenciado DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    ADD COLUMN IF NOT EXISTS justificativa_peso VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS media_ajustada_peso DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS recuperacao_nota DECIMAL(4,2) NULL,
    ADD COLUMN IF NOT EXISTS recuperacao_aplicada TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS media DECIMAL(4,2) NOT NULL DEFAULT 0.00;

CREATE TABLE IF NOT EXISTS configuracao_avaliacao (
    id TINYINT PRIMARY KEY,
    modo_arredondamento ENUM('comercial', 'cima', 'baixo') NOT NULL DEFAULT 'comercial',
    casas_decimais TINYINT NOT NULL DEFAULT 2,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais)
VALUES (1, 'comercial', 2)
ON DUPLICATE KEY UPDATE id = id;

UPDATE notas
SET
    componente1_status = IFNULL(componente1_status, 'normal'),
    componente2_status = IFNULL(componente2_status, 'normal'),
    componente3_status = IFNULL(componente3_status, 'normal'),
    componente4_status = IFNULL(componente4_status, 'normal'),
    componente5_status = IFNULL(componente5_status, 'normal'),
    media_base = CASE WHEN media_base = 0 AND media > 0 THEN media ELSE media_base END,
    peso_diferenciado = CASE WHEN peso_diferenciado = 0 THEN 1.00 ELSE peso_diferenciado END,
    media_ajustada_peso = CASE WHEN media_ajustada_peso = 0 THEN media_base ELSE media_ajustada_peso END,
    recuperacao_aplicada = CASE
        WHEN recuperacao_nota IS NOT NULL AND recuperacao_nota > media_ajustada_peso THEN 1
        ELSE 0
    END;

CREATE TABLE IF NOT EXISTS configuracao_risco (
    id TINYINT PRIMARY KEY,
    peso_media DECIMAL(5,2) NOT NULL DEFAULT 0.60,
    peso_faltas DECIMAL(5,2) NOT NULL DEFAULT 0.25,
    peso_atrasos DECIMAL(5,2) NOT NULL DEFAULT 0.15,
    limiar_media DECIMAL(4,2) NOT NULL DEFAULT 5.00,
    limiar_faltas_percentual DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    limiar_atrasos INT NOT NULL DEFAULT 3,
    limiar_score_moderado DECIMAL(5,2) NOT NULL DEFAULT 40.00,
    limiar_score_alto DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO configuracao_risco (
    id,
    peso_media,
    peso_faltas,
    peso_atrasos,
    limiar_media,
    limiar_faltas_percentual,
    limiar_atrasos,
    limiar_score_moderado,
    limiar_score_alto
)
VALUES (1, 0.60, 0.25, 0.15, 5.00, 15.00, 3, 40.00, 70.00)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS aluno_risco_indicadores (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    turma_id INT NOT NULL,
    etapa VARCHAR(30) NOT NULL,
    faltas_percentual DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    atrasos_qtd INT NOT NULL DEFAULT 0,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aluno_risco (aluno_id, turma_id, etapa),
    CONSTRAINT fk_ari_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id),
    CONSTRAINT fk_ari_turma FOREIGN KEY (turma_id) REFERENCES turmas(id)
);