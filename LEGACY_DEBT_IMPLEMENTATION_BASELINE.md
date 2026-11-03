# Legacy Debt Implementation Baseline

## Baseline status

This document records the Phase 0 inspection and safety gate for the Legacy Debt subsystem.

The current repository is on branch main and points to:

    https://github.com/harrisonmuhoro/loan-system.git

The working tree contains these uncommitted Phase 1 artifacts:

- scripts/legacy_debt_foundation.sql
- LEGACY_DEBT_DATABASE_IMPLEMENTATION.md

They were created before this Phase 0 contract was supplied. They have been reviewed against the finalized Phase 0 decisions. They have not been applied to the production database.

Review result:

- One legacy debt account per debtor is enforced by a unique foreign-key-backed key.
- Multiple legacy loan components are supported under that account.
- Migration date and permanent normal-loan linkage fields are present.
- Single-role approval is supported through requester/approver fields and self-approval triggers.
- Closed-year transactions require an enabled post-close policy and approval reference.
- Financial transaction immutability and restricted deletion are enforced.
- Existing outstanding_debtors data is not automatically migrated.

No additional application, UI, payment, migration, or database changes were made during this Phase 0 turn.

## A. Current architecture

The application is a PHP/MySQL loan-management system with:

- PHP portal pages in pages
- JSON APIs in api
- JavaScript portal logic in js
- Database configuration in config
- Domain classes in classes
- Models in models
- SQL schemas at repository root
- Helpers and middleware for responses, notifications, rate limiting, and session behavior

The main portals are Admin, Cashier, Accountant, and Loanee.

The principal current entities are users, loan_applications, payments, payment_schedules, funding_accounts, funding_transactions, notifications, activity_log, and outstanding_debtors.

## B. Current database

### users

The users table contains the main user identity and role information.

Relevant fields include:

- user_id
- full_name
- email
- id_number
- pf_number
- role
- is_active

Roles are represented as loanee, accountant, cashier, and admin.

### loan_applications

The normal loan table contains:

- id
- user_id
- application_id
- full_name
- id_number
- pf_number
- loan_type
- loan_amount
- payment duration and frequency
- status
- application and dispatch dates
- processing dates
- feedback and banking fields

application_id is unique. The normal lifecycle includes pending, forwarded, accepted, dispatched, active, rejected, returned, and closed.

### payments

The current payment table is coupled to normal loans using application_id.

Relevant fields include:

- payment_id
- application_id
- payment_date
- amount
- principal_amount
- interest_amount
- balance_after
- payment_source
- transaction_reference
- recorded_by
- recorded_at
- notes

The table has an index on application_id but does not have a legacy-account relationship or immutable reversal relationship.

### payment_schedules

This table represents normal-loan schedules and is not a legacy debt ledger.

### funding_accounts and funding_transactions

The payment API deposits normal-loan collections into a funding account. The funding model must be reviewed before legacy payment integration is enabled.

### outstanding_debtors

Current fields include:

- debtor_id
- financial_year as free-form VARCHAR(20)
- name
- pf_number
- id_number
- amount
- created_by
- created_at
- updated_at
- loan_type
- employment_type
- basic_salary
- payment_duration
- payment_frequency
- application_date
- dispatch_date
- status
- bank_name
- bank_branch
- bank_account_number
- email
- migrated_to_loan
- loan_application_id

The current unique key is financial_year plus name. This is not sufficient as a long-term identity or accounting key.

The table has indexes for year, name, timestamps, status, migration state, PF number, ID number, and financial-year/status.

### Audit tables

The schema contains:

- activity_log
- admin_logs
- api_key_logs

The legacy endpoints currently use activity_log with free-text details. This is not a full structured financial audit model.

### Foreign keys and deletion behavior

The existing schema uses a mixture of SET NULL, RESTRICT, and CASCADE. Some CASCADE relationships exist in normal application-support tables and must not be copied into historical financial parents.

## C. Current legacy-debtor workflow

### Creation/import

The Cashier UI in js/cashier.js:

1. Generates financial-year options in the browser.
2. Accepts CSV, XLS, and XLSX files.
3. Parses and validates debtor name and amount.
4. Displays a preview.
5. Sends records to api/debtors.php.
6. The API validates CSRF and role.
7. Valid rows are inserted or updated in outstanding_debtors.
8. Existing rows are matched by financial_year plus name.
9. Activity is logged as free text.

The default imported status is active in the current path, even though the legacy domain needs separate debt-account statuses.

### Update

api/debtors.php supports PUT updates for:

- name
- amount
- pf_number
- id_number
- status

The endpoint logs a basic update activity event.

### Deletion

api/debtors.php supports deletion of:

- one unmigrated debtor
- all unmigrated debtors for a financial year

This is financially dangerous because the current model has no immutable ledger. Migrated rows are protected in the individual-delete path, but the overall design still permits physical deletion of historical debtor records.

### Identification and duplicates

The current hard duplicate rule is financial year plus name. PF and ID are stored and indexed but are not the authoritative duplicate key. Same-name and identity conflicts are not robustly resolved.

