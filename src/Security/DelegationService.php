<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;

/**
 * Delegation evaluation and creation prechecks (V1.0, PoC-02).
 *
 * Authority model (conflict rules, evaluated in this order — first match wins):
 *
 *   1. explicit-deny (identity-level) always wins — checked BEFORE any
 *      delegation is consulted (see GovernancePolicy step 10a).
 *   2. direct authority: role permissions and explicit grants (existing
 *      V0.2 behaviour, unchanged). Direct authority outranks delegation.
 *   3. delegation: only consulted when no direct authority exists. A
 *      delegation grants ONLY its explicit permission_id key. Responsibility-
 *      scoped delegations are recorded and displayed in V1.0 but do NOT widen
 *      permission checks (default deny) — that linkage is a V2 candidate.
 *   4. scope containment and visibility are orthogonal: a delegation never
 *      bypasses GovernancePolicy steps 7/8.
 *
 * Creation prechecks (who may delegate what):
 *   - the actor must BE the delegator, or hold permission.manage;
 *   - when the actor IS the delegator, the delegator must hold the permission
 *     directly (role / explicit grant), or hold it via a delegation whose
 *     can_redelegate = 1 — otherwise re-delegation is refused;
 *   - the new edge must not close a cycle in the active delegation chain of
 *     that permission (loop prevention), and self-delegation is refused.
 *
 * Default deny: every unclear, invalid, expired, revoked or out-of-scope
 * delegation is rejected.
 */
final class DelegationService
{
    /** Permission source label used in AuthorizationDecision when granted via delegation. */
    public const SOURCE_DELEGATION = 'delegation';

    // ------------------------------------------------------------- evaluation

    /**
     * Pure evaluation of one delegation row against an expected grant.
     * Returns null when the delegation VALIDLY grants (delegate, permission)
     * as of $today; otherwise a machine-readable rejection reason.
     *
     * @param array<string, mixed> $row gov_delegation row
     */
    public static function evaluateRow(array $row, int $delegatePersonId, ?int $permissionId, string $today): ?string
    {
        if (($row['status'] ?? '') !== 'active') {
            return 'status-not-active';
        }
        if ((int) ($row['delegate_person_id'] ?? 0) !== $delegatePersonId) {
            return 'not-the-delegate';
        }
        if ($permissionId !== null && (int) ($row['permission_id'] ?? 0) !== $permissionId) {
            return 'permission-mismatch';
        }
        if (!empty($row['start_date']) && (string) $row['start_date'] > $today) {
            return 'not-yet-valid';
        }
        if (!empty($row['end_date']) && (string) $row['end_date'] < $today) {
            return 'expired';
        }
        if (!empty($row['revoked_at'])) {
            return 'revoked';
        }

        return null;
    }

    /**
     * Pure chain rules for a proposed delegation edge
     * delegator -> delegate on one permission.
     *
     * @param array<int, array<string, mixed>> $activeChain active delegation
     *        rows (delegate_person_id/delegator_person_id/can_redelegate)
     *        for the SAME permission
     * @return string|null null = allowed, otherwise machine reason
     */
    public static function chainError(
        array $activeChain,
        int $delegatorPersonId,
        int $delegatePersonId,
        bool $delegatorHasDirectPermission,
        bool $delegatorCanRedelegate
    ): ?string {
        if ($delegatorPersonId === $delegatePersonId) {
            return 'self-delegation';
        }

        // Where does the delegator's authority come from?
        $viaDelegation = false;
        if (!$delegatorHasDirectPermission) {
            foreach ($activeChain as $row) {
                if ((int) ($row['delegate_person_id'] ?? 0) === $delegatorPersonId
                    && ($row['status'] ?? '') === 'active'
                    && empty($row['revoked_at'])) {
                    $viaDelegation = true;
                    $delegatorCanRedelegate = (int) ($row['can_redelegate'] ?? 0) === 1;
                    break;
                }
            }
            if (!$viaDelegation) {
                return 'delegator-lacks-permission';
            }
            if (!$delegatorCanRedelegate) {
                return 'redelegate-not-allowed';
            }
        }

        // Loop prevention: the new edge delegator -> delegate closes a cycle
        // when, following EXISTING downstream edges (delegator => delegate),
        // the delegate can already reach the delegator. Authority flows
        // downstream, so the walk follows the same direction.
        $down = [];
        foreach ($activeChain as $row) {
            if (($row['status'] ?? '') === 'active' && empty($row['revoked_at'])) {
                $down[(int) ($row['delegator_person_id'] ?? 0)] = (int) ($row['delegate_person_id'] ?? 0);
            }
        }
        $seen = [];
        $cur = $delegatePersonId;
        while (isset($down[$cur]) && !isset($seen[$cur])) {
            $seen[$cur] = true;
            $cur = $down[$cur];
            if ($cur === $delegatorPersonId) {
                return 'delegation-loop';
            }
        }

        return null;
    }

    // ------------------------------------------------------------ DB lookups

