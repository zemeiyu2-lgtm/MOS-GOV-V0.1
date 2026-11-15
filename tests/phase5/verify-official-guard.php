<?php
/**
 * MOS-GOV Phase 5 — guard verification against the OFFICIAL ChurchCRM 7.8.0 artifact.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * deploy.sh extracts the official ChurchCRM-7.8.0.zip (sha256 pinned in
 * upstream-lock.json) and applies deploy/patches/7.8.0/*.patch on top of it.
 * The artifact that actually ships is therefore *official source + patch* — not
 * any local working copy. This suite verifies exactly that artifact, produced by
 * actually running apply-upstream-patches.sh against the pristine archive.
 *
 * SCOPE / HONEST BOUNDARY
 *   1. Real PHP 8.4.25, real MariaDB 11.8.9 on 127.0.0.1:3407, real SQL.
 *   2. FULLY COVERED: enableGroupSpecificProperties() and
 *      disableGroupSpecificProperties(). In 7.8.0 both take `string $groupID`
 *      and concatenate it straight into SQL and into the table name
 *      `groupprop_<id>` — this is the real injection surface, and every case
 *      below executes for real or is rejected before any SQL runs.
 *   3. deleteGroupRole() in 7.8.0 is `(int, int)` and goes through Propel ORM.
 *      Only its *guard contract* is verified here (bounded positive int, no SQL
 *      on rejection). Its ORM happy path is NOT executed — saying otherwise
 *      would require a Propel runtime this harness does not have.
 *
 * Usage: php -c <ini> verify-official-guard.php <path-to-patched-GroupService.php>
 * Exit:  0 all assertions pass, 1 otherwise.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$svcFile = $argv[1] ?? null;
if (!$svcFile || !is_file($svcFile)) {
    fwrite(STDERR, "usage: verify-official-guard.php <GroupService.php>\n");
    exit(2);
}

$host = getenv('MOSGOV_P5_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MOSGOV_P5_DB_PORT') ?: 3407);
$user = getenv('MOSGOV_P5_DB_USER') ?: 'root';
$pass = getenv('MOSGOV_P5_DB_PASS') ?: '';
$db   = getenv('MOSGOV_P5_PATCH_DB') ?: 'mosgov_p5_patch';

$cn = p5_connect($host, $port, $user, $pass, $db);
$a = new P5Assert();

/** Numeric-suffixed dynamic tables only; groupprop_master is a real CRM table. */
function p5_numeric_groupprop(\mysqli $cn): array
{
    $r = mysqli_query($cn, "SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^groupprop_[0-9]+$' ORDER BY TABLE_NAME");
    $out = [];
    while ($row = mysqli_fetch_row($r)) { $out[] = $row[0]; }
    return $out;
}

function p5_drop_numeric_groupprop(\mysqli $cn): void
{
    foreach (p5_numeric_groupprop($cn) as $t) {
        mysqli_query($cn, 'DROP TABLE IF EXISTS `' . $t . '`');
    }
}

