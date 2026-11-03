# Legacy Debt Final Implementation Report

## Final status

`NOT PRODUCTION READY`

The legacy subsystem passed the fresh isolated end-to-end regression and financial reconciliation. Production deployment is blocked by the remaining operational/security items listed under Remaining Issues.

## Implemented

- Normalized legacy debtors, one legacy account per debtor, components, ledger transactions, payment details, imports, migrations, approvals, and audit events.
- Immutable debit/credit ledger with balance derived as total debits minus total credits.
- Overpayment credit preservation.
- Controlled import workflow: upload, validate, preview, duplicate check, confirm, post, reconcile.
- Non-destructive migration of `outstanding_debtors`.
- Granular server-side RBAC and maker/checker approval controls.
- Accountant + Admin write-off approval.
- Controlled legacy-to-normal-loan migration with permanent relationship and date-based payment routing.
- Legacy Debt UI workspace linked from Admin, Accountant, and Cashier portals.
- Retired unsafe legacy write endpoints and redirected old UI paths to the controlled workspace.
- Fixed existing PHP 8 normal-loan creation failure caused by `bindParam()` inline expressions.
- Fixed existing normal-payment responses returning `payment_id: 0` after reading the insert id too late.

## Database

Legacy structures include:

- `legacy_financial_years`
- `legacy_debtors`
- `legacy_debt_accounts`
- `legacy_loan_components`
- `legacy_transactions`
- `legacy_payment_details`
- `legacy_import_batches`
- `legacy_import_records`
- `legacy_action_requests`
- `legacy_migrations`
- `legacy_reconciliation_runs`
- `legacy_reconciliation_results`
- `legacy_permissions`
- `legacy_role_permissions`
- `legacy_user_permissions`
- `legacy_audit_events`

Constraints and triggers enforce account uniqueness, transaction immutability, self-approval prevention, closed-year controls, migration uniqueness, migration-date immutability, audit immutability, import-source metadata immutability, and protected batch history.

## Financial Logic

The ledger is authoritative. Financial corrections create compensating entries rather than modifying history. Payments use the Collections Funding Account. Disputed accounts remain payable. Closed-year postings require controlled authorization and a reason. Interest and penalties are manually posted by authorized roles.

## Authorization

Authorization is resolved from the active database user and granular permissions, not from a submitted role value. Loanees have no legacy access by default. Cashier, Accountant, and Admin permissions follow the approved matrix. Self-approval is rejected server-side and by database trigger.

## Migration

Migration preserves the legacy account and creates or reuses a normal loan. The normal loan begins at `balance_at_migration`; the historical legacy balance is not added as a second obligation. Payments dated before the migration date remain on the legacy ledger. Payments dated on or after the migration date route to the normal loan using an inclusive calendar-date boundary.

## Security

Phase 7 tested unauthorized access, loanee denial, self-approval, duplicate payment, duplicate migration, direct ledger tampering, audit tampering, path traversal, MIME/extension checks, CSRF, and retired endpoint access. Unsafe legacy endpoints now return HTTP 410. The security report is in `LEGACY_DEBT_SECURITY_AUDIT.md`.

For isolated testing, `legacy_test_app@localhost` was created with only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` privileges on `legacy_test_phase8`. The project `Database` class successfully connected with that account. The existing root-based local configuration was intentionally left unchanged for the user's personal development workflow.

## Testing

Fresh isolated Phase 8 regression covered:

- import → validation → confirmation → posting;
- opening balances;
- payment;
- debit adjustment;
- waiver;
- Accountant + Admin write-off;
- migration with one independent approval;
- normal-loan creation;
- post-migration payment routing;
- closed-year authorized and unauthorized payments;
- loanee backend denial;
- duplicate payment replay;
- duplicate migration request;
- existing-data migration and reconciliation;
- audit-event generation;
- ledger and migration immutability.

Existing legacy tests passed:

- Phase 2 ledger database tests;
- Phase 3 import/reconciliation tests;
- Phase 4 RBAC tests;
- Phase 5 migration tests.

Staging acceptance checks also passed for normal-loan creation, loan retrieval, Accountant status processing, normal-loan payment recording, Collections Fund deposit, Admin dashboard/activity APIs, and Admin/Accountant/Cashier portal rendering.

## Data Migration

The production `outstanding_debtors` table currently contains no records, so there were no production source rows to migrate. A synthetic source row was migrated in the isolated Phase 8 database without deletion and reconciled with one match and zero mismatches.

Fresh regression financial evidence:

- Account 5: opening/debits `15,500.00`, credits `2,600.00`, closing legacy balance `12,900.00`.
- Account 6: approved migration balance and normal-loan amount `25,000.00`; post-migration normal-loan payment `750.00`; normal-loan balance `24,250.00`.
- Collections Fund balance equaled recorded deposits: `1,850.00`.
- Latest source reconciliation: `COMPLETED`, 1 source, 1 matched, 0 mismatches.

## Compatibility

PHP syntax checks, JavaScript syntax checks, and Git whitespace checks passed. The new legacy UI does not change the global theme. Old direct legacy import/migration routes are intentionally retired because they could bypass the normalized ledger; the old portal entry points now redirect to the controlled workspace.

## Performance

The main ledger account query uses the account/date index. Dashboard account ordering currently uses a filesort because there is no dedicated `legacy_debt_accounts(updated_at, legacy_account_id)` index. This is a measured optimization opportunity, not a financial-integrity failure.

## Remaining Issues

### Blocking

1. `config/config.php` still defaults to MySQL user `root` with an empty password. Production must use a dedicated least-privilege database account, protected credentials, and a non-development database configuration.
2. Broader existing normal-loan portal workflows do not have a complete automated browser/integration regression suite. The migration-created normal loan and routed payment were verified, but normal-loan creation, reporting, and every unrelated portal workflow require staging acceptance testing before production release.

### Non-blocking follow-up

1. Add a composite index for dashboard ordering after production query-volume evidence confirms the need.
2. Make the Phase 5 `ADD UNIQUE` migration statement explicitly rerunnable for deployment tooling that may replay migrations.
