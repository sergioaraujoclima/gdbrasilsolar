<?php
/** Gravação de faturas e seus itens, usada pelo formulário e pela importação. */
class Faturas
{
    public const DATAS    = ['data_emissao', 'vencimento', 'leitura_anterior', 'leitura_atual', 'proxima_leitura'];
    public const TEXTOS   = ['numero_nf', 'serie', 'chave_acesso', 'bandeira', 'observacoes'];
    public const DECIMAIS = ['valor_total', 'consumo_medido_kwh', 'consumo_faturado_kwh', 'energia_injetada_kwh',
                             'creditos_utilizados_kwh', 'saldo_creditos_kwh', 'consumo_ponta_kwh',
                             'consumo_fora_ponta_kwh', 'consumo_reservado_kwh', 'demanda_medida_kw',
                             'demanda_contratada_kw', 'valor_pis', 'valor_cofins', 'valor_icms'];

    /**
     * Cria ou atualiza a fatura da unidade no mês de referência.
     * $d traz os campos já convertidos (datas ISO, números float);
     * $itens é uma lista de [descricao, unidade, quantidade, preco_unitario, valor].
     */
    public static function salvar(PDO $pdo, int $unidadeId, string $referencia, array $d, array $itens, ?int $id = null): int
    {
        $v = ['unidade_id' => $unidadeId, 'referencia' => $referencia];
        foreach ([...self::DATAS, ...self::TEXTOS, ...self::DECIMAIS] as $c) {
            $v[$c] = $d[$c] ?? null;
        }
        $v['valor_total']        = $v['valor_total'] ?? 0;
        $v['dias_faturados']     = isset($d['dias_faturados']) && $d['dias_faturados'] !== '' ? (int) $d['dias_faturados'] : null;
        $v['situacao_pagamento'] = ($d['situacao_pagamento'] ?? '') === 'paga' ? 'paga' : 'aberta';
        $v['atualizado_em']      = agora();

        if ($id === null) {
            $s = $pdo->prepare("SELECT id FROM faturas WHERE unidade_id = ? AND referencia = ? AND situacao_registro = 'vigente'");
            $s->execute([$unidadeId, $referencia]);
            $id = $s->fetchColumn() ?: null;
        }

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE faturas SET ' . implode(' = ?, ', array_keys($v)) . ' = ? WHERE id = ?')
                    ->execute([...array_values($v), $id]);
            } else {
                $v['criado_em'] = agora();
                $pdo->prepare('INSERT INTO faturas (' . implode(', ', array_keys($v)) . ') VALUES ('
                    . rtrim(str_repeat('?, ', count($v)), ', ') . ')')->execute(array_values($v));
                $id = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM fatura_itens WHERE fatura_id = ?')->execute([$id]);
            $ins = $pdo->prepare(
                'INSERT INTO fatura_itens (fatura_id, ordem, descricao, unidade, quantidade, preco_unitario, valor)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($itens) as $ordem => $i) {
                if (trim((string) ($i['descricao'] ?? '')) === '') {
                    continue;
                }
                $ins->execute([$id, $ordem, trim($i['descricao']), $i['unidade'] ?? null, $i['quantidade'] ?? null,
                               $i['preco_unitario'] ?? null, $i['valor'] ?? 0]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return (int) $id;
    }

    /**
     * Valor economizado com os créditos solares: a energia compensada (kWh)
     * cobrada como se fosse consumo, pelos preços unitários de TUSD e TE da
     * própria fatura (com tributos). Devolve [fatura_id => [kwh, tusd, te, valor]].
     */
    public static function economias(PDO $pdo, array $faturas): array
    {
        $ids = array_map('intval', array_column($faturas, 'id'));
        if (!$ids) {
            return [];
        }
        $s = $pdo->query('SELECT fatura_id, descricao, preco_unitario FROM fatura_itens WHERE preco_unitario IS NOT NULL AND fatura_id IN ('
            . implode(',', $ids) . ') ORDER BY fatura_id, ordem, id');
        $precos = [];
        foreach ($s->fetchAll() as $i) {
            $d = $i['descricao'];
            // Grupo B: "Consumo-TUSD" e "Consumo-TE"; grupo A: vale o fora de ponta (horário da geração solar)
            $chave = match (true) {
                (bool) preg_match('/^Consumo[- ]TUSD$/i', $d)                     => 'tusd',
                (bool) preg_match('/^Consumo[- ]TE$/i', $d)                       => 'te',
                (bool) preg_match('/^Consumo[- ]TUSD\s*F\.?\s?Ponta/i', $d)    => 'tusd_fp',
                (bool) preg_match('/^Consumo[- ]TE\s*F\.?\s?Ponta/i', $d)      => 'te_fp',
                $d === 'Consumo'                                                 => 'unico', // modelo antigo: TUSD + TE juntos
                default                                                          => null,
            };
            if ($chave && !isset($precos[$i['fatura_id']][$chave])) {
                $precos[$i['fatura_id']][$chave] = (float) $i['preco_unitario'];
            }
        }

        $r = [];
        foreach ($faturas as $f) {
            $kwh = $f['creditos_utilizados_kwh'];
            $p   = $precos[$f['id']] ?? [];
            $tusd = $p['tusd'] ?? $p['tusd_fp'] ?? null;
            $te   = $p['te'] ?? $p['te_fp'] ?? null;
            if ($tusd === null && $te === null && isset($p['unico'])) {
                [$tusd, $te] = [$p['unico'], 0.0];
            }
            $valor = ($kwh !== null && ($tusd !== null || $te !== null))
                ? round((float) $kwh * ((float) $tusd + (float) $te), 2) : null;
            $r[(int) $f['id']] = ['kwh' => $kwh, 'tusd' => $tusd, 'te' => $te, 'valor' => $valor];
        }
        return $r;
    }

    /** Fatura vigente da unidade no mês, ou null. */
    public static function vigente(PDO $pdo, int $unidadeId, string $referencia): ?array
    {
        $s = $pdo->prepare("SELECT * FROM faturas WHERE unidade_id = ? AND referencia = ? AND situacao_registro = 'vigente' ORDER BY id DESC LIMIT 1");
        $s->execute([$unidadeId, $referencia]);
        return $s->fetch() ?: null;
    }

    /**
     * Grava uma nova versão da fatura do mês. Se já houver uma vigente, ela é
     * marcada como cancelada (com PDF e itens preservados) e a nova passa a valer.
     * Devolve [id da nova, id da anterior ou null].
     */
    public static function novaVersao(PDO $pdo, int $unidadeId, string $referencia, array $d, array $itens, ?string $motivo = null): array
    {
        $anterior = self::vigente($pdo, $unidadeId, $referencia);
        $cancelar = $pdo->prepare("UPDATE faturas SET situacao_registro = ?, cancelada_em = ?, motivo_cancelamento = ?, substituida_por = ? WHERE id = ?");
        if ($anterior) {
            $cancelar->execute(['cancelada', agora(), $motivo ?: 'Substituída por nova versão da fatura', null, $anterior['id']]);
        }
        try {
            $id = self::salvar($pdo, $unidadeId, $referencia, $d, $itens, null);
        } catch (Throwable $e) {
            if ($anterior) { // volta a anterior a valer
                $cancelar->execute(['vigente', null, null, null, $anterior['id']]);
            }
            throw $e;
        }
        if ($anterior) {
            $pdo->prepare('UPDATE faturas SET substituida_por = ? WHERE id = ?')->execute([$id, $anterior['id']]);
        }
        return [$id, $anterior ? (int) $anterior['id'] : null];
    }

    /** Faz uma versão cancelada voltar a valer; a vigente do mês passa a cancelada. */
    public static function reativar(PDO $pdo, int $id): void
    {
        $s = $pdo->prepare('SELECT * FROM faturas WHERE id = ?');
        $s->execute([$id]);
        $f = $s->fetch();
        if (!$f || $f['situacao_registro'] === 'vigente') {
            return;
        }
        $atual = self::vigente($pdo, (int) $f['unidade_id'], $f['referencia']);
        if ($atual) {
            $pdo->prepare("UPDATE faturas SET situacao_registro = 'cancelada', cancelada_em = ?, motivo_cancelamento = ?, substituida_por = ? WHERE id = ?")
                ->execute([agora(), 'Versão anterior reativada', $id, $atual['id']]);
        }
        $pdo->prepare("UPDATE faturas SET situacao_registro = 'vigente', cancelada_em = NULL, motivo_cancelamento = NULL, substituida_por = NULL WHERE id = ?")
            ->execute([$id]);
    }

    /** Versões do mesmo mês da unidade, da mais nova para a mais antiga. */
    public static function versoes(PDO $pdo, int $unidadeId, string $referencia): array
    {
        $s = $pdo->prepare('SELECT * FROM faturas WHERE unidade_id = ? AND referencia = ? ORDER BY id DESC');
        $s->execute([$unidadeId, $referencia]);
        return $s->fetchAll();
    }
}
