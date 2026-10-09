-- Situação operacional informada pelo gestor (ativa | parada) e observações.
ALTER TABLE usinas
    ADD COLUMN situacao    VARCHAR(20) NOT NULL DEFAULT 'ativa' AFTER status_externo,
    ADD COLUMN observacoes TEXT NULL AFTER dados_brutos;
