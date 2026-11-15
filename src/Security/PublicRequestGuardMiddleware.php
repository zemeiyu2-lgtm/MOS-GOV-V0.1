<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Utils\CSRFUtils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Global public-request guard (Phase 3, findings SEC-01/02/03/10).
 *
 * Registered ONCE on the plugin Slim app (see routes/routes.php), so every
 * current and future /mos-gov route is covered automatically. The old
 * GovSecureModeMiddleware was attached route-by-route — one forgotten
 * `->add(...)` on a new route silently removed the boundary.
 *
 * Order of checks (all fail closed):
 *   1. transport trust (HTTPS, trusted-proxy rules, tenant context);
 *   2. hardened session settings applied before any session is touched;
 *   3. authenticated session + same-origin + CSRF on state-changing methods;
 *   4. security headers applied to the response, including denials.
 *
 * This middleware does NOT authorize WHAT a user may do — that remains the
 * job of GovMembershipMiddleware and GovernancePolicy. It only guarantees
 * that an unauthenticated / cross-site / plaintext request never reaches them.
 */
final class PublicRequestGuardMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $server = $request->getServerParams();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = is_array($values) ? (string) reset($values) : (string) $values;
        }
        $public = PublicTrustPolicy::isPublic();
        $trust = PublicTrustPolicy::authorityCheck($server, $headers);

        SessionHardening::apply($trust['https'], $public);

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $evaluation = PublicRequestPolicy::evaluate([
            'method' => $request->getMethod(),
            'server' => $server,
            'headers' => $headers,
            'body' => $body,
            'authenticated' => GovAuthorization::currentUser() !== null,
            'public' => $public,
            'https' => $trust['https'],
            'csrf_verifier' => static fn (string $token): bool => CSRFUtils::validateToken($token),
        ]);

        if (!$trust['ok']) {
            $evaluation['ok'] = false;
            $evaluation['status'] = 403;
            foreach ($trust['reasons'] as $reason) {
                $evaluation['reasons'][] = $reason;
            }
        }

        if (!$evaluation['ok']) {
            $response = new \Slim\Psr7\Response($evaluation['status']);
            $response->getBody()->write($this->denialBody($evaluation['status']));

            return $this->withSecurityHeaders($response, $evaluation['headers']);
        }

        $response = $handler->handle($request);

        return $this->withSecurityHeaders($response, $evaluation['headers']);
    }

    /** Generic denial text: never reflect request data or exception detail. */
    private function denialBody(int $status): string
    {
        $text = $status === 401
            ? '需要登录后才能访问治理系统。'
            : '请求未通过安全检查，已被拒绝。';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<title>MOS-GOV — 请求被拒绝</title></head><body>'
            . '<h1>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>请通过正式入口重新登录后再试。若持续出现，请联系系统管理员。</p>'
            . '</body></html>';
    }

    /** @param array<string, string> $headers */
    private function withSecurityHeaders(Response $response, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
