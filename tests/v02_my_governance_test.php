<?php

/**
 * MOS-GOV V0.2 — My Governance Center + HTTP surfaces test (§26/§27/§46).
 *
 * Data-layer part verifies the answer-chain build; the HTTP part verifies
 * the real pages render, anonymous users are redirected, export requires an
 * explicit permission, and search does not leak out-of-scope records.
 */

require __DIR__ . '/v02_lib.php';

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Governance\MyGovernanceService;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;

$repo = new GovRepository();
$service = new IdentityService($repo);
[$admin, $member] = $mosGovUsers();

$mosGovSection('1. My Governance data build (answer chain)');

if ($member === null) {
    $mosGovSkip('my governance tests', 'no non-administrator user with an API key exists');
    $mosGovCleanup();
    $mosGovFinish('V0.2 MY GOVERNANCE');
}

$personId = (int) $member->getPersonId();
$mosGovResetIdentity($personId);
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

$taskId = $repo->insert('task', [
    'title' => 'V02 My Governance test task',
    'assignee_person_id' => $personId,
    'priority' => 'normal',
    'status' => 'open',
]);
$mosGovTrack('task', $taskId);

GovAuthorization::reset();
$ctx = GovernanceContext::forUser($member);
$myGov = (new MyGovernanceService($repo))->build($ctx);

$mosGovCheck('1. identity present', !empty($myGov['identity']));
$mosGovCheck('2. roles listed', count($myGov['roles']) >= 1);
$mosGovCheck('3. responsibilities key present', array_key_exists('responsibilities', $myGov));
$mosGovCheck('4. scopes listed', array_key_exists('scopes', $myGov));
$mosGovCheck('5. permissions grouped', array_key_exists('governance', $myGov['permissions']));
$mosGovCheck('7. open tasks include my task', count($myGov['open_tasks']) >= 1, 'task #' . $taskId);
$mosGovCheck('8. bodies (accountability) key present', array_key_exists('bodies', $myGov));

GovAuthorization::reset();

$mosGovSection('2. HTTP surfaces (§46 real browser behaviour)');

$baseUrl = rtrim(getenv('MOSGOV_BASE_URL') ?: 'http://localhost', '/');
$basePath = \ChurchCRM\dto\SystemURLs::getRootPath() . '/plugins/mos-gov';

$tmpDir = sys_get_temp_dir() . '/mosgov-v02-http-' . getmypid();
@mkdir($tmpDir, 0777, true);
$jar = $tmpDir . '/cookies';

$http = static function (string $method, string $url, string $jar, string $apiKey = '') use ($tmpDir): array {
    $ch = curl_init($url);
    $headers = ['Accept: text/html'];
    if ($apiKey !== '') {
        $headers[] = 'x-api-key: ' . $apiKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'location' => $location];
};

$anon = $http('GET', $baseUrl . $basePath . '/my-governance', $jar);
$mosGovCheck('anonymous my-governance redirected to login', $anon['status'] === 302, (string) $anon['status']);

if ($admin !== null) {
    $adminKey = (string) $admin->getApiKey();
    $adminJar = $tmpDir . '/admin.cookies';

    $page = $http('GET', $baseUrl . $basePath . '/my-governance', $adminJar, $adminKey);
    $mosGovCheck('admin my-governance renders 200', $page['status'] === 200, (string) $page['status']);
    $mosGovCheck('my-governance shows the no-identity notice for identity-less admin', str_contains($page['body'], 'No governance identity'));

    $search = $http('GET', $baseUrl . $basePath . '/search?q=rota', $adminJar, $adminKey);
    $mosGovCheck('governance search renders 200', $search['status'] === 200, (string) $search['status']);
    $mosGovCheck('search marks itself as scoped', str_contains($search['body'], 'governance scope'));

    $perm = $http('GET', $baseUrl . $basePath . '/permissions', $adminJar, $adminKey);
    $mosGovCheck('permission registry renders 200', $perm['status'] === 200, (string) $perm['status']);
    $mosGovCheck('permission registry shows the whitelist', str_contains($perm['body'], 'governance.view'));

    $identity = $http('GET', $baseUrl . $basePath . '/identity', $adminJar, $adminKey);
    $mosGovCheck('identity administration renders 200', $identity['status'] === 200, (string) $identity['status']);

    $export = $http('GET', $baseUrl . $basePath . '/tasks/export', $adminJar, $adminKey);
    $mosGovCheck('export refused without explicit permission (view≠export)', $export['status'] === 403, (string) $export['status']);
    $mosGovCheck('export denial explains the boundary', str_contains($export['body'], 'not permitted'));
} else {
    $mosGovSkip('admin HTTP checks', 'no administrator with an API key exists');
}

if ($member !== null) {
    $memberKey = (string) $member->getApiKey();
    $memberJar = $tmpDir . '/member.cookies';
    $page = $http('GET', $baseUrl . $basePath . '/my-governance', $memberJar, $memberKey);
    $mosGovCheck('member my-governance renders 200 with identity', $page['status'] === 200, (string) $page['status']);
    $mosGovCheck('member sees their own identity chain', str_contains($page['body'], 'Who I am'));

    $export = $http('GET', $baseUrl . $basePath . '/tasks/export', $memberJar, $memberKey);
    $mosGovCheck('member export DENIED by default (§24)', $export['status'] === 403, (string) $export['status']);

    $search = $http('GET', $baseUrl . $basePath . '/search?q=' . urlencode('V02 My Governance test task'), $memberJar, $memberKey);
    $mosGovCheck('member search finds only in-scope records', !str_contains($search['body'], 'OUT-OF-SCOPE-MARKER'));
}

