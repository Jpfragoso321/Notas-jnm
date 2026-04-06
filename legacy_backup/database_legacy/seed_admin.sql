INSERT INTO usuarios (nome, usuario, senha, perfil)
VALUES ('Administrador', 'joaopaulofragoso7@gmail.com', '@Caca060405@', 'admin')
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    senha = VALUES(senha),
    perfil = VALUES(perfil);
