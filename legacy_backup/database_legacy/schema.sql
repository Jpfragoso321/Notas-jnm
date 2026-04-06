CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    usuario VARCHAR(80) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    perfil ENUM('admin', 'professor', 'diretora', 'coordenacao_pedagogica', 'secretario') NOT NULL DEFAULT 'professor',
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS configuracao_avaliacao (
    id TINYINT PRIMARY KEY,
    modo_arredondamento ENUM('comercial', 'cima', 'baixo') NOT NULL DEFAULT 'comercial',
    casas_decimais TINYINT NOT NULL DEFAULT 2,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO configuracao_avaliacao (id, modo_arredondamento, casas_decimais)
VALUES (1, 'comercial', 2)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS turmas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    ano_letivo INT NOT NULL
);

CREATE TABLE IF NOT EXISTS professor_turma (
    id INT AUTO_INCREMENT PRIMARY KEY,
    professor_id INT NOT NULL,
    turma_id INT NOT NULL,
    UNIQUE KEY uq_professor_turma (professor_id, turma_id),
    CONSTRAINT fk_pt_professor FOREIGN KEY (professor_id) REFERENCES usuarios(id),
    CONSTRAINT fk_pt_turma FOREIGN KEY (turma_id) REFERENCES turmas(id)
);

CREATE TABLE IF NOT EXISTS alunos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turma_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    CONSTRAINT fk_aluno_turma FOREIGN KEY (turma_id) REFERENCES turmas(id)
);

CREATE TABLE IF NOT EXISTS disciplinas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turma_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    CONSTRAINT fk_disciplina_turma FOREIGN KEY (turma_id) REFERENCES turmas(id)
);

CREATE TABLE IF NOT EXISTS notas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    turma_id INT NOT NULL,
    disciplina_id INT NOT NULL,
    etapa VARCHAR(30) NOT NULL,
    componente1 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    componente1_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    componente2 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    componente2_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    componente3 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    componente3_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    componente4 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    componente4_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    componente5 DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    componente5_status ENUM('normal', 'dispensado') NOT NULL DEFAULT 'normal',
    media_base DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    peso_diferenciado DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    justificativa_peso VARCHAR(255) NULL,
    media_ajustada_peso DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    recuperacao_nota DECIMAL(4,2) NULL,
    recuperacao_aplicada TINYINT(1) NOT NULL DEFAULT 0,
    media DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nota (aluno_id, turma_id, disciplina_id, etapa),
    CONSTRAINT fk_nota_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id),
    CONSTRAINT fk_nota_turma FOREIGN KEY (turma_id) REFERENCES turmas(id),
    CONSTRAINT fk_nota_disciplina FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id)
);

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

INSERT INTO usuarios (nome, usuario, senha, perfil)
VALUES
('Administrador', 'joaopaulofragoso7@gmail.com', '@Caca060405@', 'admin')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), perfil = VALUES(perfil);

INSERT INTO usuarios (nome, usuario, senha, perfil)
VALUES
('Joao Professor', 'prof.joao', '123456', 'professor')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), perfil = VALUES(perfil);

INSERT INTO turmas (id, nome, ano_letivo)
VALUES
(1, '6A', 2026),
(2, '7B', 2026)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), ano_letivo = VALUES(ano_letivo);

INSERT INTO professor_turma (professor_id, turma_id)
SELECT u.id, 1
FROM usuarios u
WHERE u.usuario = 'prof.joao'
ON DUPLICATE KEY UPDATE professor_id = professor_id;

INSERT INTO professor_turma (professor_id, turma_id)
SELECT u.id, 2
FROM usuarios u
WHERE u.usuario = 'prof.joao'
ON DUPLICATE KEY UPDATE professor_id = professor_id;

INSERT INTO disciplinas (turma_id, nome)
VALUES
(1, 'Matematica'),
(1, 'Portugues'),
(2, 'Matematica'),
(2, 'Ciencias')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO alunos (turma_id, nome)
VALUES
(1, 'Ana Silva'),
(1, 'Bruno Costa'),
(1, 'Carla Lima'),
(2, 'Daniel Rocha'),
(2, 'Elaine Souza'),
(2, 'Felipe Santos')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
