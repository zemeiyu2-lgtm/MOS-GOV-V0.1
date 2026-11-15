<?php
/**
 * MOS-GOV Phase 5 — REAL tenant-boundary HTTP service (PHP built-in server).
 *
 * HONESTY: this is a purpose-built boundary harness, NOT the full ChurchCRM
 * 7.8.0 application (no Slim router, no Propel bootstrap, no Apache). It exists
 * so that the isolation checks issue REAL HTTP requests against REAL MariaDB
 * queries and REAL files. Full-stack ChurchCRM E2E is reported separately as
 * not covered by this harness.
 *
 * Start (after sourcing the protected lab env file):
 *   php -c <ini> -S 127.0.0.1:8099 router.php
 */
declare(strict_types=1);

// HTTP_HOST carries the port whenever the listener is not on 80/443. Tenant
// resolution must key on the bare hostname, otherwise every request behind a
// non-default port (or a reverse proxy that forwards the origin port) would be
// rejected as "unregistered host". Origin checks still compare the FULL host.
$hostRaw = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = (string) preg_replace('/:\d+$/', '', $hostRaw);

$tenants = [
    'a.mosgov.example' => [
        'tenant'  => 'church-a',
        'db'      => getenv('MOSGOV_P5_DB_A'),
        'user'    => getenv('MOSGOV_P5_USER_A'),
        'pass'    => getenv('MOSGOV_P5_PASS_A'),
        'storage' => getenv('MOSGOV_P5_STORAGE_A'),
    ],
    'b.mosgov.example' => [
        'tenant'  => 'church-b',
        'db'      => getenv('MOSGOV_P5_DB_B'),
        'user'    => getenv('MOSGOV_P5_USER_B'),
        'pass'    => getenv('MOSGOV_P5_PASS_B'),
        'storage' => getenv('MOSGOV_P5_STORAGE_B'),
    ],
];

header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function send(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
function db(array $t): \mysqli
{
    $c = @new \mysqli('127.0.0.1', (string)$t['user'], (string)$t['pass'], (string)$t['db'], 3407);
    if ($c->connect_errno) { send(500, ['error' => 'db unavailable']); }
    $c->set_charset('utf8mb4');
    return $c;
}

if (!isset($tenants[$host])) {
    send(403, ['error' => 'unregistered host', 'tenant' => null]);
}
$t = $tenants[$host];

session_name('MOSGOVP5SESS');
session_set_cookie_params([
    'httponly' => true, 'samesite' => 'Lax', 'path' => '/', 'secure' => false,
]);
session_start();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---- CSRF + Origin enforcement for every write ---- */
if ($method === 'POST') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== 'http://' . $host) {
        send(403, ['error' => 'cross-origin rejected']);
    }
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32,64}$/', $token)
        || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        send(403, ['error' => 'csrf invalid']);
    }
}

/* ---- login ---- */
if ($path === '/login' && $method === 'GET') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>MOS-GOV Phase 5 合成测试环境</title></head>'
       . '<body><h1>MOS-GOV 测试/试运行环境（仅合成数据）</h1>'
       . '<p>tenant: ' . htmlspecialchars($t['tenant'], ENT_QUOTES) . '</p>'
       . '<form method="post"><input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">'
       . '<input name="login"><input name="secret" type="password"><button type="submit">登录</button></form></body></html>';
    exit;
}

if ($path === '/login' && $method === 'POST') {
    $credFile = rtrim((string)$t['storage'], '/') . '/.p5-login.json';
    $cred = json_decode((string)@file_get_contents($credFile), true);
    $login = (string)($_POST['login'] ?? '');
    $secret = (string)($_POST['secret'] ?? '');
    if (!$cred || !hash_equals((string)$cred['login'], $login) || !hash_equals((string)$cred['secret'], $secret)) {
        send(401, ['error' => 'bad credentials']);
    }
    session_regenerate_id(true);
    $_SESSION['tenant'] = $t['tenant'];
    $_SESSION['actor']  = $login;
    unset($_SESSION['csrf']);
    send(200, ['ok' => true, 'tenant' => $t['tenant'], 'sid' => session_id()]);
}

if ($path === '/logout') {
    $_SESSION = [];
    session_destroy();
    send(200, ['ok' => true]);
}

/* ---- all other endpoints require a session bound to THIS tenant ---- */
$authed = isset($_SESSION['tenant'], $_SESSION['actor']) && $_SESSION['tenant'] === $t['tenant'];
if (!$authed) {
    send(401, ['error' => 'unauthenticated or foreign tenant session']);
}

if ($path === '/api/members') {
    $c = db($t);
    $r = mysqli_query($c, 'SELECT person_id, display_name_override FROM gov_identity ORDER BY person_id');
    $out = [];
    while ($x = mysqli_fetch_assoc($r)) { $out[] = $x; }
    send(200, ['tenant' => $t['tenant'], 'members' => $out]);
}

if ($path === '/api/governance') {
    $c = db($t);
    $r = mysqli_query($c, 'SELECT id, title FROM gov_decision ORDER BY id');
    $out = [];
    while ($x = mysqli_fetch_assoc($r)) { $out[] = $x; }
    send(200, ['tenant' => $t['tenant'], 'decisions' => $out]);
}

if (str_starts_with($path, '/api/files/')) {
    $key = rawurldecode(substr($path, strlen('/api/files/')));
    // Reject traversal / absolute / empty keys before touching the filesystem.
    if ($key === '' || str_contains($key, '/') || str_contains($key, '\\') || str_contains($key, '..')) {
        send(403, ['error' => 'invalid object key']);
    }
    $c = db($t);
    $stmt = mysqli_prepare($c, 'SELECT byte_size, sha256, tenant_id FROM gov_tenant_file WHERE object_key=? AND tenant_id=? AND status=\'active\'');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $t['tenant']);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    if (!$row) { send(404, ['error' => 'file not registered for this tenant']); }
    $p = rtrim((string)$t['storage'], '/') . '/' . $key;
    if (!is_file($p) || hash_file('sha256', $p) !== $row['sha256']) {
        send(500, ['error' => 'file integrity mismatch']);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('X-File-Sha256: ' . $row['sha256']);
    readfile($p);
    exit;
}

send(404, ['error' => 'not found']);
