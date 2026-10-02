<?php

/**
 * MOS-GOV V0.2 test library — shared bootstrap for the v02_* suites.
 *
 * Provides the check/section helpers, a GovRepository, an IdentityService,
 * and test-user resolution. Every suite creates its own governance rows and
 * removes them again, so repeated runs are safe.
 *
 * This file is included by the v02_* suites only (never by a web route).
 */

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\model\ChurchCRM\User;
use ChurchCRM\model\ChurchCRM\UserQuery;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugin\PluginManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';
PluginManager::init(SystemURLs::getDocumentRoot() . '/plugins');

$mosGovV2 = [
    'failures' => 0,
    'checks' => 0,
    'skips' => 0,
    'cleanup' => [], // [entity => [id, ...]]
];

$mosGovCheck = function (string $name, bool $ok, string $detail = '') use (&$mosGovV2): void {
    $mosGovV2['checks']++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' -- ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $mosGovV2['failures']++;
    }
};

$mosGovSkip = function (string $name, string $why) use (&$mosGovV2): void {
    echo '[SKIP] ' . $name . ' -- ' . $why . PHP_EOL;
    $mosGovV2['skips']++;
};

$mosGovSection = static function (string $name): void {
    echo PHP_EOL . '=== ' . $name . ' ===' . PHP_EOL;
};

$mosGovTrack = function (string $entity, int $id) use (&$mosGovV2): void {
    $mosGovV2['cleanup'][$entity] ??= [];
    $mosGovV2['cleanup'][$entity][] = $id;
};

$mosGovCleanup = function () use (&$mosGovV2): void {
    // children first, parents last (roughly reversed registry order).
    // FINAL REVIEW: 'visibility_rule' was missing from this list, so the row
    // tracked by v02_visibility_test was recorded but never deleted and every
    // run left one behind (all rows were status='inactive' test artifacts, so
    // the engine ignored them — but the suite must not accumulate rows).
    $order = [
        'identity_permission', 'identity_scope', 'identity_role', 'identity',
        'role_permission', 'role_scope', 'visibility_rule', 'permission', 'scope',
        'task', 'decision', 'issue', 'meeting', 'relationship',
        'responsibility', 'appointment', 'role', 'body', 'structure',
    ];
    $conn = Propel\Runtime\Propel::getConnection();
    foreach ($order as $entity) {
        $ids = $mosGovV2['cleanup'][$entity] ?? [];
        foreach ($ids as $id) {
            try {
                $stmt = $conn->prepare('DELETE FROM gov_' . str_replace('_', '_', $entity) . ' WHERE id = :id');
                $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
                $stmt->execute();
            } catch (\Throwable $e) {
                echo '[WARN] cleanup ' . $entity . '#' . $id . ': ' . $e->getMessage() . PHP_EOL;
            }
        }
    }
    echo '[CLEANUP] governance rows removed: ' . array_sum(array_map('count', $mosGovV2['cleanup'])) . PHP_EOL;
};

$mosGovFinish = function (string $suiteName) use (&$mosGovV2): void {
    echo PHP_EOL . $suiteName . ': ' . $mosGovV2['checks'] . ' checks, '
        . $mosGovV2['failures'] . ' FAIL, ' . $mosGovV2['skips'] . ' skip' . PHP_EOL;
    exit($mosGovV2['failures'] === 0 ? 0 : 1);
};

/**
 * Remove any governance identity left behind by an earlier crashed test run
 * for one person, so suites start from a clean identity state. Only test-run
 * leftovers are removed; the row is re-created by the suite itself.
 */
$mosGovResetIdentity = static function (int $personId): void {
    $conn = Propel\Runtime\Propel::getConnection();
    $stmt = $conn->prepare('DELETE ir, ip, isc FROM gov_identity i
        LEFT JOIN gov_identity_role ir ON ir.identity_id = i.id
        LEFT JOIN gov_identity_permission ip ON ip.identity_id = i.id
        LEFT JOIN gov_identity_scope isc ON isc.identity_id = i.id
        WHERE i.person_id = :pid');
    $stmt->bindValue(':pid', $personId, \PDO::PARAM_INT);
    $stmt->execute();
    $stmt = $conn->prepare('DELETE FROM gov_identity WHERE person_id = :pid');
    $stmt->bindValue(':pid', $personId, \PDO::PARAM_INT);
    $stmt->execute();
    \ChurchCRM\Plugins\MosGov\Security\GovAuthorization::reset();
};

/** Resolve a testable admin and a non-admin user (API key required). */
$mosGovUsers = static function (): array {
    $admin = null;
    foreach (UserQuery::create()->filterByAdmin(true)->find() as $candidate) {
        if (!empty($candidate->getApiKey())) {
            $admin = $candidate;
            break;
        }
    }
    $member = null;
    foreach (UserQuery::create()->filterByAdmin(false)->find() as $candidate) {
        if ($candidate->isEditSelfExclusive() || empty($candidate->getApiKey())) {
            continue;
        }
        $member = $candidate;
        break;
    }

    return [$admin, $member];
};
