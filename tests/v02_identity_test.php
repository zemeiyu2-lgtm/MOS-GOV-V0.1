<?php

/**
 * MOS-GOV V0.2 — governance identity lifecycle test (§13).
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;

$repo = new GovRepository();
$service = new IdentityService($repo);
[$admin, $member] = $mosGovUsers();

$mosGovSection('1. Identity provisioning');

if ($member === null) {
    $mosGovSkip('identity tests', 'no non-administrator user with an API key exists');
    $mosGovFinish('V0.2 IDENTITY');
}

$personId = (int) $member->getPersonId();
$identityId = $service->provisionIdentity($personId, ['member_since' => '2020-01-01']);
$mosGovTrack('identity', $identityId);
$mosGovCheck('identity provisioned for person', $identityId > 0, "identity={$identityId}");

$again = $service->provisionIdentity($personId);
$mosGovCheck('identity is idempotent per person', $again === $identityId, "{$again} vs {$identityId}");

$rows = $repo->listWhere('identity', ['person_id' => $personId], 5);
$mosGovCheck('exactly one identity row per person', count($rows) === 1);

$mosGovSection('2. Identity validation');

try {
    $service->provisionIdentity(99999999);
    $mosGovCheck('unknown person refused', false);
} catch (GovDataException $e) {
    $mosGovCheck('unknown person refused', true, $e->getMessage());
}

$bad = $repo->validate('identity', ['person_id' => $personId, 'identity_status' => 'zombie']);
$mosGovCheck('identity_status whitelist enforced', $bad !== []);

$mosGovSection('3. Identity role attachment');

// locate the seeded system role A01
$a01 = null;
foreach ($repo->list('role', 1000) as $role) {
    if (($role['role_code'] ?? '') === 'A01') {
        $a01 = $role;
        break;
    }
}
$mosGovCheck('seeded system role A01 found', $a01 !== null);

$attachId = $service->attachRole($identityId, (int) $a01['id'], null, '2026-01-01');
$mosGovTrack('identity_role', $attachId);
$mosGovCheck('role attached to identity', $attachId > 0);

try {
    $service->attachRole($identityId, (int) $a01['id'], null);
    $mosGovCheck('duplicate active attachment refused', false);
} catch (GovDataException $e) {
    $mosGovCheck('duplicate active attachment refused', true);
}

try {
    $service->attachRole($identityId, 99999999, null);
    $mosGovCheck('unknown role refused', false);
} catch (GovDataException $e) {
    $mosGovCheck('unknown role refused', true);
}

$mosGovSection('4. Identity role lifecycle');

// close the identity role directly: it must stop contributing authority
$stmt = Propel\Runtime\Propel::getConnection()->prepare("UPDATE gov_identity_role SET status = 'inactive' WHERE id = :id");
$stmt->bindValue(':id', $attachId, \PDO::PARAM_INT);
$stmt->execute();
$afterClose = $repo->listWhere('identity_role', ['identity_id' => $identityId, 'status' => 'active'], 10);
$mosGovCheck('closed identity role no longer active', $afterClose === []);

$mosGovCleanup();
$mosGovFinish('V0.2 IDENTITY');
