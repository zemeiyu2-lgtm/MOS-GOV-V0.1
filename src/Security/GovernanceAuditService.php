<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Governance evidence trail (Phase 3, finding SEC-12 / governance audit).
 *
 * WHAT ALREADY EXISTED (do not overstate): `AuditService` writes six columns
 * (actor, action, resource, resource_id, result, reason) to `gov_audit_log`.
 * That is a CRUD log. It has NO before/after state, NO authorization basis,
 * NO tamper evidence, and the application DB account can UPDATE/DELETE it.
 * It is NOT event sourcing, and this class does NOT pretend to be event
 * sourcing either: there is no event replay, no aggregate reconstruction,
 * no snapshot and no transactional outbox.
 *
 * WHAT THIS ADDS (minimum reliable improvement):
 *   - a separate append-only `gov_evidence_log` with all seven required
 *     facts: actor, time, object, action, before state, after state,
 *     authorization basis and result;
 *   - a per-row HMAC-SHA256 hash chain (key held OUTSIDE the database), so
 *     any in-place edit or out-of-sequence insert is detectable;
 *   - database-level immutability: BEFORE UPDATE / BEFORE DELETE triggers
 *     plus a least-privilege app account with only SELECT + INSERT on this
 *     table (see database/005_governance_evidence.sql and provision-tenant.sh);
 *   - an off-database daily anchor (anchor()) so a chain rebuilt wholesale
 *     from scratch cannot pass verification unnoticed.
 *
 * KNOWN LIMIT (documented, not hidden): the business write and the evidence
 * insert are still two statements. If the process dies between them, the
 * business change has no evidence row. A transactional outbox is the next
 * step and is listed as a remaining risk in the delivery report.
 */
final class GovernanceAuditService
{
    public const RESULTS = ['success', 'denied', 'error'];

    private const FIELDS = [
        'tenant_id', 'actor', 'actor_id', 'occurred_at', 'object_type', 'object_id',
        'action', 'before_state', 'after_state', 'authorization_basis', 'result',
        'request_id', 'prev_hash', 'row_hash',
    ];

    /** @param object $conn PDO / Propel connection exposing prepare(), query(), lastInsertId() */
    public function __construct(
        private readonly object $conn,
        private readonly string $tenantId,
        private readonly string $hmacKey,
    ) {
        if (!TenantContext::validId($tenantId)) {
            throw new \RuntimeException('Invalid tenant id for governance evidence');
        }
        if (strlen($hmacKey) < 32) {
            throw new \RuntimeException('Audit HMAC key must be at least 32 bytes (fail closed)');
        }
    }

