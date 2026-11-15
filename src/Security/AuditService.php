<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Utils\LoggerUtils;

/**
 * Audit service (V0.2 design §36; V1.0 dual-write extension).
 *
 * TWO channels, two roles (never duplicate sensitive content into either):
 *
 *   1. application log (unchanged legacy channel) — operational diagnostics
 *      with the full payload; retained by the logging subsystem.
 *   2. gov_audit_log (V1.0 evidence channel) — governance evidence trail:
 *      actor, action, resource, resource_id, result, reason ONLY.
 *
 * Sensitive-field policy (whitelist approach): the DB channel stores exactly
 * the six fixed columns above. Free text passes through sanitizeReason(),
 * which redacts credential-like substrings (password/token/secret/authorization
 * patterns) and hard-caps the length. Nothing else from the request
 * environment (sessions, tokens, profile fields) ever reaches the audit row.
 *
 * Failure policy (V1.0 constraint: audit losses must not be silent):
 *   - if the DB write fails, the full payload is re-logged at ERROR level to
 *     the application log (compensating record), and audit() returns FALSE.
 *   - callers of critical governance writes (delegation create/revoke,
 *     oversight lifecycle changes) MUST check the boolean and surface the
 *     loss to the user — returning false is the contract; the service never
 *     pretends a lost record was stored.
 *
 * KNOWN LIMITATION (documented, not hidden): the business write and the
 * audit insert are NOT wrapped in one transaction — the plugin's data access
 * has no spanning transaction primitive. Therefore the dual write is
 * best-effort atomic: business-success + audit-failure is detectable
 * (false + ERROR log) and surfaced, but not prevented. Migrating to a
 * transactional outbox is a V2 candidate.
 *
 * Audited today: permission changes, role/appointment changes, permission
 * management, sensitive (P5) data export, and — since V1.0 — delegation
 * and oversight lifecycle events from the generic entity write routes.
 */
final class AuditService
{
    /** Closed result whitelist for the evidence channel. */
    public const RESULTS = ['success', 'denied', 'error'];

    /** Audit a governance event. Returns true when the DB evidence row is stored. */
    public static function audit(
        string $actor,
        string $action,
        string $resource,
        ?int $resourceId,
        string $result,
        string $reason = '',
    ): bool {
        $actor = self::clip($actor, 190);
        $action = self::clip($action, 100);
        $resource = self::clip($resource, 100);
        $result = in_array($result, self::RESULTS, true) ? $result : 'error';
        $reason = self::sanitizeReason($reason);

        // Channel 1: application log (operational). Best effort — a broken
        // logging subsystem must not stop the evidence channel below.
        try {
            LoggerUtils::getAppLogger()->info('MOS-GOV audit', [
                'actor' => $actor,
                'action' => $action,
                'resource' => $resource,
                'resource_id' => $resourceId,
                'result' => $result,
                'reason' => $reason,
                'timestamp' => date('c'),
            ]);
        } catch (\Throwable $e) {
            // logging subsystem unavailable; DB channel still attempted
        }

        // Channel 2: gov_audit_log (evidence). Losses are surfaced, never hidden.
        return self::writeToDb([
            'actor' => $actor,
            'action' => $action,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'result' => $result,
            'reason' => $reason,
        ]);
    }

    /** Convenience: audit with the current user as actor. */
    public static function auditCurrent(string $action, string $resource, ?int $resourceId, string $result, string $reason = ''): bool
    {
        $user = GovAuthorization::currentUser();

        return self::audit($user?->getUserName() ?? 'anonymous', $action, $resource, $resourceId, $result, $reason);
    }

    /**
     * The evidence-channel insert. Public + connection-injectable so the
     * V1.0 test suite can exercise success and failure paths against the
     * isolated database (or a deliberately broken connection).
     *
     * @param array{actor:string, action:string, resource:string, resource_id:int|null, result:string, reason:string} $fields
     */
    public static function writeToDb(array $fields, $conn = null): bool
    {
        $payload = [
            'actor' => $fields['actor'] ?? 'anonymous',
            'action' => $fields['action'] ?? '',
            'resource' => $fields['resource'] ?? '',
            'resource_id' => $fields['resource_id'] ?? null,
            'result' => in_array($fields['result'] ?? '', self::RESULTS, true) ? $fields['result'] : 'error',
            'reason' => self::sanitizeReason((string) ($fields['reason'] ?? '')),
        ];

        try {
            $conn = $conn ?? \Propel\Runtime\Propel::getConnection();
            $stmt = $conn->prepare(
                'INSERT INTO gov_audit_log (actor, action, resource, resource_id, result, reason)
                 VALUES (:actor, :action, :resource, :resource_id, :result, :reason)'
            );
            $stmt->bindValue(':actor', $payload['actor']);
            $stmt->bindValue(':action', $payload['action']);
            $stmt->bindValue(':resource', $payload['resource']);
            $stmt->bindValue(':resource_id', $payload['resource_id'], $payload['resource_id'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $stmt->bindValue(':result', $payload['result']);
            $stmt->bindValue(':reason', $payload['reason']);
            $stmt->execute();

            return true;
        } catch (\Throwable $e) {
            // NOT silent: compensate into the application log at ERROR level
            // with the full payload so the lost evidence row is recoverable
            // from logs, and report failure to the caller via false.
            try {
                LoggerUtils::getAppLogger()->error('MOS-GOV audit DB write FAILED', [
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                    'timestamp' => date('c'),
                ]);
            } catch (\Throwable $ignored) {
                // both channels down — nothing further can be done here
            }

            return false;
        }
    }

    /**
     * Sensitive-content sanitizer for the reason field: redact credential-
     * like substrings, strip control characters, collapse whitespace, cap
     * length. Personal profiles must not be passed here in the first place;
     * this is the last line of defence, not the policy.
     */
    public static function sanitizeReason(string $reason): string
    {
        $reason = str_replace("\0", '', $reason);
        // control characters except tab/newline
        $reason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $reason) ?? $reason;
        // credential-like patterns -> redacted
        $reason = preg_replace(
            '/(?i)(password|passwd|pwd|token|secret|api[_-]?key|authorization)\s*[=:]\s*\S+/u',
            '$1=[redacted]',
            $reason
        ) ?? $reason;
        $reason = preg_replace('/\s+/u', ' ', $reason) ?? $reason;

        return self::clip(trim($reason), 255);
    }

    /** Trim + hard-cap a single-line identifier. */
    private static function clip(string $value, int $max): string
    {
        $value = trim(str_replace("\0", '', $value));

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
