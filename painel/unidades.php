<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';

exigir_login();
$pdo = db();

const CAMPOS_UC = ['numero_uc', 'distribuidora', 'titular_nome', 'titular_documento', 'classificacao',
                   'tipo_fornecimento', 'regra_gd', 'endereco', 'cidade', 'uf', 'cep', 'medidor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && !buscar_unidade($id)) {
        http_response_code(404);
        exit('Unidade não encontrada.');
    }
    if (($_POST['acao'] ?? '') === 'excluir') {
        $pdo->prepare('DELETE FROM unidades_consumidoras WHERE id = ?')->execute([$id]);
        avisar('Unidade consumidora excluída, com as faturas dela.');
        redirecionar('/painel/unidades.php');
    }

    $empresa = escopo() ?? (int) ($_POST['empresa_id'] ?? 0);
    $tipo    = ($_POST['tipo'] ?? '') === 'beneficiaria' ? 'beneficiaria' : 'geradora';
    $usina   = (int) ($_POST['usina_id'] ?? 0);
    $gerad   = (int) ($_POST['uc_geradora_id'] ?? 0);
    // só aceita vínculos com usinas e unidades que o usuário pode ver
    $usina = ($tipo === 'geradora' && $usina && buscar_usina($usina)) ? $usina : null;
    $gerad = ($tipo === 'beneficiaria' && $gerad && $gerad !== $id && buscar_unidade($gerad)) ? $gerad : null;

    $v = ['empresa_id' => $empresa, 'tipo' => $tipo, 'usina_id' => $usina, 'uc_geradora_id' => $gerad,
          'demanda_contratada_kw' => decimal($_POST['demanda_contratada_kw'] ?? ''), 'atualizado_em' => agora()];
    foreach (CAMPOS_UC as $c) {
        $v[$c] = texto($_POST[$c] ?? '');
    }
    if (!$v['numero_uc'] || !$v['distribuidora'] || !$empresa) {
        avisar('Informe o número da UC, a distribuidora e a empresa.', 'erro');
        redirecionar('/painel/unidades.php?' . ($id ? "editar=$id" : 'nova=1'));
    }

    try {
        if ($id) {
            $pdo->prepare('UPDATE unidades_consumidoras SET ' . implode(' = ?, ', array_keys($v)) . ' = ? WHERE id = ?')
                ->execute([...array_values($v), $id]);
        } else {
            $v['criado_em'] = agora();
            $pdo->prepare('INSERT INTO unidades_consumidoras (' . implode(', ', array_keys($v)) . ') VALUES ('
                . rtrim(str_repeat('?, ', count($v)), ', ') . ')')->execute(array_values($v));
        }
        avisar('Unidade consumidora salva.');
    } catch (PDOException $e) {
        avisar('Já existe uma unidade com este número nesta distribuidora.', 'erro');
    }
    redirecionar('/painel/unidades.php');
}

$editar   = isset($_GET['editar']) ? buscar_unidade((int) $_GET['editar']) : null;
$form     = $editar || isset($_GET['nova']);
$unidades = unidades_visiveis();

painel_inicio('Unidades consumidoras', 'unidades');

if ($form):
    $c = $editar ?? array_fill_keys([...CAMPOS_UC, 'usina_id', 'uc_geradora_id', 'demanda_contratada_kw'], '')
        + ['id' => 0, 'empresa_id' => escopo() ?? 1, 'tipo' => 'geradora'];
    $geradoras = array_filter($unidades, fn ($g) => $g['tipo'] === 'geradora' && (int) $g['id'] !== (int) $c['id']);
    ?>
<div class="cabecalho"><div><h1><?= $editar ? 'Editar unidade consumidora' : 'Nova unidade consumidora' ?></h1>
  <p>Os dados estão no cabeçalho da fatura da distribuidora.</p></div></div>
