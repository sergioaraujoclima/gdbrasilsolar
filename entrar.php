<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/acesso.php';

if (usuario()) {
    redirecionar('/painel/');
}

$erro  = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $s = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
    $s->execute([$email]);
    $u = $s->fetch();

    if (!$u || !password_verify((string) ($_POST['senha'] ?? ''), $u['senha_hash'])) {
        sleep(1); // dificulta tentativas em série
        $erro = 'E-mail ou senha incorretos.';
    } elseif ($u['status'] === 'pendente') {
        $erro = 'Seu cadastro ainda aguarda a aprovação do gestor.';
    } elseif ($u['status'] !== 'ativo') {
        $erro = 'Este acesso está bloqueado. Fale com o gestor.';
    } else {
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int) $u['id'];
        db()->prepare('UPDATE usuarios SET ultimo_acesso = ? WHERE id = ?')->execute([agora(), $u['id']]);
        redirecionar('/painel/');
    }
}

acesso_inicio('Entrar');
?>
<form method="post" novalidate>
  <h2>Entrar</h2>
  <?php foreach (avisos() as $a): ?><p class="aviso aviso-<?= e($a['tipo']) ?>"><?= e($a['msg']) ?></p><?php endforeach; ?>
  <?php if ($erro): ?><p class="aviso aviso-erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
  <?= csrf_campo() ?>
  <?= campo('email', 'E-mail', $email, 'email', 'required autocomplete="username" autofocus') ?>
  <?= campo('senha', 'Senha', '', 'password', 'required autocomplete="current-password"') ?>
  <button class="botao">Entrar</button>
  <p class="troca">Ainda não tem acesso? <a href="/cadastro.php">Criar conta</a></p>
</form>
<?php acesso_fim();
