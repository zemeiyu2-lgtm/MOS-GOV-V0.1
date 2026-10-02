# Changelog

## 0.2.0 — V0.2 governance identity, unified authorization and local secure mode

### Added

- **Governance identity model (§13)** — `gov_identity` (one per ChurchCRM
  person), `gov_identity_role` (role attachment, appointment-bound lifecycle),
  `gov_identity_scope` (per-person actual scope) and
  `gov_identity_permission` (explicit grant/deny override). Person facts are
  still stored only in ChurchCRM; `person_id` stays an opaque reference.
- **Scope registry (§16)** — `gov_scope` with a FIXED scope-type whitelist
  (global/church/structure/body/ministry/group/activity/project/person),
  `gov_role_scope` (role template scope, direct/inherit).
- **Permission registry (§12)** — `gov_permission` seeded with 35 whitelisted
  keys (`governance.*`, `meeting.*`, `issue.*`, `decision.*`, `task.*`,
  `identity.*`, `role.*`, `appointment.*`, `permission.manage`),
  `gov_role_permission` (role defaults seeded for A01…E01).
- **Visibility rules (§10/§22)** — `gov_visibility_rule` with information
  levels P1–P5; P5 is DENY by default and no seeded rule unlocks it.
- **System roles (§14)** — A01 General Member … E01 Governance Administrator,
  seeded under a dedicated `MOS-GOV-SYSTEM` structure/body. E01 holds system
  administration only and deliberately does NOT hold `decision.approve`.
- **Unified authorization engine (§17/§18)** — `AuthorizationDecision`,
  `GovernanceContext` (request-scoped identity→roles→scopes→permissions with
  batch queries and request caching), `ScopeResolver` (exact (type,id) pair
  containment, global ⊃ church superscopes, implicit self-person scope),
  `PermissionResolver` (explicit deny wins, deny always outranks grant),
  `VisibilityResolver` (P1–P5 with seeded rules + explicit grants),
  `GovernancePolicy` (the strict 11-step evaluation order).
  `GovAuthorization::can()` is the new unified entry; the V0.1
  `canRead()`/`canWrite()` entry points are preserved.
- **My Governance Center (§26/§27)** — `/mos-gov/my-governance` answers
  我是谁 → 角色 → 托付 → 范围 → 可见 → 可做 → 当前任务 → 向谁负责, plus stable
  containers for files/training/accountability.
- **Governance search (§23)** — `/mos-gov/search`: Query → Authorization →
  Scope → Visibility → Results; never the CRM global search.
- **Export control (§24)** — `/mos-gov/{entity}/export`: view and export are
  separate permissions; per-row scope filtering; P5 masked; audit-logged.
  Default member export = DENY; admin has NO export bypass.
- **Identity administration UI** — identity list/create/detail with role
  attachment, scope assignment and grant/deny overrides; permission registry
  page with role matrix.
- **Local/LAN secure mode (§32)** — `MOS_GOV_SECURITY_MODE=LOCAL|LAN|OFF`
  (default LOCAL), enforced by `GovSecureModeMiddleware` on every route;
  OUTBOUND_NETWORK=DENY and PUBLIC_BINDING=DENY by design.
- **AuditService (§36)** — reserved audit interface; permission changes,
  role/appointment changes and exports are audit-logged now.
- **Tests** — `v02_migrate`, `v02_identity_test`, `v02_scope_test`,
  `v02_permission_test`, `v02_visibility_test`, `v02_authorization_test`,
  `v02_security_test`, `v02_my_governance_test` (incl. real-HTTP checks);
  `run_all.php` now runs all 14 suites.

### Changed

- `database/002_v02_authorization.sql` — additive, idempotent migration
  (CREATE TABLE IF NOT EXISTS + guarded seeds). No DROP/TRUNCATE/DELETE.
- `GovRepository` — second registry (`SECURITY_ENTITIES`) for the nine V0.2
  tables; §39 semantic constraints (responsibility needs role or appointment;
  decision needs issue or meeting); permission-key whitelist validation;
  scope-type/ID coherence validation.
- `routes/routes.php` — every route runs through the secure-mode middleware;
  list pages and dashboard recents are scope-filtered for users carrying a
  governance identity; detail pages enforce scope containment (URL/ID
  guessing denied) and mask P5 fields.
- `views/_tabs.php` — governance-first navigation (governance home / my
  governance / church governance / governance running / identity / security).
- `views/settings.php` — security mode, outbound-network and database/backup
  policy section.
- `plugin.json` / `MosGovPlugin` — version 0.2.0.

### Safety

