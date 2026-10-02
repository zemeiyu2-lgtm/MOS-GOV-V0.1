<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Local / LAN secure mode (V0.2 design §32).
 *
 * MOS_GOV_SECURITY_MODE:
 *   LOCAL (default) — only loopback clients (127.0.0.1, ::1) plus the
 *                     container host bridge address (see below)
 *   LAN             — loopback plus trusted private networks
 *                     (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
 *   OFF             — enforcement disabled (explicit opt-out, documented)
 *
 * Docker note: when MOS-GOV runs inside a container with a published port,
 * the host's own browser reaches the container over the Docker bridge, so
 * Apache sees the client as the bridge gateway address (e.g. 172.18.0.1),
 * not 127.0.0.1. That gateway address IS the local machine — it is the
 * host's own IP on the bridge network — so LOCAL mode allows exactly the
 * connected-network gateway addresses parsed from /proc/net/route. Other
 * containers on the same bridge have their own distinct addresses and LAN
 * clients arrive with their real LAN source IP: none of them match the
 * gateway, so "LOCAL = this machine only" still holds. Private ranges as a
 * whole are never opened in LOCAL mode. For exotic topologies (reverse
 * proxy, custom network) an exact-address allow-list can be provided via
 * MOS_GOV_LOCAL_EXTRA_CLIENTS (comma-separated IPs, empty by default).
 *
 * Defaults per the security architecture: OUTBOUND_NETWORK = DENY (MOS-GOV
 * code performs no outbound calls) and PUBLIC_BINDING = DENY (any client
 * that is neither loopback nor a trusted local/LAN address is refused
 * before any governance logic runs).
 */
final class LocalSecureMode
{
    public const MODE_LOCAL = 'LOCAL';
    public const MODE_LAN = 'LAN';
    public const MODE_OFF = 'OFF';

    /** Effective mode from the environment (default LOCAL). */
    public static function mode(): string
    {
        $mode = strtoupper(trim((string) ($_ENV['MOS_GOV_SECURITY_MODE'] ?? getenv('MOS_GOV_SECURITY_MODE') ?: self::MODE_LOCAL)));

        return in_array($mode, [self::MODE_LOCAL, self::MODE_LAN, self::MODE_OFF], true) ? $mode : self::MODE_LOCAL;
    }

    /** Human description for the settings page. */
    public static function describe(): string
    {
        return match (self::mode()) {
            self::MODE_LAN => 'LAN mode: localhost and trusted private-network clients only.',
            self::MODE_OFF => 'OFF: network enforcement is disabled — local/LAN deployment only, never expose to the public internet.',
            default => 'LOCAL mode: localhost (127.0.0.1) and this container host only.',
        };
    }

    /** True when the client address is allowed by the current mode. */
    public static function clientAllowed(string $remoteAddr): bool
    {
        if (self::mode() === self::MODE_OFF) {
            return true;
        }

        if (self::isLoopback($remoteAddr)) {
            return true;
        }

        if (self::mode() === self::MODE_LOCAL && self::isThisHostClient($remoteAddr)) {
            return true;
        }

        if (self::mode() === self::MODE_LAN && self::isPrivateNetwork($remoteAddr)) {
            return true;
        }

        return false;
    }

    /**
     * Guard for routes: returns an AuthorizationDecision-like deny when the
     * client is not allowed, null when OK.
     */
    public static function check(Request $request): ?AuthorizationDecision
    {
        $server = $request->getServerParams();
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');

        if (self::clientAllowed($remote)) {
            return null;
        }

        return AuthorizationDecision::deny(
            'MOS-GOV is running in ' . self::mode() . ' secure mode; this client address is not trusted.',
            null,
            null,
            null,
            'secure-mode'
        );
    }

    private static function isLoopback(string $addr): bool
    {
        return in_array($addr, ['127.0.0.1', '::1', 'localhost'], true)
            || str_starts_with($addr, '127.');
    }

    /**
     * True when the address is the container host itself: one of the
     * gateway addresses of the container's connected Docker bridges (the
     * address the host uses when its own browser connects through the
     * published port), or an explicit MOS_GOV_LOCAL_EXTRA_CLIENTS entry.
     * Never matches a whole private range — exact addresses only.
     */
    private static function isThisHostClient(string $addr): bool
    {
        $normalized = self::normalizeIp($addr);
        if ($normalized === '') {
            return false;
        }

        foreach (self::thisHostAddresses() as $hostAddr) {
            if ($hostAddr !== '' && strcasecmp($hostAddr, $normalized) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Test/ops hook: drop cached host-address detection (env may have changed). */
    public static function resetRuntimeCache(): void
    {
        self::$thisHostAddresses = null;
    }

    /** @var string[]|null */
    private static ?array $thisHostAddresses = null;

    /**
     * Exact addresses considered "this machine" in LOCAL mode: the
     * MOS_GOV_LOCAL_EXTRA_CLIENTS allow-list (default empty) plus the
     * gateway addresses of every connected network from /proc/net/route.
     *
     * @return string[]
     */
    public static function thisHostAddresses(): array
    {
        if (self::$thisHostAddresses !== null) {
            return self::$thisHostAddresses;
        }

        $addrs = [];
        $extra = getenv('MOS_GOV_LOCAL_EXTRA_CLIENTS') ?: '';
        foreach (preg_split('/[\s,;]+/', (string) $extra) ?: [] as $ip) {
            $ip = self::normalizeIp((string) $ip);
            if ($ip !== '') {
                $addrs[] = $ip;
            }
        }

        $routes = @file_get_contents('/proc/net/route');
        if (is_string($routes) && $routes !== '') {
            foreach (preg_split('/\n/', $routes) ?: [] as $line) {
                $cols = preg_split('/\s+/', trim($line)) ?: [];
                if (count($cols) < 4 || $cols[0] === 'Iface') {
                    continue;
                }
                $ip = self::routeHexToIp((string) $cols[2]);
                if ($ip !== null) {
                    $addrs[] = $ip;
                }
            }
        }

        self::$thisHostAddresses = array_values(array_unique($addrs));

        return self::$thisHostAddresses;
    }

    /**
     * Convert a /proc/net/route gateway hex value (little-endian u32 as
     * printed by the kernel on LE architectures, e.g. "010012AC") into a
     * dotted-quad address ("172.18.0.1"). Returns null for invalid values.
     */
    public static function routeHexToIp(string $hex): ?string
    {
        if (preg_match('/^[0-9A-F]{8}$/', $hex) !== 1 || strtoupper($hex) === '00000000') {
            return null;
        }

        $be = implode('', array_reverse(str_split($hex, 2)));

        return long2ip((int) hexdec($be));
    }

    /** Normalize an IP: trims and folds IPv4-mapped IPv6 back to IPv4. */
    private static function normalizeIp(string $addr): string
    {
        $addr = trim($addr);
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $addr;
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && stripos($addr, '::ffff:') === 0) {
            $v4 = substr($addr, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $v4;
            }
        }

        return $addr;
    }

    private static function isPrivateNetwork(string $addr): bool
    {
        // IPv4 private ranges
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $addr) === 1) {
            return true;
        }

        // IPv6 unique-local (fc00::/7)
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && preg_match('/^f[cd]/i', $addr) === 1) {
            return true;
        }

        return false;
    }
}
