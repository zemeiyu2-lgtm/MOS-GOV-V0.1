# AI Task Tracking — MOS-GOV

This file records the execution state of AI-assisted integration work so any future session can resume without re-deriving context.

## Current state (as of 2026-09-16)

**V0.1 = COMPLETE. V0.2 = COMPLETE (feature branch
`feature/mos-gov-v0.2-governance-security`).**

V0.2 added the governance identity / scope / permission / visibility model,
the unified authorization engine, My Governance Center, scoped governance
search, export control, identity administration UI and LOCAL/LAN secure
mode. All 14 suites (6 V0.1 regression + 8 V0.2) pass against the live
Docker deployment (ChurchCRM + MariaDB 10.11).

### V0.2 execution record

- Repository audited first: branch/commit/working tree, deployment copy in
  `C:\churchcrm\src\plugins\community\mos-gov` (identical to GitHub, CRLF only).
- Environment fix (not code): root-owned daily log files broke web writes → 500.
- Migration `database/002_v02_authorization.sql`: additive, idempotent,
  21-check verification (`v02_migrate.php`), no destructive statements.
- Authorization engine per §17/§18 with request-scoped context; explicit deny
  wins; appointment lifecycle ends authority (§15); scope containment is an
  exact (type,id) match (Group 12 ≠ 13); P5 default DENY with explicit-only unlock.
- Route hardening: secure-mode middleware on every route; scope-filtered lists
  and dashboard recents; detail-page ID-guessing DENY; P5 field masking.
- Semantic corrections per §39: responsibility requires role or appointment;
  decision requires issue or meeting (one V0.1 test assertion updated to the
  corrected semantics — documented, not masked).
- Export separation §24 verified over HTTP (admin without grant → 403;
  member → 403).
- CRM cutdown §28 within plugin boundary (navigation reorganisation, scoped
  search, export off by default, P5 masking) — see docs/V02-CRM-CUTDOWN.md.

### Known limitations (V0.2, honest list)

- Audit is log-based via `AuditService`; no DB audit table / event sourcing yet.
- V0.1 module-read policy (any authenticated user) is retained for the ten
  legacy entity pages; strict scope/visibility applies to users carrying a
  governance identity and to all new V0.2 surfaces.
- Group/ministry scope IDs reference ChurchCRM groups but are not yet
  validated against them (opaque integers by design).
- ChurchCRM-admin bootstrap compatibility remains for identity management
  (documented boundary: system administration ≠ church governance authority);
  export has NO admin bypass.
- Files/training/accountability are UI containers only.
- CLI test runs create root-owned log files in this deployment (environment,
  see V02-SECURITY-MODE.md).

### Next steps (V0.3 candidates)

1. DB-backed audit trail (actor/action/resource/timestamp/result/reason).
2. Approval workflow engine on top of `governance.submit/approve/publish`.
3. Group/ministry pickers with ChurchCRM validation for scope assignment.
4. Finance read-only governance reporting interface.

## V0.1 state (as of 2026-09-15)

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
