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
 * Phase 3 additions:
 * - Dashboard reads real counters from the governance data layer
 *   (ChurchCRM\Plugins\MosGov\Data\GovRepository) -- queries never live in
 *   views or route closures.
 * - Minimal CRUD (list / view / create / edit) for the four core entities:
 *   structure -> body -> role -> appointment.
 * - All POST handlers require a valid CSRF token (ChurchCRM CSRFUtils).
 * - Login protection comes from the global AuthMiddleware on the plugins
 *   entry point; no anonymous access reaches these handlers.
 *
 * Fix history (V0.1 integration testing):
 * - The previous revision returned `function (App $app) {}` instead of using
 *   the in-scope $app, so registerPluginRoutes() silently registered nothing.
 * - Route paths were absolute ('/plugins/mos-gov'), which would have produced
 *   '/plugins/plugins/mos-gov' URLs behind the '/plugins' basePath.
 */

use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Slim\SlimUtils;
use ChurchCRM\Utils\CSRFUtils;
use ChurchCRM\dto\SystemURLs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$mosGovBase = SystemURLs::getRootPath() . '/plugins/mos-gov';
$mosGovEntityBySlug = [
    'structures' => 'structure',
    'bodies' => 'body',
    'roles' => 'role',
    'appointments' => 'appointment',
];
$mosGovSlugByEntity = array_flip($mosGovEntityBySlug);
$mosGovEntityPattern = implode('|', array_keys($mosGovEntityBySlug));

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

