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
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Integration\PersonLookup;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\GovWriteRoleAuthMiddleware;
use ChurchCRM\Slim\SlimUtils;
use ChurchCRM\Utils\CSRFUtils;
use ChurchCRM\dto\SystemURLs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$mosGovBase = SystemURLs::getRootPath() . '/plugins/mos-gov';

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
        $roleName = 'Role #' . ($row['role_id'] ?? '?');
        $roleId = (int) ($row['role_id'] ?? 0);
        if ($roleId > 0) {
            $key = 'role#' . $roleId;
            if (!isset($mosGovLabelCache[$key])) {
                $role = $repo->find('role', $roleId);
                $mosGovLabelCache[$key] = $role['name'] ?? ('Role #' . $roleId);
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
                    ? '#' . (int) $value . ' (missing)'
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
$app->get('/mos-gov', function (Request $request, Response $response) use ($mosGovRender, $mosGovRepo, $mosGovEsc, $mosGovDecorateRows): Response {
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
});

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
        'esc' => $mosGovEsc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ----------------------------------------------------------- entity list
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovDecorateRows, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);

    $rows = [];
    $error = null;
    try {
        $rows = $repo->list($entity);
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
});

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
})->add(GovWriteRoleAuthMiddleware::class);

// ------------------------------------------------ entity create: submit
$app->post('/mos-gov/{entity:' . $mosGovSlugPattern . '}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovErrorPage, $mosGovSlugByEntity, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $data = (array) $request->getParsedBody();

    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'Invalid or missing CSRF token. Go back, reload the form and try again.');
    }
    unset($data['csrf_token']);

    try {
        $context = $mosGovFormContext($repo, $cfg, $data);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    try {
        $id = $repo->insert($entity, $data);

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
})->add(GovWriteRoleAuthMiddleware::class);

// ------------------------------------------------ entity detail
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovDecorateRows, $mosGovCollectRelated, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $id = (int) $args['id'];

    $row = null;
    $decorations = [];
    $related = [];
    $error = null;

    try {
        $row = $repo->find($entity, $id);
        if ($row !== null) {
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
        'esc' => $mosGovEsc,
    ]);
});

// ------------------------------------------------ entity edit: form
$app->get('/mos-gov/{entity:' . $mosGovSlugPattern . '}/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovErrorPage, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $id = (int) $args['id'];

    try {
        $row = $repo->find($entity, $id);
        if ($row === null) {
            return $mosGovErrorPage($response, 'This record does not exist.', 404);
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
})->add(GovWriteRoleAuthMiddleware::class);

// ------------------------------------------------ entity edit: submit
$app->post('/mos-gov/{entity:' . $mosGovSlugPattern . '}/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($mosGovPage, $mosGovRepo, $mosGovEntityBySlug, $mosGovFormContext, $mosGovErrorPage, $mosGovSlugByEntity, $mosGovBase, $mosGovEsc): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $id = (int) $args['id'];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $data = (array) $request->getParsedBody();

    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'Invalid or missing CSRF token. Go back, reload the form and try again.');
    }
    unset($data['csrf_token']);

    try {
        $context = $mosGovFormContext($repo, $cfg, $data);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    try {
        $repo->update($entity, $id, $data);

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
})->add(GovWriteRoleAuthMiddleware::class);
