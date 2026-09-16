<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\model\ChurchCRM\User;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;

/**
 * The governance policy engine (V0.2 design §18).
 *
 * Strict evaluation order — the first failing step produces the DENY:
 *
 *   1.  is the user logged in?
 *   2.  does the user hold a governance identity?
 *   3.  is the identity active?
 *   4.  does the identity hold an active role?
 *   5.  does the role hold the required permission?
 *   6.  is the appointment (when bound) active?
 *   7.  does the resource belong to the identity's scope?
 *   8.  does the information level allow this?
 *   9.  is the action allowed?
 *   10. is there an explicit DENY?
 *   11. ALLOW
 *
 * Explicit DENY always outranks a plain GRANT (checked inside
 * PermissionResolver::isExplicitDeny before any allow is honoured).
 *
 * The engine NEVER trusts hidden UI: routes, repositories and search all
 * call it; view != edit != approve != export is enforced by the permission
 * registry itself.
 */
final class GovernancePolicy
{
    /**
     * Authorize an action on a resource row (or on the module itself when
     * $resource is null).
     *
     * @param string               $action   one of GovRepository::ACTIONS
     * @param string               $resource entity key (e.g. 'meeting') or 'governance'
     * @param array<string, mixed> $row      the DB row when acting on a record
     */
    public static function decide(?User $user, string $action, string $resource, ?array $row = null): AuthorizationDecision
    {
        // 1. login
        if ($user === null) {
            return AuthorizationDecision::deny('You must be signed in.', $resource . '.' . $action, source: 'auth');
        }

        // 2./3. governance identity, active
        $ctx = GovernanceContext::forUser($user);
        if ($ctx === null) {
            return AuthorizationDecision::deny(
                'No active governance identity is linked to your account.',
                $resource . '.' . $action,
                source: 'identity'
            );
        }

        // action whitelist
        if (!in_array($action, GovRepository::ACTIONS, true)) {
            return AuthorizationDecision::deny('Unsupported action.', null, null, null, 'action');
        }

        // 4. an active role is required for anything beyond viewing P1/P2
        $activeRoles = $ctx->activeRoles();

        // 5. permission
        $permission = PermissionResolver::permissionFor($resource, $action);
        if ($permission === null) {
            return AuthorizationDecision::deny('This action is not defined in the permission registry.', null, null, null, 'registry');
        }

        // 10a. explicit deny wins before any allow is considered.
        if (PermissionResolver::isExplicitDeny($ctx, $permission)) {
            return AuthorizationDecision::deny(
                'An explicit authorization denial applies to this permission.',
                $permission,
                null,
                null,
                'explicit-deny'
            );
        }

        if (!PermissionResolver::has($ctx, $permission)) {
            return AuthorizationDecision::deny(
                'Your governance roles do not include this permission.',
                $permission,
                null,
                null,
                'permission'
            );
        }

        // 6. appointment lifecycle (already enforced in the context: roles
        //    bound to ended appointments are filtered out of activeRoles).
        if ($activeRoles === []) {
            return AuthorizationDecision::deny(
                'None of your appointments is currently active.',
                $permission,
                null,
                null,
                'appointment'
            );
        }

        // "own record" shortcut: a person may always see their own
        // governance identity / person-scoped records.
        $ownRecord = self::isOwnRecord($ctx, $resource, $row);

        // 7. scope containment
        if (!$ownRecord && $row !== null) {
            $rScopes = ScopeResolver::resourceScopes($resource, $row);
            if (!ScopeResolver::covered($ctx->scopes(), $rScopes)) {
                $needed = [];
                foreach ($rScopes as $type => $ids) {
                    foreach ($ids as $id) {
                        $needed[] = ScopeResolver::describe($type, $id);
                    }
                }

                return AuthorizationDecision::deny(
                    'This record is outside your governance scope.',
                    $permission,
                    implode(', ', $needed),
                    null,
                    'scope'
                )->withScopeContext(implode(', ', $needed), $needed);
            }
        }

        // 8. information level
        $level = VisibilityResolver::levelFor($resource, $row ?? []);
        if (!VisibilityResolver::canSeeLevel($ctx, $level, $action)) {
            return AuthorizationDecision::deny(
                $level === 'P5'
                    ? 'This information is protected (level P5).'
                    : 'Your information level does not allow this.',
                $permission,
                null,
                $level,
                'visibility'
            );
        }

        // 9. action/permission cross-check (view != edit != export is already
        //    encoded in the registry; manage never implies church authority)
        if ($action === 'manage' && !in_array('E01', array_column($activeRoles, 'role_code'), true)
            && PermissionResolver::source($ctx, $permission) === PermissionResolver::SOURCE_ROLE) {
            return AuthorizationDecision::deny(
                'Manage actions are reserved for the governance administrator role.',
                $permission,
                null,
                null,
                'action'
            );
        }

        // 11. allow
        return AuthorizationDecision::allow(
            'Allowed by ' . $permission . ' via ' . PermissionResolver::source($ctx, $permission),
            PermissionResolver::source($ctx, $permission)
        );
    }

    /** A person always sees their own person-scoped records. */
    private static function isOwnRecord(GovernanceContext $ctx, string $resource, ?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        $mine = (int) $ctx->personId();
        if (($row['person_id'] ?? null) !== null && (int) $row['person_id'] === $mine) {
            return true;
        }

        return in_array($resource, ['identity', 'appointment', 'task'], true)
            && (($row['person_id'] ?? $row['assignee_person_id'] ?? null) !== null)
            && (int) ($row['person_id'] ?? $row['assignee_person_id']) === $mine;
    }

    /**
     * Information-level filter for row sets: returns only the fields the
     * identity may see. P5 fields are replaced by a protection notice
     * (§42 — never show field name + content).
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public static function filterFields(GovernanceContext $ctx, string $entity, array $rows): array
    {
        $p5Visible = VisibilityResolver::canSeeLevel($ctx, 'P5', 'view');
        $out = [];
        foreach ($rows as $row) {
            foreach (GovRepository::P5_FIELDS[$entity] ?? [] as $field) {
                if (!$p5Visible && isset($row[$field]) && $row[$field] !== null && $row[$field] !== '') {
                    $row[$field] = '__P5_PROTECTED__';
                }
            }
            $out[] = $row;
        }

        return $out;
    }
}