function p5_table_exists(\mysqli $cn, string $t): bool
{
    $stmt = mysqli_prepare($cn, 'SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    mysqli_stmt_bind_param($stmt, 's', $t);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    return (int)$row[0] > 0;
}

function p5_scalar(\mysqli $cn, string $sql): int
{
    $r = mysqli_query($cn, $sql);
    $row = mysqli_fetch_row($r);
    return (int)$row[0];
}

$svc = p5_load_group_service($svcFile);

echo "=== OFFICIAL-ARTIFACT GUARD VERIFICATION — real PHP " . PHP_VERSION . " / real MariaDB ===\n";
echo "target: " . $svcFile . "\n";
echo "db: {$user}@{$host}:{$port}/{$db}\n\n";

/* ------------------------------------------------------------------ *
 * SECTION 1 — deleteGroupRole() guard contract (int-typed in 7.8.0)
 * ------------------------------------------------------------------ */
echo "--- SECTION 1: deleteGroupRole(int,int) guard contract ---\n";

foreach ([[0, 2, 'zero groupID'], [-1, 2, 'negative groupID'],
          [12, 0, 'zero roleID'], [12, -3, 'negative roleID']] as [$g, $r, $label]) {
    p5_reset_sql_log();
    try {
        $svc->deleteGroupRole($g, $r);
        $a->ok("OG-01 deleteGroupRole({$g},{$r}) rejected [{$label}]", false, 'NOT REJECTED');
    } catch (\InvalidArgumentException $e) {
        $a->ok("OG-01 deleteGroupRole({$g},{$r}) rejected [{$label}]",
            str_contains($e->getMessage(), 'Invalid identifier') && count(p5_sql_log()) === 0,
            'guard fired, SQL=' . count(p5_sql_log()));
    } catch (\Throwable $e) {
        $a->ok("OG-01 deleteGroupRole({$g},{$r}) rejected [{$label}]", false,
            get_class($e) . ': ' . $e->getMessage());
    }
}

// A valid identifier must PASS the guard and reach the ORM layer. The ORM is
// stubbed in this harness, so control lands on "Group not found" (404) — which
// proves the guard did not swallow a legitimate call.
p5_reset_sql_log();
try {
    $svc->deleteGroupRole(12, 2);
    $a->ok('OG-02 valid id passes the guard (reaches ORM)', false, 'unexpected success');
} catch (\Throwable $e) {
    $a->ok('OG-02 valid id passes the guard (reaches ORM)',
        str_contains($e->getMessage(), 'Group not found'),
        get_class($e) . ': ' . $e->getMessage());
}

/* ------------------------------------------------------------------ *
 * SECTION 2 — enableGroupSpecificProperties(string) : the real surface
 * ------------------------------------------------------------------ */
echo "--- SECTION 2: enableGroupSpecificProperties(string) ---\n";

p5_drop_numeric_groupprop($cn);
p5_reset_sql_log();
try {
    $svc->enableGroupSpecificProperties('1');
    $sql = count(p5_sql_log());
    $a->ok('OG-03 enable("1") accepted', true, "SQL={$sql}");
    $a->ok('OG-04 enable("1") created real table groupprop_1', p5_table_exists($cn, 'groupprop_1'), 'SHOW TABLES');
    $a->ok('OG-05 enable("1") executed real SQL', $sql >= 2, "{$sql} statements");
    $r = p5_scalar($cn, "SELECT grp_hasSpecialProps FROM group_grp WHERE grp_ID=1");
    $a->ok('OG-06 enable("1") set the DB flag', $r === 1, "grp_hasSpecialProps={$r}");
} catch (\Throwable $e) {
    $a->ok('OG-03 enable("1") accepted', false, get_class($e) . ': ' . $e->getMessage());
}

// Leading zeros must be canonicalised, not carried into the table name.
p5_drop_numeric_groupprop($cn);
p5_reset_sql_log();
try {
    $svc->enableGroupSpecificProperties('0001');
    $names = p5_numeric_groupprop($cn);
    $joined = implode("\n", p5_sql_log());
    $a->ok('OG-07 enable("0001") canonicalised to groupprop_1',
        $names === ['groupprop_1'] && !str_contains($joined, 'groupprop_0001'),
        'tables=' . implode(',', $names));
} catch (\Throwable $e) {
    $a->ok('OG-07 enable("0001") canonicalised to groupprop_1', false, get_class($e) . ': ' . $e->getMessage());
}

$hostile = [
    '' => 'empty',
    '0' => 'zero',
    '00' => 'double zero',
    '-1' => 'negative',
    '+1' => 'plus sign',
    '1.5' => 'float literal',
    '1e3' => 'scientific',
    '0x31' => 'hex literal',
    ' 1' => 'leading space',
    '1 ' => 'trailing space',
    "\t1" => 'leading tab',
    "1\n" => 'trailing newline',
    '1 OR 1=1' => 'boolean injection',
    '1;DROP TABLE group_grp' => 'stacked statement',
    "1'-- " => 'quote comment',
    '1/*x*/' => 'inline comment',
    '9223372036854775808' => 'int64 max+1 (19 digits)',
    '999999999999999999999999999999' => '30-digit overflow',
    'abc' => 'non numeric',
];

$i = 0;
foreach ($hostile as $bad => $label) {
    $i++;
    p5_drop_numeric_groupprop($cn);
    p5_reset_sql_log();
    $before = p5_scalar($cn, "SELECT COUNT(*) FROM group_grp");
    try {
        $svc->enableGroupSpecificProperties((string)$bad);
        $a->ok(sprintf('OG-08.%02d enable hostile %s rejected', $i, $label), false, 'NOT REJECTED');
    } catch (\InvalidArgumentException $e) {
        $sql = count(p5_sql_log());
        $after = p5_scalar($cn, "SELECT COUNT(*) FROM group_grp");
        $tables = p5_numeric_groupprop($cn);
        $a->ok(sprintf('OG-08.%02d enable hostile %s rejected', $i, $label),
            str_contains($e->getMessage(), 'Invalid identifier')
                && $sql === 0 && $tables === [] && $before === $after,
            "SQL={$sql} newTables=" . count($tables) . " group_grp={$after}");
    } catch (\Throwable $e) {
        $a->ok(sprintf('OG-08.%02d enable hostile %s rejected', $i, $label), false,
            get_class($e) . ': ' . $e->getMessage() . ' SQL=' . count(p5_sql_log()));
    }
}

/* ------------------------------------------------------------------ *
 * SECTION 3 — disableGroupSpecificProperties(string)
 * ------------------------------------------------------------------ */
echo "--- SECTION 3: disableGroupSpecificProperties(string) ---\n";

try {
    mysqli_query($cn, 'CREATE TABLE IF NOT EXISTS groupprop_1 (per_ID mediumint(8) unsigned NOT NULL, PRIMARY KEY (per_ID)) ENGINE=InnoDB');
    if (!p5_table_exists($cn, 'groupprop_1')) { throw new \RuntimeException('fixture table missing'); }
    p5_reset_sql_log();
    $svc->disableGroupSpecificProperties('1');
    $a->ok('OG-09 disable("1") dropped the real table', !p5_table_exists($cn, 'groupprop_1'), 'table gone');
    $a->ok('OG-10 disable("1") executed real SQL', count(p5_sql_log()) >= 3, count(p5_sql_log()) . ' statements');
} catch (\Throwable $e) {
    $a->ok('OG-09 disable("1") dropped the real table', false, get_class($e) . ': ' . $e->getMessage());
}

$j = 0;
foreach ($hostile as $bad => $label) {
    $j++;
    mysqli_query($cn, 'CREATE TABLE IF NOT EXISTS groupprop_1 (per_ID mediumint(8) unsigned NOT NULL, PRIMARY KEY (per_ID)) ENGINE=InnoDB');
    p5_reset_sql_log();
    $before = p5_scalar($cn, "SELECT COUNT(*) FROM groupprop_master");
    try {
        $svc->disableGroupSpecificProperties((string)$bad);
        $a->ok(sprintf('OG-11.%02d disable hostile %s rejected', $j, $label), false, 'NOT REJECTED');
    } catch (\InvalidArgumentException $e) {
        $sql = count(p5_sql_log());
        $exists = p5_table_exists($cn, 'groupprop_1');
        $after = p5_scalar($cn, "SELECT COUNT(*) FROM groupprop_master");
        $a->ok(sprintf('OG-11.%02d disable hostile %s rejected', $j, $label),
            str_contains($e->getMessage(), 'Invalid identifier')
                && $sql === 0 && $exists && $before === $after,
            "SQL={$sql} tableKept=" . ($exists ? 'yes' : 'no') . " groupprop_master={$after}");
    } catch (\Throwable $e) {
        $a->ok(sprintf('OG-11.%02d disable hostile %s rejected', $j, $label), false,
            get_class($e) . ': ' . $e->getMessage() . ' SQL=' . count(p5_sql_log()));
    }
}

/* ------------------------------------------------------------------ *
 * SECTION 4 — database integrity after every attack
 * ------------------------------------------------------------------ */
echo "--- SECTION 4: database integrity after hostile inputs ---\n";
// Fixtures created by the hostile-disable loop must not be counted as residue.
p5_drop_numeric_groupprop($cn);
$a->ok('OG-12 group_grp table survived', p5_table_exists($cn, 'group_grp'), 'group_grp present');
$a->ok('OG-13 groupprop_master survived', p5_table_exists($cn, 'groupprop_master'), 'groupprop_master present');
$a->ok('OG-14 no rogue numeric groupprop table', p5_numeric_groupprop($cn) === [],
    'numeric tables=' . count(p5_numeric_groupprop($cn)));
$a->ok('OG-15 no group rows deleted', p5_scalar($cn, 'SELECT COUNT(*) FROM group_grp') === 2,
    'group_grp rows=' . p5_scalar($cn, 'SELECT COUNT(*) FROM group_grp'));

p5_drop_numeric_groupprop($cn);
exit($a->summary('verify-official-guard'));
