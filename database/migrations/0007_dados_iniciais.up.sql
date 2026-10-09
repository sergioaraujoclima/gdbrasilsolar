-- Primeira empresa e sua conta Growatt (token em config.php, chave growatt_token).
INSERT INTO empresas (id, nome) VALUES (1, 'GD Brasil Solar');

INSERT INTO integracoes (id, empresa_id, fabricante, descricao, url_base, chave_credencial)
VALUES (1, 1, 'growatt', 'Conta Growatt (ShinePhone)', 'https://openapi.growatt.com/v1', 'growatt_token');
