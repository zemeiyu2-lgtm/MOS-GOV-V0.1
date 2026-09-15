# WorkBuddy Phase 1–2 Integration Report — MOS-GOV V0.1

Date: 2026-09-15
Environment: ChurchCRM 7.7.0, PHP 8.4.25 (container `docker-webserver-1`),
MariaDB 10.11.19 (container `docker-database-1`), document root `/var/www/html`
(host mount `C:\churchcrm\src`).

This report documents the local integration verification of MOS-GOV V0.1 as a
ChurchCRM community plugin. No new features were added; the only code changes
are fixes for two real V0.1 blockers found during testing.

---

## Executive summary (RESULTS / CHANGES / TESTS / BLOCKERS / NEXT)

**RESULTS** — MOS-GOV V0.1 works as a ChurchCRM community plugin end to end:
discovered, enabled via the official `PluginManager` path, `boot()` runs,
routes registered, 10 `gov_*` tables created and readable, dashboard and
settings pages return HTTP 200 over real authenticated HTTP.

**CHANGES** (MOS-GOV only, no ChurchCRM core changes):
- `src/MosGovPlugin.php` — moved from `src/Plugins/MosGov/MosGovPlugin.php`
  (PSR-4 layout fix, Blocker A).
- `routes/routes.php` — rewritten to use the in-scope Slim `$app` with
  mount-point-relative paths (Blocker B).
- `tests/integration_phase2.php`, `tests/init_tables.php` — added regression
  harness.
- `docs/WORKBUDDY-REPORT.md`, `docs/AI-TASK.md` — added.

**TESTS** — all green: discovery (9 plugins, mos-gov included);
`enablePlugin('mos-gov')` → true; route registration → `GET /mos-gov`,
`GET /mos-gov/settings`; all 10 `gov_*` tables present (schema smoke);
`GET /plugins/mos-gov` → 200; `GET /plugins/mos-gov/settings` → 200;
dashboard content markers present.

**BLOCKERS** — none outstanding in code. Environment note: a root-owned log
file caused an HTTP 500 during closure; fixed by `chown www-data` (see
"Phase 2 closure" below). Push to GitHub may require interactive Git
Credential Manager authentication on this machine.

**NEXT** — Phase 3 (dashboard queries, CRUD screens, authorization layer,
provenance) is explicitly NOT started; see `docs/AI-TASK.md` for the backlog
and standing constraints.

---

## Phase 1 — Bootstrap / discovery diagnosis

**Symptom.** A standalone CLI debug script reported
`Propel\Runtime\Exception\RuntimeException: No connection defined for database "default"`
(and, before loading the DB map, "Database map was not initialized").

**Root cause.** The CLI script (`src/mos-gov-debug.php`, now removed) loaded
only `Include/LoadDatabaseMap.php` — it never went through the official
bootstrap entry point. `Include/LoadConfigs.php` (used by every web and CLI
entry, e.g. `src/cli/timerjobs.php`) is what calls `Bootstrapper::init()`
(initMySQLI + initPropel connection manager + session + SystemConfig).

**Resolution.** The diagnostic script was rewritten to use the full bootstrap.
With it, `getPluginSettingsWithValues()` and `getAllPlugins()` worked with no
exceptions, and the web Plugin Management page was confirmed to bootstrap
cleanly (HTTP 302 to the login page, no fatal). **CLI test environment issue;
no ChurchCRM core change and no MOS-GOV change were needed.**

---

## Phase 2 — Enable / routes / schema / web E2E

### Step 1 — Status before enable

