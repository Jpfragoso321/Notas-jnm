ALTER TABLE usuarios
    MODIFY COLUMN perfil ENUM('admin', 'professor', 'diretora', 'coordenacao_pedagogica', 'secretario')
    NOT NULL DEFAULT 'professor';
