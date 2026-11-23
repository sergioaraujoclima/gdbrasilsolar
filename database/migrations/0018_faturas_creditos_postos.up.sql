-- Energia compensada por posto horário (grupo A), lida do "Demonstrativo de
-- consumo" da fatura. Permite calcular a economia com o preço de cada posto.
ALTER TABLE faturas
    ADD COLUMN creditos_ponta_kwh      DECIMAL(14,3) NULL AFTER creditos_utilizados_kwh,
    ADD COLUMN creditos_fora_ponta_kwh DECIMAL(14,3) NULL AFTER creditos_ponta_kwh,
    ADD COLUMN creditos_reservado_kwh  DECIMAL(14,3) NULL AFTER creditos_fora_ponta_kwh;
