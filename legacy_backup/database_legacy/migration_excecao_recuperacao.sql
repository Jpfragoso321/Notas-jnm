ALTER TABLE notas
    ADD COLUMN IF NOT EXISTS media_base DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS recuperacao_nota DECIMAL(4,2) NULL,
    ADD COLUMN IF NOT EXISTS recuperacao_aplicada TINYINT(1) NOT NULL DEFAULT 0;

UPDATE notas
SET
    media_base = CASE WHEN media_base = 0 AND media > 0 THEN media ELSE media_base END,
    recuperacao_aplicada = CASE
        WHEN recuperacao_nota IS NOT NULL AND recuperacao_nota > media_base THEN 1
        ELSE 0
    END;
