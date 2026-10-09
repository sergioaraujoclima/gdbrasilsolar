<?php
require_once __DIR__ . "/layout.php";

/** Moldura das telas de acesso (entrar e criar conta). */
function acesso_inicio(string $titulo): void
{
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
<link rel="stylesheet" href="/assets/css/site.css?v=1">
</head>
<body>
<div class="acesso">
  <div class="acesso-lado">
    <a class="marca" href="/"><span class="marca-sol" aria-hidden="true"></span>GD Brasil Solar</a>
    <div>
      <h1>Geração e créditos da sua usina, dia a dia.</h1>
      <p>Os dados vêm do inversor e das faturas da distribuidora.</p>
    </div>
    <span></span>
  </div>
  <div class="acesso-form">
    <?php
}

function acesso_fim(): void
{
    echo "  </div>\n</div>\n</body>\n</html>\n";
}
