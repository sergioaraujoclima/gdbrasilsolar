<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/Faturas.php';

exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id      = (int) ($_POST['id'] ?? 0);
    $fatura  = $id ? buscar_fatura($id) : null;
    $unidade = buscar_unidade($fatura ? (int) $fatura['unidade_id'] : (int) ($_POST['unidade_id'] ?? 0));
    if (($id && !$fatura) || !$unidade) {
        http_response_code(404);
        exit('Fatura ou unidade não encontrada.');
    }

    if (($_POST['acao'] ?? '') === 'reativar' && $fatura) {
        Faturas::reativar($pdo, $id);
        avisar('Esta versão voltou a ser a fatura que vale. A que estava valendo foi marcada como cancelada e guardada no histórico.');
        redirecionar('/painel/faturas.php?ver=' . $id);
    }

    if (($_POST['acao'] ?? '') === 'excluir') {
        $pdo->prepare('DELETE FROM faturas WHERE id = ?')->execute([$id]);
        // Excluir a vigente faz a versão cancelada mais recente do mês voltar a valer
        $restaurada = null;
        if ($fatura['situacao_registro'] === 'vigente') {
            foreach (Faturas::versoes($pdo, (int) $fatura['unidade_id'], $fatura['referencia']) as $v) {
                Faturas::reativar($pdo, (int) $v['id']);
                $restaurada = (int) $v['id'];
                break;
            }
        }
        avisar('Fatura excluída.' . ($restaurada ? ' A versão anterior do mesmo mês voltou a valer.' : ''));
        redirecionar('/painel/faturas.php' . ($restaurada ? '?ver=' . $restaurada : ''));
    }

    if ($fatura && $fatura['situacao_registro'] === 'cancelada') {
        avisar('Esta versão está cancelada e fica só como histórico. Para editá-la, use "Tornar esta a fatura que vale".', 'erro');
        redirecionar('/painel/faturas.php?ver=' . $id);
    }

    $referencia = referencia_iso($_POST['referencia'] ?? '');
    if (!$referencia) {
        avisar('Informe o mês de referência da fatura.', 'erro');
        redirecionar('/painel/faturas.php?' . ($id ? "ver=$id" : 'nova=1&unidade=' . (int) $unidade['id']));
    }

    // Valor que não dá para entender não pode virar zero calado
    $invalidos = array_filter(Faturas::DECIMAIS, fn ($c) => decimal_invalido($_POST[$c] ?? ''));
    foreach (['item_quantidade', 'item_preco', 'item_valor'] as $c) {
        foreach ((array) ($_POST[$c] ?? []) as $valor) {
            if (decimal_invalido($valor)) {
                $invalidos[] = 'itens da fatura';
                break;
            }
        }
    }
    if ($invalidos) {
        avisar('Nada foi salvo: não entendi o número digitado em ' . implode(', ', array_unique(array_map(fn ($c) => str_replace('_', ' ', $c), $invalidos)))
            . '. Use só números, como 1.234,56.', 'erro');
        redirecionar('/painel/faturas.php?' . ($id ? "ver=$id" : 'nova=1&unidade=' . (int) $unidade['id']));
    }

    $d = ['dias_faturados' => $_POST['dias_faturados'] ?? '', 'situacao_pagamento' => $_POST['situacao_pagamento'] ?? ''];
    foreach (Faturas::DATAS as $c) {
        $d[$c] = data_iso($_POST[$c] ?? '');
    }
    foreach (Faturas::TEXTOS as $c) {
        $d[$c] = texto($_POST[$c] ?? '');
    }
    foreach (Faturas::DECIMAIS as $c) {
        $d[$c] = decimal($_POST[$c] ?? '');
    }
    $itens = [];
    foreach ((array) ($_POST['item_descricao'] ?? []) as $i => $descricao) {
        $itens[] = [
            'descricao'      => (string) $descricao,
            'unidade'        => texto($_POST['item_unidade'][$i] ?? ''),
            'quantidade'     => decimal($_POST['item_quantidade'][$i] ?? ''),
            'preco_unitario' => decimal($_POST['item_preco'][$i] ?? ''),
            'valor'          => decimal($_POST['item_valor'][$i] ?? '') ?? 0,
        ];
    }

    try {
        if ($id) {
            $id = Faturas::salvar($pdo, (int) $unidade['id'], $referencia, $d, $itens, $id);
            avisar('Fatura salva.');
        } else {
            // Nova fatura num mês que já tem uma: a anterior vira histórico (cancelada)
            [$id, $anterior] = Faturas::novaVersao($pdo, (int) $unidade['id'], $referencia, $d, $itens,
                'Substituída por fatura digitada em ' . date('d/m/Y'));
            avisar('Fatura salva.' . ($anterior ? ' A fatura que já existia neste mês foi marcada como cancelada e guardada no histórico.' : ''));
        }
    } catch (PDOException $e) {
        avisar('Não foi possível salvar: já existe outra fatura desta unidade no mesmo mês.', 'erro');
    }
    redirecionar('/painel/faturas.php' . ($id ? "?ver=$id" : ''));
}

