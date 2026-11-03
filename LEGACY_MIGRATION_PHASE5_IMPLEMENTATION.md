# Phase 5 Controlled Legacy-to-Normal Loan Migration

## Files

- `scripts/legacy_migration_phase5.sql`
- `api/legacy_migration.php`
- `api/legacy_ledger.php`
- `tests/legacy_migration_phase5_test.php`

Apply the migration after Phases 1–4:

1. `scripts/legacy_debt_foundation.sql`
2. `scripts/legacy_ledger_phase2.sql`
3. `scripts/legacy_import_phase3.sql`
4. `scripts/legacy_rbac_phase4.sql`
5. `scripts/legacy_migration_phase5.sql`

## Workflow

`POST api/legacy_migration.php?action=REQUEST`

Creates a migration request and records the approved balance/date without changing the normal-loan table.

`POST api/legacy_migration.php?action=APPROVE`

Requires one independent Cashier, Accountant, or Admin approval. The requester cannot approve their own request. The balance is rechecked, and stale requests are rejected.

`POST api/legacy_migration.php?action=EXECUTE`

Runs in one database transaction. It rechecks the balance, reuses an existing linked normal loan when present, otherwise creates one with `loan_amount = balance_at_migration`, records the permanent migration link, updates the legacy account projection, and writes audit events.

The legacy account and its ledger remain available for historical reference. The normal loan represents the same obligation after migration; the historical balance is not added a second time.

## Payment boundary

The implementation uses an inclusive calendar-date boundary:

- payment date before `DATE(migration_date)`: legacy ledger
- payment date on or after `DATE(migration_date)`: normal `payments` table

This is implemented server-side in `api/legacy_ledger.php`. The migration date and relationship become immutable after execution.

## Validation

Run against a database whose name begins with `legacy_test_`:

```text
LEGACY_TEST_DB=legacy_test_phase5 php tests/legacy_migration_phase5_test.php
```
