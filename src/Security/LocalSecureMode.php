<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Local / LAN secure mode (V0.2 design §32).
 *
 * MOS_GOV_SECURITY_MODE:
 *   LOCAL (default) — only loopback clients (127.0.0.1, ::1)
 *   LAN             — loopback plus trusted private networks
 *                     (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
 *   OFF             — enforcement disabled (explicit opt-out, documented)
 *
 * Defaults per the security architecture: OUTBOUND_NETWORK = DENY (MOS-GOV
 * code performs no outbound calls) and PUBLIC_BINDING = DENY (any client
 * that is neither loopback nor a trusted LAN address is refused before any
 * governance logic runs).
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
            default => 'LOCAL mode: localhost (127.0.0.1) clients only.',
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