/** Render a standalone error page (e.g. CSRF rejection). */
$mosGovErrorPage = static function (Response $response, string $message, int $status = 400) use ($mosGovRender): Response {
    $response->getBody()->write($mosGovRender('error_page.php', ['message' => $message]));

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

/**
 * Build field => (id => display-label) option lists for every ref field of
 * an entity config.
 *
 * @return array<string, array<int, string>>
 */
$mosGovCollectRefOptions = static function (GovRepository $repo, array $cfg): array {
    $options = [];
    foreach ($cfg['fields'] as $fieldName => $spec) {
        if ($spec['type'] !== 'ref') {
            continue;
        }
        $opts = [];
        foreach ($repo->list($spec['ref'], 1000) as $row) {
            $label = (string) ($row['name'] ?? ('#' . $row['id']));
            if (isset($row['code']) && $row['code'] !== null && $row['code'] !== '') {
                $label .= ' [' . $row['code'] . ']';
            }
            $opts[(int) $row['id']] = $label . ' (#' . $row['id'] . ')';
        }
        $options[$fieldName] = $opts;
    }

    return $options;
};

/**
 * Resolve display labels for ref fields of a detail row.
 *
 * @return array<string, string> field => "Parent name (#id)"
 */
$mosGovResolveRefLabels = static function (GovRepository $repo, array $cfg, array $row): array {
    $labels = [];
    foreach ($cfg['fields'] as $fieldName => $spec) {
        if ($spec['type'] !== 'ref') {
            continue;
        }
        $refId = (int) ($row[$fieldName] ?? 0);
        if ($refId <= 0) {
            continue;
        }
        $parent = $repo->find($spec['ref'], $refId);
        $labels[$fieldName] = ($parent['name'] ?? ('#' . $refId)) . ' (#' . $refId . ')';
    }

    return $labels;
};

// --------------------------------------------------------------- dashboard
$app->get('/mos-gov', function (Request $request, Response $response) use ($mosGovRender, $mosGovRepo): Response {
    $stats = null;
    $statsError = null;
    try {
        $stats = $mosGovRepo()->getDashboardCounts();
    } catch (GovDataException $e) {
        $statsError = $e->getMessage();
    }

    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $response->getBody()->write($mosGovRender('dashboard.php', [
        'stats' => $stats,
        'statsError' => $statsError,
        'esc' => $esc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// --------------------------------------------------------------- settings
$app->get('/mos-gov/settings', function (Request $request, Response $response) use ($mosGovRender): Response {
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $response->getBody()->write($mosGovRender('settings.php', ['esc' => $esc]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ----------------------------------------------------------- entity list
$app->get('/mos-gov/{entity:' . $mosGovEntityPattern . '}', function (Request $request, Response $response, array $args) use ($mosGovRender, $mosGovRepo, $mosGovEntityBySlug): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $rows = [];
    $error = null;
    try {
        $rows = $repo->list($entity);
    } catch (GovDataException $e) {
        $error = $e->getMessage();
    }

    $response->getBody()->write($mosGovRender('entity_list.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'rows' => $rows,
        'error' => $error,
        'esc' => $esc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ------------------------------------------------ entity create: form
$app->get('/mos-gov/{entity:' . $mosGovEntityPattern . '}/new', function (Request $request, Response $response, array $args) use ($mosGovRender, $mosGovRepo, $mosGovEntityBySlug, $mosGovCollectRefOptions, $mosGovErrorPage, $mosGovBase): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    try {
        $refOptions = $mosGovCollectRefOptions($repo, $cfg);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    $response->getBody()->write($mosGovRender('entity_form.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'isEdit' => false,
        'actionUrl' => $mosGovBase . '/' . $args['entity'],
        'data' => [],
        'errors' => [],
        'refOptions' => $mosGovCollectRefOptions($repo, $cfg),
        'statuses' => GovRepository::STATUSES,
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
        'error' => null,
        'esc' => $esc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ------------------------------------------------ entity create: submit
$app->post('/mos-gov/{entity:' . $mosGovEntityPattern . '}', function (Request $request, Response $response, array $args) use ($mosGovRender, $mosGovRepo, $mosGovEntityBySlug, $mosGovCollectRefOptions, $mosGovErrorPage, $mosGovSlugByEntity, $mosGovBase): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $data = (array) $request->getParsedBody();

    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'Invalid or missing CSRF token. Go back, reload the form and try again.');
    }
    unset($data['csrf_token']);

    try {
        $id = $repo->insert($entity, $data);

        return SlimUtils::renderRedirect($response, $mosGovBase . '/' . $mosGovSlugByEntity[$entity] . '/' . $id);
    } catch (GovDataException $e) {
        $response->getBody()->write($mosGovRender('entity_form.php', [
            'cfg' => $cfg,
            'slug' => $args['entity'],
            'isEdit' => false,
            'actionUrl' => $mosGovBase . '/' . $args['entity'],
            'data' => $data,
            'errors' => $e->getErrors(),
            'refOptions' => $mosGovCollectRefOptions($repo, $cfg),
            'statuses' => GovRepository::STATUSES,
            'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
            'error' => null,
            'esc' => $esc,
        ]));

        return $response->withStatus(400)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
});

// ------------------------------------------------ entity detail
$app->get('/mos-gov/{entity:' . $mosGovEntityPattern . '}/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($mosGovRender, $mosGovRepo, $mosGovEntityBySlug, $mosGovResolveRefLabels): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $row = null;
    $refLabels = [];
    $error = null;
    try {
        $row = $repo->find($entity, (int) $args['id']);
        if ($row !== null) {
            $refLabels = $mosGovResolveRefLabels($repo, $cfg, $row);
        }
    } catch (GovDataException $e) {
        $error = $e->getMessage();
    }

    $response->getBody()->write($mosGovRender('entity_view.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'row' => $row,
        'refLabels' => $refLabels,
        'error' => $error,
        'esc' => $esc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ------------------------------------------------ entity edit: form
$app->get('/mos-gov/{entity:' . $mosGovEntityPattern . '}/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($mosGovRender, $mosGovRepo, $mosGovEntityBySlug, $mosGovCollectRefOptions, $mosGovErrorPage, $mosGovBase): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    try {
        $row = $repo->find($entity, (int) $args['id']);
        if ($row === null) {
            return $mosGovErrorPage($response, 'This record does not exist.', 404);
        }
        $refOptions = $mosGovCollectRefOptions($repo, $cfg);
    } catch (GovDataException $e) {
        return $mosGovErrorPage($response, $e->getMessage(), 500);
    }

    $response->getBody()->write($mosGovRender('entity_form.php', [
        'cfg' => $cfg,
        'slug' => $args['entity'],
        'isEdit' => true,
        'actionUrl' => $mosGovBase . '/' . $args['entity'] . '/' . (int) $args['id'] . '/edit',
        'data' => $row,
        'errors' => [],
        'refOptions' => $mosGovCollectRefOptions($repo, $cfg),
        'statuses' => GovRepository::STATUSES,
        'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
        'error' => null,
        'esc' => $esc,
    ]));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ------------------------------------------------ entity edit: submit
$app->post('/mos-gov/{entity:' . $mosGovEntityPattern . '}/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($mosGovRender, $mosGovRepo, $mosGovEntityBySlug, $mosGovCollectRefOptions, $mosGovErrorPage, $mosGovSlugByEntity, $mosGovBase): Response {
    $entity = $mosGovEntityBySlug[$args['entity']];
    $id = (int) $args['id'];
    $repo = $mosGovRepo();
    $cfg = $repo->getEntity($entity);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $data = (array) $request->getParsedBody();

    if (!CSRFUtils::verifyRequest($data, 'mos-gov')) {
        return $mosGovErrorPage($response, 'Invalid or missing CSRF token. Go back, reload the form and try again.');
    }
    unset($data['csrf_token']);

    try {
        $repo->update($entity, $id, $data);

        return SlimUtils::renderRedirect($response, $mosGovBase . '/' . $mosGovSlugByEntity[$entity] . '/' . $id);
    } catch (GovDataException $e) {
        $response->getBody()->write($mosGovRender('entity_form.php', [
            'cfg' => $cfg,
            'slug' => $args['entity'],
            'isEdit' => true,
            'actionUrl' => $mosGovBase . '/' . $args['entity'] . '/' . $id . '/edit',
            'data' => $data,
            'errors' => $e->getErrors(),
            'refOptions' => $mosGovCollectRefOptions($repo, $cfg),
            'statuses' => GovRepository::STATUSES,
            'csrfField' => CSRFUtils::getTokenInputField('mos-gov'),
            'error' => null,
            'esc' => $esc,
        ]));

        return $response->withStatus(400)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
});
