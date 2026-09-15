# AI Task Tracking — MOS-GOV V0.1

This file records the execution state of AI-assisted integration work so any future session can resume without re-deriving context.

## Current state (as of 2026-09-15)

**Phase 1 = COMPLETED. Phase 2 = COMPLETED. Phase 3 = COMPLETED.
V0.1 COMPLETE (final closure).**

MOS-GOV V0.1 is no longer a scaffold: it is a usable first-version governance
platform for ChurchCRM. The next increment is V0.2 and must not be started
inside the V0.1 closure.

## Acceptance criteria — V0.1 COMPLETE

| # | Criterion | Evidence |
| --- | --- | --- |
| 1 | ChurchCRM discovers the plugin | `integration_phase2.php` (discovery yes) |
| 2 | Plugin can be enabled | `enablePlugin('mos-gov')` → true |
| 3 | `boot()` runs normally | plugin instance loaded, isEnabled/isConfigured true |
| 4 | Routes register normally | 8 routes registered for 10 entities |
| 5 | Dashboard works | HTTP 200, live counters, recent meetings/decisions |
| 6 | Core governance entities CRUD | 10 entities × list/detail/create/edit, HTTP + CLI tested |
| 7 | Appointment links a ChurchCRM person | `PersonLookup`, picker, existence validation |
| 8 | Issue → Decision → Task loop works | ref fields + related collections + data test |
| 9 | Structure → Body → Role → Appointment loop works | same |
| 10 | All 10 `gov_*` tables work | schema smoke, init_tables, live counts |
| 11 | Basic permission security is effective | anonymous 302, non-admin writes refused |
| 12 | Phase 2 regression passes | `integration_phase2.php` green |
| 13 | No ChurchCRM core code modified | `git status` in the ChurchCRM repo |
| 14 | No ChurchCRM core table modified | schema audit; only `gov_*` created |
| 15 | No blocking errors remain | 6/6 suites green |

## Completed

### Phase 1 — Bootstrap diagnosis (done)
- Confirmed the "Database map was not initialized" / "No connection defined for database 'default'" errors were caused by a standalone CLI debug script bypassing the official bootstrap (`Include/LoadConfigs.php` → `Bootstrapper::init()`).
- No ChurchCRM core or MOS-GOV changes required.

### Phase 2 — Enable / routes / schema / web E2E (done)
- Fixed Blocker A: main class moved to `src/MosGovPlugin.php` (PSR-4 layout expected by `PluginManager::registerPluginAutoloader()`).
- Fixed Blocker B: `routes/routes.php` rewritten to use the in-scope `$app` with mount-point-relative paths.
- `PluginManager::enablePlugin('mos-gov')` → true; `boot()` runs; 10 `gov_*` tables created via `SQLUtils::sqlImport()` and verified readable.

### Phase 3 — Data layer + minimal CRUD (done)
- Established the registry-driven repository and prepared-statement-only data access.
- Dashboard counters, minimal CRUD for structure/body/role/appointment, CSRF, login protection.
- `tests/phase3_test.php` (22 PASS).

### V0.1 final closure (done — this phase)
- Extended the entity registry to all ten governance entities with new field
  types (`select`, `datetime`, `person`), reference/person existence
  validation, cross-field rules, whitelisted filtering/sorting and
  `omitIfEmpty` so database defaults apply.
- Added `src/Integration/PersonLookup.php` (R08 read-only ChurchCRM person
  bridge) and `src/Security/GovAuthorization.php` +
  `GovWriteRoleAuthMiddleware.php` (R07 governance authorization layer).
- Rewrote routes around the registry: 8 routes serve all ten entities; writes
  are guarded by R07; detail pages show related collections; "Add …" links
  prefill the parent reference; every link is root-path aware.
- Rebuilt the views against the ChurchCRM page-header contract
  (`$sPageTitle` / `$aBreadcrumbs` / `$sPageHeaderButtons`), added shared
  section navigation, a person picker, related-collection tables, error and
  empty states.
- Added `tests/v01_data_test.php`, `tests/v01_http_test.php` and
  `tests/run_all.php`; all six suites pass.

## Known limitations (documented, not blockers)

- No audit trail / event sourcing: a decision is one mutable row.
- No workflow or approval engine; no policy engine.
- No delete action in the UI (records are closed, not erased) —
  `GovRepository::delete()` exists for tests and future explicit admin actions.
- Governance writes require ChurchCRM administrator rights.
- Plugin Management still reports the plugin as "unverified" because it was
  copied in manually rather than installed through the official flow
  (install provenance).
- The plugin has no entry in the ChurchCRM main menu; it is reached through
  Plugin Management or `/plugins/mos-gov`.

## V0.2 backlog (explicitly NOT started — do not treat as done)

1. ChatGPT review of the V0.1 implementation (repository, R07 layer, views).
2. Audit/event-sourcing design before governance decisions become
   production-critical.
3. A dedicated governance role so writes are not administrator-only.
4. Person/group/event pickers backed by search for large churches
   (V0.1 uses a bounded candidate list plus ID entry).
5. Install provenance so Plugin Management shows "verified".
6. Menu entry / dashboard placement decision.
7. Filtering, paging and search on the list screens (V0.1 caps at 500 rows).
8. Links from governance records into the ChurchCRM event calendar for
   `gov_meeting.event_id`.

## Standing constraints

- Only the MOS-GOV repository (`zemeiyu2-lgtm/MOS-GOV-V0.1`, branch `main`) receives MOS-GOV commits.
- The plugin directory lives inside the ChurchCRM checkout at `src/plugins/community/mos-gov` but is ignored by ChurchCRM's `.gitignore` (`src/plugins/community/*`).
- Work must remain minimal, testable, and reversible.
- Never modify ChurchCRM core code, core tables or the core data model.
