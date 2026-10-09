<?php
/**
 * Migrations versionadas com rollback.
 *
 * Cada mudança de banco são dois arquivos em database/migrations:
 *   NNNN_nome.up.sql    aplica a mudança
 *   NNNN_nome.down.sql  desfaz a mudança
 *
 * schema_migrations guarda o que está aplicado (com o SQL de rollback, para
 * conseguir desfazer mesmo depois que o arquivo sai do repositório) e
 * migrations_historico guarda tudo o que já foi executado, com data e erro.
 *
 * Limitação: as instruções são separadas por ";" no fim da linha, então
 * não use procedures/triggers com ";" internos.
 */
class Migrador
{
    public function __construct(private PDO $pdo, private string $dir)
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                versao      VARCHAR(100) NOT NULL,
                lote        INT UNSIGNED NOT NULL,
                checksum    CHAR(64)     NOT NULL,
                sql_down    MEDIUMTEXT   NOT NULL,
                aplicada_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (versao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations_historico (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                versao       VARCHAR(100) NOT NULL,
                acao         VARCHAR(10)  NOT NULL COMMENT 'up | down',
                sucesso      TINYINT(1)   NOT NULL,
                mensagem     TEXT         NULL,
                executado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** versao => caminho do .up.sql, em ordem. */
    private function arquivos(): array
    {
        $lista = [];
        foreach (glob($this->dir . '/*.up.sql') ?: [] as $caminho) {
            $lista[basename($caminho, '.up.sql')] = $caminho;
        }
        ksort($lista, SORT_STRING);
        return $lista;
    }

    /** versao => linha de schema_migrations, em ordem. */
    private function aplicadas(): array
    {
        $linhas = $this->pdo->query(
            'SELECT versao, lote, checksum, aplicada_em FROM schema_migrations ORDER BY versao'
        )->fetchAll();
        return array_column($linhas, null, 'versao');
    }

    public function status(): array
    {
        $arquivos  = $this->arquivos();
        $aplicadas = $this->aplicadas();

        $alteradas = [];
        foreach ($aplicadas as $versao => $linha) {
            if (isset($arquivos[$versao]) && hash_file('sha256', $arquivos[$versao]) !== $linha['checksum']) {
                $alteradas[] = $versao;
            }
        }

        return [
            'aplicadas' => array_keys($aplicadas),
            'pendentes' => array_values(array_diff(array_keys($arquivos), array_keys($aplicadas))),
            // Aplicadas cujo arquivo saiu do repositório: candidatas a rollback.
            'orfas'     => array_values(array_diff(array_keys($aplicadas), array_keys($arquivos))),
            // Aplicadas cujo .up.sql foi editado depois (não deveria acontecer).
            'alteradas' => $alteradas,
        ];
    }

    /** Aplica todas as pendentes, em ordem. Para na primeira que falhar. */
    public function subir(): array
    {
        $arquivos = $this->arquivos();
        $feitas   = [];
        $lote     = 1 + (int) $this->pdo->query('SELECT COALESCE(MAX(lote), 0) FROM schema_migrations')->fetchColumn();

        foreach ($this->status()['pendentes'] as $versao) {
            $caminhoDown = $this->dir . '/' . $versao . '.down.sql';
            if (!is_file($caminhoDown)) {
                throw new RuntimeException("Falta o arquivo de rollback $versao.down.sql");
            }
            $this->executar($versao, 'up', file_get_contents($arquivos[$versao]));

            $ins = $this->pdo->prepare(
                'INSERT INTO schema_migrations (versao, lote, checksum, sql_down) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$versao, $lote, hash_file('sha256', $arquivos[$versao]), file_get_contents($caminhoDown)]);
            $feitas[] = $versao;
        }
        return $feitas;
    }

    /** Desfaz as versões informadas, da mais nova para a mais antiga. */
    public function descer(array $versoes): array
    {
        rsort($versoes, SORT_STRING);
        $feitas = [];
        foreach ($versoes as $versao) {
            $sel = $this->pdo->prepare('SELECT sql_down FROM schema_migrations WHERE versao = ?');
            $sel->execute([$versao]);
            $sqlDown = $sel->fetchColumn();
            if ($sqlDown === false) {
                throw new RuntimeException("Migration $versao não está aplicada");
            }
            $this->executar($versao, 'down', $sqlDown);
            $this->pdo->prepare('DELETE FROM schema_migrations WHERE versao = ?')->execute([$versao]);
            $feitas[] = $versao;
        }
        return $feitas;
    }

    /** Desfaz as N últimas migrations aplicadas. */
    public function descerUltimas(int $passos): array
    {
        $aplicadas = array_keys($this->aplicadas());
        return $this->descer(array_slice($aplicadas, -max(1, $passos)));
    }

    private function executar(string $versao, string $acao, string $sql): void
    {
        $log = $this->pdo->prepare(
            'INSERT INTO migrations_historico (versao, acao, sucesso, mensagem) VALUES (?, ?, ?, ?)'
        );
        try {
            foreach (self::separar($sql) as $instrucao) {
                $this->pdo->exec($instrucao);
            }
            $log->execute([$versao, $acao, 1, null]);
        } catch (Throwable $e) {
            $log->execute([$versao, $acao, 0, $e->getMessage()]);
            throw new RuntimeException("Falha em $versao ($acao): " . $e->getMessage());
        }
    }

    /** Remove comentários de linha inteira e separa por ";" no fim da linha. */
    public static function separar(string $sql): array
    {
        $linhas = array_filter(
            preg_split('/\R/', $sql),
            fn ($l) => !preg_match('/^\s*--/', $l)
        );
        $partes = preg_split('/;[ \t]*(?:\n|$)/', implode("\n", $linhas));
        return array_values(array_filter(array_map('trim', $partes), fn ($p) => $p !== ''));
    }
}
