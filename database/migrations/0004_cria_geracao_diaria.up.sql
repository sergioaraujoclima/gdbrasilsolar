-- Energia gerada por usina em cada dia (kWh). A data é a do calendário
-- local da usina. Base de todos os relatórios diários, mensais e anuais.
CREATE TABLE geracao_diaria (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usina_id    INT UNSIGNED    NOT NULL,
    data        DATE            NOT NULL,
    energia_kwh DECIMAL(12,3)   NOT NULL,
    origem      VARCHAR(20)     NOT NULL DEFAULT 'api',
    coletado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_geracao_usina_data (usina_id, data),
    KEY ix_geracao_data (data),
    CONSTRAINT fk_geracao_usina FOREIGN KEY (usina_id) REFERENCES usinas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
