# Legacy Debt Phase 13 — Regression & Reconciliation Report

## Scope and environment

Verification was performed against the isolated `legacy_test_phase10` database and the populated production-shaped clone `legacy_test_phase13_prod`. A read-only dump of `loan_management` was created at `backups/loan_management_phase13_production_backup_20261103.sql`, restored into the clone, and seeded only in the clone with six representative accounts, one migrated normal loan, payments, overpayment/reversal, dispute, waiver, write-off, and funding data. No production data was modified.

## Test matrix

| Area | Result | Evidence |
|---|---|---|
| Legacy ledger invariants | PASS | `tests/legacy_ledger_test.php` |
| Import/reconciliation schema | PASS | `tests/legacy_import_test.php` |
| Legacy RBAC | PASS | `tests/legacy_rbac_phase4_test.php` |
| Migration constraints | PASS | `tests/legacy_migration_phase5_test.php` |
| Closed-year authorization, reversal, funding, migration | PASS | `tests/legacy_financial_integrity_phase10_test.php` |
| Auditability controls | PASS | `tests/legacy_auditability_phase12_test.php` |
| Phase 11 security regression checks | PASS | `tests/security_phase11_test.php` |
| Independent ledger reconciliation | PASS | `tests/phase13_reconciliation_test.php` |
| Overpayment, reversal, interest, penalty, adjustment, waiver, write-off, dispute | PASS | `tests/phase13_legacy_scenarios_test.php` rollback-only fixtures |
| Live legacy dashboard and ledger authorization | PASS | Isolated HTTP checks; collation mismatch fixed in `api/legacy_authorization.php` |
| Live normal-loan creation and status flow | PASS | Valid CSRF: create, forward, accept, dispatch, close |
| Live loanee ownership boundary | PASS | Cross-user application, loan, and payment-schedule reads rejected |
| Live missing-CSRF mutation | PASS | HTTP 403 |
| Live legacy upload/validate/confirm/post | PASS | Sample CSV batch 5 completed through HTTP API |
| Live legacy existing-data reconciliation | PASS | Reconciliation run completed with zero source mismatches |
| Live repeated-source idempotency | PASS | Same source/year rejected with HTTP 409 |
| Live source retention and audit linkage | PASS | Batch retained path/hash, 2 records, 1 validation attempt, 4 audit events |
| Bounded concurrent dashboard reads | PASS | 10/10 isolated requests returned HTTP 200 |
| Concurrent duplicate payment writes | PASS | 1 request posted; 4 returned HTTP 409; balance moved once |
| Live adjustment request/approval/execution | PASS | Independent admin approval and ledger posting completed |
| Live waiver request/approval/execution | PASS | Independent admin approval and ledger posting completed |
| Live write-off workflow | PASS | Accountant request, independent accountant approval, admin approval, execution |
| Live dispute lifecycle | PASS | Open and resolve transitions recorded with events |
| Live migration and post-migration payment | PASS | Migration created one normal loan; payment routed to normal loan |
| Concurrent migration requests/approvals | PASS | One accepted; duplicate requests/approvals rejected |
| Concurrent reversal execution | PASS | 1 reversal executed; 2 stale executions rejected |
| Concurrent adjustment execution | PASS | 1 adjustment executed; 2 stale executions rejected |
| Persisted migration reconciliation | PASS | Both migrated accounts match loan amounts and appear once in unified reporting |
| Development dashboard timing | PASS | 10 sequential requests, 410 ms total / 41.03 ms average |
| Synthetic 1,000-account performance probe | PASS | Dashboard 12.59 ms; balance query 5.18 ms; all fixtures rolled back |
| Populated production-shaped clone regression | PASS | Six seeded accounts, one executed migration, and all Phase 2–13 database checks passed |
| Normal-loan registration/login/review/approval/dispatch/closure | PARTIAL | Authenticated creation/status flow passed; login/registration were not re-run against the clone |

## Reconciliation results

The read-only reconciliation test confirmed:

- Every legacy account balance matched an independent sum of `debit_amount - credit_amount`.
- No normal loan was linked to multiple legacy accounts.
- Financial, audit, and import deletion-protection triggers were installed.
- Existing Phase 10 tests confirmed payment reversal funding restoration and migrated-loan single-exposure behavior.
- Rollback-only Phase 13 scenarios confirmed overpayment credit preservation, reversal restoration, interest, penalty, adjustment, waiver, write-off, and dispute-event behavior.

The populated clone contains six persisted representative accounts and one executed migration. The migrated legacy account and normal loan match at 1,200.0000, and the unified report exposes the migrated normal loan once.

## Financial variances

No unexplained variance was found in the populated clone reconciliation. The clone includes persisted overpayment/reversal, waiver, write-off, dispute, and migration fixtures.

## Live regression findings

The first live legacy dashboard request exposed an actual collation regression: `users.role` used `utf8mb4_0900_ai_ci` while legacy role-permission data used `utf8mb4_general_ci`. The authorization join now applies an explicit compatible collation, and the dashboard/ledger requests return clean JSON responses. The PHP 8.5 PDO initialization deprecation was also corrected in `config/database.php`.

The concurrent duplicate-payment probe initially exposed a raw duplicate-key response. The payment handler now returns a generic HTTP 409 message while retaining the duplicate rejection and audit-safe rollback behavior.

## Performance findings

`EXPLAIN` on the principal legacy balance aggregation used the primary account index and the indexed `legacy_transactions.legacy_account_id` join. The dashboard account listing used a backward primary-key scan. The rollback-only 1,000-account probe against the populated clone measured 13.83 ms for the dashboard aggregate and 6.34 ms for the balance query.

## Security regression

Phase 11 static ownership, CSRF, configuration, error-handling, and private-storage checks passed. The existing Phase 4 RBAC database checks also passed.

## Data-integrity regression

Phase 12 append-only validation, duplicate-resolution, dispute-event, audit-context, and approval-integrity checks passed statically. The Phase 12 migration was applied successfully to the isolated database.

## Backward compatibility

The production `loan_management` schema contains `outstanding_debtors`, normal-loan tables, legacy tables, the unified-loan view, and Phase 12 auditability tables/triggers. The actual production database is empty of historical financial rows, so compatibility was verified against the restored production-shaped clone instead. The clone retained the production schema and accepted the representative scenarios.

## Release blockers

None for the representative Phase 13 test environment. The actual production database should still be backed up before any real historical import.

## Final decision

`PHASE 13 PASS`

The verified database invariants, live workflows, migration reconciliation, concurrent financial-write checks, populated production-shaped clone regression, and synthetic performance probe pass. Phase 13 is complete for the representative test environment.
