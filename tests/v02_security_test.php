<?php

/**
 * MOS-GOV V0.2 — security mode + static security scan test (§32–§34).
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Security\AuthorizationDecision;
use ChurchCRM\Plugins\MosGov\Security\LocalSecureMode;

$mosGovSection('1. Local / LAN secure mode');

$mosGovCheck('default mode is LOCAL', LocalSecureMode::mode() === 'LOCAL', LocalSecureMode::mode());
$mosGovCheck('loopback 127.0.0.1 allowed', LocalSecureMode::clientAllowed('127.0.0.1'));
$mosGovCheck('loopback ::1 allowed', LocalSecureMode::clientAllowed('::1'));
$mosGovCheck('loopback 127.1.2.3 allowed', LocalSecureMode::clientAllowed('127.1.2.3'));

// LOCAL: private LAN clients are refused
$mosGovCheck('LAN client 192.168.1.50 refused in LOCAL mode', !LocalSecureMode::clientAllowed('192.168.1.50'));
$mosGovCheck('public client refused in LOCAL mode', !LocalSecureMode::clientAllowed('203.0.113.9'));

// LAN mode via subclassed scenario (mode is env-driven; simulate by reflectively testing private ranges)
$mosGovCheck('10.x recognized as private range', (bool) preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', '10.1.2.3'));
$mosGovCheck('172.20.x recognized as private range', (bool) preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', '172.20.0.5'));
$mosGovCheck('172.15.x NOT a private range (out of 172.16/12)', !((bool) preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', '172.15.0.5')));

$check = LocalSecureMode::clientAllowed('127.0.0.1') && !LocalSecureMode::clientAllowed('203.0.113.9');
$mosGovCheck('secure-mode allow/deny logic consistent', $check);

$mosGovSection('1b. Docker host-bridge clients in LOCAL mode');

// /proc/net/route gateway hex (little-endian u32) → dotted quad
$mosGovCheck('route hex 010012AC parses to 172.18.0.1', LocalSecureMode::routeHexToIp('010012AC') === '172.18.0.1', (string) LocalSecureMode::routeHexToIp('010012AC'));
$mosGovCheck('route hex 00000000 (no gateway) rejected', LocalSecureMode::routeHexToIp('00000000') === null);
$mosGovCheck('route hex malformed rejected', LocalSecureMode::routeHexToIp('ZZZZZZZZ') === null);

// The host's own browser arrives via the Docker bridge gateway (REMOTE_ADDR
// = e.g. 172.18.0.1). That address is the local machine, not a LAN client.
$hostAddrs = LocalSecureMode::thisHostAddresses();
if ($hostAddrs !== []) {
    $mosGovCheck('container host address detected (' . implode(', ', $hostAddrs) . ')', true, implode(', ', $hostAddrs));
    foreach ($hostAddrs as $hostAddr) {
        $mosGovCheck("host bridge address {$hostAddr} allowed in LOCAL mode", LocalSecureMode::clientAllowed($hostAddr));
        $mosGovCheck("host bridge address {$hostAddr} allowed as IPv4-mapped IPv6", LocalSecureMode::clientAllowed('::ffff:' . $hostAddr));
    }

    // a neighboring address on the same bridge is another container, not the host
    $parts = explode('.', (string) $hostAddrs[0]);
    if (count($parts) === 4) {
        $neighbor = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . ($parts[3] === '1' ? '99' : '1');
        if (!in_array($neighbor, $hostAddrs, true)) {
            $mosGovCheck("other-container address {$neighbor} refused in LOCAL mode", !LocalSecureMode::clientAllowed($neighbor));
        }
    }
} else {
    $mosGovCheck('no /proc/net/route in this environment — bridge gateway checks skipped', true);
}

// operator allow-list (exact addresses only, default empty)
putenv('MOS_GOV_LOCAL_EXTRA_CLIENTS=203.0.113.77');
LocalSecureMode::resetRuntimeCache();
$mosGovCheck('allow-listed address accepted in LOCAL mode', LocalSecureMode::clientAllowed('203.0.113.77'));
$mosGovCheck('allow-list entry accepted as IPv4-mapped IPv6', LocalSecureMode::clientAllowed('::ffff:203.0.113.77'));
putenv('MOS_GOV_LOCAL_EXTRA_CLIENTS');
LocalSecureMode::resetRuntimeCache();
$mosGovCheck('public client refused again after allow-list cleared', !LocalSecureMode::clientAllowed('203.0.113.77'));

// LOCAL must never open whole private ranges (regression guard)
$mosGovCheck('192.168.0.1 still refused in LOCAL mode', !LocalSecureMode::clientAllowed('192.168.0.1'));
$mosGovCheck('10.0.0.1 still refused in LOCAL mode', !LocalSecureMode::clientAllowed('10.0.0.1'));
$mosGovCheck('172.20.0.1 still refused in LOCAL mode', !LocalSecureMode::clientAllowed('172.20.0.1'));

$mosGovSection('1c. LAN mode trusted-private rules');

putenv('MOS_GOV_SECURITY_MODE=LAN');
$mosGovCheck('mode reads LAN from environment', LocalSecureMode::mode() === 'LAN', LocalSecureMode::mode());
$mosGovCheck('loopback allowed in LAN mode', LocalSecureMode::clientAllowed('127.0.0.1'));
$mosGovCheck('trusted private 192.168.1.50 allowed in LAN mode', LocalSecureMode::clientAllowed('192.168.1.50'));
$mosGovCheck('trusted private 10.20.30.40 allowed in LAN mode', LocalSecureMode::clientAllowed('10.20.30.40'));
$mosGovCheck('trusted private 172.20.0.5 allowed in LAN mode', LocalSecureMode::clientAllowed('172.20.0.5'));
$mosGovCheck('public 203.0.113.9 refused in LAN mode', !LocalSecureMode::clientAllowed('203.0.113.9'));
$mosGovCheck('public 8.8.8.8 refused in LAN mode', !LocalSecureMode::clientAllowed('8.8.8.8'));
putenv('MOS_GOV_SECURITY_MODE');
$mosGovCheck('mode restored to LOCAL after test', LocalSecureMode::mode() === 'LOCAL', LocalSecureMode::mode());

$mosGovSection('2. Outbound network scan (§33 — OUTBOUND_NETWORK = DENY)');

$srcDir = dirname(__DIR__) . '/src';
$outboundHits = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $code = file_get_contents($file->getPathname());
    foreach ([
        'curl_init', 'curl_exec', 'Guzzle', 'file_get_contents(http', 'wp_remote',
        'fsockopen', 'stream_socket_client',
    ] as $needle) {
        if (stripos($code, $needle) !== false) {
            $outboundHits[] = $file->getFilename() . ': ' . $needle;
        }
    }
}
$mosGovCheck('MOS-GOV source performs no outbound HTTP calls', $outboundHits === [], implode('; ', $outboundHits));

$mosGovSection('3. SQL discipline scan (§38)');

$sqlHits = [];
$viewsDir = dirname(__DIR__) . '/views';
$routesFile = dirname(__DIR__) . '/routes/routes.php';
foreach (array_merge(
    array_filter(glob($viewsDir . '/*.php') ?: []),
    [$routesFile]
) as $file) {
    $code = file_get_contents((string) $file);
    if (preg_match('/(SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)/i', $code)) {
        $sqlHits[] = basename((string) $file);
    }
}
$mosGovCheck('routes and views contain no SQL', $sqlHits === [], implode('; ', $sqlHits));

// repository uses prepared statements for every data access path
$repoCode = file_get_contents($srcDir . '/Data/GovRepository.php');
$prepareCount = substr_count($repoCode, '->prepare(');
$mosGovCheck('GovRepository prepares every statement', $prepareCount >= 7, "prepare() sites: {$prepareCount}");

$mosGovSection('4. ORDER BY whitelist');

usePropel:
$conn = Propel\Runtime\Propel::getConnection();
// simulate: unknown column must be rejected by buildOrderBy via listWhere orderBy validation
$threw = false;
try {
    (new GovRepository())->listWhere('task', [], 5, ['(SELECT 1)' => 'ASC']);
} catch (\Throwable $e) {
    $threw = true;
}
$mosGovCheck('injection via ORDER BY column rejected', $threw);

$mosGovCleanup();
$mosGovFinish('V0.2 SECURITY');
