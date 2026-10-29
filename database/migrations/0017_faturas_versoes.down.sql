-- Desfazer apaga as versões canceladas, pois a regra antiga só permite uma fatura por mês
DELETE FROM faturas WHERE situacao_registro = 'cancelada';
ALTER TABLE faturas ADD UNIQUE KEY uk_faturas_uc_ref (unidade_id, referencia);
ALTER TABLE faturas DROP INDEX ix_faturas_uc_ref, DROP COLUMN motivo_cancelamento, DROP COLUMN cancelada_em, DROP COLUMN substituida_por, DROP COLUMN situacao_registro;
