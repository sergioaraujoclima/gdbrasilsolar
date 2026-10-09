<?php
/**
 * Lê uma fatura da Neoenergia Distribuição Brasília em PDF e devolve os
 * dados no mesmo formato usado pela importação:
 *
 *   ['unidade' => [...], 'fatura' => [... 'itens' => [...]], 'avisos' => [...]]
 *
 * As regras seguem o layout do DANFE de energia da Neoenergia. Campos não
 * encontrados ficam nulos e geram um aviso; nada é inventado.
 */
class LeitorNeoenergia
{
    public const DISTRIBUIDORA = 'Neoenergia Distribuição Brasília';

    private const NUMERO = '-?\d{1,3}(?:\.\d{3})*,\d+-?';
    private const DATA   = '\d{2}\/\d{2}\/\d{4}';

    public static function lerArquivo(string $caminho): array
    {
        require_once __DIR__ . '/../lib/pdfparser/autoload.php';
        $pdf     = (new \Smalot\PdfParser\Parser())->parseFile($caminho);
        $paginas = $pdf->getPages();
        if (!$paginas) {
            throw new RuntimeException('O PDF não tem páginas legíveis.');
        }
        return self::lerTexto($paginas[0]->getText());
    }

    public static function lerTexto(string $t): array
    {
        if (trim($t) === '') {
            throw new RuntimeException('Não há texto no PDF. Faturas escaneadas (imagem) não podem ser lidas.');
        }
        if (stripos($t, 'NEOENERGIA') === false || stripos($t, 'UNIDADE CONSUMIDORA') === false) {
            throw new RuntimeException('Este PDF não parece ser uma fatura da Neoenergia Brasília, a única distribuidora lida automaticamente por enquanto.');
        }

        $avisos = [];
        $um = function (string $regex, int $grupo = 1) use ($t): ?string {
            return preg_match($regex, $t, $m) ? trim($m[$grupo]) : null;
        };

        /* ----- Itens: ficam antes do cabeçalho da distribuidora ----- */
        $itens = [];
        foreach (preg_split('/\R/', strstr($t, 'www.neoenergia', true) ?: '') as $linha) {
            $item = self::item($linha);
            if ($item) {
                $itens[] = $item;
            }
        }

        /* ----- Unidade consumidora ----- */
        $endereco = [];
        if (preg_match('/ENDEREÇO:\s*\R(.*?)\RNÚMERO DA UNIDADE CONSUMIDORA/su', $t, $m)) {
            $endereco = array_values(array_filter(array_map(
                fn ($l) => preg_replace('/\s+/', ' ', trim($l)),
                preg_split('/\R/', $m[1])
            )));
        }
        $cep = $cidade = $uf = null;
        if ($endereco && preg_match('/^(\d{5}-\d{3})\s+(.+?)\s+([A-Z]{2})$/', end($endereco), $m)) {
            [$cep, $cidade, $uf] = [$m[1], $m[2], $m[3]];
            array_pop($endereco);
        }

        $classificacao = $fornecimento = null;
        if (preg_match('/CLASSIFICAÇÃO:\s*(.+?)\s*TIPO DE FORNECIMENTO:\s*([^\r\n]+)/su', $t, $m)) {
            $classificacao = preg_replace('/\s+/', ' ', trim($m[1]));
            $fornecimento  = trim($m[2]);
        }

        // Texto sem espaços nem acentos: as frases de crédito vêm com
        // espaços no meio das palavras ("proxim o ciclo").
        $junto = strtolower(preg_replace('/\s+/', '', strtr(self::semAcento($t), ['ç' => 'c'])));
        $kwh   = function (string $frase) use ($junto): ?float {
            return preg_match('/' . $frase . '(-?\d+(?:[.,]\d+)?)kwh/', $junto, $m)
                ? (float) str_replace(',', '.', $m[1]) : null;
        };

        $injetada   = $kwh('energiainjetadanomes') ?? $kwh('excedente');
        $utilizados = $kwh('creditosutilizados') ?? $kwh('catde-');
        $saldo      = $kwh('saldoparaoproximociclo');

        $tipo = '';
        if (str_contains($junto, 'energiainjetadanomes')) {
            $tipo = 'geradora';
        } elseif (str_contains($junto, 'creditosutilizados')) {
            $tipo = 'beneficiaria';
        }
        $regra = str_contains($junto, 'gdiii') ? 'GD III' : (str_contains($junto, 'gdii') ? 'GD II' : null);

        /* ----- Medidor: consumo medido = (leitura atual - anterior) x constante ----- */
        $medidor = null;
        $medido  = null;
        $n       = self::NUMERO;
        if (preg_match_all("/^\s*(\d{4,})\s+Energia Ativa\s+\S.*?\s+($n)\s+($n)\s+($n)\s+($n)\s*$/mu", $t, $mm, PREG_SET_ORDER)) {
            $medido = 0.0;
            foreach ($mm as $m) {
                $medidor = $m[1];
                $medido += (self::numero($m[3]) - self::numero($m[2])) * self::numero($m[4]);
            }
            $medido = round($medido, 3);
        }

        /* ----- Totais por tipo de item ----- */
        $faturado = $ponta = $fora = $reservado = $demanda = null;
        foreach ($itens as $i) {
            $d = $i['descricao'];
            if (preg_match('/^Consumo[- ]TE\b/i', $d) && $i['quantidade'] !== null) {
                $faturado = ($faturado ?? 0) + $i['quantidade'];
                if (preg_match('/F\.?\s?Ponta/i', $d)) {
                    $fora = $i['quantidade'];
                } elseif (preg_match('/Reserv/i', $d)) {
                    $reservado = $i['quantidade'];
                } elseif (preg_match('/Ponta/i', $d)) {
                    $ponta = $i['quantidade'];
                }
            } elseif (preg_match('/^Demanda Ativa$/i', $d)) {
                $demanda = $i['quantidade'];
            }
        }

        $tributo = fn (string $nome) => preg_match("/^\s*$nome\s+.*?($n)\s*$/m", $t, $m) ? self::numero($m[1]) : null;

        $observacoes = null;
        if (preg_match('/INFORMAÇÕES IMPORTANTES\s*\R(.*?)\R\s*(?:\d{2}\/\d{4}\s*\R|\s*CÓDIGO DÉBITO)/su', $t, $m)) {
            $observacoes = trim(preg_replace('/[ \t]+/', ' ', $m[1]));
        }

        $d = self::DATA;
        preg_match("/LEITURA ANTERIOR\s+($d)\s+LEITURA ATUAL\s+($d)\s+N°\s*DE DIAS\s+(\d+)\s+PRÓXIMA LEITURA\s+($d)/u", $t, $lei);
        preg_match("/NOTA FISCAL N°\s*(\d+)\s*-\s*SÉRIE\s*(\d+)\s*\/\s*DATA DE EMISSÃO:\s*($d)/u", $t, $nf);

        $referencia = $um('/REF:MÊS\/ANO\s+(\d{2}\/\d{4})/u');
        $total      = $um("/TOTAL A PAGAR R\\$\s+($n)/");
        $contratada = $um('/Demanda Contratada\s+(\d+(?:,\d+)?)/');

        $unidade = [
            'numero_uc'             => $um('/NÚMERO DA UNIDADE CONSUMIDORA\s*\R\s*([\d.\-]+)/u'),
            'distribuidora'         => self::DISTRIBUIDORA,
            'tipo'                  => $tipo,
            'titular_nome'          => $um('/NOME DO CLIENTE:\s*\R\s*([^\r\n]+)/u'),
            'titular_documento'     => $um('/(?:CPF|CNPJ):\s*(\S+)/'),
            'classificacao'         => $classificacao,
            'tipo_fornecimento'     => $fornecimento,
            'regra_gd'              => $regra,
            'endereco'              => $endereco ? implode(', ', $endereco) : null,
            'cidade'                => $cidade,
            'uf'                    => $uf,
            'cep'                   => $cep,
            'medidor'               => $medidor,
            'demanda_contratada_kw' => $contratada !== null ? (float) str_replace(',', '.', $contratada) : null,
        ];

        $fatura = [
            'numero_uc'               => $unidade['numero_uc'],
            'referencia'              => $referencia ? substr(referencia_iso($referencia), 0, 7) : null,
            'numero_nf'               => $nf[1] ?? null,
            'serie'                   => $nf[2] ?? null,
            'chave_acesso'            => $um('/chave de acesso:\s*\R\s*([\d ]{40,})/i'),
            'data_emissao'            => data_iso($nf[3] ?? ''),
            'vencimento'              => data_iso($um("/VENCIMENTO\s+($d)/") ?? ''),
            'leitura_anterior'        => data_iso($lei[1] ?? ''),
            'leitura_atual'           => data_iso($lei[2] ?? ''),
            'dias_faturados'          => isset($lei[3]) ? (int) $lei[3] : null,
            'proxima_leitura'         => data_iso($lei[4] ?? ''),
            'bandeira'                => $um('/bandeira em vigor é a\s+(\p{L}+)/u'),
            'valor_total'             => $total !== null ? self::numero($total) : null,
            'consumo_medido_kwh'      => $medido,
            'consumo_faturado_kwh'    => $faturado,
            'energia_injetada_kwh'    => $injetada,
            'creditos_utilizados_kwh' => $utilizados,
            'saldo_creditos_kwh'      => $saldo,
            'consumo_ponta_kwh'       => $ponta,
            'consumo_fora_ponta_kwh'  => $fora,
            'consumo_reservado_kwh'   => $reservado,
            'demanda_medida_kw'       => $demanda,
            'demanda_contratada_kw'   => $unidade['demanda_contratada_kw'],
            'valor_pis'               => $tributo('PIS'),
            'valor_cofins'            => $tributo('COFINS'),
            'valor_icms'              => $tributo('ICMS'),
            'observacoes'             => $observacoes,
            'itens'                   => $itens,
        ];

        /* ----- Conferências ----- */
        foreach (['numero_uc' => 'número da unidade consumidora'] as $c => $nome) {
            if (!$unidade[$c]) {
                $avisos[] = "Não encontrei o $nome.";
            }
        }
        foreach (['referencia' => 'mês de referência', 'valor_total' => 'total a pagar', 'vencimento' => 'vencimento',
                  'leitura_atual' => 'datas de leitura'] as $c => $nome) {
            if ($fatura[$c] === null) {
                $avisos[] = "Não encontrei: $nome.";
            }
        }
        $soma = round(array_sum(array_column($itens, 'valor')), 2);
        $confere = $fatura['valor_total'] !== null && abs($soma - $fatura['valor_total']) < 0.015;
        if (!$itens) {
            $avisos[] = 'Não encontrei os itens da fatura.';
        } elseif (!$confere) {
            $avisos[] = 'A soma dos itens (' . brl($soma) . ') é diferente do total a pagar (' . brl($fatura['valor_total']) . ').';
        }

        return ['unidade' => $unidade, 'fatura' => $fatura, 'avisos' => $avisos,
                'soma_itens' => $soma, 'soma_confere' => $confere];
    }

