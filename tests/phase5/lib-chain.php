<?php
/**
 * MOS-GOV Phase 5 — deterministic HMAC audit-chain convention for the two
 * SYNTHETIC test churches. Each church uses its OWN key (silo-per-church),
 * so a record from A can never be verified with B's key.
 *
 * Convention (documented so verification is reproducible):
 *   prev0   = 64 x '0'
 *   payload = tenant_id|actor|occurred_at|object_type|object_id|action|result|authorization_basis|prev_hash
 *   row_hash = HMAC-SHA256(payload, church_key)
 *   prev_hash(next) = row_hash(current)
 *
 * NOTE: this is a Phase 5 lab convention used to test cross-church KEY
 * isolation. It is NOT a replacement for the production GovernanceAuditService.
 */
declare(strict_types=1);

function p5_chain_payload(array $r): string
{
    return implode('|', [
        $r['tenant_id'], $r['actor'], $r['occurred_at'], $r['object_type'],
        (string)$r['object_id'], $r['action'], $r['result'],
        $r['authorization_basis'], $r['prev_hash'],
    ]);
}

function p5_chain_hash(array $r, string $key): string
{
    return hash_hmac('sha256', p5_chain_payload($r), $key);
}

/** Verify a whole chain fetched from the DB, ordered by id. */
function p5_chain_verify(array $rows, string $key): array
{
    $prev = str_repeat('0', 64);
    foreach ($rows as $r) {
        if (!hash_equals((string)$r['prev_hash'], $prev)) {
            return ['ok' => false, 'reason' => 'prev_hash link broken at id=' . $r['id'], 'rows' => count($rows)];
        }
        $calc = p5_chain_hash([
            'tenant_id' => $r['tenant_id'], 'actor' => $r['actor'],
            'occurred_at' => $r['occurred_at'], 'object_type' => $r['object_type'],
            'object_id' => $r['object_id'], 'action' => $r['action'],
            'result' => $r['result'], 'authorization_basis' => $r['authorization_basis'],
            'prev_hash' => $r['prev_hash'],
        ], $key);
        if (!hash_equals($calc, (string)$r['row_hash'])) {
            return ['ok' => false, 'reason' => 'row_hash mismatch at id=' . $r['id'], 'rows' => count($rows)];
        }
        $prev = (string)$r['row_hash'];
    }
    return ['ok' => true, 'reason' => 'chain intact', 'rows' => count($rows), 'head' => $prev];
}
