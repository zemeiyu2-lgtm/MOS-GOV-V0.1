-- MOS-GOV V1.0 — Governance health board schema (healthboard).
--
-- Migration policy (inherits V0.2 constraint #37):
--   - additive only: CREATE TABLE IF NOT EXISTS
--   - no DROP, no TRUNCATE, no DELETE, no ALTER of any existing gov_* table
--   - idempotent and safe to re-run
--   - ChurchCRM core tables are never touched
--   - no FOREIGN KEY constraints anywhere: cross-table references are integer
--     IDs resolved at application level (same as 001/002)
--
-- Three new tables sit ON TOP of the ten V0.1 entity tables and the nine
-- V0.2 authorization tables:
--   gov_delegation   PoC-02: explicit delegation of a permission/responsibility
--                    from one person to another, with scope + validity + revoke
--   gov_oversight    PoC chain terminus: oversight records attached to a
--                    decision / task / responsibility, with findings + outcome
--   gov_audit_log    G09: DB-backed audit trail (AuditService dual-writes here;
--                    the application-log format is unchanged so historic
--                    entries remain readable)
--
-- Roles of the audit channels (no duplication of sensitive data):
--   application log (existing): operational diagnostics, full context, retained
--                               by the logging subsystem
--   gov_audit_log (new):        governance evidence trail — actor, action,
--                               resource, resource_id, result, reason only;
--                               MUST NOT contain personal-profile content,
--                               credentials, or free-text sensitive data
--
-- Status vocabularies are CLOSED whitelists validated in
-- GovRepository (V1.0 constants); the database stores VARCHAR(30) like 001/002.

-- ------------------------------------------------------------ 1. delegation
-- Lifecycle: draft -> active -> expired  (time-driven)
--            active -> revoked           (explicit revocation)
-- 'expiring' is a computed state (end_date within warning window), not stored.
CREATE TABLE IF NOT EXISTS gov_delegation (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(190) NOT NULL,
    delegator_person_id INT UNSIGNED NOT NULL,
    delegate_person_id INT UNSIGNED NOT NULL,
    supervisor_person_id INT UNSIGNED NULL,
    responsibility_id INT UNSIGNED NULL,
    permission_id INT UNSIGNED NULL,
    scope_description VARCHAR(255) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    can_redelegate TINYINT(1) NOT NULL DEFAULT 0,
    approved_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_by_person_id INT UNSIGNED NULL,
    revocation_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_delegation_delegator (delegator_person_id),
    KEY idx_gov_delegation_delegate (delegate_person_id),
    KEY idx_gov_delegation_supervisor (supervisor_person_id),
    KEY idx_gov_delegation_responsibility (responsibility_id),
    KEY idx_gov_delegation_permission (permission_id),
    KEY idx_gov_delegation_status (status),
    KEY idx_gov_delegation_end (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- 2. oversight
-- Attached to at least one of decision / task / responsibility (enforced in
-- the application registry validation, not by a DB check constraint — the
-- 001/002 style uses no CHECK constraints). responsible_person_id is a
-- snapshot of the responsible party at oversight creation; the live answer is
-- resolved from the linked record at read time.
CREATE TABLE IF NOT EXISTS gov_oversight (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(190) NOT NULL,
    decision_id INT UNSIGNED NULL,
    task_id INT UNSIGNED NULL,
    responsibility_id INT UNSIGNED NULL,
    supervisor_person_id INT UNSIGNED NOT NULL,
    responsible_person_id INT UNSIGNED NULL,
    oversight_status VARCHAR(30) NOT NULL DEFAULT 'open',
    findings TEXT NULL,
    evidence_notes TEXT NULL,
    evidence_ref VARCHAR(190) NULL,
    outcome VARCHAR(30) NULL,
    outcome_notes TEXT NULL,
    due_date DATE NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_oversight_decision (decision_id),
    KEY idx_gov_oversight_task (task_id),
    KEY idx_gov_oversight_responsibility (responsibility_id),
    KEY idx_gov_oversight_supervisor (supervisor_person_id),
    KEY idx_gov_oversight_responsible (responsible_person_id),
    KEY idx_gov_oversight_status (oversight_status),
    KEY idx_gov_oversight_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- 3. audit
-- Append-only. No updated_at column by design: audit rows are never updated.
-- resource_id NULL is valid (e.g. permission registry browsing events).
CREATE TABLE IF NOT EXISTS gov_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor VARCHAR(190) NOT NULL,
    action VARCHAR(100) NOT NULL,
    resource VARCHAR(100) NOT NULL,
    resource_id INT UNSIGNED NULL,
    result VARCHAR(30) NOT NULL DEFAULT 'success',
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_audit_resource (resource, resource_id),
    KEY idx_gov_audit_actor (actor),
    KEY idx_gov_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