- ChurchCRM core files, schema and data model untouched (verified by git).
- The ten V0.1 `gov_*` tables and all V0.1 behaviour preserved; all six
  V0.1 suites still pass.
- No `mos_person`/`mos_family`/`mos_group` duplicates; PersonLookup remains
  read-only; no outbound network, cloud, analytics, mail, SMS or map calls.

### Changed — V0.2 界面收口（简体中文 / 导航层）

界面（含导航与文案）统一为简体中文；本次只改表现层，未触碰数据模型、权限
引擎、Scope、身份模型、LocalSecureMode 与 ChurchCRM core。

- **新增 `views/_i18n.php`** — 表现层唯一的中英映射点：把数据层/注册表的英文
  标签与枚举值映射为中文显示，未知文本原样透出。实体键、权限键、范围类型、
  枚举**存储值与提交值**保持英文，仅 `<option>` 可见文本中文化。
- **导航（`views/_tabs.php`）** — 一级入口为「治理首页」「我的治理中心」；
  分组：教会治理（结构·治理主体·角色·任命·职责）、治理运行（会议·议题·决策·
  任务·关系）、治理身份（治理身份·权限注册表·治理搜索）、安全设置。
- **首页（`views/dashboard.php`）** — 标题 MOS-GOV / 教会治理平台；原先只突出
  数据库统计，现把「我的治理中心」与「治理运行」置于统计卡片之前，统计降为
  「治理数据统计」区块；页面顶部说明 ChurchCRM 为底层事实系统。
- **全部视图中文化** — 我的治理中心（1–9 步问句）、治理搜索、拒绝页、错误页、
  实体列表/表单/详情、治理身份列表/详情/新建、权限注册表、安全设置。
- **路由用户可见文案中文化（`routes/routes.php`）** — CSRF 拒绝、记录不存在、
  导出被拒、身份/权限管理权限不足等提示；授权判定逻辑与顺序完全未改。
- **测试断言同步** — `v01_http_test`、`v02_my_governance_test` 中依赖界面文案的
  断言更新为对应中文表述，语义与安全断言不变（14 套全部通过）。

### Fixed — Docker browser access in LOCAL secure mode

- **The host's own browser was refused in LOCAL mode.** Inside Docker the
  host's browser reaches the published port over the Docker bridge, so
  Apache sees `REMOTE_ADDR = 172.18.0.1` (the bridge gateway — the host's
  own address on that network), not `127.0.0.1`. `LOCAL` therefore denied
  every browser request with "this client address is not trusted".
  `LOCAL` mode now additionally accepts exactly the gateway addresses of
  the container's connected networks (parsed from `/proc/net/route`, e.g.
  `172.18.0.1`) plus an optional exact-address allow-list
  (`MOS_GOV_LOCAL_EXTRA_CLIENTS`, default empty). Private ranges as a whole
  stay closed in LOCAL mode: neighbouring containers (`172.18.0.x` ≠
  gateway) and LAN clients (their real LAN source IP) are still denied, so
  "LOCAL = this machine only" holds. LAN mode rules are unchanged.

### Fixed — V0.2 final review (legacy read-surface boundary)

Three defects were found on the legacy read surfaces during the read-only
final review. No new capability was added; only the security boundary was
corrected.

- **Broken deny path on the entity detail page.** The detail route closure
  never captured the deny-page renderer, so the §21 scope denial for
  URL/ID guessing fataled (`Value of type null is not callable`) and
  surfaced as a generic HTTP 500 instead of the 403 page. The denial view
  also required an `$esc` helper that the renderer did not pass, which
  produced a second failure on the same path — the path had never been
  exercised over HTTP. Both are fixed and covered by real-HTTP assertions.
- **Deactivating a governance identity widened access.** The legacy read
  gate keyed on an *active* governance identity, so setting an identity to
  `inactive` removed the context and made the dashboard recents, entity
  lists and detail pages skip every check — the person saw the full
  unscoped data set. The gate is now "holds a governance identity row,
  whatever its status" (`GovAuthorization::subjectToGovernancePolicy()` →
  `GovernanceContext::hasIdentityRecord()`), so access is monotonic:
  provisioning, activating or deactivating an identity can never increase
  what a person can read. An inactive identity now yields DENY.
- **Permission registry was readable by any authenticated user.** The page
  rendered the whole permission whitelist and the role→permission matrix and
  only hid the write controls; read is now gated by the same
  `permission.manage` permission as write (ChurchCRM administrators keep
  their documented bootstrap read).

Scope of the correction: the documented bootstrap read policy for users with
**no** governance identity row is unchanged — it is what keeps a
not-yet-provisioned installation and the V0.1 suites working. It is recorded
as a known limitation, not a regression.

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
