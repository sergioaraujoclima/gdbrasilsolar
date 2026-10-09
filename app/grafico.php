<?php
/**
 * Seletor de período, gráfico e tabela da geração (diária, mensal ou anual).
 * Lê ?v=dia|mes|ano, ?m=AAAA-MM e ?a=AAAA da URL.
 */
function relatorio_geracao(array $usinas, string $urlBase, ?int $usinaId = null): void
{
    $hoje  = hoje_local();
    $visao = in_array($_GET['v'] ?? '', ['dia', 'mes', 'ano'], true) ? $_GET['v'] : 'dia';
    $mes   = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : substr($hoje, 0, 7);
    $ano   = preg_match('/^\d{4}$/', $_GET['a'] ?? '') ? $_GET['a'] : substr($hoje, 0, 4);

    // Chaves do eixo, mesmo para períodos sem dado
    $chaves = [];
    if ($visao === 'dia') {
        $inicio = "$mes-01";
        $fim    = date('Y-m-t', strtotime($inicio));
        for ($d = 1; $d <= (int) substr($fim, 8, 2); $d++) {
            $chaves[] = sprintf('%s-%02d', $mes, $d);
        }
        $titulo = 'Geração por dia em ' . mes_br($inicio);
    } elseif ($visao === 'mes') {
        $inicio = "$ano-01-01";
        $fim    = "$ano-12-31";
        for ($m = 1; $m <= 12; $m++) {
            $chaves[] = sprintf('%s-%02d', $ano, $m);
        }
        $titulo = "Geração por mês em $ano";
    } else {
        $inicio = '2000-01-01';
        $fim    = $hoje;
        $titulo = 'Geração por ano';
    }

    $serie = serie_geracao($visao, $inicio, $fim, $usinaId);
    if ($visao === 'ano') {
        $anos   = array_keys($serie) ?: [substr($hoje, 0, 4)];
        $chaves = array_map('strval', range((int) min($anos), (int) substr($hoje, 0, 4)));
    }

    $rotulo = fn (string $c) => match ($visao) {
        'dia' => substr($c, 8, 2),
        'mes' => ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][(int) substr($c, 5, 2)],
        default => $c,
    };
    $rotuloTabela = fn (string $c) => match ($visao) {
        'dia' => data_br($c),
        'mes' => mes_br($c . '-01'),
        default => $c,
    };

    $conjuntos = [];
    foreach (array_values($usinas) as $i => $u) {
        $conjuntos[] = [
            'label'           => $u['nome'],
            'data'            => array_map(fn ($c) => round($serie[$c][(int) $u['id']] ?? 0, 1), $chaves),
            'backgroundColor' => CORES_USINAS[$i % count(CORES_USINAS)],
            'borderRadius'    => 3,
        ];
    }
    $sep = str_contains($urlBase, '?') ? '&' : '?';
    ?>
    <div class="visoes">
      <div class="abas" role="group" aria-label="Agrupar geração por">
        <?php foreach (['dia' => 'Diário', 'mes' => 'Mensal', 'ano' => 'Anual'] as $v => $nome): ?>
          <a href="<?= e($urlBase . $sep . 'v=' . $v) ?>"<?= $v === $visao ? ' aria-current="true"' : '' ?>><?= $nome ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($visao !== 'ano'): ?>
      <form method="get" action="<?= e(strtok($urlBase, '?')) ?>" class="visoes" style="margin:0">
        <?php parse_str((string) parse_url($urlBase, PHP_URL_QUERY), $fixos); foreach ($fixos as $k => $val): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($val) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="v" value="<?= $visao ?>">
        <?= $visao === 'dia'
            ? campo('m', 'Mês', $mes, 'month', 'max="' . substr($hoje, 0, 7) . '"')
            : campo('a', 'Ano', $ano, 'number', 'min="2000" max="' . substr($hoje, 0, 4) . '"') ?>
        <button class="botao botao-claro botao-p">Mostrar</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="bloco">
      <h2 style="margin-top:0"><?= e($titulo) ?> (kWh)</h2>
      <div class="grafico"><canvas id="grafico-geracao" role="img" aria-label="<?= e($titulo) ?>. Os valores estão na tabela abaixo."></canvas></div>
    </div>

    <div class="bloco rolagem">
      <table>
        <thead><tr>
          <th><?= ['dia' => 'Dia', 'mes' => 'Mês', 'ano' => 'Ano'][$visao] ?></th>
          <?php foreach ($usinas as $u): ?><th class="n"><?= e($u['nome']) ?></th><?php endforeach; ?>
          <?php if (count($usinas) > 1): ?><th class="n">Total</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php $somas = []; foreach ($chaves as $c): if ($visao === 'dia' && $c > $hoje) { continue; } ?>
          <tr>
            <td><?= e($rotuloTabela($c)) ?></td>
            <?php $linha = 0; foreach ($usinas as $u): $v = $serie[$c][(int) $u['id']] ?? null; $linha += (float) $v; $somas[$u['id']] = ($somas[$u['id']] ?? 0) + (float) $v; ?>
              <td class="n"><?= num($v, 1) ?></td>
            <?php endforeach; ?>
            <?php if (count($usinas) > 1): ?><td class="n"><?= num($linha, 1) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td>Total do período</td>
          <?php foreach ($usinas as $u): ?><td class="n"><?= num($somas[$u['id']] ?? 0, 1) ?></td><?php endforeach; ?>
          <?php if (count($usinas) > 1): ?><td class="n"><?= num(array_sum($somas), 1) ?></td><?php endif; ?>
        </tr></tfoot>
      </table>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
    (function () {
      if (!window.Chart) { return; }
      var nf = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
      Chart.defaults.font.family = 'Figtree, "Segoe UI", sans-serif';
      Chart.defaults.color = '#4a5b6b';
      new Chart(document.getElementById('grafico-geracao'), {
        type: 'bar',
        data: { labels: <?= json_encode(array_map($rotulo, $chaves)) ?>, datasets: <?= json_encode($conjuntos, JSON_UNESCAPED_UNICODE) ?> },
        options: {
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'top', align: 'start', labels: { boxWidth: 14, boxHeight: 14 } },
            tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + nf.format(c.parsed.y) + ' kWh'; } } }
          },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { callback: function (v) { return nf.format(v); } }, title: { display: true, text: 'kWh' } }
          }
        }
      });
    })();
    </script>
    <?php
}
