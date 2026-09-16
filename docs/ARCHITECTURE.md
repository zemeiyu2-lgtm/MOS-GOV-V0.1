# MOS-GOV V0.1 Architecture

## Layer model

```text
ChurchCRM  (source of church facts: people, families, groups, events, users)
  │
  │  person_id / group_id / event_id / user_id
  ▼
MOS-GOV  (governance interpretation and operational layer)
  │
  ├─ structure ──┐
  ├─ body        │ organisational loop
  ├─ role        │
  ├─ appointment │
  ├─ responsibility
  ├─ relationship
  ├─ meeting ────┐
  ├─ issue        │ operational loop
  ├─ decision     │
  └─ task ───────┘
```

## Boundary rule

ChurchCRM is the factual church data layer.

MOS-GOV is the governance interpretation and operational layer.

MOS-GOV must not create parallel authoritative records for ChurchCRM people,
groups, events or users.

Enforcement in code:

- `src/Integration/PersonLookup.php` is the only place that touches ChurchCRM
  people, and it only *reads* them, through ChurchCRM's own ORM
  (`PersonQuery`). It does not write, cache or duplicate person facts.
- `src/Data/GovRepository.php` only ever touches `gov_*` tables.
- Governance tables store ChurchCRM IDs as opaque integers; there are no
  FOREIGN KEYs to core tables, so a core schema change cannot break MOS-GOV
  and MOS-GOV cannot constrain core data.

## Entity relations

| Child | Reference | Parent |
| --- | --- | --- |
| `body` | `structure_id` | `structure` |
| `structure` | `parent_id` | `structure` |
| `role` | `body_id` | `body` |
| `appointment` | `role_id`, `person_id`, `appointed_by_person_id` | `role`, ChurchCRM person |
| `responsibility` | `role_id`, `appointment_id` | `role`, `appointment` |
| `relationship` | `from_type`/`from_id`, `to_type`/`to_id` | any governance node or person |
| `meeting` | `body_id`, `event_id` | `body`, ChurchCRM event |
| `issue` | `body_id`, `meeting_id`, `owner_person_id` | `body`, `meeting`, ChurchCRM person |
| `decision` | `issue_id`, `meeting_id`, `decided_by_person_id` | `issue`, `meeting`, ChurchCRM person |
| `task` | `decision_id`, `responsibility_id`, `assignee_person_id` | `decision`, `responsibility`, ChurchCRM person |

These are declared once in `GovRepository::ENTITIES` (as `ref`/`person` field
specs plus a `related` list) and drive validation, form option lists, detail
pages and the section navigation. Adding a relation is a registry change.

## Data access rule

Plugin tables are not part of ChurchCRM's Propel schema, and the schema must
not be modified. Data access therefore goes through prepared statements on the
standard ChurchCRM connection (`Propel::getConnection()`):

- every statement is prepared; no value is ever interpolated into SQL;
- table names, column names and `ORDER BY` clauses are whitelisted against the
  entity registry, so no user input can reach an identifier position;
- routes and views contain no SQL at all — they call the repository.

`SQLUtils::sqlImport()` (the utility ChurchCRM's own installer uses) remains
the tool for applying `database/001_initial.sql`.

## R07 permission concept

```text
Authentication
      ↓
ChurchCRM application permission
      ↓
MOS-GOV governance authorization
      ↓
Action
```

Implemented by `src/Security/GovAuthorization.php` — an explicit governance
authorization layer, as required, which delegates identity and roles to
ChurchCRM's own API rather than re-deriving them:

| Capability | V0.1 policy |
| --- | --- |
| read governance data | any authenticated ChurchCRM user |
| modify governance data | ChurchCRM administrators only |

`GovWriteRoleAuthMiddleware` binds the policy to every write route. Because it
extends ChurchCRM's `BaseAuthRoleMiddleware`, the browser/API behaviour
(redirect to `/v2/access-denied` vs. a 403 JSON body) matches the rest of the
application for free.

Login protection itself is not implemented by the plugin: the `/plugins`
entry point already runs the global `AuthMiddleware`, so anonymous requests
never reach a governance handler.

## R08 interface principle

Use stable foreign identifiers:

- `person_id`
- `group_id`
- `event_id`
- `user_id`

Resolve display names and current ChurchCRM facts at runtime — never store a
copy. `PersonLookup` provides `find` / `exists` / `label` / `labels` /
`candidates` / `search` for that purpose, with a request-scoped memo so a page
that shows many person references does not issue a query per reference.

## Audit position

V0.1 has no audit log or event-sourcing subsystem. `created_at` / `updated_at`
timestamps exist on every table, and `GovRepository::delete()` is deliberately
not exposed through any route (governance records are closed, not erased), but
a real audit trail must be designed before governance decisions become
production-critical. See `IMPLEMENTATION-NOTES.md`.

## Deliberately out of scope for V0.1

- complex approval workflows and a workflow engine
- event sourcing / full audit trail
- policy engine
- oversight, evaluation, record entities (P1/P2)
- BILA / Mission Platform integration
- any synchronisation that writes to ChurchCRM core data

## Future P1/P2 entities

- policy
- oversight
- evaluation
- record

They are deliberately excluded from the V0.1 P0 schema.

---

# V0.2 — Authorization architecture (appendix)

New layer between ChurchCRM authentication and governance actions:

```
ChurchCRM AuthMiddleware (login)
    ↓
GovAuthorization::can(user, action, resource, row)   ← unified entry (R07 upgraded)
    ↓
GovernancePolicy  11-step order (identity→role→permission→appointment→
                  scope→visibility→explicit-deny→ALLOW)
    ├─ GovernanceContext   request-scoped identity/roles/scopes/permissions
    ├─ ScopeResolver       exact (scope_type, scope_id) containment
    ├─ PermissionResolver  whitelist registry + explicit deny precedence
    └─ VisibilityResolver  P1–P5 information levels, P5 default DENY
    ↓
AuthorizationDecision { allowed, reason, permission, scope, level, source }
```

Code organisation (§49): `src/Security/` holds the engine;
`src/Governance/` holds IdentityService / MyGovernanceService /
GovSearchService; `src/Data/GovRepository.php` gains a second registry
(`SECURITY_ENTITIES`) for the nine authorization tables while the ten V0.1
entities and slug map stay untouched.

Boundary rules unchanged: ChurchCRM core untouched; PersonLookup read-only;
all SQL prepared + whitelisted; routes/views contain no SQL (statically
tested); no outbound network calls (statically tested).
