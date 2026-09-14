# Implementation Notes

## Why the first version is small

The purpose of this scaffold is to prove the plugin boundary in a real
ChurchCRM environment before building the complete governance application.

## Data ownership

MOS-GOV owns governance records.

ChurchCRM owns:

- people
- families
- groups
- events
- users
- other core CRM facts

## Identifier policy

IDs from ChurchCRM are references, not duplicated master records.

## Audit policy

V0.1 has no full audit/event-sourcing subsystem yet. This must be designed
before governance decisions become production-critical.

## Permission policy

Do not infer governance authorization solely from ChurchCRM login state.
R07 requires an explicit governance authorization layer.

## Migration policy

Schema changes should be delivered as ordered SQL migrations and tested on a
copy/backup of the real ChurchCRM database.
