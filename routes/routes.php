<?php

/**
 * MOS-GOV plugin routes.
 *
 * Loaded via PluginManager::registerPluginRoutes($app), which `require`s this
 * file inside its own scope -- the Slim App is available as $app, same as the
 * core plugins (see plugins/core/custom-links/routes/routes.php).
 *
 * IMPORTANT: the plugins entry point (src/plugins/index.php) runs a dedicated
 * Slim app with basePath '/plugins', so route paths must be relative to that
 * mount point ('/mos-gov', NOT '/plugins/mos-gov').
 *
 * Architecture rules honoured here:
 *  - views and route closures never embed SQL; all data access goes through
 *    ChurchCRM\Plugins\MosGov\Data\GovRepository;
 *  - ChurchCRM person references are resolved read-only through
 *    ChurchCRM\Plugins\MosGov\Integration\PersonLookup (R08);
 *  - every write route is guarded by GovWriteRoleAuthMiddleware, the route
 *    binding of the R07 governance authorization layer;
 *  - all POST handlers require a valid CSRF token (core CSRFUtils);
 *  - login protection comes from the global AuthMiddleware on the plugins
 *    entry point, so no anonymous request reaches these handlers.
 *
 * Fix history (V0.1 integration testing):
 *  - The original revision returned `function (App $app) {}` instead of using
 *    the in-scope $app, so registerPluginRoutes() silently registered nothing.
 *  - Route paths were absolute ('/plugins/mos-gov'), which would have produced
 *    '/plugins/plugins/mos-gov' URLs behind the '/plugins' basePath.
 *  - V0.1 closure: all ten governance entities share one registry-driven set
 *    of routes; per-page links are built from the real root path so the plugin
 *    also works in a subdirectory installation.
 */

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovDataMaintenance;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Data\HealthboardService;
use ChurchCRM\Plugins\MosGov\Governance\GovSearchService;
use ChurchCRM\Plugins\MosGov\Governance\RemediationService;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Governance\MyGovernanceService;
use ChurchCRM\Plugins\MosGov\Integration\MosGovFinanceReport;
use ChurchCRM\Plugins\MosGov\Integration\PersonLookup;
use ChurchCRM\Plugins\MosGov\Security\AuditService;
use ChurchCRM\Plugins\MosGov\Security\DelegationService;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\GovMembershipMiddleware;
use ChurchCRM\Plugins\MosGov\Security\GovSecureModeMiddleware;
use ChurchCRM\Plugins\MosGov\Security\GovWriteRoleAuthMiddleware;
use ChurchCRM\Plugins\MosGov\Security\GovernancePolicy;
use ChurchCRM\Plugins\MosGov\Security\LocalSecureMode;
use ChurchCRM\Plugins\MosGov\Security\PermissionResolver;
use ChurchCRM\Slim\SlimUtils;
use ChurchCRM\Utils\CSRFUtils;
use ChurchCRM\dto\SystemURLs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$mosGovBase = SystemURLs::getRootPath() . '/plugins/mos-gov';

// GOV-DEV-09-E 产品决定：治理系统不向普通成员开放。
// 应用级中间件，先于所有路由执行 —— 非管理员且无治理身份记录的登录用户
// 在进入任何 /mos-gov/* 页面前即收到 403（未登录仍走全局 AuthMiddleware）。
$app->add(new GovMembershipMiddleware());

// Presentation-layer Chinese mapping (views/_i18n.php). Used only to display
// frozen-layer English messages (e.g. authorization deny reasons) in Chinese;
// internal values, permission keys and stored data are never translated.
require_once __DIR__ . '/../views/_i18n.php';

/** URL slug => entity key, shared with the views through the registry. */
$mosGovEntityBySlug = GovRepository::SLUG_TO_ENTITY;
$mosGovSlugByEntity = array_flip($mosGovEntityBySlug);
$mosGovSlugPattern = implode('|', array_keys($mosGovEntityBySlug));

/** Render a plugin view with variables in scope; returns the HTML string. */
$mosGovRender = static function (string $view, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../views/' . $view;

    return (string) ob_get_clean();
};

/** Lazily build the repository (avoids touching the DB during route registration). */
$mosGovRepo = static function (): GovRepository {
    return new GovRepository();
};

