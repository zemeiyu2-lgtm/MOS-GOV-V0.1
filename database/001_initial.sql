-- MOS-GOV V0.1
-- Governance-owned schema.
-- IMPORTANT: ChurchCRM core tables are deliberately not altered here.
-- Cross-system references are integer IDs only and are resolved through
-- ChurchCRM at application level.

CREATE TABLE IF NOT EXISTS gov_structure (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(80) NULL,
    description TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    parent_id INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_structure_parent (parent_id),
    KEY idx_gov_structure_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_body (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    structure_id INT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    body_type VARCHAR(50) NULL,
    description TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_body_structure (structure_id),
    KEY idx_gov_body_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_role (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    body_id INT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    role_code VARCHAR(80) NULL,
    description TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_role_body (body_id),
    KEY idx_gov_role_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_appointment (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    appointed_by_person_id INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_appointment_role (role_id),
    KEY idx_gov_appointment_person (person_id),
    KEY idx_gov_appointment_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_responsibility (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    priority VARCHAR(30) NOT NULL DEFAULT 'normal',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_responsibility_role (role_id),
    KEY idx_gov_responsibility_appointment (appointment_id),
    KEY idx_gov_responsibility_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_relationship (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_type VARCHAR(50) NOT NULL,
    from_id INT UNSIGNED NOT NULL,
    relationship_type VARCHAR(80) NOT NULL,
    to_type VARCHAR(50) NOT NULL,
    to_id INT UNSIGNED NOT NULL,
    description TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_relationship_from (from_type, from_id),
    KEY idx_gov_relationship_to (to_type, to_id),
    KEY idx_gov_relationship_type (relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_meeting (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    body_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    meeting_date DATETIME NULL,
    location VARCHAR(190) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    minutes TEXT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_meeting_body (body_id),
    KEY idx_gov_meeting_event (event_id),
    KEY idx_gov_meeting_date (meeting_date),
    KEY idx_gov_meeting_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_issue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    body_id INT UNSIGNED NULL,
    meeting_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    priority VARCHAR(30) NOT NULL DEFAULT 'normal',
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    owner_person_id INT UNSIGNED NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_issue_body (body_id),
    KEY idx_gov_issue_meeting (meeting_id),
    KEY idx_gov_issue_owner (owner_person_id),
    KEY idx_gov_issue_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_decision (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    issue_id INT UNSIGNED NULL,
    meeting_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    decision_text TEXT NOT NULL,
    decision_status VARCHAR(30) NOT NULL DEFAULT 'approved',
    decided_at DATETIME NULL,
    decided_by_person_id INT UNSIGNED NULL,
    review_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_decision_issue (issue_id),
    KEY idx_gov_decision_meeting (meeting_id),
    KEY idx_gov_decision_status (decision_status),
    KEY idx_gov_decision_review (review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gov_task (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    decision_id INT UNSIGNED NULL,
    responsibility_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    assignee_person_id INT UNSIGNED NULL,
    due_date DATE NULL,
    priority VARCHAR(30) NOT NULL DEFAULT 'normal',
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_task_decision (decision_id),
    KEY idx_gov_task_responsibility (responsibility_id),
    KEY idx_gov_task_assignee (assignee_person_id),
    KEY idx_gov_task_due (due_date),
    KEY idx_gov_task_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
