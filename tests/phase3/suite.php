<?php

/**
 * MOS-GOV Phase 3 lab suite — runs against a REAL, EMPTY MariaDB 11.8.9.
 *
 * Phases (driven by the lab shell driver):
 *   main        trust boundary, SQL/XSS, CRUD, tenant isolation, uploads,
 *               clean evidence chain; writes pre_anchor.json
 *   tamper      privileged in-place edit; asserts the chain reports tampering
 *   postverify  after backup restore; asserts the chain and data are intact
 *
 * Usage:
 *   MOSGOV_LAB_* env vars ... php -c <ini> suite.php --phase=main
 *
 * All data is synthetic. No ChurchCRM UI is started; results are component /
 * data-layer level and are labelled that way in the delivery report.
 */

require __DIR__ . '/bootstrap.php';

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Security\CsvExportGuard;
use ChurchCRM\Plugins\MosGov\Security\GovernanceAuditService;
use ChurchCRM\Plugins\MosGov\Security\LocalSecureMode;
use ChurchCRM\Plugins\MosGov\Security\PublicRequestPolicy;
use ChurchCRM\Plugins\MosGov\Security\PublicTrustPolicy;
use ChurchCRM\Plugins\MosGov\Security\SafeError;
use ChurchCRM\Plugins\MosGov\Security\SessionHardening;
use ChurchCRM\Plugins\MosGov\Security\TenantContext;
use ChurchCRM\Plugins\MosGov\Security\TenantFilePolicy;
use MosGov\Lab\Assert;
use MosGov\Lab\LabConnection;

$phase = 'main';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--phase=')) {
        $phase = substr($arg, 8);
    }
}

$cfg = [
    'dbA' => getenv('MOSGOV_LAB_DB_A') ?: 'mosgov_lab_a',
    'dbB' => getenv('MOSGOV_LAB_DB_B') ?: 'mosgov_lab_b',
    'userA' => getenv('MOSGOV_LAB_USER_A') ?: 'mosgov_a',
    'passA' => getenv('MOSGOV_LAB_PASS_A') ?: '',
    'userB' => getenv('MOSGOV_LAB_USER_B') ?: 'mosgov_b',
    'passB' => getenv('MOSGOV_LAB_PASS_B') ?: '',
    'rootUser' => getenv('MOSGOV_LAB_ROOT_USER') ?: 'root',
    'rootPass' => getenv('MOSGOV_LAB_ROOT_PASS') ?: '',
    'hmac' => getenv('MOSGOV_LAB_HMAC') ?: str_repeat('k', 64),
    'rootA' => getenv('MOSGOV_LAB_ROOT_A') ?: sys_get_temp_dir() . '/mosgov-a',
    'rootB' => getenv('MOSGOV_LAB_ROOT_B') ?: sys_get_temp_dir() . '/mosgov-b',
    'evidence' => getenv('MOSGOV_LAB_EVIDENCE') ?: sys_get_temp_dir(),
];

function labConn(array $cfg, string $user, string $pass, string $db): LabConnection
{
    return LabConnection::connect($user, $pass, $db);
}

function section(string $name): void
{
    echo PHP_EOL . '=== ' . $name . ' ===' . PHP_EOL;
}

