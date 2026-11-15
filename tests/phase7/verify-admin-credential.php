<?php

declare(strict_types=1);

/**
 * Phase 7.1 测试辅助：在不回显明文口令的前提下，核验 create-admin 的产物：
 *   1. --pass-file 中的口令能通过 password_verify(user_usr.usr_Password)；
 *   2. usr_NeedPasswordChange = 1（首次登录强制改密）；
 *   3. usr_Admin = 1 且各权限位为 1；
 *   4. 口令长度 >= 32，哈希为 bcrypt($2y$)。
 *
 * 只输出 PASS/FAIL 与计数，绝不打印口令或哈希原文。
 * 用法:
 *   php -c <ini> verify-admin-credential.php --env <file> --pass-file <file> --username <name>
 */
foreach ([__DIR__ . '/../../deploy/scripts/mosgov-cli.php', '/usr/local/lib/mosgov/cli.php'] as $lib) {
    if (is_file($lib)) { require_once $lib; break; }
}
if (!class_exists('MosGovCli')) {
    fwrite(STDERR, "FAIL: MosGovCli missing\n"); exit(2);
}
MosGovCli::assertCli();
$args = MosGovCli::parseArgs($argv);

$envFile = (string) ($args['env'] ?? '');
$passFile = (string) ($args['pass-file'] ?? '');
$username = (string) ($args['username'] ?? '');
$tenant = (string) ($args['tenant'] ?? '');

$fail = 0;
function chk(bool $cond, string $label): void {
    global $fail;
    printf("%-4s %s\n", $cond ? 'PASS' : 'FAIL', $label);
    if (!$cond) { $fail++; }
}

try {
    if ($envFile === '' || $passFile === '' || $username === '') {
        throw new RuntimeException('missing args');
    }
    // 复用生产库的 env 解析器（含 TENANT_ID 一致性 fail-closed）。
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = rtrim($line, "\r");
        if ($line === '' || str_starts_with($line, '#')) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $env[$k] = $v;
    }
    if ($tenant !== '' && ($env['MOSGOV_TENANT_ID'] ?? '') !== $tenant) {
        throw new RuntimeException('env tenant mismatch');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['MOSGOV_DB_HOST'], $env['MOSGOV_DB_PORT'], $env['MOSGOV_TENANT_DB']),
        $env['MOSGOV_DB_USER'], $env['MOSGOV_DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $plain = rtrim(file_get_contents($passFile) ?: '', "\r\n");
    chk(strlen($plain) >= 32, 'credential length >= 32');

    $st = $pdo->prepare('SELECT usr_per_ID, usr_Password, usr_NeedPasswordChange, usr_Admin,
                                usr_AddRecords, usr_EditRecords, usr_DeleteRecords,
                                usr_MenuOptions, usr_ManageGroups, usr_Finance, usr_Notes,
                                usr_LoginCount, usr_FailedLogins
                         FROM user_usr WHERE usr_UserName = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    chk($row !== false, 'admin row exists for username');
    if ($row === false) { throw new RuntimeException('no row'); }

    chk((int) $row['usr_per_ID'] !== 1, 'new admin is NOT the seed account (per_ID != 1)');
    chk(str_starts_with((string) $row['usr_Password'], '$2y$'), 'password hash is bcrypt');
    chk(password_verify($plain, (string) $row['usr_Password']), 'pass-file verifies against stored hash');
    chk((int) $row['usr_NeedPasswordChange'] === 1, 'usr_NeedPasswordChange = 1 (forced change)');
    chk((int) $row['usr_Admin'] === 1, 'usr_Admin = 1');
    foreach (['usr_AddRecords', 'usr_EditRecords', 'usr_DeleteRecords', 'usr_MenuOptions',
              'usr_ManageGroups', 'usr_Finance', 'usr_Notes'] as $col) {
        chk((int) $row[$col] === 1, $col . ' = 1');
    }
    chk((int) $row['usr_LoginCount'] === 0 && (int) $row['usr_FailedLogins'] === 0,
        'fresh counters (login=0, failed=0)');

    // 确认 seed 账号未被改动（首次登录仍为公开 hash + NeedPasswordChange=1）。
    $seed = $pdo->query('SELECT usr_Password, usr_NeedPasswordChange, usr_Admin
                         FROM user_usr WHERE usr_per_ID = 1')->fetch();
    chk($seed !== false, 'seed account still present');
    if ($seed !== false) {
        chk((int) $seed['usr_NeedPasswordChange'] === 1 && (int) $seed['usr_Admin'] === 1,
            'seed account untouched (admin=1, need-change=1)');
    }
} catch (Throwable $e) {
    chk(false, 'exception: ' . $e::class);
}

printf("RESULT %s (%d failures)\n", $fail === 0 ? 'PASS' : 'FAIL', $fail);
exit($fail === 0 ? 0 : 1);
