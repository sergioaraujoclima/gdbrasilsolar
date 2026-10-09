<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/creditos.php';

$u   = exigir_login();
$pdo = db();
$unidades  = array_column(unidades_visiveis(), null, 'id');
$geradoras = array_filter($unidades, fn ($c) => $c['tipo'] === 'geradora');

/** Rateio por id, somente se a geradora for visível ao usuário. */
function buscar_rateio(int $id, array $geradoras): ?array
{
    $s = db()->prepare('SELECT * FROM rateios WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    return ($r && isset($geradoras[$r['uc_geradora_id']])) ? $r : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id     = (int) ($_POST['id'] ?? 0);
    $rateio = $id ? buscar_rateio($id, $geradoras) : null;
    $gid    = $rateio ? (int) $rateio['uc_geradora_id'] : (int) ($_POST['uc_geradora_id'] ?? 0);
    if (($id && !$rateio) || !isset($geradoras[$gid])) {
        http_response_code(404);
        exit('Rateio ou unidade geradora não encontrada.');
    }
    $volta = '/painel/rateios.php?g=' . $gid;

    if (($_POST['acao'] ?? '') === 'excluir') {
        $pdo->prepare('DELETE FROM rateios WHERE id = ?')->execute([$id]);
        avisar('Rateio excluído.');
        redirecionar($volta);
    }

    // Só entram destinos da mesma empresa da geradora, com percentual maior que zero
    $itens = [];
    foreach ((array) ($_POST['pct'] ?? []) as $destino => $valor) {
        $p = decimal($valor);
        if ($p !== null && $p > 0 && isset($unidades[$destino])
            && $unidades[$destino]['empresa_id'] == $geradoras[$gid]['empresa_id']) {
            $itens[(int) $destino] = round($p, 2);
        }
    }
    $vigencia = referencia_iso($_POST['vigencia'] ?? '');
    $soma     = round(array_sum($itens), 2);
    if (!$vigencia || abs($soma - 100) > 0.001) {
        avisar(!$vigencia ? 'Informe o mês em que o rateio passa a valer.'
            : 'Os percentuais precisam somar 100%. A soma informada foi ' . num($soma, 2) . '%.', 'erro');
        redirecionar($volta . ($id ? '&editar=' . $id : '&novo=1'));
    }

    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare('UPDATE rateios SET vigencia_inicio = ?, observacoes = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$vigencia, texto($_POST['observacoes'] ?? ''), agora(), $id]);
        } else {
            $pdo->prepare('INSERT INTO rateios (uc_geradora_id, vigencia_inicio, observacoes, criado_por, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$gid, $vigencia, texto($_POST['observacoes'] ?? ''), $u['id'], agora(), agora()]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM rateio_itens WHERE rateio_id = ?')->execute([$id]);
        $ins  = $pdo->prepare('INSERT INTO rateio_itens (rateio_id, uc_destino_id, percentual) VALUES (?, ?, ?)');
        $liga = $pdo->prepare("UPDATE unidades_consumidoras SET uc_geradora_id = ? WHERE id = ? AND tipo = 'beneficiaria' AND uc_geradora_id IS NULL");
        foreach ($itens as $destino => $p) {
            $ins->execute([$id, $destino, $p]);
            $liga->execute([$gid, $destino]); // beneficiária ainda sem geradora passa a ficar ligada a esta
        }
        $pdo->commit();
        avisar('Rateio salvo. Lembre-se de registrar o mesmo rateio no site da distribuidora.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        avisar('Já existe um rateio desta geradora com início no mesmo mês. Edite o existente.', 'erro');
    }
    redirecionar($volta);
}

$gid = isset($_GET['g']) ? (int) $_GET['g'] : (count($geradoras) === 1 ? (int) array_key_first($geradoras) : 0);
$g   = $geradoras[$gid] ?? null;
$editar = ($g && isset($_GET['editar'])) ? buscar_rateio((int) $_GET['editar'], $geradoras) : null;
$form   = $g && ($editar || isset($_GET['novo']));

painel_inicio('Rateios de crédito', 'rateios');

if (!$geradoras): ?>
<div class="cabecalho"><div><h1>Rateios de crédito</h1></div></div>
<div class="bloco"><p class="vazio">Cadastre primeiro uma unidade geradora.</p></div>

<?php elseif ($form):
    $rateios = rateios_da_geradora($gid);
    $base    = $editar ? current(array_filter($rateios, fn ($r) => $r['id'] == $editar['id'])) : (rateio_vigente($rateios) ?: null);
    $pct     = $base ? array_column($base['itens'], 'percentual', 'uc_destino_id') : [];
    $ciclos  = ciclos_por_unidade();
    // Destinos possíveis: a própria geradora e as demais unidades da mesma empresa
    $candidatas = [$gid => $g] + array_filter($unidades, fn ($c) => $c['empresa_id'] == $g['empresa_id'] && (int) $c['id'] !== $gid);
    $resumos = [];
    foreach ($candidatas as $id => $c) {
        $resumos[$id] = resumo_creditos($ciclos[$id] ?? []);
    }
    $sugestao = sugestao_rateio($resumos);
    ?>
<div class="cabecalho"><div><h1><?= $editar ? 'Editar rateio' : 'Novo rateio' ?> : <?= e(nome_uc($g)) ?></h1>
  <p><?= e(endereco_uc($g)) ?>. Informe quanto da energia injetada vai para cada unidade. Os percentuais precisam somar 100%.</p></div></div>
<form method="post" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>"><input type="hidden" name="uc_geradora_id" value="<?= $gid ?>">
  <div class="grade">
    <?= campo('vigencia', 'Vale a partir do mês', substr($editar['vigencia_inicio'] ?? hoje_local(), 0, 7), 'month', 'required') ?>
    <?= campo('observacoes', 'Observações', $editar['observacoes'] ?? '', 'text', 'maxlength="255" placeholder="Ex.: protocolo na distribuidora"') ?>
  </div>
  <div class="rolagem" style="margin-top:1.5rem"><table>
    <thead><tr><th>Unidade de destino</th><th>Papel</th><th class="n">Saldo (kWh)</th><th class="n">Consumo médio (kWh)</th><th class="n">Saldo cobre (meses)</th><th class="n">Sugestão</th><th class="n">Percentual</th></tr></thead>
    <tbody>
    <?php foreach ($candidatas as $id => $c): $r = $resumos[$id]; $p = $pct[$id] ?? null; ?>
      <tr>
        <td><?= e(nome_uc($c)) ?><?= $id === $gid ? ' (fica na própria geradora)' : '' ?><br><small><?= e(endereco_uc($c)) ?></small></td>
        <td><?= etiqueta_papel($c['tipo']) ?></td>
        <td class="n"><?= num($r['saldo'], 1) ?></td>
        <td class="n"><?= num($r['consumo_medio']) ?></td>
        <td class="n"><?= $r['cobertura_meses'] !== null ? num($r['cobertura_meses'], 1) : '–' ?></td>
        <td class="n"><?= $sugestao ? num($sugestao[$id] ?? 0) . '%' : '–' ?></td>
        <td class="n itens"><input name="pct[<?= $id ?>]" value="<?= $p !== null ? num($p, $p == round($p) ? 0 : 2) : '' ?>" inputmode="decimal" style="width:6rem;text-align:right" aria-label="Percentual para a UC <?= e($c['numero_uc']) ?>"> %</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="6">Soma dos percentuais</td><td class="n"><span id="soma-pct">0</span>%</td></tr></tfoot>
  </table></div>
  <div class="form-acoes">
    <button class="botao">Salvar rateio</button>
    <a class="botao botao-claro" href="/painel/rateios.php?g=<?= $gid ?>">Cancelar</a>
    <?php if ($editar): ?><button class="botao botao-perigo" name="acao" value="excluir" formnovalidate onclick="return confirm('Excluir este rateio?')">Excluir</button><?php endif; ?>
  </div>
</form>
<script>
(function () {
  var campos = document.querySelectorAll('input[name^="pct["]'), saida = document.getElementById('soma-pct');
  function somar() {
    var t = 0;
    campos.forEach(function (c) { t += parseFloat(c.value.replace(/\./g, '').replace(',', '.')) || 0; });
    saida.textContent = t.toLocaleString('pt-BR', { maximumFractionDigits: 2 });
    saida.style.color = Math.abs(t - 100) < 0.001 ? 'var(--folha)' : 'var(--alerta)';
  }
  campos.forEach(function (c) { c.addEventListener('input', somar); });
  somar();
})();
</script>

<?php else: ?>
<div class="cabecalho">
  <div><h1>Rateios de crédito</h1><p>Como a energia injetada por cada unidade geradora é dividida entre as unidades. O sistema guarda o histórico de cada mudança.</p></div>
  <a class="botao botao-claro" href="/painel/creditos.php">Ver créditos</a>
</div>
<?php foreach ($geradoras as $id => $c): $rateios = rateios_da_geradora($id); $vigente = rateio_vigente($rateios); ?>
<h2><?= e(nome_uc($c)) ?>, geradora<?= $c['usina'] ? ' da usina ' . e($c['usina']) : '' ?></h2>
<p class="endereco"><?= e(endereco_uc($c)) ?></p>
<div class="bloco rolagem">
  <?php if (!$rateios): ?>
    <p class="vazio">Nenhum rateio cadastrado: toda a energia injetada fica na própria unidade.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Vale a partir de</th><th>Situação</th><th>Divisão</th><th>Observações</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rateios as $r): ?>
      <tr>
        <td><?= mes_br($r['vigencia_inicio']) ?></td>
        <td><?php if ($vigente && $r['id'] == $vigente['id']): ?><span class="etiqueta">Vigente</span>
            <?php elseif ($r['vigencia_inicio'] > substr(hoje_local(), 0, 7) . '-01'): ?><span class="etiqueta etiqueta-pendente">Futuro</span>
            <?php else: ?><span class="etiqueta etiqueta-inativa">Encerrado</span><?php endif; ?></td>
        <td><?php foreach ($r['itens'] as $i): ?><?= num($i['percentual'], $i['percentual'] == round($i['percentual']) ? 0 : 2) ?>% para <?= $i['uc_destino_id'] == $id ? 'a própria unidade' : e(nome_uc($i)) ?><br><?php endforeach; ?></td>
        <td><?= e($r['observacoes']) ?></td>
        <td><div class="acoes"><a class="botao botao-claro botao-p" href="/painel/rateios.php?g=<?= $id ?>&amp;editar=<?= (int) $r['id'] ?>">Editar</a></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="form-acoes"><a class="botao" href="/painel/rateios.php?g=<?= $id ?>&amp;novo=1">Novo rateio</a></div>
</div>
<?php endforeach; ?>
<?php endif;
painel_fim();
