# MOS-GOV V0.1 Scaffold

**MOS-GOV — MOS Church Governance Plugin for ChurchCRM**

This is the first implementation scaffold following the MOS-GOV R09 design stage.

## Core principle

> ChurchCRM stores church facts; MOS-GOV stores governance structure, authorization and operational governance records.

## P0 governance tables

1. `gov_structure`
2. `gov_body`
3. `gov_role`
4. `gov_appointment`
5. `gov_responsibility`
6. `gov_relationship`
7. `gov_meeting`
8. `gov_issue`
9. `gov_decision`
10. `gov_task`

## Package structure

```text
MOS-GOV-V0.1/
├── plugin.json
├── src/
│   └── MosGovPlugin.php
├── routes/
│   └── routes.php
├── views/
│   ├── dashboard.php
│   └── settings.php
├── database/
│   └── 001_initial.sql
├── tests/
│   ├── MosGovPluginTest.php
│   └── schema_smoke.php
└── docs/
    ├── ARCHITECTURE.md
    ├── R09-DEPLOYMENT.md
    └── CHANGELOG.md
```

## Important

This package is a **scaffold**, not a claim of completed runtime compatibility.

The first real deployment target is the actual ChurchCRM installation. The correct sequence is:

```text
install
→ enable
→ dashboard
→ database
→ gov_structure CRUD
→ ChurchCRM Person lookup
→ permissions
→ lifecycle persistence
→ full governance flow
```

## ChurchCRM compatibility

The scaffold follows the community-plugin pattern represented by the ChurchCRM community plugin example, including `plugin.json`, `AbstractPlugin`, Slim routes, and ChurchCRM shell views. Exact runtime compatibility must still be verified against the deployed ChurchCRM version.

## Safety

No ChurchCRM core table is altered by the initial schema.

No destructive uninstall is performed by the plugin class.

No credentials, API keys, or secrets are included.
