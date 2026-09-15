<?php

/**
 * MOS-GOV V0.1 — Phase 3 data-layer test.
 *
 * Uses the full ChurchCRM bootstrap (same pattern as cli/timerjobs.php).
 * Exercises GovRepository against the real database, then cleans up all
 * created rows so the test is idempotent.
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/phase3_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugin\PluginManager;

// Register plugin autoloaders (mos-gov is enabled, so loadPlugin registers
// the ChurchCRM\Plugins\MosGov\ PSR-4 autoloader used by GovRepository).
PluginManager::init(SystemURLs::getDocumentRoot() . '/plugins');

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' -- ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

$repo = new GovRepository();

// ------------------------------------------------------------ 1. counts (baseline)
$before = $repo->getDashboardCounts();
echo 'baseline counts: ' . json_encode($before) . PHP_EOL;
$check('dashboard counts shape', isset($before['structures'], $before['bodies'], $before['open_issues'], $before['open_tasks']));

// ------------------------------------------------------------ 2. validation
$check('empty name rejected', $repo->validate('structure', ['name' => '', 'status' => 'active']) !== []);
$check('html in name rejected', $repo->validate('structure', ['name' => '<b>x</b>', 'status' => 'active']) !== []);
$check('bad status rejected', $repo->validate('structure', ['name' => 'X', 'status' => 'hacked']) !== []);
$check('valid structure accepted', $repo->validate('structure', ['name' => 'OK', 'status' => 'active']) === []);
$check('negative int rejected', $repo->validate('appointment', ['role_id' => 1, 'person_id' => -3, 'status' => 'active']) !== []);
$check('bad date rejected', $repo->validate('appointment', ['role_id' => 1, 'person_id' => 1, 'status' => 'active', 'start_date' => '2026-13-45']) !== []);
$check('end before start rejected', $repo->validate('appointment', ['role_id' => 1, 'person_id' => 1, 'status' => 'active', 'start_date' => '2026-01-10', 'end_date' => '2026-01-01']) !== []);
$check('nonexistent ref rejected', $repo->validate('body', ['structure_id' => 99999999, 'name' => 'X', 'status' => 'active']) !== []);
$check('missing required ref rejected', $repo->validate('body', ['name' => 'X', 'status' => 'active']) !== []);

// ------------------------------------------------------------ 3. CRUD lifecycle
$created = ['structure' => [], 'body' => [], 'role' => [], 'appointment' => []];
try {
    $s1 = $repo->insert('structure', ['name' => 'P3 Test Structure', 'code' => 'P3S', 'description' => 'phase3 test', 'status' => 'active', 'sort_order' => 1]);
    $created['structure'][] = $s1;
    $check('structure inserted', $s1 > 0, 'id=' . $s1);

    $row = $repo->find('structure', $s1);
    $check('structure found', $row !== null && $row['name'] === 'P3 Test Structure' && $row['code'] === 'P3S');

    $s2 = $repo->insert('structure', ['name' => 'P3 Test Child', 'status' => 'active', 'parent_id' => $s1]);
    $created['structure'][] = $s2;
    $check('child structure inserted', $s2 > 0);

    $b1 = $repo->insert('body', ['structure_id' => $s1, 'name' => 'P3 Test Body', 'body_type' => 'council', 'status' => 'active']);
    $created['body'][] = $b1;
    $check('body inserted', $b1 > 0);

    $r1 = $repo->insert('role', ['body_id' => $b1, 'name' => 'P3 Test Role', 'role_code' => 'P3R', 'status' => 'active']);
    $created['role'][] = $r1;
    $check('role inserted', $r1 > 0);

    $a1 = $repo->insert('appointment', ['role_id' => $r1, 'person_id' => 1, 'status' => 'active', 'start_date' => '2026-01-01']);
    $created['appointment'][] = $a1;
    $check('appointment inserted', $a1 > 0);

    // validation-based rejection at data layer
    try {
        $repo->insert('body', ['structure_id' => 99999999, 'name' => 'Bad', 'status' => 'active']);
        $check('invalid insert rejected', false, 'no exception thrown');
    } catch (GovDataException $e) {
        $check('invalid insert rejected', isset($e->getErrors()['structure_id']));
    }

    // update
    $repo->update('structure', $s1, ['name' => 'P3 Test Structure Renamed', 'code' => 'P3S2', 'description' => 'renamed', 'status' => 'inactive', 'sort_order' => 2]);
    $row = $repo->find('structure', $s1);
    $check('structure updated', $row !== null && $row['name'] === 'P3 Test Structure Renamed' && $row['status'] === 'inactive');

    // counts after
    $after = $repo->getDashboardCounts();
    $check('counts increased correctly',
        $after['structures'] === $before['structures'] + 2
        && $after['bodies'] === $before['bodies'] + 1
        && $after['open_issues'] === $before['open_issues']
        && $after['open_tasks'] === $before['open_tasks'],
        'after=' . json_encode($after));

    // list contains created rows
    $list = $repo->list('structure');
    $ids = array_map(fn ($r) => (int) $r['id'], $list);
    $check('list contains created structures', in_array($s1, $ids, true) && in_array($s2, $ids, true));

    // ref options contain parents
    $refCfg = GovRepository::ENTITIES['body'];
    $opts = [];
    foreach ($repo->list('structure', 1000) as $r) {
        $opts[(int) $r['id']] = $r['name'];
    }
    $check('ref options include parent', isset($opts[$s1]));
} catch (\Throwable $e) {
    $check('CRUD lifecycle completed without unexpected error', false, get_class($e) . ': ' . $e->getMessage());
}

// ------------------------------------------------------------ 4. cleanup
foreach (['appointment', 'role', 'body', 'structure'] as $entity) {
    foreach ($created[$entity] as $id) {
        try {
            $repo->delete($entity, (int) $id);
        } catch (\Throwable $e) {
            $check('cleanup ' . $entity . '#' . $id, false, $e->getMessage());
        }
    }
}
$final = $repo->getDashboardCounts();
$check('cleanup restored baseline counts',
    $final['structures'] === $before['structures'] && $final['bodies'] === $before['bodies'],
    'final=' . json_encode($final));

echo PHP_EOL . ($failures === 0 ? 'PHASE3 DATA-LAYER: ALL TESTS PASSED' : "PHASE3 DATA-LAYER: {$failures} FAILURES") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
