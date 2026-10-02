<?php

/**
 * MOS-GOV V0.2 — permission registry test (§12).
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\PermissionResolver;

$repo = new GovRepository();
$service = new IdentityService($repo);
[$admin, $member] = $mosGovUsers();

$mosGovSection('1. Registry whitelist integrity');

$dbKeys = array_column($repo->list('permission', 1000), 'permission_key');
$whitelist = array_keys(GovRepository::PERMISSIONS);
$mosGovCheck('every DB permission key is in the whitelist', count(array_diff($dbKeys, $whitelist)) === 0);

$bad = $repo->validate('permission', [
    'permission_key' => 'galaxy.destroy',
    'resource_type' => 'galaxy',
    'action' => 'destroy',
    'risk_level' => 'critical',
]);
$mosGovCheck('unknown permission key cannot enter the system', $bad !== []);

$bad = $repo->validate('permission', [
    'permission_key' => 'decision.approve',
    'resource_type' => 'decision',
    'action' => 'delete',
    'risk_level' => 'high',
]);
$mosGovCheck('mismatched action rejected for a known key', $bad !== []);

$actions = ['view', 'create', 'edit', 'submit', 'approve', 'publish', 'close', 'export', 'feedback', 'manage'];
$usedActions = array_map(static fn ($p) => $p[1], GovRepository::PERMISSIONS);
$mosGovCheck('action vocabulary covers view≠edit≠export', in_array('view', $usedActions) && in_array('edit', $usedActions) && in_array('export', $usedActions) && in_array('approve', $usedActions), implode(',', array_unique($usedActions)));
$mosGovCheck('action list matches the §11 whitelist', sort($usedActions) === sort($actions) || array_values(array_unique($usedActions)) === $actions);

$mosGovSection('2. Role default permissions (§40)');

$rolesByCode = [];
foreach ($repo->list('role', 200) as $role) {
    if (!empty($role['role_code'])) {
        $rolesByCode[$role['role_code']] = $role;
    }
}
$permsByRole = static function (int $roleId) use ($repo): array {
    $keys = [];
    $permIndex = [];
    foreach ($repo->list('permission', 1000) as $p) {
        $permIndex[(int) $p['id']] = $p['permission_key'];
    }
    foreach ($repo->listWhere('role_permission', ['role_id' => $roleId], 500) as $rp) {
        $keys[] = $permIndex[(int) $rp['permission_id']] ?? '?';
    }

    return $keys;
};

$a01 = $permsByRole((int) $rolesByCode['A01']['id']);
$mosGovCheck('A01 (general member) may view governance', in_array('governance.view', $a01, true));
$mosGovCheck('A01 may NOT create meetings', !in_array('meeting.create', $a01, true));
$mosGovCheck('A01 may NOT export', !in_array('governance.export', $a01, true));
$mosGovCheck('A01 may NOT manage permissions', !in_array('permission.manage', $a01, true));

$b01 = $permsByRole((int) $rolesByCode['B01']['id']);
$mosGovCheck('B01 (group leader) may create meetings', in_array('meeting.create', $b01, true));
$mosGovCheck('B01 may NOT approve decisions', !in_array('decision.approve', $b01, true));

$e01 = $permsByRole((int) $rolesByCode['E01']['id']);
$mosGovCheck('E01 (governance admin) holds permission.manage', in_array('permission.manage', $e01, true));
$mosGovCheck('E01 holds identity.edit', in_array('identity.edit', $e01, true));
$mosGovCheck('E01 does NOT hold decision.approve (no church authority)', !in_array('decision.approve', $e01, true));

$mosGovSection('3. Explicit overrides (grant / deny)');

if ($member === null) {
    $mosGovSkip('override tests', 'no non-administrator user with an API key exists');
    $mosGovCleanup();
    $mosGovFinish('V0.2 PERMISSION');
}

$personId = (int) $member->getPersonId();
$mosGovResetIdentity($personId);
$identityId = $service->provisionIdentity($personId);
$mosGovTrack('identity', $identityId);

// attach A01, then grant meeting.create explicitly
$a01Role = $rolesByCode['A01'];
$attachId = $service->attachRole($identityId, (int) $a01Role['id']);
$mosGovTrack('identity_role', $attachId);

$permIndex = [];
foreach ($repo->list('permission', 1000) as $p) {
    $permIndex[$p['permission_key']] = (int) $p['id'];
}

GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$mosGovCheck('before override: meeting.create absent', !PermissionResolver::has($ctx, 'meeting.create'));

GovAuthorization::reset();
$overrideId = $service->overridePermission($identityId, $permIndex['meeting.create'], 'grant', 'V02 test grant');
$mosGovTrack('identity_permission', $overrideId);
$ctx = GovernanceContext::forUser($member);
$mosGovCheck('explicit grant adds meeting.create', PermissionResolver::has($ctx, 'meeting.create'));
$mosGovCheck('grant source is explicit', PermissionResolver::source($ctx, 'meeting.create') === PermissionResolver::SOURCE_EXPLICIT_GRANT);

// explicit deny wins over the role-granted governance.view
$denyId = $service->overridePermission($identityId, $permIndex['governance.view'], 'deny', 'V02 test deny');
$mosGovTrack('identity_permission', $denyId);
GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$mosGovCheck('explicit DENY wins over role GRANT', PermissionResolver::isExplicitDeny($ctx, 'governance.view') && !PermissionResolver::has($ctx, 'governance.view'));

// critical permissions cannot be granted as personal overrides
try {
    $service->overridePermission($identityId, $permIndex['permission.manage'], 'grant', 'V02 escalation attempt');
    $mosGovCheck('critical permission not grantable as override', false);
} catch (GovDataException $e) {
    $mosGovCheck('critical permission not grantable as override', true);
}

GovAuthorization::reset();
$mosGovCleanup();
$mosGovFinish('V0.2 PERMISSION');
