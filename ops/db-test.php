<?php
/**
 * Teste de conexão com o banco, chamado pelo workflow após o deploy.
 * Exige o token e nunca devolve credenciais nem mensagens detalhadas.
 */
require __DIR__ . '/../config/conexao.php';

exigir_token();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $linha = db()->query('SELECT VERSION() AS versao, NOW() AS agora')->fetch();
    echo json_encode([
        'ok'     => true,
        'versao' => $linha['versao'],
        'agora'  => $linha['agora'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    $codigo = $e instanceof PDOException ? ($e->errorInfo[1] ?? $e->getCode()) : 0;
    echo json_encode(['ok' => false, 'erro_mysql' => $codigo]);
}