$ver      = isset($_GET['ver']) ? buscar_fatura((int) $_GET['ver']) : null;
$form     = $ver || isset($_GET['nova']);
$unidades = unidades_visiveis();
$filtro   = isset($_GET['unidade']) ? (int) $_GET['unidade'] : null;

painel_inicio('Faturas', 'faturas');

if ($form && !$unidades):
    echo '<div class="bloco"><p class="vazio">Cadastre primeiro a <a href="/painel/unidades.php?nova=1">unidade consumidora</a> da fatura.</p></div>';
elseif ($form):
    $f = $ver ?? ['id' => 0, 'unidade_id' => $filtro, 'referencia' => '', 'dias_faturados' => '', 'situacao_pagamento' => 'aberta'];
    $itens = [];
    if ($ver) {
        $s = $pdo->prepare('SELECT * FROM fatura_itens WHERE fatura_id = ? ORDER BY ordem, id');
        $s->execute([$ver['id']]);
        $itens = $s->fetchAll();
    }
    $itens = array_merge($itens, array_fill(0, $ver ? 3 : 8, []));
    $n = fn (string $c, int $casas) => isset($f[$c]) && $f[$c] !== null && $f[$c] !== '' ? num($f[$c], $casas) : '';
    $dec = fn (string $c, string $rotulo, int $casas = 2) => campo($c, $rotulo, $n($c, $casas), 'text', 'inputmode="decimal"');
    ?>
<div class="cabecalho"><div><h1><?= $ver ? 'Fatura de ' . mes_br($ver['referencia']) : 'Nova fatura' ?></h1>
  <?php if ($ver): $ucDaFatura = array_column($unidades, null, 'id')[$ver['unidade_id']] ?? $ver; ?>
    <p><strong><?= e(nome_uc($ver)) ?></strong>, <?= e($ver['distribuidora']) ?>.<br><?= e(endereco_uc($ver)) ?><br>
      <?php if ($ver['tipo_uc'] === 'geradora'): ?>Geradora<?= !empty($ucDaFatura['usina']) ? ' da usina ' . e($ucDaFatura['usina']) : ', sem usina vinculada' ?>.
      <?php else: ?>Beneficiária<?= !empty($ucDaFatura['uc_geradora']) ? ', recebe créditos de ' . e(nome_uc(['numero_uc' => $ucDaFatura['uc_geradora'], 'apelido' => $ucDaFatura['apelido_geradora']])) : ', sem geradora vinculada' ?>.<?php endif; ?>
      <a href="/painel/unidades.php?editar=<?= (int) $ver['unidade_id'] ?>">Editar a unidade e o vínculo</a></p>
  <?php endif; ?></div>
  <?php if ($ver):
      $versoes   = Faturas::versoes($pdo, (int) $ver['unidade_id'], $ver['referencia']);
      $cancelada = $ver['situacao_registro'] === 'cancelada';
      $vigenteId = current(array_filter($versoes, fn ($v) => $v['situacao_registro'] === 'vigente'))['id'] ?? null; ?>
  <?php endif; ?>
  <?php if ($ver && $ver['arquivo_pdf']): ?><div class="form-acoes" style="margin:0">
    <a class="botao botao-claro" href="/painel/fatura-arquivo.php?id=<?= (int) $ver['id'] ?>">Abrir o PDF</a>
    <a class="botao botao-claro" href="/painel/fatura-arquivo.php?id=<?= (int) $ver['id'] ?>&amp;tipo=json">Baixar os dados lidos (JSON)</a>
  </div><?php endif; ?></div>
