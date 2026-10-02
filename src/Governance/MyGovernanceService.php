<?php

namespace ChurchCRM\Plugins\MosGov\Governance;

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Integration\PersonLookup;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovernancePolicy;
use ChurchCRM\Plugins\MosGov\Security\PermissionResolver;
use ChurchCRM\Plugins\MosGov\Security\ScopeResolver;
use Propel\Runtime\Propel;

/**
 * Aggregates the "My Governance Center" answer chain (V0.2 design §26/§27):
 *
 *   我是谁 → 我承担什么角色 → 我被托付什么 → 我负责什么范围
 *     → 我能看到什么 → 我能做什么 → 我现在要完成什么 → 我向谁负责
 *
 * All queries run through the repository with prepared statements; personal
 * data is resolved read-only via PersonLookup (no ChurchCRM facts copied).
 */
final class MyGovernanceService
{
    private GovRepository $repo;

    public function __construct(?GovRepository $repo = null)
    {
        $this->repo = $repo ?? new GovRepository();
    }

    /**
     * Full picture for one identity.
     *
     * @return array<string, mixed>
     */
    public function build(GovernanceContext $ctx): array
    {
        $iid = $ctx->identityId();
        $identity = $this->repo->find('identity', $iid) ?? [];

        // 1-2. identity + roles + appointments
        $roles = $ctx->roles();
        $appointmentIds = array_filter(array_map(
            static fn (array $r): ?int => $r['appointment_id'],
            $roles
        ));
        $appointments = [];
        foreach ($appointmentIds as $aid) {
            $appointment = $this->repo->find('appointment', (int) $aid);
            if ($appointment !== null) {
                $appointments[] = $appointment;
            }
        }

        // 3. responsibilities: role templates + appointment-specific
        $responsibilities = [];
        $roleIds = array_map(static fn (array $r): int => $r['role_id'], $roles);
        foreach ($roleIds as $rid) {
            foreach ($this->repo->listWhere('responsibility', ['role_id' => (int) $rid], 50) as $resp) {
                $responsibilities['r' . $resp['id']] = $resp + ['via' => 'role'];
            }
        }
        foreach ($appointments as $a) {
            foreach ($this->repo->listWhere('responsibility', ['appointment_id' => (int) $a['id']], 50) as $resp) {
                $responsibilities['a' . $resp['id']] = $resp + ['via' => 'appointment'];
            }
        }

        // 4. scopes, with human labels
        $scopes = [];
        foreach ($ctx->scopes() as $scope) {
            $scopes[] = $scope + [
                'label' => $this->scopeLabel($scope['scope_type'], $scope['scope_id']),
            ];
        }

        // 5. permissions (what I may do), grouped
        $permissions = [];
        foreach ($ctx->permissions() as $key => $source) {
            if ($source === PermissionResolver::SOURCE_EXPLICIT_DENY) {
                continue;
            }
            [$resourceType, $action] = explode('.', $key, 2);
            $permissions[$resourceType] ??= [];
            $permissions[$resourceType][] = ['action' => $action, 'key' => $key, 'source' => $source];
        }

        // 6. my tasks
        $tasks = $this->repo->listWhere('task', ['assignee_person_id' => $ctx->personId()], 50);
        $openTasks = array_values(array_filter($tasks, static fn (array $t): bool => in_array($t['status'], ['open', 'in_progress'], true)));

        // 7. accountability: who I report to (body of my roles) + decisions
        $bodies = [];
        foreach ($roles as $role) {
            $roleRow = $this->repo->find('role', $role['role_id']);
            if ($roleRow !== null && ($roleRow['body_id'] ?? null) !== null) {
                $body = $this->repo->find('body', (int) $roleRow['body_id']);
                if ($body !== null) {
                    $bodies[(int) $body['id']] = $body;
                }
            }
        }

        $person = PersonLookup::find($ctx->personId());

        return [
            'identity' => $identity,
            'person' => $person,
            'roles' => $roles,
            'appointments' => $appointments,
            'responsibilities' => array_values($responsibilities),
            'scopes' => $scopes,
            'permissions' => $permissions,
            'tasks' => $tasks,
            'open_tasks' => $openTasks,
            'bodies' => array_values($bodies),
        ];
    }

    /** Human label for a scope pair (resolved through the repository). */
    private function scopeLabel(string $scopeType, ?int $scopeId): string
    {
        if ($scopeId === null) {
            return match ($scopeType) {
                'global' => 'Global (entire system)',
                'church' => 'Church (whole congregation)',
                default => ucfirst($scopeType),
            };
        }

        $row = match ($scopeType) {
            'structure' => $this->repo->find('structure', $scopeId),
            'body' => $this->repo->find('body', $scopeId),
            'role' => $this->repo->find('role', $scopeId),
            'group', 'ministry', 'activity', 'project' => null, // ChurchCRM references
            'person' => null,
            default => null,
        };

        $name = $row['name'] ?? null;
        if ($name !== null) {
            return ucfirst($scopeType) . ': ' . $name . ' (#' . $scopeId . ')';
        }

        if ($scopeType === 'person') {
            $label = PersonLookup::label($scopeId);

            return 'Person: ' . $label;
        }

        // ChurchCRM group/ministry/event references stay opaque integers,
        // resolved only as far as governance needs.
        return ucfirst($scopeType) . ' #' . $scopeId;
    }
}
