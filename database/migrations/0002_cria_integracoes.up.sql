-- Contas de acesso às APIs dos fabricantes (uma empresa pode ter várias).
-- O token NÃO fica no banco: chave_credencial aponta para a entrada
-- correspondente em config/config.php, gerado a partir dos secrets.
CREATE TABLE integracoes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id       INT UNSIGNED NOT NULL,
    fabricante       VARCHAR(30)  NOT NULL COMMENT 'growatt, ...',
    descricao        VARCHAR(150) NOT NULL,
    url_base         VARCHAR(200) NOT NULL,
    chave_credencial VARCHAR(60)  NOT NULL COMMENT 'nome da chave em config.php',
    ativo            TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_integracoes (empresa_id, fabricante, chave_credencial),
    CONSTRAINT fk_integracoes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
