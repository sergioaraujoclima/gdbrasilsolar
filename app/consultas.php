<?php
/** Consultas usadas por mais de uma página do painel, já limitadas à empresa do usuário. */

const CORES_USINAS = ['#0f2a44', '#f48c06', '#1e8f5e', '#7b4bb7', '#c2185b', '#00838f'];

function usinas_visiveis(bool $soAtivas = true): array
{
    $params = [];
    $sql = 'SELECT u.*, e.nome AS empresa FROM usinas u JOIN empresas e ON e.id = u.empresa_id WHERE 1=1'
        . filtro_empresa('u', $params) . ($soAtivas ? ' AND u.ativo = 1' : '') . ' ORDER BY u.id';
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function buscar_usina(int $id): ?array
{
    $params = [$id];
    $s = db()->prepare(
        'SELECT u.*, e.nome AS empresa FROM usinas u JOIN empresas e ON e.id = u.empresa_id WHERE u.id = ?'
        . filtro_empresa('u', $params)
    );
    $s->execute($params);
    return $s->fetch() ?: null;
}

function unidades_visiveis(): array
{
    $params = [];
    $s = db()->prepare(
        'SELECT c.*, e.nome AS empresa, u.nome AS usina, g.numero_uc AS uc_geradora
         FROM unidades_consumidoras c
         JOIN empresas e ON e.id = c.empresa_id
         LEFT JOIN usinas u ON u.id = c.usina_id
         LEFT JOIN unidades_consumidoras g ON g.id = c.uc_geradora_id
         WHERE 1=1' . filtro_empresa('c', $params) . ' ORDER BY c.tipo DESC, c.id'
    );
    $s->execute($params);
    return $s->fetchAll();
}

function buscar_unidade(int $id): ?array
{
    $params = [$id];
    $s = db()->prepare('SELECT c.* FROM unidades_consumidoras c WHERE c.id = ?' . filtro_empresa('c', $params));
    $s->execute($params);
    return $s->fetch() ?: null;
}

/** Totais de geração por usina: hoje, mês, ano, acumulado e último dia com geração. */
function totais_geracao(): array
{
    $hoje   = hoje_local();
    $params = [$hoje, substr($hoje, 0, 7) . '-01', substr($hoje, 0, 4) . '-01-01'];
    $s = db()->prepare(
        'SELECT g.usina_id,
                SUM(CASE WHEN g.data = ?  THEN g.energia_kwh ELSE 0 END) AS hoje,
                SUM(CASE WHEN g.data >= ? THEN g.energia_kwh ELSE 0 END) AS mes,
                SUM(CASE WHEN g.data >= ? THEN g.energia_kwh ELSE 0 END) AS ano,
                SUM(g.energia_kwh) AS total,
                MAX(CASE WHEN g.energia_kwh > 0 THEN g.data END) AS ultima_geracao
         FROM geracao_diaria g JOIN usinas u ON u.id = g.usina_id
         WHERE 1=1' . filtro_empresa('u', $params) . ' GROUP BY g.usina_id'
    );
    $s->execute($params);
    return array_column($s->fetchAll(), null, 'usina_id');
}

/**
 * Geração agrupada por dia, mês ou ano entre duas datas.
 * Devolve [chave => [usina_id => kWh]], com chave AAAA-MM-DD, AAAA-MM ou AAAA.
 */
function serie_geracao(string $visao, string $inicio, string $fim, ?int $usinaId = null): array
{
    $tamanho = ['dia' => 10, 'mes' => 7, 'ano' => 4][$visao];
    $params  = [$inicio, $fim];
    $sql = "SELECT g.usina_id, SUBSTR(g.data, 1, $tamanho) AS chave, SUM(g.energia_kwh) AS kwh
            FROM geracao_diaria g JOIN usinas u ON u.id = g.usina_id
            WHERE g.data BETWEEN ? AND ?" . filtro_empresa('u', $params);
    if ($usinaId !== null) {
        $sql .= ' AND g.usina_id = ?';
        $params[] = $usinaId;
    }
    $s = db()->prepare($sql . " GROUP BY g.usina_id, SUBSTR(g.data, 1, $tamanho) ORDER BY chave");
    $s->execute($params);

    $serie = [];
    foreach ($s->fetchAll() as $l) {
        $serie[$l['chave']][(int) $l['usina_id']] = (float) $l['kwh'];
    }
    return $serie;
}

/** Faturas visíveis, da mais recente para a mais antiga. */
function faturas_visiveis(?int $unidadeId = null, ?int $usinaId = null): array
{
    $params = [];
    $sql = 'SELECT f.*, c.numero_uc, c.tipo AS tipo_uc, c.distribuidora, c.usina_id, c.uc_geradora_id, e.nome AS empresa
            FROM faturas f
            JOIN unidades_consumidoras c ON c.id = f.unidade_id
            JOIN empresas e ON e.id = c.empresa_id
            LEFT JOIN unidades_consumidoras g ON g.id = c.uc_geradora_id
            WHERE 1=1' . filtro_empresa('c', $params);
    if ($unidadeId !== null) {
        $sql .= ' AND f.unidade_id = ?';
        $params[] = $unidadeId;
    }
    if ($usinaId !== null) {
        // faturas da UC onde a usina está e das UCs que recebem créditos dela
        $sql .= ' AND (c.usina_id = ? OR g.usina_id = ?)';
        array_push($params, $usinaId, $usinaId);
    }
    $s = db()->prepare($sql . ' ORDER BY f.referencia DESC, c.tipo DESC, f.id DESC');
    $s->execute($params);
    return $s->fetchAll();
}

/** Último saldo de créditos informado em fatura, por unidade consumidora. */
function saldos_creditos(): array
{
    $saldos = [];
    foreach (faturas_visiveis() as $f) {
        if ($f['saldo_creditos_kwh'] !== null && !isset($saldos[$f['unidade_id']])) {
            $saldos[$f['unidade_id']] = $f;
        }
    }
    return $saldos;
}

/** Fatura por id, somente se a unidade dela for visível ao usuário. */
function buscar_fatura(int $id): ?array
{
    foreach (faturas_visiveis() as $f) {
        if ((int) $f['id'] === $id) {
            return $f;
        }
    }
    return null;
}
