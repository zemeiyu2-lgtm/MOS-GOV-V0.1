# Implementation Notes

## Why the first version is small

V0.1 exists to prove the plugin boundary in a real ChurchCRM environment and to
make the governance working loops usable — not to be a complete governance
application. Everything that is implemented is implemented safely and tested;
everything else is explicitly listed as out of scope rather than half-built.

## Data ownership

MOS-GOV owns governance records.

ChurchCRM owns:

- people
- families
- groups
- events
- users
- other core CRM facts

In code, that split is enforced by three files:

- `src/Data/GovRepository.php` touches only `gov_*` tables.
- `src/Integration/PersonLookup.php` is the only reader of ChurchCRM people,
  and it never writes.
- `src/Security/GovAuthorization.php` reads the current ChurchCRM user and
  nothing else.

## Identifier policy

IDs from ChurchCRM are references, not duplicated master records.

There are no FOREIGN KEY constraints between `gov_*` tables and ChurchCRM core
tables. That is deliberate: it keeps the core schema free to evolve without
MOS-GOV migrations, and prevents MOS-GOV from constraining core data. The
trade-off is that referential integrity for ChurchCRM references is enforced at
the application layer (`gov_appointment.person_id` and friends are validated to
exist in ChurchCRM before a row is written).

## Repository pattern

ChurchCRM's Propel schema belongs to the core and must not be extended for
plugin tables, so plugin tables have no generated models. MOS-GOV therefore
uses a registry-driven repository:

- one `ENTITIES` array describes every table: fields, types, labels, validation
  rules, defaults, and cross-entity relations;
- validation, form rendering, list rendering, detail rendering and navigation
  are all derived from that registry;
- all SQL is prepared, and identifiers (`table`, columns, `ORDER BY`) come from
  the registry — never from request data, so identifiers cannot be injected.

Adding a governance field or relation is therefore a registry edit, and the
existing tests cover the new surface automatically.

## Permission policy

Do not infer governance authorization solely from ChurchCRM login state. R07
requires an explicit governance authorization layer — `GovAuthorization`.

V0.1 policy is deliberately the strictest reasonable one: governance writes are
restricted to ChurchCRM administrators. That has a known consequence: a
ChurchCRM user who may edit CRM records but is not an administrator cannot
maintain governance records. When a dedicated "governance secretary" role is
needed, `GovAuthorization::canWrite()` is the single place to change.

## Audit policy

V0.1 has no full audit/event-sourcing subsystem yet. `created_at` and
`updated_at` are recorded on every row, and there is no route that deletes
governance records, but this must be designed before governance decisions
become production-critical.

Specifically still open:

- who changed which governance field, and when;
- the history of a decision (a decision currently has one mutable row);
- linking governance records to the meeting at which they were agreed in an
  immutable way.

## Migration policy

Schema changes should be delivered as ordered SQL migrations
(`002_…`, `003_…`) and tested on a copy/backup of the real ChurchCRM database.
V0.1 ships a single idempotent file (`001_initial.sql`) containing only
`CREATE TABLE IF NOT EXISTS gov_*` statements.

---

# V0.2 implementation notes (appendix)

- **Migration policy**: `database/002_v02_authorization.sql` is additive and
  idempotent (CREATE TABLE IF NOT EXISTS + WHERE NOT EXISTS seeds). Verified
  by `tests/v02_migrate.php` (no destructive statements, V0.1 rows preserved,
  seeds not duplicated on re-run).
- **§39 semantic corrections** implemented at validation layer (not DB CHECK)
  to avoid breaking existing V0.1 data: `gov_responsibility` requires
  role_id or appointment_id; `gov_decision` requires issue_id or meeting_id;
  relationship types were already whitelisted via the registry.
  `tests/v01_data_test.php` had one assertion updated to the corrected
  semantics (context-free responsibility is now invalid) — an intentional,
  documented behaviour change, not a masked failure.
- **Identity uniqueness**: one `gov_identity` row per `person_id`
  (DB unique index + idempotent service).
- **P5 masking**: `GovernancePolicy::filterFields()` replaces P5 field
  content with `__P5_PROTECTED__` before any view/export serialisation;
  the CSV export writes `[protected]`.
- **Request-scoped caches only**: GovernanceContext and VisibilityResolver
  memoise per request and are reset by `GovAuthorization::reset()` in tests.
  No persistent person-fact caching.
- **Log ownership pitfall**: CLI processes create root-owned daily log files
  that break www-data web writes; fix + prevention documented in
  docs/V02-SECURITY-MODE.md.

---

# V0.2 final review — read-surface boundary note

The final review was read-only apart from the three security corrections
listed in `docs/CHANGELOG.md` ("Fixed — V0.2 final review").

**The gate that matters.** `GovAuthorization::subjectToGovernancePolicy()`
decides WHICH policy applies to the legacy read surfaces (dashboard recents,
the ten entity lists, entity detail pages). It answers "does this person hold
a `gov_identity` row at all", via `GovernanceContext::hasIdentityRecord()`
(request-scoped cache, one indexed lookup per person):

```
no identity row            → V0.1 bootstrap read policy (documented limitation)
identity row, any status   → GovernancePolicy decides every row
```

It must never be expressed as "is there an active identity", because
`GovernanceContext::forUser()` returns null for an inactive identity — which
would make deactivation *widen* access. This is asserted directly in
`tests/v02_my_governance_test.php` §3 ("inactive identity still counts as an
identity row for the read gate").

**Why the bootstrap branch stays.** Making the absence of an identity deny
outright would break the six V0.1 suites (`read-only user can read governance
lists`, detail-page read) and would leave a fresh installation unusable before
any identity is provisioned. The limitation is therefore explicit and
negative-only: it can only ever *widen* access relative to the strict policy,
never bypass a scope an identity actually holds.

**Regression coverage.** `tests/v02_my_governance_test.php` §3 adds 12 real-HTTP
checks: out-of-scope detail → 403 + "This information is protected" + no title
leak; legacy list renders but hides out-of-scope rows at data level; permission
registry → 403 + no matrix leak; inactive identity does not open either the
detail page or the unscoped list; identity-less administrator keeps the
bootstrap read. The suite count stays at 14 (checks added to an existing suite,
no new suite type).
