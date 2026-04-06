ALTER TABLE notas
    ADD COLUMN IF NOT EXISTS peso_diferenciado DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    ADD COLUMN IF NOT EXISTS justificativa_peso VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS media_ajustada_peso DECIMAL(4,2) NOT NULL DEFAULT 0.00;

UPDATE notas
SET
    peso_diferenciado = CASE WHEN peso_diferenciado = 0 THEN 1.00 ELSE peso_diferenciado END,
    media_ajustada_peso = CASE WHEN media_ajustada_peso = 0 THEN media_base ELSE media_ajustada_peso END;
