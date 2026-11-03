# Historical Data Staging Verification

## Status

`NOT VERIFIED`

The live `loan_management` database was not modified. A read-only dump was created at:

`backups/loan_management_phase13_production_backup_20261103.sql`

It was restored into the isolated database `legacy_test_phase13_prod`. The live source currently contains zero `outstanding_debtors`, loan applications, payments, legacy accounts, legacy transactions, and financial years. Consequently, no real historical production population was available for record-level compatibility verification.

## Safe staging procedure

1. Take a consistent `mysqldump --single-transaction --routines --events --triggers` backup.
2. Restore into a database whose name begins with `legacy_test_`.
3. Revoke application write access to the source database.
4. Run the schema and legacy migrations only in the clone.
5. Preserve the source `outstanding_debtors` table during migration.
6. Reconcile counts, identities, balances, financial years, loans, payments, and migrated relationships.
7. Run the full test suite and record every mismatch.
8. Obtain business approval before any production import.

## Required staging reconciliation

The staging run must record:

- historical debtor count;
- total outstanding balance;
- imported batch and record counts;
- matched, duplicate, ambiguous, and rejected counts;
- opening-balance total;
- ledger debit/credit totals;
- payment and funding-account totals;
- migrated exposure totals;
- unresolved variance.

For this run, these values are `NOT VERIFIED` because the source contains no historical records. The seeded clone is representative test data, not historical production data.

## Protection

The backup and clone are excluded from Git. Do not distribute the dump, connect ordinary development sessions to it, or import real personal data without approved access controls and retention handling.
