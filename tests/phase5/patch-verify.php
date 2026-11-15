<?php
/**
 * MOS-GOV Phase 5 — REAL GroupService patch verification.
 *
 * Executes the actual patched ChurchCRM 7.8.0 GroupService.php methods with
 * real PHP 8.4 and real MariaDB 11.8.9. Every generated SQL statement is
 * executed against the isolated 127.0.0.1:3407 instance.
 *
 * Usage:
 *   php -c <ini> patch-verify.php <path-to-GroupService.php>
 *
 * Exit 0 = all assertions pass, 1 = at least one failure.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$svcFile = $argv[1] ?? null;
if (!$svcFile || !is_file($svcFile)) {
    fwrite(STDERR, "usage: patch-verify.php <GroupService.php>\n");
    exit(2);
}

$host = getenv('MOSGOV_P5_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MOSGOV_P5_DB_PORT') ?: 3407);
$user = getenv('MOSGOV_P5_DB_USER') ?: 'root';
$pass = getenv('MOSGOV_P5_DB_PASS') ?: '';
$db   = getenv('MOSGOV_P5_PATCH_DB') ?: 'mosgov_p5_patch';

$cn = p5_connect($host, $port, $user, $pass, $db);
$a = new P5Assert();

/** Restore synthetic fixtures before each case (real SQL, not counted in the guard log). */
function resetFixtures(\mysqli $cn): void
{
    // Drop EVERY dynamic groupprop_* table, including any rogue table a
    // previous (failing) case may have created. This keeps each case isolated.
    // Only numeric-suffixed dynamic tables. groupprop_master is a real
    // ChurchCRM table and must NOT be dropped by the cleanup.
    $r = mysqli_query($cn, "SELECT TABLE_NAME FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^groupprop_[0-9]+$'");
    $names = [];
    while ($row = mysqli_fetch_row($r)) { $names[] = $row[0]; }
    foreach ($names as $t) {
        mysqli_query($cn, 'DROP TABLE IF EXISTS `' . $t . '`');
    }
    $stmts = [
        'DELETE FROM list_lst',
        'DELETE FROM group_grp',
        'DELETE FROM person2group2role_p2g2r',
        'DELETE FROM groupprop_master',
        "INSERT INTO group_grp (grp_ID,grp_RoleListID,grp_DefaultRole,grp_hasSpecialProps) VALUES (12,12,1,0),(1,1,1,0)",
        "INSERT INTO list_lst (lst_ID,lst_OptionID,lst_OptionName,lst_OptionSequence) VALUES (12,1,'SYNTHETIC Member',1),(12,2,'SYNTHETIC Leader',2),(12,3,'SYNTHETIC Helper',3),(1,1,'SYNTHETIC Member',1)",
        "INSERT INTO person2group2role_p2g2r (p2g2r_per_ID,p2g2r_grp_ID,p2g2r_rle_ID) VALUES (9001,12,2),(9002,12,3)",
        "INSERT INTO groupprop_master (grp_ID,prop_Field) VALUES (12,'SYNTHETIC'),(1,'SYNTHETIC')",
    ];
    foreach ($stmts as $s) {
        if (!mysqli_query($cn, $s)) {
            throw new \RuntimeException('FIXTURE_RESET_FAILED: ' . mysqli_error($cn) . ' | ' . $s);
        }
    }
}

$svc = p5_load_group_service($svcFile);

echo "=== PATCH VERIFICATION — real PHP " . PHP_VERSION . " / real MariaDB ===\n";
echo "target: " . basename($svcFile) . "\n";
echo "db: {$user}@{$host}:{$port}/{$db}\n\n";

/* ------------------------------------------------------------------ *
 * SECTION 1 — syntax is checked separately by php -l (run-all.sh).
 *             Here we confirm the class actually loads and runs.
 * ------------------------------------------------------------------ */
$a->ok('PV-00 class loads', $svc instanceof \ChurchCRM\Service\GroupService, get_class($svc));

/* ------------------------------------------------------------------ *
 * SECTION 2 — valid positive integer inputs (real business behaviour)
 * ------------------------------------------------------------------ */
echo "--- SECTION 2: valid positive integers ---\n";