    /**
     * Append one evidence row. Throws on failure — a lost evidence row must
     * never be reported as a successful write.
     *
     * @param array{
     *   actor:string, actor_id?:int|null, object_type:string, object_id?:int|null,
     *   action:string, before_state?:mixed, after_state?:mixed,
     *   authorization_basis:string|array, result:string, request_id?:string
     * } $event
     * @return array{ok:bool, id:int, row_hash:string, prev_hash:string}
     */
    public function record(array $event): array
    {
        $required = ['actor', 'object_type', 'action', 'authorization_basis', 'result'];
        foreach ($required as $key) {
            if (!isset($event[$key]) || $event[$key] === '') {
                throw new \InvalidArgumentException('Evidence event missing required field: ' . $key);
            }
        }
        $result = (string) $event['result'];
        if (!in_array($result, self::RESULTS, true)) {
            throw new \InvalidArgumentException('Invalid evidence result');
        }

        $basis = is_array($event['authorization_basis'])
            ? self::json($event['authorization_basis'])
            : (string) $event['authorization_basis'];

        $row = [
            'tenant_id' => $this->tenantId,
            'actor' => mb_substr((string) $event['actor'], 0, 190),
            'actor_id' => isset($event['actor_id']) ? (int) $event['actor_id'] : null,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'object_type' => mb_substr((string) $event['object_type'], 0, 100),
            'object_id' => isset($event['object_id']) ? (int) $event['object_id'] : null,
            'action' => mb_substr((string) $event['action'], 0, 100),
            'before_state' => array_key_exists('before_state', $event) ? self::json($event['before_state']) : null,
            'after_state' => array_key_exists('after_state', $event) ? self::json($event['after_state']) : null,
            'authorization_basis' => mb_substr($basis, 0, 1000),
            'result' => $result,
            'request_id' => mb_substr((string) ($event['request_id'] ?? ''), 0, 64),
        ];

        $this->begin();
        try {
            // FOR UPDATE serialises concurrent appenders on InnoDB so two
            // requests cannot build a row on the same predecessor hash.
            $stmt = $this->conn->prepare('SELECT row_hash FROM gov_evidence_log ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute();
            $prevHash = (string) ($stmt->fetchColumn() ?: str_repeat('0', 64));

            $row['prev_hash'] = $prevHash;
            $row['row_hash'] = $this->hashRow($row, $prevHash);

            $columns = implode(', ', self::FIELDS);
            $placeholders = implode(', ', array_map(static fn (string $f): string => ':' . $f, self::FIELDS));
            $insert = $this->conn->prepare(
                'INSERT INTO gov_evidence_log (' . $columns . ') VALUES (' . $placeholders . ')'
            );
            foreach (self::FIELDS as $field) {
                $insert->bindValue(':' . $field, $row[$field]);
            }
            $insert->execute();
            $id = (int) $this->conn->lastInsertId();
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollBack();
            throw new \RuntimeException('Governance evidence write failed; business write must be treated as failed: ' . $e::class);
        }

        return ['ok' => true, 'id' => $id, 'row_hash' => $row['row_hash'], 'prev_hash' => $prevHash];
    }

    /**
     * Recompute and verify the whole chain.
     *
     * @return array{ok:bool, rows:int, broken_at:?int, reason:string}
     */
    public function verify(): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM gov_evidence_log ORDER BY id ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $expectedPrev = str_repeat('0', 64);
        $count = 0;

        foreach ($rows as $row) {
            $count++;
            if ((string) $row['prev_hash'] !== $expectedPrev) {
                return [
                    'ok' => false, 'rows' => $count, 'broken_at' => (int) $row['id'],
                    'reason' => 'chain link broken (prev_hash mismatch)',
                ];
            }
            $recomputed = $this->hashRow($row, (string) $row['prev_hash']);
            if (!hash_equals((string) $row['row_hash'], $recomputed)) {
                return [
                    'ok' => false, 'rows' => $count, 'broken_at' => (int) $row['id'],
                    'reason' => 'row hash mismatch (content tampered or HMAC key changed)',
                ];
            }
            $expectedPrev = (string) $row['row_hash'];
        }

        return ['ok' => true, 'rows' => $count, 'broken_at' => null, 'reason' => 'chain intact'];
    }

    /**
     * Fingerprint to be written OUTSIDE the database (daily anchor file /
     * backup manifest). A forger who can rewrite every row still cannot match
     * an anchor recorded before the tampering.
     *
     * @return array{rows:int, last_id:int, last_hash:string, generated_at:string}
     */
    public function anchor(): array
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) AS c, MAX(id) AS m FROM gov_evidence_log');
        $stmt->execute();
        $meta = $stmt->fetch(\PDO::FETCH_ASSOC);
        $lastHash = str_repeat('0', 64);
        if ((int) ($meta['m'] ?? 0) > 0) {
            $q = $this->conn->prepare('SELECT row_hash FROM gov_evidence_log ORDER BY id DESC LIMIT 1');
            $q->execute();
            $lastHash = (string) $q->fetchColumn();
        }

        return [
            'rows' => (int) ($meta['c'] ?? 0),
            'last_id' => (int) ($meta['m'] ?? 0),
            'last_hash' => $lastHash,
            'generated_at' => gmdate('c'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hashRow(array $row, string $prevHash): string
    {
        $canonical = [];
        foreach (self::FIELDS as $field) {
            if ($field === 'row_hash') {
                continue;
            }
            $value = $row[$field] ?? null;
            $canonical[] = $field . '=' . ($value === null ? "\x00" : (string) $value);
        }

        return hash_hmac('sha256', implode("\x1f", $canonical) . "\x1f" . $prevHash, $this->hmacKey);
    }

    private static function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $encoded === false ? '{"_unencodable":true}' : mb_substr($encoded, 0, 16000);
    }

    private function begin(): void
    {
        if (method_exists($this->conn, 'beginTransaction')) {
            $this->conn->beginTransaction();
        }
    }

    private function commit(): void
    {
        if (method_exists($this->conn, 'commit')) {
            $this->conn->commit();
        }
    }

    private function rollBack(): void
    {
        if (method_exists($this->conn, 'rollBack')) {
            try {
                $this->conn->rollBack();
            } catch (\Throwable $ignored) {
                // nothing further can be done; caller already fails closed
            }
        }
    }
}
