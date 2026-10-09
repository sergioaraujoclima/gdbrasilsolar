<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/creditos.php';

exigir_login();
$unidades = array_column(unidades_visiveis(), null, 'id');
$ciclos   = ciclos_por_unidade();
$resumos  = [];
foreach ($unidades as $id => $c) {
    $resumos[$id] = resumo_creditos($ciclos[$id] ?? []);
}
$geradoras = array_filter($unidades, fn ($c) => $c['tipo'] === 'geradora');
$atendidas = []; // unidades que aparecem em algum bloco de geradora

painel_inicio('Créditos', 'creditos');
?>
<div class="cabecalho">
  <div><h1>Créditos de energia</h1>
    <p>Saldo de cada unidade ciclo a ciclo, para onde vai a energia injetada e como ajustar o rateio.</p></div>
  <a class="botao" href="/painel/rateios.php">Rateios de crédito</a>
</div>

<?php if (!$geradoras): ?>
  <div class="bloco"><p class="vazio">Nenhuma unidade geradora cadastrada. <a href="/painel/anexar.php">Anexe a fatura</a> de uma unidade com usina para começar.</p></div>
<?php endif; ?>

<?php foreach ($geradoras as $gid => $g):
    $rg       = $resumos[$gid];
    $rateios  = rateios_da_geradora($gid);
    $vigente  = rateio_vigente($rateios);
    $pctAtual = $vigente ? array_column($vigente['itens'], 'percentual', 'uc_destino_id') : [];

    // Destinos: a própria geradora, as beneficiárias ligadas a ela e quem estiver no rateio vigente
    $destinos = [$gid => $rg];
    foreach ($unidades as $id => $c) {
        if ((int) $c['uc_geradora_id'] === $gid || isset($pctAtual[$id])) {
            $destinos[$id] = $resumos[$id];
        }
    }
    $atendidas += $destinos;
    $sugestao    = sugestao_rateio($destinos);
    $necessidade = 0;
    foreach ($destinos as $id => $r) {
        $necessidade += isset($sugestao[$id]) && $sugestao[$id] > 0 ? (float) $r['consumo_medio'] : 0;
    }

    $alertas = [];
    foreach ($destinos as $id => $r) {
        $uc = nome_uc($unidades[$id]);
        if ($r['cobertura_meses'] !== null && $r['cobertura_meses'] > MESES_VALIDADE_CREDITO) {
            $alertas[] = "$uc tem saldo para " . num($r['cobertura_meses']) . ' meses do próprio consumo. Créditos expiram em '
                . MESES_VALIDADE_CREDITO . ' meses: parte desse saldo tende a vencer sem uso.';
        }
        if ($id !== $gid && $r['ciclos'] && !$r['saldo'] && empty($pctAtual[$id])) {
            $alertas[] = "$uc está sem saldo de créditos e fora do rateio vigente.";
        }
    }
    if (!$vigente) {
        $alertas[] = 'Nenhum rateio cadastrado para esta geradora. Sem rateio, toda a energia injetada fica nela mesma.';
    }
    ?>
<h2><?= e(nome_uc($g)) ?>, geradora<?= $g['usina'] ? ' da usina ' . e($g['usina']) : '' ?></h2>
<p class="endereco"><?= e(endereco_uc($g)) ?></p>

<?php foreach ($alertas as $a): ?><p class="aviso aviso-info"><?= e($a) ?></p><?php endforeach; ?>

<div class="indicadores">
  <div class="indicador indicador-credito"><div class="rotulo">Saldo acumulado</div>
    <div class="valor"><?= $rg['saldo'] !== null ? num($rg['saldo']) . ' <small>kWh</small>' : '–' ?></div>
    <div class="nota"><span class="etiqueta etiqueta-<?= $rg['tendencia'] ?>"><?= rotulo_tendencia($rg['tendencia']) ?></span>
      <?= $rg['ultima'] ? ' fatura de ' . mes_br($rg['ultima']) : '' ?></div></div>
  <div class="indicador"><div class="rotulo">Injeção média por ciclo</div><div class="valor"><?= $rg['injecao_media'] !== null ? num($rg['injecao_media']) . ' <small>kWh</small>' : '–' ?></div>
    <div class="nota">Média de até <?= CICLOS_PARA_MEDIA ?> ciclos (<?= $rg['ciclos'] ?> cadastrado<?= $rg['ciclos'] === 1 ? '' : 's' ?>)</div></div>
  <div class="indicador"><div class="rotulo">Consumo médio dos destinos que precisam</div><div class="valor"><?= $necessidade ? num($necessidade) . ' <small>kWh</small>' : '–' ?></div>
    <div class="nota"><?php if ($necessidade && $rg['injecao_media'] !== null): $sobra = $rg['injecao_media'] - $necessidade; ?>
      <?= $sobra >= 0 ? 'Sobram ' . num($sobra) . ' kWh por ciclo: há espaço para outra UC' : 'Faltam ' . num(-$sobra) . ' kWh por ciclo' ?>
    <?php else: ?>Unidades com menos de <?= MESES_COBERTURA_ALVO ?> meses de saldo<?php endif; ?></div></div>
  <div class="indicador"><div class="rotulo">Rateio vigente</div>
    <div class="valor" style="font-size:1.1rem"><?php if ($vigente): foreach ($vigente['itens'] as $i): ?>
      <?= num($i['percentual'], $i['percentual'] == round($i['percentual']) ? 0 : 2) ?>% para <?= $i['uc_destino_id'] == $gid ? 'a própria unidade' : e(nome_uc($i)) ?><br>
    <?php endforeach; else: ?>Não cadastrado<?php endif; ?></div>
    <div class="nota"><?= $vigente ? 'Desde ' . mes_br($vigente['vigencia_inicio']) . '. ' : '' ?><a href="/painel/rateios.php?g=<?= $gid ?>&amp;novo=1">Definir novo rateio</a></div></div>
