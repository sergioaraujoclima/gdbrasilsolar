<?php
/**
 * Anexar fatura em PDF: o sistema lê o arquivo, mostra o que encontrou e,
 * depois da confirmação, grava a fatura (e a unidade consumidora, se for nova).
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/consultas.php';
require __DIR__ . '/../app/Faturas.php';
require __DIR__ . '/../app/LeitorNeoenergia.php';

exigir_login();
$pdo   = db();
$pasta = __DIR__ . '/../storage/faturas';
const TAMANHO_MAXIMO = 5 * 1024 * 1024;

function caminho_temporario(string $pasta, string $token): string
{
    return $pasta . '/tmp-' . preg_replace('/[^a-f0-9]/', '', $token) . '.pdf';
}

/** Unidade já cadastrada com este número, mesmo fora da empresa do usuário. */
function unidade_por_numero(PDO $pdo, ?string $numero): ?array
{
    // compara só os dígitos: "129756100966" e "1.297.561.009-66" são a mesma unidade
    $digitos = preg_replace('/\D/', '', (string) $numero);
    if ($digitos === '') {
        return null;
    }
    $s = $pdo->prepare('SELECT * FROM unidades_consumidoras WHERE distribuidora = ?');
    $s->execute([LeitorNeoenergia::DISTRIBUIDORA]);
    foreach ($s->fetchAll() as $c) {
        if (preg_replace('/\D/', '', $c['numero_uc']) === $digitos) {
            return $c;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $etapa = $_POST['etapa'] ?? 'enviar';
    $token = (string) ($_POST['t'] ?? '');

    /* ---------- 1. Recebe e lê o PDF ---------- */
    if ($etapa === 'enviar') {
        $arq = $_FILES['arquivo'] ?? null;
        if (!$arq || $arq['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
            avisar('Escolha o arquivo PDF da fatura. O tamanho máximo é 5 MB.', 'erro');
            redirecionar('/painel/anexar.php');
        }
        if ($arq['size'] > TAMANHO_MAXIMO || file_get_contents($arq['tmp_name'], false, null, 0, 5) !== '%PDF-') {
            avisar('O arquivo precisa ser um PDF de até 5 MB.', 'erro');
            redirecionar('/painel/anexar.php');
        }
        if (!is_dir($pasta)) {
            mkdir($pasta, 0755, true);
        }
        $token   = bin2hex(random_bytes(12));
        $destino = caminho_temporario($pasta, $token);
        move_uploaded_file($arq['tmp_name'], $destino);
        try {
            $_SESSION['anexos'] = [$token => LeitorNeoenergia::lerArquivo($destino)]; // um anexo por vez
        } catch (Throwable $e) {
            @unlink($destino);
            avisar('Não consegui ler esta fatura: ' . $e->getMessage(), 'erro');
            redirecionar('/painel/anexar.php');
        }
        redirecionar('/painel/anexar.php?t=' . $token);
    }

    $lido = $_SESSION['anexos'][$token] ?? null;
    $tmp  = caminho_temporario($pasta, $token);
    if (!$lido || !is_file($tmp)) {
        avisar('Este envio expirou. Anexe o PDF de novo.', 'erro');
        redirecionar('/painel/anexar.php');
    }

    if ($etapa === 'cancelar') {
        @unlink($tmp);
        unset($_SESSION['anexos'][$token]);
        avisar('Envio descartado. Nada foi gravado.', 'info');
        redirecionar('/painel/faturas.php');
    }

    /* ---------- 2. Confirmação: grava UC (se nova) e fatura ---------- */
    $u = $lido['unidade'];
    $f = $lido['fatura'];
    $referencia = referencia_iso($f['referencia'] ?? '');

    // Faturas antigas não trazem o número da UC: vale a unidade escolhida ou o número digitado
    $escolhida = null;
    if (!$u['numero_uc']) {
        $escolhida = buscar_unidade((int) ($_POST['unidade_existente'] ?? 0));
        $u['numero_uc'] = $escolhida ? $escolhida['numero_uc'] : texto($_POST['numero_uc_digitado'] ?? '');
        $f['numero_uc'] = $u['numero_uc'];
    }
    if (!$u['numero_uc'] || !$referencia) {
        avisar(!$referencia ? 'Não encontrei o mês de referência nesta fatura. Use "Digitar fatura".'
            : 'Escolha a unidade desta fatura ou digite o número da UC.', 'erro');
        redirecionar('/painel/anexar.php?t=' . $token);
    }

    $unidade = $escolhida ?? unidade_por_numero($pdo, $u['numero_uc']);
    if ($unidade && escopo() !== null && (int) $unidade['empresa_id'] !== escopo()) {
        avisar('Esta unidade consumidora está cadastrada em outra empresa.', 'erro');
        redirecionar('/painel/anexar.php?t=' . $token);
    }

    $novaUc = false;
    if (!$unidade) {
        $empresa = escopo() ?? (int) ($_POST['empresa_id'] ?? 0);
        $tipo    = $_POST['tipo'] ?? '';
        if (!$empresa || !in_array($tipo, ['geradora', 'beneficiaria'], true)) {
            avisar('Para cadastrar a nova unidade, escolha a empresa e o papel dela na compensação.', 'erro');
            redirecionar('/painel/anexar.php?t=' . $token);
        }
        $usina = (int) ($_POST['usina_id'] ?? 0);
        $gerad = (int) ($_POST['uc_geradora_id'] ?? 0);
        $v = [
            'empresa_id'     => $empresa,
            'tipo'           => $tipo,
            'usina_id'       => ($tipo === 'geradora' && $usina && buscar_usina($usina)) ? $usina : null,
            'uc_geradora_id' => ($tipo === 'beneficiaria' && $gerad && buscar_unidade($gerad)) ? $gerad : null,
            'criado_em'      => agora(),
            'atualizado_em'  => agora(),
        ];
        foreach (['numero_uc', 'distribuidora', 'titular_nome', 'titular_documento', 'classificacao', 'tipo_fornecimento',
                  'regra_gd', 'endereco', 'cidade', 'uf', 'cep', 'medidor', 'demanda_contratada_kw'] as $c) {
            $v[$c] = $u[$c] ?? null;
        }
        $pdo->prepare('INSERT INTO unidades_consumidoras (' . implode(', ', array_keys($v)) . ') VALUES ('
            . rtrim(str_repeat('?, ', count($v)), ', ') . ')')->execute(array_values($v));
        $unidade = ['id' => (int) $pdo->lastInsertId()];
        $novaUc  = true;
    }

    // PDF anterior do mesmo mês, se a fatura estiver sendo substituída
    $s = $pdo->prepare('SELECT arquivo_pdf FROM faturas WHERE unidade_id = ? AND referencia = ?');
    $s->execute([$unidade['id'], $referencia]);
    $pdfAntigo = $s->fetchColumn();

    $id      = Faturas::salvar($pdo, (int) $unidade['id'], $referencia, $f, $f['itens']);
    $arquivo = 'fatura-' . $id . '-' . bin2hex(random_bytes(6)) . '.pdf';
    rename($tmp, $pasta . '/' . $arquivo);
    if ($pdfAntigo) {
        @unlink($pasta . '/' . basename($pdfAntigo));
    }
    $pdo->prepare('UPDATE faturas SET arquivo_pdf = ?, dados_extraidos = ? WHERE id = ?')->execute([
        $arquivo,
        json_encode(['unidade' => $u, 'fatura' => $f], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        $id,
    ]);
    unset($_SESSION['anexos'][$token]);

    avisar(($novaUc ? 'Unidade ' . $u['numero_uc'] . ' cadastrada; defina o nome/apelido dela em "Editar a unidade e o vínculo". ' : '') . 'Fatura de ' . mes_br($referencia)
        . ($pdfAntigo !== false ? ' substituída' : ' gravada') . ' a partir do PDF. Confira os campos abaixo.');
    redirecionar('/painel/faturas.php?ver=' . $id);
}

$token = (string) ($_GET['t'] ?? '');
$lido  = $token !== '' ? ($_SESSION['anexos'][$token] ?? null) : null;

painel_inicio('Anexar fatura', 'faturas');

if (!$lido): ?>
<div class="cabecalho"><div><h1>Anexar fatura em PDF</h1>
  <p>Envie o PDF baixado do site da distribuidora. O sistema lê os campos e mostra tudo para você conferir antes de gravar.</p></div></div>
<form method="post" enctype="multipart/form-data" class="bloco">
  <?= csrf_campo() ?><input type="hidden" name="etapa" value="enviar">
  <div class="grade">
    <label class="campo"><span>Arquivo da fatura (PDF, até 5 MB)</span><input type="file" name="arquivo" accept="application/pdf,.pdf" required></label>
  </div>
  <p class="nota" style="margin-top:1rem;color:var(--tinta-2)">Por enquanto são lidas as faturas da Neoenergia Distribuição Brasília. PDFs escaneados (imagem) não são lidos; para outros casos, use <a href="/painel/faturas.php?nova=1">Digitar fatura</a>.</p>
  <div class="form-acoes">
    <button class="botao">Ler a fatura</button>
    <a class="botao botao-claro" href="/painel/faturas.php">Voltar</a>
  </div>
</form>
<?php else:
    $u = $lido['unidade'];
    $f = $lido['fatura'];
    $unidade   = unidade_por_numero($pdo, $u['numero_uc']);
    $deOutra   = $unidade && escopo() !== null && (int) $unidade['empresa_id'] !== escopo();
    $ref       = referencia_iso($f['referencia'] ?? '');
    $jaExiste  = false;
    if ($unidade && $ref) {
        $s = $pdo->prepare('SELECT 1 FROM faturas WHERE unidade_id = ? AND referencia = ?');
        $s->execute([$unidade['id'], $ref]);
        $jaExiste = (bool) $s->fetchColumn();
    }
    $geradoras = array_filter(unidades_visiveis(), fn ($g) => $g['tipo'] === 'geradora');
    ?>
<div class="cabecalho"><div><h1>Conferir a fatura lida</h1>
  <p>Nada foi gravado ainda. Confira os dados e confirme.</p></div></div>

<?php foreach ($lido['avisos'] as $a): ?><p class="aviso aviso-info"><?= e($a) ?> <?= str_contains($a, 'unidade consumidora') ? 'Informe abaixo de qual unidade é esta fatura.' : 'Você poderá corrigir o campo depois de gravar.' ?></p><?php endforeach; ?>
<?php if ($lido['soma_confere']): ?><p class="aviso">A soma dos <?= count($f['itens']) ?> itens confere com o total a pagar: <?= brl($f['valor_total']) ?>.</p><?php endif; ?>
<?php if ($jaExiste): ?><p class="aviso aviso-info">Já existe uma fatura de <?= mes_br($ref) ?> para esta unidade. Ao confirmar, ela será substituída pelos dados deste PDF.</p><?php endif; ?>
<?php if ($deOutra): ?><p class="aviso aviso-erro">Esta unidade consumidora está cadastrada em outra empresa. Não é possível gravar esta fatura.</p><?php endif; ?>

<form method="post">
  <?= csrf_campo() ?><input type="hidden" name="t" value="<?= e($token) ?>">

  <div class="bloco">
    <h2 style="margin-top:0"><?= $unidade ? e(nome_uc($unidade)) : 'Unidade consumidora ' . e($u['numero_uc'] ?: 'não identificada') ?>
      <?php if ($unidade): ?><span class="etiqueta">Já cadastrada</span><?php elseif ($u['numero_uc']): ?><span class="etiqueta etiqueta-pendente">Nova: será cadastrada</span><?php endif; ?></h2>
    <p class="endereco" style="margin:.4rem 0 1rem"><?= e(endereco_uc($u)) ?></p>
    <dl class="dados">
      <div><dt>Titular</dt><dd><?= e($u['titular_nome'] ?: '–') ?></dd></div>
      <div><dt>Classificação</dt><dd><?= e($u['classificacao'] ?: '–') ?></dd></div>
      <div><dt>Fornecimento</dt><dd><?= e($u['tipo_fornecimento'] ?: '–') ?></dd></div>
      <div><dt>Medidor</dt><dd><?= e($u['medidor'] ?: '–') ?></dd></div>
    </dl>
    <?php if (!$u['numero_uc']): ?>
    <fieldset><legend>De qual unidade é esta fatura?</legend><div class="grade">
      <?= selecao('unidade_existente', 'Unidade já cadastrada', opcoes_uc(unidades_visiveis()), '', true) ?>
      <?= campo('numero_uc_digitado', 'Ou digite o número da UC (unidade nova)', '', 'text', 'maxlength="30" placeholder="Ex.: 1.297.561.009-66"') ?>
    </div></fieldset>
    <?php endif; ?>
    <?php if (!$unidade): ?>
    <fieldset><legend><?= $u['numero_uc'] ? 'Complete o cadastro da nova unidade' : 'Preencha somente se a unidade for nova' ?></legend><div class="grade">
      <?php if (eh_admin()): ?><?= selecao('empresa_id', 'Empresa', array_column(empresas_disponiveis(), 'nome', 'id'), 1) ?><?php endif; ?>
      <?= selecao('tipo', 'Papel na compensação', ['geradora' => 'Geradora (tem usina)', 'beneficiaria' => 'Beneficiária (recebe créditos)'], $u['tipo'], $u['tipo'] === '') ?>
      <?= selecao('usina_id', 'Usina instalada (se geradora)', array_column(usinas_visiveis(false), 'nome', 'id'), '', true) ?>
      <?= selecao('uc_geradora_id', 'Recebe créditos da UC (se beneficiária)', opcoes_uc($geradoras), '', true) ?>
    </div></fieldset>
    <?php endif; ?>
  </div>

  <div class="bloco">
    <h2 style="margin-top:0">Fatura de <?= $ref ? mes_br($ref) : 'mês não identificado' ?></h2>
    <dl class="dados">
      <div><dt>Total a pagar</dt><dd><?= brl($f['valor_total']) ?></dd></div>
      <div><dt>Vencimento</dt><dd><?= data_br($f['vencimento']) ?></dd></div>
      <div><dt>Emissão</dt><dd><?= data_br($f['data_emissao']) ?></dd></div>
      <div><dt>Nota fiscal</dt><dd><?= e($f['numero_nf'] ?: '–') ?></dd></div>
      <div><dt>Leitura</dt><dd><?= data_br($f['leitura_anterior']) ?> a <?= data_br($f['leitura_atual']) ?> (<?= e($f['dias_faturados'] ?? '–') ?> dias)</dd></div>
      <div><dt>Bandeira</dt><dd><?= e($f['bandeira'] ?: '–') ?></dd></div>
      <div><dt>Consumo medido</dt><dd><?= kwh($f['consumo_medido_kwh'], 1) ?></dd></div>
      <div><dt>Consumo faturado</dt><dd><?= kwh($f['consumo_faturado_kwh'], 1) ?></dd></div>
      <div><dt>Energia injetada</dt><dd><?= kwh($f['energia_injetada_kwh'], 1) ?></dd></div>
      <div><dt>Créditos utilizados</dt><dd><?= kwh($f['creditos_utilizados_kwh'], 1) ?></dd></div>
      <div><dt>Saldo para o próximo ciclo</dt><dd><?= kwh($f['saldo_creditos_kwh'], 1) ?></dd></div>
      <?php if ($f['demanda_medida_kw'] !== null): ?>
      <div><dt>Demanda faturada / contratada</dt><dd><?= num($f['demanda_medida_kw'], 2) ?> / <?= num($f['demanda_contratada_kw'], 0) ?> kW</dd></div>
      <div><dt>Ponta / fora de ponta / reservado</dt><dd><?= num($f['consumo_ponta_kwh'], 1) ?> / <?= num($f['consumo_fora_ponta_kwh'], 1) ?> / <?= num($f['consumo_reservado_kwh'], 1) ?> kWh</dd></div>
      <?php endif; ?>
      <div><dt>PIS / COFINS / ICMS</dt><dd><?= brl($f['valor_pis']) ?> / <?= brl($f['valor_cofins']) ?> / <?= brl($f['valor_icms']) ?></dd></div>
    </dl>
    <div class="rolagem" style="margin-top:1.25rem"><table>
      <thead><tr><th>Item</th><th>Unid.</th><th class="n">Quantidade</th><th class="n">Preço unitário</th><th class="n">Valor</th></tr></thead>
      <tbody><?php foreach ($f['itens'] as $i): ?>
        <tr><td><?= e($i['descricao']) ?></td><td><?= e($i['unidade'] ?? '') ?></td>
            <td class="n"><?= $i['quantidade'] !== null ? num($i['quantidade'], 2) : '' ?></td>
            <td class="n"><?= $i['preco_unitario'] !== null ? num($i['preco_unitario'], 8) : '' ?></td>
            <td class="n"><?= brl($i['valor']) ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="4">Soma dos itens</td><td class="n"><?= brl($lido['soma_itens']) ?></td></tr></tfoot>
    </table></div>
  </div>

  <div class="form-acoes">
    <?php if (!$deOutra): ?><button class="botao" name="etapa" value="confirmar"><?= ($unidade || !$u['numero_uc']) ? 'Gravar fatura' : 'Cadastrar unidade e gravar fatura' ?></button><?php endif; ?>
    <button class="botao botao-claro" name="etapa" value="cancelar" formnovalidate>Descartar</button>
  </div>
</form>
<?php endif;
painel_fim();
