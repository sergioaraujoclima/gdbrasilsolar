<?php
/**
 * Traz para o banco as usinas e a geração diária das contas Growatt.
 *
 * A carga histórica anda de hoje para trás, em janelas de 7 dias (o máximo
 * que a API devolve por chamada com detalhe diário), e guarda em
 * cargas_historicas onde parou. Pode ser chamada quantas vezes for preciso.
 */
class SincronizadorGrowatt
{
    private const FUSO          = 'America/Sao_Paulo';
    private const DIAS_JANELA   = 7;
    private const INICIO_PADRAO = '2023-01-01'; // usado se a usina não informar a data de instalação

    /** @var array<int, Growatt> */
    private array $clientes = [];

    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    private function hoje(): string
    {
        return (new DateTime('now', new DateTimeZone(self::FUSO)))->format('Y-m-d');
    }

    private function cliente(array $integracao): Growatt
    {
        $id = (int) $integracao['id'];
        if (!isset($this->clientes[$id])) {
            $token = (string) ($this->cfg[$integracao['chave_credencial']] ?? '');
            $url   = ($this->cfg['growatt_url'] ?? '') ?: $integracao['url_base'];
            $this->clientes[$id] = new Growatt($url, $token);
        }
        return $this->clientes[$id];
    }

    private function integracoes(): array
    {
        return $this->pdo->query(
            "SELECT * FROM integracoes WHERE fabricante = 'growatt' AND ativo = 1 ORDER BY id"
        )->fetchAll();
    }

