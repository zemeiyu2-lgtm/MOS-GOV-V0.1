# MOS-GOV V0.1 Architecture

## Layer model

```text
ChurchCRM
  │
  │ person_id / group_id / event_id / user_id
  ▼
MOS-GOV
  │
  ├─ structure
  ├─ body
  ├─ role
  ├─ appointment
  ├─ responsibility
  ├─ relationship
  ├─ meeting
  ├─ issue
  ├─ decision
  └─ task
```

## Boundary rule

ChurchCRM is the factual church data layer.

MOS-GOV is the governance interpretation and operational layer.

MOS-GOV must not create parallel authoritative records for ChurchCRM people, groups, events or users.

## R07 permission concept

```text
Authentication
      ↓
ChurchCRM application permission
      ↓
MOS-GOV governance authorization
      ↓
Action
```

## R08 interface principle

Use stable foreign identifiers:

- `person_id`
- `group_id`
- `event_id`
- `user_id`

Resolve display names and current ChurchCRM facts at runtime.

## Future P1/P2

Planned later entities:

- policy
- oversight
- evaluation
- record

They are deliberately excluded from the V0.1 P0 schema.
