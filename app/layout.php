<?php
/** Moldura das páginas do painel (menu lateral, avisos, rodapé). */

function painel_inicio(string $titulo, string $secao): void
{
    $u = exigir_login();
    $menu = [
        'dashboard' => ['/painel/', 'Geração'],
        'usinas'    => ['/painel/usinas.php', 'Usinas'],
        'unidades'  => ['/painel/unidades.php', 'Unidades consumidoras'],
        'faturas'   => ['/painel/faturas.php', 'Faturas'],
        'creditos'  => ['/painel/creditos.php', 'Créditos'],
        'rateios'   => ['/painel/rateios.php', 'Rateios de crédito'],
    ];
    if ($u['papel'] === 'admin') {
        $pendentes = (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE status = 'pendente'")->fetchColumn();
        $menu['empresas'] = ['/painel/empresas.php', 'Empresas'];
        $menu['usuarios'] = ['/painel/usuarios.php', 'Usuários' . ($pendentes ? " ($pendentes)" : '')];
        $menu['importar'] = ['/painel/importar.php', 'Importar dados'];
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> | GD Brasil Solar</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/base.css?v=1">
<link rel="stylesheet" href="/assets/css/painel.css?v=3">
</head>
<body class="painel">
<aside class="lateral">
  <a class="marca" href="/painel/"><span class="marca-sol" aria-hidden="true"></span>GD Brasil Solar</a>
  <nav aria-label="Módulos">
    <?php foreach ($menu as $chave => [$url, $rotulo]): ?>
      <a href="<?= $url ?>"<?= $chave === $secao ? ' aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="lateral-pe">
    <span><?= e($u['nome']) ?></span>
    <form method="post" action="/sair.php"><?= csrf_campo() ?><button class="link">Sair</button></form>
  </div>
</aside>
<main class="conteudo">
  <?php foreach (avisos() as $a): ?>
    <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['msg']) ?></p>
  <?php endforeach; ?>
    <?php
}

function painel_fim(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** Campo de formulário com rótulo. */
function campo(string $nome, string $rotulo, mixed $valor = '', string $tipo = 'text', string $extra = ''): string
{
    return '<label class="campo"><span>' . e($rotulo) . '</span><input type="' . $tipo . '" name="' . $nome
        . '" value="' . e($valor) . '" ' . $extra . '></label>';
}

/** Campo de seleção; $opcoes é valor => rótulo. */
function selecao(string $nome, string $rotulo, array $opcoes, mixed $atual = '', bool $vazio = false): string
{
    $h = '<label class="campo"><span>' . e($rotulo) . '</span><select name="' . $nome . '">';
    if ($vazio) {
        $h .= '<option value="">–</option>';
    }
    foreach ($opcoes as $valor => $texto) {
        $h .= '<option value="' . e($valor) . '"' . ((string) $valor === (string) $atual ? ' selected' : '') . '>'
            . e($texto) . '</option>';
    }
    return $h . '</select></label>';
}
