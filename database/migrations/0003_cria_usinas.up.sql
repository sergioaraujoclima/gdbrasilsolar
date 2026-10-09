-- Usinas solares. Os campos vêm do cadastro da usina no fabricante;
-- dados_brutos guarda a resposta completa da API para não perder nada.
CREATE TABLE usinas (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empresa_id        INT UNSIGNED  NOT NULL,
    integracao_id     INT UNSIGNED  NULL,
    nome              VARCHAR(150)  NOT NULL,
    fabricante        VARCHAR(30)   NOT NULL,
    id_externo        VARCHAR(40)   NOT NULL COMMENT 'plant_id no fabricante',
    potencia_pico_kwp DECIMAL(10,3) NULL,
    cidade            VARCHAR(100)  NULL,
    pais              VARCHAR(60)   NULL,
    latitude          DECIMAL(10,6) NULL,
    longitude         DECIMAL(10,6) NULL,
    data_instalacao   DATE          NULL,
    status_externo    VARCHAR(20)   NULL,
    energia_total_kwh DECIMAL(14,3) NULL COMMENT 'acumulado informado pelo fabricante',
    dados_brutos      LONGTEXT      NULL COMMENT 'JSON original da API',
    sincronizado_em   DATETIME      NULL,
    ativo             TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usinas_externo (fabricante, id_externo),
    KEY ix_usinas_empresa (empresa_id),
    CONSTRAINT fk_usinas_empresa    FOREIGN KEY (empresa_id)    REFERENCES empresas (id),
    CONSTRAINT fk_usinas_integracao FOREIGN KEY (integracao_id) REFERENCES integracoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
