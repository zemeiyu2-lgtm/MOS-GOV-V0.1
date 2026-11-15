-- ============================================================================
-- MOS-GOV Phase 3 — governance evidence trail (append-only, tamper-evident)
--
-- Migration policy: additive only. Nothing here modifies, truncates or drops
-- any existing gov_* table or ChurchCRM core table. Safe to run once on a
-- fresh database; re-running is idempotent (DROP TRIGGER IF EXISTS only
-- targets the two triggers created by THIS migration).
--
-- This is a CRUD-adjacent EVIDENCE LOG, not event sourcing. There is no event
-- replay, no aggregate reconstruction and no transactional outbox. See
-- src/Security/GovernanceAuditService.php for the exact scope statement.
--
-- The application DB account must be granted SELECT + INSERT on this table
-- ONLY (see deploy/scripts/provision-tenant.sh). The triggers are the second
-- layer: even an account that accidentally receives UPDATE/DELETE on the
-- whole schema cannot alter or remove an evidence row.
-- ============================================================================

CREATE TABLE IF NOT EXISTS gov_evidence_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     VARCHAR(32)  NOT NULL,
    actor         VARCHAR(190) NOT NULL,          -- operation subject (login name)
    actor_id      INT UNSIGNED NULL,              -- ChurchCRM user/person id when known
    occurred_at   DATETIME     NOT NULL,          -- UTC, set by the service
    object_type   VARCHAR(100) NOT NULL,          -- governance entity key
    object_id     BIGINT UNSIGNED NULL,           -- affected record id
    action        VARCHAR(100) NOT NULL,          -- create/update/delete/export/approve/...
    before_state  LONGTEXT NULL,                 -- JSON snapshot before the change
    after_state   LONGTEXT NULL,                 -- JSON snapshot after the change
    authorization_basis VARCHAR(1000) NOT NULL,  -- permission key + decision source + policy step
    result        ENUM('success','denied','error') NOT NULL,
    request_id    VARCHAR(64) NOT NULL DEFAULT '',
    prev_hash     CHAR(64) NOT NULL,             -- hash of the preceding row
    row_hash      CHAR(64) NOT NULL,             -- HMAC-SHA256 of this row + prev_hash
    PRIMARY KEY (id),
    UNIQUE KEY uq_gov_evidence_row_hash (row_hash),
    KEY idx_gov_evidence_tenant_time (tenant_id, occurred_at),
    KEY idx_gov_evidence_object (object_type, object_id),
    KEY idx_gov_evidence_actor (actor, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS gov_evidence_no_update;
DROP TRIGGER IF EXISTS gov_evidence_no_delete;

DELIMITER $$
CREATE TRIGGER gov_evidence_no_update
BEFORE UPDATE ON gov_evidence_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'gov_evidence_log is append-only: UPDATE is forbidden';
END$$
CREATE TRIGGER gov_evidence_no_delete
BEFORE DELETE ON gov_evidence_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'gov_evidence_log is append-only: DELETE is forbidden';
END$$
DELIMITER ;

-- The legacy six-column CRUD log stays for backward compatibility but is
-- treated as read-only evidence in new deployments. provision-tenant.sh
-- deliberately omits UPDATE/DELETE grants on it as well.
