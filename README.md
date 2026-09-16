# MOS-GOV V0.2

**MOS-GOV — MOS church governance plugin for ChurchCRM**

MOS-GOV V0.2 upgrades V0.1 from "a governance CRUD plugin" to a governance
system with **governance identity, roles, appointments, responsibilities,
scope, information levels, a unified authorization engine, a personal
governance center and a local/LAN secure mode** — running on the ChurchCRM
factual data layer, for localhost / trusted-LAN deployment only.

## Core principle

> ChurchCRM stores church facts; MOS-GOV stores governance structure,
> authorization and operational governance records.

MOS-GOV never duplicates people, families, groups, events or users. It stores
integer references (`person_id`, `group_id`, `event_id`, `user_id`) and resolves
names at runtime through ChurchCRM's own models.

## What V0.1 does

| Capability | Status |
| --- | --- |
| Official plugin discovery + enable/disable | yes |
| Dashboard with live governance counters + recent meetings/decisions | yes |
| Full CRUD (list / detail / create / edit) for all 10 governance entities | yes |
| Validation + CSRF + login protection + governance authorization | yes |
| ChurchCRM person reference resolution (read-only) | yes |
| Structure → Body → Role → Appointment → Responsibility loop | yes |
| Meeting → Issue → Decision → Task loop | yes |
| Cross-entity relations shown on detail pages | yes |
| Governance identity / roles / appointments / scope (V0.2) | yes |
| Unified authorization engine with 11-step order (V0.2) | yes |
| Information levels P1–P5, P5 default DENY (V0.2) | yes |
| My Governance Center (V0.2) | yes |
| Scoped governance search + export control (V0.2) | yes |
| Local/LAN secure mode (V0.2) | yes |
| Audit / event sourcing | **no** (AuditService interface reserved) |

## Governance entities (P0 tables)

1. `gov_structure` — self-referencing organisational tree
2. `gov_body` — a governing body inside a structure
3. `gov_role` — a role defined by a body
4. `gov_appointment` — a person appointed to a role (ChurchCRM `person_id`)
5. `gov_responsibility` — what a role or appointment is accountable for
6. `gov_relationship` — typed link between any two governance nodes
7. `gov_meeting` — a meeting of a body
8. `gov_issue` — an open matter, optionally raised at a meeting
9. `gov_decision` — a decision, optionally responding to an issue
10. `gov_task` — an action, optionally following from a decision

## Working loops

```text
Structure → Body → Role → Appointment → Responsibility
                                    ↘
Meeting → Issue → Decision → Task
```

Each arrow is a real foreign reference stored on the child record, and each is
visible from both ends: a parent's detail page lists its children, and a
child's "Add …" link prefills the parent reference.

## Package structure

```text
mos-gov/
├── plugin.json
├── src/
│   ├── MosGovPlugin.php
│   ├── Data/
│   │   ├── GovRepository.php        # entity registry + prepared-statement CRUD
│   │   └── GovDataException.php
│   ├── Integration/
│   │   └── PersonLookup.php         # read-only ChurchCRM person bridge (R08)
│   └── Security/
│       ├── GovAuthorization.php             # R07 policy decision point
│       └── GovWriteRoleAuthMiddleware.php   # route binding for R07
├── routes/
│   └── routes.php                   # 8 registry-driven routes for 10 entities
├── views/
│   ├── _tabs.php                    # shared section navigation
│   ├── dashboard.php
│   ├── entity_list.php  entity_form.php  entity_view.php
│   ├── settings.php
│   └── error_page.php
├── database/
│   └── 001_initial.sql              # 10 tables, idempotent
├── tests/
│   ├── run_all.php                  # run the whole suite
│   ├── schema_smoke.php  init_tables.php
│   ├── integration_phase2.php  phase3_test.php   # regressions
│   ├── v01_data_test.php            # ten-entity data layer
│   └── v01_http_test.php            # auth / authorization / CSRF / pages
└── docs/
```

## Permissions (R07)

```text
ChurchCRM authentication
        ↓
ChurchCRM application permissions (User::isAdmin …)
        ↓
MOS-GOV governance authorization  →  GovAuthorization
        ↓
Action
```

V0.1 policy: **any authenticated user may read governance data; only ChurchCRM
administrators may create or edit it.** The decision lives in exactly one
method (`GovAuthorization::canWrite()`), so a future dedicated governance role
is a one-line policy change. Write routes are bound to the policy through
`GovWriteRoleAuthMiddleware`, which reuses ChurchCRM's own role-middleware
machinery (including the access-denied redirect).

## Installation

1. Copy this directory to `src/plugins/community/mos-gov/`.
2. Enable it in ChurchCRM → Admin → Plugins.
3. Create the tables (both migrations are idempotent, safe to re-run):

```bash
docker exec <webserver-container> php /var/www/html/plugins/community/mos-gov/tests/init_tables.php   # V0.1 schema
docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/v02_migrate.php      # V0.2 authorization model
```

4. Set the security mode: `MOS_GOV_SECURITY_MODE=LOCAL` (default) or `LAN`.
5. Open `/plugins/mos-gov`.

## Tests

```bash
docker exec <webserver-container> php /var/www/html/plugins/community/mos-gov/tests/run_all.php
```

14 suites: the six V0.1 suites plus V0.2 migration / identity / scope /
permission / visibility / authorization / security / my-governance(+HTTP).
Individual suites can be run on their own. `v01_http_test.php` and
`v02_my_governance_test.php` need the web server reachable
(`MOSGOV_BASE_URL`, default `http://localhost`).

## V0.2 documentation

- `docs/V02-GOVERNANCE-AUTHORIZATION.md` — identity/role/scope/permission model and the 11-step engine
- `docs/V02-SECURITY-MODE.md` — LOCAL/LAN secure mode, outbound/database policy
- `docs/V02-CRM-CUTDOWN.md` — governance-first UI cutdown and core-boundary guidance
- `docs/V02-MY-GOVERNANCE.md` — the personal governance center contract

## Safety

- No ChurchCRM core file, table or data model is modified.
- All plugin SQL is parameterised; table, column and sort names come from a
  whitelisted registry, never from user input.
- No destructive uninstall: disabling the plugin preserves governance history.
- No credentials or secrets are stored in this package.

## Documentation

- `docs/ARCHITECTURE.md` — layer model and boundary rules
- `docs/IMPLEMENTATION-NOTES.md` — data ownership, audit and migration policy
- `docs/R09-DEPLOYMENT.md` — deployment and verification sequence
- `docs/WORKBUDDY-REPORT.md` — integration reports per phase
- `docs/AI-TASK.md` — execution state and backlog
- `docs/MOS-AI-HANDOFF.md` — ChatGPT/WorkBuddy handoff protocol
- `docs/CHANGELOG.md`
