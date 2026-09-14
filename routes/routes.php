<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $app->get('/plugins/mos-gov', function (Request $request, Response $response): Response {
        ob_start();
        require __DIR__ . '/../views/dashboard.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    $app->get('/plugins/mos-gov/settings', function (Request $request, Response $response): Response {
        ob_start();
        require __DIR__ . '/../views/settings.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};
