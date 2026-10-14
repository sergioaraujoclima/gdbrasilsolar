<?php
/**
 * Sincronizador de inversores.
 *
 * Mantém a geração diária das usinas atualizada no banco. Roda sozinho
 * quando alguém acessa o painel, no máximo uma vez a cada INTERVALO_MINUTOS,
 * e sempre depois que a página já foi entregue ao navegador, para não
 * deixar o acesso mais lento. Também pode ser chamado pelo deploy ou à mão
 * (ops/growatt-sync.php?acao=recentes).
 *
 * Para incluir outro fabricante, crie a classe dele em app/ e chame-a em
 * executar(), como é feito com a Growatt.
 */
require_once __DIR__ . '/../app/Growatt.php';
require_once __DIR__ . '/../app/SincronizadorGrowatt.php';

class SincronizadorInversores
{
    public const INTERVALO_MINUTOS = 30;
    private const TRAVA = __DIR__ . '/../storage/sincronizador.lock';

    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    /** Última execução registrada, ou null. */
    public function ultima(): ?array
    {
        $s = $this->pdo->query('SELECT * FROM sincronizacoes ORDER BY id DESC LIMIT 1');
        return $s->fetch() ?: null;
    }

    /** Já passou o intervalo desde a última execução? */
    public function estaNaHora(): bool
    {
        $u = $this->ultima();
        return !$u || strtotime($u['iniciado_em'] . ' UTC') < time() - self::INTERVALO_MINUTOS * 60;
    }

    /**
     * Executa a sincronização. Devolve o resumo, ou null se outra execução
     * já estiver em andamento.
     */
    public function executar(string $origem): ?array
    {
        if (!is_dir(dirname(self::TRAVA))) {
            mkdir(dirname(self::TRAVA), 0755, true);
        }
        $trava = fopen(self::TRAVA, 'c');
        if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) {
            return null; // já existe uma sincronização rodando
        }
        try {
            $this->pdo->prepare('INSERT INTO sincronizacoes (origem, iniciado_em) VALUES (?, ?)')
                ->execute([$origem, gmdate('Y-m-d H:i:s')]);
            $id = (int) $this->pdo->lastInsertId();

            try {
                $r = (new SincronizadorGrowatt($this->pdo, $this->cfg))->sincronizarPendentes();
            } catch (Throwable $e) {
                $r = ['dias' => 0, 'usinas' => 0, 'erros' => [$e->getMessage()]];
            }

            $msg = $r['erros'] ? implode(' | ', $r['erros'])
                : $r['dias'] . ' dia(s) gravado(s) de ' . $r['usinas'] . ' usina(s)';
            $this->pdo->prepare('UPDATE sincronizacoes SET finalizado_em = ?, sucesso = ?, dias_gravados = ?, mensagem = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s'), $r['erros'] ? 0 : 1, $r['dias'], mb_substr($msg, 0, 255), $id]);
            return $r + ['mensagem' => $msg];
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    /**
     * Agenda a sincronização para depois que a página for entregue, se já
     * for hora. Chamada no início de cada página do painel.
     */
    public static function agendarAposResposta(PDO $pdo, array $cfg): void
    {
        try {
            $s = new self($pdo, $cfg);
            if (!$s->estaNaHora()) {
                return;
            }
        } catch (Throwable $e) {
            return; // tabela ainda não criada, banco fora do ar: o painel segue normal
        }
        register_shutdown_function(function () use ($s) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // não segura outras páginas do mesmo usuário
            }
            // fecha a conexão com o navegador antes de chamar a API
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } elseif (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(120);
            try {
                $s->executar('acesso');
            } catch (Throwable $e) {
                error_log('Sincronizador de inversores: ' . $e->getMessage());
            }
        });
    }
}
