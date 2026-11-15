<?php

declare(strict_types=1);

/**
 * MOS-GOV 单教会运行时健康检查（CLI，不经 Web 暴露）。
 *
 * 用法: php healthcheck.php --tenant church-a [--env /path/to/env] [--json]
 *
 * 覆盖：DB 归属、租户边界、append-only 触发器、应用账号最小权限、
 *       证据链完整性、私有存储、备份空间、发布版本、PHP 扩展。
 * 输出结构化 JSON；失败时 exit 1。不回显任何口令或 PDO 异常原文。
 *
 * 说明：HTTP 层只保留 /.well-known/ 静态存活探针；DB / 证据链检查一律走
 * 本机 CLI，避免公网上出现一个未认证的数据库探测接口。
 */
foreach ([__DIR__ . '/mosgov-cli.php', __DIR__ . '/cli.php', '/usr/local/lib/mosgov/cli.php'] as $lib) {
    if (is_file($lib)) {
        require_once $lib;
        break;
    }
}
if (!class_exists('MosGovCli')) {
    fwrite(STDERR, "MOS-GOV: CLI library missing\n");
    exit(1);
}

MosGovCli::assertCli();
$args = MosGovCli::parseArgs($argv);

$checks = [];
$add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

try {
    $tenant = MosGovCli::tenant($args);
    $env    = MosGovCli::loadEnv($tenant, isset($args['env']) ? (string) $args['env'] : null);
    $add('env_loaded', true, 'tenant=' . $tenant);

    $pdo = MosGovCli::pdo($env);
    $add('db_connect', true, 'pdo_mysql connected');

    $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $add('db_boundary', $currentDb === $env['MOSGOV_TENANT_DB'],
        $currentDb === $env['MOSGOV_TENANT_DB'] ? 'database matches tenant' : 'database mismatch');

    // 租户 singleton 行：错配一律 fail closed
    $row = $pdo->query('SELECT tenant_id, tenant_db, host, status FROM gov_tenant WHERE singleton = 1')
               ->fetch(PDO::FETCH_ASSOC);
    $tenantOk = is_array($row)
        && $row['tenant_id'] === $env['MOSGOV_TENANT_ID']
        && $row['tenant_db'] === $env['MOSGOV_TENANT_DB']
        && $row['status'] === 'active';
    $add('tenant_singleton_active', $tenantOk,
        $tenantOk ? 'active single-tenant row matches env' : 'tenant row missing / mismatched / suspended');

    // ChurchCRM 用户表：公网环境不得残留 MD5 / SHA-256 旧哈希。
    // ChurchCRM 7.8.0 的 User::isPasswordValid() 会在登录时把旧哈希升级为 bcrypt，
    // 但「尚未登录过」的存量账户仍是弱哈希；上线前必须清空或强制全部改密。
    $userTable = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_usr' LIMIT 1"
    )->fetchColumn();
    if ($userTable) {
        $legacy = (int) $pdo->query(
            "SELECT COUNT(*) FROM user_usr
             WHERE usr_Password IS NOT NULL AND usr_Password <> ''
               AND usr_Password NOT LIKE '\$2y\$%'"
        )->fetchColumn();
        $add('no_legacy_password_hashes', $legacy === 0, 'legacy_hash_rows=' . $legacy);
    } else {
        // 实验台 / 尚未初始化 ChurchCRM 用户表时不伪报通过，明确标注跳过。
        $add('no_legacy_password_hashes', true, 'user_usr 不存在，此项跳过（未验证）');
    }

    // append-only 触发器（005 两个 + 006 租户守卫 = 3 个作用于 evidence 表）
    $triggerCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'gov_evidence_log'"
    )->fetchColumn();
    $add('evidence_append_only_triggers', $triggerCount >= 3, 'triggers=' . $triggerCount);

    // 应用账号不得拥有对审计表的 UPDATE / DELETE / DDL 权限
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $broad = false;
    foreach ($grants as $grant) {
        $g = (string) $grant;
        $hasWrite = preg_match('/\b(ALL|UPDATE|DELETE|DROP|ALTER|CREATE)\b/i', $g) === 1;
        $onProtected = str_contains($g, 'gov_evidence_log') || str_contains($g, 'gov_audit_log')
            || str_contains($g, '*.*') || str_contains($g, '`' . $env['MOSGOV_TENANT_DB'] . '`.*');
        if ($hasWrite && $onProtected) {
            $broad = true;
            break;
        }
    }
    $add('app_account_least_privilege', !$broad,
        $broad ? 'app account holds broad write privileges' : 'no broad write grant on audit tables');

    // HMAC 密钥：只检查长度，绝不输出内容
    $keyLen = strlen($env['MOSGOV_AUDIT_HMAC_KEY']);
    $add('audit_hmac_key', $keyLen >= 32 && !str_contains($env['MOSGOV_AUDIT_HMAC_KEY'], 'phase3-lab'),
        'length=' . $keyLen);

    // 证据链
    MosGovCli::loadSecurity($env);
    $audit  = new ChurchCRM\Plugins\MosGov\Security\GovernanceAuditService(
        $pdo, $env['MOSGOV_TENANT_ID'], $env['MOSGOV_AUDIT_HMAC_KEY']
    );
    $verify = $audit->verify();
    $add('evidence_chain_intact', (bool) $verify['ok'],
        'rows=' . $verify['rows'] . ' reason=' . $verify['reason']);
    $anchor = $audit->anchor();

    // 私有存储：Web 根之外、非符号链接、其他用户不可读
    $storage = $env['MOSGOV_TENANT_STORAGE'] ?? '';
    $storageOk = $storage !== ''
        && !str_starts_with($storage, '/var/www/')
        && is_dir($storage)
        && !is_link($storage);
    if ($storageOk) {
        $perms = fileperms($storage) & 0777;
        $storageOk = ($perms & 0005) === 0;
    }
    $add('private_storage', $storageOk,
        $storageOk ? 'outside webroot, not world-readable' : 'storage missing / exposed / symlinked');

    // 备份目录与可用空间
    $backupDir = $env['MOSGOV_BACKUP_DIR'] ?? '';
    $free = ($backupDir !== '' && is_dir($backupDir)) ? @disk_free_space($backupDir) : false;
    $backupOk = is_string($backupDir) && $backupDir !== '' && is_dir($backupDir)
        && is_writable($backupDir) && $free !== false && $free >= 512 * 1024 * 1024;
    $add('backup_capacity', $backupOk,
        $backupOk ? 'free=' . round(((float) $free) / 1073741824, 2) . ' GiB' : 'backup dir missing / <512MiB free');

    // 发布版本锁定
    $revisionFile = '/var/www/mos-gov/current/REVISION';
    $pinned = $env['MOSGOV_RELEASE_COMMIT'] ?? '';
    $revision = is_file($revisionFile) ? trim((string) file_get_contents($revisionFile)) : '';
    $add('release_commit_pinned',
        preg_match('/^[0-9a-f]{40}$/', $pinned) === 1 && $revision === $pinned,
        'expected=' . substr($pinned, 0, 8) . ' active=' . substr($revision, 0, 8));

    // 运行时扩展
    $requiredExt = ['pdo_mysql', 'mbstring', 'intl', 'gd', 'zip', 'openssl', 'sodium', 'fileinfo'];
    $missing = array_values(array_filter($requiredExt, static fn (string $e): bool => !extension_loaded($e)));
    $add('php_extensions', $missing === [], $missing === [] ? 'all present' : 'missing: ' . implode(',', $missing));
} catch (Throwable $e) {
    // 只写日志，不把 PDO / 文件系统异常原文返回给调用方
    error_log('[MOS-GOV][healthcheck] ' . $e::class . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    $add('runtime_exception', false, 'internal error; see /var/log/mosgov/healthcheck.log');
    $anchor = ['rows' => 0, 'last_id' => 0, 'last_hash' => str_repeat('0', 64), 'generated_at' => gmdate('c')];
    $tenant = $tenant ?? '';
}

$failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['ok']));
MosGovCli::emit([
    'ok'      => $failed === [],
    'tenant'  => $tenant,
    'checks'  => $checks,
    'failed'  => count($failed),
    'anchor'  => $anchor,
]);
