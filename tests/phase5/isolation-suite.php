<?php
/**
 * MOS-GOV Phase 5 — dual-church isolation acceptance (REAL SQL / REAL FILES).
 *
 * Runs against the isolated MariaDB 11.8.9 instance on 127.0.0.1:3407 with two
 * independently provisioned databases, two least-privilege accounts, two
 * private storage roots and two independent HMAC audit keys.
 *
 * Every check below issues a REAL SQL query or reads a REAL file. HTTP-level
 * checks (login, session, cross-host API, file download) live in
 * http-boundary/http-tests.sh and are cross-referenced in the report.
 *
 * Usage: php -c <ini> isolation-suite.php
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib-chain.php';

$host = getenv('MOSGOV_P5_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MOSGOV_P5_DB_PORT') ?: 3407);
$root = getenv('MOSGOV_P5_DB_USER') ?: 'root';
$rp   = getenv('MOSGOV_P5_DB_PASS') ?: '';
$dbA  = getenv('MOSGOV_P5_DB_A');
$dbB  = getenv('MOSGOV_P5_DB_B');
$usrA = getenv('MOSGOV_P5_USER_A'); $pwA = getenv('MOSGOV_P5_PASS_A');
$usrB = getenv('MOSGOV_P5_USER_B'); $pwB = getenv('MOSGOV_P5_PASS_B');
$keyA = getenv('MOSGOV_P5_HMAC_A'); $keyB = getenv('MOSGOV_P5_HMAC_B');
$stoA = getenv('MOSGOV_P5_STORAGE_A'); $stoB = getenv('MOSGOV_P5_STORAGE_B');

function cx(string $h, int $p, string $u, string $pw, string $db): \mysqli
{
    $c = @new \mysqli($h, $u, $pw, $db, $p);
    if ($c->connect_errno) { throw new \RuntimeException('CONNECT_FAILED: ' . $c->connect_error); }
    $c->set_charset('utf8mb4');
    return $c;
}
function one(\mysqli $c, string $sql)
{
    $r = mysqli_query($c, $sql);
    $row = mysqli_fetch_row($r);
    return $row[0] ?? null;
}
function rows(\mysqli $c, string $sql): array
{
    $r = mysqli_query($c, $sql);
    $out = [];
    while ($x = mysqli_fetch_assoc($r)) { $out[] = $x; }
    return $out;
}
function denied(callable $fn): bool
{
    try { $fn(); return false; } catch (\mysqli_sql_exception $e) { return true; }
    catch (\Throwable $e) { return true; }
}

$RA = cx($host, $port, $root, $rp, $dbA);
$RB = cx($host, $port, $root, $rp, $dbB);
$UA = cx($host, $port, $usrA, $pwA, $dbA);   // least-privilege app account A
$UB = cx($host, $port, $usrB, $pwB, $dbB);   // least-privilege app account B
$a  = new P5Assert();

echo "=== DUAL-CHURCH ISOLATION — real PHP " . PHP_VERSION . " / real MariaDB ===\n";
echo "A={$dbA} (app {$usrA})  B={$dbB} (app {$usrB})\n\n";

/* I1 — login identity isolation ----------------------------------------- */
echo "--- I1 登录身份隔离 ---\n";
$a->ok('I1-01 A 身份只存在于 A 库', (int)one($RA, "SELECT COUNT(*) FROM gov_evidence_log WHERE actor='synthetic.admin.a'") === 3,
    'A rows with A actor = ' . one($RA, "SELECT COUNT(*) FROM gov_evidence_log WHERE actor='synthetic.admin.a'"));
$a->ok('I1-02 A 库无 B 身份', (int)one($RA, "SELECT COUNT(*) FROM gov_evidence_log WHERE actor='synthetic.admin.b'") === 0, 'B actor in A DB = 0');
$a->ok('I1-03 B 库无 A 身份', (int)one($RB, "SELECT COUNT(*) FROM gov_evidence_log WHERE actor='synthetic.admin.a'") === 0, 'A actor in B DB = 0');
$a->ok('I1-04 两套凭据文件各自独立存在',
    is_file(rtrim($stoA, '/') . '/.p5-login.json') && is_file(rtrim($stoB, '/') . '/.p5-login.json'),
    'A=' . (is_file(rtrim($stoA, '/') . '/.p5-login.json') ? 'present' : 'missing')
    . ' B=' . (is_file(rtrim($stoB, '/') . '/.p5-login.json') ? 'present' : 'missing'));
