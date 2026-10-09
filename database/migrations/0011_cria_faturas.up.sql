-- Faturas da distribuidora, uma por UC por mês de referência, com os
-- campos de consumo, compensação de créditos e tributos; fatura_itens
-- guarda as linhas do quadro "Itens da fatura".
CREATE TABLE faturas (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unidade_id              INT UNSIGNED    NOT NULL,
    referencia              DATE            NOT NULL COMMENT 'primeiro dia do mês de referência',
    numero_nf               VARCHAR(20)     NULL,
    serie                   VARCHAR(5)      NULL,
    chave_acesso            VARCHAR(60)     NULL,
    data_emissao            DATE            NULL,
    vencimento              DATE            NULL,
    leitura_anterior        DATE            NULL,
    leitura_atual           DATE            NULL,
    dias_faturados          SMALLINT        NULL,
    proxima_leitura         DATE            NULL,
    bandeira                VARCHAR(20)     NULL,
    valor_total             DECIMAL(12,2)   NOT NULL DEFAULT 0,
    consumo_medido_kwh      DECIMAL(14,3)   NULL,
    consumo_faturado_kwh    DECIMAL(14,3)   NULL,
    energia_injetada_kwh    DECIMAL(14,3)   NULL,
    creditos_utilizados_kwh DECIMAL(14,3)   NULL,
    saldo_creditos_kwh      DECIMAL(14,3)   NULL COMMENT 'saldo para o próximo ciclo',
    consumo_ponta_kwh       DECIMAL(14,3)   NULL,
    consumo_fora_ponta_kwh  DECIMAL(14,3)   NULL,
    consumo_reservado_kwh   DECIMAL(14,3)   NULL,
    demanda_medida_kw       DECIMAL(10,2)   NULL,
    demanda_contratada_kw   DECIMAL(10,2)   NULL,
    valor_pis               DECIMAL(12,2)   NULL,
    valor_cofins            DECIMAL(12,2)   NULL,
    valor_icms              DECIMAL(12,2)   NULL,
    situacao_pagamento      VARCHAR(20)     NOT NULL DEFAULT 'aberta' COMMENT 'aberta | paga',
    observacoes             TEXT            NULL,
    criado_em               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_faturas_uc_ref (unidade_id, referencia),
    CONSTRAINT fk_faturas_uc FOREIGN KEY (unidade_id) REFERENCES unidades_consumidoras (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fatura_itens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fatura_id      BIGINT UNSIGNED NOT NULL,
    ordem          SMALLINT        NOT NULL DEFAULT 0,
    descricao      VARCHAR(100)    NOT NULL,
    unidade        VARCHAR(10)     NULL,
    quantidade     DECIMAL(14,3)   NULL,
    preco_unitario DECIMAL(14,8)   NULL,
    valor          DECIMAL(12,2)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_itens_fatura (fatura_id),
    CONSTRAINT fk_itens_fatura FOREIGN KEY (fatura_id) REFERENCES faturas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
