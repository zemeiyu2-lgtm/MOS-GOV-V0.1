<?php

namespace ChurchCRM\Plugins\MosGov\Governance;

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Integration\PersonLookup;
use ChurchCRM\Plugins\MosGov\Security\AuditService;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use Propel\Runtime\Propel;

/**
 * Governance identity lifecycle (V0.2 design §13/§15).
 *
 * Identity is separated from role: a ChurchCRM person holds ONE governance
 * identity; roles are attached through gov_identity_role and (when the role
 * is an office) through a gov_appointment that governs the lifecycle. Ending
 * an appointment must end the identity role's authority — enforced by
 * re-checking the appointment on every context load.
 *
 * All writes are audited and require explicit authorization upstream
 * (identity.edit / role.manage / appointment.* via GovernancePolicy).
 */
final class IdentityService
{
    private GovRepository $repo;

    public function __construct(?GovRepository $repo = null)
    {
        $this->repo = $repo ?? new GovRepository();
    }

    /** Identity of one person, or null. */
    public function findByPerson(int $personId): ?array
    {
        $rows = $this->repo->listWhere('identity', ['person_id' => $personId], 1);

        return $rows[0] ?? null;
    }

    /** Provision the identity of one person (idempotent per person). */
    public function provisionIdentity(int $personId, array $extra = []): int
    {
        if (!PersonLookup::exists($personId)) {
            throw new GovDataException('The ChurchCRM person does not exist.', ['person_id' => 'Unknown person.']);
        }

        $existing = $this->findByPerson($personId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $id = $this->repo->insert('identity', array_merge([
            'person_id' => $personId,
            'identity_status' => 'active',
        ], $extra));

        AuditService::auditCurrent('create', 'identity', $id, 'ALLOW', 'identity provisioned');

        return $id;
    }

    /** Attach a role to an identity, optionally bound to an appointment. */
    public function attachRole(int $identityId, int $roleId, ?int $appointmentId = null, ?string $startDate = null, ?string $endDate = null): int
    {
        if ($this->repo->find('identity', $identityId) === null) {
            throw new GovDataException('The governance identity does not exist.', ['identity_id' => 'Unknown identity.']);
        }
        if ($this->repo->find('role', $roleId) === null) {
            throw new GovDataException('The governance role does not exist.', ['role_id' => 'Unknown role.']);
        }
        if ($appointmentId !== null && $this->repo->find('appointment', $appointmentId) === null) {
            throw new GovDataException('The appointment does not exist.', ['appointment_id' => 'Unknown appointment.']);
        }

        // one active attachment per (identity, role) pair
        foreach ($this->repo->listWhere('identity_role', ['identity_id' => $identityId], 200) as $row) {
            if ((int) $row['role_id'] === $roleId && $row['status'] === 'active') {
                throw new GovDataException('This identity already holds this role.', ['role_id' => 'Role already attached.']);
            }
        }

        $id = $this->repo->insert('identity_role', [
            'identity_id' => $identityId,
            'role_id' => $roleId,
            'appointment_id' => $appointmentId,
            'status' => 'active',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        AuditService::auditCurrent('attach-role', 'identity_role', $id, 'ALLOW', 'role ' . $roleId . ' attached to identity ' . $identityId);

        return $id;
    }

    /**
     * End an appointment. Lifecycle rule (§15): the bound identity role(s)
     * are closed with it, so no ended appointment keeps producing authority.
     */
    public function endAppointment(int $appointmentId, ?string $endDate = null): void
    {
        $appointment = $this->repo->find('appointment', $appointmentId);
        if ($appointment === null) {
            throw new GovDataException('The appointment does not exist.');
        }

        $data = $appointment;
        $data['status'] = 'inactive';
        if ($endDate !== null && $endDate !== '') {
            $data['end_date'] = $endDate;
        }
        $this->repo->update('appointment', $appointmentId, $data);

        // close bound identity roles
        $conn = Propel::getConnection();
        $stmt = $conn->prepare(
            "UPDATE gov_identity_role SET status = 'inactive', end_date = COALESCE(end_date, :end)
             WHERE appointment_id = :aid AND status = 'active'"
        );
        $stmt->bindValue(':end', $endDate ?? date('Y-m-d'));
        $stmt->bindValue(':aid', $appointmentId, \PDO::PARAM_INT);
        $stmt->execute();

        AuditService::auditCurrent('end-appointment', 'appointment', $appointmentId, 'ALLOW', 'appointment ended; bound identity roles closed');
    }

    /**
     * Grant / deny one permission to one identity (explicit override).
     * Boundaries: unknown permission keys are refused; the override cannot
     * bypass the security model (P5 default deny stays, scope containment
     * stays) — denies always win in the policy engine.
     */
    public function overridePermission(int $identityId, int $permissionId, string $grantMode, string $reason, array $extra = []): int
    {
        if (!in_array($grantMode, GovRepository::GRANT_MODES, true)) {
            throw new GovDataException('Grant mode must be grant or deny.');
        }

        $perm = $this->repo->find('permission', $permissionId);
        if ($perm === null || !isset(GovRepository::PERMISSIONS[$perm['permission_key']])) {
            throw new GovDataException('Unknown permission.');
        }
        // risk guard: critical permissions cannot be granted as personal overrides
        if ($grantMode === 'grant' && ($perm['risk_level'] ?? '') === 'critical') {
            throw new GovDataException('Critical permissions cannot be granted as a personal override.', ['permission_id' => 'Risk level critical.']);
        }

        $id = $this->repo->insert('identity_permission', array_merge([
            'identity_id' => $identityId,
            'permission_id' => $permissionId,
            'grant_mode' => $grantMode,
            'reason' => $reason,
            'authorized_by' => GovAuthorization::currentUser()?->getPersonId(),
            'status' => 'active',
        ], $extra));

        AuditService::auditCurrent(
            'permission-override',
            'identity_permission',
            $id,
            'ALLOW',
            $grantMode . ' ' . $perm['permission_key'] . ' on identity ' . $identityId . ': ' . $reason
        );

        return $id;
    }

    /** Assign a concrete scope to an identity (least privilege, whitelisted). */
    public function assignScope(int $identityId, string $scopeType, ?int $scopeId, string $sourceType = 'manual_assignment', ?int $sourceId = null, array $extra = []): int
    {
        if (!in_array($scopeType, GovRepository::SCOPE_TYPES, true)) {
            throw new GovDataException('Unknown scope type.');
        }
        $needsId = !in_array($scopeType, GovRepository::GLOBAL_SCOPE_TYPES, true);
        if ($needsId && ($scopeId === null || $scopeId < 1)) {
            throw new GovDataException('Scope type "' . $scopeType . '" requires a numeric scope ID.');
        }
        if (!$needsId && $scopeId !== null) {
            throw new GovDataException('Scope type "' . $scopeType . '" must not carry a numeric scope ID.');
        }
        if (!in_array($sourceType, GovRepository::SCOPE_SOURCE_TYPES, true)) {
            throw new GovDataException('Unknown scope source type.');
        }

        $id = $this->repo->insert('identity_scope', array_merge([
            'identity_id' => $identityId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => 'active',
        ], $extra));

        AuditService::auditCurrent('assign-scope', 'identity_scope', $id, 'ALLOW', $scopeType . '#' . ($scopeId ?? '') . ' to identity ' . $identityId);

        return $id;
    }
}
