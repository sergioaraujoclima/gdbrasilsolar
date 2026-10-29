-- Versões de fatura: quando a distribuidora refatura um mês (ex.: após
-- contestação), a nova fica vigente e a anterior é guardada como cancelada,
-- com o PDF, para servir de prova. Só a vigente entra em totais e créditos.
ALTER TABLE faturas
    ADD COLUMN situacao_registro   VARCHAR(20)     NOT NULL DEFAULT 'vigente' COMMENT 'vigente | cancelada' AFTER situacao_pagamento,
    ADD COLUMN substituida_por     BIGINT UNSIGNED NULL AFTER situacao_registro,
    ADD COLUMN cancelada_em        DATETIME        NULL AFTER substituida_por,
    ADD COLUMN motivo_cancelamento VARCHAR(255)    NULL AFTER cancelada_em,
    ADD KEY ix_faturas_uc_ref (unidade_id, referencia);

-- Libera mais de uma versão por unidade e mês (a unicidade da vigente fica no sistema)
ALTER TABLE faturas DROP INDEX uk_faturas_uc_ref;