// ===========================================================================
// 3. FINAL REVIEW §1 — legacy read surfaces must not be a scope bypass.
//    These checks exist because the deny path on the legacy detail page and
//    the identity-status gate were both broken in the first V0.2 pass:
//      (a) the detail closure did not capture the deny-page renderer;
//      (b) the deny view was rendered without its $esc helper;
//      (c) the scope gate keyed on an ACTIVE identity, so deactivating an
//          identity WIDENED access to the unscoped list.
//    All three are now covered over real HTTP.
// ===========================================================================
$mosGovSection('3. Legacy surface scope enforcement (§21 — FINAL REVIEW regression)');

// An unassigned task is church-scoped; an A01 identity only holds a
// self-person scope, so it is a genuine out-of-scope record.
$outOfScopeTask = $repo->insert('task', [
    'title' => 'OUT-OF-SCOPE-TASK-MARKER',
    'priority' => 'normal',
    'status' => 'open',
]);
$mosGovTrack('task', $outOfScopeTask);

$memberKey = $member === null ? '' : (string) $member->getApiKey();
$memberJar = $tmpDir . '/legacy-member.cookies';

// --- gate semantics (monotonic access) ------------------------------------
GovAuthorization::reset();
$mosGovCheck(
    'identity-less admin is NOT under the governance read policy',
    $admin === null || GovAuthorization::subjectToGovernancePolicy($admin) === false
);
$mosGovCheck(
    'identity holder IS under the governance read policy',
    $member !== null && GovAuthorization::subjectToGovernancePolicy($member) === true
);

// --- out-of-scope detail must deny, not error, and not leak ---------------
if ($member !== null) {
    $detail = $http('GET', $baseUrl . $basePath . '/tasks/' . $outOfScopeTask, $memberJar, $memberKey);
    $mosGovCheck('out-of-scope detail is DENIED with 403 (not 500)', $detail['status'] === 403, (string) $detail['status']);
    $mosGovCheck('denial renders the protected-information page', str_contains($detail['body'], 'This information is protected'));
    $mosGovCheck('denial states the scope boundary', str_contains($detail['body'], 'outside your governance scope'));
    $mosGovCheck('denial leaks no protected content', !str_contains($detail['body'], 'OUT-OF-SCOPE-TASK-MARKER'));

    $list = $http('GET', $baseUrl . $basePath . '/tasks', $memberJar, $memberKey);
    $mosGovCheck('legacy list still renders for an identity holder', $list['status'] === 200, (string) $list['status']);
    $mosGovCheck('legacy list hides out-of-scope rows at data level', !str_contains($list['body'], 'OUT-OF-SCOPE-TASK-MARKER'));

    $registry = $http('GET', $baseUrl . $basePath . '/permissions', $memberJar, $memberKey);
    $mosGovCheck('permission registry DENIED without permission.manage', $registry['status'] === 403, (string) $registry['status']);
    $mosGovCheck('permission registry leaks no permission matrix', !str_contains($registry['body'], 'governance.approve'));

    // --- deactivating the identity must NOT widen access ------------------
    $conn = Propel\Runtime\Propel::getConnection();
    $stmt = $conn->prepare("UPDATE gov_identity SET identity_status = 'inactive' WHERE id = :id");
    $stmt->bindValue(':id', $identityId, \PDO::PARAM_INT);
    $stmt->execute();
    GovAuthorization::reset();

    $mosGovCheck(
        'inactive identity still counts as an identity row for the read gate',
        GovAuthorization::subjectToGovernancePolicy($member) === true
    );

    $detailInactive = $http('GET', $baseUrl . $basePath . '/tasks/' . $outOfScopeTask, $memberJar, $memberKey);
    $mosGovCheck(
        'deactivating an identity does NOT open out-of-scope detail (§1)',
        $detailInactive['status'] === 403 && !str_contains($detailInactive['body'], 'OUT-OF-SCOPE-TASK-MARKER'),
        (string) $detailInactive['status']
    );

    $listInactive = $http('GET', $baseUrl . $basePath . '/tasks', $memberJar, $memberKey);
    $mosGovCheck(
        'deactivating an identity does NOT leak the unscoped list (§1)',
        !str_contains($listInactive['body'], 'OUT-OF-SCOPE-TASK-MARKER'),
        (string) $listInactive['status']
    );

    $stmt = $conn->prepare("UPDATE gov_identity SET identity_status = 'active' WHERE id = :id");
    $stmt->bindValue(':id', $identityId, \PDO::PARAM_INT);
    $stmt->execute();
    GovAuthorization::reset();
}

// --- the bootstrap administrator keeps its documented read access ---------
if ($admin !== null) {
    $adminList = $http('GET', $baseUrl . $basePath . '/tasks', $tmpDir . '/legacy-admin.cookies', (string) $admin->getApiKey());
    $mosGovCheck('identity-less admin keeps the V0.1 bootstrap list read', $adminList['status'] === 200, (string) $adminList['status']);
    $adminRegistry = $http('GET', $baseUrl . $basePath . '/permissions', $tmpDir . '/legacy-admin.cookies', (string) $admin->getApiKey());
    $mosGovCheck('administrator can still read the permission registry', $adminRegistry['status'] === 200, (string) $adminRegistry['status']);
}

GovAuthorization::reset();
$mosGovCleanup();
$mosGovFinish('V0.2 MY GOVERNANCE');
