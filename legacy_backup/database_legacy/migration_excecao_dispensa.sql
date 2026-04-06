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
    ADD COLUMN IF NOT EXISTS media DECIMAL(4,2) NOT NULL DEFAULT 0.00;

UPDATE notas
SET
    componente1_status = IFNULL(componente1_status, 'normal'),
    componente2_status = IFNULL(componente2_status, 'normal'),
    componente3_status = IFNULL(componente3_status, 'normal'),
    componente4_status = IFNULL(componente4_status, 'normal'),
    componente5_status = IFNULL(componente5_status, 'normal');
