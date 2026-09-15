<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\model\ChurchCRM\User;

/**
 * MOS-GOV governance authorization layer (R07).
 *
 * Why this exists as its own class instead of a bare middleware:
 *
 * docs/ARCHITECTURE.md and docs/IMPLEMENTATION-NOTES.md state that
 * governance authorization must NOT be inferred from ChurchCRM login state
 * alone, and that R07 requires an explicit governance authorization layer.
 * This class IS that layer — the single decision point for every MOS-GOV
 * permission question. It deliberately delegates the underlying identity and
 * role facts to ChurchCRM's own API (AuthenticationManager + User), because
 * ChurchCRM remains the authority on users and application permissions.
 *
 * Policy for V0.1:
 *  - read  : any authenticated ChurchCRM user (the plugin pages are already
 *            behind the global AuthMiddleware on the /plugins entry point);
 *  - write : ChurchCRM administrators only.
 *
 * The write policy lives in exactly one method, so a future governance role
 * (e.g. "governance secretary") only has to change canWrite().
 */
final class GovAuthorization
{
    /** Role name used for the access-denied redirect (see BaseAuthRoleMiddleware). */
    public const ROLE_NAME = 'Governance';

    private static ?User $user = null;
    private static bool $resolved = false;

    /** The current ChurchCRM user, or null when nobody is authenticated. */
    public static function currentUser(): ?User
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        try {
            $user = AuthenticationManager::getCurrentUser();
            self::$user = $user instanceof User ? $user : null;
        } catch (\Throwable $e) {
            self::$user = null;
        }

        return self::$user;
    }

    /**
     * Read access to governance data.
     *
     * V0.1 policy: any authenticated user. Zero-permission users keep
     * read-only access — matching ChurchCRM's own read-default policy.
     */
    public static function canRead(?User $user = null): bool
    {
        $user ??= self::currentUser();

        return $user !== null;
    }

    /**
     * Write access to governance data (create / edit governance records).
     *
     * V0.1 policy: ChurchCRM administrators only. Reuses the core
     * User::isAdmin() API rather than re-deriving permissions from roles.
     */
    public static function canWrite(?User $user = null): bool
    {
        $user ??= self::currentUser();

        return $user !== null && $user->isAdmin();
    }

    /** Human-readable reason shown on the access-denied page. */
    public static function writeDeniedMessage(): string
    {
        return 'Modifying MOS-GOV governance data requires ChurchCRM administrator rights.';
    }

    /**
     * Explicit permission rows for the dashboard / detail screens, so the UI
     * can hide actions the user cannot perform instead of failing on submit.
     *
     * @return array{canRead:bool, canWrite:bool, isAdmin:bool, userName:?string}
     */
    public static function capabilitySummary(): array
    {
        $user = self::currentUser();

        return [
            'canRead' => self::canRead($user),
            'canWrite' => self::canWrite($user),
            'isAdmin' => $user !== null && $user->isAdmin(),
            'userName' => $user?->getUserName(),
        ];
    }

    /** Test seam: forget the memoised user. */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }
}
