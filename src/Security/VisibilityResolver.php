<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use Propel\Runtime\Propel;

/**
 * Information-level visibility (V0.2 design §10 + §22).
 *
 * Levels:
 *   P1 Public · P2 Member · P3 Ministry · P4 Governance · P5 Sensitive
 *
 * Hard rules:
 *   - Rules express VISIBILITY: whether an information level (P1..P5) is
 *     visible to the identity. They never gate which ACTIONS may run —
 *     action authorization lives exclusively in the permission registry
 *     (PermissionResolver / GovernancePolicy steps 5/10).
 *   - P5 is DENY by default. No role, however senior, gains P5 automatically;
 *     it requires an explicit per-identity grant (gov_identity_permission).
 *   - P3/P4 visibility is additionally scope-bound: the ScopeResolver must
 *     also contain the resource, so an allow row never widens scope.
 *   - Rules come from gov_visibility_rule (role_id NULL = every role) with
 *     request-level caching. Rule rows are evaluated with view semantics:
 *     the level's canonical visibility rule set is the action='view' rows
 *     (the seeded baseline); rule rows carrying any other action value are
 *     not consulted for visibility.
 */
final class VisibilityResolver
{
    /** @var array<string, array{allow:bool, deny:bool}>|null */
    private static ?array $ruleCache = null;

    /** Default information level of a resource row. */
    public static function levelFor(string $entity, array $row = []): string
    {
        return GovRepository::INFORMATION_LEVELS_BY_RESOURCE[$entity] ?? 'P4';
    }

    /** True when the field's content is P5-sensitive for this row. */
    public static function isSensitiveField(string $entity, string $field): bool
    {
        return in_array($field, GovRepository::P5_FIELDS[$entity] ?? [], true);
    }

    /**
     * May this identity see an information level at all?
     *
     * Visibility rules answer a question about SEEING ("is this level of
     * information visible to me"), never about DOING. The $action parameter
     * is kept for call-site compatibility but deliberately ignored: every
     * evaluation uses the level's canonical visibility rule set (the
     * action='view' rows), so a visibility rule can never turn into an
     * action gate and a missing per-action rule set can never silently
     * deny a legitimately granted action. Scope containment is NOT checked
     * here — the policy engine combines both answers.
     */
    public static function canSeeLevel(GovernanceContext $ctx, string $level, string $action = 'view'): bool
    {
        if (!in_array($level, GovRepository::INFORMATION_LEVELS, true)) {
            return false; // unknown level: deny
        }

        $explicit = self::explicitPermissionCovers($ctx, $level, $action);
        if ($explicit) {
            return true;
        }

        $rules = self::rules($level, 'view');

        // A role-specific deny always refuses, even when another rule allows.
        foreach ($ctx->activeRoles() as $role) {
            if ($rules[$role['role_id']]['deny'] ?? false) {
                return false;
            }
        }

        // A role-specific allow outranks the wildcard default-deny row
        // (the seeded P5 wildcard deny is a default, not an explicit veto).
        foreach ($ctx->activeRoles() as $role) {
            if ($rules[$role['role_id']]['allow'] ?? false) {
                return true;
            }
        }

        if ($rules['*']['deny'] ?? false) {
            return false;
        }

        return $rules['*']['allow'] ?? false;
    }

    /**
     * P5 (and any level) can be unlocked by an explicit per-identity or
     * seeded rule; this is the ONLY path to P5 — never a role default.
     */
    private static function explicitPermissionCovers(GovernanceContext $ctx, string $level, string $action): bool
    {
        if ($level !== 'P5') {
            return false;
        }

        return self::hasP5Grant($ctx);
    }

    /** True when the identity carries an explicit, active P5 allow rule. */
    private static function hasP5Grant(GovernanceContext $ctx): bool
    {
        static $cache = [];
        $iid = $ctx->identityId();
        if (isset($cache[$iid])) {
            return $cache[$iid];
        }

        $conn = Propel::getConnection();
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM gov_visibility_rule
             WHERE rule_type = :allow AND information_level = :p5 AND status = :active
               AND (role_id IS NULL OR role_id IN (SELECT role_id FROM gov_identity_role WHERE identity_id = :iid AND status = :iactive))'
        );
        $stmt->bindValue(':allow', 'allow');
        $stmt->bindValue(':p5', 'P5');
        $stmt->bindValue(':active', 'active');
        $stmt->bindValue(':iid', $iid, \PDO::PARAM_INT);
        $stmt->bindValue(':iactive', 'active');
        $stmt->execute();

        return $cache[$iid] = (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Compiled rules for one (level, action): role_id|"*" => allow/deny flags.
     *
     * @return array<string, array{allow:bool, deny:bool}>
     */
    private static function rules(string $level, string $action): array
    {
        $key = $level . '|' . $action;
        if (self::$ruleCache !== null && isset(self::$ruleCache[$key])) {
            return self::$ruleCache[$key];
        }

        $conn = Propel::getConnection();
        $stmt = $conn->prepare(
            'SELECT role_id, rule_type FROM gov_visibility_rule
             WHERE information_level = :level AND action = :action AND status = :active'
        );
        $stmt->bindValue(':level', $level);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':active', 'active');
        $stmt->execute();

        $compiled = ['*' => ['allow' => false, 'deny' => false]];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $k = $row['role_id'] !== null ? (string) (int) $row['role_id'] : '*';
            $compiled[$k] ??= ['allow' => false, 'deny' => false];
            $compiled[$k][$row['rule_type']] = true;
        }

        return self::$ruleCache[$key] = $compiled;
    }

    /** Test seam: clear the request-scoped rule cache. */
    public static function resetCache(): void
    {
        self::$ruleCache = null;
    }
}