</div>

<div class="bloco rolagem">
  <h3 style="margin-bottom:.75rem">Para onde vão os créditos</h3>
  <table>
    <thead><tr><th>Unidade</th><th>Papel</th><th class="n">Saldo (kWh)</th><th>Tendência</th><th class="n">Consumo médio (kWh)</th><th class="n">Saldo cobre (meses)</th><th class="n">Rateio atual</th><th class="n">Recebe por ciclo (kWh)</th><th class="n">Rateio sugerido</th></tr></thead>
    <tbody>
    <?php foreach ($destinos as $id => $r): $p = (float) ($pctAtual[$id] ?? 0); ?>
      <tr>
        <td><a href="/painel/faturas.php?unidade=<?= $id ?>"><?= e(nome_uc($unidades[$id])) ?></a></td>
        <td><span class="etiqueta etiqueta-<?= e($unidades[$id]['tipo']) ?>"><?= $id === $gid ? 'Geradora' : 'Beneficiária' ?></span></td>
        <td class="n"><?= num($r['saldo'], 1) ?></td>
        <td><span class="etiqueta etiqueta-<?= $r['tendencia'] ?>"><?= rotulo_tendencia($r['tendencia']) ?></span></td>
        <td class="n"><?= num($r['consumo_medio']) ?></td>
        <td class="n"><?= $r['cobertura_meses'] !== null ? num($r['cobertura_meses'], 1) : '–' ?></td>
        <td class="n"><?= $vigente ? num($p, $p == round($p) ? 0 : 2) . '%' : '–' ?></td>
        <td class="n"><?= ($vigente && $rg['injecao_media'] !== null) ? num($rg['injecao_media'] * $p / 100) : '–' ?></td>
        <td class="n"><?= $sugestao ? num($sugestao[$id] ?? 0) . '%' : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="nota" style="margin-top:.9rem;color:var(--tinta-2)">O rateio sugerido divide a injeção na proporção do consumo médio das unidades com menos de <?= MESES_COBERTURA_ALVO ?> meses de saldo. É uma estimativa a partir das faturas cadastradas; os créditos transferidos só aparecem no saldo da unidade de destino nos ciclos seguintes.</p>
</div>

<div class="bloco rolagem">
  <h3 style="margin-bottom:.75rem">Ciclos da geradora</h3>
  <?php if (empty($ciclos[$gid])): ?><p class="vazio">Nenhuma fatura desta unidade.</p><?php else: ?>
  <table>
    <thead><tr><th>Referência</th><th class="n">Injetado (kWh)</th><th class="n">Consumo medido (kWh)</th><th class="n">Créditos usados (kWh)</th><th class="n">Saldo (kWh)</th><th class="n">Variação do saldo</th></tr></thead>
    <tbody>
    <?php foreach ($ciclos[$gid] as $f): $v = $f['variacao_saldo']; ?>
      <tr>
        <td><a href="/painel/faturas.php?ver=<?= (int) $f['id'] ?>"><?= mes_br($f['referencia']) ?></a></td>
        <td class="n"><?= num($f['energia_injetada_kwh']) ?></td>
        <td class="n"><?= num($f['consumo_medido_kwh']) ?></td>
        <td class="n"><?= num($f['creditos_utilizados_kwh']) ?></td>
        <td class="n"><?= num($f['saldo_creditos_kwh'], 1) ?></td>
        <td class="n"><?= $v === null ? '–' : ($v > 0 ? '+' : '') . num($v, 1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php $soltas = array_diff_key(array_filter($unidades, fn ($c) => $c['tipo'] === 'beneficiaria'), $atendidas);
if ($soltas): ?>
<h2>Beneficiárias sem unidade geradora</h2>
<div class="bloco rolagem">
  <table>
    <thead><tr><th>Unidade</th><th class="n">Saldo (kWh)</th><th>Tendência</th><th class="n">Consumo médio (kWh)</th><th></th></tr></thead>
    <tbody><?php foreach ($soltas as $id => $c): $r = $resumos[$id]; ?>
      <tr><td><?= e(nome_uc($c)) ?><br><small><?= e(endereco_uc($c)) ?></small></td><td class="n"><?= num($r['saldo'], 1) ?></td>
        <td><span class="etiqueta etiqueta-<?= $r['tendencia'] ?>"><?= rotulo_tendencia($r['tendencia']) ?></span></td>
        <td class="n"><?= num($r['consumo_medio']) ?></td>
        <td><a class="botao botao-claro botao-p" href="/painel/unidades.php?editar=<?= $id ?>">Ligar a uma geradora</a></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</div>
<?php endif; ?>
<?php painel_fim();
