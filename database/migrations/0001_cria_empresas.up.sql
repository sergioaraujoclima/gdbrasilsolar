-- Empresas (clientes) do sistema. Cada empresa tem uma ou mais usinas.
CREATE TABLE empresas (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome          VARCHAR(150) NOT NULL,
    documento     VARCHAR(20)  NULL COMMENT 'CNPJ ou CPF, somente dígitos',
    ativo         TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_empresas_documento (documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
