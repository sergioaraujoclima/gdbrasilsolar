<?php
/**
 * Importa unidades consumidoras e faturas de um arquivo JSON, sem que os
 * dados precisem passar pelo repositório. Pode ser repetida: atualiza o
 * que já existe (UC pela distribuidora + número, fatura pela UC + mês).
 *
 * Em cada unidade, "usina" aceita o id da usina ou as palavras "ativa" e
 * "parada", resolvidas pela data da última geração registrada.
 */
class Importador
{
    private const CAMPOS_UC = ['titular_nome', 'titular_documento', 'classificacao', 'tipo_fornecimento', 'regra_gd',
                               'endereco', 'cidade', 'uf', 'cep', 'medidor', 'demanda_contratada_kw'];

    public function __construct(private PDO $pdo)
    {
    }

    public function importar(array $dados, int $empresaId): array
    {
        $log     = [];
        $porUc   = [];
        $usinas  = $this->usinasPorAtividade($empresaId);

        foreach ($dados['unidades'] ?? [] as $u) {
            $numero = trim((string) ($u['numero_uc'] ?? ''));
            $distr  = trim((string) ($u['distribuidora'] ?? ''));
            if ($numero === '' || $distr === '') {
                $log[] = 'Unidade ignorada: falta número ou distribuidora.';
                continue;
            }

            $usinaId = null;
            $ref     = $u['usina'] ?? null;
            if ($ref === 'ativa' && $usinas) {
                $usinaId = $usinas[0]['id'];
            } elseif ($ref === 'parada' && count($usinas) > 1) {
                $usinaId = end($usinas)['id'];
            } elseif (is_int($ref) && in_array($ref, array_column($usinas, 'id'), true)) {
                $usinaId = $ref;
            }
            if (in_array($ref, ['ativa', 'parada'], true) && $usinaId) {
                $this->pdo->prepare('UPDATE usinas SET situacao = ?, atualizado_em = ? WHERE id = ?')
                    ->execute([$ref, agora(), $usinaId]);
            }

            $v = ['empresa_id' => $empresaId, 'numero_uc' => $numero, 'distribuidora' => $distr,
                  'tipo' => ($u['tipo'] ?? '') === 'beneficiaria' ? 'beneficiaria' : 'geradora',
                  'usina_id' => $usinaId, 'atualizado_em' => agora()];
            foreach (self::CAMPOS_UC as $c) {
                $v[$c] = $u[$c] ?? null;
            }

            $s = $this->pdo->prepare('SELECT id FROM unidades_consumidoras WHERE distribuidora = ? AND numero_uc = ?');
            $s->execute([$distr, $numero]);
            $id = $s->fetchColumn();
            if ($id) {
                $this->pdo->prepare('UPDATE unidades_consumidoras SET ' . implode(' = ?, ', array_keys($v)) . ' = ? WHERE id = ?')
                    ->execute([...array_values($v), $id]);
            } else {
                $v['criado_em'] = agora();
                $this->pdo->prepare('INSERT INTO unidades_consumidoras (' . implode(', ', array_keys($v)) . ') VALUES ('
                    . rtrim(str_repeat('?, ', count($v)), ', ') . ')')->execute(array_values($v));
                $id = $this->pdo->lastInsertId();
            }
            $porUc[$numero] = (int) $id;

            $nomeUsina = $usinaId ? current(array_filter($usinas, fn ($x) => $x['id'] === $usinaId))['nome'] : null;
            $log[] = "UC $numero " . ($id && !isset($v['criado_em']) ? 'atualizada' : 'cadastrada')
                . ($nomeUsina ? " e ligada à usina \"$nomeUsina\"" : '') . '.';
        }

        // Vínculo beneficiária -> geradora, depois que todas existem
        foreach ($dados['unidades'] ?? [] as $u) {
            $de   = $porUc[trim((string) ($u['numero_uc'] ?? ''))] ?? null;
            $para = $porUc[trim((string) ($u['uc_geradora'] ?? ''))] ?? null;
            if ($de && $para) {
                $this->pdo->prepare('UPDATE unidades_consumidoras SET uc_geradora_id = ? WHERE id = ?')->execute([$para, $de]);
                $log[] = "UC {$u['numero_uc']} recebe créditos da UC {$u['uc_geradora']}.";
            }
        }

        foreach ($dados['faturas'] ?? [] as $f) {
            $numero  = trim((string) ($f['numero_uc'] ?? ''));
            $unidade = $porUc[$numero] ?? $this->unidadeDaEmpresa($numero, $empresaId);
            $ref     = referencia_iso($f['referencia'] ?? '');
            if (!$unidade || !$ref) {
                $log[] = "Fatura ignorada: UC \"$numero\" não encontrada ou referência inválida.";
                continue;
            }
            Faturas::salvar($this->pdo, $unidade, $ref, $f, $f['itens'] ?? []);
            $log[] = 'Fatura de ' . mes_br($ref) . " da UC $numero gravada com " . count($f['itens'] ?? []) . ' itens.';
        }
        return $log;
    }

    /** Usinas da empresa, da que gerou mais recentemente para a que parou há mais tempo. */
    private function usinasPorAtividade(int $empresaId): array
    {
        $s = $this->pdo->prepare(
            'SELECT u.id, u.nome, MAX(CASE WHEN g.energia_kwh > 0 THEN g.data END) AS ultima
             FROM usinas u LEFT JOIN geracao_diaria g ON g.usina_id = u.id
             WHERE u.empresa_id = ? GROUP BY u.id, u.nome'
        );
        $s->execute([$empresaId]);
        $usinas = array_map(fn ($l) => ['id' => (int) $l['id'], 'nome' => $l['nome'], 'ultima' => (string) $l['ultima']], $s->fetchAll());
        usort($usinas, fn ($a, $b) => strcmp($b['ultima'], $a['ultima']));
        return $usinas;
    }

    private function unidadeDaEmpresa(string $numero, int $empresaId): ?int
    {
        $s = $this->pdo->prepare('SELECT id FROM unidades_consumidoras WHERE numero_uc = ? AND empresa_id = ?');
        $s->execute([$numero, $empresaId]);
        return ($id = $s->fetchColumn()) ? (int) $id : null;
    }
}
