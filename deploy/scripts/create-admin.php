<?php

declare(strict_types=1);

/**
 * MOS-GOV 首个教会管理员创建（CLI，B4）。仅供命令行调用，不通过 Web 暴露。
 *
 * 背景（以 ChurchCRM 7.8.0 官方 Install.sql 与源码为据，非猜测）：
 *   - user_usr 主键 = usr_per_ID；usr_UserName 为唯一键（utf8 COLLATE utf8_unicode_ci，
 *     大小写不敏感）；usr_Password varchar(500) 存 bcrypt（password_hash(PASSWORD_DEFAULT)）。
 *   - 官方 Install.sql 预置 per_ID=1 / usr_per_ID=1 / usr_UserName='Admin' / usr_Admin=1 /
 *     usr_NeedPasswordChange=1，口令 hash 是公开的（默认口令公开可得）。它属于「安装产物」，
 *     不是教会设的管理员；公开默认凭据可被直接用于管理员登录（P7.2 已证），是本命令要终结的风险。
 *   - P9 既定流程是「另建一个强密码管理员」，本命令把这一步脚本化。
 *   - 为了让部署后不再存在可用的默认管理员，本命令在「创建正式管理员」的同一事务内，
 *     核实并停用官方 seed（只置 per_DateDeactivated，绝不删除），提交前双重校验，失败整体回滚。
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
 *   4 管理员已存在（安全退出，未改动）；5 DB 写入失败（已回滚）；
 *   6 凭据文件写入失败；7 seed Admin 核实/停用校验失败（已回滚，seed 未改动）。
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

// ---- 5. 事务：创建 person + user，并核实/停用官方 seed Admin（原子） ----------------
// 顺序：建正式管理员 → 写全权限 → 核实 seed 身份 → 停用 seed → 提交前双重校验。
// 任一步失败整体回滚：绝不允许「新管理员建成但默认管理员仍可用」或「默认已停用但新管理员没建成」
// 的半完成状态。
$seedState = 'unknown';
try {
    $pdo->beginTransaction();

    // 5a. 正式管理员的人员记录。
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

    // 5b. 正式管理员账户：全权限（与 createUser(admin) 的 normalizeAccessMode 一致）；
    //     usr_NeedPasswordChange=1 让首次登录被 LocalAuthentication 强制跳改密页。
    $st = $pdo->prepare(
        'INSERT INTO user_usr
          (usr_per_ID, usr_UserName, usr_Password, usr_NeedPasswordChange,
           usr_Admin, usr_AddRecords, usr_EditRecords, usr_DeleteRecords,
           usr_MenuOptions, usr_ManageGroups, usr_Finance, usr_Notes,
           usr_LastLogin, usr_LoginCount, usr_FailedLogins, usr_EditSelf)
         VALUES (?, ?, ?, 1, 1,1,1,1,1,1,1,1, NOW(), 0, 0, 0)'
    );
    $st->execute([$personId, $username, $hash]);

    // 5c. 核实并停用官方 seed Admin（per_ID=1）。
    //     绝不只凭 per_ID 就修改：必须同时满足官方 Install.sql 的 seed 画像
    //     （person: Church / Admin / 建档 '2004-08-25 18:00:00'；user: Admin / usr_Admin=1）。
    //     不匹配则判定「无法确认为 seed」→ 抛错回滚，避免误伤真实人员或遗漏默认管理员。
    $seed = $pdo->query(
        "SELECT p.per_ID, p.per_FirstName, p.per_LastName, p.per_DateEntered,
                p.per_DateDeactivated,
                u.usr_per_ID, u.usr_UserName, u.usr_Admin
           FROM person_per p
           LEFT JOIN user_usr u ON u.usr_per_ID = p.per_ID
          WHERE p.per_ID = 1
          LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($seed === false || $seed['usr_per_ID'] === null) {
        // 目标库没有 seed 登录账户（可能已被管理员移除）：无默认凭据风险，视为已满足。
        $seedState = 'absent';
    } else {
        $isOfficialSeed =
            strcasecmp((string) $seed['per_FirstName'], 'Church') === 0
            && strcasecmp((string) $seed['per_LastName'], 'Admin') === 0
            && (string) $seed['per_DateEntered'] === '2004-08-25 18:00:00'
            && strcasecmp((string) $seed['usr_UserName'], 'Admin') === 0
            && (int) $seed['usr_Admin'] === 1;

        if (!$isOfficialSeed) {
            throw new RuntimeException('seed admin identity not verified');
        }

        if ($seed['per_DateDeactivated'] === null) {
            // 只置停用日期，不删除任何记录；条件化确保只影响仍处于「未停用」的这一行。
            $up = $pdo->prepare(
                'UPDATE person_per SET per_DateDeactivated = CURDATE()
                  WHERE per_ID = 1 AND per_DateDeactivated IS NULL'
            );
            $up->execute();
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('seed deactivation affected unexpected rows');
            }
            $seedState = 'disabled';
        } else {
            $seedState = 'already_disabled';
        }
    }

    // 5d. 提交前双重校验：正式管理员存在且具管理员权限；seed 已停用（或不存在）。
    $chk = $pdo->prepare(
        'SELECT usr_Admin FROM user_usr WHERE usr_per_ID = ? AND LOWER(usr_UserName) = LOWER(?)'
    );
    $chk->execute([$personId, $username]);
    $newAdmin = $chk->fetchColumn();
    if ($newAdmin === false || (int) $newAdmin !== 1) {
        throw new RuntimeException('new administrator not verified');
    }
    if ($seedState === 'disabled' || $seedState === 'already_disabled') {
        $deact = $pdo->query('SELECT per_DateDeactivated FROM person_per WHERE per_ID = 1')->fetchColumn();
        if ($deact === false || $deact === null) {
            throw new RuntimeException('seed admin still active before commit');
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($passFile); // 账户未建成，凭据文件一并销毁，绝不留下孤立明文
    $msg = $e->getMessage();
    $seedFailure = in_array($msg, [
        'seed admin identity not verified',
        'seed deactivation affected unexpected rows',
        'seed admin still active before commit',
        'new administrator not verified',
    ], true);
    $reason = $seedFailure ? 'seed_admin_verification_failed' : 'db_write_failed';
    $code   = $seedFailure ? 7 : 5;
    auditEvent('create_failed', ['tenant' => $tenant, 'stage' => 'db_write', 'reason' => $reason]);
    out(['ok' => false, 'exit_code' => $code, 'tenant' => $tenant,
         'reason' => $reason,
         'detail' => 'transaction rolled back; no partial account, seed left unchanged']);
}

auditEvent('admin_created', [
    'tenant'   => $tenant,
    'username' => $username,
    'person_id'=> $personId,
]);
auditEvent('seed_admin_handled', [
    'tenant'     => $tenant,
    'person_id'  => 1,
    'seed_state' => $seedState, // disabled | already_disabled | absent
    'method'     => 'per_DateDeactivated',
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
    'seed_admin_disabled'    => in_array($seedState, ['disabled', 'already_disabled'], true),
    'seed_admin_state'       => $seedState,
    'credential_file'        => $passFile,
    'credential_file_mode'   => '0600',
    'next_steps'             => [
        '1) 用一次性安全渠道把 --pass-file 中的初始口令交给教会负责人；',
        '2) 首次登录会被强制修改密码（usr_NeedPasswordChange=1）；',
        '3) 转交后立即 shred -u 删除该凭据文件。',
        '4) seed Admin（per_ID=1）已被停用；如需恢复见 RUNBOOK 恢复说明。',
    ],
]);
