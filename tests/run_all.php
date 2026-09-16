<?php

/**
 * MOS-GOV V0.1 — run the whole test suite.
 *
 * Each suite is an independent CLI script (so it can also be run on its own).
 * This runner executes them in dependency order and aggregates the result.
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/run_all.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$suites = [
    'schema smoke (SQL file structure)' => 'schema_smoke.php',
    'table initialization (idempotent)' => 'init_tables.php',
    'Phase 2 integration regression' => 'integration_phase2.php',
    'Phase 3 data layer regression' => 'phase3_test.php',
    'V0.1 data layer (ten entities)' => 'v01_data_test.php',
    'V0.1 HTTP end-to-end' => 'v01_http_test.php',
    // V0.2
    'V0.2 migration (authorization model)' => 'v02_migrate.php',
    'V0.2 governance identity' => 'v02_identity_test.php',
    'V0.2 scope containment' => 'v02_scope_test.php',
    'V0.2 permission registry' => 'v02_permission_test.php',
    'V0.2 information visibility' => 'v02_visibility_test.php',
    'V0.2 authorization engine' => 'v02_authorization_test.php',
    'V0.2 security mode + scans' => 'v02_security_test.php',
    'V0.2 my governance + HTTP' => 'v02_my_governance_test.php',
];

$results = [];
$failed = 0;
$php = PHP_BINARY;

foreach ($suites as $label => $script) {
    $path = __DIR__ . '/' . $script;
    echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
    echo 'RUN  ' . $label . '  (' . $script . ')' . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;

    if (!file_exists($path)) {
        echo 'MISSING: ' . $path . PHP_EOL;
        $results[$label] = 'MISSING';
        $failed++;
        continue;
    }

    $output = [];
    $exitCode = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    echo implode(PHP_EOL, $output) . PHP_EOL;

    if ($exitCode === 0) {
        $results[$label] = 'PASS';
    } else {
        $results[$label] = 'FAIL (exit ' . $exitCode . ')';
        $failed++;
    }
}

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo 'MOS-GOV TEST SUITE SUMMARY (V0.1 + V0.2)' . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;
foreach ($results as $label => $result) {
    printf("%-40s %s%s", $label, $result, PHP_EOL);
}
echo str_repeat('-', 72) . PHP_EOL;
echo($failed === 0
    ? 'ALL ' . count($suites) . ' SUITES PASSED'
    : $failed . ' of ' . count($suites) . ' SUITES FAILED') . PHP_EOL;

exit($failed === 0 ? 0 : 1);
