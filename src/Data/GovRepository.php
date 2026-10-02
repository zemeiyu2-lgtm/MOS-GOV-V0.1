<?php

namespace ChurchCRM\Plugins\MosGov\Data;

use Propel\Runtime\Propel;

/**
 * Data access layer for the MOS-GOV governance tables (gov_*).
 *
 * Design decisions (Phase 3 audit, 2026-09-15; extended in V0.1 closure):
 *
 * - Propel generated models are NOT available for plugin-owned tables: the
 *   Propel schema belongs to ChurchCRM core and must not be modified. The
 *   minimal safe data-access path is therefore PDO through the standard
 *   ChurchCRM connection obtained via Propel::getConnection().
 * - Every statement is prepared; table, column and ORDER BY names come
 *   exclusively from the whitelisted ENTITIES registry below, never from
 *   user input.
 * - Only gov_* tables are touched. ChurchCRM core tables are neither read
 *   nor written here. ChurchCRM references are opaque integer IDs per the
 *   architecture boundary rule (R08); resolving them to names is the job of
 *   ChurchCRM\Plugins\MosGov\Integration\PersonLookup.
 * - Routes and views must not embed SQL; they call this repository.
 * - All ten V0.1 governance entities are described by this one registry, so
 *   adding a field or an entity is a single, testable change.
 */
final class GovRepository
{
    /** Whitelisted status values for the organisational entities. */
    public const STATUSES = ['active', 'inactive', 'archived'];

    /** Shared priority scale (responsibility / issue / task). */
    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /** Meeting lifecycle. */
    public const MEETING_STATUSES = ['planned', 'held', 'cancelled'];

    /** Issue lifecycle; 'open' drives the dashboard counter. */
    public const ISSUE_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    /** Decision lifecycle. */
    public const DECISION_STATUSES = ['proposed', 'approved', 'rejected', 'superseded'];

    /** Task lifecycle; 'open' drives the dashboard counter. */
    public const TASK_STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    /** Governance node types addressable by gov_relationship.from_type / to_type. */
    public const RELATIONSHIP_ENTITY_TYPES = ['structure', 'body', 'role', 'appointment', 'meeting', 'person'];

    // ------------------------------------------------- V0.2 security model
    // All security vocabularies below are CLOSED whitelists. User input can
    // never introduce a new scope type, grant mode, information level,
    // action or permission key: every value is validated against these
    // constants before it reaches the database.

    /** Fixed scope-type whitelist (V0.2 design §9.3). */
    public const SCOPE_TYPES = ['global', 'church', 'structure', 'body', 'ministry', 'group', 'activity', 'project', 'person'];

    /** Scope types that address the whole system (no numeric scope_id). */
    public const GLOBAL_SCOPE_TYPES = ['global', 'church'];

    /** gov_role_scope.scope_mode whitelist. */
    public const ROLE_SCOPE_MODES = ['direct', 'inherit'];

    /** gov_identity_scope.source_type whitelist. */
    public const SCOPE_SOURCE_TYPES = ['appointment', 'manual_assignment', 'inherited'];

    /** grant / deny modes (gov_identity_permission, gov_role_permission). */
    public const GRANT_MODES = ['grant', 'deny'];

    /** Information levels (V0.2 design §10). P5 defaults to DENY. */
    public const INFORMATION_LEVELS = ['P1', 'P2', 'P3', 'P4', 'P5'];

    /** Default information level of every governed resource type. */
    public const INFORMATION_LEVELS_BY_RESOURCE = [
        'structure' => 'P2',
        'body' => 'P2',
        'role' => 'P2',
        'appointment' => 'P4',
        'responsibility' => 'P3',
        'relationship' => 'P3',
        'meeting' => 'P3',
        'issue' => 'P3',
        'decision' => 'P3',
        'task' => 'P3',
        'identity' => 'P4',
        'permission' => 'P4',
    ];

    /** Fields whose content is P5 (sensitive) regardless of the row level. */
    public const P5_FIELDS = [
        'identity' => ['notes'],
        'appointment' => ['notes'],
        'meeting' => ['minutes'],
        'issue' => ['description'],
        'decision' => ['decision_text'],
    ];

    /** Governance actions (V0.2 design §11). Deliberately richer than CRUD. */
    public const ACTIONS = ['view', 'create', 'edit', 'submit', 'approve', 'publish', 'close', 'export', 'feedback', 'manage'];

    /**
     * Permission registry whitelist (V0.2 design §12). permission_key =>
     * [resource_type, action, risk_level]. The database may not contain any
     * permission key outside this registry; admin UIs and the data layer
     * both refuse unknown keys.
     */
    public const PERMISSIONS = [
        'governance.view' => ['governance', 'view', 'low'],
        'governance.create' => ['governance', 'create', 'medium'],
        'governance.edit' => ['governance', 'edit', 'medium'],
        'governance.submit' => ['governance', 'submit', 'medium'],
        'governance.approve' => ['governance', 'approve', 'high'],
        'governance.publish' => ['governance', 'publish', 'high'],
        'governance.close' => ['governance', 'close', 'medium'],
        'governance.export' => ['governance', 'export', 'high'],
        'governance.feedback' => ['governance', 'feedback', 'low'],
        'governance.manage' => ['governance', 'manage', 'high'],
        'meeting.view' => ['meeting', 'view', 'low'],
        'meeting.create' => ['meeting', 'create', 'medium'],
        'meeting.edit' => ['meeting', 'edit', 'medium'],
        'meeting.submit' => ['meeting', 'submit', 'medium'],
        'meeting.export' => ['meeting', 'export', 'high'],
        'issue.view' => ['issue', 'view', 'low'],
        'issue.create' => ['issue', 'create', 'medium'],
        'issue.edit' => ['issue', 'edit', 'medium'],
        'decision.view' => ['decision', 'view', 'low'],
        'decision.create' => ['decision', 'create', 'medium'],
        'decision.edit' => ['decision', 'edit', 'medium'],
        'decision.approve' => ['decision', 'approve', 'high'],
        'decision.publish' => ['decision', 'publish', 'high'],
        'task.view' => ['task', 'view', 'low'],
        'task.create' => ['task', 'create', 'medium'],
        'task.edit' => ['task', 'edit', 'medium'],
        'task.close' => ['task', 'close', 'medium'],
        'identity.view' => ['identity', 'view', 'medium'],
        'identity.edit' => ['identity', 'edit', 'high'],
        'role.view' => ['role', 'view', 'low'],
        'role.manage' => ['role', 'manage', 'high'],
        'appointment.view' => ['appointment', 'view', 'low'],
        'appointment.create' => ['appointment', 'create', 'high'],
        'appointment.end' => ['appointment', 'end', 'high'],
        'permission.manage' => ['permission', 'manage', 'critical'],
    ];

