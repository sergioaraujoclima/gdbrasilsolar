-- Controle da carga histórica, que anda de hoje para trás em janelas de
-- 7 dias. proxima_data é o último dia da próxima janela a buscar; a carga
-- pode ser interrompida e retomada a qualquer momento.
CREATE TABLE cargas_historicas (
    usina_id      INT UNSIGNED NOT NULL,
    data_limite   DATE         NOT NULL COMMENT 'dia mais antigo a buscar',
    proxima_data  DATE         NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pendente' COMMENT 'pendente | concluida',
    ultimo_erro   VARCHAR(255) NULL,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usina_id),
    CONSTRAINT fk_cargas_usina FOREIGN KEY (usina_id) REFERENCES usinas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
