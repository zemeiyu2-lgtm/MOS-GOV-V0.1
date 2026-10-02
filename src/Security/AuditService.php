<?php

namespace ChurchCRM\Plugins\MosGov\Security;

use ChurchCRM\Utils\LoggerUtils;

/**
 * Audit service (V0.2 design §36).
 *
 * V0.2 does NOT implement full event sourcing. This service reserves the
 * audit interface and records the highest-value governance events to the
 * application log so a future DB-backed audit trail can migrate the format:
 *
 *   actor, action, resource, resource_id, timestamp, result, reason
 *
 * Audited today: permission changes, role/appointment changes, permission
 * management, sensitive (P5) data export. Failures never break the request.
 */
final class AuditService
{
    /** Audit a governance event (best effort — logging must not break pages). */
    public static function audit(
        string $actor,
        string $action,
        string $resource,
        ?int $resourceId,
        string $result,
        string $reason = '',
    ): void {
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
            // auditing is best-effort by design
        }
    }

    /** Convenience: audit with the current user as actor. */
    public static function auditCurrent(string $action, string $resource, ?int $resourceId, string $result, string $reason = ''): void
    {
        $user = GovAuthorization::currentUser();
        self::audit($user?->getUserName() ?? 'anonymous', $action, $resource, $resourceId, $result, $reason);
    }
}
