<?php

declare(strict_types=1);

/**
 * MOS-GOV 首个教会管理员创建（CLI，B4）。仅供命令行调用，不通过 Web 暴露。
 *
 * 背景（以 ChurchCRM 7.8.0 官方 Install.sql 与源码为据，非猜测）：
 *   - user_usr 主键 = usr_per_ID；usr_UserName 为唯一键（utf8 COLLATE utf8_unicode_ci，
 *     大小写不敏感）；usr_Password varchar(500) 存 bcrypt（password_hash(PASSWORD_DEFAULT)）。
 *   - 官方 Install.sql 预置 per_ID=1 / usr_per_ID=1 / usr_UserName='Admin' / usr_Admin=1 /
 *     usr_NeedPasswordChange=1，口令 hash 是公开的。它属于「安装产物」，不是教会设的管理员，
 *     本命令不得覆盖或重置它（详见下方 seed 处理）。
 *   - P9 既定流程是「另建一个强密码管理员」，本命令把这一步脚本化。
 *
 * 安全约定：
 *   - 强制 --tenant；env 的 MOSGOV_TENANT_ID 必须与 tenant、gov_tenant singleton 三者一致，
 *     否则 fail-closed（杜绝跨租户操作）。
 *   - 明文密码只写入 --pass-file（0400/0600，调用方负责交给人后 shred）；
 *     结构化输出绝不回显密码、哈希或 apiKey；不写任何日志文件。
 *   - 参数一律用 PDO 预处理语句绑定；枚举值在白名单内，绝不拼接进 SQL。
 *   - person + user 同一事务；任何失败 rollback，绝不留半创建账户。
 *   - 默认拒绝重复创建：目标用户名已存在，或已存在非 seed 管理员，均安全退出（退出码 4），
 *     不修改任何现有账户。
 *
 * 用法:
 *   php create-admin.php --tenant church-a --pass-file /root/.church-a-admin.pass \
 *       [--username admin] [--first-name Church] [--last-name Admin] [--email admin@example.org]
 *
 * 退出码:
 *   0 成功；2 参数/输入非法；3 前置条件不满足（租户/DB/表）；
 *   4 管理员已存在（安全退出，未改动）；5 DB 写入失败（已回滚）；6 凭据文件写入失败。
 */
// 逐个定位 CLI 公共库（仓库内 mosgov-cli.php / cli.php，或已安装位置）。
$lib = '';
foreach ([
    __DIR__ . '/mosgov-cli.php',
    __DIR__ . '/cli.php',
    '/usr/local/lib/mosgov/cli.php',
    '/usr/local/lib/mosgov/mosgov-cli.php',
] as $candidate) {
    if (is_file($candidate)) {
        $lib = $candidate;
        break;
    }
}
unset($candidate);
if ($lib === '') {
    fwrite(STDERR, "MOS-GOV create-admin: CLI library missing\n");
    exit(1);
}
require_once $lib;
if (!class_exists('MosGovCli')) {
    fwrite(STDERR, "MOS-GOV create-admin: MosGovCli class missing\n");
    exit(1);
}

MosGovCli::assertCli();

/** 统一结构化输出；失败一律非 0 退出码，且 payload 不含敏感值。 */
function out(array $payload): never
{
    $payload['generated_at'] = gmdate('c');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(($payload['ok'] ?? false) === true ? 0 : (int) ($payload['exit_code'] ?? 1));
}

/** 审计只记事件元数据，绝不记密码/哈希/凭据内容。 */
function auditEvent(string $event, array $fields = []): void
{
    $line = ['event' => $event, 'ts' => gmdate('c')] + $fields;
    fwrite(STDERR, '[mosgov-audit] ' . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

$args = MosGovCli::parseArgs($argv);

// ---- 1. 参数与输入校验（严格白名单，参数化 SQL 之外再设一道） --------------------
try {
    $tenant = MosGovCli::tenant($args); // /^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$/
} catch (Throwable $e) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'missing_or_invalid_tenant']);
}

$username   = (string) ($args['username'] ?? 'admin');
$firstName  = (string) ($args['first-name'] ?? 'Church');
$lastName   = (string) ($args['last-name'] ?? 'Admin');
$email      = array_key_exists('email', $args) ? (string) $args['email'] : '';
$passFile   = (string) ($args['pass-file'] ?? '');
$envOverride = isset($args['env']) ? (string) $args['env'] : null;

// ChurchCRM UserService::createUser 约束：用户名 3..50 字符；这里额外限制字符集，
// 防止引号/空白/控制字符进入唯一键（即便已参数化，也拒绝异常输入）。
if (preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) !== 1) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'invalid_username',
         'detail' => 'username must be 3-50 chars of [A-Za-z0-9._-]']);
}
if (strlen($firstName) < 1 || strlen($firstName) > 50
    || strlen($lastName) < 1 || strlen($lastName) > 50
    || preg_match('/[\x00-\x1f\x7f]/', $firstName.$lastName) === 1) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'invalid_name']);
}
if ($email !== '' && (strlen($email) > 50 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'invalid_email']);
}
// 绝对路径：POSIX /... 或 Windows X:/...；后者仅为在隔离实验机上测试所需，
// 生产（Linux）只会匹配 /...，不改变安全语义。
$isAbsPath = $passFile !== ''
    && ($passFile[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $passFile) === 1);
