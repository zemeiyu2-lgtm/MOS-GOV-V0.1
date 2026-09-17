<?php

/**
 * MOS-GOV V0.1 — HTTP end-to-end test.
 *
 * Drives the running ChurchCRM instance over real HTTP (curl) to verify the
 * things a data-layer test cannot prove:
 *
 *  - authentication: anonymous requests are redirected to the login page;
 *  - authorization (R07): a non-administrator can read governance data but is
 *    refused every write route, before any CSRF processing;
 *  - CSRF: a POST without a token is rejected, a POST with the token issued by
 *    the form succeeds;
 *  - every governance list page and create form renders for an administrator;
 *  - the governance loops work over HTTP, including the "Add …" links that
 *    prefill a parent reference;
 *  - the dashboard and settings pages show live governance data.
 *
 * Credentials are read from ChurchCRM's own user table (read-only) — nothing
 * is hardcoded and no core table is modified. Rows created over HTTP are
 * deleted again through the MOS-GOV data layer at the end.
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/v01_http_test.php
 * Optional: MOSGOV_BASE_URL=http://localhost
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\model\ChurchCRM\UserQuery;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugin\PluginManager;

PluginManager::init(SystemURLs::getDocumentRoot() . '/plugins');

$baseUrl = rtrim(getenv('MOSGOV_BASE_URL') ?: 'http://localhost', '/');
$basePath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$failures = 0;
$skips = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' -- ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};
$skip = function (string $name, string $why) use (&$skips): void {
    echo '[SKIP] ' . $name . ' -- ' . $why . PHP_EOL;
    $skips++;
};
$section = static function (string $name): void {
    echo PHP_EOL . '=== ' . $name . ' ===' . PHP_EOL;
};

$tmpDir = sys_get_temp_dir() . '/mosgov-http-' . getmypid();
@mkdir($tmpDir, 0777, true);
$jarFor = static fn (string $who): string => $tmpDir . '/' . $who . '.cookies';

/**
 * Perform one HTTP request and return status, body and redirect target.
 *
 * @param array<int, string>        $headers
 * @param array<string, mixed>|null $post
 *
 * @return array{status:int, body:string, location:?string}
 */
$request = static function (
    string $method,
    string $url,
    string $jar,
    string $apiKey = '',
    ?array $post = null,
    bool $follow = false,
    string $accept = 'text/html'
) use ($tmpDir): array {
    $ch = curl_init($url);
    $headers = ['Accept: ' . $accept];
    if ($apiKey !== '') {
        $headers[] = 'x-api-key: ' . $apiKey;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_MAXREDIRS => 5,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post ?? []);
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $error !== '' ? 'CURL ERROR: ' . $error : (string) $body,
        'location' => $location,
    ];
};

/** Extract the CSRF token embedded in a MOS-GOV form. */
$csrfFrom = static function (string $html): ?string {
    return preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $html, $m) === 1 ? $m[1] : null;
};

// ------------------------------------------------------------------- users
$adminUser = null;
foreach (UserQuery::create()->filterByAdmin(true)->find() as $candidate) {
    if (!empty($candidate->getApiKey())) {
        $adminUser = $candidate;
        break;
    }
}
$readOnlyUser = null;
$editorUser = null;
foreach (UserQuery::create()->filterByAdmin(false)->find() as $candidate) {
    // ChurchCRM confines EditSelf-only accounts to the self-service flow before
    // any plugin middleware runs, so they cannot exercise plugin authorization.
    if ($candidate->isEditSelfExclusive() || empty($candidate->getApiKey())) {
        continue;
    }
    if ($candidate->isEditRecordsEnabled()) {
        // Someone who may edit ChurchCRM records but is not an administrator:
        // useful to prove the governance (admin-only) policy is tighter.
        $editorUser ??= $candidate;
        continue;
    }
    $readOnlyUser ??= $candidate;
}

