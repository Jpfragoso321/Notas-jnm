CREATE TABLE IF NOT EXISTS configuracao_avaliacao (
    id TINYINT PRIMARY KEY,
    modo_arredondamento ENUM('comercial', 'cima', 'baixo') NOT NULL DEFAULT 'comercial',
    casas_decimais TINYINT NOT NULL DEFAULT 2,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais)
VALUES (1, 'comercial', 2)
ON DUPLICATE KEY UPDATE id = id;