if (!$isAbsPath || is_dir($passFile) || is_link($passFile)) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'invalid_pass_file',
         'detail' => '--pass-file must be an absolute path to a new regular file']);
}
if (file_exists($passFile)) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'pass_file_exists',
         'detail' => 'refusing to overwrite an existing credential file']);
}
$passDir = dirname($passFile);
if (!is_dir($passDir)) {
    out(['ok' => false, 'exit_code' => 2, 'reason' => 'pass_dir_missing']);
}

// ---- 2. 租户绑定与前置检查（fail-closed，杜绝跨租户） ------------------------------
try {
    $env = MosGovCli::loadEnv($tenant, $envOverride); // 内部断言 env.TENANT_ID === tenant
    $pdo = MosGovCli::pdo($env);
} catch (Throwable $e) {
    auditEvent('preflight_failed', ['tenant' => $tenant, 'stage' => 'env_or_db']);
    out(['ok' => false, 'exit_code' => 3, 'reason' => 'tenant_or_db_unavailable', 'tenant' => $tenant]);
}

try {
    // 2a. 核心表必须齐备（person_per / user_usr / gov_tenant）。
    $need = ['person_per', 'user_usr', 'gov_tenant'];
    $ph   = implode(',', array_fill(0, count($need), '?'));
    $st   = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
    );
    $st->execute($need);
    $present = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($present) !== count($need)) {
        out(['ok' => false, 'exit_code' => 3, 'tenant' => $tenant,
             'reason' => 'schema_incomplete', 'detail' => 'core tables missing']);
    }

    // 2b. gov_tenant singleton 三向校验：tenant_id / tenant_db / status。
    //     env 已保证 TENANT_ID===tenant；这里再锚定库内 singleton，防止 env 与库不匹配。
    $t = $pdo->query('SELECT tenant_id, tenant_db, status FROM gov_tenant WHERE singleton = 1')
             ->fetch(PDO::FETCH_ASSOC);
    if ($t === false
        || !hash_equals((string) $t['tenant_id'], $tenant)
        || !hash_equals((string) $t['tenant_db'], (string) $env['MOSGOV_TENANT_DB'])
        || (string) $t['status'] !== 'active') {
        auditEvent('preflight_failed', ['tenant' => $tenant, 'stage' => 'tenant_anchor_mismatch']);
        out(['ok' => false, 'exit_code' => 3, 'tenant' => $tenant,
             'reason' => 'tenant_anchor_mismatch',
             'detail' => 'gov_tenant singleton does not match tenant/env (fail closed)']);
    }

    // 2c. 普通用户的权限字段必须存在（确保是 7.8.0 结构，避免旧 schema 半字段）。
    $cols = $pdo->query('SHOW COLUMNS FROM user_usr')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['usr_per_ID', 'usr_UserName', 'usr_Password', 'usr_NeedPasswordChange',
              'usr_Admin', 'usr_AddRecords', 'usr_EditRecords', 'usr_DeleteRecords',
              'usr_MenuOptions', 'usr_ManageGroups', 'usr_Finance', 'usr_Notes'] as $c) {
        if (!in_array($c, $cols, true)) {
            out(['ok' => false, 'exit_code' => 3, 'tenant' => $tenant,
                 'reason' => 'user_usr_schema_mismatch', 'detail' => 'missing column ' . $c]);
        }
    }
} catch (Throwable $e) {
    out(['ok' => false, 'exit_code' => 3, 'tenant' => $tenant, 'reason' => 'preflight_error']);
}

// ---- 3. 幂等：默认拒绝重复创建，绝不覆盖/重置现有账户 --------------------------
try {
    // 3a. 目标用户名是否已占用（唯一键大小写不敏感，用 LOWER 对齐判定）。
    $st = $pdo->prepare('SELECT COUNT(*) FROM user_usr WHERE LOWER(usr_UserName) = LOWER(?)');
    $st->execute([$username]);
    if ((int) $st->fetchColumn() > 0) {
        auditEvent('create_refused', ['tenant' => $tenant, 'reason' => 'username_exists']);
        out(['ok' => false, 'exit_code' => 4, 'tenant' => $tenant,
             'action' => 'refused', 'reason' => 'username_exists',
             'detail' => 'user already exists; no changes made']);
    }

    // 3b. 是否已存在「非 seed 管理员」。seed 账号 usr_per_ID=1 是 Install.sql 安装产物，
    //     公开默认口令，不算教会管理员；但也不得覆盖（本命令从不 UPDATE/DELETE 任何行）。
    //     若已有教会自建管理员，则视为已初始化，拒绝再建第二个。
    $st = $pdo->query('SELECT COUNT(*) FROM user_usr WHERE usr_Admin = 1 AND usr_per_ID <> 1');
    if ((int) $st->fetchColumn() > 0) {
        auditEvent('create_refused', ['tenant' => $tenant, 'reason' => 'admin_exists']);
        out(['ok' => false, 'exit_code' => 4, 'tenant' => $tenant,
             'action' => 'refused', 'reason' => 'admin_exists',
             'detail' => 'a non-seed administrator already exists; no changes made']);
    }
} catch (Throwable $e) {
    out(['ok' => false, 'exit_code' => 5, 'tenant' => $tenant, 'reason' => 'check_failed']);
}

