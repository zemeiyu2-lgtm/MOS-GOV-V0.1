<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Request-level public guards (Phase 3, findings SEC-02/03/04/10).
 *
 * Kept free of PSR-7 / Slim dependencies on purpose: the exact same logic is
 * used by `PublicRequestGuardMiddleware` at runtime and by the offline test
 * suite, so the tested behaviour is the deployed behaviour.
 *
 * Coverage:
 *   - security response headers (HSTS, nosniff, frame deny, referrer, CSP);
 *   - Origin / Referer same-origin validation on every state-changing request
 *     (defence in depth on top of the synchronizer CSRF token);
 *   - a mandatory CSRF token for POST / PUT / PATCH / DELETE;
 *   - fail-closed refusal when the caller is not authenticated on a write;
 *   - no request header is ever used as an authorization grant.
 */
final class PublicRequestPolicy
{
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /** @return array<string, string> */
    public static function securityHeaders(bool $https, bool $public = true): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' =>
                "default-src 'self'; base-uri 'self'; object-src 'none'; "
                . "frame-ancestors 'none'; form-action 'self'; "
                . "script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:",
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ];
        if ($https && $public) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Same-origin check. In PUBLIC mode a state-changing request without a
     * matching Origin (or Referer fallback) is refused — browsers always send
     * one of the two for form posts, so missing both is a strong CSRF signal.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     * @return array{ok:bool, reason:string}
     */
    public static function validateOrigin(string $method, array $server, array $headers = [], bool $public = true): array
    {
        if (in_array(strtoupper($method), self::SAFE_METHODS, true)) {
            return ['ok' => true, 'reason' => 'safe method'];
        }
        if (!$public) {
            return ['ok' => true, 'reason' => 'non-public deployment'];
        }

        $expectedHost = strtolower((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        if ($expectedHost === '') {
            return ['ok' => false, 'reason' => 'missing Host header'];
        }

        $origin = (string) ($headers['Origin'] ?? $server['HTTP_ORIGIN'] ?? '');
        $candidates = [];
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $candidates[] = $origin;
        }
        $referer = (string) ($headers['Referer'] ?? $server['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $candidates[] = $referer;
        }
        if ($candidates === []) {
            return ['ok' => false, 'reason' => 'state-changing request has neither Origin nor Referer'];
        }

        $expectedScheme = strtolower((string) ($server['HTTPS'] ?? '')) !== ''
            && strtolower((string) ($server['HTTPS'] ?? '')) !== 'off' ? 'https' : 'http';
        foreach ($candidates as $candidate) {
            $parts = parse_url($candidate);
            if (!is_array($parts) || !isset($parts['host'])) {
                continue;
            }
            $host = strtolower((string) $parts['host']);
            if (isset($parts['port']) && (int) $parts['port'] !== 443 && (int) $parts['port'] !== 80) {
                $host .= ':' . (int) $parts['port'];
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($host === $expectedHost && ($scheme === '' || $scheme === $expectedScheme)) {
                return ['ok' => true, 'reason' => 'same origin'];
            }
        }

        return ['ok' => false, 'reason' => 'cross-origin state-changing request'];
    }

    /**
     * CSRF gate. `$verify` is injected so the runtime can delegate to
     * ChurchCRM\Utils\CSRFUtils::verifyRequest() while tests supply a deterministic
     * verifier; the policy itself enforces WHEN verification is required.
     *
     * @param array<string, mixed> $body
     * @return array{ok:bool, reason:string}
     */
    public static function requireCsrf(string $method, array $body, ?string $headerToken, callable $verify): array
    {
        if (in_array(strtoupper($method), self::SAFE_METHODS, true)) {
            return ['ok' => true, 'reason' => 'safe method'];
        }
        $token = null;
        if (isset($body['csrf_token']) && is_string($body['csrf_token'])) {
            $token = $body['csrf_token'];
        } elseif ($headerToken !== null && $headerToken !== '') {
            $token = $headerToken;
        }
        if ($token === null || !preg_match('/^[A-Za-z0-9._-]{16,256}$/', $token)) {
            return ['ok' => false, 'reason' => 'CSRF token missing or malformed'];
        }
        if (!$verify($token)) {
            return ['ok' => false, 'reason' => 'CSRF token invalid or expired'];
        }

        return ['ok' => true, 'reason' => 'valid token'];
    }

    /**
     * Full evaluation of one request, used by the middleware and the lab suite.
     *
     * @param array{
     *   method:string, server:array, headers:array, body:array,
     *   authenticated:bool, public:bool, https:bool,
     *   csrf_verifier:callable
     * } $ctx
     * @return array{ok:bool, status:int, reasons:list<string>, headers:array<string,string>}
     */
    public static function evaluate(array $ctx): array
    {
        $method = strtoupper((string) $ctx['method']);
        $public = (bool) $ctx['public'];
        $https = (bool) $ctx['https'];
        $headers = self::securityHeaders($https, $public);
        $reasons = [];

        if ($public && !$https) {
            $reasons[] = 'HTTPS required';
        }
        if (!$ctx['authenticated']) {
            $reasons[] = 'authentication required';
        }
        $origin = self::validateOrigin($method, $ctx['server'], $ctx['headers'], $public);
        if (!$origin['ok']) {
            $reasons[] = $origin['reason'];
        }
        $csrf = self::requireCsrf(
            $method,
            $ctx['body'],
            (string) ($ctx['headers']['X-CSRF-Token'] ?? ''),
            $ctx['csrf_verifier']
        );
        if (!$csrf['ok']) {
            $reasons[] = $csrf['reason'];
        }

        return [
            'ok' => $reasons === [],
            'status' => $reasons === [] ? 200 : ($ctx['authenticated'] ? 403 : 401),
            'reasons' => $reasons,
            'headers' => $headers,
        ];
    }
}
