-- MOS-GOV V0.2 — Governance identity, scope, permission and visibility model.
--
-- Migration policy (V0.2 constraint #37):
--   - additive only: CREATE TABLE IF NOT EXISTS / INSERT ... WHERE NOT EXISTS
--   - no DROP, no TRUNCATE, no DELETE, no ALTER of any existing gov_* table
--   - idempotent and safe to re-run
--   - ChurchCRM core tables are never touched
--
-- The nine new tables sit ON TOP of the ten V0.1 governance tables:
--   gov_identity          one governance identity per ChurchCRM person
--   gov_identity_role     which governance role an identity currently holds
--   gov_scope             whitelisted scope registry (global..person)
--   gov_role_scope        theoretical scope a role is defined for
--   gov_identity_scope    actual scope granted to one identity
--   gov_permission        atomic permission whitelist registry
--   gov_role_permission   default permissions of a role
--   gov_identity_permission  explicit per-person grant/deny override
--   gov_visibility_rule   information-level visibility rules (P1..P5)

-- ---------------------------------------------------------------- 1. identity
CREATE TABLE IF NOT EXISTS gov_identity (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    person_id INT UNSIGNED NOT NULL,
    identity_status VARCHAR(30) NOT NULL DEFAULT 'active',
    member_since DATE NULL,
    display_name_override VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gov_identity_person (person_id),
    KEY idx_gov_identity_status (identity_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- identity <-> role assignment (lifecycle driven by gov_appointment)
CREATE TABLE IF NOT EXISTS gov_identity_role (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    start_date DATE NULL,
    end_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_identity_role_identity (identity_id),
    KEY idx_gov_identity_role_role (role_id),
    KEY idx_gov_identity_role_appointment (appointment_id),
    KEY idx_gov_identity_role_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- 3. scope
-- scope_type is a FIXED whitelist. User input can never introduce a new type;
-- the application layer validates against GovRepository::SCOPE_TYPES and the
-- (scope_type, scope_id) pair is unique. scope_id is NULL only for the two
-- types that address the whole system (global, church).
CREATE TABLE IF NOT EXISTS gov_scope (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type VARCHAR(30) NOT NULL,
    scope_id INT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gov_scope_ref (scope_type, scope_id),
    KEY idx_gov_scope_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- theoretical scope of a role
CREATE TABLE IF NOT EXISTS gov_role_scope (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NOT NULL,
    scope_type VARCHAR(30) NOT NULL,
    scope_id INT UNSIGNED NULL,
    scope_mode VARCHAR(20) NOT NULL DEFAULT 'direct',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_role_scope_role (role_id),
    KEY idx_gov_role_scope_ref (scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- actual scope of one identity
CREATE TABLE IF NOT EXISTS gov_identity_scope (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_id INT UNSIGNED NOT NULL,
    scope_type VARCHAR(30) NOT NULL,
    scope_id INT UNSIGNED NULL,
    source_type VARCHAR(30) NOT NULL DEFAULT 'manual_assignment',
    source_id INT UNSIGNED NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_identity_scope_identity (identity_id),
    KEY idx_gov_identity_scope_ref (scope_type, scope_id),
    KEY idx_gov_identity_scope_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ 6. permission
-- The permission key whitelist. Rows are seeded below; the application layer
-- refuses to create permission keys outside GovRepository::PERMISSIONS.
CREATE TABLE IF NOT EXISTS gov_permission (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(100) NOT NULL,
    resource_type VARCHAR(40) NOT NULL,
    action VARCHAR(30) NOT NULL,
    description VARCHAR(255) NULL,
    risk_level VARCHAR(20) NOT NULL DEFAULT 'low',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gov_permission_key (permission_key),
    KEY idx_gov_permission_resource (resource_type, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- default permissions of a role
CREATE TABLE IF NOT EXISTS gov_role_permission (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    grant_mode VARCHAR(20) NOT NULL DEFAULT 'allow',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gov_role_permission (role_id, permission_id),
    KEY idx_gov_role_permission_perm (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- explicit per-identity grant/deny override (special cases only).
-- A deny always wins over any grant; overrides can never bypass the
-- system security boundary (P5 default deny, scope containment).
CREATE TABLE IF NOT EXISTS gov_identity_permission (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    grant_mode VARCHAR(20) NOT NULL DEFAULT 'grant',
    start_date DATE NULL,
    end_date DATE NULL,
    reason VARCHAR(255) NULL,
    authorized_by INT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_identity_permission_identity (identity_id),
    KEY idx_gov_identity_permission_perm (permission_id),
    KEY idx_gov_identity_permission_mode (grant_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 9. visibility
CREATE TABLE IF NOT EXISTS gov_visibility_rule (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_type VARCHAR(40) NOT NULL,
    information_level VARCHAR(10) NOT NULL,
    role_id INT UNSIGNED NULL,
    scope_type VARCHAR(30) NULL,
    action VARCHAR(30) NOT NULL DEFAULT 'view',
    rule_type VARCHAR(20) NOT NULL DEFAULT 'allow',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gov_visibility_lookup (resource_type, information_level, role_id, action),
    KEY idx_gov_visibility_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================ SEED DATA ====
-- All inserts are guarded (WHERE NOT EXISTS) so the migration is idempotent.

-- System scope registry roots (global and church carry no numeric id).
INSERT INTO gov_scope (scope_type, scope_id, name, description)
SELECT 'global', NULL, 'Global (entire system)', 'Whole-system governance scope. Restricted to explicit grants only.'
WHERE NOT EXISTS (SELECT 1 FROM gov_scope WHERE scope_type = 'global' AND scope_id IS NULL);

INSERT INTO gov_scope (scope_type, scope_id, name, description)
SELECT 'church', NULL, 'Church (single congregation)', 'The local congregation as a whole.'
WHERE NOT EXISTS (SELECT 1 FROM gov_scope WHERE scope_type = 'church' AND scope_id IS NULL);

-- Permission whitelist (section 12 of the V0.2 design). Inserted per key.
INSERT INTO gov_permission (permission_key, resource_type, action, description, risk_level)
SELECT k.permission_key, k.resource_type, k.action, k.description, k.risk_level
FROM (
    SELECT 'governance.view'      AS permission_key, 'governance' AS resource_type, 'view'    AS action, 'View the governance module' AS description, 'low'    AS risk_level
    UNION ALL SELECT 'governance.create',  'governance', 'create',  'Create governance records',  'medium'
    UNION ALL SELECT 'governance.edit',    'governance', 'edit',    'Edit governance records',    'medium'
    UNION ALL SELECT 'governance.submit',  'governance', 'submit',  'Submit governance records for review', 'medium'
    UNION ALL SELECT 'governance.approve', 'governance', 'approve', 'Approve governance records', 'high'
    UNION ALL SELECT 'governance.publish', 'governance', 'publish', 'Publish governance records', 'high'
    UNION ALL SELECT 'governance.close',   'governance', 'close',   'Close governance records',   'medium'
    UNION ALL SELECT 'governance.export',  'governance', 'export',  'Export governance data',     'high'
    UNION ALL SELECT 'governance.feedback','governance', 'feedback','Give governance feedback',   'low'
    UNION ALL SELECT 'governance.manage',  'governance', 'manage',  'Manage governance operations','high'
    UNION ALL SELECT 'meeting.view',       'meeting',    'view',    'View meetings',              'low'
    UNION ALL SELECT 'meeting.create',     'meeting',    'create',  'Create meetings',            'medium'
    UNION ALL SELECT 'meeting.edit',       'meeting',    'edit',    'Edit meetings',              'medium'
    UNION ALL SELECT 'meeting.submit',     'meeting',    'submit',  'Submit meeting minutes',     'medium'
    UNION ALL SELECT 'meeting.export',     'meeting',    'export',  'Export meeting records',     'high'
    UNION ALL SELECT 'issue.view',         'issue',      'view',    'View issues',                'low'
    UNION ALL SELECT 'issue.create',       'issue',      'create',  'Raise issues',               'medium'
    UNION ALL SELECT 'issue.edit',         'issue',      'edit',    'Edit issues',                'medium'
    UNION ALL SELECT 'decision.view',      'decision',   'view',    'View decisions',             'low'
    UNION ALL SELECT 'decision.create',    'decision',   'create',  'Draft decisions',            'medium'
    UNION ALL SELECT 'decision.edit',      'decision',   'edit',    'Edit decisions',             'medium'
    UNION ALL SELECT 'decision.approve',   'decision',   'approve', 'Approve decisions',          'high'
    UNION ALL SELECT 'decision.publish',   'decision',   'publish', 'Publish decisions',          'high'
    UNION ALL SELECT 'task.view',          'task',       'view',    'View tasks',                 'low'
    UNION ALL SELECT 'task.create',        'task',       'create',  'Create tasks',               'medium'
    UNION ALL SELECT 'task.edit',          'task',       'edit',    'Edit tasks',                 'medium'
    UNION ALL SELECT 'task.close',         'task',       'close',   'Close tasks',                'medium'
    UNION ALL SELECT 'identity.view',      'identity',   'view',    'View governance identities', 'medium'
    UNION ALL SELECT 'identity.edit',      'identity',   'edit',    'Edit governance identities', 'high'
    UNION ALL SELECT 'role.view',          'role',       'view',    'View governance roles',      'low'
    UNION ALL SELECT 'role.manage',        'role',       'manage',  'Define and manage governance roles', 'high'
    UNION ALL SELECT 'appointment.view',   'appointment','view',    'View appointments',          'low'
    UNION ALL SELECT 'appointment.create', 'appointment','create',  'Create appointments',        'high'
    UNION ALL SELECT 'appointment.end',    'appointment','end',     'End appointments',           'high'
    UNION ALL SELECT 'permission.manage',  'permission', 'manage',  'Manage the permission registry', 'critical'
) AS k
WHERE NOT EXISTS (
    SELECT 1 FROM gov_permission p WHERE p.permission_key = k.permission_key
);

-- ------------------------------------------------- system role definitions
-- The ten V0.1 governance roles live under a body; the V0.2 system roles
-- (A01..E01) are seeded under a dedicated system structure + body so the
-- existing gov_role model stays untouched. Guarded + idempotent.
INSERT INTO gov_structure (name, code, description)
SELECT 'MOS-GOV System', 'MOS-GOV-SYSTEM', 'System-owned structure holding the V0.2 governance role definitions. Managed by migrations.'
WHERE NOT EXISTS (SELECT 1 FROM gov_structure WHERE code = 'MOS-GOV-SYSTEM');

INSERT INTO gov_body (structure_id, name, body_type, description)
SELECT s.id, 'Governance Role Registry', 'system', 'Container for the V0.2 system role definitions (A01..E01).'
FROM gov_structure s
WHERE s.code = 'MOS-GOV-SYSTEM'
  AND NOT EXISTS (SELECT 1 FROM gov_body b WHERE b.structure_id = s.id AND b.body_type = 'system');

INSERT INTO gov_role (body_id, name, role_code, description)
SELECT b.id, r.name, r.role_code, r.description
FROM gov_body b
JOIN (
    SELECT 'A01' AS role_code, 'General Member'        AS name, '普通会员: P1/P2 view, P3 own related, P4/P5 deny' AS description
    UNION ALL SELECT 'A02', 'Activity Participant',    '活动参与者: P1/P2 view, P3 assigned activity, P4/P5 deny'
    UNION ALL SELECT 'B01', 'Small Group Leader',      '小组负责人: P3 group scope, P4 limited, P5 deny'
    UNION ALL SELECT 'B02', 'Ministry Leader',         '事工负责人: P3 ministry scope, P4 limited, P5 deny'
    UNION ALL SELECT 'B03', 'Finance Worker',          '财务同工: P3 financial scope, P4 financial governance, P5 limited'
    UNION ALL SELECT 'C01', 'Governance Secretary',    '治理秘书: P3 related, P4 assigned governance body, P5 limited'
    UNION ALL SELECT 'D01', 'Deacon',                  '执事: P3 assigned, P4 assigned governance, P5 limited'
    UNION ALL SELECT 'D02', 'Elder',                   '长老: P3 assigned/broad per appointment, P4 broad per scope, P5 explicit authorization'
    UNION ALL SELECT 'D03', 'Pastor',                  '牧者: P3 pastoral/ministry scope, P4 broad per scope, P5 explicit authorization'
    UNION ALL SELECT 'E01', 'Governance Administrator','治理管理员: system administration only; NOT church governance authority'
) r ON r.role_code IS NOT NULL
JOIN gov_structure st ON st.code = 'MOS-GOV-SYSTEM'
WHERE b.body_type = 'system' AND b.structure_id = st.id
  AND NOT EXISTS (SELECT 1 FROM gov_role gr WHERE gr.body_id = b.id AND gr.role_code = r.role_code);

-- ---------------------------------------------------- default role scopes
-- scope_mode = direct; actual per-person scope still comes from
-- gov_identity_scope / gov_appointment (least-privilege principle).
-- A01/A02 deliberately get NO template scope: their P3 visibility is
-- "own related" only (own tasks, own identity) — never the whole church.
-- B01/B02 are bound per person via gov_identity_scope (group/ministry).
INSERT INTO gov_role_scope (role_id, scope_type, scope_id, scope_mode)
SELECT gr.id, rs.scope_type, rs.scope_id, 'direct'
FROM gov_role gr
JOIN gov_body b ON b.id = gr.body_id AND b.body_type = 'system'
JOIN (
    SELECT 'B01' AS role_code, 'group' AS scope_type, NULL AS scope_id
    UNION ALL SELECT 'B02', 'ministry', NULL
    UNION ALL SELECT 'B03', 'church', NULL
    UNION ALL SELECT 'C01', 'church', NULL
    UNION ALL SELECT 'D01', 'church', NULL
    UNION ALL SELECT 'D02', 'church', NULL
    UNION ALL SELECT 'D03', 'church', NULL
    UNION ALL SELECT 'E01', 'global', NULL
) rs
WHERE gr.role_code = rs.role_code
  AND NOT EXISTS (
      SELECT 1 FROM gov_role_scope x
      WHERE x.role_id = gr.id AND x.scope_type = rs.scope_type
        AND ((x.scope_id IS NULL AND rs.scope_id IS NULL) OR x.scope_id = rs.scope_id)
  );

-- --------------------------------------------------- default role permissions
-- Section 40 of the V0.2 design. View permissions only within scope; the
-- ScopeResolver still constrains every query to the identity's own scope.
INSERT INTO gov_role_permission (role_id, permission_id, grant_mode)
SELECT gr.id, p.id, 'allow'
FROM gov_role gr
JOIN gov_body b ON b.id = gr.body_id AND b.body_type = 'system'
JOIN (
    SELECT 'A01' AS role_code, 'governance.view' AS permission_key
    UNION ALL SELECT 'A01', 'meeting.view'
    UNION ALL SELECT 'A01', 'issue.view'
    UNION ALL SELECT 'A01', 'decision.view'
    UNION ALL SELECT 'A01', 'task.view'
    UNION ALL SELECT 'A01', 'identity.view'

    UNION ALL SELECT 'A02', 'governance.view'
    UNION ALL SELECT 'A02', 'meeting.view'
    UNION ALL SELECT 'A02', 'issue.view'
    UNION ALL SELECT 'A02', 'decision.view'
    UNION ALL SELECT 'A02', 'task.view'
    UNION ALL SELECT 'A02', 'identity.view'

    UNION ALL SELECT 'B01', 'governance.view'
    UNION ALL SELECT 'B01', 'meeting.view'
    UNION ALL SELECT 'B01', 'meeting.create'
    UNION ALL SELECT 'B01', 'meeting.edit'
    UNION ALL SELECT 'B01', 'issue.view'
    UNION ALL SELECT 'B01', 'issue.create'
    UNION ALL SELECT 'B01', 'issue.edit'
    UNION ALL SELECT 'B01', 'decision.view'
    UNION ALL SELECT 'B01', 'task.view'
    UNION ALL SELECT 'B01', 'task.create'
    UNION ALL SELECT 'B01', 'task.edit'
    UNION ALL SELECT 'B01', 'task.close'
    UNION ALL SELECT 'B01', 'appointment.view'
    UNION ALL SELECT 'B01', 'identity.view'

    UNION ALL SELECT 'B02', 'governance.view'
    UNION ALL SELECT 'B02', 'meeting.view'
    UNION ALL SELECT 'B02', 'meeting.create'
    UNION ALL SELECT 'B02', 'meeting.edit'
    UNION ALL SELECT 'B02', 'issue.view'
    UNION ALL SELECT 'B02', 'issue.create'
    UNION ALL SELECT 'B02', 'issue.edit'
    UNION ALL SELECT 'B02', 'decision.view'
    UNION ALL SELECT 'B02', 'task.view'
    UNION ALL SELECT 'B02', 'task.create'
    UNION ALL SELECT 'B02', 'task.edit'
    UNION ALL SELECT 'B02', 'task.close'
    UNION ALL SELECT 'B02', 'appointment.view'
    UNION ALL SELECT 'B02', 'identity.view'

    UNION ALL SELECT 'B03', 'governance.view'
    UNION ALL SELECT 'B03', 'governance.export'
    UNION ALL SELECT 'B03', 'meeting.view'
    UNION ALL SELECT 'B03', 'issue.view'
    UNION ALL SELECT 'B03', 'decision.view'
    UNION ALL SELECT 'B03', 'task.view'
    UNION ALL SELECT 'B03', 'identity.view'

    UNION ALL SELECT 'C01', 'governance.view'
    UNION ALL SELECT 'C01', 'governance.submit'
    UNION ALL SELECT 'C01', 'meeting.view'
    UNION ALL SELECT 'C01', 'meeting.create'
    UNION ALL SELECT 'C01', 'meeting.edit'
    UNION ALL SELECT 'C01', 'meeting.submit'
    UNION ALL SELECT 'C01', 'issue.view'
    UNION ALL SELECT 'C01', 'issue.create'
    UNION ALL SELECT 'C01', 'issue.edit'
    UNION ALL SELECT 'C01', 'decision.view'
    UNION ALL SELECT 'C01', 'decision.create'
    UNION ALL SELECT 'C01', 'decision.edit'
    UNION ALL SELECT 'C01', 'task.view'
    UNION ALL SELECT 'C01', 'task.create'
    UNION ALL SELECT 'C01', 'task.edit'
    UNION ALL SELECT 'C01', 'task.close'
    UNION ALL SELECT 'C01', 'appointment.view'
    UNION ALL SELECT 'C01', 'identity.view'

    UNION ALL SELECT 'D01', 'governance.view'
    UNION ALL SELECT 'D01', 'meeting.view'
    UNION ALL SELECT 'D01', 'meeting.create'
    UNION ALL SELECT 'D01', 'meeting.edit'
    UNION ALL SELECT 'D01', 'issue.view'
    UNION ALL SELECT 'D01', 'issue.create'
    UNION ALL SELECT 'D01', 'issue.edit'
    UNION ALL SELECT 'D01', 'decision.view'
    UNION ALL SELECT 'D01', 'decision.create'
    UNION ALL SELECT 'D01', 'decision.edit'
    UNION ALL SELECT 'D01', 'task.view'
    UNION ALL SELECT 'D01', 'task.create'
    UNION ALL SELECT 'D01', 'task.edit'
    UNION ALL SELECT 'D01', 'task.close'
    UNION ALL SELECT 'D01', 'appointment.view'
    UNION ALL SELECT 'D01', 'identity.view'

    UNION ALL SELECT 'D02', 'governance.view'
    UNION ALL SELECT 'D02', 'governance.approve'
    UNION ALL SELECT 'D02', 'governance.publish'
    UNION ALL SELECT 'D02', 'governance.close'
    UNION ALL SELECT 'D02', 'meeting.view'
    UNION ALL SELECT 'D02', 'meeting.create'
    UNION ALL SELECT 'D02', 'meeting.edit'
    UNION ALL SELECT 'D02', 'meeting.submit'
    UNION ALL SELECT 'D02', 'issue.view'
    UNION ALL SELECT 'D02', 'issue.create'
    UNION ALL SELECT 'D02', 'issue.edit'
    UNION ALL SELECT 'D02', 'decision.view'
    UNION ALL SELECT 'D02', 'decision.create'
    UNION ALL SELECT 'D02', 'decision.edit'
    UNION ALL SELECT 'D02', 'decision.approve'
    UNION ALL SELECT 'D02', 'decision.publish'
    UNION ALL SELECT 'D02', 'task.view'
    UNION ALL SELECT 'D02', 'task.create'
    UNION ALL SELECT 'D02', 'task.edit'
    UNION ALL SELECT 'D02', 'task.close'
    UNION ALL SELECT 'D02', 'appointment.view'
    UNION ALL SELECT 'D02', 'identity.view'

    UNION ALL SELECT 'D03', 'governance.view'
    UNION ALL SELECT 'D03', 'governance.approve'
    UNION ALL SELECT 'D03', 'governance.publish'
    UNION ALL SELECT 'D03', 'governance.close'
    UNION ALL SELECT 'D03', 'meeting.view'
    UNION ALL SELECT 'D03', 'meeting.create'
    UNION ALL SELECT 'D03', 'meeting.edit'
    UNION ALL SELECT 'D03', 'meeting.submit'
    UNION ALL SELECT 'D03', 'issue.view'
    UNION ALL SELECT 'D03', 'issue.create'
    UNION ALL SELECT 'D03', 'issue.edit'
    UNION ALL SELECT 'D03', 'decision.view'
    UNION ALL SELECT 'D03', 'decision.create'
    UNION ALL SELECT 'D03', 'decision.edit'
    UNION ALL SELECT 'D03', 'decision.approve'
    UNION ALL SELECT 'D03', 'decision.publish'
    UNION ALL SELECT 'D03', 'task.view'
    UNION ALL SELECT 'D03', 'task.create'
    UNION ALL SELECT 'D03', 'task.edit'
    UNION ALL SELECT 'D03', 'task.close'
    UNION ALL SELECT 'D03', 'appointment.view'
    UNION ALL SELECT 'D03', 'identity.view'

    UNION ALL SELECT 'E01', 'governance.view'
    UNION ALL SELECT 'E01', 'governance.manage'
    UNION ALL SELECT 'E01', 'governance.export'
    UNION ALL SELECT 'E01', 'identity.view'
    UNION ALL SELECT 'E01', 'identity.edit'
    UNION ALL SELECT 'E01', 'role.view'
    UNION ALL SELECT 'E01', 'role.manage'
    UNION ALL SELECT 'E01', 'appointment.view'
    UNION ALL SELECT 'E01', 'appointment.create'
    UNION ALL SELECT 'E01', 'appointment.end'
    UNION ALL SELECT 'E01', 'permission.manage'
    UNION ALL SELECT 'E01', 'meeting.view'
    UNION ALL SELECT 'E01', 'issue.view'
    UNION ALL SELECT 'E01', 'decision.view'
    UNION ALL SELECT 'E01', 'task.view'
) rp
JOIN gov_permission p ON p.permission_key = rp.permission_key
WHERE gr.role_code = rp.role_code
  AND NOT EXISTS (
      SELECT 1 FROM gov_role_permission x
      WHERE x.role_id = gr.id AND x.permission_id = p.id
  );

-- ------------------------------------------------------- visibility rules
-- Baseline model (information level defaults; P5 = DENY by default for
-- everyone — no P5 allow row is seeded anywhere in the system):
--   role_id NULL  -> applies to every role
--   P1/P2 view    -> allowed for everyone
--   P3 view       -> allowed only inside the identity's own scope
--                    (scope containment is enforced by ScopeResolver, so
--                    this allow row never widens the scope itself)
--   P4 view       -> allowed for governance roles B01+, still scope-bound
--   P5 view       -> explicit per-identity permission only
INSERT INTO gov_visibility_rule (resource_type, information_level, role_id, scope_type, action, rule_type)
SELECT v.resource_type, v.information_level, v.role_id, v.scope_type, v.action, v.rule_type
FROM (
    SELECT '*' AS resource_type, 'P1' AS information_level, NULL AS role_id, NULL AS scope_type, 'view' AS action, 'allow' AS rule_type
    UNION ALL SELECT '*', 'P2', NULL, NULL, 'view', 'allow'
    UNION ALL SELECT '*', 'P3', NULL, NULL, 'view', 'allow'
    UNION ALL SELECT '*', 'P4', NULL, NULL, 'view', 'allow'
    UNION ALL SELECT '*', 'P5', NULL, NULL, 'view', 'deny'
    UNION ALL SELECT '*', 'P5', NULL, NULL, 'export', 'deny'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM gov_visibility_rule x
    WHERE x.resource_type = v.resource_type
      AND x.information_level = v.information_level
      AND x.role_id <=> v.role_id
      AND x.action = v.action
      AND x.rule_type = v.rule_type
);
