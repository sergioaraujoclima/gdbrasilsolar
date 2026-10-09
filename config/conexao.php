<?php
/**
 * Conexão única com o MySQL.
 * As credenciais vêm de config/config.php, gerado no deploy a partir dos
 * secrets do GitHub (o arquivo não existe no repositório).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $arquivo = __DIR__ . '/config.php';
    if (!is_file($arquivo)) {
        throw new RuntimeException('config/config.php não encontrado');
    }
    $cfg = require $arquivo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $cfg['db_host'],
        $cfg['db_name']
    );

    // db_dsn só existe em ambiente de teste local.
    if (!empty($cfg['db_dsn'])) {
        $dsn = $cfg['db_dsn'];
    }

    $pdo = new PDO($dsn, $cfg['db_user'] ?? null, $cfg['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/** Valida o token enviado pelo workflow no cabeçalho X-Deploy-Token. */
function exigir_token(): void
{
    $arquivo = __DIR__ . '/config.php';
    $cfg = is_file($arquivo) ? require $arquivo : [];
    $esperado = (string) ($cfg['app_token'] ?? '');
    $recebido = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');

    if ($esperado === '' || !hash_equals($esperado, $recebido)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'acesso negado']);
        exit;
    }
}