// ---- 4. 安全生成初始凭据 -------------------------------------------------------
// 32 位十六进制（128-bit 随机），纯 hex 避免 option file / shell 转义问题；
// 仅写入 --pass-file，不进入结构化输出、不写日志、不进 Git。
$plain = bin2hex(random_bytes(16));
$hash  = password_hash($plain, PASSWORD_DEFAULT); // 与 ChurchCRM User::hashPassword 一致
if (!is_string($hash) || str_starts_with($hash, '$2y$') !== true) {
    out(['ok' => false, 'exit_code' => 5, 'tenant' => $tenant, 'reason' => 'hash_failed']);
}

// 用 O_EXCL 原子创建，防止竞态覆盖；权限 0600。
$fh = @fopen($passFile, 'xb');
if ($fh === false) {
    out(['ok' => false, 'exit_code' => 6, 'tenant' => $tenant, 'reason' => 'pass_file_write_failed']);
}
$wrote = fwrite($fh, $plain . "\n");
fclose($fh);
@chmod($passFile, 0600);
if ($wrote === false) {
    @unlink($passFile);
    out(['ok' => false, 'exit_code' => 6, 'tenant' => $tenant, 'reason' => 'pass_file_write_failed']);
}
// 从内存清除明文引用（PHP 字符串不可真正的 mlock，这里尽力而为）。
unset($plain);

// ---- 5. 事务创建 person + user（原子，不留半账户） ------------------------------
try {
    $pdo->beginTransaction();

    $st = $pdo->prepare(
        'INSERT INTO person_per (per_FirstName, per_LastName, per_Email, per_DateEntered, per_EnteredBy)
         VALUES (?, ?, ?, NOW(), 1)'
    );
    $emailParam = $email === '' ? null : $email;
    $st->execute([$firstName, $lastName, $emailParam]);
    $personId = (int) $pdo->lastInsertId();
    if ($personId <= 0) {
        throw new RuntimeException('bad person id');
    }

    // 管理员全权限：与 ChurchCRM createUser(admin) 的 normalizeAccessMode 一致。
    // usr_NeedPasswordChange=1：首次登录由 LocalAuthentication 强制跳转改密页。
    $st = $pdo->prepare(
        'INSERT INTO user_usr
          (usr_per_ID, usr_UserName, usr_Password, usr_NeedPasswordChange,
           usr_Admin, usr_AddRecords, usr_EditRecords, usr_DeleteRecords,
           usr_MenuOptions, usr_ManageGroups, usr_Finance, usr_Notes,
           usr_LastLogin, usr_LoginCount, usr_FailedLogins, usr_EditSelf)
         VALUES (?, ?, ?, 1, 1,1,1,1,1,1,1,1, NOW(), 0, 0, 0)'
    );
    $st->execute([$personId, $username, $hash]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($passFile); // 账户未建成，凭据文件一并销毁，绝不留下孤立明文
    auditEvent('create_failed', ['tenant' => $tenant, 'stage' => 'db_write']);
    out(['ok' => false, 'exit_code' => 5, 'tenant' => $tenant,
         'reason' => 'db_write_failed', 'detail' => 'transaction rolled back; no partial account']);
}

auditEvent('admin_created', [
    'tenant'   => $tenant,
    'username' => $username,
    'person_id'=> $personId,
]);

// 输出绝不含密码 / 哈希 / apiKey。
out([
    'ok'                     => true,
    'exit_code'              => 0,
    'action'                 => 'created',
    'tenant'                 => $tenant,
    'database'               => (string) $env['MOSGOV_TENANT_DB'],
    'username'               => $username,
    'person_id'              => $personId,
    'need_password_change'   => true,
    'seed_admin_untouched'   => true,
    'credential_file'        => $passFile,
    'credential_file_mode'   => '0600',
    'next_steps'             => [
        '1) 用一次性安全渠道把 --pass-file 中的初始口令交给教会负责人；',
        '2) 首次登录会被强制修改密码（usr_NeedPasswordChange=1）；',
        '3) 转交后立即 shred -u 删除该凭据文件。',
    ],
]);
