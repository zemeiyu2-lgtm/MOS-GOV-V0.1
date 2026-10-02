<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Plugins\MosGov\Data\GovRepository;

/**
 * Scope containment (V0.2 design §16).
 *
 * A resource belongs to one or more candidate scopes; an identity may act on
 * it only when one of its effective scopes covers the resource. Superscopes:
 *   global ⊃ church ⊃ everything else
 * Numeric scopes match by exact (scope_type, scope_id) pair — Group 12 is
 * NEVER widened to Group 13 by id arithmetic.
 */
final class ScopeResolver
{
    /**
     * Candidate scopes a resource row lives in. Returns
     * [scope_type => [scope_id|null, ...]] pairs in specificity order.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, array<int, int|null>>
     */
    public static function resourceScopes(string $entity, array $row): array
    {
        switch ($entity) {
            case 'structure':
            case 'body':
            case 'role':
                // Governance structure belongs to the congregation.
                return ['church' => [null]];

            case 'identity':
            case 'identity_role':
            case 'identity_scope':
            case 'identity_permission':
            case 'appointment':
                // Person-bound records: scoped to the person themself; the
                // "own record" case is handled by the policy before scope.
                $pid = $row['person_id'] ?? null;
                return $pid !== null
                    ? ['person' => [(int) $pid]]
                    : ['church' => [null]];

            case 'meeting':
            case 'issue':
            case 'decision':
                // Meetings/issues/decisions live in the body that owns them;
                // the owning body id is looked up lazily by the caller when
                // needed (body_id may be null on issues/decisions).
                $bid = $row['body_id'] ?? null;
                return $bid !== null
                    ? ['body' => [(int) $bid]]
                    : ['church' => [null]];

            case 'task':
                $assignee = $row['assignee_person_id'] ?? null;
                return $assignee !== null
                    ? ['person' => [(int) $assignee]]
                    : ['church' => [null]];

            case 'permission':
            case 'role_permission':
            case 'visibility_rule':
            case 'scope':
            case 'role_scope':
                // The authorization registry itself is system-level and is
                // only reachable with explicit manage permissions.
                return ['global' => [null]];

            default:
                return ['church' => [null]];
        }
    }

    /**
     * True when one of the identity's effective scopes covers the resource.
     *
     * @param array<int, array{scope_type:string, scope_id?:int|null}> $identityScopes
     * @param array<string, array<int, int|null>>                      $resourceScopes
     */
    public static function covered(array $identityScopes, array $resourceScopes): bool
    {
        $hasGlobal = false;
        $hasChurch = false;
        foreach ($identityScopes as $scope) {
            $type = $scope['scope_type'];
            if ($type === 'global') {
                $hasGlobal = true;
            } elseif ($type === 'church') {
                $hasChurch = true;
            }
        }
        if ($hasGlobal) {
            return true; // global covers everything
        }

        foreach ($resourceScopes as $type => $ids) {
            foreach ($ids as $id) {
                if ($hasChurch && $type !== 'global') {
                    // church covers all congregational structure/body/person
                    // scopes of this congregation.
                    return true;
                }
                foreach ($identityScopes as $scope) {
                    if ($scope['scope_type'] !== $type) {
                        continue;
                    }
                    $sid = $scope['scope_id'] ?? null;
                    if ($id === null ? $sid === null : $sid === $id) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Human label of a scope pair for denial pages (no protected content).
     */
    public static function describe(string $scopeType, ?int $scopeId): string
    {
        if ($scopeId === null) {
            return $scopeType;
        }

        return $scopeType . '#' . $scopeId;
    }

    /** Whitelist guard kept central: unknown scope types are always invalid. */
    public static function isKnownType(string $scopeType): bool
    {
        return in_array($scopeType, GovRepository::SCOPE_TYPES, true);
    }
}
