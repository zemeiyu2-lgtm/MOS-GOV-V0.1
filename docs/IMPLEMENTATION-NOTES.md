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
