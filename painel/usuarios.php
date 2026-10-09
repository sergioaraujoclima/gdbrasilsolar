<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';

$admin = exigir_admin();
$pdo   = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $id   = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    $s    = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $s->execute([$id]);
    $alvo = $s->fetch();

    if (!$alvo) {
        avisar('Usuário não encontrado.', 'erro');
    } elseif ($acao === 'empresa') {
        $empresa = (int) ($_POST['empresa_id'] ?? 0);
        $existe  = $pdo->prepare('SELECT nome FROM empresas WHERE id = ? AND ativo = 1');
        $existe->execute([$empresa]);
        if ($nomeEmpresa = $existe->fetchColumn()) {
            $pdo->prepare('UPDATE usuarios SET empresa_id = ?, atualizado_em = ? WHERE id = ?')->execute([$empresa, agora(), $id]);
            avisar($alvo['nome'] . ' agora está na empresa ' . $nomeEmpresa . '.');
        } else {
            avisar('Escolha uma empresa ativa.', 'erro');
        }
    } elseif ($id === (int) $admin['id']) {
        avisar('Você não pode alterar o próprio acesso por aqui.', 'erro');
    } elseif ($acao === 'aprovar') {
        // Empresa existente escolhida, ou uma nova com o nome informado
        $empresa = (int) ($_POST['empresa_id'] ?? 0);
        if (!$empresa) {
            $nome = trim((string) ($_POST['empresa_nova'] ?? '')) ?: ($alvo['empresa_solicitada'] ?: $alvo['nome']);
            $pdo->prepare('INSERT INTO empresas (nome, criado_em, atualizado_em) VALUES (?, ?, ?)')->execute([$nome, agora(), agora()]);
            $empresa = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE usuarios SET status = 'ativo', empresa_id = ?, aprovado_por = ?, aprovado_em = ?, atualizado_em = ? WHERE id = ?")
            ->execute([$empresa, $admin['id'], agora(), agora(), $id]);
        avisar($alvo['nome'] . ' foi aprovado e já pode entrar.');
    } elseif ($acao === 'bloquear' || $acao === 'reativar') {
        $pdo->prepare('UPDATE usuarios SET status = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$acao === 'bloquear' ? 'bloqueado' : 'ativo', agora(), $id]);
        avisar($acao === 'bloquear' ? 'Acesso bloqueado.' : 'Acesso reativado.');
    } elseif ($acao === 'recusar' && $alvo['status'] === 'pendente') {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        avisar('Cadastro recusado e removido.');
    }
    redirecionar('/painel/usuarios.php');
}

$usuarios = $pdo->query(
    "SELECT u.*, e.nome AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id
     ORDER BY CASE u.status WHEN 'pendente' THEN 0 ELSE 1 END, u.criado_em DESC"
)->fetchAll();
$empresas  = $pdo->query('SELECT id, nome FROM empresas WHERE ativo = 1 ORDER BY nome')->fetchAll();
$pendentes = array_filter($usuarios, fn ($u) => $u['status'] === 'pendente');
$demais    = array_filter($usuarios, fn ($u) => $u['status'] !== 'pendente');

painel_inicio('Usuários', 'usuarios');
?>
<div class="cabecalho"><div><h1>Usuários</h1><p>Aprove quem se cadastrou pelo site e defina a empresa de cada pessoa. As empresas são cadastradas em <a href="/painel/empresas.php">Empresas</a>.</p></div></div>

<h2>Aguardando aprovação</h2>
<?php if (!$pendentes): ?>
  <div class="bloco"><p class="vazio">Nenhum cadastro aguardando aprovação.</p></div>
<?php endif; ?>
<?php foreach ($pendentes as $u): ?>
  <form method="post" class="bloco">
    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
    <dl class="dados">
      <div><dt>Nome</dt><dd><?= e($u['nome']) ?></dd></div>
      <div><dt>E-mail</dt><dd><?= e($u['email']) ?></dd></div>
      <div><dt>Empresa informada</dt><dd><?= e($u['empresa_solicitada'] ?: '–') ?></dd></div>
      <div><dt>Cadastro em</dt><dd><?= data_br($u['criado_em']) ?></dd></div>
    </dl>
    <div class="grade" style="margin-top:1rem">
      <?= selecao('empresa_id', 'Ligar a uma empresa existente', array_column($empresas, 'nome', 'id'), '', true) ?>
      <?= campo('empresa_nova', 'Ou criar uma empresa com este nome', $u['empresa_solicitada'] ?: $u['nome']) ?>
    </div>
    <div class="form-acoes">
      <button class="botao" name="acao" value="aprovar">Aprovar acesso</button>
      <button class="botao botao-perigo" name="acao" value="recusar" onclick="return confirm('Recusar e remover este cadastro?')">Recusar</button>
    </div>
  </form>
<?php endforeach; ?>

<h2>Todos os usuários</h2>
<div class="bloco rolagem">
  <table>
    <thead><tr><th>Nome</th><th>E-mail</th><th>Empresa</th><th>Perfil</th><th>Situação</th><th>Último acesso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($demais as $u): ?>
      <tr>
        <td><?= e($u['nome']) ?><?= (int) $u['id'] === (int) $admin['id'] ? ' (você)' : '' ?></td><td><?= e($u['email']) ?></td>
        <td><form method="post" class="acoes" style="justify-content:flex-start"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <select name="empresa_id" aria-label="Empresa de <?= e($u['nome']) ?>" style="font:inherit;padding:.3rem .4rem;border:1px solid #9fb2c3;border-radius:6px">
            <?php foreach ($empresas as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= (int) $emp['id'] === (int) $u['empresa_id'] ? ' selected' : '' ?>><?= e($emp['nome']) ?></option><?php endforeach; ?>
          </select><button class="botao botao-claro botao-p" name="acao" value="empresa">Mudar</button></form></td>
        <td><?= $u['papel'] === 'admin' ? 'Administrador' : 'Cliente' ?></td>
        <td><span class="etiqueta etiqueta-<?= e($u['status']) ?>"><?= $u['status'] === 'ativo' ? 'Ativo' : 'Bloqueado' ?></span></td>
        <td><?= data_br($u['ultimo_acesso']) ?></td>
        <td><?php if ((int) $u['id'] !== (int) $admin['id']): ?>
          <form method="post" class="acoes"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <?php if ($u['status'] === 'ativo'): ?><button class="botao botao-perigo botao-p" name="acao" value="bloquear">Bloquear</button>
            <?php else: ?><button class="botao botao-claro botao-p" name="acao" value="reativar">Reativar</button><?php endif; ?>
          </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php painel_fim();
