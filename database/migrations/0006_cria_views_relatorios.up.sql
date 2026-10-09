-- Relatórios mensal e anual, sempre calculados a partir do diário.
CREATE VIEW vw_geracao_mensal AS
SELECT u.empresa_id,
       g.usina_id,
       u.nome             AS usina,
       YEAR(g.data)       AS ano,
       MONTH(g.data)      AS mes,
       SUM(g.energia_kwh) AS energia_kwh,
       COUNT(*)           AS dias_com_dado
FROM geracao_diaria g
JOIN usinas u ON u.id = g.usina_id
GROUP BY u.empresa_id, g.usina_id, u.nome, YEAR(g.data), MONTH(g.data);

CREATE VIEW vw_geracao_anual AS
SELECT u.empresa_id,
       g.usina_id,
       u.nome             AS usina,
       YEAR(g.data)       AS ano,
       SUM(g.energia_kwh) AS energia_kwh,
       COUNT(*)           AS dias_com_dado
FROM geracao_diaria g
JOIN usinas u ON u.id = g.usina_id
GROUP BY u.empresa_id, g.usina_id, u.nome, YEAR(g.data);
