<?php
/**
 * Sincronização com a Growatt.
 *
 *   ?acao=usinas     cadastra/atualiza as usinas e abre a carga histórica
 *   ?acao=historico  processa um trecho da carga histórica (chamar até concluir)
 *   ?acao=recentes   busca o que falta desde o último dia gravado (sincronizador de inversores)
 *   ?acao=resumo     só mostra a situação
 *   ?acao=mensal     geração por mês de cada usina (análise de desempenho)
 */
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../app/Growatt.php';
require __DIR__ . '/../app/SincronizadorGrowatt.php';
require __DIR__ . '/../sincronizador-inversores/SincronizadorInversores.php';

exigir_token();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(90);

try {
    $s = new SincronizadorGrowatt(db(), require __DIR__ . '/../config/config.php');
    $r = match ($_GET['acao'] ?? 'resumo') {
        'usinas'    => $s->sincronizarUsinas(),
        'historico' => $s->processarHistorico(),
        'mensal'    => ['meses' => db()->query(
            "SELECT usina_id, DATE_FORMAT(data, '%Y-%m') AS mes, ROUND(SUM(energia_kwh)) AS kwh,
                    SUM(energia_kwh > 0) AS dias_gerando, COUNT(*) AS dias, ROUND(MAX(energia_kwh)) AS max_dia
             FROM geracao_diaria GROUP BY usina_id, DATE_FORMAT(data, '%Y-%m') ORDER BY usina_id, mes"
        )->fetchAll(PDO::FETCH_NUM), 'usinas' => db()->query(
            'SELECT id, potencia_pico_kwp, data_instalacao, energia_total_kwh FROM usinas ORDER BY id'
        )->fetchAll(PDO::FETCH_NUM)],
        'recentes'  => (new SincronizadorInversores(db(), require __DIR__ . '/../config/config.php'))->executar($_GET['origem'] ?? 'manual')
                        ?? ['mensagem' => 'Já havia uma sincronização em andamento.'],
        default     => $s->resumo(),
    };
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
