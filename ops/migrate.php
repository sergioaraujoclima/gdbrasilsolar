<?php
/**
 * Executor de migrations, chamado pelo workflow a cada deploy.
 *
 *   ?acao=status              só mostra a situação
 *   ?acao=up                  aplica as pendentes (padrão)
 *   ?acao=up&rollback=1       antes, desfaz as aplicadas cujo arquivo saiu do repositório
 *   ?acao=down&passos=N       desfaz as N últimas (uso manual)
 */
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../app/Migrador.php';

exigir_token();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(120);

try {
    $m    = new Migrador(db(), __DIR__ . '/../database/migrations');
    $acao = $_GET['acao'] ?? 'up';
    $r    = ['ok' => true, 'acao' => $acao, 'desfeitas' => [], 'aplicadas' => []];

    if ($acao === 'down') {
        $r['desfeitas'] = $m->descerUltimas((int) ($_GET['passos'] ?? 1));
    } elseif ($acao === 'up') {
        $orfas = $m->status()['orfas'];
        if ($orfas) {
            if (($_GET['rollback'] ?? '') !== '1') {
                throw new RuntimeException(
                    'Migrations aplicadas sem arquivo no repositório: ' . implode(', ', $orfas)
                    . '. Para desfazê-las, inclua [rollback] na mensagem do commit.'
                );
            }
            $r['desfeitas'] = $m->descer($orfas);
        }
        $r['aplicadas'] = $m->subir();
    }

    $s = $m->status();
    $r['total_aplicadas'] = count($s['aplicadas']);
    $r['pendentes']       = $s['pendentes'];
    $r['alteradas']       = $s['alteradas'];
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
