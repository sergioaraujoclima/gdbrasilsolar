-- Códigos da distribuidora usados pelo modelo antigo de fatura, que não traz
-- o número da UC. Servem para reconhecer a unidade ao anexar o PDF.
ALTER TABLE unidades_consumidoras
    ADD COLUMN codigo_cliente    VARCHAR(20) NULL AFTER medidor,
    ADD COLUMN codigo_instalacao VARCHAR(20) NULL AFTER codigo_cliente;
