<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Route middleware enforcing the MOS-GOV local/LAN secure mode (§32).
 *
 * Runs BEFORE any governance logic: clients that are neither loopback nor
 * (in LAN mode) a trusted private address receive a 403 without any data.
 */
final class GovSecureModeMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $deny = LocalSecureMode::check($request);
        if ($deny !== null) {
            $response = new \Slim\Psr7\Response(403);
            $response->getBody()->write(
                '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>MOS-GOV — refused</title></head>'
                . '<body style="font-family:sans-serif;max-width:40em;margin:4em auto">'
                . '<h1>MOS-GOV is not reachable from this network</h1>'
                . '<p>' . htmlspecialchars($deny->reason, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Security mode: ' . htmlspecialchars(LocalSecureMode::describe(), ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>'
            );

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        return $handler->handle($request);
    }
}
