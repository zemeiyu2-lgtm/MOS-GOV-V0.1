<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\model\ChurchCRM\User;

/**
 * MOS-GOV governance authorization layer (R07, upgraded in V0.2 to the
 * unified authorization center — design §17/§18).
 *
 * V0.1 entry points are PRESERVED:
 *   - canRead()  : module read (any authenticated user) — the V0.1 policy
 *                  for the ten governance entity pages;
 *   - canWrite() : ChurchCRM administrators only.
 *
 * V0.2 adds the decision engine:
 *   - can($user, $action, $resource, $row) returns an AuthorizationDecision
 *     produced by GovernancePolicy (identity → role → appointment → scope →
 *     information level → explicit deny). New surfaces (My Governance,
 *     governance search, export, identity/permission management) are bound
 *     to it; the legacy pages additionally mask P5 fields and enforce scope
 *     for users who carry a governance identity.
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
            $user = \ChurchCRM\Authentication\AuthenticationManager::getCurrentUser();
            self::$user = $user instanceof User ? $user : null;
        } catch (\Throwable $e) {
            self::$user = null;
        }

        return self::$user;
    }

    /**
     * Read access to governance data.
     *
     * V0.1 policy (kept): any authenticated user. Zero-permission users keep
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
     * V0.1 policy (kept): ChurchCRM administrators only. Reuses the core
     * User::isAdmin() API rather than re-deriving permissions from roles.
     */
    public static function canWrite(?User $user = null): bool
    {
        $user ??= self::currentUser();

        return $user !== null && $user->isAdmin();
    }

    /**
     * V0.2 unified decision: ALLOW or DENY with a reason. Every new
     * governance surface routes through this method.
     *
     * @param string               $action   one of GovRepository::ACTIONS
     * @param string               $resource entity key or 'governance'
     * @param array<string, mixed> $row      the resource row (null = module level)
     */
    public static function can(?User $user, string $action, string $resource, ?array $row = null): AuthorizationDecision
    {
        return GovernancePolicy::decide($user, $action, $resource, $row);
    }

    /** Boolean convenience wrapper around can(). */
    public static function allows(?User $user, string $action, string $resource, ?array $row = null): bool
    {
        return self::can($user, $action, $resource, $row)->allowed;
    }

    /** The request-scoped governance context of the current user. */
    public static function context(): ?GovernanceContext
    {
        $user = self::currentUser();

        return $user === null ? null : GovernanceContext::forUser($user);
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
        $ctx = $user === null ? null : GovernanceContext::forUser($user);

        return [
            'canRead' => self::canRead($user),
            'canWrite' => self::canWrite($user),
            'isAdmin' => $user !== null && $user->isAdmin(),
            'userName' => $user?->getUserName(),
            'identityId' => $ctx?->identityId(),
            'roleCodes' => $ctx !== null ? array_column($ctx->activeRoles(), 'role_code') : [],
        ];
    }

    /** Test seam: forget the memoised user. */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
        GovernanceContext::reset();
        VisibilityResolver::resetCache();
    }
}
