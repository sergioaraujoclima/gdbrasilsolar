<?php
/**
 * Acompanhamento dos créditos de energia por unidade consumidora:
 * evolução do saldo a cada ciclo, médias de consumo e injeção, rateio
 * vigente e sugestão de rateio.
 *
 * Tudo é calculado a partir das faturas cadastradas; quanto mais ciclos,
 * mais confiáveis as médias e a tendência.
 */

const MESES_VALIDADE_CREDITO = 60;  // créditos expiram 60 meses após a geração
const MESES_COBERTURA_ALVO   = 12;  // acima disso, a UC não precisa receber mais
const CICLOS_PARA_MEDIA      = 6;

/** Ciclos (faturas) de cada unidade, do mais antigo para o mais novo: [unidade_id => [fatura, ...]]. */
function ciclos_por_unidade(): array
{
    $ciclos = [];
    foreach (array_reverse(faturas_visiveis()) as $f) {
        $ciclos[(int) $f['unidade_id']][] = $f;
    }
    foreach ($ciclos as &$lista) {
        usort($lista, fn ($a, $b) => strcmp($a['referencia'], $b['referencia']));
        $anterior = null;
        foreach ($lista as &$f) {
            $f['variacao_saldo'] = ($anterior !== null && $f['saldo_creditos_kwh'] !== null)
                ? (float) $f['saldo_creditos_kwh'] - $anterior : null;
            if ($f['saldo_creditos_kwh'] !== null) {
                $anterior = (float) $f['saldo_creditos_kwh'];
            }
        }
    }
    return $ciclos;
}

/** Resumo de uma unidade a partir dos seus ciclos. */
function resumo_creditos(array $ciclos): array
{
    $saldos   = array_values(array_filter(array_column($ciclos, 'saldo_creditos_kwh'), fn ($v) => $v !== null));
    $recentes = array_slice($ciclos, -CICLOS_PARA_MEDIA);
    $media    = function (string $campo) use ($recentes): ?float {
        $v = array_filter(array_column($recentes, $campo), fn ($x) => $x !== null);
        return $v ? array_sum($v) / count($v) : null;
    };

    $saldo   = $saldos ? (float) end($saldos) : null;
    $consumo = $media('consumo_medido_kwh');

    // Tendência: último saldo contra o de até 3 ciclos atrás
    $tendencia = 'sem_historico';
    if (count($saldos) >= 2) {
        $base = (float) $saldos[max(0, count($saldos) - 4)];
        $dif  = $saldo - $base;
        $tendencia = abs($dif) <= max(1, abs($base) * 0.02) ? 'estavel' : ($dif > 0 ? 'crescendo' : 'diminuindo');
    }

    return [
        'ciclos'          => count($ciclos),
        'ultima'          => $ciclos ? end($ciclos)['referencia'] : null,
        'saldo'           => $saldo,
        'tendencia'       => $tendencia,
        'consumo_medio'   => $consumo,
        'injecao_media'   => $media('energia_injetada_kwh'),
        'uso_medio'       => $media('creditos_utilizados_kwh'),
        'cobertura_meses' => ($saldo !== null && $consumo) ? $saldo / $consumo : null,
    ];
}

function rotulo_tendencia(string $t): string
{
    return ['crescendo' => 'Crescendo', 'diminuindo' => 'Diminuindo', 'estavel' => 'Estável',
            'sem_historico' => 'Sem histórico'][$t];
}

/** Rateios de uma geradora, do mais recente para o mais antigo, com os itens. */
function rateios_da_geradora(int $geradoraId): array
{
    $s = db()->prepare('SELECT * FROM rateios WHERE uc_geradora_id = ? ORDER BY vigencia_inicio DESC');
    $s->execute([$geradoraId]);
    $rateios = $s->fetchAll();
    $i = db()->prepare(
        'SELECT i.uc_destino_id, i.percentual, c.numero_uc FROM rateio_itens i
         JOIN unidades_consumidoras c ON c.id = i.uc_destino_id WHERE i.rateio_id = ? ORDER BY i.percentual DESC'
    );
    foreach ($rateios as &$r) {
        $i->execute([$r['id']]);
        $r['itens'] = $i->fetchAll();
    }
    return $rateios;
}

/** Rateio que vale hoje: o de vigência mais recente que já começou. */
function rateio_vigente(array $rateios): ?array
{
    $mes = substr(hoje_local(), 0, 7) . '-01';
    foreach ($rateios as $r) {
        if ($r['vigencia_inicio'] <= $mes) {
            return $r;
        }
    }
    return null;
}

/**
 * Sugestão de rateio: divide a injeção na proporção do consumo médio das
 * unidades que ainda têm menos de MESES_COBERTURA_ALVO meses de saldo.
 * $destinos: [unidade_id => resumo_creditos]. Devolve [unidade_id => %].
 */
function sugestao_rateio(array $destinos): array
{
    $necessidade = [];
    foreach ($destinos as $id => $r) {
        $precisa = $r['consumo_medio'] && ($r['cobertura_meses'] === null || $r['cobertura_meses'] < MESES_COBERTURA_ALVO);
        $necessidade[$id] = $precisa ? (float) $r['consumo_medio'] : 0.0;
    }
    $total = array_sum($necessidade);
    if ($total <= 0) {
        return [];
    }
    $pct = array_map(fn ($n) => round(100 * $n / $total), $necessidade);
    // acerta o arredondamento na maior parcela, para somar 100
    arsort($necessidade);
    $pct[array_key_first($necessidade)] += 100 - array_sum($pct);
    return $pct;
}