### Balance

The current amount field is treated as the outstanding value. There is no legacy transaction ledger. For migrated records, normal-loan balance is calculated from loan amount minus summed normal-loan payments.

## D. Current import mechanisms

### Cashier import

Files:

- pages/cashier.php
- js/cashier.js
- api/debtors.php

Formats:

- CSV
- XLS
- XLSX

Validation includes name length and positive amount checks. The frontend removes some duplicates and provides a preview. The backend performs additional validation and uses an insert/update statement.

The current endpoint does not create a durable import batch or row-level import record. The original uploaded file is not permanently retained by this workflow.

### api/import-debtors.php

This is a separate legacy-oriented endpoint that inserts simple legacy data into loan_applications with normal-loan defaults. It has overlapping semantics and can bypass the intended distinction between a legacy account and a normal loan.

### api/import-legacy-loans.php

This endpoint treats legacy data as normal legacy-marked loans in loan_applications. It supports retrieval, import, update, and delete paths. This is another overlapping path and can create or mutate normal-loan records directly.

### Import risks

- Multiple endpoints use different legacy representations.
- Financial-year assignment is inconsistent.
- Original files and row lineage are not durably stored.
- Duplicate handling is not identity-safe.
- Some imports create normal loans rather than historical accounts.
- Deletion behavior differs by endpoint.

## E. Current payment workflow

api/payments.php:

- Starts or validates the session.
- Checks user identity and role.
- Validates CSRF.
- Allows cashier payment recording.
- Requires a normal-loan application ID.
- Validates payment source and amount.
- Inserts a normal-loan payment.
- Updates normal-loan status on first payment.
- Deposits collections into the funding account.
- Retrieves payments for staff or the authenticated loanee context.

No existing legacy payment workflow was found that supports:

- legacy account allocation
- immutable debit/credit ledger
- payment reversal as a compensating transaction
- controlled post-close authorization
- legacy migration-date routing

## F. Current migration workflow

### api/migrate_outstanding_to_loans.php

The endpoint:

1. Allows POST only.
2. Requires admin or cashier.
3. Requires CSRF.
4. Requires a financial year.
5. Reads unmigrated outstanding_debtors rows.
6. Validates amount and PF format.
7. Creates a normal loan_application.
8. Marks the source row migrated.
9. Deletes the source row.
10. Logs a migration activity event.

The deletion step violates the approved accounting principle. It destroys the original source row and causes historical reporting to depend on data that no longer exists.

### Other migration/import paths

api/import-debtors.php and api/import-legacy-loans.php can create normal legacy-marked loans directly. They do not provide a single controlled migration request/approval/linkage model.

### Migration risks

- Legacy source deletion.
- No permanent migration record.
- No migration date routing boundary.
- No durable balance-at-migration snapshot.
- No one-approver-but-not-requester rule.
- Potential duplicate normal-loan creation.
- Potential balance duplication.
- Incomplete historical lineage.

## G. Existing security controls

### Authentication

Authentication is session-based. APIs check session user ID and role. The system supports role values admin, accountant, cashier, and loanee.

### Authorization

Authorization is mostly role-based and is implemented separately in individual endpoints. Legacy debtor access currently allows admin, cashier, and accountant in api/debtors.php. Migration currently allows admin and cashier.

Granular legacy permissions do not yet exist.

### CSRF

State-changing legacy APIs require the session CSRF token. The frontend generally obtains and sends the token.

### Sessions

Session configuration includes HTTP-only and SameSite behavior in several entry points. Configuration also includes session timeout support and Nairobi timezone. Settings are not perfectly centralized across all portals and endpoints.

### Prepared statements

The reviewed APIs generally use PDO prepared statements. Dynamic update fields in the debtor PUT endpoint are allow-listed in code, but future APIs must retain strict allow-listing.

### File uploads

The Cashier import path parses CSV/XLS/XLSX in the browser and sends parsed data. A permanent secure source-file retention mechanism is not currently present.

### Audit logging

Basic activity logging exists, but structured entity, actor, reason, before/after, approval, and request relationships are not consistently captured.

### IDOR protection

Some APIs validate role and ownership, particularly normal-loanee payment retrieval. A complete legacy-account object-level authorization layer does not yet exist.

## H. Compatibility risks

1. Replacing outstanding_debtors immediately would break existing Admin and Cashier views.
2. Removing old import endpoints would break existing clients and workflows.
3. Changing normal payments could affect funding-account deposits.
4. Existing migrated loan records may not have reliable source links.
5. Free-form financial years may not map cleanly to a canonical calendar.
6. PF and national-ID conflicts may require manual identity resolution.
7. Existing status values do not match the proposed legacy-account lifecycle.
8. Current deletes may already have removed some source history.
9. Existing SQL files differ by MySQL version and fixes.
10. Existing code may assume legacy data is represented as normal loans.
11. A new FK to loan_applications could affect current delete behavior.
12. Permanent uploaded-file retention creates storage and access-control requirements.

