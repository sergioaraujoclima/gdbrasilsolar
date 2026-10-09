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

    public function __construct(array $cfg)
    {
        $this->baseUrl = rtrim($cfg['growatt_url'] ?: 'https://openapi.growatt.com/v1', '/');
        $this->token   = (string) ($cfg['growatt_token'] ?? '');
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

    /** Lista as usinas da conta. */
    public function listarUsinas(): array
    {
        return $this->get('plant/list');
    }
}
