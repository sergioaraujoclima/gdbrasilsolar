<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/grafico.php';

exigir_login();
$usinas = usinas_visiveis();
$totais = totais_geracao();
$saldos = saldos_creditos();

$soma = ['hoje' => 0, 'mes' => 0, 'ano' => 0, 'total' => 0];
foreach ($usinas as $u) {
    foreach ($soma as $k => $_) {
        $soma[$k] += (float) ($totais[$u['id']][$k] ?? 0);
    }
}
$saldoTotal = array_sum(array_column($saldos, 'saldo_creditos_kwh'));

painel_inicio('Geração', 'dashboard');
?>
<div class="cabecalho">
  <div>
    <h1>Geração das usinas</h1>
    <p>Energia lida do inversor até <?= data_br(hoje_local()) ?>. Os créditos vêm da última fatura de cada unidade.</p>
  </div>
</div>

<?php if (!$usinas): ?>
  <div class="bloco"><p class="vazio">Nenhuma usina cadastrada ainda. <a href="/painel/usinas.php?nova=1">Cadastrar a primeira usina</a>.</p></div>
<?php else: ?>

<div class="indicadores">
  <div class="indicador"><div class="rotulo">Gerado hoje</div><div class="valor"><?= num($soma['hoje'], 1) ?> <small>kWh</small></div></div>
  <div class="indicador"><div class="rotulo">Gerado neste mês</div><div class="valor"><?= num($soma['mes']) ?> <small>kWh</small></div></div>
  <div class="indicador"><div class="rotulo">Gerado neste ano</div><div class="valor"><?= num($soma['ano']) ?> <small>kWh</small></div></div>
  <div class="indicador"><div class="rotulo">Gerado desde o início</div><div class="valor"><?= num($soma['total']) ?> <small>kWh</small></div></div>
  <div class="indicador indicador-credito">
    <div class="rotulo">Saldo de créditos</div>
    <div class="valor"><?= $saldos ? num($saldoTotal) . ' <small>kWh</small>' : '–' ?></div>
    <div class="nota"><?= $saldos ? 'Soma do último saldo de ' . count($saldos) . ' unidade(s)' : 'Cadastre uma fatura para ver o saldo' ?></div>
  </div>
</div>

<div class="usinas-resumo">
<?php foreach (array_values($usinas) as $i => $u): $t = $totais[$u['id']] ?? []; ?>
  <div class="usina-cartao" style="--cor: <?= CORES_USINAS[$i % count(CORES_USINAS)] ?>">
    <h3><a href="/painel/usina.php?id=<?= (int) $u['id'] ?>"><?= e($u['nome']) ?></a>
      <span class="etiqueta etiqueta-<?= e($u['situacao']) ?>"><?= $u['situacao'] === 'parada' ? 'Parada' : 'Ativa' ?></span></h3>
    <dl>
      <div><dt>Hoje</dt><dd><?= num($t['hoje'] ?? 0, 1) ?></dd></div>
      <div><dt>Mês</dt><dd><?= num($t['mes'] ?? 0) ?></dd></div>
      <div><dt>Ano</dt><dd><?= num($t['ano'] ?? 0) ?></dd></div>
    </dl>
    <p class="nota">Valores em kWh. Última geração registrada em <?= data_br($t['ultima_geracao'] ?? null) ?>.<?= $u['potencia_pico_kwp'] ? ' Potência de pico: ' . num($u['potencia_pico_kwp'], 1) . ' kWp.' : '' ?></p>
  </div>
<?php endforeach; ?>
</div>

<h2>Relatório de geração</h2>
<?php relatorio_geracao($usinas, '/painel/'); ?>

<?php endif; ?>
<?php painel_fim();
