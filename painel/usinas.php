<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';

exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id   = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? 'salvar';

    if ($id && !buscar_usina($id)) {
        http_response_code(404);
        exit('Usina não encontrada.');
    }

    if ($acao === 'desativar' || $acao === 'reativar') {
        $pdo->prepare('UPDATE usinas SET ativo = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$acao === 'reativar' ? 1 : 0, agora(), $id]);
        avisar($acao === 'reativar' ? 'Usina reativada.' : 'Usina desativada. O histórico de geração foi mantido.');
        redirecionar('/painel/usinas.php');
    }

    $empresa = escopo() ?? (int) ($_POST['empresa_id'] ?? 0);
    $nome    = trim((string) ($_POST['nome'] ?? ''));
    if ($nome === '' || !$empresa) {
        avisar('Informe o nome da usina e a empresa.', 'erro');
        redirecionar('/painel/usinas.php?' . ($id ? "editar=$id" : 'nova=1'));
    }

    $dados = [
        $empresa,
        $nome,
        decimal($_POST['potencia_pico_kwp'] ?? ''),
        texto($_POST['cidade'] ?? ''),
        texto($_POST['pais'] ?? ''),
        data_iso($_POST['data_instalacao'] ?? ''),
        ($_POST['situacao'] ?? '') === 'parada' ? 'parada' : 'ativa',
        texto($_POST['observacoes'] ?? ''),
        agora(),
    ];
    if ($id) {
        $pdo->prepare(
            'UPDATE usinas SET empresa_id = ?, nome = ?, potencia_pico_kwp = ?, cidade = ?, pais = ?,
                    data_instalacao = ?, situacao = ?, observacoes = ?, atualizado_em = ? WHERE id = ?'
        )->execute([...$dados, $id]);
        avisar('Usina atualizada.');
    } else {
        // Usina sem integração: os dados de geração entram depois, por integração ou importação.
        $pdo->prepare(
            "INSERT INTO usinas (empresa_id, nome, potencia_pico_kwp, cidade, pais, data_instalacao, situacao,
                                 observacoes, atualizado_em, fabricante, id_externo, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([...$dados, texto($_POST['fabricante'] ?? '') ?? 'manual', 'manual-' . bin2hex(random_bytes(6)), agora()]);
        avisar('Usina cadastrada.');
    }
    redirecionar('/painel/usinas.php');
}

$editar = isset($_GET['editar']) ? buscar_usina((int) $_GET['editar']) : null;
$form   = $editar || isset($_GET['nova']);
$totais = totais_geracao();

painel_inicio('Usinas', 'usinas');

if ($form):
    $u = $editar ?? ['id' => 0, 'empresa_id' => escopo() ?? 1, 'nome' => '', 'potencia_pico_kwp' => '', 'cidade' => '', 'pais' => 'Brasil',
                     'data_instalacao' => '', 'situacao' => 'ativa', 'observacoes' => '', 'fabricante' => 'manual', 'id_externo' => ''];
    ?>
<div class="cabecalho"><div><h1><?= $editar ? 'Editar usina' : 'Nova usina' ?></h1>
  <?php if ($editar && $u['fabricante'] !== 'manual'): ?><p>Integrada à <?= e(ucfirst($u['fabricante'])) ?> (código <?= e($u['id_externo']) ?>). A geração é lida automaticamente.</p><?php endif; ?>
</div></div>
<form method="post" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
  <div class="grade">
    <?= campo('nome', 'Nome da usina', $u['nome'], 'text', 'required maxlength="150"') ?>
    <?php if (eh_admin()): ?><?= selecao('empresa_id', 'Empresa', array_column(empresas_disponiveis(), 'nome', 'id'), $u['empresa_id']) ?><?php endif; ?>
    <?php if (!$editar): ?><?= selecao('fabricante', 'Fabricante do inversor', ['manual' => 'Sem integração', 'growatt' => 'Growatt', 'outro' => 'Outro'], 'manual') ?><?php endif; ?>
    <?= campo('potencia_pico_kwp', 'Potência de pico (kWp)', $u['potencia_pico_kwp'] !== null && $u['potencia_pico_kwp'] !== '' ? num($u['potencia_pico_kwp'], 2) : '', 'text', 'inputmode="decimal"') ?>
    <?= campo('data_instalacao', 'Início da operação', $u['data_instalacao'], 'date') ?>
    <?= selecao('situacao', 'Situação', ['ativa' => 'Ativa', 'parada' => 'Parada'], $u['situacao']) ?>
    <?= campo('cidade', 'Cidade', $u['cidade']) ?>
    <?= campo('pais', 'País', $u['pais']) ?>
    <label class="campo largo"><span>Observações</span><textarea name="observacoes"><?= e($u['observacoes']) ?></textarea></label>
  </div>
  <div class="form-acoes">
    <button class="botao">Salvar usina</button>
    <a class="botao botao-claro" href="/painel/usinas.php">Cancelar</a>
  </div>
</form>
<?php else:
    $usinas = usinas_visiveis(false); ?>
<div class="cabecalho">
  <div><h1>Usinas</h1><p>Cadastro das usinas de cada empresa.</p></div>
  <a class="botao" href="/painel/usinas.php?nova=1">Nova usina</a>
</div>
<div class="bloco rolagem">
<?php if (!$usinas): ?>
  <p class="vazio">Nenhuma usina cadastrada. Use "Nova usina" para começar.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Usina</th><?php if (eh_admin()): ?><th>Empresa</th><?php endif; ?><th>Situação</th><th>Integração</th><th class="n">Potência (kWp)</th><th>Início</th><th class="n">Gerado no ano (kWh)</th><th class="n">Total (kWh)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($usinas as $u): $t = $totais[$u['id']] ?? []; ?>
      <tr>
        <td><a href="/painel/usina.php?id=<?= (int) $u['id'] ?>"><?= e($u['nome']) ?></a></td>
        <?php if (eh_admin()): ?><td><?= e($u['empresa']) ?></td><?php endif; ?>
        <td><?php if (!$u['ativo']): ?><span class="etiqueta etiqueta-inativa">Desativada</span>
            <?php else: ?><span class="etiqueta etiqueta-<?= e($u['situacao']) ?>"><?= $u['situacao'] === 'parada' ? 'Parada' : 'Ativa' ?></span><?php endif; ?></td>
        <td><?= $u['fabricante'] === 'manual' ? 'Sem integração' : e(ucfirst($u['fabricante'])) ?></td>
        <td class="n"><?= num($u['potencia_pico_kwp'], 1) ?></td>
        <td><?= data_br($u['data_instalacao']) ?></td>
        <td class="n"><?= num($t['ano'] ?? null) ?></td>
        <td class="n"><?= num($t['total'] ?? null) ?></td>
        <td><div class="acoes">
          <a class="botao botao-claro botao-p" href="/painel/usinas.php?editar=<?= (int) $u['id'] ?>">Editar</a>
          <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <?php if ($u['ativo']): ?>
              <button class="botao botao-perigo botao-p" name="acao" value="desativar" onclick="return confirm('Desativar esta usina? O histórico de geração é mantido.')">Desativar</button>
            <?php else: ?>
              <button class="botao botao-claro botao-p" name="acao" value="reativar">Reativar</button>
            <?php endif; ?>
          </form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php endif;
painel_fim();
