# MOS-GOV V0.1 — R09 Deployment & Verification

## 1. Purpose

This document describes how to deploy MOS-GOV V0.1 into a ChurchCRM
installation and how to verify it. It reflects the implemented V0.1 state, not
a specification.

It is intentionally conservative:

- ChurchCRM core files are not modified.
- Governance data is owned by MOS-GOV.
- ChurchCRM IDs are referenced as IDs, not duplicated as people/groups/events.
- Destructive uninstall is disabled by design.
- All V0.1 SQL is idempotent and non-destructive.

## 2. Installation location

For a ChurchCRM checkout following the community-plugin convention:

```text
src/plugins/community/mos-gov/
```

Copy the complete package contents into that directory.

## 3. Initial database setup

Apply the plugin's own schema file:

```text
database/001_initial.sql
```

against the same ChurchCRM database used by the deployment. The file contains
only `CREATE TABLE IF NOT EXISTS gov_*` statements — it is safe to run
repeatedly and cannot touch non-`gov_` objects.

The packaged verifier does the import through ChurchCRM's own
`SQLUtils::sqlImport()` and then asserts that all ten tables are readable:

```bash
docker exec <webserver-container> php /var/www/html/plugins/community/mos-gov/tests/init_tables.php
```

Before production use, verify:

- database engine/version (V0.1 verified on MariaDB 10.11 / MySQL 8 syntax)
- SQL mode
- charset/collation (`utf8mb4` / `utf8mb4_unicode_ci`)
- backup
- table naming conflicts

## 4. Verification sequence

The whole sequence is automated:

```bash
docker exec <webserver-container> php /var/www/html/plugins/community/mos-gov/tests/run_all.php
```

### P0-A — Plugin discovery, enable, boot

1. Place the plugin in the community plugin directory.
2. Confirm `plugin.json` is detected (Plugin Management lists MOS-GOV).
3. Confirm class autoloading works: the main class must be at
   `src/MosGovPlugin.php` for the PSR-4 prefix declared in `plugin.json`.
4. Enable the plugin from Plugin Management.
5. Confirm `boot()` ran (`isEnabled()` / `isConfigured()` are true and the
   plugin is not quarantined).

Covered by `tests/integration_phase2.php`.

### P0-B — Dashboard

Open:

```text
/plugins/mos-gov
```

Expected result: the MOS-GOV dashboard renders inside the ChurchCRM shell,
showing live counters for all ten governance tables plus the most recent
governance meetings and decisions. An empty database renders `0` and explicit
"none yet" states; an unavailable data layer renders an error banner instead of
a crash.

### P0-C — Database

Confirm all ten tables exist and are readable:

- gov_structure, gov_body, gov_role, gov_appointment, gov_responsibility,
  gov_relationship, gov_meeting, gov_issue, gov_decision, gov_task

Covered by `tests/schema_smoke.php` and `tests/init_tables.php`.
`/plugins/mos-gov/settings` also reports each table's live row count.

### P0-D — CRUD

All ten entities support list / detail / create / edit, with server-side
validation, CSRF protection and correct empty/error states. The two governance
loops must be verifiable from both ends:

```text
structure → body → role → appointment → responsibility
meeting   → issue → decision → task
```

A parent's detail page lists its children, and each child's "Add …" action
opens a form with the parent reference already filled in.

Covered by `tests/v01_data_test.php` and `tests/v01_http_test.php`.

### P0-E — ChurchCRM person lookup

The application resolves `person_id` through ChurchCRM's own Person model.
No duplicate MOS-GOV person table exists. `gov_appointment.person_id`,
`appointed_by_person_id`, `gov_issue.owner_person_id`,
`gov_decision.decided_by_person_id` and `gov_task.assignee_person_id` are all
validated to exist in ChurchCRM, and their names are resolved for display.

Covered by `tests/v01_data_test.php` (bridge) and `tests/v01_http_test.php`
(appointment form picker, rejection of unknown person IDs).

### P0-F — Permission verification

R07 defines three layers:

1. ChurchCRM application authentication
2. ChurchCRM application permissions
3. MOS-GOV governance authorization

A logged-in user is not automatically authorized to perform every governance
action. V0.1: read = any authenticated user; write = ChurchCRM administrators.

Verify with an administrator account (all pages 200, writes succeed) and with
a non-administrator account (reads 200, every write route answers 302 to
`/v2/access-denied`, or 403 JSON for API clients). `tests/v01_http_test.php`
does this automatically using accounts taken from the ChurchCRM user table.

### P0-G — Lifecycle

Test:

1. enable
2. use the dashboard and create governance records
3. disable
4. restart/reload
5. re-enable
6. confirm governance data persists

The plugin class never drops tables on deactivate or uninstall.

## 5. V0.1 non-goals

Not included:

- workflow / approval engine
- audit or event sourcing
- policy engine
- oversight/evaluation/record entities (P1/P2)
- BILA integration
- Mission Platform integration
- automatic synchronisation that changes ChurchCRM core data
- deletion of governance records through the UI (records are closed, not erased)

## 6. Rollback

Do not delete the ten governance tables merely because the plugin is disabled.

If rollback is necessary:

1. disable the plugin
2. preserve a database backup
3. remove the plugin code
4. restore only if required

Governance history should not be silently destroyed.