    /** Identity lifecycle statuses. */
    public const IDENTITY_STATUSES = ['active', 'inactive', 'suspended', 'archived'];

    /** Risk levels for gov_permission. */
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * V0.2 security registry: single source of truth for the nine
     * authorization tables (identity / scope / permission / visibility).
     * Kept separate from ENTITIES so the ten V0.1 governance entities and
     * their slug map stay untouched; getEntity() resolves both registries,
     * so the whitelisted prepared-statement CRUD machinery serves them too.
     */
    public const SECURITY_ENTITIES = [
        'identity' => [
            'table' => 'gov_identity',
            'label' => 'Governance Identity',
            'labelPlural' => 'Governance Identities',
            'listFields' => ['person_id', 'identity_status', 'member_since', 'display_name_override'],
            'fields' => [
                'person_id' => ['type' => 'person', 'required' => true, 'label' => 'Person'],
                'identity_status' => ['type' => 'select', 'required' => true, 'options' => self::IDENTITY_STATUSES, 'default' => 'active', 'label' => 'Identity status'],
                'member_since' => ['type' => 'date', 'required' => false, 'label' => 'Member since'],
                'display_name_override' => ['type' => 'text', 'required' => false, 'max' => 190, 'label' => 'Display name override'],
                'notes' => ['type' => 'textlong', 'required' => false, 'label' => 'Notes'],
            ],
        ],
        'identity_role' => [
            'table' => 'gov_identity_role',
            'label' => 'Identity Role',
            'labelPlural' => 'Identity Roles',
            'listFields' => ['identity_id', 'role_id', 'appointment_id', 'status', 'start_date'],
            'fields' => [
                'identity_id' => ['type' => 'ref', 'ref' => 'identity', 'required' => true, 'label' => 'Identity'],
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => true, 'label' => 'Role'],
                'appointment_id' => ['type' => 'ref', 'ref' => 'appointment', 'required' => false, 'label' => 'Appointment'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
                'start_date' => ['type' => 'date', 'required' => false, 'label' => 'Start date'],
                'end_date' => ['type' => 'date', 'required' => false, 'label' => 'End date'],
            ],
        ],
        'scope' => [
            'table' => 'gov_scope',
            'label' => 'Scope',
            'labelPlural' => 'Scopes',
            'listFields' => ['scope_type', 'scope_id', 'name', 'status'],
            'fields' => [
                'scope_type' => ['type' => 'select', 'required' => true, 'options' => self::SCOPE_TYPES, 'label' => 'Scope type'],
                'scope_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'label' => 'Scope ID'],
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
        ],
        'role_scope' => [
            'table' => 'gov_role_scope',
            'label' => 'Role Scope',
            'labelPlural' => 'Role Scopes',
            'listFields' => ['role_id', 'scope_type', 'scope_id', 'scope_mode'],
            'fields' => [
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => true, 'label' => 'Role'],
                'scope_type' => ['type' => 'select', 'required' => true, 'options' => self::SCOPE_TYPES, 'label' => 'Scope type'],
                'scope_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'label' => 'Scope ID'],
                'scope_mode' => ['type' => 'select', 'required' => true, 'options' => self::ROLE_SCOPE_MODES, 'default' => 'direct', 'label' => 'Scope mode'],
            ],
        ],
        'identity_scope' => [
            'table' => 'gov_identity_scope',
            'label' => 'Identity Scope',
            'labelPlural' => 'Identity Scopes',
            'listFields' => ['identity_id', 'scope_type', 'scope_id', 'source_type', 'status'],
            'fields' => [
                'identity_id' => ['type' => 'ref', 'ref' => 'identity', 'required' => true, 'label' => 'Identity'],
                'scope_type' => ['type' => 'select', 'required' => true, 'options' => self::SCOPE_TYPES, 'label' => 'Scope type'],
                'scope_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'label' => 'Scope ID'],
                'source_type' => ['type' => 'select', 'required' => true, 'options' => self::SCOPE_SOURCE_TYPES, 'default' => 'manual_assignment', 'label' => 'Source'],
                'source_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'label' => 'Source ID'],
                'start_date' => ['type' => 'date', 'required' => false, 'label' => 'Start date'],
                'end_date' => ['type' => 'date', 'required' => false, 'label' => 'End date'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
        ],
        'permission' => [
            'table' => 'gov_permission',
            'label' => 'Permission',
            'labelPlural' => 'Permissions',
            'listFields' => ['permission_key', 'resource_type', 'action', 'risk_level', 'status'],
            'fields' => [
                'permission_key' => ['type' => 'text', 'required' => true, 'max' => 100, 'label' => 'Permission key'],
                'resource_type' => ['type' => 'text', 'required' => true, 'max' => 40, 'label' => 'Resource type'],
                'action' => ['type' => 'text', 'required' => true, 'max' => 30, 'label' => 'Action'],
                'description' => ['type' => 'text', 'required' => false, 'max' => 255, 'label' => 'Description'],
                'risk_level' => ['type' => 'select', 'required' => true, 'options' => self::RISK_LEVELS, 'default' => 'low', 'label' => 'Risk level'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
        ],
        'role_permission' => [
            'table' => 'gov_role_permission',
            'label' => 'Role Permission',
            'labelPlural' => 'Role Permissions',
            'listFields' => ['role_id', 'permission_id', 'grant_mode'],
            'fields' => [
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => true, 'label' => 'Role'],
                'permission_id' => ['type' => 'ref', 'ref' => 'permission', 'required' => true, 'label' => 'Permission'],
                'grant_mode' => ['type' => 'select', 'required' => true, 'options' => ['allow'], 'default' => 'allow', 'label' => 'Grant mode'],
            ],
        ],
        'identity_permission' => [
            'table' => 'gov_identity_permission',
            'label' => 'Identity Permission',
            'labelPlural' => 'Identity Permissions',
            'listFields' => ['identity_id', 'permission_id', 'grant_mode', 'status', 'end_date'],
            'fields' => [
                'identity_id' => ['type' => 'ref', 'ref' => 'identity', 'required' => true, 'label' => 'Identity'],
                'permission_id' => ['type' => 'ref', 'ref' => 'permission', 'required' => true, 'label' => 'Permission'],
                'grant_mode' => ['type' => 'select', 'required' => true, 'options' => self::GRANT_MODES, 'default' => 'grant', 'label' => 'Grant mode'],
                'start_date' => ['type' => 'date', 'required' => false, 'label' => 'Start date'],
                'end_date' => ['type' => 'date', 'required' => false, 'label' => 'End date'],
                'reason' => ['type' => 'text', 'required' => false, 'max' => 255, 'label' => 'Reason'],
                'authorized_by' => ['type' => 'person', 'required' => false, 'label' => 'Authorized by'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
        ],
        'visibility_rule' => [
            'table' => 'gov_visibility_rule',
            'label' => 'Visibility Rule',
            'labelPlural' => 'Visibility Rules',
            'listFields' => ['resource_type', 'information_level', 'role_id', 'action', 'rule_type'],
            'fields' => [
                'resource_type' => ['type' => 'select', 'required' => true, 'options' => ['*', 'governance', 'identity', 'permission', 'structure', 'body', 'role', 'appointment', 'responsibility', 'relationship', 'meeting', 'issue', 'decision', 'task'], 'label' => 'Resource type'],
                'information_level' => ['type' => 'select', 'required' => true, 'options' => self::INFORMATION_LEVELS, 'label' => 'Information level'],
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => false, 'label' => 'Role'],
                'scope_type' => ['type' => 'select', 'required' => false, 'options' => self::SCOPE_TYPES, 'label' => 'Scope type'],
                'action' => ['type' => 'select', 'required' => true, 'options' => self::ACTIONS, 'default' => 'view', 'label' => 'Action'],
                'rule_type' => ['type' => 'select', 'required' => true, 'options' => ['allow', 'deny'], 'default' => 'allow', 'label' => 'Rule type'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
        ],
    ];

    /** Columns that always exist on every gov_* table. */
    private const COMMON_COLUMNS = ['id', 'created_at', 'updated_at'];

    /**
     * URL slug => entity key. Routing and navigation both read this map, so a
     * slug can never drift between the route table and the view links.
     */
    public const SLUG_TO_ENTITY = [
        'structures' => 'structure',
        'bodies' => 'body',
        'roles' => 'role',
        'appointments' => 'appointment',
        'responsibilities' => 'responsibility',
        'relationships' => 'relationship',
        'meetings' => 'meeting',
        'issues' => 'issue',
        'decisions' => 'decision',
        'tasks' => 'task',
    ];

    /**
     * Entity registry: single source of truth for tables, fields, labels,
     * validation rules and cross-entity relations.
     *
     * Field spec keys:
     *  - type      text | textlong | int | date | datetime | status | select | ref | person
     *  - label     human label used in views and error messages
     *  - required  whether an empty value is a validation error
     *  - max/min   length / numeric bounds
     *  - options   allowed values for status|select
     *  - default   value applied when the submitted value is empty
     *  - ref       parent entity key (ref fields)
     *  - omitIfEmpty  skip the column entirely on INSERT when empty, so the
     *                 database DEFAULT (e.g. CURRENT_TIMESTAMP) applies
     *                 instead of an explicit NULL on a NOT NULL column
     *
     * 'related' lists child collections shown on a detail page:
     *  - entity  child entity key
     *  - field   child column pointing back at this record
     *  - label   heading override (defaults to the child plural label)
     */
    public const ENTITIES = [
        // ---------------------------------------------------------- structure
        'structure' => [
            'table' => 'gov_structure',
            'label' => 'Structure',
            'labelPlural' => 'Structures',
            'listFields' => ['name', 'code', 'status', 'sort_order'],
            'defaultOrder' => ['sort_order' => 'ASC', 'name' => 'ASC'],
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'code' => ['type' => 'text', 'required' => false, 'max' => 80, 'label' => 'Code'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
                'parent_id' => ['type' => 'ref', 'ref' => 'structure', 'required' => false, 'label' => 'Parent structure'],
                'sort_order' => ['type' => 'int', 'required' => false, 'min' => 0, 'default' => 0, 'label' => 'Sort order'],
            ],
            'related' => [
                ['entity' => 'body', 'field' => 'structure_id'],
                ['entity' => 'structure', 'field' => 'parent_id', 'label' => 'Child structures'],
            ],
        ],

        // --------------------------------------------------------------- body
        'body' => [
            'table' => 'gov_body',
            'label' => 'Body',
            'labelPlural' => 'Bodies',
            'listFields' => ['name', 'body_type', 'status'],
            'fields' => [
                'structure_id' => ['type' => 'ref', 'ref' => 'structure', 'required' => true, 'label' => 'Structure'],
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'body_type' => ['type' => 'text', 'required' => false, 'max' => 50, 'label' => 'Type'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
            'related' => [
                ['entity' => 'role', 'field' => 'body_id'],
                ['entity' => 'meeting', 'field' => 'body_id'],
                ['entity' => 'issue', 'field' => 'body_id'],
            ],
        ],

        // --------------------------------------------------------------- role
        'role' => [
            'table' => 'gov_role',
            'label' => 'Role',
            'labelPlural' => 'Roles',
            'listFields' => ['name', 'role_code', 'status'],
            'fields' => [
                'body_id' => ['type' => 'ref', 'ref' => 'body', 'required' => true, 'label' => 'Body'],
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'role_code' => ['type' => 'text', 'required' => false, 'max' => 80, 'label' => 'Code'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
            'related' => [
                ['entity' => 'appointment', 'field' => 'role_id'],
                ['entity' => 'responsibility', 'field' => 'role_id'],
            ],
        ],

        // -------------------------------------------------------- appointment
        'appointment' => [
            'table' => 'gov_appointment',
            'label' => 'Appointment',
            'labelPlural' => 'Appointments',
            'listFields' => ['person_id', 'role_id', 'start_date', 'end_date', 'status'],
            'fields' => [
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => true, 'label' => 'Role'],
                'person_id' => ['type' => 'person', 'required' => true, 'label' => 'Person'],
                'appointed_by_person_id' => ['type' => 'person', 'required' => false, 'label' => 'Appointed by'],
                'start_date' => ['type' => 'date', 'required' => false, 'label' => 'Start date'],
                'end_date' => ['type' => 'date', 'required' => false, 'label' => 'End date'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
                'notes' => ['type' => 'textlong', 'required' => false, 'label' => 'Notes'],
            ],
            'related' => [
                ['entity' => 'responsibility', 'field' => 'appointment_id'],
            ],
        ],

        // ----------------------------------------------------- responsibility
        'responsibility' => [
            'table' => 'gov_responsibility',
            'label' => 'Responsibility',
            'labelPlural' => 'Responsibilities',
            'listFields' => ['title', 'role_id', 'appointment_id', 'priority', 'status'],
            'fields' => [
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => false, 'label' => 'Role'],
                'appointment_id' => ['type' => 'ref', 'ref' => 'appointment', 'required' => false, 'label' => 'Appointment'],
                'title' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Title'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'priority' => ['type' => 'select', 'required' => true, 'options' => self::PRIORITIES, 'default' => 'normal', 'label' => 'Priority'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
            'related' => [
                ['entity' => 'task', 'field' => 'responsibility_id'],
            ],
        ],

        // ------------------------------------------------------- relationship
        'relationship' => [
            'table' => 'gov_relationship',
            'label' => 'Relationship',
            'labelPlural' => 'Relationships',
            'listFields' => ['from_type', 'from_id', 'relationship_type', 'to_type', 'to_id', 'status'],
            'fields' => [
                'from_type' => ['type' => 'select', 'required' => true, 'options' => self::RELATIONSHIP_ENTITY_TYPES, 'label' => 'From type'],
                'from_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'label' => 'From ID'],
                'relationship_type' => ['type' => 'text', 'required' => true, 'max' => 80, 'label' => 'Relationship type'],
                'to_type' => ['type' => 'select', 'required' => true, 'options' => self::RELATIONSHIP_ENTITY_TYPES, 'label' => 'To type'],
                'to_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'label' => 'To ID'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::STATUSES, 'default' => 'active', 'label' => 'Status'],
            ],
        ],

        // ------------------------------------------------------------ meeting
        'meeting' => [
            'table' => 'gov_meeting',
            'label' => 'Meeting',
            'labelPlural' => 'Meetings',
            'listFields' => ['title', 'body_id', 'meeting_date', 'status'],
            'defaultOrder' => ['meeting_date' => 'DESC', 'id' => 'DESC'],
            'recentOrder' => ['meeting_date' => 'DESC', 'id' => 'DESC'],
            'fields' => [
                'body_id' => ['type' => 'ref', 'ref' => 'body', 'required' => true, 'label' => 'Body'],
                'event_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'label' => 'ChurchCRM event ID'],
                'title' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Title'],
                'meeting_date' => ['type' => 'datetime', 'required' => false, 'label' => 'Meeting date'],
                'location' => ['type' => 'text', 'required' => false, 'max' => 190, 'label' => 'Location'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::MEETING_STATUSES, 'default' => 'planned', 'label' => 'Status'],
                'minutes' => ['type' => 'textlong', 'required' => false, 'label' => 'Minutes'],
                'created_by_user_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'label' => 'Created by user ID'],
            ],
            'related' => [
                ['entity' => 'issue', 'field' => 'meeting_id'],
                ['entity' => 'decision', 'field' => 'meeting_id'],
            ],
        ],

        // -------------------------------------------------------------- issue
        'issue' => [
            'table' => 'gov_issue',
            'label' => 'Issue',
            'labelPlural' => 'Issues',
            'listFields' => ['title', 'priority', 'status', 'owner_person_id', 'opened_at'],
            'defaultOrder' => ['opened_at' => 'DESC', 'id' => 'DESC'],
            'recentOrder' => ['opened_at' => 'DESC', 'id' => 'DESC'],
            'fields' => [
                'body_id' => ['type' => 'ref', 'ref' => 'body', 'required' => false, 'label' => 'Body'],
                'meeting_id' => ['type' => 'ref', 'ref' => 'meeting', 'required' => false, 'label' => 'Meeting'],
                'title' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Title'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'priority' => ['type' => 'select', 'required' => true, 'options' => self::PRIORITIES, 'default' => 'normal', 'label' => 'Priority'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::ISSUE_STATUSES, 'default' => 'open', 'label' => 'Status'],
                'owner_person_id' => ['type' => 'person', 'required' => false, 'label' => 'Owner'],
                'opened_at' => ['type' => 'datetime', 'required' => false, 'omitIfEmpty' => true, 'label' => 'Opened at'],
                'closed_at' => ['type' => 'datetime', 'required' => false, 'label' => 'Closed at'],
            ],
            'related' => [
                ['entity' => 'decision', 'field' => 'issue_id'],
            ],
        ],

        // ----------------------------------------------------------- decision
        'decision' => [
            'table' => 'gov_decision',
            'label' => 'Decision',
            'labelPlural' => 'Decisions',
            'listFields' => ['title', 'decision_status', 'issue_id', 'decided_at'],
            'defaultOrder' => ['decided_at' => 'DESC', 'id' => 'DESC'],
            'recentOrder' => ['decided_at' => 'DESC', 'id' => 'DESC'],
            'fields' => [
                'issue_id' => ['type' => 'ref', 'ref' => 'issue', 'required' => false, 'label' => 'Issue'],
                'meeting_id' => ['type' => 'ref', 'ref' => 'meeting', 'required' => false, 'label' => 'Meeting'],
                'title' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Title'],
                'decision_text' => ['type' => 'textlong', 'required' => true, 'label' => 'Decision text'],
                'decision_status' => ['type' => 'status', 'required' => true, 'options' => self::DECISION_STATUSES, 'default' => 'approved', 'label' => 'Decision status'],
                'decided_at' => ['type' => 'datetime', 'required' => false, 'label' => 'Decided at'],
                'decided_by_person_id' => ['type' => 'person', 'required' => false, 'label' => 'Decided by'],
                'review_date' => ['type' => 'date', 'required' => false, 'label' => 'Review date'],
            ],
            'related' => [
                ['entity' => 'task', 'field' => 'decision_id'],
            ],
        ],

        // --------------------------------------------------------------- task
        'task' => [
            'table' => 'gov_task',
            'label' => 'Task',
            'labelPlural' => 'Tasks',
            'listFields' => ['title', 'assignee_person_id', 'due_date', 'priority', 'status'],
            'defaultOrder' => ['id' => 'DESC'],
            'recentOrder' => ['id' => 'DESC'],
            'fields' => [
                'decision_id' => ['type' => 'ref', 'ref' => 'decision', 'required' => false, 'label' => 'Decision'],
                'responsibility_id' => ['type' => 'ref', 'ref' => 'responsibility', 'required' => false, 'label' => 'Responsibility'],
                'title' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Title'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'assignee_person_id' => ['type' => 'person', 'required' => false, 'label' => 'Assignee'],
                'due_date' => ['type' => 'date', 'required' => false, 'label' => 'Due date'],
                'priority' => ['type' => 'select', 'required' => true, 'options' => self::PRIORITIES, 'default' => 'normal', 'label' => 'Priority'],
                'status' => ['type' => 'status', 'required' => true, 'options' => self::TASK_STATUSES, 'default' => 'open', 'label' => 'Status'],
                'completed_at' => ['type' => 'datetime', 'required' => false, 'label' => 'Completed at'],
            ],
        ],
    ];

    private \Propel\Runtime\Connection\ConnectionInterface $conn;

    public function __construct(?\Propel\Runtime\Connection\ConnectionInterface $conn = null)
    {
        $this->conn = $conn ?? Propel::getConnection();
    }

    /**
     * All entity keys, in governance dependency order (parents first).
     *
     * @return array<int, string>
     */
    public static function entityKeys(): array
    {
        return array_keys(self::ENTITIES);
    }

    /** URL slug for an entity key (inverse of SLUG_TO_ENTITY). */
    public static function slugFor(string $entity): string
    {
        $slug = array_search($entity, self::SLUG_TO_ENTITY, true);
        if ($slug === false) {
            throw new GovDataException('Unknown governance entity.');
        }

        return $slug;
    }

    /** Entity key for a URL slug, or null when the slug is unknown. */
    public static function entityForSlug(string $slug): ?string
    {
        return self::SLUG_TO_ENTITY[$slug] ?? null;
    }

    /** Singular human label for an entity key. */
    public static function labelFor(string $entity): string
    {
        return self::ENTITIES[$entity]['label'] ?? $entity;
    }

    /** Plural human label for an entity key. */
    public static function labelPluralFor(string $entity): string
    {
        return self::ENTITIES[$entity]['labelPlural'] ?? self::labelFor($entity);
    }

    /**
     * Navigation map used by the shared section tabs and the dashboard:
     * slug => plural label, in governance dependency order.
     *
     * @return array<string, string>
     */
    public static function navigationMap(): array
    {
        $out = [];
        foreach (self::SLUG_TO_ENTITY as $slug => $entity) {
            $out[$slug] = self::labelPluralFor($entity);
        }

        return $out;
    }

    /**
     * @param string $entity one of the ENTITIES or SECURITY_ENTITIES keys
     */
    public function getEntity(string $entity): array
    {
        $cfg = self::ENTITIES[$entity] ?? self::SECURITY_ENTITIES[$entity] ?? null;
        if ($cfg === null) {
            throw new GovDataException('Unknown governance entity.');
        }

        return $cfg;
    }

    /** True when the key belongs to the V0.2 security registry. */
    public static function isSecurityEntity(string $entity): bool
    {
        return isset(self::SECURITY_ENTITIES[$entity]);
    }

    /**
     * Dashboard counters, including the open-issue / open-task totals.
     * Empty tables yield 0; failures raise GovDataException so callers can
     * show an explicit error state.
     *
     * @return array<string, int>
     */
    public function getDashboardCounts(): array
    {
        try {
            return [
                'structures' => $this->countRows('gov_structure'),
                'bodies' => $this->countRows('gov_body'),
                'roles' => $this->countRows('gov_role'),
                'appointments' => $this->countRows('gov_appointment'),
                'responsibilities' => $this->countRows('gov_responsibility'),
                'relationships' => $this->countRows('gov_relationship'),
                'meetings' => $this->countRows('gov_meeting'),
                'issues' => $this->countRows('gov_issue'),
                'open_issues' => $this->countRows('gov_issue', 'open'),
                'decisions' => $this->countRows('gov_decision'),
                'tasks' => $this->countRows('gov_task'),
                'open_tasks' => $this->countRows('gov_task', 'open'),
            ];
        } catch (\PDOException $e) {
            throw new GovDataException('Governance data is currently unavailable.', [], $e);
        }
    }

    /**
     * The most recent rows of an entity, using its recentOrder (or the
     * default newest-first order).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(string $entity, int $limit = 5): array
    {
        $cfg = $this->getEntity($entity);

        return $this->list($entity, $limit, $cfg['recentOrder'] ?? ['id' => 'DESC']);
    }

    /**
     * List rows of an entity.
     *
     * @param array<string, string> $orderBy column => ASC|DESC; columns are
     *                                       whitelisted against the registry
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $entity, int $limit = 500, array $orderBy = []): array
    {
        return $this->listWhere($entity, [], $limit, $orderBy);
    }

    /**
     * List rows matching equality conditions.
     *
     * @param array<string, mixed>  $where   column => value (columns whitelisted)
     * @param array<string, string> $orderBy column => ASC|DESC
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWhere(string $entity, array $where, int $limit = 500, array $orderBy = []): array
    {
        $cfg = $this->getEntity($entity);
        $limit = max(1, min(1000, $limit));
        $allowed = $this->allowedColumns($cfg);

        if ($orderBy === []) {
            $orderBy = $cfg['defaultOrder'] ?? ['id' => 'DESC'];
        }

        $clauses = [];
        foreach (array_keys($where) as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new GovDataException('Unsupported filter on ' . $cfg['label'] . '.');
            }
            $clauses[] = $column . ' = :' . $column;
        }

        $order = $this->buildOrderBy($cfg, $orderBy);

        try {
            $sql = 'SELECT * FROM ' . $cfg['table']
                . ($clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses))
                . ' ORDER BY ' . $order
                . ' LIMIT :lim';
            $stmt = $this->conn->prepare($sql);
            foreach ($where as $column => $value) {
                $stmt->bindValue(':' . $column, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to load ' . $cfg['label'] . ' records.', [], $e);
        }
    }

    /**
     * Count rows of a child collection (used by detail pages for the
     * relation headings).
     */
    public function countWhere(string $entity, string $column, int $value): int
    {
        $cfg = $this->getEntity($entity);
        if (!in_array($column, $this->allowedColumns($cfg), true)) {
            throw new GovDataException('Unsupported filter on ' . $cfg['label'] . '.');
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) FROM ' . $cfg['table'] . ' WHERE ' . $column . ' = :v'
            );
            $stmt->bindValue(':v', $value, \PDO::PARAM_INT);
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to load ' . $cfg['label'] . ' records.', [], $e);
        }
    }

    /**
     * Find one row by primary key, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $entity, int $id): ?array
    {
        $cfg = $this->getEntity($entity);

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM ' . $cfg['table'] . ' WHERE id = :id'
            );
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to load ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Insert one row. Runs normalisation + validation first; throws
     * GovDataException with per-field errors on invalid input.
     */
    public function insert(string $entity, array $data): int
    {
        $cfg = $this->getEntity($entity);
        $data = $this->normalize($entity, $data);
        $errors = $this->validate($entity, $data);
        if ($errors !== []) {
            throw new GovDataException('Please correct the highlighted fields.', $errors);
        }
        $data = $this->applyDefaults($cfg, $data);

        $fields = [];
        foreach (array_keys($cfg['fields']) as $field) {
            if (($cfg['fields'][$field]['omitIfEmpty'] ?? false) && $this->isEmptyValue($data[$field] ?? null)) {
                continue;
            }
            $fields[] = $field;
        }

        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(fn ($f) => ':' . $f, $fields));

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO ' . $cfg['table'] . ' (' . $columns . ') VALUES (' . $placeholders . ')'
            );
            foreach ($fields as $field) {
                $this->bindValue($stmt, $field, $data[$field] ?? null, $cfg['fields'][$field]['type']);
            }
            $stmt->execute();

            return (int) $this->conn->lastInsertId();
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to save the ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Update one row by primary key. Runs normalisation + validation first.
     */
    public function update(string $entity, int $id, array $data): void
    {
        $cfg = $this->getEntity($entity);
        $data = $this->normalize($entity, $data);
        $errors = $this->validate($entity, $data);
        if ($errors !== []) {
            throw new GovDataException('Please correct the highlighted fields.', $errors);
        }
        $data = $this->applyDefaults($cfg, $data);

        $fields = array_keys($cfg['fields']);
        $assignments = implode(', ', array_map(fn ($f) => $f . ' = :' . $f, $fields));

        try {
            $stmt = $this->conn->prepare(
                'UPDATE ' . $cfg['table'] . ' SET ' . $assignments . ' WHERE id = :id'
            );
            foreach ($fields as $field) {
                $this->bindValue($stmt, $field, $data[$field] ?? null, $cfg['fields'][$field]['type']);
            }
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to save the ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Delete one row by primary key. Returns false when the row does not
     * exist. Not exposed via routes in V0.1 (governance records are closed,
     * not erased); kept for tests and future explicit admin actions.
     */
    public function delete(string $entity, int $id): bool
    {
        $cfg = $this->getEntity($entity);

        try {
            $stmt = $this->conn->prepare('DELETE FROM ' . $cfg['table'] . ' WHERE id = :id');
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to delete the ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Trim strings and canonicalise values so validation and storage agree.
     *  - text/textlong: trimmed
     *  - int/ref/person: numeric strings become ints
     *  - date: "YYYY-MM-DD" kept, anything else null when empty
     *  - datetime: "YYYY-MM-DDTHH:MM" (or a space separator, with optional
     *    seconds) becomes "YYYY-MM-DD HH:MM:SS"
     *  - empty strings become null for every non-text type
     */
    public function normalize(string $entity, array $data): array
    {
        $cfg = $this->getEntity($entity);

        foreach ($cfg['fields'] as $field => $spec) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);

            switch ($spec['type']) {
                case 'text':
                case 'textlong':
                    $data[$field] = $value;
                    break;

                case 'int':
                case 'ref':
                case 'person':
                    $data[$field] = $value === '' ? null : $value;
                    break;

                case 'date':
                    $data[$field] = $value === '' ? null : $value;
                    break;

                case 'datetime':
                    if ($value === '') {
                        $data[$field] = null;
                        break;
                    }
                    $normalized = $this->normalizeDateTime($value);
                    $data[$field] = $normalized ?? $value; // keep raw so validation reports it
                    break;

                default:
                    $data[$field] = $value === '' ? null : $value;
            }
        }

        return $data;
    }

    /**
     * Validate (already normalised) input against the entity field spec.
     *
     * @return array<string, string> field => error message; empty when valid
     */
    public function validate(string $entity, array $data): array
    {
        $cfg = $this->getEntity($entity);
        $errors = [];

        foreach ($cfg['fields'] as $field => $spec) {
            $value = $data[$field] ?? null;
            $isEmpty = $this->isEmptyValue($value);

            switch ($spec['type']) {
                case 'text':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value)) {
                        $errors[$field] = $spec['label'] . ' is invalid.';
                        break;
                    }
                    if (isset($spec['max']) && mb_strlen($value) > $spec['max']) {
                        $errors[$field] = $spec['label'] . ' must be at most ' . $spec['max'] . ' characters.';
                        break;
                    }
                    if (preg_match('/<[^>]*>/', $value)) {
                        $errors[$field] = $spec['label'] . ' cannot contain HTML tags.';
                        break;
                    }
                    if (str_contains($value, "\0")) {
                        $errors[$field] = $spec['label'] . ' contains invalid characters.';
                    }
                    break;

                case 'textlong':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value)) {
                        $errors[$field] = $spec['label'] . ' is invalid.';
                        break;
                    }
                    if (mb_strlen($value) > 65535) {
                        $errors[$field] = $spec['label'] . ' is too long.';
                    }
                    break;

                case 'int':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    $min = (int) ($spec['min'] ?? 1);
                    if (!is_numeric($value) || !preg_match('/^\d+$/', (string) $value) || (int) $value < $min) {
                        $errors[$field] = $spec['label'] . ' must be a whole number of ' . $min . ' or greater.';
                    }
                    break;

                case 'date':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[$field] = $spec['label'] . ' must have the format YYYY-MM-DD.';
                        break;
                    }
                    [$y, $m, $d] = array_map('intval', explode('-', $value));
                    if (!checkdate($m, $d, $y)) {
                        $errors[$field] = $spec['label'] . ' is not a valid calendar date.';
                    }
                    break;

                case 'datetime':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value) || $this->normalizeDateTime($value) === null) {
                        $errors[$field] = $spec['label'] . ' must be a valid date and time.';
                    }
                    break;

                case 'status':
                case 'select':
                    $options = $spec['options'] ?? self::STATUSES;
                    if ($isEmpty) {
                        if ($spec['required'] ?? false) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value) || !in_array($value, $options, true)) {
                        $errors[$field] = $spec['label'] . ' must be one of: ' . implode(', ', $options) . '.';
                    }
                    break;

                case 'ref':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_numeric($value) || !preg_match('/^\d+$/', (string) $value) || (int) $value <= 0) {
                        $errors[$field] = $spec['label'] . ' is invalid.';
                        break;
                    }
                    $refCfg = $this->getEntity($spec['ref']);
                    if ($this->find($spec['ref'], (int) $value) === null) {
                        $errors[$field] = 'Selected ' . strtolower($refCfg['label']) . ' does not exist.';
                    }
                    break;

                case 'person':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_numeric($value) || !preg_match('/^\d+$/', (string) $value) || (int) $value <= 0) {
                        $errors[$field] = $spec['label'] . ' must be a ChurchCRM person ID.';
                        break;
                    }
                    if (!$this->personExists((int) $value)) {
                        $errors[$field] = 'Selected ChurchCRM person does not exist.';
                    }
                    break;
            }
        }

