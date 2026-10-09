-- Nome/apelido dado pelo gestor a cada unidade consumidora (ex.: "Pivô 13B").
ALTER TABLE unidades_consumidoras ADD COLUMN apelido VARCHAR(80) NULL AFTER numero_uc;