<?php if ($ver && $cancelada): ?>
  <p class="aviso aviso-erro"><strong>Fatura cancelada</strong> em <?= data_br($ver['cancelada_em']) ?>: <?= e($ver['motivo_cancelamento'] ?: 'substituída por nova versão') ?>.
    Fica guardada só como histórico e não entra nos totais nem nos créditos.
    <?php if ($vigenteId): ?><a href="/painel/faturas.php?ver=<?= (int) $vigenteId ?>">Ver a fatura que vale</a>.<?php endif; ?></p>
<?php endif; ?>
<?php if ($ver && count($versoes) > 1): ?>
<div class="bloco rolagem" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.75rem">Versões desta fatura (<?= mes_br($ver['referencia']) ?>)</h3>
  <table>
    <thead><tr><th>Situação</th><th>Gravada em</th><th class="n">Valor</th><th class="n">Consumo faturado (kWh)</th><th class="n">Saldo (kWh)</th><th>Motivo do cancelamento</th><th></th></tr></thead>
    <tbody><?php foreach ($versoes as $v): ?>
      <tr<?= (int) $v['id'] === (int) $ver['id'] ? ' class="atual"' : '' ?>>
        <td><span class="etiqueta etiqueta-<?= $v['situacao_registro'] === 'vigente' ? 'paga' : 'bloqueado' ?>"><?= $v['situacao_registro'] === 'vigente' ? 'Vale' : 'Cancelada' ?></span></td>
        <td><?= data_br($v['criado_em']) ?></td>
        <td class="n"><?= brl($v['valor_total']) ?></td>
        <td class="n"><?= num($v['consumo_faturado_kwh']) ?></td>
        <td class="n"><?= num($v['saldo_creditos_kwh'], 1) ?></td>
        <td><?= e($v['motivo_cancelamento'] ?? '') ?></td>
        <td><div class="acoes">
          <?php if ((int) $v['id'] !== (int) $ver['id']): ?><a class="botao botao-claro botao-p" href="/painel/faturas.php?ver=<?= (int) $v['id'] ?>">Abrir</a><?php endif; ?>
          <?php if ($v['arquivo_pdf']): ?><a class="botao botao-claro botao-p" href="/painel/fatura-arquivo.php?id=<?= (int) $v['id'] ?>">PDF</a><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
