# Legacy Debt Database Implementation

## Status

Phase 1 database foundation implemented.

This phase creates structure only. It does not migrate existing outstanding_debtors data, alter existing schema files, or implement UI/API workflows.

## Implementation artifact

- scripts/legacy_debt_foundation.sql

The migration is intended to run after the base loan-management schema. It uses InnoDB, utf8mb4, foreign keys, unique keys, MySQL 8 CHECK constraints, JSON metadata fields, and triggers for ledger integrity.

The installed local MySQL client is MySQL 8.4.7. A live MySQL server was not reachable on localhost:3306 during validation, so execution against a database could not be completed in this environment.

## Tables

### legacy_financial_years

Controlled financial-year master.

Key features:

- Unique label.
- Unique start/end date pair.
- Start date must precede end date.
- OPEN and CLOSED statuses.
- post_close_transactions_allowed controls whether approved exception postings may be made after closure.
- Created and closed actor/timestamp metadata.
- Foreign keys to users with SET NULL actor behavior.

### legacy_debtors

Historical identity only.

Key features:

- PF number and national ID.
- Both identifiers are individually indexed and unique when present.
- Missing identifiers remain possible for historical data.
- Identity status supports review.
- Optional link to an existing user.
- No accounting balance columns.

### legacy_debt_accounts

One account per legacy debtor.

Key features:

- Unique debtor foreign key enforces one account per debtor.
- Account reference is unique.
- Dispute and migration statuses are explicit.
- Optional link to loan_applications.
- No mutable balance column.
- Balance is derived from legacy_transactions.

A debtor can have multiple legacy_loan_components under this one account.

### legacy_loan_components

Represents multiple historical loans/components belonging to one account.

Key features:

- Component reference is unique.
- Each component belongs to one controlled financial year.
- Original principal is non-negative.
- Source system and source reference are retained.
- Component data is not the accounting ledger; ledger postings remain authoritative.

### legacy_transactions

Immutable debit/credit ledger.

Key features:

- Required account and financial-year relationships.
- Enumerated transaction types.
- Exactly one positive debit or credit enforced by CHECK and trigger.
- Unique transaction reference.
- Optional component/source/parent transaction.
- POSTED and REVERSED states.
- Posted transaction changes are rejected by trigger.
- Closed financial years reject postings unless the year permits post-close transactions and an approval reference is supplied.
- Corrections must be compensating or reversal transactions.

Balance formula:

    SUM(debit_amount) - SUM(credit_amount)

### legacy_payment_details

Payment-specific extension for a ledger transaction.

Key features:

- One-to-one relationship with the ledger transaction.
- Payment method, dates, payer, receipt, and transaction reference.
- Funding-account reference.
- Reconciliation state and actor metadata.
- Reversal is represented through the ledger parent/reversal relationship.

### legacy_import_batches

Import-level audit and control.

Stores:

- Source filename/path/hash/MIME.
- Uploader and timestamps.
- Controlled financial year.
- Batch status.
- Detected, valid, imported, rejected, and duplicate counts.
- Total imported debt.
- Confirmation and completion metadata.
- Failure summary.

### legacy_import_records

Row-level import lineage.

Stores:

- Source row number.
- Original JSON source data.
- Normalized identity fields.
- Source reference and amount.
- Validation/duplicate state.
- Duplicate relationship.
- Created debtor/account links.
- Error message.

### legacy_action_requests

Maker/checker foundation for sensitive operations.

Supports:

- Payment reversals.
- Adjustments.
- Interest and penalties.
- Waivers.
- Write-offs.
- Migration.
- Reconciliation.

Stores requester, approver, executor, amount, target transaction, reason, supporting reference, status, and timestamps. A CHECK constraint prevents self-approval.

### legacy_migrations

Permanent legacy-to-normal-loan relationship.

Stores:

- Unique migration reference.
- One migration per legacy account.
- Action request.
- Status.
- Balance at migration.
- Migration date.
- Loan application ID/reference.
- Requester, approver, executor, and timestamps.
- Failure reason.

The legacy account remains intact after migration.

### legacy_audit_events

Structured append-oriented audit trail.

Stores:

- Event reference.
- Entity type and ID.
- Action.
- Actor.
- Request/reference.
- Reason.
- Before/after JSON.
- Source IP and user agent.
- Timestamp.

## Relationships

- Users create, approve, execute, and audit legacy operations.
- A financial year has many components, transactions, and import batches.
- A debtor has exactly one legacy debt account.
- An account has many loan components and transactions.
- A payment is a transaction with payment details.
- A transaction may reference a parent transaction for reversal/compensation.
- A batch has many import records.
- A migration belongs to one account and may link to one normal loan.
- Financial parents use RESTRICT, SET NULL, or no cascade behavior. No financial history is destroyed by parent deletion.

## Compatibility decisions

### Existing outstanding_debtors

KEEP temporarily.

It remains available as a source/compatibility table while future backfill and reconciliation are performed. This Phase 1 migration does not copy or transform its data.

### Existing loan_applications

KEEP.

legacy_migrations links to loan_applications.id with ON DELETE RESTRICT so a linked normal loan cannot silently destroy migration history.

### Existing payments

KEEP for normal loans.

Do not reuse the existing normal-loan payments table as the legacy ledger. Legacy payment records use legacy_transactions plus legacy_payment_details.

### Existing activity_log

KEEP for general system activity.

legacy_audit_events provides structured financial audit data without deleting or replacing existing activity history.

### Existing financial year text

DEPRECATE as an authority.

Future legacy writes must use legacy_financial_years. Existing text values require a controlled mapping during Phase 2.

## Deletion policy

No physical deletion path is provided by this foundation for financial records.

Foreign keys use RESTRICT for financial parents and SET NULL only for non-financial actor references or import links that must remain auditable after a user is removed.

## Future migration strategy

Phase 2 must:

1. Profile outstanding_debtors.
2. Normalize PF and national-ID values.
3. Resolve duplicates and identity conflicts.
4. Create one legacy_debtors row per confirmed identity.
5. Create one legacy_debt_accounts row per debtor.
6. Create one legacy_loan_components row per historical loan/year/source component.
7. Post reconstructed OPENING_BALANCE transactions.
8. Recreate existing migration links in legacy_migrations.
9. Reconcile counts and totals.
10. Obtain business sign-off before switching reads/writes.

No existing data is migrated by Phase 1.

## Validation performed

Static checks completed:

- All required entity tables are present.
- Required foreign keys are defined.
- Required unique constraints are defined.
- Decimal amounts use DECIMAL(19,4).
- Transaction types are constrained by ENUM.
- Financial-year references are foreign keys.
- Account-per-debtor uniqueness is enforced.
- Migration-per-account uniqueness is enforced.
- Self-approval constraints are present.
- Financial parent deletion is restricted.
- Ledger debit/credit checks and immutability triggers are present.
- No existing application files or schema files were modified.

Runtime validation limitation:

- MySQL client version detected: 8.4.7.
- Connection to localhost:3306 failed with error 10013.
- The SQL migration therefore could not be executed against a live database in this environment.

Before production use, execute the migration against a backup/staging database and test:

- Foreign-key enforcement.
- Duplicate identity rejection.
- Duplicate account rejection.
- Invalid financial-year rejection.
- Invalid transaction type rejection.
- Invalid debit/credit combinations.
- Posted transaction immutability.
- Orphan prevention.
- Restricted deletion.
- Migration relationship preservation.

## Phase boundary

Phase 1 is complete as a source-level database foundation.

Phase 2 is not started. It must not begin automatically.