    /**
     * The active delegation row that grants $permissionKey to
     * $delegatePersonId as of today, or null. Permission_id is resolved
     * through the gov_permission registry (unknown keys -> null -> deny).
     *
     * @param ConnectionInterface|null $conn test seam; defaults to Propel
     * @return array<string, mixed>|null
     */
    public static function activeDelegationGrant(
        ?ConnectionInterface $conn,
        int $delegatePersonId,
        string $permissionKey
    ): ?array {
        if ($delegatePersonId <= 0 || !isset(GovRepository::PERMISSIONS[$permissionKey])) {
            return null;
        }
        $conn = $conn ?? Propel::getConnection();

        $stmt = $conn->prepare(
            'SELECT d.*, p.permission_key FROM gov_delegation d
             JOIN gov_permission p ON p.id = d.permission_id AND p.status = :pactive
             WHERE d.status = :active AND d.delegate_person_id = :pid
               AND p.permission_key = :pkey'
        );
        $stmt->bindValue(':pactive', 'active');
        $stmt->bindValue(':active', 'active');
        $stmt->bindValue(':pid', $delegatePersonId, \PDO::PARAM_INT);
        $stmt->bindValue(':pkey', $permissionKey);
        $stmt->execute();

        $today = date('Y-m-d');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (self::evaluateRow($row, $delegatePersonId, (int) $row['permission_id'], $today) === null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Active delegation chain of one permission (for loop detection).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeChain(
        ?ConnectionInterface $conn,
        ?int $permissionId,
        string $today
    ): array {
        if ($permissionId === null || $permissionId <= 0) {
            return [];
        }
        $conn = $conn ?? Propel::getConnection();

        $stmt = $conn->prepare(
            "SELECT * FROM gov_delegation
             WHERE status = 'active' AND permission_id = :pid
               AND (start_date IS NULL OR start_date <= :today1)
               AND (end_date IS NULL OR end_date >= :today2)
               AND revoked_at IS NULL"
        );
        $stmt->bindValue(':pid', $permissionId, \PDO::PARAM_INT);
        $stmt->bindValue(':today1', $today);
        $stmt->bindValue(':today2', $today);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Creation precheck for a delegation. Returns null when allowed,
     * otherwise a machine-readable denial reason (caller must audit it).
     *
     * @param GovernanceContext|null $ctx context of the ACTOR; when null the
     *        on-behalf manager rule cannot be verified -> denied
     * @param array<string, mixed> $data normalised delegation payload
     */
    public static function precheckCreate(
        ?ConnectionInterface $conn,
        ?GovernanceContext $ctx,
        array $data
    ): ?string {
        if ($ctx === null) {
            return 'no-context';
        }

        $actor = $ctx->personId();
        $delegator = (int) ($data['delegator_person_id'] ?? 0);
        $delegate = (int) ($data['delegate_person_id'] ?? 0);
        if ($delegator <= 0 || $delegate <= 0) {
            return 'invalid-parties';
        }

        $isManager = PermissionResolver::has($ctx, 'permission.manage');
        if ($actor !== $delegator && !$isManager) {
            return 'only-delegator-or-manager';
        }

        $permissionId = ($data['permission_id'] ?? null) === null ? null : (int) $data['permission_id'];

        // Authority of the DELEGATOR over the delegated permission.
        if ($actor === $delegator) {
            $direct = false;
            if ($permissionId !== null) {
                $key = self::permissionKeyForId($conn, $permissionId);
                $direct = $key !== null && PermissionResolver::has($ctx, $key);
            } else {
                // responsibility-scoped delegation: require the delegator to
                // hold the general governance authority they are handing over
                $direct = PermissionResolver::has($ctx, 'governance.manage');
            }

            $chain = self::activeChain($conn, $permissionId, date('Y-m-d'));
            $error = self::chainError(
                $chain,
                $delegator,
                $delegate,
                $direct,
                false // recomputed inside chainError from the chain rows
            );
            if ($error !== null) {
                return $error;
            }

            return null;
        }

        // On-behalf creation by a permission manager: the manager is trusted
        // for authority verification (documented V1.0 limitation), but chain
        // and loop rules still apply.
        $chain = self::activeChain($conn, $permissionId, date('Y-m-d'));
        $error = self::chainError($chain, $delegator, $delegate, true, true);
        if ($error !== null) {
            return $error;
        }

        return null;
    }

    /** Resolve a permission registry id to its key, or null. */
    public static function permissionKeyForId(?ConnectionInterface $conn, int $permissionId): ?string
    {
        if ($permissionId <= 0) {
            return null;
        }
        $conn = $conn ?? Propel::getConnection();

        $stmt = $conn->prepare(
            'SELECT permission_key FROM gov_permission WHERE id = :id AND status = :active LIMIT 1'
        );
        $stmt->bindValue(':id', $permissionId, \PDO::PARAM_INT);
        $stmt->bindValue(':active', 'active');
        $stmt->execute();
        $key = $stmt->fetchColumn();

        return $key === false ? null : (string) $key;
    }
}