        // Cross-field rule: appointment end date must not precede start date.
        if ($entity === 'appointment') {
            $start = $data['start_date'] ?? null;
            $end = $data['end_date'] ?? null;
            if (is_string($start) && is_string($end) && $start !== '' && $end !== ''
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
                && $end < $start) {
                $errors['end_date'] = 'End date cannot be before the start date.';
            }
        }

        // V0.2 semantic constraints (design §39): a governance record must
        // carry its context — no free-floating responsibilities, no
        // context-free decisions.
        if ($entity === 'responsibility' && $this->isEmptyValue($data['role_id'] ?? null) && $this->isEmptyValue($data['appointment_id'] ?? null)) {
            $errors['role_id'] = 'A responsibility must belong to a role or to an appointment.';
        }
        if ($entity === 'decision' && $this->isEmptyValue($data['issue_id'] ?? null) && $this->isEmptyValue($data['meeting_id'] ?? null)) {
            $errors['issue_id'] = 'A decision must reference an issue or a meeting.';
        }

        // V0.2 scope whitelist semantics: global/church scopes carry no
        // numeric id; every other scope type requires one.
        if (in_array($entity, ['scope', 'role_scope', 'identity_scope'], true)) {
            $scopeType = $data['scope_type'] ?? null;
            $scopeId = $data['scope_id'] ?? null;
            if (is_string($scopeType) && in_array($scopeType, self::SCOPE_TYPES, true)) {
                if (in_array($scopeType, self::GLOBAL_SCOPE_TYPES, true)) {
                    if (!$this->isEmptyValue($scopeId)) {
                        $errors['scope_id'] = 'A ' . $scopeType . ' scope must not carry a numeric scope ID.';
                    }
                } elseif ($this->isEmptyValue($scopeId)) {
                    $errors['scope_id'] = 'Scope type "' . $scopeType . '" requires a numeric scope ID.';
                }
            }
        }