</div>
<?php endif; ?>
<form method="post" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
  <div class="grade">
    <?php if ($ver): ?>
      <label class="campo"><span>Unidade consumidora</span><input value="<?= e(nome_uc($ver)) ?>" disabled></label>
    <?php else: ?>
      <?= selecao('unidade_id', 'Unidade consumidora', opcoes_uc($unidades), $f['unidade_id']) ?>
    <?php endif; ?>
    <?= campo('referencia', 'Mês de referência', $f['referencia'] ? substr($f['referencia'], 0, 7) : '', 'month', 'required') ?>
    <?= $dec('valor_total', 'Total a pagar (R$)') ?>
    <?= campo('vencimento', 'Vencimento', $f['vencimento'] ?? '', 'date') ?>
    <?= selecao('situacao_pagamento', 'Pagamento', ['aberta' => 'Em aberto', 'paga' => 'Paga'], $f['situacao_pagamento']) ?>
    <?= campo('data_emissao', 'Data de emissão', $f['data_emissao'] ?? '', 'date') ?>
    <?= campo('numero_nf', 'Número da nota fiscal', $f['numero_nf'] ?? '') ?>
    <?= campo('serie', 'Série', $f['serie'] ?? '') ?>
    <?= campo('chave_acesso', 'Chave de acesso', $f['chave_acesso'] ?? '') ?>
    <?= campo('bandeira', 'Bandeira tarifária', $f['bandeira'] ?? '') ?>
  </div>

  <fieldset><legend>Leitura</legend><div class="grade">
    <?= campo('leitura_anterior', 'Leitura anterior', $f['leitura_anterior'] ?? '', 'date') ?>
    <?= campo('leitura_atual', 'Leitura atual', $f['leitura_atual'] ?? '', 'date') ?>
    <?= campo('dias_faturados', 'Número de dias', $f['dias_faturados'] ?? '', 'number', 'min="0" max="99"') ?>
    <?= campo('proxima_leitura', 'Próxima leitura', $f['proxima_leitura'] ?? '', 'date') ?>
  </div></fieldset>

  <fieldset><legend>Consumo e créditos (kWh)</legend><div class="grade">
    <?= $dec('consumo_medido_kwh', 'Consumo medido', 1) ?>
    <?= $dec('consumo_faturado_kwh', 'Consumo faturado', 1) ?>
    <?= $dec('energia_injetada_kwh', 'Energia injetada no mês', 1) ?>
    <?= $dec('creditos_utilizados_kwh', 'Créditos utilizados', 1) ?>
    <?= $dec('saldo_creditos_kwh', 'Saldo para o próximo ciclo', 1) ?>
  </div></fieldset>

  <fieldset><legend>Grupo A: postos horários e demanda</legend><div class="grade">
    <?= $dec('consumo_ponta_kwh', 'Consumo na ponta (kWh)') ?>
    <?= $dec('consumo_fora_ponta_kwh', 'Consumo fora de ponta (kWh)') ?>
    <?= $dec('consumo_reservado_kwh', 'Consumo reservado (kWh)') ?>
    <?= $dec('demanda_medida_kw', 'Demanda faturada (kW)') ?>
    <?= $dec('demanda_contratada_kw', 'Demanda contratada (kW)') ?>
  </div></fieldset>

  <fieldset><legend>Tributos (R$)</legend><div class="grade">
    <?= $dec('valor_pis', 'PIS') ?><?= $dec('valor_cofins', 'COFINS') ?><?= $dec('valor_icms', 'ICMS') ?>
  </div></fieldset>

  <fieldset><legend>Itens da fatura</legend>
    <div class="rolagem"><table class="itens">
      <thead><tr><th>Descrição</th><th>Unid.</th><th class="n">Quantidade</th><th class="n">Preço unitário</th><th class="n">Valor (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($itens as $i): ?>
        <tr>
          <td><input name="item_descricao[]" value="<?= e($i['descricao'] ?? '') ?>" aria-label="Descrição"></td>
          <td><input name="item_unidade[]" value="<?= e($i['unidade'] ?? '') ?>" size="5" aria-label="Unidade"></td>
          <td><input name="item_quantidade[]" value="<?= isset($i['quantidade']) ? num($i['quantidade'], 2) : '' ?>" inputmode="decimal" aria-label="Quantidade"></td>
          <td><input name="item_preco[]" value="<?= isset($i['preco_unitario']) ? num($i['preco_unitario'], 8) : '' ?>" inputmode="decimal" aria-label="Preço unitário"></td>
          <td><input name="item_valor[]" value="<?= isset($i['valor']) ? num($i['valor'], 2) : '' ?>" inputmode="decimal" aria-label="Valor"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </fieldset>

  <fieldset><legend>Observações</legend>
    <label class="campo"><span>Informações importantes da fatura</span><textarea name="observacoes"><?= e($f['observacoes'] ?? '') ?></textarea></label>
  </fieldset>

  <div class="form-acoes">
    <?php if ($ver && $cancelada): ?>
      <button class="botao" name="acao" value="reativar" formnovalidate onclick="return confirm('Tornar esta versão a fatura que vale? A atual será marcada como cancelada.')">Tornar esta a fatura que vale</button>
    <?php else: ?>
      <button class="botao">Salvar fatura</button>
    <?php endif; ?>
    <a class="botao botao-claro" href="/painel/faturas.php">Voltar</a>
    <?php if ($ver): ?><button class="botao botao-perigo" name="acao" value="excluir" formnovalidate onclick="return confirm('Excluir esta fatura?')">Excluir</button><?php endif; ?>
  </div>
</form>
<?php else:
    $faturas = faturas_visiveis($filtro, null, true);
    // Agrupa por unidade, com os meses em ordem (no mesmo mês, a que vale primeiro)
    $grupos = [];
    foreach ($faturas as $f) {
        $grupos[$f['unidade_id']][] = $f;
    }
    foreach ($grupos as &$lista) {
        usort($lista, fn ($a, $b) => [$a['referencia'], $b['situacao_registro'] === 'vigente', $b['id']]
            <=> [$b['referencia'], $a['situacao_registro'] === 'vigente', $a['id']]);
    }
    unset($lista);
    uasort($grupos, fn ($a, $b) => [$b[0]['tipo_uc'], $a[0]['numero_uc']] <=> [$a[0]['tipo_uc'], $b[0]['numero_uc']]);
    $somar = ['valor_total', 'consumo_medido_kwh', 'consumo_faturado_kwh', 'energia_injetada_kwh', 'creditos_utilizados_kwh'];
    $geral = array_fill_keys($somar, 0.0) + ['saldo' => 0.0, 'faturas' => 0];
    ?>
<div class="cabecalho">
  <div><h1>Faturas</h1><p>Faturas de cada unidade consumidora, mês a mês, com o acumulado da unidade e o total geral.</p></div>
  <div class="form-acoes" style="margin:0">
    <a class="botao" href="/painel/anexar.php">Anexar fatura em PDF</a>
    <a class="botao botao-claro" href="/painel/faturas.php?nova=1<?= $filtro ? '&unidade=' . $filtro : '' ?>">Digitar fatura</a>
  </div>
</div>
<?php if ($filtro): ?><p style="margin-bottom:1rem"><a href="/painel/faturas.php">Ver as faturas de todas as unidades</a></p><?php endif; ?>
<?php if (!$faturas): ?>
  <div class="bloco"><p class="vazio">Nenhuma fatura cadastrada<?= $filtro ? ' para esta unidade' : '' ?>.</p></div>
<?php else: ?>
<div class="bloco rolagem">
  <table class="agrupada">
    <thead><tr><th>Referência</th><th>Vencimento</th><th>Pagamento</th><th class="n">Valor</th><th class="n">Consumo medido (kWh)</th><th class="n">Consumo faturado (kWh)</th><th class="n">Injetado (kWh)</th><th class="n">Créditos usados (kWh)</th><th class="n">Saldo (kWh)</th></tr></thead>
    <?php foreach ($grupos as $lista): $p = $lista[0]; $sub = array_fill_keys($somar, 0.0); $saldo = null; ?>
    <tbody>
      <tr class="grupo"><th colspan="9" scope="rowgroup">
        <a class="grupo-link" href="/painel/unidades.php?editar=<?= (int) $p['unidade_id'] ?>">Editar unidade</a>
        <span class="grupo-numero">UC <?= e($p['numero_uc']) ?></span>
        <?= etiqueta_papel($p['tipo_uc']) ?>
        <span class="grupo-apelido"><?= e(apelido_uc($p) ?? 'Sem nome: defina um apelido em Editar unidade') ?></span>
        <span class="grupo-nota"><?= e(endereco_uc($p)) ?><?php if (eh_admin()): ?> | <?= e($p['empresa']) ?><?php endif; ?></span></th></tr>
      <?php $validas = array_filter($lista, fn ($x) => $x['situacao_registro'] === 'vigente');
      foreach ($lista as $f):
          $vale = $f['situacao_registro'] === 'vigente';
          if ($vale) {
              foreach ($somar as $c) { $sub[$c] += (float) $f[$c]; }
              $saldo = $f['saldo_creditos_kwh'] ?? $saldo;
          } ?>
      <tr<?= $vale ? '' : ' class="cancelada" title="Versão cancelada: guardada como histórico, fora dos totais"' ?>>
        <td><a href="/painel/faturas.php?ver=<?= (int) $f['id'] ?>"><?= mes_br($f['referencia']) ?></a><?= $vale ? '' : ' <span class="etiqueta etiqueta-bloqueado">Cancelada</span>' ?></td>
        <td><?= data_br($f['vencimento']) ?></td>
        <td><span class="etiqueta etiqueta-<?= e($f['situacao_pagamento']) ?>"><?= $f['situacao_pagamento'] === 'paga' ? 'Paga' : 'Em aberto' ?></span></td>
        <td class="n"><?= brl($f['valor_total']) ?></td>
        <td class="n"><?= num($f['consumo_medido_kwh']) ?></td>
        <td class="n"><?= num($f['consumo_faturado_kwh']) ?></td>
        <td class="n"><?= num($f['energia_injetada_kwh']) ?></td>
        <td class="n"><?= num($f['creditos_utilizados_kwh']) ?></td>
        <td class="n"><?= num($f['saldo_creditos_kwh'], 1) ?></td>
      </tr>
      <?php endforeach;
          foreach ($somar as $c) { $geral[$c] += $sub[$c]; }
          $geral['saldo'] += (float) $saldo; $geral['faturas'] += count($validas); ?>
      <tr class="subtotal">
        <td colspan="3">Acumulado da UC (<?= count($validas) ?> fatura<?= count($validas) === 1 ? '' : 's' ?><?= count($lista) > count($validas) ? ', sem as canceladas' : '' ?>)</td>
        <td class="n"><?= brl($sub['valor_total']) ?></td>
        <td class="n"><?= num($sub['consumo_medido_kwh']) ?></td>
        <td class="n"><?= num($sub['consumo_faturado_kwh']) ?></td>
        <td class="n"><?= num($sub['energia_injetada_kwh']) ?></td>
        <td class="n"><?= num($sub['creditos_utilizados_kwh']) ?></td>
        <td class="n"><?= $saldo !== null ? num($saldo, 1) : '–' ?></td>
      </tr>
    </tbody>
    <?php endforeach; ?>
    <tfoot><tr>
      <td colspan="3">Total de todas as unidades (<?= $geral['faturas'] ?> fatura<?= $geral['faturas'] === 1 ? '' : 's' ?>)</td>
      <td class="n"><?= brl($geral['valor_total']) ?></td>
      <td class="n"><?= num($geral['consumo_medido_kwh']) ?></td>
      <td class="n"><?= num($geral['consumo_faturado_kwh']) ?></td>
      <td class="n"><?= num($geral['energia_injetada_kwh']) ?></td>
      <td class="n"><?= num($geral['creditos_utilizados_kwh']) ?></td>
      <td class="n"><?= num($geral['saldo'], 1) ?></td>
    </tr></tfoot>
  </table>
  <p class="nota" style="margin-top:.9rem;color:var(--tinta-2)">Faturas canceladas (substituídas por uma nova versão do mesmo mês) aparecem riscadas e não entram nos acumulados. Na coluna Saldo, o acumulado mostra o saldo mais recente da unidade (saldo não se soma entre meses); o total geral soma o saldo mais recente de cada unidade.</p>
</div>
<?php endif; ?>
<?php endif;
painel_fim();