    /** Uma linha do quadro "Itens da fatura", ou null se a linha não for um item. */
    private static function item(string $linha): ?array
    {
        $n = self::NUMERO;
        if (!preg_match("/^(.*?)\s+($n)((?:\s+$n)*)\s*$/u", rtrim($linha), $m)) {
            return null;
        }
        $descricao = trim(preg_replace('/\s+/', ' ', $m[1]));
        preg_match_all("/$n/", $m[2] . $m[3], $nums);
        $nums = array_map([self::class, 'numero'], $nums[0]);

        $unidade = null;
        if (preg_match('/^(.*\S)\s+(kWh|kW|kVArh?|kVARh?)$/', $descricao, $u)) {
            [$descricao, $unidade] = [trim($u[1]), $u[2]];
        }
        if ($descricao === '') {
            return null;
        }
        // Com unidade: quantidade, preço unitário e valor. Sem unidade: só o valor.
        $completo = $unidade !== null && count($nums) >= 3;
        return [
            'descricao'      => $descricao,
            'unidade'        => $unidade,
            'quantidade'     => $completo ? $nums[0] : null,
            'preco_unitario' => $completo ? $nums[1] : null,
            'valor'          => $completo ? $nums[2] : $nums[0],
        ];
    }

    /** "1.234,56" -> 1234.56; "50,65-" -> -50.65 */
    private static function numero(string $s): float
    {
        $negativo = str_contains($s, '-');
        $v = (float) str_replace(['.', ',', '-'], ['', '.', ''], $s);
        return $negativo ? -$v : $v;
    }

    private static function semAcento(string $s): string
    {
        return strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
                          'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'Á' => 'a', 'Ã' => 'a', 'É' => 'e',
                          'Í' => 'i', 'Ó' => 'o', 'Õ' => 'o', 'Ú' => 'u', 'Ç' => 'c', 'Ê' => 'e', 'Â' => 'a', 'Ô' => 'o']);
    }
}
