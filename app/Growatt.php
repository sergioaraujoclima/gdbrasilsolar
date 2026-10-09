<?php
/**
 * Cliente da API pública da Growatt (OpenAPI v1), a mesma do token gerado
 * no ShinePhone. O token vem de config/config.php.
 *
 * A API tem limite de frequência: evite chamar o mesmo endpoint mais de
 * uma vez a cada poucos minutos.
 */
class Growatt
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl ?: 'https://openapi.growatt.com/v1', '/');
        $this->token   = $token;
        if ($this->token === '') {
            throw new RuntimeException('Token da Growatt não configurado');
        }
    }

    /**
     * Faz um GET e devolve ['http' => int, 'json' => array|null].
     */
    public function get(string $caminho, array $parametros = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($caminho, '/');
        if ($parametros) {
            $url .= '?' . http_build_query($parametros);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['token: ' . $this->token, 'Accept: application/json'],
        ]);
        $corpo = curl_exec($ch);
        if ($corpo === false) {
            $erro = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Falha de rede: ' . $erro);
        }
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['http' => $http, 'json' => json_decode($corpo, true)];
    }

    /**
     * GET que já valida a resposta e devolve só o conteúdo de "data".
     * Lança GrowattErro quando a API recusa (token, limite de frequência...).
     */
    public function dados(string $caminho, array $parametros = []): array
    {
        $r = $this->get($caminho, $parametros);
        $j = $r['json'];
        if ($r['http'] !== 200 || !is_array($j) || ($j['error_code'] ?? null) !== 0) {
            $codigo = is_array($j) ? ($j['error_code'] ?? '?') : '?';
            $msg    = is_array($j) ? ($j['error_msg'] ?? '') : 'resposta não é JSON';
            throw new GrowattErro("Growatt HTTP {$r['http']}, error_code $codigo: $msg");
        }
        return is_array($j['data'] ?? null) ? $j['data'] : [];
    }

    /** Lista as usinas da conta (resposta crua, usada no teste de conexão). */
    public function listarUsinas(): array
    {
        return $this->get('plant/list');
    }

    /** Todas as usinas da conta, percorrendo as páginas. */
    public function usinas(): array
    {
        $todas = [];
        for ($pagina = 1; $pagina <= 50; $pagina++) {
            $d      = $this->dados('plant/list', ['page' => $pagina, 'perpage' => 100]);
            $lote   = $d['plants'] ?? [];
            $todas  = array_merge($todas, $lote);
            if (!$lote || count($todas) >= (int) ($d['count'] ?? 0)) {
                break;
            }
        }
        return $todas;
    }

    /**
     * Energia gerada por dia (kWh) entre duas datas, no máximo 7 dias por
     * chamada (limite da API). Devolve ['AAAA-MM-DD' => kWh].
     */
    public function energiaDiaria(string $idUsina, string $inicio, string $fim): array
    {
        $d = $this->dados('plant/energy', [
            'plant_id'   => $idUsina,
            'start_date' => $inicio,
            'end_date'   => $fim,
            'time_unit'  => 'day',
            'page'       => 1,
            'perpage'    => 100,
        ]);
        $dias = [];
        foreach ($d['energys'] ?? [] as $item) {
            $data = substr((string) ($item['date'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) && is_numeric($item['energy'] ?? null)) {
                $dias[$data] = (float) $item['energy'];
            }
        }
        return $dias;
    }
}

class GrowattErro extends RuntimeException
{
}