    /** Cadastra/atualiza as usinas de cada conta e abre a carga histórica das novas. */
    public function sincronizarUsinas(): array
    {
        $upsert = $this->pdo->prepare(
            "INSERT INTO usinas
                (empresa_id, integracao_id, nome, fabricante, id_externo, potencia_pico_kwp, cidade, pais,
                 latitude, longitude, data_instalacao, status_externo, energia_total_kwh, dados_brutos, sincronizado_em)
             VALUES
                (:empresa, :integracao, :nome, 'growatt', :id_externo, :pico, :cidade, :pais,
                 :lat, :lon, :instalacao, :status, :total, :bruto, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                potencia_pico_kwp = VALUES(potencia_pico_kwp), cidade = VALUES(cidade),
                pais = VALUES(pais), latitude = VALUES(latitude), longitude = VALUES(longitude),
                data_instalacao = VALUES(data_instalacao), status_externo = VALUES(status_externo),
                energia_total_kwh = VALUES(energia_total_kwh), dados_brutos = VALUES(dados_brutos),
                sincronizado_em = VALUES(sincronizado_em)"
        );
        $abrirCarga = $this->pdo->prepare(
            "INSERT IGNORE INTO cargas_historicas (usina_id, data_limite, proxima_data)
             SELECT id, COALESCE(data_instalacao, :padrao), :hoje FROM usinas
             WHERE fabricante = 'growatt' AND id_externo = :id_externo"
        );

        $total  = 0;
        $campos = [];
        foreach ($this->integracoes() as $integracao) {
            foreach ($this->cliente($integracao)->usinas() as $u) {
                $campos = array_keys($u);
                $upsert->execute([
                    ':empresa'    => $integracao['empresa_id'],
                    ':integracao' => $integracao['id'],
                    ':nome'       => (string) ($u['name'] ?? ('Usina ' . ($u['plant_id'] ?? ''))),
                    ':id_externo' => (string) $u['plant_id'],
                    ':pico'       => self::numero($u['peak_power'] ?? null),
                    ':cidade'     => self::texto($u['city'] ?? null),
                    ':pais'       => self::texto($u['country'] ?? null),
                    ':lat'        => self::numero($u['latitude'] ?? null),
                    ':lon'        => self::numero($u['longitude'] ?? null),
                    ':instalacao' => self::data($u['create_date'] ?? null),
                    ':status'     => self::texto($u['status'] ?? null),
                    ':total'      => self::numero($u['total_energy'] ?? null),
                    ':bruto'      => json_encode($u, JSON_UNESCAPED_UNICODE),
                ]);
                $abrirCarga->execute([
                    ':padrao'     => self::INICIO_PADRAO,
                    ':hoje'       => $this->hoje(),
                    ':id_externo' => (string) $u['plant_id'],
                ]);
                $total++;
            }
        }
        return ['usinas' => $total, 'campos_api' => $campos];
    }

    /**
     * Processa janelas da carga histórica por até $segundos. Em caso de
     * recusa da API, registra o erro e para (a próxima chamada retoma).
     */
    public function processarHistorico(float $segundos = 25.0): array
    {
        $inicioRelogio = microtime(true);
        $janelas       = 0;
        $erro          = null;

        $proxima = $this->pdo->prepare(
            "SELECT c.usina_id, c.data_limite, c.proxima_data, u.id_externo, i.id, i.url_base, i.chave_credencial
             FROM cargas_historicas c
             JOIN usinas u      ON u.id = c.usina_id
             JOIN integracoes i ON i.id = u.integracao_id
             WHERE c.status = 'pendente' AND u.fabricante = 'growatt'
             ORDER BY c.usina_id LIMIT 1"
        );
        $avancar = $this->pdo->prepare(
            'UPDATE cargas_historicas SET proxima_data = ?, status = ?, ultimo_erro = NULL WHERE usina_id = ?'
        );
        $falhar = $this->pdo->prepare('UPDATE cargas_historicas SET ultimo_erro = ? WHERE usina_id = ?');

        while (microtime(true) - $inicioRelogio < $segundos) {
            $proxima->execute();
            $c = $proxima->fetch();
            if (!$c) {
                break;
            }

            $fim    = new DateTimeImmutable($c['proxima_data']);
            $limite = new DateTimeImmutable($c['data_limite']);
            $inicio = max($limite, $fim->modify('-' . (self::DIAS_JANELA - 1) . ' days'));
            if ($fim < $limite) {
                $avancar->execute([$c['proxima_data'], 'concluida', $c['usina_id']]);
                continue;
            }

            try {
                $dias = $this->cliente($c)->energiaDiaria(
                    $c['id_externo'],
                    $inicio->format('Y-m-d'),
                    $fim->format('Y-m-d')
                );
            } catch (Throwable $e) {
                $erro = $e->getMessage();
                $falhar->execute([mb_substr($erro, 0, 250), $c['usina_id']]);
                break;
            }

            $this->gravarDias((int) $c['usina_id'], $dias);
            $novaProxima = $inicio->modify('-1 day');
            $avancar->execute([
                $novaProxima->format('Y-m-d'),
                $novaProxima < $limite ? 'concluida' : 'pendente',
                $c['usina_id'],
            ]);
            $janelas++;
            usleep(800000); // respeita o limite de frequência da API
        }

        return ['janelas' => $janelas, 'erro' => $erro] + $this->resumo();
    }

    /** Atualiza os últimos dias de todas as usinas (rotina diária). */
    public function sincronizarRecentes(): array
    {
        $usinas = $this->pdo->query(
            "SELECT u.id AS usina_id, u.id_externo, i.id, i.url_base, i.chave_credencial
             FROM usinas u JOIN integracoes i ON i.id = u.integracao_id
             WHERE u.fabricante = 'growatt' AND u.ativo = 1"
        )->fetchAll();

        $fim    = new DateTimeImmutable($this->hoje());
        $inicio = $fim->modify('-' . (self::DIAS_JANELA - 1) . ' days');
        $dias   = 0;
        foreach ($usinas as $u) {
            $dias += $this->gravarDias(
                (int) $u['usina_id'],
                $this->cliente($u)->energiaDiaria($u['id_externo'], $inicio->format('Y-m-d'), $fim->format('Y-m-d'))
            );
            usleep(800000);
        }
        return ['dias_atualizados' => $dias] + $this->resumo();
    }

    private function gravarDias(int $usinaId, array $dias): int
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO geracao_diaria (usina_id, data, energia_kwh, origem) VALUES (?, ?, ?, 'growatt')
             ON DUPLICATE KEY UPDATE energia_kwh = VALUES(energia_kwh), coletado_em = UTC_TIMESTAMP()"
        );
        foreach ($dias as $data => $kwh) {
            $ins->execute([$usinaId, $data, $kwh]);
        }
        return count($dias);
    }

    /** Situação da carga por usina, sem nomes nem valores de produção. */
    public function resumo(): array
    {
        $linhas = $this->pdo->query(
            "SELECT u.id AS usina_id, c.status, c.data_limite, c.proxima_data,
                    COUNT(g.id) AS dias_gravados, MIN(g.data) AS primeiro_dia, MAX(g.data) AS ultimo_dia,
                    SUM(g.energia_kwh > 0) AS dias_com_geracao,
                    ROUND(100 * SUM(g.energia_kwh) / NULLIF(u.energia_total_kwh, 0), 1) AS pct_do_total_fabricante
             FROM usinas u
             LEFT JOIN cargas_historicas c ON c.usina_id = u.id
             LEFT JOIN geracao_diaria g    ON g.usina_id = u.id
             GROUP BY u.id, c.status, c.data_limite, c.proxima_data, u.energia_total_kwh
             ORDER BY u.id"
        )->fetchAll();

        $pendentes = count(array_filter($linhas, fn ($l) => $l['status'] !== 'concluida'));
        return ['concluido' => $linhas && $pendentes === 0, 'usinas_detalhe' => $linhas];
    }

    private static function numero(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function texto(mixed $v): ?string
    {
        return ($v === null || $v === '') ? null : mb_substr((string) $v, 0, 100);
    }

    private static function data(mixed $v): ?string
    {
        $d = substr((string) $v, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }
}
