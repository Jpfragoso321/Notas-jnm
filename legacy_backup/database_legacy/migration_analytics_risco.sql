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
