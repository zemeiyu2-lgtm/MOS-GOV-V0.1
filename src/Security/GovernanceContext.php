<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\model\ChurchCRM\User;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use Propel\Runtime\Propel;

/**
 * Request-scoped governance context of the current user (V0.2 design §13).
 *
 * Loads, in ONE round of prepared queries per subject:
 *
 *   ChurchCRM User → person_id → gov_identity
 *     → active gov_identity_role rows (joined to gov_role + gov_appointment)
 *     → effective scopes (gov_identity_scope, appointment-bound)
 *     → effective permissions (gov_role_permission + identity overrides)
 *
 * The context is memoised per PHP request (never persisted, never cached to
 * disk — person facts stay in ChurchCRM). Appointment lifecycle is honoured:
 * an ended appointment contributes no roles, scopes or permissions.
 */
final class GovernanceContext
{
    private static ?self $current = null;

    /** @var array<string, mixed>|null null = no governance identity */
    private static ?array $data = null;

    private function __construct(array $data)
    {
        self::$data = $data;
    }

    // ------------------------------------------------------------- factories

    /** Context of the currently authenticated user (memoised). */
    public static function current(): ?self
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $user = GovAuthorization::currentUser();

        return self::$current = $user === null ? null : self::forUser($user);
    }

    /** Context for one ChurchCRM user. */
    public static function forUser(User $user): ?self
    {
        $personId = (int) $user->getPersonId();
        if ($personId <= 0) {
            return null;
        }

        $conn = Propel::getConnection();

        // 1. governance identity
        $stmt = $conn->prepare('SELECT * FROM gov_identity WHERE person_id = :pid LIMIT 1');
        $stmt->bindValue(':pid', $personId, \PDO::PARAM_INT);
        $stmt->execute();
        $identity = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($identity === false || ($identity['identity_status'] ?? '') !== 'active') {
            return null;
        }
        $identityId = (int) $identity['id'];

        // 2. active identity roles joined to the role + appointment lifecycle
        $stmt = $conn->prepare(
            'SELECT ir.id AS identity_role_id, ir.appointment_id, ir.start_date AS ir_start, ir.end_date AS ir_end,
                    r.id AS role_id, r.name AS role_name, r.role_code,
                    a.status AS appointment_status, a.end_date AS appointment_end
             FROM gov_identity_role ir
             JOIN gov_role r ON r.id = ir.role_id
             LEFT JOIN gov_appointment a ON a.id = ir.appointment_id
             WHERE ir.identity_id = :iid AND ir.status = :active'
        );
        $stmt->bindValue(':iid', $identityId, \PDO::PARAM_INT);
        $stmt->bindValue(':active', 'active');
        $stmt->execute();
        $roles = [];
        $today = date('Y-m-d');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $appointmentId = $row['appointment_id'] !== null ? (int) $row['appointment_id'] : null;

            // Appointment lifecycle: an active appointment is required when
            // the identity role is bound to one; expired appointments stop
            // producing authority.
            $appointmentActive = true;
            if ($appointmentId !== null) {
                $appointmentActive = ($row['appointment_status'] ?? '') === 'active';
                if ($appointmentActive && !empty($row['appointment_end']) && $row['appointment_end'] < $today) {
                    $appointmentActive = false;
                }
            }
            // identity_role window
            if ($appointmentActive && !empty($row['ir_end']) && $row['ir_end'] < $today) {
                $appointmentActive = false;
            }

            $roles[] = [
                'identity_role_id' => (int) $row['identity_role_id'],
                'role_id' => (int) $row['role_id'],
                'role_code' => (string) $row['role_code'],
                'role_name' => (string) $row['role_name'],
                'appointment_id' => $appointmentId,
                'active' => $appointmentActive,
            ];
        }

        // 3. effective scopes: explicit identity scopes + appointment-bound
        //    role scopes resolved to concrete scope rows.
        $stmt = $conn->prepare(
            'SELECT s.identity_id AS sid, s.scope_type, s.scope_id, s.source_type, s.status, s.end_date
             FROM gov_identity_scope s WHERE s.identity_id = :iid'
        );
        $stmt->bindValue(':iid', $identityId, \PDO::PARAM_INT);
        $stmt->execute();
        $scopeRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $conn->prepare(
            'SELECT rs.scope_type, rs.scope_id FROM gov_role_scope rs
             JOIN gov_identity_role ir ON ir.role_id = rs.role_id
             WHERE ir.identity_id = :iid'
        );
        $stmt->bindValue(':iid', $identityId, \PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $rs) {
            $scopeRows[] = [
                'identity_id' => $identityId,
                'scope_type' => $rs['scope_type'],
                'scope_id' => $rs['scope_id'],
                'source_type' => 'inherited',
                'status' => 'active',
                'end_date' => null,
            ];
        }

        $scopes = [];
        foreach ($scopeRows as $s) {
            if (($s['status'] ?? 'active') !== 'active') {
                continue;
            }
            if (!empty($s['end_date']) && $s['end_date'] < $today) {
                continue;
            }
            if (!in_array($s['scope_type'], GovRepository::SCOPE_TYPES, true)) {
                continue; // never trust unknown scope types
            }
            $key = $s['scope_type'] . '#' . ($s['scope_id'] ?? '');
            $scopes[$key] = [
                'scope_type' => (string) $s['scope_type'],
                'scope_id' => $s['scope_id'] !== null ? (int) $s['scope_id'] : null,
                'source_type' => (string) $s['source_type'],
            ];
        }

        // 4. effective permissions: role permissions of ACTIVE roles, then
        //    identity-level overrides (deny wins).
        $activeRoleIds = array_map(
            static fn (array $r): int => $r['role_id'],
            array_values(array_filter($roles, static fn (array $r): bool => $r['active']))
        );

        $permissions = [];
        $activeRoleIds = array_values(array_unique($activeRoleIds));
        if ($activeRoleIds !== []) {
            $named = [];
            $params = [];
            foreach ($activeRoleIds as $i => $roleId) {
                $ph = ':r' . $i;
                $named[] = $ph;
                $params[$ph] = $roleId;
            }
            $stmt = $conn->prepare(
                'SELECT DISTINCT p.permission_key FROM gov_role_permission rp
                 JOIN gov_permission p ON p.id = rp.permission_id AND p.status = :pactive
                 WHERE rp.role_id IN (' . implode(',', $named) . ') AND rp.grant_mode = :allow'
            );
            $stmt->bindValue(':pactive', 'active');
            $stmt->bindValue(':allow', 'allow');
            foreach ($params as $ph => $roleId) {
                $stmt->bindValue($ph, $roleId, \PDO::PARAM_INT);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $key) {
                if (isset(GovRepository::PERMISSIONS[$key])) {
                    $permissions[$key] = 'role';
                }
            }
        }

        $stmt = $conn->prepare(
            'SELECT p.permission_key, ip.grant_mode FROM gov_identity_permission ip
             JOIN gov_permission p ON p.id = ip.permission_id AND p.status = :pactive
             WHERE ip.identity_id = :iid AND ip.status = :iactive'
        );
        $stmt->bindValue(':pactive', 'active');
        $stmt->bindValue(':iid', $identityId, \PDO::PARAM_INT);
        $stmt->bindValue(':iactive', 'active');
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = $row['permission_key'];
            if (!isset(GovRepository::PERMISSIONS[$key])) {
                continue;
            }
            if ($row['grant_mode'] === 'deny') {
                $permissions[$key] = 'explicit-deny';
            } elseif (($permissions[$key] ?? null) !== 'explicit-deny') {
                $permissions[$key] = 'explicit-grant';
            }
        }

        $data = [
            'identity_id' => $identityId,
            'person_id' => $personId,
            'identity_status' => (string) $identity['identity_status'],
            'member_since' => $identity['member_since'],
            'roles' => $roles,
            'active_role_ids' => $activeRoleIds,
            'scopes' => array_values($scopes),
            'permissions' => $permissions,
        ];

        // implicit self-scope: a person always "belongs to" their own
        // person-scoped records (own tasks, own identity) without an
        // explicit assignment.
        $data['scopes'][] = [
            'scope_type' => 'person',
            'scope_id' => $personId,
            'source_type' => 'self',
        ];

        return self::$current = new self($data);
    }

    // -------------------------------------------------------------- accessors

    public function identityId(): int
    {
        return (int) self::$data['identity_id'];
    }

    public function personId(): int
    {
        return (int) self::$data['person_id'];
    }

    /** @return array<int, array<string, mixed>> */
    public function roles(): array
    {
        return self::$data['roles'];
    }

    /** @return array<int, array<string, mixed>> */
    public function activeRoles(): array
    {
        return array_values(array_filter($this->roles(), static fn (array $r): bool => $r['active']));
    }

    /** @return array<int, array<string, mixed>> */
    public function scopes(): array
    {
        return self::$data['scopes'];
    }

    /** @return array<string, string> permission_key => source */
    public function permissions(): array
    {
        return self::$data['permissions'];
    }

    /** Raw context (for the My Governance Center). */
    public function toArray(): array
    {
        return self::$data;
    }

    /** Test seam: forget the request-scoped context. */
    public static function reset(): void
    {
        self::$current = null;
        self::$data = null;
    }
}
