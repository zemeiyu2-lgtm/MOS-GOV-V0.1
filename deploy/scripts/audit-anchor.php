<?php

declare(strict_types=1);

/**
 * MOS-GOV 治理证据链「库外每日锚点」（CLI，由 mosgov-audit-anchor.sh 每日调用）。
 *
 * 用法:
 *   php audit-anchor.php --tenant church-a --out /var/backups/mosgov/church-a/anchors
 *   php audit-anchor.php --tenant church-a --verify-only
 *
 * 为什么需要：HMAC 哈希链能发现「改一行」，但如果攻击者能删库重建整条链并持有
 * HMAC 密钥，链本身仍会自洽。每天把 (行数, 末行 id, 末行 hash) 写到数据库之外、
 * 且不可覆盖的锚点文件，再由 mosgov-audit-anchor.sh 同步到无 DeleteObject 权限的
 * 对象存储，才能发现「整链重建」。
 *
 * 锚点文件只写新文件（同秒加序号），绝不覆盖；权限 0400；绝不写入数据库表。
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

try {
    $tenant = MosGovCli::tenant($args);
    $env    = MosGovCli::loadEnv($tenant, isset($args['env']) ? (string) $args['env'] : null);
    $pdo    = MosGovCli::pdo($env);

    MosGovCli::loadSecurity($env);
    $audit  = new ChurchCRM\Plugins\MosGov\Security\GovernanceAuditService(
        $pdo, $env['MOSGOV_TENANT_ID'], $env['MOSGOV_AUDIT_HMAC_KEY']
    );

    $verify = $audit->verify();
    if (!$verify['ok']) {
        // 链已损坏：不写新的锚点，避免把损坏状态固化成「新的可信起点」
        error_log(sprintf(
            '[MOS-GOV][anchor] tenant=%s chain BROKEN rows=%d at=%s reason=%s',
            $tenant, $verify['rows'], $verify['broken_at'] ?? 0, $verify['reason']
        ));
        MosGovCli::emit([
            'ok' => false, 'tenant' => $tenant, 'action' => 'refused',
            'reason' => 'evidence chain broken; anchor NOT written', 'verify' => $verify,
        ]);
    }

    $anchor = $audit->anchor();
    if (isset($args['verify-only'])) {
        MosGovCli::emit([
            'ok' => true, 'tenant' => $tenant, 'action' => 'verify-only',
            'verify' => $verify, 'anchor' => $anchor,
        ]);
    }

    $out = (string) ($args['out'] ?? ($env['MOSGOV_BACKUP_DIR'] ?? '') . '/anchors');
    if ($out === '' || (!is_dir($out) && !mkdir($out, 0700, true) && !is_dir($out))) {
        throw new RuntimeException('Anchor output directory unavailable');
    }
    if (is_link($out)) {
        throw new RuntimeException('Anchor output directory is a symlink');
    }

    $payload = [
        'schema_version' => 1,
        'tenant'         => $tenant,
        'database'       => $env['MOSGOV_TENANT_DB'],
        'rows'           => $anchor['rows'],
        'last_id'        => $anchor['last_id'],
        'last_hash'      => $anchor['last_hash'],
        'chain_verified' => $verify['ok'],
        'chain_rows'     => $verify['rows'],
        'release_commit' => $env['MOSGOV_RELEASE_COMMIT'] ?? '',
        'generated_at'   => gmdate('c'),
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($body === false) {
        throw new RuntimeException('Anchor serialization failed');
    }
    $payload['self_sha256'] = hash('sha256', $body);

    $base = $out . '/anchor-' . gmdate('Ymd-His');
    $file = $base . '.json';
    for ($i = 1; is_file($file); $i++) {
        $file = $base . '-' . $i . '.json';
    }
    $finalBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    if (file_put_contents($file, $finalBody, LOCK_EX) === false) {
        throw new RuntimeException('Anchor write failed');
    }
    chmod($file, 0400);

    MosGovCli::emit([
        'ok' => true, 'tenant' => $tenant, 'action' => 'written',
        'file' => $file, 'sha256' => $payload['self_sha256'],
        'anchor' => $anchor, 'verify' => $verify,
    ]);
} catch (Throwable $e) {
    error_log('[MOS-GOV][anchor] ' . $e::class . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    MosGovCli::emit([
        'ok' => false, 'tenant' => $tenant ?? '', 'action' => 'failed',
        'reason' => 'internal error; see /var/log/mosgov/anchor-cron.log',
    ]);
}
