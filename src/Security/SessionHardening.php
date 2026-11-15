<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Session / cookie hardening (Phase 3, finding SEC-02).
 *
 * ChurchCRM core must not be edited (it is outside the versioned plugin and
 * is shared with other MOS projects). These settings are therefore applied
 * from plugin bootstrap before any session is started, and duplicated in the
 * server-level php.ini template (`deploy/php/99-mosgov.ini`) so a forgotten
 * plugin load cannot silently weaken them.
 *
 * Fixes over the V0.2 Windows installer (which set only httponly + SameSite):
 *   - session.cookie_secure = 1 whenever the request is HTTPS;
 *   - session.use_strict_mode = 1 (reject uninitialised session IDs);
 *   - bounded idle lifetime (gc_maxlifetime / cookie_lifetime = 0);
 *   - SameSite=Lax is never relaxed to None on a public deployment;
 *   - callers must rotate the session ID on privilege changes (see
 *     rotateAfterPrivilegeChange()).
 */
final class SessionHardening
{
    public const IDLE_SECONDS = 1800;

    /** @return array<string, string> effective values after apply() */
    public static function apply(bool $https, bool $public = true): array
    {
        $settings = [
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'Lax',
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.cookie_lifetime' => '0',
            'session.gc_maxlifetime' => (string) self::IDLE_SECONDS,
        ];
        // A public deployment never downgrades the cookie to insecure.
        if ($https || $public) {
            $settings['session.cookie_secure'] = '1';
        }
        foreach ($settings as $key => $value) {
            @ini_set($key, $value);
        }

        // ini_set() for session.* is not reliable across SAPIs / build
        // configurations (observed on PHP 8.4.25 Windows CLI). The server
        // php.ini is therefore authoritative; verify the EFFECTIVE values
        // and refuse to serve a public request when they are not enforced.
        if ($public) {
            $issues = self::violations(true);
            if ($issues !== []) {
                throw new \RuntimeException('Session hardening not in effect (configure deploy/php/99-mosgov.ini): ' . implode('; ', $issues));
            }
        }

        return self::effective();
    }

    /** @return array<string, string> */
    public static function effective(): array
    {
        $keys = [
            'session.cookie_httponly', 'session.cookie_secure', 'session.cookie_samesite',
            'session.use_strict_mode', 'session.use_only_cookies', 'session.cookie_lifetime',
            'session.gc_maxlifetime',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (string) ini_get($key);
        }

        return $out;
    }

    /**
     * Fail-closed verification for public deployments. Returns the list of
     * violations; an empty list means the session layer is safe to use.
     *
     * @return list<string>
     */
    public static function violations(bool $public = true): array
    {
        $v = self::effective();
        $issues = [];
        if ($v['session.cookie_httponly'] !== '1') {
            $issues[] = 'cookie_httponly is off';
        }
        if ($v['session.use_strict_mode'] !== '1') {
            $issues[] = 'use_strict_mode is off';
        }
        if ($public && $v['session.cookie_secure'] !== '1') {
            $issues[] = 'cookie_secure is off on a public deployment';
        }
        if ($public && !in_array($v['session.cookie_samesite'], ['Lax', 'Strict'], true)) {
            $issues[] = 'SameSite is not Lax/Strict';
        }
        if ((int) $v['session.gc_maxlifetime'] > 3600) {
            $issues[] = 'idle lifetime exceeds one hour';
        }

        return $issues;
    }

    /** Rotate the session id after login / privilege change (fixation defence). */
    public static function rotateAfterPrivilegeChange(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return session_regenerate_id(true);
    }
}