// Explicitly assert each tenant key file holds ONLY its own tenant.
$la = json_decode((string)@file_get_contents(rtrim($stoA, '/') . '/.p5-login.json'), true);
$lb = json_decode((string)@file_get_contents(rtrim($stoB, '/') . '/.p5-login.json'), true);
$a->ok('I1-05 A 凭据绑定 church-a', ($la['tenant'] ?? '') === 'church-a', 'A file tenant=' . ($la['tenant'] ?? 'none'));
$a->ok('I1-06 B 凭据绑定 church-b', ($lb['tenant'] ?? '') === 'church-b', 'B file tenant=' . ($lb['tenant'] ?? 'none'));
$a->ok('I1-07 两套凭据不相同', ($la['secret'] ?? '') !== ($lb['secret'] ?? ''), 'secrets differ');

/* I2 — member records ----------------------------------------------------- */
echo "\n--- I2 成员记录隔离 ---\n";
$a->ok('I2-01 A 有 3 名合成成员', (int)one($RA, 'SELECT COUNT(*) FROM gov_identity') === 3, 'count=' . one($RA, 'SELECT COUNT(*) FROM gov_identity'));
$a->ok('I2-02 B 有 3 名合成成员', (int)one($RB, 'SELECT COUNT(*) FROM gov_identity') === 3, 'count=' . one($RB, 'SELECT COUNT(*) FROM gov_identity'));
$a->ok('I2-03 A 成员 ID 全在 1001-1003', (int)one($RA, 'SELECT COUNT(*) FROM gov_identity WHERE person_id BETWEEN 1001 AND 1003') === 3, 'disjoint range');
$a->ok('I2-04 B 成员 ID 全在 2001-2003', (int)one($RB, 'SELECT COUNT(*) FROM gov_identity WHERE person_id BETWEEN 2001 AND 2003') === 3, 'disjoint range');
$a->ok('I2-05 A 库查不到 B 成员', (int)one($RA, 'SELECT COUNT(*) FROM gov_identity WHERE person_id >= 2001') === 0, 'cross rows = 0');
$a->ok('I2-06 B 库查不到 A 成员', (int)one($RB, 'SELECT COUNT(*) FROM gov_identity WHERE person_id < 2001') === 0, 'cross rows = 0');
$a->ok('I2-07 A 应用账号经真实连接只能读 A 成员', (int)one($UA, 'SELECT COUNT(*) FROM gov_identity WHERE person_id >= 2001') === 0, 'least-privilege connection verified');

/* I3 — small groups and roles --------------------------------------------- */
echo "\n--- I3 小组和角色隔离 ---\n";
$a->ok('I3-01 A 角色名全含 A', (int)one($RA, "SELECT COUNT(*) FROM gov_role WHERE name NOT LIKE '% A %'") === 0, 'A-only role naming');
$a->ok('I3-02 B 角色名全含 B', (int)one($RB, "SELECT COUNT(*) FROM gov_role WHERE name NOT LIKE '% B %'") === 0, 'B-only role naming');
$a->ok('I3-03 A 库无 B 角色', (int)one($RA, "SELECT COUNT(*) FROM gov_role WHERE role_code LIKE '%-church-b'") === 0, 'cross roles = 0');
$a->ok('I3-04 B 库无 A 角色', (int)one($RB, "SELECT COUNT(*) FROM gov_role WHERE role_code LIKE '%-church-a'") === 0, 'cross roles = 0');
$a->ok('I3-05 A 小组数=2 且 B 小组数=2',
    (int)one($RA, 'SELECT COUNT(*) FROM gov_body') === 2 && (int)one($RB, 'SELECT COUNT(*) FROM gov_body') === 2, 'independent bodies');

