<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/grafico.php';

exigir_login();
$u = buscar_usina((int) ($_GET['id'] ?? 0));
if (!$u) {
    http_response_code(404);
    exit('Usina não encontrada.');
}
$t        = totais_geracao()[$u['id']] ?? [];
$faturas  = faturas_visiveis(null, (int) $u['id']);
$unidades = array_filter(unidades_visiveis(), fn ($c) => (int) $c['usina_id'] === (int) $u['id']);
$idsGer   = array_column($unidades, 'id');
$benef    = array_filter(unidades_visiveis(), fn ($c) => in_array($c['uc_geradora_id'], $idsGer));
$saldos   = saldos_creditos();

painel_inicio($u['nome'], 'usinas');
?>
<div class="cabecalho">
  <div>
    <h1><?= e($u['nome']) ?> <span class="etiqueta etiqueta-<?= e($u['situacao']) ?>"><?= $u['situacao'] === 'parada' ? 'Parada' : 'Ativa' ?></span></h1>
    <p><?= e($u['empresa']) ?><?= $u['cidade'] ? ', ' . e($u['cidade']) : '' ?>. <?= $u['fabricante'] === 'manual' ? 'Sem integração com inversor.' : 'Inversor ' . e(ucfirst($u['fabricante'])) . '.' ?></p>
  </div>
  <a class="botao botao-claro" href="/painel/usinas.php?editar=<?= (int) $u['id'] ?>">Editar usina</a>
</div>

<div class="indicadores">
  <div class="indicador"><div class="rotulo">Gerado neste mês</div><div class="valor"><?= num($t['mes'] ?? 0) ?> <small>kWh</small></div></div>
  <div class="indicador"><div class="rotulo">Gerado neste ano</div><div class="valor"><?= num($t['ano'] ?? 0) ?> <small>kWh</small></div></div>
  <div class="indicador"><div class="rotulo">Gerado desde o início</div><div class="valor"><?= num($t['total'] ?? 0) ?> <small>kWh</small></div>
    <div class="nota">Última geração em <?= data_br($t['ultima_geracao'] ?? null) ?></div></div>
  <?php foreach ($unidades as $c): $s = $saldos[$c['id']] ?? null; ?>
  <div class="indicador indicador-credito"><div class="rotulo">Saldo de créditos: <?= e(nome_uc($c)) ?></div>
    <div class="valor"><?= $s ? num($s['saldo_creditos_kwh']) . ' <small>kWh</small>' : '–' ?></div>
    <div class="nota"><?= $s ? 'Fatura de ' . mes_br($s['referencia']) : 'Sem fatura com saldo informado' ?></div></div>
  <?php endforeach; ?>
</div>

<h2>Unidades consumidoras</h2>
<div class="bloco rolagem">
<?php if (!$unidades && !$benef): ?>
  <p class="vazio">Nenhuma unidade consumidora ligada a esta usina. <a href="/painel/unidades.php?nova=1">Cadastrar unidade</a>.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Unidade e endereço</th><th>Papel</th><th>Distribuidora</th><th>Classificação</th><th class="n">Saldo de créditos (kWh)</th></tr></thead>
    <tbody>
    <?php foreach ([...$unidades, ...$benef] as $c): $s = $saldos[$c['id']] ?? null; ?>
      <tr>
        <td><a href="/painel/unidades.php?editar=<?= (int) $c['id'] ?>"><?= e(nome_uc($c)) ?></a><br><small><?= e(endereco_uc($c)) ?></small></td>
        <td><span class="etiqueta etiqueta-<?= e($c['tipo']) ?>"><?= $c['tipo'] === 'geradora' ? 'Geradora' : 'Beneficiária' ?></span></td>
        <td><?= e($c['distribuidora']) ?></td><td><?= e($c['classificacao']) ?></td>
        <td class="n"><?= $s ? num($s['saldo_creditos_kwh'], 1) : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<h2>Faturas vinculadas</h2>
<div class="bloco rolagem">
<?php if (!$faturas): ?>
  <p class="vazio">Nenhuma fatura vinculada a esta usina.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Referência</th><th>UC</th><th>Papel</th><th>Vencimento</th><th class="n">Valor</th><th class="n">Injetado (kWh)</th><th class="n">Créditos usados (kWh)</th><th class="n">Saldo (kWh)</th></tr></thead>
    <tbody>
    <?php foreach ($faturas as $f): ?>
      <tr>
        <td><a href="/painel/faturas.php?ver=<?= (int) $f['id'] ?>"><?= mes_br($f['referencia']) ?></a></td>
        <td><?= e(nome_uc($f)) ?></td>
        <td><span class="etiqueta etiqueta-<?= e($f['tipo_uc']) ?>"><?= $f['tipo_uc'] === 'geradora' ? 'Geradora' : 'Beneficiária' ?></span></td>
        <td><?= data_br($f['vencimento']) ?></td>
        <td class="n"><?= brl($f['valor_total']) ?></td>
        <td class="n"><?= num($f['energia_injetada_kwh']) ?></td>
        <td class="n"><?= num($f['creditos_utilizados_kwh']) ?></td>
        <td class="n"><?= num($f['saldo_creditos_kwh'], 1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<h2>Relatório de geração</h2>
<?php relatorio_geracao([$u], '/painel/usina.php?id=' . (int) $u['id'], (int) $u['id']); ?>
<?php painel_fim();
