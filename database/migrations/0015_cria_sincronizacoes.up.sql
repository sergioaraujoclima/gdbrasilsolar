-- Registro de cada execução do sincronizador de inversores: quando rodou,
-- o que gravou e o erro, se houve. Serve também para saber se já é hora de rodar de novo.
CREATE TABLE sincronizacoes (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    origem        VARCHAR(20)  NOT NULL COMMENT 'acesso | deploy | manual',
    iniciado_em   DATETIME     NOT NULL,
    finalizado_em DATETIME     NULL,
    sucesso       TINYINT(1)   NULL,
    dias_gravados INT          NOT NULL DEFAULT 0,
    mensagem      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY ix_sincronizacoes_inicio (iniciado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