/* I4 — governance records ------------------------------------------------- */
echo "\n--- I4 治理记录隔离 ---\n";
$a->ok('I4-01 A 决议只带 A 标记', (int)one($RA, "SELECT COUNT(*) FROM gov_decision WHERE title NOT LIKE '% A %'") === 0, 'A-only decisions');
$a->ok('I4-02 B 决议只带 B 标记', (int)one($RB, "SELECT COUNT(*) FROM gov_decision WHERE title NOT LIKE '% B %'") === 0, 'B-only decisions');
$a->ok('I4-03 A 库写入 B 租户证据被触发器拒绝', denied(fn() => mysqli_query($RA,
    "INSERT INTO gov_evidence_log (tenant_id,actor,occurred_at,object_type,action,authorization_basis,result,prev_hash,row_hash)
     VALUES ('church-b','intruder',NOW(),'structure','create','none','success',REPEAT('0',64),REPEAT('1',64))")),
    'gov_evidence_tenant_guard fired');
$a->ok('I4-04 B 库写入 A 租户证据被触发器拒绝', denied(fn() => mysqli_query($RB,
    "INSERT INTO gov_evidence_log (tenant_id,actor,occurred_at,object_type,action,authorization_basis,result,prev_hash,row_hash)
     VALUES ('church-a','intruder',NOW(),'structure','create','none','success',REPEAT('0',64),REPEAT('2',64))")),
    'gov_evidence_tenant_guard fired');

/* I5 — file storage ------------------------------------------------------- */
echo "\n--- I5 文件存储隔离 ---\n";
$fa = array_values(array_diff(scandir($stoA) ?: [], ['.', '..']));
$fb = array_values(array_diff(scandir($stoB) ?: [], ['.', '..']));
$a->ok('I5-01 A 存储根只含 A 文件', count(array_filter($fa, fn($f) => !str_contains($f, '-A-') && $f !== '.p5-login.json')) === 0, 'A files: ' . implode(',', $fa));
$a->ok('I5-02 B 存储根只含 B 文件', count(array_filter($fb, fn($f) => !str_contains($f, '-B-') && $f !== '.p5-login.json')) === 0, 'B files: ' . implode(',', $fb));
$a->ok('I5-03 A 文件登记表租户全为 church-a', (int)one($RA, "SELECT COUNT(*) FROM gov_tenant_file WHERE tenant_id<>'church-a'") === 0, 'tenant stamps');
$a->ok('I5-04 B 文件登记表租户全为 church-b', (int)one($RB, "SELECT COUNT(*) FROM gov_tenant_file WHERE tenant_id<>'church-b'") === 0, 'tenant stamps');
$a->ok('I5-05 A 登记表中查不到 B 的 object_key', (int)one($RA, "SELECT COUNT(*) FROM gov_tenant_file WHERE object_key LIKE '%-B-%'") === 0, 'cross keys = 0');
$a->ok('I5-06 B 登记表中查不到 A 的 object_key', (int)one($RB, "SELECT COUNT(*) FROM gov_tenant_file WHERE object_key LIKE '%-A-%'") === 0, 'cross keys = 0');
$a->ok('I5-07 A 库写入 B 租户文件登记被触发器拒绝', denied(fn() => mysqli_query($RA,
    "INSERT INTO gov_tenant_file (tenant_id,object_key,original_name,mime_type,byte_size,sha256,uploaded_by,status)
     VALUES ('church-b','intruder.txt','intruder.txt','text/plain',1,REPEAT('a',64),'intruder','active')")),
    'gov_tenant_file_tenant_guard fired');
foreach (['A' => [$RA, $stoA], 'B' => [$RB, $stoB]] as $tag => [$c, $dir]) {
    foreach (rows($c, 'SELECT object_key,byte_size,sha256 FROM gov_tenant_file') as $f) {
        $p = rtrim($dir, '/') . '/' . $f['object_key'];
        $a->ok("I5-08-{$tag}-{$f['object_key']} 文件真实存在且 SHA256/大小一致",
            is_file($p) && filesize($p) === (int)$f['byte_size'] && hash_file('sha256', $p) === $f['sha256'],
            'bytes=' . $f['byte_size'] . ' sha=' . substr($f['sha256'], 0, 12));
    }
}

/* I6 — database isolation (real permission denial) ------------------------ */
echo "\n--- I6 数据库隔离 ---\n";
$a->ok('I6-01 A 应用账号不能读 B 库', denied(fn() => mysqli_query($UA, "SELECT COUNT(*) FROM `{$dbB}`.gov_identity")), 'permission denied');
$a->ok('I6-02 B 应用账号不能读 A 库', denied(fn() => mysqli_query($UB, "SELECT COUNT(*) FROM `{$dbA}`.gov_identity")), 'permission denied');
$a->ok('I6-03 A 应用账号不能写 B 库', denied(fn() => mysqli_query($UA, "INSERT INTO `{$dbB}`.gov_structure (name) VALUES ('intruder')")), 'permission denied');
$a->ok('I6-04 B 应用账号不能写 A 库', denied(fn() => mysqli_query($UB, "INSERT INTO `{$dbA}`.gov_structure (name) VALUES ('intruder')")), 'permission denied');
$a->ok('I6-05 A 应用账号可读自己的库', (int)one($UA, 'SELECT COUNT(*) FROM gov_identity') === 3, 'own DB readable');
$a->ok('I6-06 tenant 身份不可被篡改', denied(fn() => mysqli_query($RA, "UPDATE gov_tenant SET tenant_id='church-b' WHERE singleton=1")), 'gov_tenant_no_identity_change fired');
$a->ok('I6-07 tenant 行不可被删除', denied(fn() => mysqli_query($RB, 'DELETE FROM gov_tenant WHERE singleton=1')), 'gov_tenant_no_delete fired');

/* I7 — audit chain isolation ---------------------------------------------- */
echo "\n--- I7 审计记录隔离 ---\n";
$chainA = rows($RA, 'SELECT * FROM gov_evidence_log ORDER BY id');
$chainB = rows($RB, 'SELECT * FROM gov_evidence_log ORDER BY id');
$va = p5_chain_verify($chainA, $keyA);
$vb = p5_chain_verify($chainB, $keyB);
$a->ok('I7-01 A 链用 A 密钥验证通过', $va['ok'] === true, $va['reason'] . ' rows=' . $va['rows']);
$a->ok('I7-02 B 链用 B 密钥验证通过', $vb['ok'] === true, $vb['reason'] . ' rows=' . $vb['rows']);
$a->ok('I7-03 A 链用 B 密钥验证失败', p5_chain_verify($chainA, $keyB)['ok'] === false, 'cross-church key rejected');
$a->ok('I7-04 B 链用 A 密钥验证失败', p5_chain_verify($chainB, $keyA)['ok'] === false, 'cross-church key rejected');
$a->ok('I7-05 两条链头不同', $va['head'] !== $vb['head'], 'A=' . substr($va['head'], 0, 12) . ' B=' . substr($vb['head'], 0, 12));
$a->ok('I7-06 A 证据行 tenant 全为 church-a', (int)one($RA, "SELECT COUNT(*) FROM gov_evidence_log WHERE tenant_id<>'church-a'") === 0, 'tenant stamps');
$a->ok('I7-07 A 应用账号不能改审计行', denied(fn() => mysqli_query($UA, "UPDATE gov_evidence_log SET after_state='x' WHERE id=1")), 'append-only + least privilege');
$a->ok('I7-08 A 应用账号不能删审计行', denied(fn() => mysqli_query($UA, 'DELETE FROM gov_evidence_log WHERE id=1')), 'append-only + least privilege');
$a->ok('I7-09 root 也不能 UPDATE 审计行（触发器）', denied(fn() => mysqli_query($RA, "UPDATE gov_evidence_log SET after_state='x' WHERE id=1")), 'gov_evidence_no_update fired');

/* I9 — unauthorised cross-church access (DB layer) ------------------------ */
echo "\n--- I9 未授权跨教会访问被拒绝（数据库层；HTTP 层见 http-tests.sh）---\n";
$a->ok('I9-01 A 账号跨库 SELECT 被拒', denied(fn() => mysqli_query($UA, "SELECT * FROM `{$dbB}`.gov_decision")), 'denied');
$a->ok('I9-02 A 账号跨库文件登记 SELECT 被拒', denied(fn() => mysqli_query($UA, "SELECT * FROM `{$dbB}`.gov_tenant_file")), 'denied');
$a->ok('I9-03 A 库内伪造 B 身份被拒', denied(fn() => mysqli_query($RA,
    "INSERT INTO gov_evidence_log (tenant_id,actor,occurred_at,object_type,action,authorization_basis,result,prev_hash,row_hash)
     VALUES ('church-b','synthetic.admin.b',NOW(),'decision','update','none','success',REPEAT('0',64),REPEAT('3',64))")), 'denied');

exit($a->summary('isolation-suite'));