/** Shared HTML-escaping closure handed to every view. */
$mosGovEsc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Render a standalone error page (CSRF rejection, missing record, data failure). */
$mosGovErrorPage = static function (Response $response, string $message, int $status = 400) use ($mosGovRender): Response {
    $response->getBody()->write($mosGovRender('error_page.php', ['message' => $message]));

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

/**
 * V0.2 — render a governance DENY page. The page explains the boundary
 * ("此信息受权限保护" style) without leaking the protected content.
 *
 * FINAL REVIEW §1: the view needs $esc; without it every deny path fataled
 * ("Value of type null is not callable") and surfaced as a generic 500.
 */
$mosGovDenyPage = static function (Response $response, \ChurchCRM\Plugins\MosGov\Security\AuthorizationDecision $decision, int $status = 403) use ($mosGovRender, $mosGovEsc): Response {
    $response->getBody()->write($mosGovRender('denied_page.php', [
        'decision' => $decision,
        'esc' => $mosGovEsc,
    ]));

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

/**
 * V0.2 guard for the new governance surfaces: a permission from the V0.2
 * registry is required. V0.1 admin compatibility: ChurchCRM administrators
 * keep write access to the legacy CRUD (identity bootstrap), but export
 * never gets an admin bypass — export requires an explicit permission
 * (§24: view and export are separated, only explicit grants may export).
 */
$mosGovGuard = static function (string $action, string $resource, bool $adminFallback = true): ?\ChurchCRM\Plugins\MosGov\Security\AuthorizationDecision {
    $user = GovAuthorization::currentUser();
    $decision = GovAuthorization::can($user, $action, $resource);
    if ($decision->allowed) {
        return null;
    }
    if ($adminFallback && $action !== 'export' && $user !== null && $user->isAdmin()) {
        return null;
    }

    return $decision;
};

/** Render a full plugin page (shared header/footer) with an error notice. */
$mosGovPage = static function (Response $response, string $view, array $vars, int $status = 200) use ($mosGovRender): Response {
    $response->getBody()->write($mosGovRender($view, $vars));

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

// -------------------------------------------------------------- label helpers

/** Request-scoped cache of parent labels, keyed "entity#id". */
$mosGovLabelCache = [];

/**
 * Human label for a governance record, used for reference option lists,
 * detail headings and relationship endpoints.
 */
$mosGovDescribe = static function (GovRepository $repo, string $entity, array $row) use (&$mosGovLabelCache): string {
    $id = (int) ($row['id'] ?? 0);

    // Appointments have no name column: describe them by role + person.
    if ($entity === 'appointment') {
        $roleName = '角色 #' . ($row['role_id'] ?? '?');
        $roleId = (int) ($row['role_id'] ?? 0);
        if ($roleId > 0) {
            $key = 'role#' . $roleId;
            if (!isset($mosGovLabelCache[$key])) {
                $role = $repo->find('role', $roleId);
                $mosGovLabelCache[$key] = $role['name'] ?? ('角色 #' . $roleId);
            }
            $roleName = $mosGovLabelCache[$key];
        }
        $person = PersonLookup::label((int) ($row['person_id'] ?? 0));

        return trim($roleName . ' — ' . $person) . ' (#' . $id . ')';
    }

    $label = null;
    foreach (['name', 'title'] as $primary) {
        if (isset($row[$primary]) && $row[$primary] !== null && $row[$primary] !== '') {
            $label = (string) $row[$primary];
            break;
        }
    }
    if ($label === null) {
        $label = '#' . $id;
    }
    foreach (['code', 'role_code'] as $secondary) {
        if (!empty($row[$secondary])) {
            $label .= ' [' . $row[$secondary] . ']';
            break;
        }
    }

    return $label . ' (#' . $id . ')';
};

/**
 * Resolve reference/person display labels for a set of rows.
 *
 * @return array<int, array{ref: array<string,string>, person: array<string,string>}>
 *         row id => ['ref' => field => label, 'person' => field => label]
 */
$mosGovDecorateRows = static function (GovRepository $repo, array $cfg, array $rows) use ($mosGovDescribe): array {
    $out = [];

    foreach ($rows as $row) {
        $ref = [];
        $person = [];
        foreach ($cfg['fields'] as $field => $spec) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($spec['type'] === 'ref') {
                $parent = $repo->find($spec['ref'], (int) $value);
                $ref[$field] = $parent === null
                    ? '#' . (int) $value . '（已缺失）'
                    : $mosGovDescribe($repo, $spec['ref'], $parent);
            } elseif ($spec['type'] === 'person') {
                $person[$field] = PersonLookup::label((int) $value);
            }
        }
        $out[(int) $row['id']] = ['ref' => $ref, 'person' => $person];
    }

    return $out;
};

/**
 * Build field => (id => display-label) option lists for every ref field of an
 * entity config.
 *
 * @return array<string, array<int, string>>
 */
$mosGovCollectRefOptions = static function (GovRepository $repo, array $cfg) use ($mosGovDescribe): array {
    $options = [];
    foreach ($cfg['fields'] as $fieldName => $spec) {
        if ($spec['type'] !== 'ref') {
            continue;
        }
        $opts = [];
        foreach ($repo->list($spec['ref'], 1000) as $row) {
            $opts[(int) $row['id']] = $mosGovDescribe($repo, $spec['ref'], $row);
        }
        $options[$fieldName] = $opts;
    }

    return $options;
};

/**
 * Child collections of a record, as configured in the entity's 'related'
 * section. Returns ready-to-render structures for the detail view.
 *
 * @return array<int, array{label:string, slug:string, fields:array<int,string>, rows:array<int,array>, decorations:array}>
 */
$mosGovCollectRelated = static function (GovRepository $repo, array $cfg, int $id) use ($mosGovDecorateRows, $mosGovSlugByEntity): array {
    $related = [];

    foreach ($cfg['related'] ?? [] as $rel) {
        $childCfg = $repo->getEntity($rel['entity']);
        $rows = $repo->listWhere($rel['entity'], [$rel['field'] => $id], 50, ['id' => 'DESC']);

        $related[] = [
            'label' => $rel['label'] ?? $childCfg['labelPlural'],
            'slug' => $mosGovSlugByEntity[$rel['entity']],
            'entity' => $rel['entity'],
            'field' => $rel['field'],
            'fields' => array_slice($childCfg['listFields'], 0, 4),
            'rows' => $rows,
            'decorations' => $mosGovDecorateRows($repo, $childCfg, $rows),
        ];
    }

    return $related;
};

/**
 * Prefill reference fields from query parameters, so the "Add …" links on a
 * detail page open a child form that is already linked to its parent record
 * (e.g. /decisions/new?issue_id=7).
 *
 * Only field names declared in the registry are considered, only positive
 * integers are accepted, and the value is re-validated on submit.
 *
 * @return array<string, int>
 */
$mosGovPrefill = static function (array $cfg, array $query): array {
    $data = [];
    foreach ($cfg['fields'] as $field => $spec) {
        if ($spec['type'] !== 'ref') {
            continue;
        }
        $raw = $query[$field] ?? null;
        if (is_string($raw) && preg_match('/^\d+$/', $raw) && (int) $raw > 0) {
            $data[$field] = (int) $raw;
        }
    }

    return $data;
};

/** Form/validation context shared by the create and edit handlers. */
$mosGovFormContext = static function (GovRepository $repo, array $cfg, array $data = []) use ($mosGovCollectRefOptions): array {
    $personLabels = [];
    foreach ($cfg['fields'] as $field => $spec) {
        if ($spec['type'] === 'person' && !empty($data[$field])) {
            $personLabels[$field] = PersonLookup::label((int) $data[$field]);
        }
    }

    return [
        'refOptions' => $mosGovCollectRefOptions($repo, $cfg),
        'personCandidates' => PersonLookup::candidates(),
        'personLabels' => $personLabels,
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
    ];
};

// --------------------------------------------------------------- dashboard
// 处理器提取为变量：治理首页需要两个可达地址（见本段末尾说明）。
$mosGovDashboardHandler = function (Request $request, Response $response) use ($mosGovRender, $mosGovRepo, $mosGovEsc, $mosGovDecorateRows): Response {
    $repo = $mosGovRepo();
    $stats = null;
    $statsError = null;
    $recentMeetings = [];
    $recentDecisions = [];
    $meetingDecorations = [];
    $decisionDecorations = [];
    $recentError = null;

    try {
        $stats = $repo->getDashboardCounts();
    } catch (GovDataException $e) {
        $statsError = $e->getMessage();
    }

    if ($statsError === null) {
        try {
            $recentMeetings = $repo->recent('meeting', 5);
            $recentDecisions = $repo->recent('decision', 5);
            // V0.2: scoped users only see records inside their scope.
            // FINAL REVIEW §1: the gate is "holds a governance identity row"
            // (any status) — never "has an ACTIVE identity", which would let
            // deactivation widen read access.
            if (GovAuthorization::subjectToGovernancePolicy()) {
                $user = GovAuthorization::currentUser();
                $recentMeetings = array_values(array_filter(
                    $recentMeetings,
                    static fn (array $row): bool => GovAuthorization::can($user, 'view', 'meeting', $row)->allowed
                ));
                $recentDecisions = array_values(array_filter(
                    $recentDecisions,
                    static fn (array $row): bool => GovAuthorization::can($user, 'view', 'decision', $row)->allowed
                ));
            }
            $meetingDecorations = $mosGovDecorateRows($repo, $repo->getEntity('meeting'), $recentMeetings);
            $decisionDecorations = $mosGovDecorateRows($repo, $repo->getEntity('decision'), $recentDecisions);
        } catch (GovDataException $e) {
            $recentError = $e->getMessage();
        }
    }

    $response->getBody()->write($mosGovRender('dashboard.php', [
        'stats' => $stats,
        'statsError' => $statsError,
        'recentMeetings' => $recentMeetings,
        'recentDecisions' => $recentDecisions,
        'meetingDecorations' => $meetingDecorations,
        'decisionDecorations' => $decisionDecorations,
        'recentError' => $recentError,
        'canWrite' => GovAuthorization::canWrite(),
        'esc' => $mosGovEsc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
};

// 治理首页的两个可达地址：
//   /mos-gov            插件内页面互相引用的规范地址（保持既有行为，勿改）；
//   /mos-gov/dashboard  侧栏「治理首页」使用 —— 裸插件根路径 /plugins/mos-gov
//                       在内置服务器下会被当作目录请求，实测 302 落到根应用。
$app->get('/mos-gov', $mosGovDashboardHandler)->add(GovSecureModeMiddleware::class);
$app->get('/mos-gov/dashboard', $mosGovDashboardHandler)->add(GovSecureModeMiddleware::class);

// --------------------------------------------------------------- healthboard
$app->get('/mos-gov/healthboard', function (Request $request, Response $response) use ($mosGovRender, $mosGovEsc): Response {
    $snapshot = [];
    $hbError = null;
    $hbTruncated = false;
    $hbService = null;
    try {
        $hbService = new HealthboardService();
        $snapshot = $hbService->snapshot();
        $hbTruncated = $hbService->truncated();
    } catch (\Throwable $e) {
        $hbError = 'Governance healthboard data is currently unavailable.';
    }

    // 行级可见性过滤：与 dashboard recent 相同的机制 —— 范围用户（scope）
    // 逐行经授权引擎 view 判定后计数；非范围用户直接可见全部。
    $user = GovAuthorization::currentUser();
    $scoped = GovAuthorization::subjectToGovernancePolicy();
    $filterRows = static function (array $rows, string $entity) use ($scoped, $user): array {
        if (!$scoped) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => GovAuthorization::can($user, 'view', $entity, $row)->allowed
        ));
    };

    if ($hbError === null) {
        $snapshot['overdue']['rows'] = $filterRows($snapshot['overdue']['rows'] ?? [], 'task');
        foreach (['expiringSoon', 'staleActive', 'expired', 'revoked'] as $k) {
            $snapshot['delegation'][$k] = $filterRows($snapshot['delegation'][$k] ?? [], 'delegation');
        }
        foreach (['proposed', 'approved', 'reviewOverdue'] as $k) {
            $snapshot['decision'][$k] = $filterRows($snapshot['decision'][$k] ?? [], 'decision');
        }
        foreach (['open', 'inProgress', 'missingEvidence', 'awaitingClosure', 'completed'] as $k) {
            $snapshot['oversight'][$k] = $filterRows($snapshot['oversight'][$k] ?? [], 'oversight');
        }
    }

    $response->getBody()->write($mosGovRender('healthboard.php', [
        'snapshot' => $snapshot,
        'hbError' => $hbError,
        'canSeeAudit' => GovAuthorization::allows($user, 'manage', 'permission') || $user?->isAdmin(),
        'hbTruncated' => $hbTruncated,
        'esc' => $mosGovEsc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
})->add(GovSecureModeMiddleware::class);

// --------------------------------------------- remediation (V1.0, GOV-DEV-07)
// 受控闭环写入口：提交证据 / 复核。规则（责任人、复核人、禁自批、
// 证据前置、终态拒绝）全部在 RemediationService；这里只做
// CSRF + 写角色中间件 + 审计结果透出（audit-failed => 500）。
$mosGovRemediationActor = static function (): array {
    $user = GovAuthorization::currentUser();

    return [
        'name' => $user?->getUserName() ?? 'anonymous',
        'personId' => $user?->getPersonId(),
        'hasManage' => GovAuthorization::allows($user, 'manage', 'permission') || $user?->isAdmin(),
    ];
};

$mosGovRemediationHandler = static function (string $op) use ($mosGovRepo, $mosGovErrorPage, $mosGovBase, $mosGovRemediationActor): \Closure {
    return static function (Request $request, Response $response, array $args) use ($mosGovRepo, $mosGovErrorPage, $mosGovBase, $mosGovRemediationActor, $op): Response {
        $data = (array) $request->getParsedBody();
        if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
            return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失：请返回、刷新表单后重试。');
        }

        $actor = $mosGovRemediationActor();
        $svc = new RemediationService($mosGovRepo());
        $result = $op === 'evidence'
            ? $svc->submitEvidence(
                (int) $args['id'],
                $actor['name'],
                $actor['personId'],
                $actor['hasManage'],
                trim((string) ($data['evidence_ref'] ?? '')),
                trim((string) ($data['evidence_notes'] ?? ''))
            )
            : $svc->review(
                (int) $args['id'],
                $actor['name'],
                $actor['personId'],
                $actor['hasManage'],
                (string) ($data['outcome'] ?? ''),
                trim((string) ($data['outcome_notes'] ?? ''))
            );

        if (!$result['ok']) {
            $status = match ($result['code']) {
                'forbidden', 'terminal', 'self-approval' => 403,
                'audit-failed' => 500,
                default => 400,
            };

            return $mosGovErrorPage($response, $result['message'], $status);
        }

        return SlimUtils::renderRedirect($response, $mosGovBase . '/oversight/' . (int) $args['id']);
    };
};

$app->post('/mos-gov/oversight/{id:[0-9]+}/evidence', $mosGovRemediationHandler('evidence'))->add(GovWriteRoleAuthMiddleware::class);
$app->post('/mos-gov/oversight/{id:[0-9]+}/review', $mosGovRemediationHandler('review'))->add(GovWriteRoleAuthMiddleware::class);

// --------------------------------------------------------------- settings
$app->get('/mos-gov/settings', function (Request $request, Response $response) use ($mosGovRender, $mosGovRepo, $mosGovEsc): Response {
    $stats = null;
    $statsError = null;

    try {
        $stats = $mosGovRepo()->getDashboardCounts();
    } catch (GovDataException $e) {
        $statsError = $e->getMessage();
    }

    $response->getBody()->write($mosGovRender('settings.php', [
        'stats' => $stats,
        'statsError' => $statsError,
        'canWrite' => GovAuthorization::canWrite(),
        'capabilities' => GovAuthorization::capabilitySummary(),
        'secureMode' => LocalSecureMode::mode(),
        'secureModeDescription' => LocalSecureMode::describe(),
        'esc' => $mosGovEsc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
})->add(GovSecureModeMiddleware::class);

// ----------------------------------------------------------- entity list
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovDecorateRows, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);

    $rows = [];
    $error = null;
    try {
        $rows = $repo->list($entity);
        // V0.2 §20: scoped listing for users who hold a governance identity —
        // rows outside the identity's scope are removed at data level.
        // FINAL REVIEW §1: an INACTIVE identity must not widen this to the
        // full list; the gate is identity-row presence, not active status.
        if (GovAuthorization::subjectToGovernancePolicy()) {
            $user = GovAuthorization::currentUser();
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => GovAuthorization::can($user, 'view', $entity, $row)->allowed
            ));
        }
        $decorations = $mosGovDecorateRows($repo, $cfg, $rows);
    } catch (GovDataException $e) {
        $error = $e->getMessage();
        $decorations = [];
    }

    return $mosGovPage($response, 'entity_list.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'rows' => $rows,
        'decorations' => $decorations,
        'error' => $error,
        'canWrite' => GovAuthorization::canWrite(),
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// ------------------------------------------------ entity create: form
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}/new', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovPrefill, $mosGovErrorPage, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);

    try {
        $context = $mosGovFormContext($repo, $cfg);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    return $mosGovPage($response, 'entity_form.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'isEdit' => false,
        'actionUrl' => $mosGovBase . '/' . $args['entity'],
        'data' => $mosGovPrefill($cfg, $request->getQueryParams()),
        'errors' => [],
        'refOptions' => $context['refOptions'],
        'personCandidates' => $context['personCandidates'],
        'personLabels' => $context['personLabels'],
        'csrfField' => $context['csrfField'],
        'canWrite' => true,
        'esc' => $mosGovEsc,
    ]);
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// ------------------------------------------------ entity create: submit
$app->post('/mos-gov/{entity:' . $mosGovSlugPattern . '}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovErrorPage, $mosGovSlugByEntity, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $data = (array) $request->getParsedBody();

    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失：请返回、刷新表单后重试。');
    }
    unset($data['csrf_token']);

    // V1.0 整改闭环（DEV-07）：复核结论只能经 review() 写入，
    // 通用表单（创建）不得携带非 pending 的 outcome —— 防止未经复核标记通过。
    if ($entity === 'oversight' && isset($data['outcome']) && $data['outcome'] !== 'pending') {
        AuditService::auditCurrent('outcome-write-denied', 'oversight', null, 'denied', 'create form may not set a review outcome');

        return $mosGovErrorPage($response, '复核结论只能通过「复核」操作写入，不能随创建表单提交。', 403);
    }

    try {
        $context = $mosGovFormContext($repo, $cfg, $data);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    try {
        // V1.0 guards (delegation / oversight):
        //   1. delegation authority precheck (PoC-02: delegator must hold the
        //      permission; no loops; no unauthorised re-delegation)
        //   2. duplicate-open-record guard (double submit)
        //   3. critical-write audit with surfaced loss (audit() false => 500)
        if ($entity === 'delegation') {
            $precheckError = DelegationService::precheckCreate(
                null,
                GovernanceContext::current(),
                $repo->normalize($entity, $data)
            );
            if ($precheckError !== null) {
                AuditService::auditCurrent('delegation-create-denied', 'delegation', null, 'denied', $precheckError);

                return $mosGovErrorPage($response, '授权未创建：' . $precheckError, 403);
            }
        }
        if (in_array($entity, ['delegation', 'oversight'], true)
            && $repo->hasOpenDuplicate($entity, $repo->normalize($entity, $data))) {
            AuditService::auditCurrent('duplicate-denied', $entity, null, 'denied', 'identical open record already exists');

            return $mosGovErrorPage($response, '已存在同类的进行中记录，请勿重复提交。', 409);
        }

        $id = $repo->insert($entity, $data);

        if (in_array($entity, ['delegation', 'oversight'], true)
            && !AuditService::auditCurrent('create', $entity, $id, 'success', '')) {
            return $mosGovErrorPage($response, '记录已保存，但审计记录写入失败：请立即联系管理员核查 gov_audit_log。', 500);
        }

        return SlimUtils::renderRedirect($response, $mosGovBase . '/' . $mosGovSlugByEntity[$entity] . '/' . $id);
    } catch (GovDataException $e) {
        return $mosGovPage($response, 'entity_form.php', [
            'cfg' => $cfg,
            'slug' => $args['entity'],
            'isEdit' => false,
            'actionUrl' => $mosGovBase . '/' . $args['entity'],
            'data' => $data,
            'errors' => $e->getErrors(),
            'refOptions' => $context['refOptions'],
            'personCandidates' => $context['personCandidates'],
            'personLabels' => $context['personLabels'],
            'csrfField' => $context['csrfField'],
            'canWrite' => true,
            'error' => $e->getMessage(),
            'esc' => $mosGovEsc,
        ], 400);
    }
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// ------------------------------------------------ entity detail
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovDecorateRows, $mosGovCollectRelated, $mosGovDenyPage, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $id = (int) $args['id'];

    $row = null;
    $decorations = [];
    $related = [];
    $error = null;

    // V0.2 §21: scope containment + information levels on detail pages.
    // URL/ID guessing can no longer read out-of-scope records for users who
    // hold a governance identity; P5 fields are masked for everyone
    // without an explicit P5 grant.
    //
    // FINAL REVIEW §1: two corrections here.
    //  (a) the gate is identity-row presence, not an ACTIVE identity, so
    //      deactivating an identity can never turn into full disclosure;
    //  (b) the deny page is a captured closure variable — without it this
    //      path fataled into a generic 500 instead of the 403 page.
    // When the row does not exist the decision is evaluated at module level,
    // so a governed user cannot probe which ids exist.
    $governed = GovAuthorization::subjectToGovernancePolicy();
    $scopedUser = $governed ? GovAuthorization::context() : null;
    if ($governed) {
        $preRow = $repo->find($entity, $id);
        $decision = GovAuthorization::can(GovAuthorization::currentUser(), 'view', $entity, $preRow);
        if (!$decision->allowed) {
            return $mosGovDenyPage($response, $decision);
        }
    }

    try {
        $row = $repo->find($entity, $id);
        if ($row !== null) {
            if ($scopedUser !== null) {
                $row = GovernancePolicy::filterFields($scopedUser, $entity, [$row])[0];
            }
            $decorations = $mosGovDecorateRows($repo, $cfg, [$row]);
            $decorations = $decorations[$id] ?? [];
            $related = $mosGovCollectRelated($repo, $cfg, $id);
        }
    } catch (GovDataException $e) {
        $error = $e->getMessage();
    }

    return $mosGovPage($response, 'entity_view.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'row' => $row,
        'decorations' => $decorations,
        'related' => $related,
        'error' => $error,
        'canWrite' => GovAuthorization::canWrite(),
        // V1.0 整改闭环（DEV-07）：oversight 详情页挂「提交证据 / 复核」面板。
        'remediationActor' => static function () {
            $user = GovAuthorization::currentUser();

            return [
                'personId' => $user?->getPersonId(),
                'hasManage' => GovAuthorization::allows($user, 'manage', 'permission') || $user?->isAdmin(),
            ];
        },
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// ------------------------------------------------ entity edit: form
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovErrorPage, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $id = (int) $args['id'];

    try {
        $row = $repo->find($entity, $id);
        if ($row === null) {
            return $mosGovErrorPage($response, '该记录不存在。', 404);
        }
        $context = $mosGovFormContext($repo, $cfg, $row);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    return $mosGovPage($response, 'entity_form.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'isEdit' => true,
        'actionUrl' => $mosGovBase . '/' . $args['entity'] . '/' . $id . '/edit',
        'data' => $row,
        'errors' => [],
        'refOptions' => $context['refOptions'],
        'personCandidates' => $context['personCandidates'],
        'personLabels' => $context['personLabels'],
        'csrfField' => $context['csrfField'],
        'canWrite' => true,
        'esc' => $mosGovEsc,
    ]);
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// ------------------------------------------------ entity edit: submit
$app->post('/mos-gov/{entity:' . $mosGovSlugPattern . '}/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovErrorPage, $mosGovSlugByEntity, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $id = (int) $args['id'];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $data = (array) $request->getParsedBody();

    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失：请返回、刷新表单后重试。');
    }
    unset($data['csrf_token']);

    // V1.0 整改闭环（DEV-07）：编辑表单同样不得改写复核结论（见创建守卫）。
    if ($entity === 'oversight' && isset($data['outcome']) && $data['outcome'] !== 'pending') {
        AuditService::auditCurrent('outcome-write-denied', 'oversight', $id, 'denied', 'edit form may not set a review outcome');

        return $mosGovErrorPage($response, '复核结论只能通过「复核」操作写入，不能随编辑表单提交。', 403);
    }

    try {
        $context = $mosGovFormContext($repo, $cfg, $data);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    try {
        $oldRow = in_array($entity, ['delegation', 'oversight'], true) ? $repo->find($entity, $id) : null;
        $repo->update($entity, $id, $data);

        // V1.0: audit lifecycle-critical writes; surface audit loss.
        if ($oldRow !== null) {
            $action = 'update';
            $oldStatus = (string) ($oldRow['status'] ?? $oldRow['oversight_status'] ?? '');
            $newStatus = (string) ($data['status'] ?? $data['oversight_status'] ?? $oldStatus);
            if ($entity === 'delegation' && $oldStatus !== 'revoked' && $newStatus === 'revoked') {
                $action = 'revoke';
            } elseif ($oldStatus !== $newStatus) {
                $action = 'status-change';
            }
            if (!AuditService::auditCurrent($action, $entity, $id, 'success', $action === 'update' ? '' : ('status ' . $oldStatus . ' -> ' . $newStatus))) {
                return $mosGovErrorPage($response, '记录已保存，但审计记录写入失败：请立即联系管理员核查 gov_audit_log。', 500);
            }
        }

        return SlimUtils::renderRedirect($response, $mosGovBase . '/' . $mosGovSlugByEntity[$entity] . '/' . $id);
    } catch (GovDataException $e) {
        return $mosGovPage($response, 'entity_form.php', [
            'cfg' => $cfg,
            'slug' => $args['entity'],
            'isEdit' => true,
            'actionUrl' => $mosGovBase . '/' . $args['entity'] . '/' . $id . '/edit',
            'data' => $data,
            'errors' => $e->getErrors(),
            'refOptions' => $context['refOptions'],
            'personCandidates' => $context['personCandidates'],
            'personLabels' => $context['personLabels'],
            'csrfField' => $context['csrfField'],
            'canWrite' => true,
            'error' => $e->getMessage(),
            'esc' => $mosGovEsc,
        ], 400);
    }
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// ===========================================================================
// MOS-GOV V0.2 — governance identity, my governance center, scoped search,
// export control and identity administration. All routes below enforce the
// local/LAN secure mode and route permission questions through the unified
// authorization engine (GovAuthorization::can → GovernancePolicy).
// ===========================================================================

// ------------------------------------------------------------ my governance
$app->get('/mos-gov/my-governance', function (Request $request, Response $response) use ($mosGovPage, $mosGovEsc): Response {
    $ctx = GovAuthorization::context();

    $data = null;
    if ($ctx !== null) {
        $service = new MyGovernanceService();
        $data = $service->build($ctx);
    }

    return $mosGovPage($response, 'my_governance.php', [
        'ctx' => $ctx,
        'data' => $data,
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// --------------------------------------------------------- governance search
$app->get('/mos-gov/search', function (Request $request, Response $response) use ($mosGovPage, $mosGovErrorPage, $mosGovEsc): Response {
    $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
    $results = [];
    $error = null;

    try {
        $ctx = GovAuthorization::context();
        if ($ctx !== null && $query !== '') {
            $service = new GovSearchService();
            $results = $service->search($ctx, $query);
        }
    } catch (GovDataException $e) {
        $error = $e->getMessage();
    }

    return $mosGovPage($response, 'search.php', [
        'query' => $query,
        'results' => $results,
        'error' => $error,
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// ------------------------------------------------------------ export (CSV)
// §24: view and export are separate permissions. Default member = DENY,
// P5 = DENY; only explicit grants (governance.export / meeting.export) may
// export, and every export is audit-logged.
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}/export', function (Request $request, Response $response, array $args) use ($mosGovRepo, $mosGovEntityBySlug, $mosGovErrorPage, $mosGovMsg): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $user = GovAuthorization::currentUser();
    $ctx = GovAuthorization::context();

    // module-level export permission (view rights are NOT enough)
    $decision = GovAuthorization::can($user, 'export', $entity);
    if (!$decision->allowed) {
        AuditService::auditCurrent('export-denied', $entity, null, 'DENY', $decision->reason);

        return $mosGovErrorPage($response, '不允许导出：' . $mosGovMsg($decision->reason), 403);
    }

    $repo = $mosGovRepo();
    $rows = $repo->list($entity, 1000);

    // per-row scope containment + P5 masking
    $visible = [];
    foreach ($rows as $row) {
        $d = GovAuthorization::can($user, 'view', $entity, $row);
        if ($d->allowed) {
            $visible[] = $ctx !== null
                ? GovernancePolicy::filterFields($ctx, $entity, [$row])[0]
                : $row;
        }
    }

    AuditService::auditCurrent('export', $entity, null, 'ALLOW', count($visible) . ' rows');

    $cfg = $repo->getEntity($entity);
    $columns = array_merge(['id'], array_keys($cfg['fields']), ['created_at', 'updated_at']);

    $response->getBody()->write("\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    $out = fopen('php://temp', 'r+');
    fputcsv($out, $columns);
    foreach ($visible as $row) {
        $line = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            $line[] = $value === '__P5_PROTECTED__' ? '[protected]' : (string) $value;
        }
        fputcsv($out, $line);
    }
    rewind($out);
    $response->getBody()->write((string) stream_get_contents($out));
    fclose($out);

    return $response
        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
        ->withHeader('Content-Disposition', 'attachment; filename="mos-gov-' . $entity . '-' . date('Ymd-His') . '.csv"')
        ->withHeader('Cache-Control', 'no-store');
})->add(GovSecureModeMiddleware::class);

// ===========================================================================
// Identity administration (V0.2 §13). Guarded by the identity.view /
// identity.edit / role.manage / permission.manage registry permissions;
// ChurchCRM administrators keep bootstrap access for the legacy CRUD path
// (documented boundary: system administration ≠ church governance authority).
// ===========================================================================

$mosGovIdentityGuard = static function (string $permission) use ($mosGovDenyPage): ?Response {
    $user = GovAuthorization::currentUser();
    $decision = GovAuthorization::can($user, explode('.', $permission)[1], explode('.', $permission)[0]);
    if ($decision->allowed) {
        return null;
    }
    // bootstrap compatibility for ChurchCRM administrators on non-export actions
    if ($user !== null && $user->isAdmin()) {
        return null;
    }

    return $mosGovDenyPage(new \Slim\Psr7\Response(), $decision);
};

// identity list
$app->get('/mos-gov/identity', function (Request $request, Response $response) use ($mosGovPage, $mosGovRepo, $mosGovEsc, $mosGovIdentityGuard, $mosGovErrorPage): Response {
    if ($resp = $mosGovIdentityGuard('identity.view')) {
        return $resp;
    }

    $repo = $mosGovRepo();
    $user = GovAuthorization::currentUser();
    $rows = $repo->list('identity', 500);
    $personLabels = [];
    $visible = [];
    foreach ($rows as $row) {
        $d = GovAuthorization::can($user, 'view', 'identity', $row);
        if ($d->allowed) {
            $visible[] = $row;
            $personLabels[(int) $row['id']] = PersonLookup::label((int) $row['person_id']);
        }
    }

    return $mosGovPage($response, 'identity_list.php', [
        'rows' => $visible,
        'personLabels' => $personLabels,
        'canEdit' => GovAuthorization::allows($user, 'edit', 'identity') || ($user !== null && $user->isAdmin()),
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// identity create
$app->get('/mos-gov/identity/new', function (Request $request, Response $response) use ($mosGovPage, $mosGovRepo, $mosGovEsc, $mosGovIdentityGuard): Response {
    if ($resp = $mosGovIdentityGuard('identity.edit')) {
        return $resp;
    }

    return $mosGovPage($response, 'identity_form.php', [
        'isEdit' => false,
        'row' => [],
        'personCandidates' => PersonLookup::candidates(),
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

$app->post('/mos-gov/identity', function (Request $request, Response $response) use ($mosGovRepo, $mosGovPage, $mosGovErrorPage, $mosGovEsc): Response {
    if (!CSRFUtils::verifyRequest((array) $request->getParsedBody(), 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失：请返回、刷新表单后重试。');
    }
    $user = GovAuthorization::currentUser();
    $allowed = GovAuthorization::allows($user, 'edit', 'identity') || ($user !== null && $user->isAdmin());
    if (!$allowed) {
        return $mosGovErrorPage($response, '你没有管理治理身份的权限。', 403);
    }

    $repo = $mosGovRepo();
    $data = (array) $request->getParsedBody();
    unset($data['csrf_token']);

    $service = new IdentityService($repo);
    try {
        $identityId = $service->provisionIdentity((int) ($data['person_id'] ?? 0), [
            'identity_status' => $data['identity_status'] ?? 'active',
            'member_since' => $data['member_since'] ?? null,
            'display_name_override' => $data['display_name_override'] ?? null,
        ]);

        return SlimUtils::renderRedirect($response, SystemURLs::getRootPath() . '/plugins/mos-gov/identity/' . $identityId);
    } catch (GovDataException $e) {
        return $mosGovPage($response, 'identity_form.php', [
            'isEdit' => false,
            'row' => $data,
            'personCandidates' => PersonLookup::candidates(),
            'errors' => $e->getErrors(),
            'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
            'esc' => $mosGovEsc,
        ], 400);
    }
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// identity detail: roles, scopes, permission overrides
$app->get('/mos-gov/identity/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovDenyPage, $mosGovEsc): Response {
    $repo = $mosGovRepo();
    $id = (int) $args['id'];
    $row = $repo->find('identity', $id);
    if ($row === null) {
        return $mosGovPage($response, 'error_page.php', ['message' => '该治理身份不存在。'], 404);
    }

    $user = GovAuthorization::currentUser();
    $decision = GovAuthorization::can($user, 'view', 'identity', $row);
    if (!$decision->allowed && !($user !== null && $user->isAdmin())) {
        return $mosGovDenyPage($response, $decision);
    }

    $identityRoles = $repo->listWhere('identity_role', ['identity_id' => $id], 100);
    $identityScopes = $repo->listWhere('identity_scope', ['identity_id' => $id], 100);
    $overrides = $repo->listWhere('identity_permission', ['identity_id' => $id], 100);

    // decorate references
    $roleLabels = $appointmentLabels = $permissionLabels = [];
    foreach ($repo->list('role', 1000) as $r) {
        $roleLabels[(int) $r['id']] = $r['name'] . ' [' . ($r['role_code'] ?? '') . ']';
    }
    foreach ($identityRoles as $ir) {
        if (!empty($ir['appointment_id'])) {
            $a = $repo->find('appointment', (int) $ir['appointment_id']);
            $appointmentLabels[(int) $ir['appointment_id']] = $a ? '任命 #' . $a['id'] . '（人员 ' . PersonLookup::label((int) $a['person_id']) . '）' : '#' . $ir['appointment_id'];
        }
    }
    foreach ($repo->list('permission', 1000) as $p) {
        $permissionLabels[(int) $p['id']] = $p['permission_key'];
    }

    return $mosGovPage($response, 'identity_view.php', [
        'row' => $row,
        'personLabel' => PersonLookup::label((int) $row['person_id']),
        'identityRoles' => $identityRoles,
        'identityScopes' => $identityScopes,
        'overrides' => $overrides,
        'roleLabels' => $roleLabels,
        'appointmentLabels' => $appointmentLabels,
        'permissionLabels' => $permissionLabels,
        'scopeTypes' => GovRepository::SCOPE_TYPES,
        'grantModes' => GovRepository::GRANT_MODES,
        'canEdit' => GovAuthorization::allows($user, 'edit', 'identity') || ($user !== null && $user->isAdmin()),
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// attach role to identity
$app->post('/mos-gov/identity/{id:[0-9]+}/attach-role', function (Request $request, Response $response, array $args) use ($mosGovRepo, $mosGovErrorPage): Response {
    if (!CSRFUtils::verifyRequest((array) $request->getParsedBody(), 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失。');
    }
    $user = GovAuthorization::currentUser();
    if (!(GovAuthorization::allows($user, 'edit', 'identity') || $user?->isAdmin())) {
        return $mosGovErrorPage($response, '你没有管理治理身份的权限。', 403);
    }

    $data = (array) $request->getParsedBody();
    $service = new IdentityService($mosGovRepo());
    try {
        $service->attachRole(
            (int) $args['id'],
            (int) ($data['role_id'] ?? 0),
            isset($data['appointment_id']) && $data['appointment_id'] !== '' ? (int) $data['appointment_id'] : null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null
        );
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, implode(' ', $e->getErrors() ?: [$e->getMessage()]));
    }

    return SlimUtils::renderRedirect($response, SystemURLs::getRootPath() . '/plugins/mos-gov/identity/' . (int) $args['id']);
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// assign scope to identity
$app->post('/mos-gov/identity/{id:[0-9]+}/assign-scope', function (Request $request, Response $response, array $args) use ($mosGovRepo, $mosGovErrorPage): Response {
    if (!CSRFUtils::verifyRequest((array) $request->getParsedBody(), 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失。');
    }
    $user = GovAuthorization::currentUser();
    if (!(GovAuthorization::allows($user, 'edit', 'identity') || $user?->isAdmin())) {
        return $mosGovErrorPage($response, '你没有管理治理身份的权限。', 403);
    }

    $data = (array) $request->getParsedBody();
    $service = new IdentityService($mosGovRepo());
    try {
        $service->assignScope(
            (int) $args['id'],
            (string) ($data['scope_type'] ?? ''),
            isset($data['scope_id']) && $data['scope_id'] !== '' ? (int) $data['scope_id'] : null,
            (string) ($data['source_type'] ?? 'manual_assignment')
        );
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage());
    }

    return SlimUtils::renderRedirect($response, SystemURLs::getRootPath() . '/plugins/mos-gov/identity/' . (int) $args['id']);
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// explicit permission override (grant / deny)
$app->post('/mos-gov/identity/{id:[0-9]+}/override-permission', function (Request $request, Response $response, array $args) use ($mosGovRepo, $mosGovErrorPage): Response {
    if (!CSRFUtils::verifyRequest((array) $request->getParsedBody(), 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失。');
    }
    $user = GovAuthorization::currentUser();
    if (!(GovAuthorization::allows($user, 'manage', 'permission') || $user?->isAdmin())) {
        return $mosGovErrorPage($response, '你没有管理权限。', 403);
    }

    $data = (array) $request->getParsedBody();
    $service = new IdentityService($mosGovRepo());
    try {
        $service->overridePermission(
            (int) $args['id'],
            (int) ($data['permission_id'] ?? 0),
            (string) ($data['grant_mode'] ?? 'grant'),
            (string) ($data['reason'] ?? '')
        );
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage());
    }

    return SlimUtils::renderRedirect($response, SystemURLs::getRootPath() . '/plugins/mos-gov/identity/' . (int) $args['id']);
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// ------------------------------------------------------- permission registry
// FINAL REVIEW §1: reading the registry is itself a governance act — it
// exposes the whole permission model and the role→permission matrix, which
// is exactly what permission.manage governs. The page used to render for any
// authenticated user and only hide the write controls; read is now gated by
// the same permission as write.
$app->get('/mos-gov/permissions', function (Request $request, Response $response) use ($mosGovPage, $mosGovRepo, $mosGovDenyPage, $mosGovEsc): Response {
    $user = GovAuthorization::currentUser();
    $canManage = GovAuthorization::allows($user, 'manage', 'permission') || ($user !== null && $user->isAdmin());
    if (!$canManage) {
        return $mosGovDenyPage($response, GovAuthorization::can($user, 'manage', 'permission'));
    }

    $repo = $mosGovRepo();
    $permissions = $repo->list('permission', 1000);
    $roles = [];
    $rolePermissions = [];
    foreach ($repo->list('role', 200) as $role) {
        if (!empty($role['role_code'])) {
            $roles[(int) $role['id']] = $role;
        }
    }
    foreach ($repo->list('role_permission', 5000) as $rp) {
        $rolePermissions[(int) $rp['role_id']][] = (int) $rp['permission_id'];
    }

    return $mosGovPage($response, 'permission_registry.php', [
        'permissions' => $permissions,
        'roles' => $roles,
        'rolePermissions' => $rolePermissions,
        'canManage' => $canManage,
        'esc' => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// ===========================================================================
// MOS-GOV V2.0 — 新增的两个模块入口
//
// V2.0 把一级导航收口为 仪表板/人/组/活动/账务/管理（§2、§35）。其中两项
// 能力核心不提供，必须由插件补齐，否则导航会出现死链（§27）：
//
//   管理 → 数据维护  §23  要求保留并强化数据格式化/清零，且必须防误触
//   账务 → 报表    §16/§19 要求地区中立报表；核心 /finance/reports 的唯一下级页
//                          是美国捐赠税务声明，属必须删除的地区特定逻辑，故不可用
//
// 两条路由都挂 GovSecureModeMiddleware，与插件其余页面保持一致。
// ===========================================================================

// ===========================================================================
// MOS CHURCH 结构精简阶段 —— 数据格式化 / 清零 与 账务报表
//
// 一级导航收口为 仪表板/日历/人/组/活动/财务/管理员。两项能力核心不提供，
// 由插件补齐，否则导航出现死链（§13）：
//
//   管理员 → 数据格式化  §4/§12 要求可清空业务数据服务新教会，且必须有
//                         权限保护、二次确认、清零前备份、不删管理员
//   财务   → 报表        §7 要求地区无关的教会账务报表；核心 /finance/reports
//                         的唯一下级页是美国捐赠税务声明，不能用
//
// 安全要点：本文件不提供任何无保护的清零入口。
//   核心 DELETE /api/database/reset 缺少管理员校验（详见 GovDataMaintenance
//   类注释），因此本插件自行实现「只清业务数据、保留系统骨架与管理员」的清零，
//   并且必须同时满足：管理员权限 + CSRF + 输入确认词，三者缺一不可。
// ===========================================================================

// ------------------------------------------------------- 管理员权限守卫
// 数据格式化是本产品最危险的操作：单独抽一个守卫，确保 GET 只读、POST 才动手，
// 且两者都要求管理员（§12.7）。
//
// 实现要点：守卫必须复用路由处理器传入的 $response，不能自己 new 一个。
// Slim\Psr7\Response 在本版本是 PSR-7 消息对象而非工厂（没有 ::create()），
// 早前误用 `Response::create()` 会导致非管理员访问时抛 500 而非 403 ——
// 「拒绝」必须干净，否则既泄露内部错误、又让真正的权限问题被误判成故障。
$mosGovAdminOnly = static function (Response $response): ?Response {
    $user = GovAuthorization::currentUser();
    if ($user !== null && $user->isAdmin()) {
        return null;
    }

    return \ChurchCRM\Slim\SlimUtils::renderErrorJSON(
        $response,
        '仅管理员可执行数据维护操作',
        [],
        403
    );
};

// ------------------------------------------------------------ 数据格式化
$app->get('/mos-gov/admin/data-maintenance', function (Request $request, Response $response) use ($mosGovPage, $mosGovEsc, $mosGovAdminOnly): Response {
    // 危险操作：连「查看清零范围」都要求管理员，避免普通用户探知数据结构。
    if ($resp = $mosGovAdminOnly($response)) {
        return $resp;
    }

    $maintenance = new GovDataMaintenance();
    $scope = $maintenance->scopePreview();

    $root = SystemURLs::getRootPath();

    // 备份与恢复走核心既有入口（只读/恢复，不做清零）：
    // §12.3 要求清零前可备份，故这里提供备份入口；但「清零」本身由本插件执行，
    // 不跳转到核心那个会 DROP 全部表的 /admin/system/reset。
    $operations = [
        [
            'key'     => 'backup',
            'title'   => '备份数据库',
            'desc'    => '生成当前数据库快照。清零业务数据前建议先做备份。',
            'risk'    => 'safe',
            'confirm' => '不修改任何数据',
            'href'    => $root . '/admin/system/backup',
        ],
        [
            'key'     => 'restore',
            'title'   => '从备份恢复',
            'desc'    => '用先前备份覆盖当前数据。恢复后当前数据会被替换。',
            'risk'    => 'warn',
            'confirm' => '将以备份内容替换当前全部数据',
            'href'    => $root . '/admin/system/restore',
        ],
    ];

    return $mosGovPage($response, 'admin_data_maintenance.php', [
        'scope'      => $scope,
        'operations' => $operations,
        'csrfField'  => CSRFUtils::getTokenInputField('mos-gov'),
        'confirmWord' => GovDataMaintenance::CONFIRM_WORD,
        'esc'        => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);

// POST：执行清零。三重校验 —— 管理员 + CSRF + 确认词（§12.2/§12.6/§12.7）。
$app->post('/mos-gov/admin/data-maintenance', function (Request $request, Response $response) use ($mosGovPage, $mosGovEsc, $mosGovAdminOnly, $mosGovErrorPage): Response {
    if ($resp = $mosGovAdminOnly($response)) {
        return $resp;
    }

    $data = (array) $request->getParsedBody();

    // CSRF：防止第三方页面诱导管理员误触。
    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'CSRF 令牌无效或缺失，请返回刷新后重试。', 400);
    }

    $confirm = trim((string) ($data['confirm_word'] ?? ''));
    if ($confirm !== GovDataMaintenance::CONFIRM_WORD) {
        // 明确告知需要输入什么，避免管理员反复试错（§12.2 要求二次确认）。
        return $mosGovPage($response, 'admin_data_maintenance.php', [
            'scope'       => (new GovDataMaintenance())->scopePreview(),
            'operations'  => [],
            'csrfField'   => CSRFUtils::getTokenInputField('mos-gov'),
            'confirmWord' => GovDataMaintenance::CONFIRM_WORD,
            'error'       => '确认词不正确，未执行任何清零。请完整输入：' . GovDataMaintenance::CONFIRM_WORD,
            'esc'         => $mosGovEsc,
        ], 400);
    }

    $maintenance = new GovDataMaintenance();
    $result = $maintenance->clearBusinessData();

    return $mosGovPage($response, 'admin_data_maintenance.php', [
        // 清零后范围已变化，直接展示执行结果。
        'scope'      => $maintenance->scopePreview(),
        'operations' => [],
        'result'     => $result,
        'csrfField'  => CSRFUtils::getTokenInputField('mos-gov'),
        'confirmWord' => GovDataMaintenance::CONFIRM_WORD,
        'esc'        => $mosGovEsc,
    ]);
})->add(GovWriteRoleAuthMiddleware::class)->add(GovSecureModeMiddleware::class);

// ---------------------------------------------------------- 账务报表
$app->get('/mos-gov/finance/report', function (Request $request, Response $response) use ($mosGovPage, $mosGovEsc): Response {
    $report = new MosGovFinanceReport();

    return $mosGovPage($response, 'finance_report.php', [
        'monthly' => $report->monthly(),
        'yearly'  => $report->yearly(),
        'byFund'  => $report->byFund(),
        'totals'  => $report->totals(),
        'error'   => $report->error(),
        'esc'     => $mosGovEsc,
    ]);
})->add(GovSecureModeMiddleware::class);