`PluginManager` discovery: yes. Active: false. Quarantined: false.
Verification: unverified ("No install provenance recorded — plugin was copied
in manually") — expected for a manually-copied community plugin.

### Step 2 — Official enable attempt

`PluginManager::enablePlugin('mos-gov')` returned **false** (no exception,
no admin-visible log).

### Step 3 — Root-cause analysis (two real MOS-GOV blockers)

**Blocker A — main class not PSR-4 locatable.**
`PluginManager::registerPluginAutoloader()` derives the PSR-4 prefix from the
`mainClass` in plugin.json (`ChurchCRM\Plugins\MosGov\`) and maps it to the
plugin's `src/` directory. Therefore the loader expects
`src/MosGovPlugin.php`. MOS-GOV shipped the class at
`src/Plugins/MosGov/MosGovPlugin.php`, so `class_exists()` failed and
`loadPlugin()` returned null. This matches the layout convention of all core
plugins (e.g. `custom-links/src/CustomLinksPlugin.php`).

**Blocker B — routes never registered, and wrong path prefix.**
The previous `routes/routes.php` returned `function (App $app) {...}`, but
`PluginManager::registerPluginRoutes()` `require`s the file inside its own
scope and uses the in-scope `$app` directly — the returned closure was
silently discarded, so no routes were ever registered. Additionally, route
paths were absolute (`/plugins/mos-gov`) while the plugins entry point
(`src/plugins/index.php`) runs a dedicated Slim app with basePath `/plugins`,
which would have produced `/plugins/plugins/mos-gov` URLs.

### Step 4 — Fixes (MOS-GOV only)

1. Moved `src/Plugins/MosGov/MosGovPlugin.php` → `src/MosGovPlugin.php`
   (namespace unchanged; plugin.json unchanged).
2. Rewrote `routes/routes.php`: use the in-scope `$app`, register
   `GET /mos-gov` and `GET /mos-gov/settings` (mount-point-relative).

### Step 5 — Re-test results

- `enablePlugin('mos-gov')` → **true** (official path; `boot()` executed via
  `loadPlugin()`; `isEnabled()`, `isConfigured()` = true; not quarantined).
- Route registration into a Slim collector → 2 routes:
  `GET /mos-gov`, `GET /mos-gov/settings`.

### Step 6 — Schema compatibility check

`database/001_initial.sql` reviewed against MariaDB 10.11.19: 10 tables, all
`ENGINE=InnoDB` + `utf8mb4_unicode_ci`, no FOREIGN KEYs, only
`CREATE TABLE IF NOT EXISTS gov_*` statements, no ChurchCRM core tables
referenced. Fully compatible. The test harness also asserts at runtime that
the SQL file contains no non-`gov_*` objects before executing it.

### Step 7 — Table initialization

Executed via `SQLUtils::sqlImport()` (the same utility the ChurchCRM core
installer uses) through the standard Propel connection.

### Step 8 — Verification

All 10 governance tables created and readable via the standard ORM
connection (the same connection a dashboard would use):

```
gov_structure      rows=0 engine=InnoDB  [OK]
gov_body           rows=0 engine=InnoDB  [OK]
gov_role           rows=0 engine=InnoDB  [OK]
gov_appointment    rows=0 engine=InnoDB  [OK]
gov_responsibility rows=0 engine=InnoDB  [OK]
gov_relationship   rows=0 engine=InnoDB  [OK]
gov_meeting        rows=0 engine=InnoDB  [OK]
gov_issue          rows=0 engine=InnoDB  [OK]
gov_decision       rows=0 engine=InnoDB  [OK]
gov_task           rows=0 engine=InnoDB  [OK]
```

### Step 9 — Web end-to-end

Authenticated HTTP requests (admin API key) against the running container:

```
GET /plugins/mos-gov           → 200 text/html
GET /plugins/mos-gov/settings  → 200 text/html
```

Dashboard HTML rendered completely (page title "MOS-GOV", governance
dashboard cards, V0.1 scaffold notice).

Note: the V0.1 dashboard intentionally shows placeholder statistics ("—") and
does not yet query the governance tables; connecting the cards to real
queries is explicitly deferred (documented in the view itself).

---

## Phase 2 closure — final regression

During closure, an HTTP 500 on both plugin pages appeared. Diagnosis: a log
file (`logs/2026-09-15-app.log`) had been created by a root-owned CLI
diagnostic run, so the web process (`www-data`) could no longer append to it
and Monolog's StreamHandler threw. This was an environment side effect of the
diagnostics, not a code issue. Fixed with
`chown -R www-data:www-data /var/www/html/logs/`. Lesson recorded: run CLI
diagnostics as `www-data` (or fix log ownership afterwards).

Final regression results (all green):

```
discovery:                mos-gov discovered (9 plugins total)
enablePlugin:             true (boot ran, isEnabled/isConfigured true)
route registration:       GET /mos-gov, GET /mos-gov/settings
gov_* tables:             all 10 present (schema smoke passed)
GET /plugins/mos-gov          → 200 text/html
GET /plugins/mos-gov/settings → 200 text/html
dashboard marker "Governance dashboard": present
```

---

## Changes made

| File | Change |
| --- | --- |
| `src/MosGovPlugin.php` | Moved from `src/Plugins/MosGov/MosGovPlugin.php` (PSR-4 layout fix — Blocker A) |
| `routes/routes.php` | Rewritten: in-scope `$app`, mount-point-relative paths (Blocker B) |
| `tests/integration_phase2.php` | Added: regression test (bootstrap → enable → routes → table check) |
| `tests/init_tables.php` | Added: idempotent table init + 10-table verification |
| `tests/probe_load*.php` | Temporary diagnostics; removed after root-cause confirmed |

## Core safety

- No ChurchCRM core file modified (verified via `git status` in the ChurchCRM
  repository; the pre-existing local edit to
  `docker/Dockerfile.churchcrm-apache-php8` — adding the `bcmath` PHP
  extension — predates this work and is unrelated to MOS-GOV).
- No core table created/altered/dropped; only `gov_*` tables were added.
- No files deleted outside MOS-GOV's own obsolete artifacts.

## Known follow-ups (deferred, not done in Phase 2)

1. Dashboard cards currently show placeholders; real queries need explicit
   approval before implementation.
2. Plugin Management shows "unverified" until the plugin is installed through
   the official flow (provenance recording).
3. Plugin routes currently require login (global `AuthMiddleware`) but are
   not admin-restricted; adding `AdminRoleAuthMiddleware` is a V0.2 concern.

---

# Phase 3 — Governance data layer + minimal CRUD (2026-09-15)

## RESULTS

Phase 3 delivered the core governance data layer and a minimal, safe CRUD
foundation for the four core entities (structure -> body -> role ->
appointment), plus real dashboard counters. All Phase 2 checks still pass.

Data-layer audit conclusions (recorded for future phases):

1. **No generated Propel models.** The Propel schema belongs to ChurchCRM
   core; plugin tables cannot be added to it without a core change. Plugin
   data access therefore goes through PDO-style statements on the standard
   ChurchCRM connection obtained via `Propel::getConnection()` (in this
   install the connection object is `Propel\Runtime\Connection\
   ConnectionInterface`, statements are `StatementInterface`).
2. **PDO over SQLUtils for queries.** `SQLUtils::sqlImport()` remains the
   tool for schema migration files only.
3. **A dedicated repository layer exists** (`src/Data/GovRepository.php`);
   routes and views contain no SQL.
4. **Schema audit passed**: the four tables have clear, non-overlapping
   responsibilities (`gov_structure` self-referencing tree, `gov_body`
   belongs to structure, `gov_role` belongs to body, `gov_appointment`
   belongs to role + ChurchCRM `person_id` reference). No field duplication;
   lack of FOREIGN KEYs is a deliberate boundary decision (documented in
   `001_initial.sql`). The schema supports future CRUD growth.
5. **Dashboard reads real data safely**: empty tables display 0; data-layer
   failures render an explicit error banner instead of a crash.

## CHANGES

| File | Change |
| --- | --- |
| `src/Data/GovRepository.php` | New: entity registry (single source of truth for tables/fields/validation), prepared-statement-only CRUD, dashboard counters, per-field validation incl. ref existence and appointment date ordering |
| `src/Data/GovDataException.php` | New: safe-message exception carrying per-field validation errors |
| `routes/routes.php` | Dashboard now renders real counters; added 6 routes: list / new-form / create / detail / edit-form / update for `{structures\|bodies\|roles\|appointments}`; CSRF enforcement on all POSTs; root-path-aware redirects |
| `views/dashboard.php` | Real counters (0 when empty), error banner, links to the four list pages |
| `views/entity_list.php` | New: shared list view (escapes all output) |
| `views/entity_form.php` | New: shared create/edit form (CSRF field, per-field errors, ref select options, status whitelist) |
| `views/entity_view.php` | New: shared detail view with resolved parent labels |
| `views/error_page.php` | New: standalone error page for CSRF rejection / 404 / data-layer failures |
| `tests/phase3_test.php` | New: CLI data-layer suite (validation, full CRUD lifecycle, counters, cleanup) |

## TESTS

All green:

- `php -l` on all 10 PHP files: no syntax errors.
- `tests/phase3_test.php`: 22 PASS (validation rules, insert/find/update,
  invalid-insert rejection, counts delta, list/ref-options, baseline-restore
  cleanup).
- `tests/integration_phase2.php` (Phase 2 regression): discovery yes, active
  true, enablePlugin true, boot ran, **8 routes registered** (2 original +
  6 new), all 10 gov_* tables present.
- `tests/schema_smoke.php`: passed.
- HTTP regression (unauthenticated + API-key authenticated):
  - unauthenticated GET/POST -> 302 to `/session/begin` (login protection);
  - authenticated GET dashboard / settings / 4 lists / new-form -> 200
    text/html;
  - detail of nonexistent id -> explicit "does not exist" page;
  - POST without CSRF token -> 400 (rejected); positive-path CRUD covered by
    the CLI suite (API-key auth has no session, so a browser-session CSRF
    round trip was not automatable here).

## BLOCKERS

None outstanding. Environment note: pushing requires the repo-local
`credential.helper=wincred` workaround (Git Credential Manager crashes when
spawned as a grandchild process by git's HTTP transport on this machine);
the stored Windows credential is used automatically.

## NEXT

- Phase 4 (pending ChatGPT review): remaining entities (meeting, issue,
  decision, task, responsibility, relationship) can reuse the same
  registry-driven repository/view pattern by adding entries to
  `GovRepository::ENTITIES`.
- R07 governance authorization layer and person lookup (resolving
  `person_id` to names at runtime) remain future work; the repository
  intentionally does not read ChurchCRM core tables.
