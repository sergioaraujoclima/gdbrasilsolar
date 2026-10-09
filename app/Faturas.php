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
            $s = $pdo->prepare('SELECT id FROM faturas WHERE unidade_id = ? AND referencia = ?');
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
}
