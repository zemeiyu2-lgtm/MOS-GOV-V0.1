# AI Task Tracking — MOS-GOV V0.1

This file records the execution state of AI-assisted integration work so any future session can resume without re-deriving context.

## Current state (as of 2026-09-15)

**Phase 1 = COMPLETED.**
**Phase 2 = COMPLETED.**
**Phase 3 = ACTIVE — core entity/data-layer validation and minimum dashboard data integration.**

## Completed

### Phase 1 — Bootstrap diagnosis (done)
- Confirmed the "Database map was not initialized" / "No connection defined for database 'default'" errors were caused by a standalone CLI debug script bypassing the official bootstrap (`Include/LoadConfigs.php` → `Bootstrapper::init()`).
- No ChurchCRM core or MOS-GOV changes required.
- Temporary ChurchCRM debug probe was removed after closure.

### Phase 2 — Enable / routes / schema / web E2E (done)
- Fixed Blocker A: main class moved to `src/MosGovPlugin.php` (PSR-4 layout expected by `PluginManager::registerPluginAutoloader()`).
- Fixed Blocker B: `routes/routes.php` rewritten to use the in-scope `$app` with mount-point-relative paths (`/mos-gov`, `/mos-gov/settings`).
- `PluginManager::enablePlugin('mos-gov')` → true; `boot()` runs.
- 2 routes registered; 10 `gov_*` tables created via `SQLUtils::sqlImport()` and verified readable; dashboard and settings pages return HTTP 200 over authenticated HTTP.
- Regression harness retained in `tests/integration_phase2.php` and `tests/init_tables.php`.

## Phase 3 — Core governance data layer (ACTIVE)

### Objective
Establish the minimum real data path for the four core governance entities:
`gov_structure` → `gov_body` → `gov_role` → `gov_appointment`, and expose only the minimum dashboard statistics needed to prove the data path works.

### Scope
1. Inspect the existing SQL schema and existing dashboard/view code for the four core entities.
2. Confirm primary keys, fields, relationships, nullability, timestamps, and expected ChurchCRM Person references without changing the schema unless a true V0.1 blocker is found.
3. Define the minimum data-access approach compatible with ChurchCRM/Propel and the current plugin architecture.
4. Implement read-only dashboard statistics for:
   - Structures
   - Bodies
   - Active/Open governance items only if already represented by the existing schema; otherwise leave the card unchanged and report why.
5. Implement the smallest viable CRUD foundation for `gov_structure`, `gov_body`, and `gov_role` only if the existing V0.1 route/view architecture supports it cleanly. Do not expand to all ten entities in this phase.
6. Preserve the existing route contract and add routes only when necessary.
7. Add regression tests for all new data-access paths.
8. Run authenticated HTTP tests against the local ChurchCRM instance.

### Explicit non-goals for Phase 3
- No broad UI redesign.
- No complete CRUD for all ten governance entities.
- No production-grade audit/event sourcing implementation.
- No provenance implementation unless it is required to validate the existing plugin-management flow.
- No ChurchCRM core modifications.
- No ChurchCRM core-table modifications.
- No unrelated refactoring.

### Autonomous repair rule
WorkBuddy may directly diagnose and minimally repair MOS-GOV-owned code, tests, routes, views, SQL, or documentation when required to satisfy this phase. It should run regression tests after each repair.

WorkBuddy must STOP and report before:
- modifying ChurchCRM core code;
- modifying ChurchCRM core database tables;
- changing the approved MOS-GOV data model in a way that affects existing governance tables;
- performing destructive database operations;
- expanding the scope beyond this phase.

### Acceptance criteria
- Existing Phase 2 regression tests remain green.
- Core governance schema remains compatible with MariaDB 10.11.
- At least the four core entities are correctly readable through the chosen data-access layer.
- Dashboard shows real counts for Structures and Bodies, replacing placeholders where applicable.
- Any implemented CRUD foundation passes authenticated HTTP and data-persistence tests.
- No ChurchCRM core code or core tables are modified.
- WorkBuddy updates `docs/WORKBUDDY-REPORT.md` with RESULTS / CHANGES / TESTS / BLOCKERS / NEXT.

## Standing constraints

- Only the MOS-GOV repository (`zemeiyu2-lgtm/MOS-GOV-V0.1`, branch `main`) receives MOS-GOV commits.
- The plugin directory lives inside the ChurchCRM checkout at `src/plugins/community/mos-gov` but is ignored by ChurchCRM's `.gitignore` (`src/plugins/community/*`).
- Work must remain minimal, testable, and reversible.
