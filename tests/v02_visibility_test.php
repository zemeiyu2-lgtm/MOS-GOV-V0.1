<?php

/**
 * MOS-GOV V0.2 — information visibility test (§10/§22/§42). P5 = DENY.
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\GovernancePolicy;
use ChurchCRM\Plugins\MosGov\Security\VisibilityResolver;

$repo = new GovRepository();
$service = new IdentityService($repo);
[$admin, $member] = $mosGovUsers();

$mosGovSection('1. Level defaults per resource');

$mosGovCheck('structure is P2', VisibilityResolver::levelFor('structure') === 'P2');
$mosGovCheck('meeting is P3', VisibilityResolver::levelFor('meeting') === 'P3');
$mosGovCheck('identity is P4', VisibilityResolver::levelFor('identity') === 'P4');
$mosGovCheck('appointment is P4', VisibilityResolver::levelFor('appointment') === 'P4');

$mosGovSection('2. P5 field mapping (§42)');

$mosGovCheck('identity.notes is a P5 field', VisibilityResolver::isSensitiveField('identity', 'notes'));
$mosGovCheck('meeting.minutes is a P5 field', VisibilityResolver::isSensitiveField('meeting', 'minutes'));
$mosGovCheck('decision.decision_text is a P5 field', VisibilityResolver::isSensitiveField('decision', 'decision_text'));
$mosGovCheck('meeting.title is NOT a P5 field', !VisibilityResolver::isSensitiveField('meeting', 'title'));

$mosGovSection('3. Level visibility per role context');

if ($member === null) {
    $mosGovSkip('identity visibility tests', 'no non-administrator user with an API key exists');
    $mosGovCleanup();
    $mosGovFinish('V0.2 VISIBILITY');
}

$personId = (int) $member->getPersonId();
$identityId = $service->provisionIdentity($personId);
$mosGovTrack('identity', $identityId);

$a01 = null;
foreach ($repo->list('role', 1000) as $role) {
    if (($role['role_code'] ?? '') === 'A01') {
        $a01 = $role;
        break;
    }
}
$attachId = $service->attachRole($identityId, (int) $a01['id']);
$mosGovTrack('identity_role', $attachId);

GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$mosGovCheck('P1 viewable', VisibilityResolver::canSeeLevel($ctx, 'P1'));
$mosGovCheck('P2 viewable', VisibilityResolver::canSeeLevel($ctx, 'P2'));
$mosGovCheck('P3 viewable inside scope', VisibilityResolver::canSeeLevel($ctx, 'P3'));
$mosGovCheck('P4 viewable (governance level)', VisibilityResolver::canSeeLevel($ctx, 'P4'));
$mosGovCheck('P5 DENIED by default (no role grants P5)', !VisibilityResolver::canSeeLevel($ctx, 'P5'));
$mosGovCheck('P5 DENIED even for export action', !VisibilityResolver::canSeeLevel($ctx, 'P5', 'export'));

$mosGovSection('4. P5 explicit unlock + field masking');

// explicit P5 allow row for the A01 role (seeded none — created here, cleaned up later)
$p5RuleId = $repo->insert('visibility_rule', [
    'resource_type' => '*',
    'information_level' => 'P5',
    'role_id' => (int) $a01['id'],
    'action' => 'view',
    'rule_type' => 'allow',
    'status' => 'active',
]);
$mosGovTrack('visibility_rule', $p5RuleId);
VisibilityResolver::resetCache();
GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$mosGovCheck('explicit P5 allow row unlocks P5 for the role', VisibilityResolver::canSeeLevel($ctx, 'P5'));

// mask check
$rows = [['id' => 1, 'title' => 'T', 'minutes' => 'SECRET', 'notes' => 'N']];
$masked = GovernancePolicy::filterFields($ctx, 'meeting', $rows);
$mosGovCheck('P5 content masked when not allowed', true, 'check below');
// with P5 allowed the content stays visible
$mosGovCheck('P5 content visible with explicit grant', $masked[0]['minutes'] === 'SECRET');

// without the grant the same row must be masked
$stmt = Propel\Runtime\Propel::getConnection()->prepare("UPDATE gov_visibility_rule SET status = 'inactive' WHERE id = :id");
$stmt->bindValue(':id', $p5RuleId, \PDO::PARAM_INT);
$stmt->execute();
VisibilityResolver::resetCache();
GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$masked = GovernancePolicy::filterFields($ctx, 'meeting', $rows);
$mosGovCheck('P5 content replaced by protection marker without grant', $masked[0]['minutes'] === '__P5_PROTECTED__');

GovAuthorization::reset();
$mosGovCleanup();
$mosGovFinish('V0.2 VISIBILITY');
