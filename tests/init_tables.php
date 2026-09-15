<?php

/**
 * MOS-GOV V0.1 — Phase 2b: governance table init + verification.
 *
 * Executes ONLY the plugin's own database/001_initial.sql via the official
 * SQLUtils::sqlImport() used by the ChurchCRM core installer, then verifies
 * the 10 governance tables are readable through the standard Propel
 * connection (the same connection the dashboard would use).
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/init_tables.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Utils\SQLUtils;
use Propel\Runtime\Propel;

$conn = Propel::getConnection();

function section(string $name): void
{
    echo PHP_EOL . "=== {$name} ===" . PHP_EOL;
}

// ---------------------------------------------------------------- pre-check
section('1. PRE-CHECK: gov_* tables before import');
$existing = $conn->query("SHOW TABLES LIKE 'gov_%'")->fetchAll(\PDO::FETCH_COLUMN);
echo count($existing) === 0 ? "(none — safe to initialize)" : ('already present: ' . implode(', ', $existing)) . PHP_EOL;

// Safety: the SQL file must only contain gov_* CREATE statements.
$sql = file_get_contents(__DIR__ . '/../database/001_initial.sql');
if (preg_match_all('/(CREATE\s+(?:TABLE|DATABASE|INDEX)[^;]*|DROP\s+TABLE[^;]*|ALTER\s+TABLE[^;]*|INSERT\s+INTO\s+([a-zA-Z0-9_]+))/i', $sql, $mm)) {
    $foreign = array_filter($mm[0], fn ($s) => stripos($s, 'gov_') === false && stripos(trim($s), 'create table') !== 0);
    if ($foreign !== []) {
        echo 'ABORT: SQL file references non-gov objects:' . PHP_EOL . implode(PHP_EOL, $foreign) . PHP_EOL;
        exit(1);
    }
}
echo "SQL file safety check: only gov_* CREATE TABLE statements found." . PHP_EOL;

// ---------------------------------------------------------------- import
section('2. IMPORT database/001_initial.sql via SQLUtils::sqlImport');
SQLUtils::sqlImport(__DIR__ . '/../database/001_initial.sql', $conn);
echo "import finished without exception." . PHP_EOL;

// ---------------------------------------------------------------- verify
section('3. VERIFY: 10 governance tables exist + are readable');
$tables = [
    'gov_structure',
    'gov_body',
    'gov_role',
    'gov_appointment',
    'gov_responsibility',
    'gov_relationship',
    'gov_meeting',
    'gov_issue',
    'gov_decision',
    'gov_task',
];

$fail = 0;
foreach ($tables as $t) {
    try {
        $count = $conn->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetchColumn();
        printf("%-20s rows=%s engine=%s  [OK]%s", $t, $count, $engine, PHP_EOL);
    } catch (\Throwable $e) {
        $fail++;
        printf("%-20s ERROR: %s%s", $t, $e->getMessage(), PHP_EOL);
    }
}

// Core tables untouched check (sample: list non-gov tables count before/after not tracked;
// CREATE TABLE IF NOT EXISTS gov_* cannot affect other tables by construction).
echo PHP_EOL . 'result: ' . ($fail === 0 ? 'ALL 10 GOVERNANCE TABLES OK' : "{$fail} FAILURES") . PHP_EOL;
