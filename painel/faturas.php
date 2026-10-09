<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/Faturas.php';

exigir_login();
$pdo = db();

/** Fatura por id, somente se a unidade dela for visível ao usuário. */
function buscar_fatura(int $id): ?array
{
    foreach (faturas_visiveis() as $f) {
        if ((int) $f['id'] === $id) {
            return $f;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id      = (int) ($_POST['id'] ?? 0);
    $fatura  = $id ? buscar_fatura($id) : null;
    $unidade = buscar_unidade($fatura ? (int) $fatura['unidade_id'] : (int) ($_POST['unidade_id'] ?? 0));
    if (($id && !$fatura) || !$unidade) {
        http_response_code(404);
        exit('Fatura ou unidade não encontrada.');
    }

    if (($_POST['acao'] ?? '') === 'excluir') {
        $pdo->prepare('DELETE FROM faturas WHERE id = ?')->execute([$id]);
        avisar('Fatura excluída.');
        redirecionar('/painel/faturas.php');
    }

    $referencia = referencia_iso($_POST['referencia'] ?? '');
    if (!$referencia) {
        avisar('Informe o mês de referência da fatura.', 'erro');
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
        $id = Faturas::salvar($pdo, (int) $unidade['id'], $referencia, $d, $itens, $id ?: null);
        avisar('Fatura salva.');
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
  <?php if ($ver): ?><p>UC <?= e($ver['numero_uc']) ?>, <?= e($ver['distribuidora']) ?>.</p><?php endif; ?></div></div>
<form method="post" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
  <div class="grade">
    <?php if ($ver): ?>
      <label class="campo"><span>Unidade consumidora</span><input value="<?= e($ver['numero_uc']) ?>" disabled></label>
    <?php else: ?>
      <?= selecao('unidade_id', 'Unidade consumidora', array_column($unidades, 'numero_uc', 'id'), $f['unidade_id']) ?>
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
    <button class="botao">Salvar fatura</button>
    <a class="botao botao-claro" href="/painel/faturas.php">Voltar</a>
    <?php if ($ver): ?><button class="botao botao-perigo" name="acao" value="excluir" formnovalidate onclick="return confirm('Excluir esta fatura?')">Excluir</button><?php endif; ?>
  </div>
</form>
<?php else:
    $faturas = faturas_visiveis($filtro); ?>
<div class="cabecalho">
  <div><h1>Faturas</h1><p>Faturas da distribuidora, ligadas às unidades geradoras e beneficiárias.</p></div>
  <a class="botao" href="/painel/faturas.php?nova=1<?= $filtro ? '&unidade=' . $filtro : '' ?>">Nova fatura</a>
</div>
<div class="bloco rolagem">
<?php if (!$faturas): ?>
  <p class="vazio">Nenhuma fatura cadastrada<?= $filtro ? ' para esta unidade' : '' ?>.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Referência</th><th>UC</th><th>Papel</th><?php if (eh_admin()): ?><th>Empresa</th><?php endif; ?><th>Vencimento</th><th>Pagamento</th><th class="n">Valor</th><th class="n">Consumo faturado (kWh)</th><th class="n">Injetado (kWh)</th><th class="n">Créditos usados (kWh)</th><th class="n">Saldo (kWh)</th></tr></thead>
    <tbody>
    <?php foreach ($faturas as $f): ?>
      <tr>
        <td><a href="/painel/faturas.php?ver=<?= (int) $f['id'] ?>"><?= mes_br($f['referencia']) ?></a></td>
        <td><?= e($f['numero_uc']) ?></td>
        <td><span class="etiqueta etiqueta-<?= e($f['tipo_uc']) ?>"><?= $f['tipo_uc'] === 'geradora' ? 'Geradora' : 'Beneficiária' ?></span></td>
        <?php if (eh_admin()): ?><td><?= e($f['empresa']) ?></td><?php endif; ?>
        <td><?= data_br($f['vencimento']) ?></td>
        <td><span class="etiqueta etiqueta-<?= e($f['situacao_pagamento']) ?>"><?= $f['situacao_pagamento'] === 'paga' ? 'Paga' : 'Em aberto' ?></span></td>
        <td class="n"><?= brl($f['valor_total']) ?></td>
        <td class="n"><?= num($f['consumo_faturado_kwh']) ?></td>
        <td class="n"><?= num($f['energia_injetada_kwh']) ?></td>
        <td class="n"><?= num($f['creditos_utilizados_kwh']) ?></td>
        <td class="n"><?= num($f['saldo_creditos_kwh'], 1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php endif;
painel_fim();