## I. Implementation dependencies

Before Phase 1 implementation:

1. Approve the database foundation artifact currently uncommitted.
2. Confirm the production MySQL server version and collation.
3. Obtain a real staging database or verified backup.
4. Profile existing outstanding_debtors and loan_application legacy records.
5. Define canonical financial-year rows and closed-year controls.
6. Decide source-file storage location, encryption, retention, and access.
7. Confirm the funding-account posting policy for legacy payments.
8. Confirm the migration-date cutoff and payment routing behavior.
9. Create server-side granular permission definitions.
10. Define reconciliation and identity-resolution procedures.
11. Confirm how multiple legacy loan components are grouped under one debtor account.
12. Create rollback and reconciliation scripts before any data backfill.

## J. Phase-by-phase implementation plan

### Phase 0 — Baseline and approval

Complete this document, verify repository state, identify current data/risks, and approve the implementation boundary.

### Phase 1 — Database foundation

Create normalized financial-year, debtor, account, component, transaction, payment-detail, import, action-request, migration, and audit structures.

Do not migrate existing data automatically.

### Phase 2 — Data profiling and backfill

Profile duplicates and source totals. Map identities. Backfill accounts, components, and opening-balance transactions. Preserve outstanding_debtors as reference. Reconcile before cutover.

### Phase 3 — Import adapter

Build one controlled import service with batch/record lineage, permanent secure source retention, duplicate decisions, preview, confirmation, and audit.

### Phase 4 — Ledger payment service

Build legacy payment posting, funding-account integration, migration-date routing, reconciliation, overpayment credit handling, and post-close authorization.

### Phase 5 — Sensitive operations

Implement reversal, adjustment, interest, penalty, waiver, write-off, maker/checker rules, and structured audits.

### Phase 6 — Controlled migration

Implement one-approver migration, prevent self-approval, preserve legacy account, create normal loan with same balance, store migration date, and maintain permanent links.

### Phase 7 — UI and reporting

Update Admin, Cashier, and Accountant interfaces after APIs and permissions are stable. Keep Loanee access disabled unless separately approved.

### Phase 8 — Cutover and deprecation

Switch new writes to the normalized subsystem, maintain compatibility reads, disable destructive paths, and retire old endpoints only after reconciliation.

## K. Safety risks

### Critical

- Existing migration deletes source debtor records.
- Legacy amount is not a reconstructable ledger.
- Direct legacy-to-normal-loan imports can duplicate concepts.
- Physical delete APIs can destroy financial history.
- Current payment model is normal-loan-specific.

### High

- No structured migration request/approval record.
- No migration-date payment routing.
- Weak name-based duplicate prevention.
- Role checks are not granular.
- Existing source uploads are not permanently retained.
- Current financial-year values are free text.

### Medium

- Free-text activity logs.
- Mixed normal and legacy status semantics.
- Different session setup across portals.
- Existing cascade relationships must be reviewed before adding financial links.

## L. Test baseline

### Existing tests

A tests directory was not present in the current checked-out repository state. Existing diagnostic and maintenance scripts are not a substitute for automated financial tests.

### Existing validation performed

- Repository branch and remote inspected.
- Current Git status inspected.
- SQL schemas and legacy APIs inspected.
- MySQL client version detected as 8.4.7.
- Live localhost MySQL connection was unavailable during the previous foundation validation.
- No production database was modified.
- Temporary isolated test data was removed after testing.

### Required future tests

- Role and permission tests.
- CSRF and IDOR tests.
- Financial-year open/closed/post-close tests.
- Identity and duplicate tests.
- One-account-per-debtor tests.
- Multiple-component tests.
- Debit/credit invariant tests.
- Immutable transaction tests.
- Payment, reversal, overpayment, and funding tests.
- Migration-date routing tests.
- Single-role migration approval and self-approval rejection.
- Write-off joint approval.
- Import batch/file-retention tests.
- Foreign-key and deletion tests.
- Reconciliation and rollback tests.
- Concurrent payment tests.

## Safety gate conclusion

The Phase 0 inspection is complete. The repository is not clean because the approved Phase 1 source artifacts are uncommitted:

- scripts/legacy_debt_foundation.sql
- LEGACY_DEBT_DATABASE_IMPLEMENTATION.md

No production data was changed and the Phase 1 SQL was not applied to the production database. The source artifacts have now been reviewed against this contract. The unavailable live database remains a validation limitation, not a reason to modify production or bypass the safety gate.

# PHASE 0 READY

Readiness conditions satisfied:

1. Existing architecture, workflows, security controls, compatibility risks, and tests were inspected.
2. The finalized single-role migration approval rule is documented.
3. Controlled post-close transactions are documented.
4. Permanent legacy preservation and migration-date routing are documented.
5. The existing Phase 1 artifacts were reviewed against the Phase 0 contract.
6. No production database or financial records were modified.

The live database was unavailable for runtime verification. Before applying any migration, run it against a fresh staging restore and complete the required database tests. Phase 1 implementation work is not started automatically by this baseline.

