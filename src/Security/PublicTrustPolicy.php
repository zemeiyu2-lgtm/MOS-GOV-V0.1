<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Public-Internet trust boundary (Phase 3, finding SEC-01 / SEC-10).
 *
 * The V0.2 `LocalSecureMode` treated REMOTE_ADDR as an ACCESS-CONTROL gate:
 * "loopback or trusted LAN, otherwise 403". That assumption breaks in two
 * opposite ways on a public deployment:
 *
 *   - behind a reverse proxy / Apache vhost on the same host, REMOTE_ADDR is
 *     127.0.0.1 (or the proxy address) for EVERY Internet client, so the
 *     loopback allow-rule silently grants access to the whole world;
 *   - behind a proxy whose address is NOT allow-listed, every legitimate
 *     client is refused, so operators are tempted to set
 *     MOS_GOV_SECURITY_MODE=OFF, which disables the gate entirely.
 *
 * Neither behaviour is acceptable. This class therefore establishes the
 * public boundary explicitly:
 *
 *   1. In PUBLIC mode, client IP is NEVER used to authorize a request.
 *      Authorization comes only from the authenticated session and the
 *      governance policy engine (GovMembershipMiddleware / GovernancePolicy).
 *   2. HTTPS is mandatory. X-Forwarded-Proto is honoured ONLY when the
 *      immediate peer (REMOTE_ADDR) is an explicitly configured trusted
 *      proxy; otherwise a forged header cannot upgrade an HTTP request.
 *   3. X-Forwarded-For is used for LOGGING ONLY and is explicitly labelled
 *      untrusted. It never participates in any allow/deny decision.
 *   4. Deployment configuration fails closed: unknown mode, OFF in public
 *      mode, missing HTTPS or a missing tenant context are hard errors.
 *
 * LOCAL / LAN modes remain available for the existing Windows local runtime,
 * which must keep working unchanged (Phase 3 constraint: preserve the local
 * environment and backups).
 */
final class PublicTrustPolicy
{
    public const MODE_PUBLIC = 'PUBLIC';
    public const MODE_LOCAL = 'LOCAL';
    public const MODE_LAN = 'LAN';

    /** Environment variable that selects the deployment topology. */
    public const ENV_DEPLOYMENT = 'MOSGOV_DEPLOYMENT';

    /** Exact IP addresses of reverse proxies allowed to speak for scheme/client. */
    public const ENV_TRUSTED_PROXIES = 'MOSGOV_TRUSTED_PROXIES';

    /**
     * Effective deployment mode. Unknown / missing values fall back to the
     * MOST restrictive mode (LOCAL), never to PUBLIC and never to OFF.
     *
     * @param array<string, string>|null $env injected for tests; null = getenv()
     */
    public static function deploymentMode(?array $env = null): string
    {
        $raw = self::env(self::ENV_DEPLOYMENT, $env);
        $mode = strtoupper(trim($raw));

        return match ($mode) {
            'PUBLIC', 'INTERNET' => self::MODE_PUBLIC,
            'LAN' => self::MODE_LAN,
            default => self::MODE_LOCAL,
        };
    }

    public static function isPublic(?array $env = null): bool
    {
        return self::deploymentMode($env) === self::MODE_PUBLIC;
    }

