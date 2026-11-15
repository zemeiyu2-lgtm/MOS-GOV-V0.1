<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Error-response sanitizer (Phase 3, finding SEC-09).
 *
 * Before Phase 3, 15+ route handlers passed `$e->getMessage()` straight into
 * the rendered error page. A PDO/Propel exception message routinely contains
 * the SQL fragment, table/column names, DB host, port and sometimes the
 * connection user — exactly the reconnaissance data an Internet attacker
 * needs, and a direct breach of the "no sensitive information disclosure"
 * acceptance item.
 *
 * This helper keeps the detail in the server-side log (never in the response),
 * and returns a stable, non-descriptive message plus a short correlation id so
 * support can find the real error without exposing it to the client.
 */
final class SafeError
{
    /** Public, fixed wording; deliberately contains no exception content. */
    public const GENERIC = '操作未能完成，请稍后重试。如持续出现，请向管理员提供错误编号。';

    public static function idFor(\Throwable $e): string
    {
        return substr(hash(
            'sha256',
            $e::class . '|' . $e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage()
        ), 0, 12);
    }

    /**
     * Log the full diagnostic server-side and return the safe client text.
     *
     * @param string $context short, non-sensitive operation label
     */
    public static function publicMessage(\Throwable $e, string $context = ''): string
    {
        $id = self::idFor($e);
        // error_log goes to the hardened server log (logrotate + 0640 root),
        // never to the HTTP response. display_errors must be Off in php.ini.
        error_log(sprintf(
            '[MOS-GOV][%s] %s: %s in %s:%d | id=%s',
            $context !== '' ? $context : 'request',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $id
        ));

        return self::GENERIC . ' 错误编号：' . $id;
    }

    /**
     * Sanitize a diagnostic string produced by a service (no Throwable
     * available, e.g. GovDataMaintenance / MosGovFinanceReport). Keeps
     * harmless user-facing validation text, replaces DB internals.
     */
    public static function publicText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (!self::looksLikeLeak($text)) {
            return $text;
        }
        error_log('[MOS-GOV][redacted] service diagnostic withheld from response: ' . substr(hash('sha256', $text), 0, 12));

        return self::GENERIC . ' 错误编号：' . substr(hash('sha256', $text), 0, 12);
    }

    /** True when a rendered string still leaks a raw DB-style diagnostic. */
    public static function looksLikeLeak(string $rendered): bool
    {
        return preg_match(
            '/(SQLSTATE|PDOException|mysqli|Propel|SELECT\s+|INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM|Access denied|Unknown database|localhost|127\.0\.0\.1|3306|3406)/i',
            $rendered
        ) === 1;
    }
}
