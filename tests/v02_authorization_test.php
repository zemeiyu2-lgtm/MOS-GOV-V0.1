<?php

/**
 * MOS-GOV V0.2 — unified authorization engine test (§17/§18/§21).
 *
 * Walks the strict evaluation order with real rows:
 * unauthenticated → no identity → inactive identity → no active role →
 * appointment ended → scope outside → P5 → explicit DENY → ALLOW.
 * Includes the §21 URL/ID-guessing scenario at data level.
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Security\AuthorizationDecision;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\GovernancePolicy;

$repo = new GovRepository();
$service = new IdentityService($repo);
[$admin, $member] = $mosGovUsers();

$mosGovSection('1. Step 1 — unauthenticated');

$decision = GovernancePolicy::decide(null, 'view', 'meeting');
$mosGovCheck('anonymous user denied', !$decision->allowed, $decision->reason);

$mosGovSection('2. Steps 2–4 — identity and role');

if ($member === null) {
    $mosGovSkip('identity steps', 'no non-administrator user with an API key exists');
    $mosGovCleanup();
    $mosGovFinish('V0.2 AUTHORIZATION');
}

$personId = (int) $member->getPersonId();

// clean test-run leftovers so the "no identity" step starts from zero
$mosGovResetIdentity($personId);
$conn = Propel\Runtime\Propel::getConnection();

$decision = GovernancePolicy::decide($member, 'view', 'meeting');
$mosGovCheck('no governance identity → denied', !$decision->allowed, $decision->reason);

$identityId = $service->provisionIdentity($personId);
$mosGovTrack('identity', $identityId);

$a01 = $b03 = $e01 = null;
foreach ($repo->list('role', 1000) as $role) {
    $code = $role['role_code'] ?? '';
    if ($code === 'A01') { $a01 = $role; }
    if ($code === 'B03') { $b03 = $role; }
    if ($code === 'E01') { $e01 = $role; }
}

$decision = GovernancePolicy::decide($member, 'view', 'meeting');
$mosGovCheck('identity without active role → denied', !$decision->allowed, $decision->reason);

$attachId = $service->attachRole($identityId, (int) $a01['id']);
$mosGovTrack('identity_role', $attachId);
GovAuthorization::reset();
$decision = GovernancePolicy::decide($member, 'view', 'governance');
$mosGovCheck('A01 with active role → module view allowed', $decision->allowed, $decision->reason);

$mosGovSection('3. Step 6 — appointment lifecycle (§15)');

// end the bound identity role via an ended appointment: authority must stop.
// end the unbound attachment before attaching the appointment-bound one
// (one active attachment per identity/role pair)
$stmt = $conn->prepare("UPDATE gov_identity_role SET status = 'inactive' WHERE id = :id");
$stmt->bindValue(':id', $attachId, \PDO::PARAM_INT);
$stmt->execute();

$appointmentId = $repo->insert('appointment', [
    'role_id' => (int) $a01['id'],
    'person_id' => $personId,
    'status' => 'active',
    'start_date' => '2026-01-01',
    'end_date' => date('Y-m-d'),
]);
$mosGovTrack('appointment', $appointmentId);
// FINAL REVIEW: this bound attachment used to discard its id, so every run
// left one orphaned gov_identity_role row behind.
$appointmentAttachId = $service->attachRole($identityId, (int) $a01['id'], $appointmentId, '2026-01-01', date('Y-m-d'));
$mosGovTrack('identity_role', $appointmentAttachId);
GovAuthorization::reset();

$ended = $service instanceof IdentityService;
$stmt = Propel\Runtime\Propel::getConnection()->prepare("UPDATE gov_appointment SET status = 'inactive' WHERE id = :id");
$stmt->bindValue(':id', $appointmentId, \PDO::PARAM_INT);
$stmt->execute();
$service->endAppointment($appointmentId, date('Y-m-d'));
GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$activeCodes = array_column($ctx->activeRoles(), 'role_code');
$mosGovCheck('ended appointment produces no active authority', !in_array('A01', $activeCodes, true) && $activeCodes === [], json_encode($activeCodes));

$mosGovSection('4. Step 7 — scope containment (§21 ID guessing)');

// re-attach A01 (unbound) so the scope steps have an active role again
$stmt = $conn->prepare("UPDATE gov_identity_role SET status = 'inactive' WHERE identity_id = :iid AND role_id = :rid AND status = 'active'");
$stmt->bindValue(':iid', $identityId, \PDO::PARAM_INT);
$stmt->bindValue(':rid', (int) $a01['id'], \PDO::PARAM_INT);
$stmt->execute();
$reattachId = $service->attachRole($identityId, (int) $a01['id']);
$mosGovTrack('identity_role', $reattachId);
GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$mosGovCheck('A01 identity active again', $ctx !== null && $ctx->activeRoles() !== []);

// a task assigned to somebody else (the classic /mos-gov/task/100 guess)
$otherTask = ['id' => 100, 'assignee_person_id' => $personId + 999, 'title' => 'Not yours'];
$decision = GovernancePolicy::decide($member, 'view', 'task', $otherTask);
$mosGovCheck('task outside scope DENIED (ID guessing)', !$decision->allowed, $decision->reason);

$otherMeeting = ['id' => 100, 'body_id' => 77, 'title' => 'Not yours'];
$decision = GovernancePolicy::decide($member, 'view', 'meeting', $otherMeeting);
$mosGovCheck('meeting outside scope DENIED (ID guessing)', !$decision->allowed, $decision->reason);

$ownTask = ['id' => 101, 'assignee_person_id' => $personId, 'title' => 'Yours'];
$decision = GovernancePolicy::decide($member, 'view', 'task', $ownTask);
$mosGovCheck('own task ALLOWED', $decision->allowed, $decision->reason);

$mosGovSection('5. Steps 8–10 — P5 and explicit DENY');

$ownMeetingP5 = ['id' => 102, 'body_id' => null, 'title' => 'X', 'minutes' => 'secret'];
$decision = GovernancePolicy::decide($member, 'export', 'meeting', $ownMeetingP5);
$mosGovCheck('export denied without export permission (view≠export)', !$decision->allowed, $decision->reason);

// explicit deny on governance.view
$permIndex = [];
foreach ($repo->list('permission', 1000) as $p) {
    $permIndex[$p['permission_key']] = (int) $p['id'];
}
$denyId = $service->overridePermission($identityId, $permIndex['governance.view'], 'deny', 'V02 test deny');
$mosGovTrack('identity_permission', $denyId);
GovAuthorization::reset();
$decision = GovernancePolicy::decide($member, 'view', 'governance');
$mosGovCheck('explicit DENY outranks role GRANT', !$decision->allowed, $decision->reason);

GovAuthorization::reset();
$mosGovCleanup();
$mosGovFinish('V0.2 AUTHORIZATION');
