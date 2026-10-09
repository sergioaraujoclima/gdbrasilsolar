<?php
/** Entrega o PDF anexado a uma fatura, ou os dados lidos dele em JSON. */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/consultas.php';

exigir_login();
$f = buscar_fatura((int) ($_GET['id'] ?? 0));
if (!$f || !$f['arquivo_pdf']) {
    http_response_code(404);
    exit('Esta fatura não tem arquivo anexado.');
}
$nome = 'fatura-' . preg_replace('/\D/', '', $f['numero_uc']) . '-' . substr($f['referencia'], 0, 7);

if (($_GET['tipo'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '.json"');
    echo $f['dados_extraidos'] ?: '{}';
    exit;
}

$caminho = __DIR__ . '/../storage/faturas/' . basename($f['arquivo_pdf']);
if (!is_file($caminho)) {
    http_response_code(404);
    exit('O arquivo desta fatura não está mais no servidor.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nome . '.pdf"');
header('Content-Length: ' . filesize($caminho));
readfile($caminho);
