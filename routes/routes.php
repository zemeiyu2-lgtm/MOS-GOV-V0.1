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
 * Fix history (V0.1 integration testing):
 * - The previous revision returned `function (App $app) {}` instead of using
 *   the in-scope $app, so registerPluginRoutes() silently registered nothing.
 * - Route paths were absolute ('/plugins/mos-gov'), which would have produced
 *   '/plugins/plugins/mos-gov' URLs behind the '/plugins' basePath.
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/mos-gov', function (Request $request, Response $response): Response {
    ob_start();
    require __DIR__ . '/../views/dashboard.php';
    $html = ob_get_clean();

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->get('/mos-gov/settings', function (Request $request, Response $response): Response {
    ob_start();
    require __DIR__ . '/../views/settings.php';
    $html = ob_get_clean();

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});
