<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Church (tenant) boundary (Phase 3, finding SEC-11 / multi-church).
 *
 * HONEST SCOPE STATEMENT: ChurchCRM 7.8.0 and the MOS-GOV V2.0 schema are
 * SINGLE-CONGREGATION systems. `gov_scope.scope_type = 'church'` is seeded
 * as one row ("Church (single congregation)") and there is no church_id /
 * tenant_id column in any of the 22 governance tables. Adding a tenant_id to
 * a shared database would require rewriting every query in a 75 KB repository
 * plus the 1,099 ChurchCRM core files that own person / family / giving data —
 * high risk of silent cross-church leakage.
 *
 * The chosen minimal reliable architecture is therefore PHYSICAL ISOLATION
 * (silo per church), not shared-schema SaaS multi-tenancy:
 *
 *   - one MariaDB database per church, one least-privilege DB user per church;
 *   - one Apache vhost / FPM pool / system account / upload root per church;
 *   - one env file per church, setting the tenant identity below;
 *   - a shared, read-only application codebase (symlinked release directory).
 *
 * TenantContext is the runtime guard that makes a misrouted vhost, a wrong
 * env file or a path-traversal attempt FAIL CLOSED instead of silently serving
 * another church's data. It is intentionally small: no new UI, no tenant
 * switching inside a session, no cross-church "super admin".
 */
final class TenantContext
{
    public const ENV_ID = 'MOSGOV_TENANT_ID';
    public const ENV_DB = 'MOSGOV_TENANT_DB';
    public const ENV_HOST = 'MOSGOV_TENANT_HOST';
    public const ENV_STORAGE = 'MOSGOV_TENANT_STORAGE';

    private function __construct(
        private readonly string $id,
        private readonly string $db,
        private readonly string $host,
        private readonly string $storageRoot,
    ) {
    }

    /** Build from the environment. Throws on anything missing or malformed. */
    public static function fromEnvironment(?array $env = null, ?string $httpHost = null): self
    {
        $id = self::env(self::ENV_ID, $env);
        $db = self::env(self::ENV_DB, $env);
        $host = self::env(self::ENV_HOST, $env);
        $storage = self::env(self::ENV_STORAGE, $env);

        if (!self::validId($id)) {
            throw new \RuntimeException('Tenant context missing or invalid: ' . self::ENV_ID);
        }
        if (!preg_match('/^[a-z0-9_]{3,48}$/', $db)) {
            throw new \RuntimeException('Tenant context missing or invalid: ' . self::ENV_DB);
        }
        if ($host === '' || !preg_match('/^[a-z0-9.-]{4,253}$/i', $host)) {
            throw new \RuntimeException('Tenant context missing or invalid: ' . self::ENV_HOST);
        }
        if ($storage === '' || str_contains($storage, "\0")) {
            throw new \RuntimeException('Tenant context missing or invalid: ' . self::ENV_STORAGE);
        }

        $ctx = new self($id, $db, $host, rtrim(str_replace('\\', '/', $storage), '/'));
        if ($httpHost !== null && $httpHost !== '') {
            $ctx->assertHttpHost($httpHost);
        }

        return $ctx;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $id) === 1;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function db(): string
    {
        return $this->db;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    /** Reject a request arriving on another church's hostname. */
    public function assertHttpHost(string $httpHost): void
    {
        $bare = strtolower(trim(explode(':', $httpHost)[0]));
        if ($bare !== strtolower($this->host)) {
            throw new \RuntimeException('Tenant/host mismatch: refusing to serve this request');
        }
    }

    /**
     * Verify that the connection actually points at THIS church's database.
     * A wrong env file or a copied vhost must be detected at request time.
     *
     * @return array{ok:bool, database:string, tenant_id:string, status:string, reason:string}
     */
    public function assertDatabase(object $pdo): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== $this->db) {
            return [
                'ok' => false, 'database' => $database, 'tenant_id' => '', 'status' => '',
                'reason' => 'connected database does not match tenant configuration',
            ];
        }

        $row = $pdo->query('SELECT tenant_id, tenant_db, status FROM gov_tenant WHERE singleton = 1')
            ->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'ok' => false, 'database' => $database, 'tenant_id' => '', 'status' => '',
                'reason' => 'gov_tenant boundary row missing (fail closed)',
            ];
        }
        if ((string) $row['tenant_id'] !== $this->id
            || (string) $row['tenant_db'] !== $this->db
            || (string) $row['status'] !== 'active') {
            return [
                'ok' => false, 'database' => $database,
                'tenant_id' => (string) $row['tenant_id'], 'status' => (string) $row['status'],
                'reason' => 'tenant identity in database differs from deployment configuration',
            ];
        }

        return [
            'ok' => true, 'database' => $database, 'tenant_id' => (string) $row['tenant_id'],
            'status' => (string) $row['status'], 'reason' => 'matched',
        ];
    }

    /**
     * Resolve an uploaded file path inside this church's private storage root.
     * Rejects traversal, absolute paths, drive letters, streams and null bytes.
     * The result is a filesystem path, never a URL: files must be served by an
     * authenticated download endpoint, never by Apache directly.
     */
    public function resolvePath(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '://')) {
            throw new \RuntimeException('Invalid storage path');
        }
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw new \RuntimeException('Absolute storage paths are not allowed');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Path traversal rejected');
            }
        }
        $base = realpath($this->storageRoot);
        if ($base === false) {
            throw new \RuntimeException('Tenant storage root does not exist');
        }
        $full = $base . '/' . $relative;
        $resolved = realpath($full);
        if ($resolved !== false && !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $base) . '/')) {
            throw new \RuntimeException('Resolved path escapes tenant storage root');
        }

        return $full;
    }

    /** Deterministic, tenant-namespaced object key for backups / attachments. */
    public function objectKey(string $name): string
    {
        return $this->id . '/' . date('Y/m/d') . '/' . basename($name);
    }

    private static function env(string $name, ?array $env): string
    {
        if ($env !== null) {
            return trim((string) ($env[$name] ?? ''));
        }
        $value = getenv($name);

        return $value === false ? '' : trim((string) $value);
    }
}