echo 'Target: ' . $baseUrl . $basePath . PHP_EOL;
echo 'Admin user: ' . ($adminUser !== null ? $adminUser->getUserName() : '(none found)') . PHP_EOL;
echo 'Read-only user: ' . ($readOnlyUser !== null ? $readOnlyUser->getUserName() : '(none found)') . PHP_EOL;
echo 'Record-editor user: ' . ($editorUser !== null ? $editorUser->getUserName() : '(none found)') . PHP_EOL;

$repo = new GovRepository();
$created = ['structure' => [], 'body' => []];
$baseline = $repo->getDashboardCounts();

try {
    // ------------------------------------------------- 1. authentication
    $section('1. Authentication (anonymous)');

    if ($adminUser === null) {
        $skip('anonymous checks', 'no administrator with an API key exists in this database');
    } else {
        $anonJar = $jarFor('anon');
        $anon = $request('GET', $baseUrl . $basePath, $anonJar);
        $check(
            'anonymous dashboard is redirected to login',
            $anon['status'] === 302 && str_contains((string) $anon['location'], '/session/begin'),
            $anon['status'] . ' -> ' . (string) $anon['location']
        );
        $anonList = $request('GET', $baseUrl . $basePath . '/structures', $anonJar);
        $check('anonymous list page is redirected to login', $anonList['status'] === 302, (string) $anonList['status']);
        $anonPost = $request('POST', $baseUrl . $basePath . '/structures', $anonJar, '', ['name' => 'Anon']);
        $check('anonymous POST is redirected to login', $anonPost['status'] === 302, (string) $anonPost['status']);
    }

    if ($adminUser === null) {
        echo PHP_EOL . 'V0.1 HTTP: ABORTED (no administrator credentials available)' . PHP_EOL;
        exit(1);
    }

    $adminKey = (string) $adminUser->getApiKey();
    $adminJar = $jarFor('admin');

    // ------------------------------------------- 2. admin read surfaces
    $section('2. Administrator read surfaces');

    $dashboard = $request('GET', $baseUrl . $basePath, $adminJar, $adminKey);
    $check('dashboard returns 200', $dashboard['status'] === 200, (string) $dashboard['status']);
    foreach (['治理数据统计', '最近的会议', '最近的决策', '待处理议题', '待办任务'] as $marker) {
        $check('dashboard shows "' . $marker . '"', str_contains($dashboard['body'], $marker));
    }

    $settings = $request('GET', $baseUrl . $basePath . '/settings', $adminJar, $adminKey);
    $check('settings returns 200', $settings['status'] === 200, (string) $settings['status']);
    $check('settings shows governance table status', str_contains($settings['body'], 'gov_structure') && str_contains($settings['body'], '你的治理权限'));

    $slugs = array_keys(GovRepository::SLUG_TO_ENTITY);
    foreach ($slugs as $slug) {
        $res = $request('GET', $baseUrl . $basePath . '/' . $slug, $adminJar, $adminKey);
        $check('list page /' . $slug . ' returns 200', $res['status'] === 200, (string) $res['status']);
    }

    // ------------------------------------------- 3. CSRF enforcement
    $section('3. CSRF enforcement');

    $noToken = $request('POST', $baseUrl . $basePath . '/structures', $adminJar, $adminKey, ['name' => 'No CSRF']);
    $check('POST without CSRF token is rejected with 400', $noToken['status'] === 400, (string) $noToken['status']);

    $badToken = $request('POST', $baseUrl . $basePath . '/structures', $adminJar, $adminKey, ['csrf_token' => 'deadbeef', 'name' => 'Bad CSRF']);
    $check('POST with an invalid CSRF token is rejected with 400', $badToken['status'] === 400, (string) $badToken['status']);

    // ------------------------------------------- 4. forms + create flow
    $section('4. Governance loops over HTTP');

    $formUrls = [];
    foreach ($slugs as $slug) {
        $res = $request('GET', $baseUrl . $basePath . '/' . $slug . '/new', $adminJar, $adminKey);
        $token = $csrfFrom($res['body']);
        $formUrls[$slug] = $token;
        $check(
            'create form /' . $slug . '/new renders with a CSRF token',
            $res['status'] === 200 && $token !== null,
            $res['status'] . ' token=' . ($token === null ? 'none' : 'yes')
        );
    }

    // Structure
    $structureToken = $formUrls['structures'];
    $createStructure = $request('POST', $baseUrl . $basePath . '/structures', $adminJar, $adminKey, [
        'csrf_token' => $structureToken,
        'name' => 'HTTP Test Structure',
        'code' => 'HTTP1',
        'description' => 'created over HTTP',
        'status' => 'active',
        'sort_order' => '7',
    ]);
    $check(
        'structure created over HTTP (302 to detail)',
        $createStructure['status'] === 302 && preg_match('#/structures/(\d+)$#', (string) $createStructure['location'], $m) === 1,
        $createStructure['status'] . ' -> ' . (string) $createStructure['location']
    );
    $structureId = isset($m[1]) ? (int) $m[1] : 0;
    if ($structureId > 0) {
        $created['structure'][] = $structureId;
    }

    $structureDetail = $request('GET', $baseUrl . $basePath . '/structures/' . $structureId, $adminJar, $adminKey);
    $check('structure detail returns 200 with its name', $structureDetail['status'] === 200 && str_contains($structureDetail['body'], 'HTTP Test Structure'));
    $check('structure detail shows the related-collection sections', str_contains($structureDetail['body'], '治理主体') && str_contains($structureDetail['body'], '下级结构'));

    // Body, using the parent reference prefill link (/bodies/new?structure_id=N)
    $prefilled = $request('GET', $baseUrl . $basePath . '/bodies/new?structure_id=' . $structureId, $adminJar, $adminKey);
    $check(
        'parent prefill marks the structure option as selected',
        $prefilled['status'] === 200 && preg_match('/value="' . $structureId . '"\s+selected/', $prefilled['body']) === 1,
        (string) $prefilled['status']
    );

    $bodyToken = $csrfFrom($prefilled['body']);
    $createBody = $request('POST', $baseUrl . $basePath . '/bodies', $adminJar, $adminKey, [
        'csrf_token' => $bodyToken ?? '',
        'structure_id' => (string) $structureId,
        'name' => 'HTTP Test Body',
        'body_type' => 'committee',
        'status' => 'active',
    ]);
    $check(
        'body created over HTTP with the prefilled parent',
        $createBody['status'] === 302 && preg_match('#/bodies/(\d+)$#', (string) $createBody['location'], $mb) === 1,
        $createBody['status'] . ' -> ' . (string) $createBody['location']
    );
    if (isset($mb[1]) && (int) $mb[1] > 0) {
        $created['body'][] = (int) $mb[1];
    }

    // The parent now shows the child in its related collection.
    $structureDetail = $request('GET', $baseUrl . $basePath . '/structures/' . $structureId, $adminJar, $adminKey);
    $check('structure detail now lists the child body', str_contains($structureDetail['body'], 'HTTP Test Body'));

    // Invalid submission re-renders the form with per-field errors.
    $invalidToken = $csrfFrom($request('GET', $baseUrl . $basePath . '/bodies/new', $adminJar, $adminKey)['body']);
    $invalid = $request('POST', $baseUrl . $basePath . '/bodies', $adminJar, $adminKey, [
        'csrf_token' => $invalidToken ?? '',
        'structure_id' => '',
        'name' => '',
        'status' => 'active',
    ]);
    $check(
        'invalid submission re-renders the form with 400 and field errors',
        $invalid['status'] === 400
        && str_contains($invalid['body'], 'is required')
        && str_contains($invalid['body'], '请修正标红'),
        (string) $invalid['status']
    );

    // Person-backed entity: the appointment form offers ChurchCRM people.
    $appointmentForm = $request('GET', $baseUrl . $basePath . '/appointments/new', $adminJar, $adminKey);
    $check(
        'appointment form exposes ChurchCRM people as candidates',
        $appointmentForm['status'] === 200 && str_contains($appointmentForm['body'], 'gov-person-candidates')
        && str_contains($appointmentForm['body'], '<option value="1">'),
        (string) $appointmentForm['status']
    );

    // Invalid ChurchCRM person is refused.
    $appointmentToken = $csrfFrom($appointmentForm['body']);
    $badPerson = $request('POST', $baseUrl . $basePath . '/appointments', $adminJar, $adminKey, [
        'csrf_token' => $appointmentToken ?? '',
        'role_id' => '99999999',
        'person_id' => '99999999',
        'status' => 'active',
    ]);
    $check(
        'appointment with unknown role/person is refused',
        $badPerson['status'] === 400 && str_contains($badPerson['body'], 'does not exist'),
        (string) $badPerson['status']
    );

    // Missing record
    $missing = $request('GET', $baseUrl . $basePath . '/structures/99999999', $adminJar, $adminKey);
    $check(
        'unknown record renders an explicit "does not exist" page',
        $missing['status'] === 200 && str_contains($missing['body'], '不存在'),
        (string) $missing['status']
    );

    // Unknown entity slug falls through to the plugin 404 handler.
    $unknownSlug = $request('GET', $baseUrl . $basePath . '/not-an-entity', $adminJar, $adminKey);
    $check('unknown entity slug is not served by a governance route', $unknownSlug['status'] !== 200, (string) $unknownSlug['status']);

    // ------------------------------------------- 5. authorization (R07)
    $section('5. Governance authorization (R07)');

    if ($readOnlyUser === null) {
        $skip('authorization checks', 'no non-administrator with an API key exists in this database');
    } else {
        $roKey = (string) $readOnlyUser->getApiKey();
        $roJar = $jarFor('readonly');

        $roList = $request('GET', $baseUrl . $basePath . '/structures', $roJar, $roKey);
        $check('read-only user can read governance lists', $roList['status'] === 200, (string) $roList['status']);

        $roDashboard = $request('GET', $baseUrl . $basePath, $roJar, $roKey);
        $check(
            'read-only user sees the read-only notice on the dashboard',
            $roDashboard['status'] === 200 && str_contains($roDashboard['body'], '只读'),
            (string) $roDashboard['status']
        );

        $roNewForm = $request('GET', $baseUrl . $basePath . '/structures/new', $roJar, $roKey);
        $check(
            'read-only user is refused the create form',
            $roNewForm['status'] === 302 && str_contains((string) $roNewForm['location'], 'access-denied'),
            $roNewForm['status'] . ' -> ' . (string) $roNewForm['location']
        );

        // No CSRF token is supplied: a 302 (not a 400) proves the
        // authorization middleware runs before CSRF processing.
        $roPost = $request('POST', $baseUrl . $basePath . '/structures', $roJar, $roKey, ['name' => 'Should not exist']);
        $check(
            'read-only user POST is refused before CSRF by the authorization layer',
            $roPost['status'] === 302 && str_contains((string) $roPost['location'], 'access-denied'),
            $roPost['status'] . ' -> ' . (string) $roPost['location']
        );

        $roJson = $request('POST', $baseUrl . $basePath . '/structures', $roJar, $roKey, ['name' => 'Nope'], false, 'application/json');
        $check(
            'read-only API client receives 403 JSON',
            $roJson['status'] === 403 && str_contains($roJson['body'], '"code":403'),
            $roJson['status'] . ' ' . substr($roJson['body'], 0, 160)
        );

        $roEdit = $request('GET', $baseUrl . $basePath . '/structures/' . $structureId . '/edit', $roJar, $roKey);
        $check(
            'read-only user is refused the edit form',
            $roEdit['status'] === 302 && str_contains((string) $roEdit['location'], 'access-denied'),
            $roEdit['status'] . ' -> ' . (string) $roEdit['location']
        );
    }

    if ($editorUser === null) {
        $skip('editor authorization checks', 'no non-administrator with record-edit rights exists');
    } else {
        $editorJar = $jarFor('editor');
        $editorKey = (string) $editorUser->getApiKey();

        $editorList = $request('GET', $baseUrl . $basePath . '/structures', $editorJar, $editorKey);
        $check('record-editor can read governance lists', $editorList['status'] === 200, (string) $editorList['status']);

        $editorPost = $request('POST', $baseUrl . $basePath . '/structures', $editorJar, $editorKey, ['name' => 'Should not exist']);
        $check(
            'record-editor without admin rights is refused governance writes',
            $editorPost['status'] === 302 && str_contains((string) $editorPost['location'], 'access-denied'),
            $editorPost['status'] . ' -> ' . (string) $editorPost['location']
        );
    }

    // ------------------------------------------- 6. edit over HTTP
    $section('6. Edit over HTTP');

    $editForm = $request('GET', $baseUrl . $basePath . '/structures/' . $structureId . '/edit', $adminJar, $adminKey);
    $editToken = $csrfFrom($editForm['body']);
    $check('edit form renders with the stored values', $editForm['status'] === 200 && str_contains($editForm['body'], 'HTTP1'), (string) $editForm['status']);

    $saved = $request('POST', $baseUrl . $basePath . '/structures/' . $structureId . '/edit', $adminJar, $adminKey, [
        'csrf_token' => $editToken ?? '',
        'name' => 'HTTP Test Structure (edited)',
        'code' => 'HTTP2',
        'description' => 'edited over HTTP',
        'status' => 'inactive',
        'sort_order' => '8',
    ]);
    $check(
        'edit over HTTP redirects to the record',
        $saved['status'] === 302 && str_contains((string) $saved['location'], '/structures/' . $structureId),
        $saved['status'] . ' -> ' . (string) $saved['location']
    );

    $edited = $request('GET', $baseUrl . $basePath . '/structures/' . $structureId, $adminJar, $adminKey);
    $check('edited values are visible on the detail page', str_contains($edited['body'], 'HTTP Test Structure (edited)') && str_contains($edited['body'], 'HTTP2'));

    $readOnlyView = $request('GET', $baseUrl . $basePath . '/structures/' . $structureId, $jarFor('ro-view'), $readOnlyUser?->getApiKey() ?? '');
    $check('detail page omits write actions for a read-only user', !str_contains($readOnlyView['body'], '/edit">编辑'));
} catch (\Throwable $e) {
    $check('HTTP suite completed without unexpected error', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    // ------------------------------------------------------------- cleanup
    $section('Cleanup');
    foreach ($created as $entity => $entityIds) {
        foreach ($entityIds as $id) {
            if ($id > 0) {
                try {
                    $deleted = $repo->delete($entity, (int) $id);
                    echo ($deleted ? '[PASS] ' : '[INFO] ') . 'removed ' . $entity . ' #' . $id . PHP_EOL;
                } catch (\Throwable $e) {
                    echo '[FAIL] cleanup ' . $entity . ' #' . $id . ': ' . $e->getMessage() . PHP_EOL;
                    $failures++;
                }
            }
        }
    }

    foreach (glob($tmpDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmpDir);

    $leftover = array_sum(array_map('count', $created));
    $check(
        'governance tables are back to their baseline after cleanup',
        $repo->getDashboardCounts() === $baseline,
        'leftover rows created by this run: ' . $leftover . '; now=' . json_encode($repo->getDashboardCounts())
    );
}

echo PHP_EOL . ($failures === 0
    ? 'V0.1 HTTP: ALL TESTS PASSED' . ($skips > 0 ? " ({$skips} skipped)" : '')
    : "V0.1 HTTP: {$failures} FAILURES") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
