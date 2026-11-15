-- ============================================================================
-- MOS-GOV Phase 3 — per-church (tenant) physical-isolation boundary
--
-- HONEST DESIGN NOTE: ChurchCRM 7.8.0 is single-congregation. This migration
-- does NOT turn it into shared-schema SaaS multi-tenancy. Each church gets its
-- OWN database, OWN least-privilege DB account, OWN vhost / FPM pool and OWN
-- private storage root (see deploy/scripts/provision-tenant.sh). The tables
-- below make a misrouted vhost or a wrong env file FAIL CLOSED at runtime:
-- every evidence row and every stored file is stamped with the tenant id read
-- from the environment, and the database refuses a mismatched stamp.
--
-- Additive only: no existing table is altered.
-- ============================================================================

CREATE TABLE IF NOT EXISTS gov_tenant (
    singleton   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    tenant_id   VARCHAR(32)  NOT NULL,
    tenant_db   VARCHAR(48)  NOT NULL,
    host        VARCHAR(253) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    status      ENUM('active','suspended','provisioning') NOT NULL DEFAULT 'provisioning',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (singleton),
    UNIQUE KEY uq_gov_tenant_id (tenant_id),
    UNIQUE KEY uq_gov_tenant_host (host),
    CONSTRAINT chk_gov_tenant_singleton CHECK (singleton = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private attachment registry. Files live OUTSIDE the web root in a
-- tenant-specific directory; Apache never serves them directly. Downloads go
-- through an authenticated endpoint that validates this registry + tenant.
CREATE TABLE IF NOT EXISTS gov_tenant_file (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     VARCHAR(32)  NOT NULL,
    object_key    VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    byte_size     BIGINT UNSIGNED NOT NULL,
    sha256        CHAR(64)     NOT NULL,
    uploaded_by   VARCHAR(190) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status        ENUM('active','quarantined','deleted') NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY uq_gov_tenant_file_key (tenant_id, object_key),
    KEY idx_gov_tenant_file_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS gov_tenant_no_delete;
DROP TRIGGER IF EXISTS gov_tenant_no_identity_change;
DROP TRIGGER IF EXISTS gov_evidence_tenant_guard;
DROP TRIGGER IF EXISTS gov_tenant_file_tenant_guard;

DELIMITER $$
CREATE TRIGGER gov_tenant_no_delete
BEFORE DELETE ON gov_tenant
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'tenant boundary row cannot be deleted';
END$$
CREATE TRIGGER gov_tenant_no_identity_change
BEFORE UPDATE ON gov_tenant
FOR EACH ROW
BEGIN
    IF NEW.tenant_id <> OLD.tenant_id OR NEW.tenant_db <> OLD.tenant_db OR NEW.singleton <> OLD.singleton THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'tenant identity is immutable (status/display_name may change only)';
    END IF;
END$$
CREATE TRIGGER gov_evidence_tenant_guard
BEFORE INSERT ON gov_evidence_log
FOR EACH ROW
BEGIN
    DECLARE v_tenant VARCHAR(32);
    SELECT tenant_id INTO v_tenant FROM gov_tenant WHERE singleton = 1;
    IF v_tenant IS NULL OR NEW.tenant_id <> v_tenant THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'evidence tenant_id does not match this database tenant';
    END IF;
END$$
CREATE TRIGGER gov_tenant_file_tenant_guard
BEFORE INSERT ON gov_tenant_file
FOR EACH ROW
BEGIN
    DECLARE v_tenant VARCHAR(32);
    SELECT tenant_id INTO v_tenant FROM gov_tenant WHERE singleton = 1;
    IF v_tenant IS NULL OR NEW.tenant_id <> v_tenant THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'file tenant_id does not match this database tenant';
    END IF;
END$$
DELIMITER ;

-- provision-tenant.sh seeds exactly one row, then flips status to 'active':
--   INSERT INTO gov_tenant (...) VALUES (1, 'church-a', 'mosgov_church_a', ...);
--   UPDATE gov_tenant SET status = 'active' WHERE singleton = 1;
