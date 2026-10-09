<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/acesso.php';

if (usuario()) {
    redirecionar('/painel/');
}

$erros = [];
$d = ['nome' => '', 'email' => '', 'empresa' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $d['nome']    = trim((string) ($_POST['nome'] ?? ''));
    $d['email']   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $d['empresa'] = trim((string) ($_POST['empresa'] ?? ''));
    $senha        = (string) ($_POST['senha'] ?? '');

    if (mb_strlen($d['nome']) < 3) {
        $erros[] = 'Informe seu nome completo.';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if (strlen($senha) < 10) {
        $erros[] = 'A senha precisa ter pelo menos 10 caracteres.';
    }
    if ($senha !== (string) ($_POST['senha2'] ?? '')) {
        $erros[] = 'As duas senhas não são iguais.';
    }

    $pdo = db();
    if (!$erros) {
        $s = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $s->execute([$d['email']]);
        if ($s->fetchColumn()) {
            $erros[] = 'Já existe uma conta com este e-mail. Use a tela de entrar.';
        }
    }

    if (!$erros) {
        // Primeiro acesso: enquanto não houver administrador, o e-mail do
        // gestor entra direto como administrador da primeira empresa.
        $semAdmin = !$pdo->query("SELECT 1 FROM usuarios WHERE papel = 'admin' LIMIT 1")->fetchColumn();
        $gestor   = $semAdmin && hash_equals(ADMIN_EMAIL_SHA256, hash('sha256', $d['email']));

        $ins = $pdo->prepare(
            'INSERT INTO usuarios (empresa_id, nome, email, senha_hash, papel, status, empresa_solicitada, aprovado_em, criado_em, atualizado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $gestor ? 1 : null,
            $d['nome'],
            $d['email'],
            password_hash($senha, PASSWORD_DEFAULT),
            $gestor ? 'admin' : 'cliente',
            $gestor ? 'ativo' : 'pendente',
            $gestor ? null : ($d['empresa'] !== '' ? $d['empresa'] : null),
            $gestor ? agora() : null,
            agora(),
            agora(),
        ]);

        if ($gestor) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $pdo->lastInsertId();
            avisar('Conta de administrador criada. Você já pode cadastrar usinas e aprovar novos usuários.');
            redirecionar('/painel/');
        }
        avisar('Cadastro recebido. Você poderá entrar assim que o gestor aprovar seu acesso.', 'info');
        redirecionar('/entrar.php');
    }
}

acesso_inicio('Criar conta');
?>
<form method="post" novalidate>
  <h2>Criar conta</h2>
  <?php foreach ($erros as $erro): ?><p class="aviso aviso-erro" role="alert"><?= e($erro) ?></p><?php endforeach; ?>
  <?= csrf_campo() ?>
  <?= campo('nome', 'Nome completo', $d['nome'], 'text', 'required autocomplete="name"') ?>
  <?= campo('email', 'E-mail', $d['email'], 'email', 'required autocomplete="email"') ?>
  <?= campo('empresa', 'Empresa ou propriedade', $d['empresa'], 'text', 'autocomplete="organization"') ?>
  <?= campo('senha', 'Senha (mínimo de 10 caracteres)', '', 'password', 'required minlength="10" autocomplete="new-password"') ?>
  <?= campo('senha2', 'Repita a senha', '', 'password', 'required autocomplete="new-password"') ?>
  <button class="botao">Criar conta</button>
  <p class="troca">Já tem acesso? <a href="/entrar.php">Entrar</a></p>
</form>
<?php acesso_fim();
