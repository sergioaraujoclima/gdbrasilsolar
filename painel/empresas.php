<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';

$admin = exigir_admin();
$pdo   = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id   = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? 'salvar';

    if ($acao === 'desativar' || $acao === 'reativar') {
        if ($acao === 'desativar' && $id === (int) $admin['empresa_id']) {
            avisar('Você está ligado a esta empresa. Mude a sua empresa em Usuários antes de desativá-la.', 'erro');
        } else {
            $pdo->prepare('UPDATE empresas SET ativo = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$acao === 'reativar' ? 1 : 0, agora(), $id]);
            avisar($acao === 'reativar' ? 'Empresa reativada.' : 'Empresa desativada. Os dados dela foram mantidos.');
        }
        redirecionar('/painel/empresas.php');
    }

    if ($acao === 'excluir') {
        $usos = 0;
        foreach (['usinas', 'unidades_consumidoras', 'usuarios', 'integracoes'] as $tabela) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM $tabela WHERE empresa_id = ?");
            $s->execute([$id]);
            $usos += (int) $s->fetchColumn();
        }
        if ($usos) {
            avisar('Esta empresa tem usinas, unidades ou usuários. Mova-os para outra empresa ou apenas desative-a.', 'erro');
        } else {
            $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$id]);
            avisar('Empresa excluída.');
        }
        redirecionar('/painel/empresas.php');
    }

    $nome = trim((string) ($_POST['nome'] ?? ''));
    $doc  = preg_replace('/\D/', '', (string) ($_POST['documento'] ?? '')) ?: null;
    if ($nome === '') {
        avisar('Informe o nome da empresa.', 'erro');
        redirecionar('/painel/empresas.php?' . ($id ? "editar=$id" : 'nova=1'));
    }
    try {
        if ($id) {
            $pdo->prepare('UPDATE empresas SET nome = ?, documento = ?, atualizado_em = ? WHERE id = ?')->execute([$nome, $doc, agora(), $id]);
            avisar('Empresa atualizada.');
        } else {
            $pdo->prepare('INSERT INTO empresas (nome, documento, criado_em, atualizado_em) VALUES (?, ?, ?, ?)')->execute([$nome, $doc, agora(), agora()]);
            avisar('Empresa cadastrada.');
        }
    } catch (PDOException $e) {
        avisar('Já existe uma empresa com este CNPJ ou CPF.', 'erro');
    }
    redirecionar('/painel/empresas.php');
}

$empresas = $pdo->query(
    'SELECT e.*,
            (SELECT COUNT(*) FROM usinas u WHERE u.empresa_id = e.id) AS usinas,
            (SELECT COUNT(*) FROM unidades_consumidoras c WHERE c.empresa_id = e.id) AS unidades,
            (SELECT COUNT(*) FROM usuarios s WHERE s.empresa_id = e.id) AS usuarios
     FROM empresas e ORDER BY e.nome'
)->fetchAll();
$editar = null;
foreach ($empresas as $e) {
    if (isset($_GET['editar']) && (int) $e['id'] === (int) $_GET['editar']) {
        $editar = $e;
    }
}
$form = $editar || isset($_GET['nova']);

painel_inicio('Empresas', 'empresas');

if ($form):
    $e = $editar ?? ['id' => 0, 'nome' => '', 'documento' => '']; ?>
<div class="cabecalho"><div><h1><?= $editar ? 'Editar empresa' : 'Nova empresa' ?></h1></div></div>
<form method="post" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
  <div class="grade">
    <?= campo('nome', 'Nome da empresa ou do proprietário', $e['nome'], 'text', 'required maxlength="150"') ?>
    <?= campo('documento', 'CNPJ ou CPF (opcional)', $e['documento'], 'text', 'maxlength="20" inputmode="numeric"') ?>
  </div>
  <div class="form-acoes">
    <button class="botao">Salvar empresa</button>
    <a class="botao botao-claro" href="/painel/empresas.php">Cancelar</a>
    <?php if ($editar): ?><button class="botao botao-perigo" name="acao" value="excluir" formnovalidate onclick="return confirm('Excluir esta empresa?')">Excluir</button><?php endif; ?>
  </div>
</form>
<?php else: ?>
<div class="cabecalho">
  <div><h1>Empresas</h1><p>Cada empresa tem as próprias usinas, unidades consumidoras e usuários. Você está ligado a
    <?php foreach ($empresas as $e) { if ((int) $e['id'] === (int) $admin['empresa_id']) { echo '<strong>' . e($e['nome']) . '</strong>'; } } ?>;
    para trocar, use <a href="/painel/usuarios.php">Usuários</a>.</p></div>
  <a class="botao" href="/painel/empresas.php?nova=1">Nova empresa</a>
</div>
<div class="bloco rolagem">
  <table>
    <thead><tr><th>Empresa</th><th>CNPJ ou CPF</th><th>Situação</th><th class="n">Usinas</th><th class="n">Unidades</th><th class="n">Usuários</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($empresas as $e): ?>
      <tr>
        <td><?= e($e['nome']) ?></td><td><?= e($e['documento'] ?: '–') ?></td>
        <td><span class="etiqueta<?= $e['ativo'] ? '' : ' etiqueta-inativa' ?>"><?= $e['ativo'] ? 'Ativa' : 'Desativada' ?></span></td>
        <td class="n"><?= (int) $e['usinas'] ?></td><td class="n"><?= (int) $e['unidades'] ?></td><td class="n"><?= (int) $e['usuarios'] ?></td>
        <td><div class="acoes">
          <a class="botao botao-claro botao-p" href="/painel/empresas.php?editar=<?= (int) $e['id'] ?>">Editar</a>
          <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <?php if ($e['ativo']): ?><button class="botao botao-perigo botao-p" name="acao" value="desativar">Desativar</button>
            <?php else: ?><button class="botao botao-claro botao-p" name="acao" value="reativar">Reativar</button><?php endif; ?>
          </form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif;
painel_fim();