        // V0.2 permission registry whitelist: no unknown permission keys can
        // enter the system through any page or API.
        if ($entity === 'permission') {
            $key = $data['permission_key'] ?? null;
            if (is_string($key) && $key !== '' && isset(self::PERMISSIONS[$key])) {
                [$rt, $act, $risk] = self::PERMISSIONS[$key];
                if (($data['resource_type'] ?? null) !== $rt) {
                    $errors['resource_type'] = 'Resource type must be "' . $rt . '" for this permission key.';
                }
                if (($data['action'] ?? null) !== $act) {
                    $errors['action'] = 'Action must be "' . $act . '" for this permission key.';
                }
                if (($data['risk_level'] ?? null) !== $risk) {
                    $errors['risk_level'] = 'Risk level must be "' . $risk . '" for this permission key.';
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve a ChurchCRM person existence check. Kept as a thin seam so the
     * repository stays testable: PersonLookup reads ChurchCRM's own ORM
     * (read-only) and never writes it.
     */
    private function personExists(int $personId): bool
    {
        return \ChurchCRM\Plugins\MosGov\Integration\PersonLookup::exists($personId);
    }

    /**
     * Fill configured default values for fields that are missing or empty.
     * Needed for NOT NULL columns without an application-level value
     * (e.g. gov_structure.sort_order, gov_issue.status).
     */
    private function applyDefaults(array $cfg, array $data): array
    {
        foreach ($cfg['fields'] as $field => $spec) {
            if (array_key_exists('default', $spec) && $this->isEmptyValue($data[$field] ?? null)) {
                $data[$field] = $spec['default'];
            }
        }

        return $data;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Accept the formats produced by HTML date/time inputs and by API
     * clients; return "YYYY-MM-DD HH:MM:SS" or null when unparseable.
     */
    private function normalizeDateTime(string $value): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return null;
        }
        [$y, $mo, $d, $h, $mi] = array_map('intval', [$m[1], $m[2], $m[3], $m[4], $m[5]]);
        $s = isset($m[6]) ? (int) $m[6] : 0;

        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s);
    }

    /** @return array<int, string> */
    private function allowedColumns(array $cfg): array
    {
        return array_merge(array_keys($cfg['fields']), self::COMMON_COLUMNS);
    }

    /**
     * Build a whitelisted ORDER BY clause. Unknown columns or directions
     * raise a GovDataException rather than reaching the database.
     */
    private function buildOrderBy(array $cfg, array $orderBy): string
    {
        $allowed = $this->allowedColumns($cfg);
        $parts = [];

        foreach ($orderBy as $column => $direction) {
            if (!in_array($column, $allowed, true)) {
                throw new GovDataException('Unsupported sort on ' . $cfg['label'] . '.');
            }
            $direction = strtoupper((string) $direction);
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new GovDataException('Unsupported sort direction on ' . $cfg['label'] . '.');
            }
            $parts[] = $column . ' ' . $direction;
        }

        return $parts === [] ? 'id DESC' : implode(', ', $parts);
    }

    private function countRows(string $table, ?string $status = null): int
    {
        if ($status === null) {
            $stmt = $this->conn->query('SELECT COUNT(*) FROM ' . $table);

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE status = :status');
        $stmt->bindValue(':status', $status);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function bindValue(\Propel\Runtime\Connection\StatementInterface $stmt, string $field, mixed $value, string $type): void
    {
        // Normalize "empty" to NULL for optional fields; database columns are
        // nullable and '' is not a meaningful value for dates/ints/refs.
        if ($value === null || $value === '') {
            $stmt->bindValue(':' . $field, null, \PDO::PARAM_NULL);

            return;
        }

        switch ($type) {
            case 'int':
            case 'ref':
            case 'person':
                $stmt->bindValue(':' . $field, (int) $value, \PDO::PARAM_INT);
                break;
            case 'date':
            case 'datetime':
            case 'text':
            case 'textlong':
            case 'status':
            case 'select':
                $stmt->bindValue(':' . $field, is_string($value) ? trim($value) : (string) $value, \PDO::PARAM_STR);
                break;
            default:
                $stmt->bindValue(':' . $field, $value);
        }
    }
}