// ---------------------------------------------------------------------------
if ($phase === 'main') {
    // ------------------------------------------------------ T03 trust boundary
    section('T03 公网信任边界 / Session / CSRF / 错误泄露');

    Assert::check('unknown deployment mode fails closed to LOCAL',
        PublicTrustPolicy::deploymentMode(['MOSGOV_DEPLOYMENT' => 'nonsense']) === 'LOCAL');
    Assert::check('explicit public deployment recognised',
        PublicTrustPolicy::deploymentMode(['MOSGOV_DEPLOYMENT' => 'public']) === 'PUBLIC');

    $publicEnv = [
        'MOSGOV_DEPLOYMENT' => 'public',
        'MOSGOV_TENANT_ID' => 'church-a',
        'MOSGOV_TRUSTED_PROXIES' => '127.0.0.1',
    ];
    $directHttps = PublicTrustPolicy::authorityCheck(
        ['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on', 'HTTP_HOST' => 'a.mosgov.example'], [], $publicEnv
    );
    Assert::check('direct HTTPS public request accepted', $directHttps['ok'] === true && $directHttps['https'] === true);

    $forged = PublicTrustPolicy::authorityCheck(
        ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'a.mosgov.example'],
        ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.1'], $publicEnv
    );
    Assert::check('forged X-Forwarded-Proto from untrusted peer cannot upgrade to HTTPS',
        $forged['https'] === false && $forged['ok'] === false);
    Assert::check('untrusted forwarded client IP is marked non-authoritative',
        $forged['client']['authoritative'] === true && $forged['client']['via_header'] === false);

    $proxied = PublicTrustPolicy::authorityCheck(
        ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'a.mosgov.example'],
        ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7'], $publicEnv
    );
    Assert::check('configured trusted proxy may assert scheme', $proxied['https'] === true && $proxied['ok'] === true);
    Assert::check('forwarded client IP is labelled as a claim, never an authority',
        $proxied['client']['log_ip'] === '198.51.100.7' && $proxied['client']['authoritative'] === false);

    $off = $publicEnv;
    $off['MOS_GOV_SECURITY_MODE'] = 'OFF';
    $offCheck = PublicTrustPolicy::authorityCheck(
        ['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on'], [], $off
    );
    Assert::check('legacy SECURITY_MODE=OFF is refused in public deployment', $offCheck['ok'] === false);

    $noTenant = $publicEnv;
    unset($noTenant['MOSGOV_TENANT_ID']);
    Assert::check('missing tenant context is refused in public deployment',
        PublicTrustPolicy::authorityCheck(['HTTPS' => 'on'], [], $noTenant)['ok'] === false);

    putenv('MOSGOV_DEPLOYMENT=public');
    putenv('MOS_GOV_SECURITY_MODE=OFF');
    Assert::check('LocalSecureMode silently downgrades OFF in public deployment',
        LocalSecureMode::mode() === 'LOCAL');
    putenv('MOS_GOV_SECURITY_MODE');
    putenv('MOSGOV_DEPLOYMENT');

    $server = ['HTTP_HOST' => 'a.mosgov.example', 'HTTPS' => 'on'];
    Assert::check('same-origin POST passes Origin validation',
        PublicRequestPolicy::validateOrigin('POST', $server, ['Origin' => 'https://a.mosgov.example'])['ok']);
    Assert::check('cross-origin POST is refused',
        !PublicRequestPolicy::validateOrigin('POST', $server, ['Origin' => 'https://evil.example'])['ok']);
    Assert::check('POST with neither Origin nor Referer is refused',
        !PublicRequestPolicy::validateOrigin('POST', $server, [])['ok']);
    Assert::check('GET does not require Origin',
        PublicRequestPolicy::validateOrigin('GET', $server, [])['ok']);

    $alwaysTrue = static fn (string $t): bool => true;
    Assert::check('POST without CSRF token is refused',
        !PublicRequestPolicy::requireCsrf('POST', [], null, $alwaysTrue)['ok']);
    Assert::check('POST with valid CSRF token passes',
        PublicRequestPolicy::requireCsrf('POST', ['csrf_token' => str_repeat('a', 64)], null, $alwaysTrue)['ok']);
    Assert::check('X-CSRF-Token header accepted for API-style calls',
        PublicRequestPolicy::requireCsrf('POST', [], str_repeat('b', 64), $alwaysTrue)['ok']);

    $unauth = PublicRequestPolicy::evaluate([
        'method' => 'GET', 'server' => $server, 'headers' => [], 'body' => [],
        'authenticated' => false, 'public' => true, 'https' => true, 'csrf_verifier' => $alwaysTrue,
    ]);
    Assert::check('unauthenticated request gets 401', $unauth['ok'] === false && $unauth['status'] === 401);
    $headers = PublicRequestPolicy::securityHeaders(true, true);
    Assert::check('HSTS / CSP / nosniff / frame-deny headers present',
        isset($headers['Strict-Transport-Security'], $headers['Content-Security-Policy'],
            $headers['X-Content-Type-Options'], $headers['X-Frame-Options']));

    SessionHardening::apply(true, true);
    Assert::check('hardened session settings have no violations', SessionHardening::violations(true) === [],
        json_encode(SessionHardening::effective(), JSON_UNESCAPED_SLASHES));

    $leaky = new \RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'127.0.0.1:3306' on SELECT * FROM gov_identity");
    Assert::check('raw DB exception message is classified as a leak', SafeError::looksLikeLeak($leaky->getMessage()));
    Assert::check('public error text never contains DB diagnostics', !SafeError::looksLikeLeak(SafeError::publicMessage($leaky, 'lab')));
    Assert::check('service diagnostic text is redacted', !SafeError::looksLikeLeak(SafeError::publicText($leaky->getMessage())));
    Assert::check('CSV formula injection neutralised', CsvExportGuard::cell('=HYPERLINK("http://evil")') === "'=HYPERLINK(\"http://evil\")");

    // --------------------------------------------- T02 migrations + T06 CRUD
    section('T02/T06 迁移与治理实体 CRUD（真实数据库）');
    $connA = labConn($cfg, $cfg['userA'], $cfg['passA'], $cfg['dbA']);
    \Propel\Runtime\Propel::setConnection($connA);

    $tables = $connA->pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchColumn();
    Assert::check('migrations created governance tables', (int) $tables >= 20, 'tables=' . $tables);
    foreach (['gov_evidence_log', 'gov_tenant', 'gov_tenant_file', 'gov_audit_log'] as $t) {
        $exists = $connA->pdo->query("SHOW TABLES LIKE '" . $t . "'")->fetchColumn();
        Assert::check('table ' . $t . ' exists after migration', (string) $exists === $t);
    }
    $canonical = ['structure', 'body', 'role', 'appointment', 'responsibility',
        'relationship', 'meeting', 'issue', 'decision', 'task'];
    Assert::check('all ten canonical governance entities registered',
        array_diff($canonical, array_values(GovRepository::SLUG_TO_ENTITY)) === [],
        'registered=' . implode(',', array_values(GovRepository::SLUG_TO_ENTITY)));

    $repo = new GovRepository($connA);

    // Existing input validation is a real defence: HTML tags are rejected
    // before they ever reach storage. Verified here rather than assumed.
    $xssRejected = false;
    try {
        $repo->insert('structure', ['name' => '<script>alert(1)</script>', 'code' => 'XSS-PROBE', 'status' => 'active']);
    } catch (GovDataException $e) {
        $xssRejected = true;
    }
    Assert::check('repository rejects HTML tags in text fields (input validation)', $xssRejected);

    // SQL injection probe without HTML tags: must be stored verbatim and must
    // not execute, even though it contains quotes, OR, UNION SELECT and --.
    $sqlPayload = "x' OR '1'='1 -- UNION SELECT";
    $baselineRows = (int) $connA->pdo->query('SELECT COUNT(*) FROM gov_structure')->fetchColumn();
    $newId = $repo->insert('structure', ['name' => $sqlPayload, 'code' => 'SQLI-PROBE', 'status' => 'active']);
    Assert::check('repository insert returns an id', $newId > 0, 'id=' . $newId);
    $stored = $repo->find('structure', $newId);
    Assert::check('SQL injection payload stored verbatim (prepared statement)',
        $stored !== null && $stored['name'] === $sqlPayload);
    $rowCount = $connA->pdo->query('SELECT COUNT(*) FROM gov_structure')->fetchColumn();
    Assert::check('injected payload did not alter table structure or row count',
        (int) $rowCount === $baselineRows + 1, 'baseline=' . $baselineRows . ' rows=' . $rowCount);

    // Defence in depth: data that bypasses input validation (e.g. an import)
    // must still be escaped at render time.
    $raw = $connA->prepare('INSERT INTO gov_structure (name, code, status) VALUES (:n, :c, :s)');
    $raw->bindValue(':n', '<img src=x onerror=alert(1)>');
    $raw->bindValue(':c', 'RENDER-PROBE');
    $raw->bindValue(':s', 'active');
    $raw->execute();
    $rawId = (int) $connA->lastInsertId();
    $escaped = htmlspecialchars((string) $repo->find('structure', $rawId)['name'], ENT_QUOTES, 'UTF-8');
    Assert::check('output escaping neutralises stored HTML',
        !str_contains($escaped, '<img') && str_contains($escaped, '&lt;img'));
    $connA->exec('DELETE FROM gov_structure WHERE id = ' . $rawId);

    $badOrder = false;
    try {
        $repo->list('structure', 10, ['id; DROP TABLE gov_structure' => 'ASC']);
    } catch (GovDataException $e) {
        $badOrder = true;
    }
    Assert::check('ORDER BY injection rejected by column whitelist', $badOrder);

    $repo->update('structure', $newId, [
        'name' => '合成治理结构-A（已修改）', 'code' => 'SQLI-PROBE', 'status' => 'active',
    ]);
    $updated = $repo->find('structure', $newId);
    Assert::check('governance entity update persists', $updated['name'] === '合成治理结构-A（已修改）');
    Assert::check('countWhere reads the persisted row', $repo->countWhere('structure', 'id', $newId) === 1);
    $formulaId = $repo->insert('structure', ['name' => '=1+1', 'code' => 'CSV-PROBE', 'status' => 'active']);
    Assert::check('CSV formula payload stored as data', $repo->find('structure', $formulaId)['name'] === '=1+1');
    Assert::check('CSV export neutralises stored formula', CsvExportGuard::cell('=1+1') === "'=1+1");
    Assert::check('governance entity delete works', $repo->delete('structure', $formulaId) === true);
    Assert::check('delete persisted (no phantom row)', $repo->find('structure', $formulaId) === null);

    // ------------------------------------------------------- T05 tenant scope
    section('T05 多教会隔离（物理隔离 + 运行时 fail-closed）');
    $envA = [
        'MOSGOV_TENANT_ID' => 'church-a', 'MOSGOV_TENANT_DB' => $cfg['dbA'],
        'MOSGOV_TENANT_HOST' => 'a.mosgov.example', 'MOSGOV_TENANT_STORAGE' => $cfg['rootA'],
        'MOSGOV_DEPLOYMENT' => 'public',
    ];
    $envB = [
        'MOSGOV_TENANT_ID' => 'church-b', 'MOSGOV_TENANT_DB' => $cfg['dbB'],
        'MOSGOV_TENANT_HOST' => 'b.mosgov.example', 'MOSGOV_TENANT_STORAGE' => $cfg['rootB'],
        'MOSGOV_DEPLOYMENT' => 'public',
    ];
    $tenantA = TenantContext::fromEnvironment($envA, 'a.mosgov.example');
    $tenantB = TenantContext::fromEnvironment($envB, 'b.mosgov.example');
    Assert::check('tenant A context resolves', $tenantA->id() === 'church-a');
    Assert::check('tenant B context resolves', $tenantB->id() === 'church-b');
    Assert::check('tenant database boundary A passes', $tenantA->assertDatabase($connA)['ok'] === true);

    $rootConnB = labConn($cfg, $cfg['rootUser'], $cfg['rootPass'], $cfg['dbB']);
    Assert::check('tenant A context refuses church B database',
        $tenantA->assertDatabase($rootConnB)['ok'] === false);
    $hostMismatch = false;
    try {
        TenantContext::fromEnvironment($envA, 'b.mosgov.example');
    } catch (\RuntimeException $e) {
        $hostMismatch = true;
    }
    Assert::check('request on another church hostname fails closed', $hostMismatch);
    $invalidTenant = false;
    try {
        TenantContext::fromEnvironment(['MOSGOV_TENANT_ID' => '../evil', 'MOSGOV_TENANT_DB' => $cfg['dbA'],
            'MOSGOV_TENANT_HOST' => 'a.mosgov.example', 'MOSGOV_TENANT_STORAGE' => $cfg['rootA']]);
    } catch (\RuntimeException $e) {
        $invalidTenant = true;
    }
    Assert::check('malformed tenant id fails closed', $invalidTenant);

    $crossA = false;
    try {
        $connA->pdo->query('SELECT COUNT(*) FROM `' . $cfg['dbB'] . '`.gov_structure')->fetchColumn();
    } catch (\PDOException $e) {
        $crossA = true;
    }
    Assert::check('church A DB account cannot read church B tables', $crossA);
    $connB = labConn($cfg, $cfg['userB'], $cfg['passB'], $cfg['dbB']);
    $crossB = false;
    try {
        $connB->pdo->query('SELECT COUNT(*) FROM `' . $cfg['dbA'] . '`.gov_structure')->fetchColumn();
    } catch (\PDOException $e) {
        $crossB = true;
    }
    Assert::check('church B DB account cannot read church A tables', $crossB);
    Assert::check('church B tenant boundary row is its own',
        $tenantB->assertDatabase($rootConnB)['tenant_id'] === 'church-b');

    // Export permission boundary (source-level assertion of the frozen rule).
    $routes = file_get_contents(MOSGOV_LAB_ROOT . '/routes/routes.php');
    Assert::check('export has no administrator bypass in the authorization guard',
        str_contains($routes, "\$adminFallback && \$action !== 'export'"));
    Assert::check('global public guard is registered once at app level',
        str_contains($routes, '$app->add(new PublicRequestGuardMiddleware());'));

    // -------------------------------------------------------------- T07 files
    section('T07 文件上传 / 路径遍历 / 私有存储隔离');
    Assert::check('tenant storage roots are distinct', $tenantA->storageRoot() !== $tenantB->storageRoot());
    foreach (['../church-b/secret.pdf', 'a/../../x.pdf', '/etc/passwd', 'C:/Windows/win.ini', 'a//b.pdf'] as $bad) {
        $rejected = false;
        try {
            $tenantA->resolvePath($bad);
        } catch (\RuntimeException $e) {
            $rejected = true;
        }
        Assert::check('path rejected: ' . $bad, $rejected);
    }
    $accepted = TenantFilePolicy::validate('会议纪要.pdf', 1024, 'application/pdf');
    Assert::check('allowed document upload accepted', $accepted['ok'] === true);
    Assert::check('PHP upload rejected by extension allow-list',
        TenantFilePolicy::validate('shell.php', 100, 'application/x-httpd-php')['ok'] === false);
    Assert::check('oversized upload rejected',
        TenantFilePolicy::validate('big.pdf', 99_999_999, 'application/pdf')['ok'] === false);
    Assert::check('traversal filename rejected',
        TenantFilePolicy::validate('../../etc/passwd', 100, 'text/plain')['ok'] === false);

    $key = TenantFilePolicy::objectKey('church-a', 'pdf');
    $filePath = $tenantA->resolvePath($key);
    @mkdir(dirname($filePath), 0777, true);
    $bytes = "SYNTHETIC-ONLY / 合成测试文件 / " . bin2hex(random_bytes(8));
    file_put_contents($filePath, $bytes);
    Assert::check('file written inside tenant A private root', is_file($filePath));
    Assert::check('file readable back with identical bytes', file_get_contents($filePath) === $bytes);
    Assert::check('file path is outside any web document root',
        !str_contains(str_replace('\\', '/', $filePath), '/www/'));
    $sha = hash('sha256', $bytes);
    $ins = $connA->prepare('INSERT INTO gov_tenant_file
        (tenant_id, object_key, original_name, mime_type, byte_size, sha256, uploaded_by)
        VALUES (:t,:k,:n,:m,:s,:h,:u)');
    $ins->bindValue(':t', 'church-a');
    $ins->bindValue(':k', $key);
    $ins->bindValue(':n', '会议纪要.pdf');
    $ins->bindValue(':m', 'application/pdf');
    $ins->bindValue(':s', strlen($bytes));
    $ins->bindValue(':h', $sha);
    $ins->bindValue(':u', 'lab-admin');
    $ins->execute();
    Assert::check('file registered in tenant file table', (int) $connA->lastInsertId() > 0);
    $wrongTenant = false;
    try {
        $bad = $connA->prepare('INSERT INTO gov_tenant_file
            (tenant_id, object_key, original_name, mime_type, byte_size, sha256, uploaded_by)
            VALUES (:t,:k,:n,:m,:s,:h,:u)');
        $bad->bindValue(':t', 'church-b');
        $bad->bindValue(':k', 'church-b/foreign.pdf');
        $bad->bindValue(':n', 'foreign.pdf');
        $bad->bindValue(':m', 'application/pdf');
        $bad->bindValue(':s', 10);
        $bad->bindValue(':h', str_repeat('0', 64));
        $bad->bindValue(':u', 'lab-admin');
        $bad->execute();
    } catch (\PDOException $e) {
        $wrongTenant = true;
    }
    Assert::check('cross-tenant file registration blocked by DB trigger', $wrongTenant);

    $bReach = false;
    try {
        $tenantB->resolvePath(str_replace('\\', '/', $filePath));
    } catch (\RuntimeException $e) {
        $bReach = true;
    }
    Assert::check('church B context cannot address church A absolute file path', $bReach);

    // -------------------------------------------------------------- T04 audit
    section('T04 治理审计证据链（干净状态）');
    $audit = new GovernanceAuditService($connA, 'church-a', $cfg['hmac']);
    $events = [
        ['create', 'structure', $newId, null, ['name' => '合成治理结构-A'], '{"permission":"structure.create","source":"role","step":5}', 'success'],
        ['update', 'structure', $newId, ['name' => '旧值'], ['name' => '合成治理结构-A（已修改）'], '{"permission":"structure.update","source":"role","step":5}', 'success'],
        ['delete', 'structure', $formulaId, ['name' => '=1+1'], null, '{"permission":"structure.delete","source":"role","step":5}', 'success'],
        ['export', 'meeting', null, null, ['exported_rows' => 0], '{"permission":"meeting.export","source":"explicit-deny","step":10}', 'denied'],
        ['approve', 'decision', null, null, null, '{"permission":"decision.approve","source":"delegation","step":5}', 'error'],
        ['upload', 'file', null, null, ['object_key' => $key], '{"permission":"file.upload","source":"role","step":5}', 'success'],
    ];
    foreach ($events as [$action, $object, $id, $before, $after, $basis, $result]) {
        $r = $audit->record([
            'actor' => 'lab-admin', 'actor_id' => 1,
            'object_type' => $object, 'object_id' => $id, 'action' => $action,
            'before_state' => $before, 'after_state' => $after,
            'authorization_basis' => $basis, 'result' => $result, 'request_id' => 'lab-' . $action,
        ]);
        Assert::check('evidence appended: ' . $action, $r['ok'] === true && strlen($r['row_hash']) === 64);
    }
    $verified = $audit->verify();
    Assert::check('hash chain verifies over all rows', $verified['ok'] === true, $verified['reason']);
    Assert::check('chain contains all six events', $verified['rows'] === 6, 'rows=' . $verified['rows']);

    $cols = $connA->pdo->query('SHOW COLUMNS FROM gov_evidence_log')->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['actor', 'occurred_at', 'object_type', 'object_id', 'action', 'before_state',
        'after_state', 'authorization_basis', 'result', 'tenant_id'] as $required) {
        Assert::check('evidence column present: ' . $required, in_array($required, $cols, true));
    }
    $sample = $connA->pdo->query('SELECT * FROM gov_evidence_log WHERE action = "update"')->fetch(\PDO::FETCH_ASSOC);
    Assert::check('before/after state actually recorded',
        str_contains((string) $sample['before_state'], '旧值') && str_contains((string) $sample['after_state'], '已修改'));
    Assert::check('authorization basis recorded', str_contains((string) $sample['authorization_basis'], 'structure.update'));

    $appTamper = false;
    try {
        $connA->exec("UPDATE gov_evidence_log SET after_state = 'x' WHERE id = 1");
    } catch (\PDOException $e) {
        $appTamper = true;
    }
    Assert::check('application DB account cannot UPDATE evidence rows', $appTamper);
    $appDelete = false;
    try {
        $connA->exec('DELETE FROM gov_evidence_log WHERE id = 1');
    } catch (\PDOException $e) {
        $appDelete = true;
    }
    Assert::check('application DB account cannot DELETE evidence rows', $appDelete);

    $rootA = labConn($cfg, $cfg['rootUser'], $cfg['rootPass'], $cfg['dbA']);
    $rootUpdate = false;
    try {
        $rootA->exec("UPDATE gov_evidence_log SET actor = 'forged' WHERE id = 1");
    } catch (\PDOException $e) {
        $rootUpdate = true;
    }
    Assert::check('even root is blocked by append-only trigger', $rootUpdate);

    $foreignEvidence = false;
    try {
        $rootA->exec("INSERT INTO gov_evidence_log
            (tenant_id, actor, occurred_at, object_type, action, authorization_basis, result, prev_hash, row_hash)
            VALUES ('church-b', 'intruder', NOW(), 'structure', 'create', 'none', 'success',
                    REPEAT('0',64), REPEAT('1',64))");
    } catch (\PDOException $e) {
        $foreignEvidence = true;
    }
    Assert::check('cross-tenant evidence insert blocked by DB trigger', $foreignEvidence);

    $anchor = $audit->anchor();
    $anchor['marker_id'] = $newId;
    $anchor['marker'] = (string) $connA->pdo->query('SELECT name FROM gov_structure WHERE id = ' . $newId)->fetchColumn();
    @mkdir($cfg['evidence'], 0777, true);
    file_put_contents($cfg['evidence'] . '/pre_anchor.json', json_encode($anchor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    Assert::check('off-database anchor written', is_file($cfg['evidence'] . '/pre_anchor.json'));
    Assert::summary('main');
    echo 'BACKUP_READY' . PHP_EOL;
    exit(Assert::$fail === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
if ($phase === 'tamper') {
    section('T04b 篡改检测（特权账号模拟）');
    $rootA = labConn($cfg, $cfg['rootUser'], $cfg['rootPass'], $cfg['dbA']);
    $rootA->exec('DROP TRIGGER IF EXISTS gov_evidence_no_update');
    $rootA->exec("UPDATE gov_evidence_log SET after_state = 'TAMPERED-BY-PRIVILEGED-ACCOUNT' WHERE id = 2");
    Assert::check('privileged in-place edit succeeded after trigger removal',
        (string) $rootA->pdo->query('SELECT after_state FROM gov_evidence_log WHERE id = 2')->fetchColumn() === 'TAMPERED-BY-PRIVILEGED-ACCOUNT');
    $audit = new GovernanceAuditService($rootA, 'church-a', $cfg['hmac']);
    $broken = $audit->verify();
    Assert::check('hash chain detects the tampering', $broken['ok'] === false, $broken['reason']);
    Assert::check('chain reports the exact tampered row', $broken['broken_at'] === 2, 'id=' . $broken['broken_at']);
    Assert::summary('tamper');
    echo 'RESTORE_READY' . PHP_EOL;
    exit(Assert::$fail === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
if ($phase === 'postverify') {
    section('T08/T09 备份恢复后完整性与服务恢复');
    $connA = labConn($cfg, $cfg['userA'], $cfg['passA'], $cfg['dbA']);
    $audit = new GovernanceAuditService($connA, 'church-a', $cfg['hmac']);
    $pre = json_decode((string) file_get_contents($cfg['evidence'] . '/pre_anchor.json'), true);
    $post = $audit->anchor();
    Assert::check('restored chain verifies', $audit->verify()['ok'] === true);
    Assert::check('restored evidence row count matches pre-backup anchor',
        $post['rows'] === (int) $pre['rows'], 'pre=' . $pre['rows'] . ' post=' . $post['rows']);
    Assert::check('restored chain head hash matches pre-backup anchor',
        $post['last_hash'] === (string) $pre['last_hash']);
    Assert::check('tampered value is gone after restore',
        !str_contains((string) $connA->pdo->query('SELECT after_state FROM gov_evidence_log WHERE id = 2')->fetchColumn(), 'TAMPERED'));
    Assert::check('business data restored',
        (string) $connA->pdo->query('SELECT name FROM gov_structure WHERE id = ' . (int) $pre['marker_id'])->fetchColumn() === (string) $pre['marker']);
    $triggers = $connA->pdo->query("SHOW TRIGGERS LIKE 'gov_evidence_log'")->fetchAll(\PDO::FETCH_ASSOC);
    Assert::check('append-only triggers restored with the backup', count($triggers) >= 2, 'count=' . count($triggers));
    $restoreAppTamper = false;
    try {
        $connA->exec("UPDATE gov_evidence_log SET actor = 'x' WHERE id = 1");
    } catch (\PDOException $e) {
        $restoreAppTamper = true;
    }
    Assert::check('application account still cannot modify evidence after restore', $restoreAppTamper);
    Assert::summary('postverify');
    exit(Assert::$fail === 0 ? 0 : 1);
}

fwrite(STDERR, 'Unknown phase: ' . $phase . PHP_EOL);
exit(2);