resetFixtures($cn); p5_reset_sql_log();
try {
    $roles = $svc->deleteGroupRole('12', '2');
    $log = p5_sql_log();
    $a->ok('PV-01 deleteGroupRole(12,2) accepted', is_array($roles), 'returned ' . count($roles) . ' roles');
    $a->ok('PV-02 real SQL executed', count($log) >= 8, count($log) . ' statements executed on MariaDB');
    $r = mysqli_query($cn, 'SELECT COUNT(*) c FROM list_lst WHERE lst_ID=12');
    $c = (int)mysqli_fetch_assoc($r)['c'];
    $a->ok('PV-03 role really deleted in DB', $c === 2, "remaining roles for group 12 = {$c} (expected 2)");
    $r = mysqli_query($cn, 'SELECT COUNT(*) c FROM person2group2role_p2g2r WHERE p2g2r_grp_ID=12 AND p2g2r_rle_ID=1');
    $c = (int)mysqli_fetch_assoc($r)['c'];
    $a->ok('PV-04 member role reset in DB', $c === 1, "members reset to default role = {$c}");
    $joined = implode("\n", $log);
    $a->ok('PV-05 all IDs numeric in SQL', !preg_match('/grp_ID\s*=\s*["\'][^0-9]/', $joined), 'no non-numeric grp_ID concatenation');
} catch (\Throwable $e) {
    $a->ok('PV-01 deleteGroupRole(12,2) accepted', false, 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage());
}

resetFixtures($cn); p5_reset_sql_log();
try {
    $svc->enableGroupSpecificProperties('1');
    $r = mysqli_query($cn, "SHOW TABLES LIKE 'groupprop_1'");
    $exists = mysqli_num_rows($r) === 1;
    $r2 = mysqli_query($cn, 'SELECT grp_hasSpecialProps v FROM group_grp WHERE grp_ID=1');
    $flag = (int)mysqli_fetch_assoc($r2)['v'];
    $a->ok('PV-06 enable(1) creates real table', $exists, 'SHOW TABLES LIKE groupprop_1');
    $a->ok('PV-07 enable(1) sets flag in DB', $flag === 1, "grp_hasSpecialProps={$flag}");
    $a->ok('PV-08 enable(1) executed SQL', count(p5_sql_log()) >= 2, count(p5_sql_log()) . ' statements');
} catch (\Throwable $e) {
    $a->ok('PV-06 enable(1) creates real table', false, 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage());
}

// disable on the SAME real table just created above
p5_reset_sql_log();
try {
    $svc->disableGroupSpecificProperties('1');
    $r = mysqli_query($cn, "SHOW TABLES LIKE 'groupprop_1'");
    $gone = mysqli_num_rows($r) === 0;
    $a->ok('PV-09 disable(1) drops real table', $gone, 'table groupprop_1 removed');
    $a->ok('PV-10 disable(1) executed SQL', count(p5_sql_log()) === 3, count(p5_sql_log()) . ' statements');
} catch (\Throwable $e) {
    $a->ok('PV-09 disable(1) drops real table', false, 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage());
}

// upstream business behaviour must be preserved: cannot delete the only role
resetFixtures($cn); p5_reset_sql_log();
try {
    $svc->deleteGroupRole('1', '1');
    $a->ok('PV-11 only-role guard preserved', false, 'no exception raised');
} catch (\Exception $e) {
    $a->ok('PV-11 only-role guard preserved', str_contains($e->getMessage(), 'only group'), $e->getMessage());
}

/* ------------------------------------------------------------------ *
 * SECTION 3 — invalid / hostile inputs must be rejected BEFORE any SQL
 * ------------------------------------------------------------------ */
echo "\n--- SECTION 3: invalid inputs (guard must fire before SQL) ---\n";

$invalid = [
    'zero'            => '0',
    'negative'        => '-3',
    'empty'           => '',
    'non-numeric'     => 'abc',
    'sql-or'          => '1 OR 1=1',
    'sql-stacked'     => '1; DROP TABLE group_grp',
    'sql-quote'       => "1' OR '1'='1",
    'sql-comment'     => '1/*x*/',
    'plus-sign'       => '+1',
    'scientific'      => '1e3',
    'leading-space'   => ' 1',
    'trailing-space'  => '1 ',
    'float-string'    => '1.5',
    'hex-string'      => '0x31',
    'huge-integer'    => '999999999999999999999999999999',
    'max-plus-one'    => '9223372036854775808',
];

