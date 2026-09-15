# Changelog

## 0.1.0 — V0.1 complete

### Added

- `src/Data/GovRepository.php` extended to all ten governance entities through a
  single registry (tables, fields, labels, validation rules, cross-entity
  relations). New field types: `select`, `datetime`, `person`; new
  `ref`/`person` existence validation; `omitIfEmpty` so database defaults
  (e.g. `opened_at CURRENT_TIMESTAMP`) apply instead of an explicit NULL.
- `src/Data/GovDataException.php` — safe-message exception with per-field errors.
- `src/Integration/PersonLookup.php` — read-only bridge to ChurchCRM people
  (R08). Resolves `person_id` to names through ChurchCRM's own ORM; never
  writes ChurchCRM tables and never creates a parallel person table.
- `src/Security/GovAuthorization.php` — the R07 governance authorization layer,
  the single decision point for governance permissions.
- `src/Security/GovWriteRoleAuthMiddleware.php` — binds R07 to every write
  route, reusing ChurchCRM's role-middleware machinery.
- `views/_tabs.php` — registry-driven section navigation.
- `views/entity_list.php`, `views/entity_form.php`, `views/entity_view.php` —
  shared list / create-edit / detail screens for all ten entities, including a
  ChurchCRM person picker and per-entity related-collection sections.
- `tests/v01_data_test.php` — data-layer suite: registry/schema checks,
  person bridge, validation across every field type, full CRUD lifecycle of
  both governance loops, relation checks, counters, cleanup.
- `tests/v01_http_test.php` — HTTP suite: anonymous redirect, administrator
  page render for all ten entities, CSRF rejection and success, parent
  prefill, invalid-input re-render, unknown-record handling, and governance
  authorization for non-administrator accounts (including the JSON/API path).
- `tests/run_all.php` — runs all six suites and summarises the result.

### Changed

- `routes/routes.php` — rewritten around the entity registry: 8 routes now
  serve all ten entities; write routes are guarded by R07; detail pages render
  related collections; "Add …" links prefill the parent reference; all links
  are built from the real root path so subdirectory installs work.
- `views/dashboard.php` — live counters for all ten tables plus recent
  governance meetings and decisions, empty and error states.
- `views/settings.php` — reports the current user's governance capabilities and
  the live row count of each governance table.
- `views/error_page.php` — styled, and links back to the MOS-GOV dashboard.
- `docs/ARCHITECTURE.md`, `docs/R09-DEPLOYMENT.md`, `docs/IMPLEMENTATION-NOTES.md`,
  `docs/AI-TASK.md`, `docs/WORKBUDDY-REPORT.md`, `README.md` — updated to
  describe the implemented V0.1 behaviour.

### Fixed

- Internal links in the list, form and detail views were hardcoded to
  `/plugins/mos-gov/…` (the views even computed a root-path-aware base URL and
  then ignored it), which broke every link in a subdirectory installation.
  All links now derive from `SystemURLs::getRootPath()`.
- Every page rendered its own page header *and* an empty browser title,
  because the views never set the ChurchCRM header contract. Views now set
  `$sPageTitle`, `$sPageSubtitle`, `$aBreadcrumbs` and `$sPageHeaderButtons`,
  so titles, breadcrumbs and header actions are populated by the shell.

### Safety

- No ChurchCRM core file, table or data model was modified.
- All dynamic SQL fragments remain whitelisted against the entity registry.
- No destructive uninstall: disabling the plugin preserves governance history.
