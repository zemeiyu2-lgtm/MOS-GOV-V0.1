<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Permission resolution over the GovernanceContext (V0.2 design §17).
 *
 * The context already aggregated role + identity permissions; this resolver
 * answers the individual questions the policy engine asks:
 *   - does the identity hold permission X at all?
 *   - is it an explicit deny? (deny always wins)
 *   - where did the permission come from?
 */
final class PermissionResolver
{
    public const SOURCE_ROLE = 'role';
    public const SOURCE_EXPLICIT_GRANT = 'explicit-grant';
    public const SOURCE_EXPLICIT_DENY = 'explicit-deny';

    public static function has(GovernanceContext $ctx, string $permissionKey): bool
    {
        $source = $ctx->permissions()[$permissionKey] ?? null;

        return $source !== null && $source !== self::SOURCE_EXPLICIT_DENY;
    }

    public static function isExplicitDeny(GovernanceContext $ctx, string $permissionKey): bool
    {
        return ($ctx->permissions()[$permissionKey] ?? null) === self::SOURCE_EXPLICIT_DENY;
    }

    public static function source(GovernanceContext $ctx, string $permissionKey): ?string
    {
        return $ctx->permissions()[$permissionKey] ?? null;
    }

    /**
     * The permission key required for (resource, action), or null when the
     * pair is outside the registry. Mapping rules:
     *   - entity-scoped actions map to "<entity>.<action>" when registered;
     *   - anything else falls back to "governance.<action>" when registered;
     *   - unknown pairs return null (deny by default).
     */
    public static function permissionFor(string $resourceType, string $action): ?string
    {
        $direct = $resourceType . '.' . $action;
        if (isset(\ChurchCRM\Plugins\MosGov\Data\GovRepository::PERMISSIONS[$direct])) {
            return $direct;
        }

        $general = 'governance.' . $action;
        if (isset(\ChurchCRM\Plugins\MosGov\Data\GovRepository::PERMISSIONS[$general])) {
            return $general;
        }

        return null;
    }
}
