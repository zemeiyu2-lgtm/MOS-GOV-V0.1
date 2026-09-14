# MOS-GOV V0.1 — R09 Deployment & Verification

## 1. Purpose

This package is the first implementation scaffold after the R09 architecture/specification stage.

It is intentionally conservative:

- ChurchCRM core files are not modified.
- Governance data is owned by MOS-GOV.
- ChurchCRM IDs are referenced as IDs, not duplicated as people/groups/events.
- Destructive uninstall is disabled by design.
- CRUD and permission UI are added only after runtime compatibility is verified.

## 2. Installation location

For a ChurchCRM checkout following the community-plugin convention:

```text
src/plugins/community/mos-gov/
```

Copy the complete package contents into that directory.

## 3. Initial database setup

Run:

```text
database/001_initial.sql
```

against the same ChurchCRM database used by the deployment.

Before production use, verify:

- database engine/version
- SQL mode
- charset/collation
- backup
- table naming conflicts

## 4. Verification sequence

### P0-A — Plugin discovery
1. Place plugin in the community plugin directory.
2. Confirm `plugin.json` is detected.
3. Confirm class autoloading works.
4. Enable plugin.

### P0-B — Dashboard
Open:

```text
/plugins/mos-gov
```

Expected result: MOS-GOV dashboard renders inside the ChurchCRM shell.

### P0-C — Database
Confirm all ten tables exist:

- gov_structure
- gov_body
- gov_role
- gov_appointment
- gov_responsibility
- gov_relationship
- gov_meeting
- gov_issue
- gov_decision
- gov_task

### P0-D — CRUD
The first CRUD verification target is `gov_structure`.

Only after this works should the full governance chain be implemented:

```text
structure
  ↓
body
  ↓
role
  ↓
appointment
  ↓
responsibility

meeting
  ↓
issue
  ↓
decision
  ↓
task
```

### P0-E — ChurchCRM person lookup

The application must resolve `person_id` through ChurchCRM's own Person model/query.

Do not create a duplicate MOS-GOV person table.

### P0-F — Permission verification

R07 defines three layers:

1. ChurchCRM application authentication
2. ChurchCRM application permissions
3. MOS-GOV governance authorization

A logged-in user is not automatically authorized to perform every governance action.

### P0-G — Lifecycle

Test:

1. enable
2. use dashboard
3. disable
4. restart/reload
5. re-enable
6. confirm governance data persists

## 5. V0.1 non-goals

Not yet included:

- complete CRUD UI
- complex workflow engine
- audit/event sourcing
- policy engine
- oversight/evaluation modules
- BILA integration
- Mission Platform integration
- automatic synchronization that changes ChurchCRM core data

These belong to later increments after runtime evidence.

## 6. Rollback

Do not delete the ten governance tables merely because the plugin is disabled.

If rollback is necessary:

1. disable plugin
2. preserve database backup
3. remove plugin code
4. restore only if required

Governance history should not be silently destroyed.
