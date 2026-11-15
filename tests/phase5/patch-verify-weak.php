<?php
/**
 * MOS-GOV Phase 5 — weak-typing / coercion probe (real PHP execution).
 *
 * Deliberately does NOT declare strict_types=1, matching ChurchCRM 7.8.0's own
 * (non-strict) GroupService.php. Under PHP's weak scalar typing a bool/int/float
 * passed to `string $groupID` is coerced to a string BEFORE the method body runs,
 * so the guard can no longer tell what the original type was.
 *
 * This suite does not claim a pass/fail on that PHP-language behaviour; it
 * records it and asserts the security-relevant property: every accepted value
 * still yields a pure bounded integer, never an injectable SQL fragment.
 *
 * Usage: php -c <ini> patch-verify-weak.php <GroupService.php>
 */
require_once __DIR__ . '/bootstrap.php';

$svcFile = $argv[1] ?? null;
if (!$svcFile || !is_file($svcFile)) { fwrite(STDERR, "usage: patch-verify-weak.php <GroupService.php>\n"); exit(2); }

$cn = p5_connect(
    getenv('MOSGOV_P5_DB_HOST') ?: '127.0.0.1',
    (int)(getenv('MOSGOV_P5_DB_PORT') ?: 3407),
    getenv('MOSGOV_P5_DB_USER') ?: 'root',
    getenv('MOSGOV_P5_DB_PASS') ?: '',
    getenv('MOSGOV_P5_PATCH_DB') ?: 'mosgov_p5_patch'
);
$svc = p5_load_group_service($svcFile);
$a = new P5Assert();

echo "=== WEAK-TYPE COERCION PROBE — real PHP " . PHP_VERSION . " (no strict_types) ===\n\n";

$cases = [
    'string-1'   => '1',
    'int-12'     => 12,
    'float-1.0'  => 1.0,
    'bool-true'  => true,
    'bool-false' => false,
    'float-1.5'  => 1.5,
    'null'       => null,
];

foreach ($cases as $label => $value) {
    // clean dynamic tables before each probe
    $r = mysqli_query($cn, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^groupprop_[0-9]+$'");
    $names = [];
    while ($row = mysqli_fetch_row($r)) { $names[] = $row[0]; }
    foreach ($names as $t) { mysqli_query($cn, 'DROP TABLE IF EXISTS `' . $t . '`'); }

    p5_reset_sql_log();
    try {
        $svc->enableGroupSpecificProperties($value);
        $sql = implode(' | ', p5_sql_log());
        preg_match_all('/groupprop_([0-9]+|\b)/', $sql, $m);
        $pureInt = (bool)preg_match('/grp_ID\s*=\s*[0-9]+\b/', $sql)
            && !preg_match('/grp_ID\s*=\s*["\'][^0-9]/', $sql);
        $accepted = true;
        $detail = 'ACCEPTED (coerced); SQL=' . count(p5_sql_log()) . '; pure-integer-ID=' . ($pureInt ? 'yes' : 'NO');
        $a->ok('WK-' . $label, $pureInt, $detail);
    } catch (\Throwable $e) {
        $accepted = false;
        $a->ok('WK-' . $label, count(p5_sql_log()) === 0,
            'REJECTED (' . get_class($e) . '); SQL=' . count(p5_sql_log()));
    }
    printf("       value=%s accepted=%s\n", var_export($value, true), $accepted ? 'yes' : 'no');
}

echo "\nNOTE: bool/int/float coercion to a digit string is a PHP language behaviour,\n"
   . "not an SQL injection. Public Slim routes supply strings constrained by\n"
   . "{groupID:[0-9]+}. Residual risk and recommended follow-up are recorded in\n"
   . "the Phase 5 security report.\n";

exit($a->summary('patch-verify-weak'));
