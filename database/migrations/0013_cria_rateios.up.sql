-- Rateio de créditos: como a energia injetada por uma UC geradora é
-- dividida, em percentual, entre ela mesma e as UCs beneficiárias.
-- Cada rateio vale a partir de um mês; o vigente é o mais recente.
CREATE TABLE rateios (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uc_geradora_id  INT UNSIGNED NOT NULL,
    vigencia_inicio DATE         NOT NULL COMMENT 'primeiro dia do mês em que passa a valer',
    observacoes     VARCHAR(255) NULL,
    criado_por      INT UNSIGNED NULL,
    criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rateios_vigencia (uc_geradora_id, vigencia_inicio),
    CONSTRAINT fk_rateios_geradora FOREIGN KEY (uc_geradora_id) REFERENCES unidades_consumidoras (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rateio_itens (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rateio_id     INT UNSIGNED NOT NULL,
    uc_destino_id INT UNSIGNED NOT NULL COMMENT 'pode ser a própria geradora',
    percentual    DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rateio_destino (rateio_id, uc_destino_id),
    CONSTRAINT fk_ritens_rateio  FOREIGN KEY (rateio_id)     REFERENCES rateios (id) ON DELETE CASCADE,
    CONSTRAINT fk_ritens_destino FOREIGN KEY (uc_destino_id) REFERENCES unidades_consumidoras (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
