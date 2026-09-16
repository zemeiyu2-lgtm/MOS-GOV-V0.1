<?php

/**
 * MOS-GOV V0.2 — scope containment test (§16).
 *
 * Core rule: Group 12 is never widened to Group 13 by ID arithmetic; an
 * identity without a scope sees only its own person-scoped records.
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\ScopeResolver;

$repo = new GovRepository();
$service = new IdentityService($repo);
[$admin, $member] = $mosGovUsers();

$mosGovSection('1. Scope whitelist enforcement');

$bad = $repo->validate('scope', ['scope_type' => 'galaxy', 'name' => 'X']);
$mosGovCheck('unknown scope_type rejected by validation', $bad !== []);

$bad = $repo->validate('scope', ['scope_type' => 'group', 'name' => 'X', 'scope_id' => null]);
$mosGovCheck('group scope without numeric ID rejected', $bad !== []);

$bad = $repo->validate('scope', ['scope_type' => 'global', 'name' => 'X', 'scope_id' => 5]);
$mosGovCheck('global scope with numeric ID rejected', $bad !== []);

try {
    $service->assignScope(1, 'galaxy', null);
    $mosGovCheck('service rejects unknown scope type', false);
} catch (GovDataException $e) {
    $mosGovCheck('service rejects unknown scope type', true);
}

$mosGovSection('2. ScopeResolver containment (exact pairs)');

$mk = static fn (string $type, ?int $id) => ['scope_type' => $type, 'scope_id' => $id];

$mosGovCheck('global covers church', ScopeResolver::covered([$mk('global', null)], ['church' => [null]]));
$mosGovCheck('church covers body 7', ScopeResolver::covered([$mk('church', null)], ['body' => [7]]));
$mosGovCheck('body 7 does not cover body 8', !ScopeResolver::covered([$mk('body', 7)], ['body' => [8]]));
$mosGovCheck('group 12 does not cover group 13', !ScopeResolver::covered([$mk('group', 12)], ['group' => [13]]));
$mosGovCheck('group 12 covers group 12', ScopeResolver::covered([$mk('group', 12)], ['group' => [12]]));
$mosGovCheck('person 3 does not cover person 4', !ScopeResolver::covered([$mk('person', 3)], ['person' => [4]]));

$mosGovSection('3. Identity scope rows (least privilege)');

if ($member === null) {
    $mosGovSkip('identity scope tests', 'no non-administrator user with an API key exists');
    $mosGovCleanup();
    $mosGovFinish('V0.2 SCOPE');
}

$personId = (int) $member->getPersonId();
$identityId = $service->provisionIdentity($personId);
$mosGovTrack('identity', $identityId);

// seed a group scope object + assignment (group 12 exists only as a scope);
// reuse the row when a previous crashed run left it behind
$scopeRows = $repo->listWhere('scope', ['scope_type' => 'group', 'scope_id' => 12], 1);
if ($scopeRows !== []) {
    $scopeId = (int) $scopeRows[0]['id'];
} else {
    $scopeId = $repo->insert('scope', ['scope_type' => 'group', 'scope_id' => 12, 'name' => 'V02 Test Group 12', 'status' => 'active']);
    $mosGovTrack('scope', $scopeId);
}
$assignId = $service->assignScope($identityId, 'group', 12);
$mosGovTrack('identity_scope', $assignId);
$mosGovCheck('identity scope assigned (group 12)', $assignId > 0);

GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$scopes = $ctx->scopes();
$mosGovCheck('context carries group 12 scope', in_array(12, array_map(static fn ($s) => $s['scope_id'], $scopes), true), json_encode($scopes));

// meeting scoped to body 3 (arbitrary): group-12 identity is OUT of scope
$meetingRow = ['id' => 3, 'body_id' => 3, 'title' => 'X'];
$mosGovCheck('group scope does NOT cover another body meeting', !ScopeResolver::covered($scopes, ScopeResolver::resourceScopes('meeting', $meetingRow)));

// a task assigned to the member themself is person-scoped and always visible
$ownTask = ['id' => 9, 'assignee_person_id' => $personId, 'title' => 'Own'];
$mosGovCheck('own task is within scope', ScopeResolver::covered($scopes, ScopeResolver::resourceScopes('task', $ownTask)));

$otherTask = ['id' => 10, 'assignee_person_id' => $personId + 1000, 'title' => 'Other'];
$mosGovCheck("another person's task is OUT of scope", !ScopeResolver::covered($scopes, ScopeResolver::resourceScopes('task', $otherTask)));

GovAuthorization::reset();
$mosGovCleanup();
$mosGovFinish('V0.2 SCOPE');
