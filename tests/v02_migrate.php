<?php

/**
 * MOS-GOV V0.2 — authorization model migration + verification.
 *
 * Executes database/002_v02_authorization.sql via the official
 * SQLUtils::sqlImport(), then verifies:
 *   - the nine V0.2 tables exist and are readable;
 *   - the V0.1 ten tables still exist untouched (row counts unchanged);
 *   - the permission whitelist seed and system role seed are present;
 *   - re-running (idempotency) does not duplicate seed rows.
 *
 * The migration is additive only: no DROP, no TRUNCATE, no DELETE.
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/v02_migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

use ChurchCRM\Utils\SQLUtils;
use Propel\Runtime\Propel;

$conn = Propel::getConnection();

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "[PASS] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "[FAIL] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . PHP_EOL;
    }
};

echo "=== MOS-GOV V0.2 migration ===" . PHP_EOL;

// V0.1 baseline before migration
$baseline = [];
foreach (['gov_structure', 'gov_body', 'gov_role', 'gov_appointment', 'gov_responsibility', 'gov_relationship', 'gov_meeting', 'gov_issue', 'gov_decision', 'gov_task'] as $t) {
    $baseline[$t] = (int) $conn->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
}

// ------------------------------------------------------------- import 002
$sql = file_get_contents(__DIR__ . '/../database/002_v02_authorization.sql');

// safety: additive statements only
$forbidden = [];
if (preg_match_all('/(DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM|ALTER\s+TABLE)/i', $sql, $mm)) {
    $forbidden = $mm[1];
}
$check('migration contains no destructive statements', $forbidden === [], implode(',', $forbidden));

SQLUtils::sqlImport(__DIR__ . '/../database/002_v02_authorization.sql', $conn);
$check('002_v02_authorization.sql imported without exception', true);

// ------------------------------------------------------------ new tables
$newTables = [
    'gov_identity',
    'gov_identity_role',
    'gov_scope',
    'gov_role_scope',
    'gov_identity_scope',
    'gov_permission',
    'gov_role_permission',
    'gov_identity_permission',
    'gov_visibility_rule',
];
foreach ($newTables as $t) {
    try {
        $count = (int) $conn->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $check("table {$t} exists and is readable", true, "rows={$count}");
    } catch (\Throwable $e) {
        $check("table {$t} exists and is readable", false, $e->getMessage());
    }
}

// -------------------------------------------------------- V0.1 preserved
$preserved = true;
$detail = '';
foreach ($baseline as $t => $countBefore) {
    $countAfter = (int) $conn->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    if ($countAfter < $countBefore) {
        $preserved = false;
        $detail .= "{$t}: {$countBefore} -> {$countAfter}; ";
    }
}
$check('V0.1 tables preserved (no rows lost)', $preserved, $detail);

// ------------------------------------------------------------- seed rows
$permCount = (int) $conn->query("SELECT COUNT(*) FROM gov_permission")->fetchColumn();
$check('permission whitelist seeded (>= 35 keys)', $permCount >= 35, "count={$permCount}");

$permUnknown = (int) $conn->query("SELECT COUNT(*) FROM gov_permission WHERE permission_key NOT IN ('governance.view','governance.create','governance.edit','governance.submit','governance.approve','governance.publish','governance.close','governance.export','governance.feedback','governance.manage','meeting.view','meeting.create','meeting.edit','meeting.submit','meeting.export','issue.view','issue.create','issue.edit','decision.view','decision.create','decision.edit','decision.approve','decision.publish','task.view','task.create','task.edit','task.close','identity.view','identity.edit','role.view','role.manage','appointment.view','appointment.create','appointment.end','permission.manage')")->fetchColumn();
$check('no permission keys outside the whitelist', $permUnknown === 0, "unknown={$permUnknown}");

$roleCount = (int) $conn->query("SELECT COUNT(*) FROM gov_role WHERE role_code IN ('A01','A02','B01','B02','B03','C01','D01','D02','D03','E01')")->fetchColumn();
$check('system roles A01..E01 seeded (10)', $roleCount === 10, "count={$roleCount}");

$rpCount = (int) $conn->query("SELECT COUNT(*) FROM gov_role_permission")->fetchColumn();
$check('default role permissions seeded', $rpCount > 0, "count={$rpCount}");

$p5Deny = (int) $conn->query("SELECT COUNT(*) FROM gov_visibility_rule WHERE information_level = 'P5' AND rule_type = 'deny' AND status = 'active'")->fetchColumn();
$p5Allow = (int) $conn->query("SELECT COUNT(*) FROM gov_visibility_rule WHERE information_level = 'P5' AND rule_type = 'allow' AND status = 'active' AND resource_type = '*' AND role_id IS NULL")->fetchColumn();
$check('P5 default DENY rules present', $p5Deny >= 1, "deny={$p5Deny}");
$check('no seeded P5 allow rule (explicit grants only)', $p5Allow === 0, "allow={$p5Allow}");

$scopeTypes = $conn->query("SELECT DISTINCT scope_type FROM gov_scope")->fetchAll(\PDO::FETCH_COLUMN);
$check('scope types all inside the whitelist', count(array_diff($scopeTypes, ['global', 'church', 'structure', 'body', 'ministry', 'group', 'activity', 'project', 'person'])) === 0, implode(',', $scopeTypes));

// ------------------------------------------------------------ idempotency
SQLUtils::sqlImport(__DIR__ . '/../database/002_v02_authorization.sql', $conn);
$permCount2 = (int) $conn->query("SELECT COUNT(*) FROM gov_permission")->fetchColumn();
$roleCount2 = (int) $conn->query("SELECT COUNT(*) FROM gov_role WHERE role_code IN ('A01','A02','B01','B02','B03','C01','D01','D02','D03','E01')")->fetchColumn();
$check('re-run does not duplicate permission seeds', $permCount2 === $permCount, "{$permCount} -> {$permCount2}");
$check('re-run does not duplicate role seeds', $roleCount2 === $roleCount, "{$roleCount} -> {$roleCount2}");

echo PHP_EOL . "result: {$pass} PASS, {$fail} FAIL" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
