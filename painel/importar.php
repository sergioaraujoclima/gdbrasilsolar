<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/Faturas.php';
require __DIR__ . '/../app/Importador.php';

exigir_admin();
$log = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $bruto = is_uploaded_file($_FILES['arquivo']['tmp_name'] ?? '')
        ? file_get_contents($_FILES['arquivo']['tmp_name'])
        : '';
    $dados = json_decode((string) $bruto, true);
    if (!is_array($dados)) {
        avisar('O arquivo não é um JSON válido de importação.', 'erro');
        redirecionar('/painel/importar.php');
    }
    try {
        $log = (new Importador(db()))->importar($dados, (int) ($_POST['empresa_id'] ?? 0));
    } catch (Throwable $e) {
        avisar('A importação parou com erro: ' . $e->getMessage(), 'erro');
        redirecionar('/painel/importar.php');
    }
}

painel_inicio('Importar dados', 'importar');
?>
<div class="cabecalho"><div><h1>Importar dados</h1>
  <p>Carrega unidades consumidoras e faturas de um arquivo JSON. Pode ser repetida: o que já existe é atualizado.</p></div></div>

<?php if ($log !== null): ?>
<div class="bloco">
  <h2 style="margin-top:0">Resultado da importação</h2>
  <ul><?php foreach ($log as $linha): ?><li><?= e($linha) ?></li><?php endforeach; ?></ul>
  <p><a href="/painel/faturas.php">Ver as faturas</a> ou <a href="/painel/unidades.php">conferir as unidades</a>.</p>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="bloco">
  <?= csrf_campo() ?>
  <div class="grade">
    <?= selecao('empresa_id', 'Empresa que receberá os dados', array_column(empresas_disponiveis(), 'nome', 'id')) ?>
    <label class="campo"><span>Arquivo de importação (.json)</span><input type="file" name="arquivo" accept=".json,application/json" required></label>
  </div>
  <div class="form-acoes"><button class="botao">Importar arquivo</button></div>
</form>
<?php painel_fim();
