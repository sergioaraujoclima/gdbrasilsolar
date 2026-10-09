<?php
/**
 * Sincronização com a Growatt.
 *
 *   ?acao=usinas     cadastra/atualiza as usinas e abre a carga histórica
 *   ?acao=historico  processa um trecho da carga histórica (chamar até concluir)
 *   ?acao=recentes   atualiza os últimos 7 dias (rotina diária)
 *   ?acao=resumo     só mostra a situação
 */
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../app/Growatt.php';
require __DIR__ . '/../app/SincronizadorGrowatt.php';

exigir_token();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(90);

try {
    $s = new SincronizadorGrowatt(db(), require __DIR__ . '/../config/config.php');
    $r = match ($_GET['acao'] ?? 'resumo') {
        'usinas'    => $s->sincronizarUsinas(),
        'historico' => $s->processarHistorico(),
        'recentes'  => $s->sincronizarRecentes(),
        default     => $s->resumo(),
    };
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
