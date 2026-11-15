<?php

declare(strict_types=1);

/**
 * MOS-GOV 运维 CLI 公共库。仅供命令行调用，不通过 Web 暴露。
 *
 * 与 deploy/scripts/mosgov-lib.sh 同样的约定：
 *   - env 只做 KEY=VALUE 逐行解析，不使用 parse_ini_file / eval / include；
 *   - 结果以结构化 JSON 输出，绝不回显口令、HMAC 密钥或 PDO 异常原文；
 *   - 任何失败都以非 0 退出，供 healthcheck.sh / cron 判定。
 */
final class MosGovCli
{
    public const DEFAULT_PLUGIN_SRC = '/var/www/mos-gov/current/plugins/community/mos-gov/src/Security';

    public static function assertCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            fwrite(STDERR, "MOS-GOV CLI: only cli SAPI is allowed\n");
            exit(1);
        }
    }

    /** @return array<string,string> */
    public static function parseArgs(array $argv): array
    {
        $out = [];
        for ($i = 1, $n = count($argv); $i < $n; $i++) {
            $arg = $argv[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $name = substr($arg, 2);
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } elseif ($i + 1 < $n && !str_starts_with($argv[$i + 1], '--')) {
                $value = $argv[++$i];
            } else {
                $value = '1';
            }
            $out[$name] = $value;
        }
        return $out;
    }

    public static function tenant(array $args): string
    {
        $tenant = (string) ($args['tenant'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$/', $tenant) !== 1) {
            throw new RuntimeException('Missing or invalid --tenant');
        }
        return $tenant;
    }

    /** @return array<string,string> */
    public static function loadEnv(string $tenant, ?string $override = null): array
    {
        $path = $override ?: "/etc/mosgov/{$tenant}/env";
        if (!is_file($path)) {
            throw new RuntimeException('Environment file not found');
        }
        $env = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new RuntimeException('Malformed environment line');
            }
            [$key, $value] = explode('=', $line, 2);
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('Invalid environment key');
            }
            $env[$key] = $value;
        }
        foreach (['MOSGOV_TENANT_ID', 'MOSGOV_TENANT_DB', 'MOSGOV_DB_HOST', 'MOSGOV_DB_PORT',
                  'MOSGOV_DB_USER', 'MOSGOV_DB_PASSWORD', 'MOSGOV_AUDIT_HMAC_KEY'] as $key) {
            if (($env[$key] ?? '') === '') {
                throw new RuntimeException('Missing environment key: ' . $key);
            }
        }
        if ($env['MOSGOV_TENANT_ID'] !== $tenant) {
            throw new RuntimeException('Tenant / environment mismatch (fail closed)');
        }
        return $env;
    }

    public static function pdo(array $env): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['MOSGOV_DB_HOST'],
            $env['MOSGOV_DB_PORT'],
            $env['MOSGOV_TENANT_DB']
        );
        return new PDO($dsn, $env['MOSGOV_DB_USER'], $env['MOSGOV_DB_PASSWORD'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }

    /** Load the two security classes from the ACTIVE release, without Composer. */
    public static function loadSecurity(array $env): void
    {
        $src = $env['MOSGOV_PLUGIN_SRC'] ?? (getenv('MOSGOV_PLUGIN_SRC') ?: self::DEFAULT_PLUGIN_SRC);
        $src = rtrim($src, '/\\');
        foreach (['TenantContext.php', 'GovernanceAuditService.php'] as $file) {
            $path = $src . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                throw new RuntimeException('Security class not found in active release: ' . $file);
            }
            require_once $path;
        }
    }

    /** @param array<string,mixed> $payload */
    public static function emit(array $payload): never
    {
        $payload['generated_at'] = gmdate('c');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
        exit(($payload['ok'] ?? false) === true ? 0 : 1);
    }
}