<form method="post" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
  <div class="grade">
    <?= campo('numero_uc', 'Número da unidade consumidora', $c['numero_uc'], 'text', 'required') ?>
    <?= campo('distribuidora', 'Distribuidora', $c['distribuidora'], 'text', 'required') ?>
    <?php if (eh_admin()): ?><?= selecao('empresa_id', 'Empresa', array_column(empresas_disponiveis(), 'nome', 'id'), $c['empresa_id']) ?><?php endif; ?>
    <?= selecao('tipo', 'Papel na compensação', ['geradora' => 'Geradora (tem usina)', 'beneficiaria' => 'Beneficiária (recebe créditos)'], $c['tipo']) ?>
    <?= selecao('usina_id', 'Usina instalada (se geradora)', array_column(usinas_visiveis(false), 'nome', 'id'), $c['usina_id'], true) ?>
    <?= selecao('uc_geradora_id', 'Recebe créditos da UC (se beneficiária)', array_column($geradoras, 'numero_uc', 'id'), $c['uc_geradora_id'], true) ?>
    <?= campo('titular_nome', 'Titular', $c['titular_nome']) ?>
    <?= campo('titular_documento', 'CPF ou CNPJ do titular', $c['titular_documento']) ?>
    <?= campo('classificacao', 'Classificação', $c['classificacao'], 'text', 'placeholder="B2 Rural, A4 Verde..."') ?>
    <?= campo('tipo_fornecimento', 'Tipo de fornecimento', $c['tipo_fornecimento']) ?>
    <?= selecao('regra_gd', 'Regra de compensação', ['GD I' => 'GD I', 'GD II' => 'GD II', 'GD III' => 'GD III'], $c['regra_gd'], true) ?>
    <?= campo('medidor', 'Número do medidor', $c['medidor']) ?>
    <?= campo('demanda_contratada_kw', 'Demanda contratada (kW)', $c['demanda_contratada_kw'] !== '' && $c['demanda_contratada_kw'] !== null ? num($c['demanda_contratada_kw'], 2) : '', 'text', 'inputmode="decimal"') ?>
    <?= campo('endereco', 'Endereço', $c['endereco']) ?>
    <?= campo('cidade', 'Cidade', $c['cidade']) ?>
    <?= campo('uf', 'UF', $c['uf'], 'text', 'maxlength="2"') ?>
    <?= campo('cep', 'CEP', $c['cep']) ?>
  </div>
  <div class="form-acoes">
    <button class="botao">Salvar unidade</button>
    <a class="botao botao-claro" href="/painel/unidades.php">Cancelar</a>
    <?php if ($editar): ?><button class="botao botao-perigo" name="acao" value="excluir" formnovalidate onclick="return confirm('Excluir esta unidade e todas as faturas dela?')">Excluir</button><?php endif; ?>
  </div>
</form>
<?php else: ?>
<div class="cabecalho">
  <div><h1>Unidades consumidoras</h1><p>As unidades geradoras e as que recebem os créditos.</p></div>
  <a class="botao" href="/painel/unidades.php?nova=1">Nova unidade</a>
</div>
<div class="bloco rolagem">
<?php if (!$unidades): ?>
  <p class="vazio">Nenhuma unidade consumidora cadastrada.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Número da UC</th><th>Papel</th><th>Vínculo</th><th>Distribuidora</th><th>Classificação</th><th>Titular</th><th>Cidade</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($unidades as $c): ?>
      <tr>
        <td><?= e($c['numero_uc']) ?></td>
        <td><span class="etiqueta etiqueta-<?= e($c['tipo']) ?>"><?= $c['tipo'] === 'geradora' ? 'Geradora' : 'Beneficiária' ?></span></td>
        <td><?= $c['tipo'] === 'geradora' ? ($c['usina'] ? 'Usina ' . e($c['usina']) : 'Sem usina') : ($c['uc_geradora'] ? 'Recebe da UC ' . e($c['uc_geradora']) : 'Sem geradora') ?></td>
        <td><?= e($c['distribuidora']) ?></td><td><?= e($c['classificacao']) ?></td><td><?= e($c['titular_nome']) ?></td><td><?= e($c['cidade']) ?></td>
        <td><div class="acoes">
          <a class="botao botao-claro botao-p" href="/painel/faturas.php?unidade=<?= (int) $c['id'] ?>">Faturas</a>
          <a class="botao botao-claro botao-p" href="/painel/unidades.php?editar=<?= (int) $c['id'] ?>">Editar</a>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php endif;
painel_fim();
