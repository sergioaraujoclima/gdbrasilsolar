<?php
/**
 * Base de todas as páginas: sessão, usuário logado, proteção CSRF e
 * funções de formatação.
 */
require_once __DIR__ . '/../config/conexao.php';

// O primeiro administrador só pode ser criado com este e-mail (guardado
// como hash para não expor o endereço no repositório).
const ADMIN_EMAIL_SHA256 = '6321457a1d6d7b71b139d130471ba44ff64ee4370c35846d98a9e18facdb08a0';

header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

session_name('gdsolar');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function agora(): string
{
    return gmdate('Y-m-d H:i:s');
}

function hoje_local(): string
{
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function redirecionar(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------- CSRF ---------- */

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf() . '">';
}

function csrf_validar(): void
{
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('A sessão expirou. Volte à página anterior e tente de novo.');
    }
}

/* ---------- Avisos entre páginas ---------- */

function avisar(string $mensagem, string $tipo = 'ok'): void
{
    $_SESSION['avisos'][] = ['msg' => $mensagem, 'tipo' => $tipo];
}

function avisos(): array
{
    $a = $_SESSION['avisos'] ?? [];
    unset($_SESSION['avisos']);
    return $a;
}

/* ---------- Usuário e permissões ---------- */

function usuario(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['usuario_id'])) {
            $s = db()->prepare("SELECT * FROM usuarios WHERE id = ? AND status = 'ativo'");
            $s->execute([$_SESSION['usuario_id']]);
            $u = $s->fetch() ?: null;
        }
    }
    return $u;
}

function exigir_login(): array
{
    $u = usuario();
    if (!$u) {
        redirecionar('/entrar.php');
    }
    return $u;
}

function exigir_admin(): array
{
    $u = exigir_login();
    if ($u['papel'] !== 'admin') {
        http_response_code(403);
        exit('Esta área é restrita ao administrador.');
    }
    return $u;
}

function eh_admin(): bool
{
    return (usuario()['papel'] ?? '') === 'admin';
}

/** Empresa a que o usuário está limitado; null = administrador, vê todas. */
function escopo(): ?int
{
    $u = exigir_login();
    return $u['papel'] === 'admin' ? null : (int) $u['empresa_id'];
}

/**
 * Trecho de WHERE que limita a consulta à empresa do usuário.
 * Ex.: "SELECT ... FROM usinas u WHERE 1=1" . filtro_empresa('u', $params)
 */
function filtro_empresa(string $alias, array &$params): string
{
    $empresa = escopo();
    if ($empresa === null) {
        return '';
    }
    $params[] = $empresa;
    return " AND $alias.empresa_id = ?";
}

function empresas_disponiveis(): array
{
    $params = [];
    $sql = 'SELECT e.id, e.nome FROM empresas e WHERE e.ativo = 1';
    if (escopo() !== null) {
        $sql .= ' AND e.id = ?';
        $params[] = escopo();
    }
    $s = db()->prepare($sql . ' ORDER BY e.nome');
    $s->execute($params);
    return $s->fetchAll();
}

/* ---------- Formatação ---------- */

function num(mixed $v, int $casas = 0): string
{
    return ($v === null || $v === '') ? '–' : number_format((float) $v, $casas, ',', '.');
}

function kwh(mixed $v, int $casas = 0): string
{
    return ($v === null || $v === '') ? '–' : num($v, $casas) . ' kWh';
}

function brl(mixed $v): string
{
    return ($v === null || $v === '') ? '–' : 'R$ ' . num($v, 2);
}

function data_br(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '–';
}

function mes_br(?string $d): string
{
    return $d ? date('m/Y', strtotime($d)) : '–';
}

/**
 * Converte um número digitado: aceita "1.234,56", "1234,56", "1.234", "1234.56",
 * "R$ 1.234,56", "50,65-" e "-50,65". Vazio ou ilegível vira null.
 */
function decimal(mixed $v): ?float
{
    $v = preg_replace('/[^\d,.\-]/', '', (string) $v); // tira R$, kWh, espaços
    $negativo = str_contains($v, '-');
    $v = str_replace('-', '', $v);
    if ($v === '') {
        return null;
    }
    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);   // padrão brasileiro
    } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v);                 // "1.500" é mil e quinhentos
    }
    if (!is_numeric($v)) {
        return null;
    }
    return $negativo ? -(float) $v : (float) $v;
}

/** Campo preenchido com algo que não é número. */
function decimal_invalido(mixed $v): bool
{
    return trim((string) $v) !== '' && decimal($v) === null;
}

function texto(mixed $v): ?string
{
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}

/** Aceita "2026-08-31" ou "31/08/2026"; vazio vira null. */
function data_iso(mixed $v): ?string
{
    $v = trim((string) $v);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m)) {
        return "$m[3]-$m[2]-$m[1]";
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/** Aceita "2026-08" ou "08/2026"; devolve o primeiro dia do mês. */
function referencia_iso(mixed $v): ?string
{
    $v = trim((string) $v);
    if (preg_match('#^(\d{2})/(\d{4})$#', $v, $m)) {
        return "$m[2]-$m[1]-01";
    }
    return preg_match('/^(\d{4}-\d{2})/', $v, $m) ? $m[1] . '-01' : null;
}
