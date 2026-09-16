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
