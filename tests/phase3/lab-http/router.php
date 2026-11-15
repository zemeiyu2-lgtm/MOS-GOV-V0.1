<?php

/**
 * Phase 3 lab HTTP front controller (NOT the ChurchCRM application).
 *
 * Purpose: exercise the NEW public security components over real HTTP with a
 * real PHP session, real CSRF token, real 401/403 responses and real security
 * headers. It is deliberately tiny and uses synthetic accounts in memory.
 * It does NOT boot ChurchCRM / Slim / Propel, so these results must not be
 * reported as full-product end-to-end login tests.
 *
 * TLS termination is simulated the same way the production vhost does:
 * Apache sets `HTTPS=on` for the TLS-offloading vhost. The router mirrors it.
 */

require dirname(__DIR__) . '/bootstrap.php';

use ChurchCRM\Plugins\MosGov\Security\PublicRequestPolicy;
use ChurchCRM\Plugins\MosGov\Security\PublicTrustPolicy;
use ChurchCRM\Plugins\MosGov\Security\SafeError;
use ChurchCRM\Plugins\MosGov\Security\SessionHardening;
use ChurchCRM\Plugins\MosGov\Security\TenantContext;

$server = $_SERVER;
$server['HTTPS'] = 'on'; // vhost: SetEnv HTTPS on (TLS terminated upstream)
$headers = [];
foreach ($server as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
        $headers[$name] = (string) $v;
    }
}
$headers['X-CSRF-Token'] = (string) ($server['HTTP_X_CSRF_TOKEN'] ?? '');
$headers['X-CSRF-Token'] = $headers['X-CSRF-Token'] !== '' ? $headers['X-CSRF-Token'] : (string) ($headers['X-Csrf-Token'] ?? '');
$origin = (string) ($server['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $headers['Origin'] = $origin;
}
$referer = (string) ($server['HTTP_REFERER'] ?? '');
if ($referer !== '') {
    $headers['Referer'] = $referer;
}
$forwardedProto = (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '');
if ($forwardedProto !== '') {
    $headers['X-Forwarded-Proto'] = $forwardedProto;
}

$env = [
    'MOSGOV_DEPLOYMENT' => 'public',
    'MOSGOV_TENANT_ID' => 'church-a',
    'MOSGOV_TENANT_DB' => getenv('MOSGOV_LAB_DB_A') ?: 'mosgov_lab_a',
    'MOSGOV_TENANT_HOST' => '127.0.0.1',
    'MOSGOV_TENANT_STORAGE' => getenv('MOSGOV_LAB_ROOT_A') ?: sys_get_temp_dir(),
    'MOSGOV_TRUSTED_PROXIES' => '127.0.0.1',
];
foreach ($env as $k => $v) {
    putenv($k . '=' . $v);
}

$trust = PublicTrustPolicy::authorityCheck($server, $headers);
$secureHeaders = PublicRequestPolicy::securityHeaders($trust['https'], true);

// Tenant/host binding must be enforced before any endpoint is dispatched.
$hostBare = strtolower(explode(':', (string) ($server['HTTP_HOST'] ?? ''))[0]);
try {
    $labTenant = TenantContext::fromEnvironment(null, $hostBare);
} catch (\Throwable $e) {
    http_response_code(403);
    foreach ($secureHeaders as $k => $v) {
        header($k . ': ' . $v);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'tenant/host mismatch, request refused']);
    return;
}

SessionHardening::apply(true, true);
session_start();

$path = parse_url((string) $server['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = strtoupper((string) $server['REQUEST_METHOD']);
$body = $method === 'POST' ? $_POST : [];

function respond(int $status, string $body, array $headers, bool $json = false): void
{
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    header('Content-Type: ' . ($json ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8'));
    header('Content-Length: ' . strlen($body));
    echo $body;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf'];
}

function authenticated(): bool
{
    return isset($_SESSION['user']) && $_SESSION['user'] === 'lab-admin';
}

// Synthetic in-memory account. Password is a lab-only throwaway value.
$users = ['lab-admin' => password_hash('LabAdmin!2026', PASSWORD_BCRYPT)];

try {
    if ($path === '/health') {
        $tenant = $labTenant;
        respond(200, json_encode([
            'status' => 'ok',
            'php' => PHP_VERSION,
            'tenant' => $tenant->id(),
            'https' => $trust['https'],
            'mode' => $trust['mode'],
        ], JSON_UNESCAPED_UNICODE), $secureHeaders, true);
        return;
    }

    if ($path === '/login' && $method === 'GET') {
        $token = csrfToken();
        respond(200, '<!doctype html><meta charset="utf-8"><form method="post" action="/login">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
            . '<input name="username"><input name="password" type="password"></form>', $secureHeaders);
        return;
    }

    if ($path === '/login' && $method === 'POST') {
        $csrf = PublicRequestPolicy::requireCsrf(
            $method, $body, (string) ($headers['X-Csrf-Token'] ?? ''),
            static fn (string $t): bool => hash_equals(csrfToken(), $t)
        );
        if (!$csrf['ok']) {
            respond(403, json_encode(['error' => 'CSRF rejected', 'reason' => $csrf['reason']]), $secureHeaders, true);
            return;
        }
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if (!isset($users[$username]) || !password_verify($password, $users[$username])) {
            respond(401, json_encode(['error' => 'invalid credentials']), $secureHeaders, true);
            return;
        }
        // Fixation defence: rotate the session id at the privilege boundary.
        SessionHardening::rotateAfterPrivilegeChange();
        $_SESSION['user'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(200, json_encode(['status' => 'logged-in', 'user' => $username]), $secureHeaders, true);
        return;
    }

    if ($path === '/logout' && $method === 'POST') {
        $csrf = PublicRequestPolicy::requireCsrf(
            $method, $body, (string) ($headers['X-Csrf-Token'] ?? ''),
            static fn (string $t): bool => hash_equals(csrfToken(), $t)
        );
        if (!$csrf['ok'] || !authenticated()) {
            respond(403, json_encode(['error' => 'logout refused']), $secureHeaders, true);
            return;
        }
        $_SESSION = [];
        session_destroy();
        respond(200, json_encode(['status' => 'logged-out']), $secureHeaders, true);
        return;
    }

    if (str_starts_with($path, '/api/')) {
        $evaluation = PublicRequestPolicy::evaluate([
            'method' => $method,
            'server' => $server,
            'headers' => $headers,
            'body' => $body,
            'authenticated' => authenticated(),
            'public' => true,
            'https' => $trust['https'],
            'csrf_verifier' => static fn (string $t): bool => hash_equals(csrfToken(), $t),
        ]);
        if (!$trust['ok']) {
            $evaluation['ok'] = false;
            $evaluation['status'] = 403;
        }
        if (!$evaluation['ok']) {
            respond($evaluation['status'], json_encode([
                'error' => 'request refused',
                'reasons' => $evaluation['reasons'],
            ], JSON_UNESCAPED_UNICODE), $evaluation['headers'], true);
            return;
        }
        $tenant = $labTenant;
        respond(200, json_encode([
            'status' => 'ok',
            'user' => $_SESSION['user'],
            'tenant' => $tenant->id(),
            'scope' => 'church-a-only',
            'method' => $method,
        ], JSON_UNESCAPED_UNICODE), $evaluation['headers'], true);
        return;
    }

    respond(404, json_encode(['error' => 'not found']), $secureHeaders, true);
} catch (\Throwable $e) {
    respond(500, json_encode(['error' => SafeError::publicMessage($e, 'lab-http')]), $secureHeaders, true);
}
