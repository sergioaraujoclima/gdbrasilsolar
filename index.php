<?php
header('Cache-Control: no-cache');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GD Brasil Solar | Geração e créditos da sua usina solar</title>
<meta name="description" content="Acompanhe a geração das suas usinas solares e o saldo de créditos de energia em cada unidade consumidora.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/base.css?v=1">
<link rel="stylesheet" href="/assets/css/site.css?v=1">
</head>
<body class="site">

<header class="faixa topo">
  <a class="marca" href="/"><span class="marca-sol" aria-hidden="true"></span>GD Brasil Solar</a>
  <nav aria-label="Acesso">
    <a class="botao botao-claro" href="/cadastro.php">Criar conta</a>
    <a class="botao" href="/entrar.php">Entrar</a>
  </nav>
</header>

<main>
  <section class="faixa abertura">
    <div>
      <h1>A geração da sua usina e os créditos de energia, em um só lugar.</h1>
      <p class="resumo">O GD Brasil Solar lê a produção direto do inversor, dia a dia, e cruza com as faturas da distribuidora para mostrar quanto foi gerado, quanto virou crédito e quanto ainda há de saldo.</p>
      <div class="acoes">
        <a class="botao botao-sol" href="/cadastro.php">Criar conta</a>
        <a class="botao botao-claro" href="/entrar.php">Já tenho acesso</a>
      </div>
    </div>

    <svg class="curva" viewBox="0 0 600 320" role="img" aria-label="Curva de geração de uma usina solar ao longo de um dia, do nascer do sol ao meio-dia">
      <defs>
        <linearGradient id="luz" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#ffb703" stop-opacity="0.75"/>
          <stop offset="1" stop-color="#ffb703" stop-opacity="0.05"/>
        </linearGradient>
        <clipPath id="ja-gerado"><rect class="cortina" x="40" y="0" width="520" height="262"/></clipPath>
      </defs>
      <path class="area" clip-path="url(#ja-gerado)" d="M40,260 C160,260 200,60 300,60 C400,60 440,260 560,260 Z"/>
      <path class="linha" d="M40,260 C160,260 200,60 300,60 C400,60 440,260 560,260"/>
      <line class="eixo" x1="20" y1="260" x2="580" y2="260"/>
      <g class="astro"><circle r="22"/><circle r="11"/></g>
      <text class="hora" x="40" y="286">6h</text>
      <text class="hora" x="170" y="286">9h</text>
      <text class="hora" x="300" y="286">12h</text>
      <text class="hora" x="430" y="286">15h</text>
      <text class="hora" x="560" y="286">18h</text>
      <text class="legenda" x="40" y="312">Energia gerada até agora</text>
    </svg>
  </section>

  <section class="faixa acompanha">
    <h2>O que você acompanha</h2>
    <p class="introducao">Três informações que hoje ficam espalhadas entre o aplicativo do inversor e as faturas em PDF.</p>
    <div class="trio">
      <div>
        <svg viewBox="0 0 48 48" fill="none" stroke="#f48c06" stroke-width="3" stroke-linecap="round" aria-hidden="true"><circle cx="24" cy="24" r="8" fill="#ffb703"/><path d="M24 4v6M24 38v6M4 24h6M38 24h6M9.9 9.9l4.2 4.2M33.9 33.9l4.2 4.2M9.9 38.1l4.2-4.2M33.9 14.1l4.2-4.2"/></svg>
        <h3>Geração de cada usina</h3>
        <p>Energia produzida por dia, por mês e por ano, lida automaticamente do inversor, desde o primeiro dia de operação.</p>
      </div>
      <div>
        <svg viewBox="0 0 48 48" fill="#1e8f5e" aria-hidden="true"><path d="M27 4 10 27h11l-3 17 20-25H26z"/></svg>
        <h3>Créditos de energia</h3>
        <p>Quanto foi injetado na rede, quanto cada unidade beneficiária usou e qual é o saldo para o próximo ciclo.</p>
      </div>
      <div>
        <svg viewBox="0 0 48 48" fill="none" stroke="#0f2a44" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M12 5h18l8 8v30H12z"/><path d="M30 5v8h8M18 23h14M18 30h14M18 37h8"/></svg>
        <h3>Faturas vinculadas</h3>
        <p>As faturas da distribuidora ficam ligadas à usina e às unidades que recebem os créditos, com valores e consumo de cada mês.</p>
      </div>
    </div>
  </section>

  <section class="caminho">
    <div class="faixa">
      <h2>O caminho de um crédito</h2>
      <p class="introducao">Da placa solar ao desconto na conta, o sistema registra cada etapa.</p>
      <ol class="passos">
        <li><h3>A usina gera</h3><p>O inversor informa a energia produzida em cada dia.</p></li>
        <li><h3>A energia entra na rede</h3><p>O que não é consumido no local é injetado e medido pela distribuidora.</p></li>
        <li><h3>A distribuidora credita</h3><p>A energia injetada vira saldo em kWh na fatura da unidade geradora.</p></li>
        <li><h3>As unidades abatem</h3><p>Cada unidade beneficiária usa os créditos e paga menos na própria fatura.</p></li>
      </ol>
    </div>
  </section>

  <section class="faixa objetivo">
    <h2>Nosso objetivo</h2>
    <div>
      <blockquote>Gerenciar e apresentar, de forma simples e eficiente, a geração de energia para proprietários de usinas solares, com controle preciso dos créditos.</blockquote>
      <ul>
        <li>Uma empresa pode ter uma ou várias usinas, cada uma com suas unidades consumidoras.</li>
        <li>Os dados vêm das fontes oficiais: o inversor e a fatura da distribuidora.</li>
        <li>Cada empresa vê somente as próprias usinas, e o acesso é liberado pelo gestor.</li>
      </ul>
    </div>
  </section>

  <section class="faixa">
    <div class="chamada">
      <div>
        <h2>Tem uma usina solar?</h2>
        <p>Crie sua conta. O acesso é liberado depois da aprovação do gestor.</p>
      </div>
      <a class="botao botao-sol" href="/cadastro.php">Criar conta</a>
    </div>
  </section>
</main>

<footer class="faixa rodape">
  <span>GD Brasil Solar</span>
  <span>Geração distribuída de energia solar</span>
</footer>

</body>
</html>
