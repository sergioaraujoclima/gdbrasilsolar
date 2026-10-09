<?php
/**
 * Teste de conexão com a API da Growatt, chamado pelo workflow.
 * Exige o token de deploy e devolve só um resumo, sem dados da usina.
 */
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../app/Growatt.php';

exigir_token();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $cfg = require __DIR__ . '/../config/config.php';
    $r   = (new Growatt($cfg))->listarUsinas();
    $j   = $r['json'];

    $codigo = is_array($j) ? ($j['error_code'] ?? null) : null;
    $ok     = $r['http'] === 200 && $codigo === 0;
    if (!$ok) {
        http_response_code(502);
    }
    echo json_encode([
        'ok'         => $ok,
        'http'       => $r['http'],
        'error_code' => $codigo,
        'error_msg'  => is_array($j) ? ($j['error_msg'] ?? '') : 'resposta não é JSON',
        'usinas'     => is_array($j) ? ($j['data']['count'] ?? null) : null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