$methods = [
    'deleteGroupRole'               => fn($svc, $v) => $svc->deleteGroupRole($v, '2'),
    'enableGroupSpecificProperties' => fn($svc, $v) => $svc->enableGroupSpecificProperties($v),
    'disableGroupSpecificProperties'=> fn($svc, $v) => $svc->disableGroupSpecificProperties($v),
];

foreach ($methods as $mName => $call) {
    foreach ($invalid as $label => $value) {
        resetFixtures($cn); p5_reset_sql_log();
        $id = 'PV-I-' . $mName . '-' . $label;
        try {
            $call($svc, $value);
            $a->ok($id, false, 'NOT REJECTED (value=' . var_export($value, true) . ') SQL=' . count(p5_sql_log()));
        } catch (\InvalidArgumentException $e) {
            $noSql = count(p5_sql_log()) === 0;
            $a->ok($id, $noSql, 'rejected by guard; SQL statements executed=' . count(p5_sql_log()));
        } catch (\Throwable $e) {
            // TypeError from the string parameter declaration is also a safe rejection
            $noSql = count(p5_sql_log()) === 0;
            $a->ok($id, $noSql, 'rejected by type system (' . get_class($e) . '); SQL=' . count(p5_sql_log()));
        }
    }
}

/* ------------------------------------------------------------------ *
 * SECTION 4 — abnormal PHP types
 * ------------------------------------------------------------------ */
echo "\n--- SECTION 4: abnormal PHP types ---\n";

$abnormal = [
    'float-1.5'   => 1.5,
    'bool-true'   => true,
    'bool-false'  => false,
    'null'        => null,
    'array'       => [12],
    'object'      => new \stdClass(),
];
foreach ($methods as $mName => $call) {
    foreach ($abnormal as $label => $value) {
        resetFixtures($cn); p5_reset_sql_log();
        $id = 'PV-T-' . $mName . '-' . $label;
        try {
            $call($svc, $value);
            $a->ok($id, false, 'ACCEPTED abnormal type; SQL=' . count(p5_sql_log()));
        } catch (\Throwable $e) {
            $a->ok($id, count(p5_sql_log()) === 0, 'rejected (' . get_class($e) . '); SQL=' . count(p5_sql_log()));
        }
    }
}

/* ------------------------------------------------------------------ *
 * SECTION 5 — injection did not damage the database
 * ------------------------------------------------------------------ */
echo "\n--- SECTION 5: post-injection DB integrity ---\n";
resetFixtures($cn);
foreach (['1; DROP TABLE group_grp', "1' OR '1'='1", '1 OR 1=1'] as $payload) {
    p5_reset_sql_log();
    try { $svc->deleteGroupRole($payload, '2'); } catch (\Throwable $e) {}
    try { $svc->enableGroupSpecificProperties($payload); } catch (\Throwable $e) {}
}
$r = mysqli_query($cn, "SHOW TABLES LIKE 'group_grp'");
$a->ok('PV-S-01 group_grp survives injection', mysqli_num_rows($r) === 1, 'table still present');
$r = mysqli_query($cn, 'SELECT COUNT(*) c FROM group_grp');
$a->ok('PV-S-02 no rows deleted by injection', (int)mysqli_fetch_assoc($r)['c'] === 2, 'row count = ' . mysqli_fetch_assoc(mysqli_query($cn, 'SELECT COUNT(*) c FROM group_grp'))['c']);
// Only count numeric-suffixed dynamic tables; groupprop_master is legitimate.
$cond = "TABLE_SCHEMA='{$db}' AND TABLE_NAME REGEXP '^groupprop_[0-9]+$'";
$r = mysqli_query($cn, "SELECT COUNT(*) c FROM information_schema.TABLES WHERE {$cond}");
$n = (int)mysqli_fetch_assoc($r)['c'];
$a->ok('PV-S-03 no rogue numeric tables created', $n === 0, "groupprop_<digits> tables = {$n} (expected 0)");

exit($a->summary('patch-verify'));