    /**
     * Exact proxy addresses. CIDR ranges are deliberately NOT supported:
     * a typo in a netmask would silently widen the trust boundary.
     *
     * @return list<string>
     */
    public static function trustedProxies(?array $env = null): array
    {
        $raw = self::env(self::ENV_TRUSTED_PROXIES, $env);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $out[] = $candidate;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Resolve the address used for logging and rate-limit accounting.
     *
     * The returned structure always says whether the value is authoritative.
     * Callers MUST NOT use it for authorization.
     *
     * @param array<string, mixed> $server  $_SERVER-like parameters
     * @param array<string, mixed> $headers normalized request headers
     * @return array{peer:string, log_ip:string, authoritative:bool, via_header:bool}
     */
    public static function resolveClientAddress(array $server, array $headers = [], ?array $env = null): array
    {
        $peer = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        $peerIsTrustedProxy = $peer !== '' && in_array($peer, self::trustedProxies($env), true);
        $forwarded = (string) ($headers['X-Forwarded-For'] ?? $server['HTTP_X_FORWARDED_FOR'] ?? '');

        if ($peerIsTrustedProxy && $forwarded !== '') {
            // Left-most entry is the original client as reported by the proxy.
            // It is a claim, not proof — mark it non-authoritative.
            $candidate = trim(explode(',', $forwarded)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return [
                    'peer' => $peer,
                    'log_ip' => $candidate,
                    'authoritative' => false,
                    'via_header' => true,
                ];
            }
        }

        return [
            'peer' => $peer,
            'log_ip' => $peer,
            'authoritative' => $peer !== '' && filter_var($peer, FILTER_VALIDATE_IP) !== false,
            'via_header' => false,
        ];
    }

    /**
     * Evaluate the transport-level trust boundary. Never authorizes a user.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     * @return array{ok:bool, mode:string, https:bool, client:array, reasons:list<string>}
     */
    public static function authorityCheck(array $server, array $headers = [], ?array $env = null): array
    {
        $mode = self::deploymentMode($env);
        $reasons = [];
        $client = self::resolveClientAddress($server, $headers, $env);

        // A legacy "OFF" secure mode must never be combined with a public
        // deployment — that was the pre-Phase-3 way to bypass the IP gate.
        $legacyMode = strtoupper(trim(self::env('MOS_GOV_SECURITY_MODE', $env)));
        if ($mode === self::MODE_PUBLIC && $legacyMode === 'OFF') {
            $reasons[] = 'MOS_GOV_SECURITY_MODE=OFF is forbidden in PUBLIC deployment (fail closed).';
        }
        if ($mode === self::MODE_PUBLIC && self::env('MOSGOV_TENANT_ID', $env) === '') {
            $reasons[] = 'Missing tenant context in PUBLIC deployment (fail closed).';
        }

        $directHttps = strtolower((string) ($server['HTTPS'] ?? '')) !== ''
            && strtolower((string) ($server['HTTPS'] ?? '')) !== 'off';
        $serverPort = (int) ($server['SERVER_PORT'] ?? 0);
        $forwardedProto = strtolower(trim((string) ($headers['X-Forwarded-Proto'] ?? $server['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $peerIsTrustedProxy = in_array($client['peer'], self::trustedProxies($env), true);

        // Only a configured, directly connected proxy may assert the scheme.
        $https = $directHttps || $serverPort === 443
            || ($peerIsTrustedProxy && $forwardedProto === 'https');

        if ($mode === self::MODE_PUBLIC && !$https) {
            $reasons[] = 'HTTPS is mandatory in PUBLIC deployment; untrusted or missing scheme evidence.';
        }
        if ($forwardedProto !== '' && !$peerIsTrustedProxy) {
            // Not a hard failure when direct HTTPS is present, but recorded so
            // the operator can see header spoofing attempts in the logs.
            $reasons[] = 'Ignored X-Forwarded-Proto from an untrusted peer (logged, not trusted).';
        }

        return [
            'ok' => $reasons === [],
            'mode' => $mode,
            'https' => $https,
            'client' => $client,
            'reasons' => $reasons,
        ];
    }

    /**
     * Throw on any failed public-mode precondition. Used by the bootstrap so
     * a misconfigured server refuses to serve governance data at all.
     *
     * @throws \RuntimeException
     */
    public static function assertPublicReady(array $server, array $headers = [], ?array $env = null): void
    {
        $check = self::authorityCheck($server, $headers, $env);
        if (!$check['ok']) {
            throw new \RuntimeException('MOS-GOV public deployment refused: ' . implode(' ', $check['reasons']));
        }
    }

    /** @param array<string, string>|null $env */
    private static function env(string $name, ?array $env): string
    {
        if ($env !== null) {
            return (string) ($env[$name] ?? '');
        }
        $value = getenv($name);

        return $value === false ? '' : (string) $value;
    }
}
