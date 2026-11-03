# Loan Management System

PHP/MySQL application for staff loan applications, approvals, repayments, funding accounts, reporting, and controlled legacy-debt management. It is designed for Apache/WampServer and uses `Africa/Nairobi` time.

## Features

- Admin, accountant, cashier, and loanee portals.
- Loan application, review, approval, dispatch, repayment, and closure workflows.
- Payment schedules, funding accounts, notifications, and reports.
- Legacy-debt upload, validation, duplicate review, posting, reconciliation, and migration.
- Immutable legacy ledger with payments, overpayment credits, reversals, adjustments, waivers, write-offs, interest, penalties, disputes, and closed-year controls.
- Permanent legacy-to-normal-loan migration links and migration-date payment routing.
- Server-side RBAC, maker/checker approvals, CSRF, ownership checks, protected uploads, and audit history.

## Technology and structure

- PHP 7.4+, MySQL/MariaDB, Apache, PDO, JavaScript Fetch API.
- PhpSpreadsheet and Smalot PDF Parser for supported imports.

```text
api/          JSON endpoints
classes/      Services and domain classes
config/       Application/database configuration
helpers/      Shared helpers
js/           Portal JavaScript
models/       Data models
pages/        Portal pages
scripts/      Database migrations and maintenance SQL
tests/        Regression, security, audit, and reconciliation tests
secure/       Protected import storage
backups/      Local backups excluded from Git
```

## Roles

Admin provides oversight, approvals, audit review, and reporting. Accountant handles accounting, legacy review, payments, adjustments, waivers, interest, penalties, migrations, and write-off participation. Cashier handles imports, payments, reconciliation, and authorized operational requests. Loanee access is limited to normal loan functionality by default.

Authorization is enforced server-side with database-backed roles and granular permissions; frontend checks are not security controls.

## Installation

Place the project at:

```text
D:\wamp64\www\loan-system
```

Open:

```text
http://localhost/loan-system/
```

Create `loan_management`, import the base schema, then apply the legacy migrations in order:

```text
scripts/legacy_debt_foundation.sql
scripts/legacy_ledger_phase2.sql
scripts/legacy_import_phase3.sql
scripts/legacy_rbac_phase4.sql
scripts/legacy_migration_phase5.sql
scripts/legacy_security_phase7.sql
scripts/legacy_financial_remediation_phase10.sql
scripts/legacy_auditability_phase12.sql
```

Apply migrations to a backup or staging database first. Install dependencies with:

```bash
composer install
```

## Configuration

Development may use local defaults. Production must use explicit environment variables:

```text
APP_ENV=production
LOAN_DB_HOST=127.0.0.1
LOAN_DB_NAME=loan_management
LOAN_DB_USER=loan_app_user
LOAN_DB_PASS=<strong-secret>
```

Production rejects missing credentials, MySQL `root`, and blank passwords. Do not commit credentials. Configure HTTPS, secure session cookies, and a dedicated database account.

## Legacy debt model

The normalized model uses:

- `legacy_debtors`: canonical debtor identity.
- `legacy_debt_accounts`: one account per debtor.
- `legacy_loan_components`: multiple historical loan components per account.
- `legacy_transactions`: immutable financial source of truth.
- `legacy_payment_details`: payment and funding metadata.
- `legacy_import_batches` / `legacy_import_records`: retained source data.
- `legacy_import_validation_attempts` / `legacy_import_validation_rows`: append-only validation history.
- `legacy_action_requests`: maker/checker approvals.
- `legacy_migrations`: permanent migration relationships.
- `legacy_dispute_events`: immutable dispute lifecycle.
- `legacy_audit_events`: actor, action, reason, before/after, request, and timestamp evidence.

Balances are always calculated as:

```text
balance = total debits - total credits
```

Old financial transactions are never edited or deleted; corrections create new ledger transactions.

## Import and migration workflows

Legacy imports follow:

```text
UPLOAD → VALIDATE → PREVIEW → DUPLICATE CHECK → CONFIRM → POST → RECONCILE
```

Repeated validation creates new attempts instead of deleting history. Source files retain their batch, generated path, and SHA-256 hash. Possible duplicates are not automatically merged; authorized users record `SAME_PERSON`, `DIFFERENT_PERSON`, or `UNRESOLVED` with a reason.

Migration preserves the legacy account, normal loan, and permanent link. Before the migration date payments belong to the legacy account; on or after the migration date they route to the normal loan. The same collectible debt is not counted twice in unified reporting.

## Security controls

- Server-side RBAC and granular permissions.
- Requester cannot approve their own action.
- Accountant + Admin approval for write-offs.
- CSRF validation on state changes.
- Database-backed ownership checks.
- Fail-closed production database configuration.
- Generic database-error responses.
- Protected `secure/` storage and no directory listing.
- Disabled `api/clear-data.php` destructive endpoint.
- Immutable financial, import, dispute, migration, and audit history triggers.
- No wildcard CORS in the audited API scope.
- HTTP-only, SameSite session cookies.

## Testing

When PHP is not on PATH under WampServer:

```powershell
D:\wamp64\bin\php\php8.5.0\php.exe -l api\legacy_ledger.php
```

Important tests:

```text
tests/legacy_ledger_test.php
tests/legacy_import_test.php
tests/legacy_rbac_phase4_test.php
tests/legacy_migration_phase5_test.php
tests/legacy_financial_integrity_phase10_test.php
tests/security_phase11_test.php
tests/legacy_auditability_phase12_test.php
tests/phase13_reconciliation_test.php
tests/phase13_legacy_scenarios_test.php
tests/phase13_performance_test.php
```

Database tests require an isolated database beginning with `legacy_test_`:

```powershell
$env:LEGACY_TEST_DB='legacy_test_phase13_prod'
$env:LEGACY_TEST_HOST='127.0.0.1'
$env:LEGACY_TEST_USER='root'
$env:LEGACY_TEST_PASSWORD=''
```

Never point test scripts at `loan_management`.

## Phase 13 representative backup

The verified local production-shaped test artifacts are:

```text
backups/loan_management_phase13_production_backup_20261103.sql
legacy_test_phase13_prod
```

The production database was not modified. The clone was seeded with representative normal, overpaid, disputed, waived, written-off, reversed, and migrated scenarios, then used for regression, reconciliation, and synthetic 1,000-account performance testing.

## Operational requirements

Before importing real historical debt:

1. Configure at least one valid financial year.
2. Use a dedicated non-root database account.
3. Take and verify a restorable backup.
4. Keep `secure/`, logs, uploads, and backups outside public access where possible.
5. Verify Apache denies private source files.
6. Review audit and reconciliation results after the first import.
7. Monitor performance after real data is loaded.

## Current status

Phase 13 passed for the representative production-shaped test environment. Phase 14 remediation checks passed after canonical identity matching, wildcard CORS removal, and debug error-display remediation.

The system is ready for controlled deployment and staging validation. Configure financial years and complete an approved backup/recovery rehearsal before importing real historical debt.
