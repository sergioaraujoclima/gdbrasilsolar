-- Usuários do sistema. O primeiro administrador se cadastra pelo site;
-- os demais se autocadastram e ficam pendentes até um administrador aprovar.
CREATE TABLE usuarios (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id         INT UNSIGNED NULL,
    nome               VARCHAR(150) NOT NULL,
    email              VARCHAR(190) NOT NULL,
    senha_hash         VARCHAR(255) NOT NULL,
    papel              VARCHAR(20)  NOT NULL DEFAULT 'cliente' COMMENT 'admin | cliente',
    status             VARCHAR(20)  NOT NULL DEFAULT 'pendente' COMMENT 'pendente | ativo | bloqueado',
    empresa_solicitada VARCHAR(150) NULL COMMENT 'nome da empresa informado no autocadastro',
    aprovado_por       INT UNSIGNED NULL,
    aprovado_em        DATETIME     NULL,
    ultimo_acesso      DATETIME     NULL,
    criado_em          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email),
    CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
