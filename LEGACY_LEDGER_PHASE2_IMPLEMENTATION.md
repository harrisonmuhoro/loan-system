# Phase 2 Legacy Financial Ledger

## Status

Phase 2 server-side ledger engine implemented without changing frontend/UI code.

## Files

- api/legacy_ledger.php
- scripts/legacy_ledger_phase2.sql
- tests/legacy_ledger_test.php

The Phase 2 SQL must be applied after the Phase 1 foundation:

1. scripts/legacy_debt_foundation.sql
2. scripts/legacy_ledger_phase2.sql

## API operations

All POST operations require an authenticated session and CSRF token.

### Get account

    GET api/legacy_ledger.php?legacy_account_id=123

Returns the account, derived balance, and transaction/payment history. Access is limited to Admin, Accountant, and Cashier.

### Record payment

    POST api/legacy_ledger.php?action=RECORD_PAYMENT

Required fields:

- legacy_account_id
- financial_year_id
- amount
- payment_date
- payment_method
- transaction_reference
- reason
- csrf_token

Optional fields include transaction number, receipt number, payer information, post-close approval reference, and notes.

The operation:

1. Locks the legacy account and financial year.
2. Validates the financial year and closed-period policy.
3. Creates a PAYMENT credit transaction.
4. Creates payment details.
5. Deposits the amount into the existing Collections Fund.
6. Refreshes the account status projection.
7. Writes an audit event.
8. Commits all writes together.

A unique transaction reference and optional unique transaction number provide idempotency protection. Overpayments produce a negative derived balance, representing a credit, and set the account to OVERPAID.

### Request an action

    POST api/legacy_ledger.php?action=REQUEST_ACTION

Supported actions:

- PAYMENT_REVERSAL
- ADJUSTMENT
- WAIVER
- WRITE_OFF

Required fields include account, financial year, action type, reason code, and reason text. Adjustments additionally require adjustment_type DEBIT or CREDIT. Reversals require target_transaction_id.

The request is stored in legacy_action_requests and audited. It does not alter the ledger until approved and executed.

### Approve an action

    POST api/legacy_ledger.php?action=APPROVE_ACTION

Requires action_request_id and reason.

The requester cannot approve their own request.

Write-offs require two independent approvals:

- one Accountant
- one Admin

Other actions require one authorized independent approver according to role.

### Execute an action

    POST api/legacy_ledger.php?action=EXECUTE_ACTION

Execution requires an approved request and creates the immutable posting:

- ADJUSTMENT_DEBIT
- ADJUSTMENT_CREDIT
- WAIVER
- WRITE_OFF
- PAYMENT_REVERSAL

A reversal locks the original PAYMENT, verifies that it is still posted and not already reversed, creates a separate PAYMENT_REVERSAL debit, marks the original payment reversed, and preserves both records.

Waivers and write-offs cannot exceed the current derived balance.

### Post interest or penalty

    POST api/legacy_ledger.php?action=POST_CHARGE

Only Accountant and Admin may post manually entered INTEREST or PENALTY transactions.

Required fields include account, financial year, amount, transaction date, transaction reference, and reason.

No automatic interest calculation is introduced.

## Closed financial years

The API allows controlled post-close payments and charges only when:

- The financial year has post_close_transactions_allowed enabled.
- A post-close approval reference is supplied.
- A reason is supplied.
- The actor and timestamp are stored in the ledger/audit event.

Original transactions remain immutable. The financial year is not reopened.

## Disputed accounts

The API does not reject payments solely because dispute_status is OPEN. The account remains DISPUTED when its status projection is refreshed.

## Funding account

Legacy payments use the existing Collections Fund account name and funding_accounts/funding_transactions schema. The deposit and ledger transaction are in the same database transaction.

## Concurrency and idempotency

- Account and financial-year rows are locked with SELECT FOR UPDATE.
- Payment transaction references are unique.
- Payment source transaction numbers are unique when supplied.
- Action request references are unique.
- One action request can execute once.
- One payment can have only one PAYMENT_REVERSAL.
- All financial and funding writes roll back together on failure.

## Balance authority

The API never accepts a balance from the client and never overwrites a financial balance. It calculates:

    SUM(debit_amount) - SUM(credit_amount)

The account status is a derived projection only.

## Test coverage

tests/legacy_ledger_test.php verifies:

- required Phase 2 tables
- one account per debtor
- normal ledger posting
- overpayment credit
- debit/credit invariant
- posted transaction immutability
- closed-year authorization
- transaction rollback

Run it only against a dedicated database whose name begins with legacy_test_:

    set LEGACY_TEST_DB=legacy_test_loan
    php tests/legacy_ledger_test.php

## Known boundary

The current repository does not expose a live production database connection to the development environment. The Phase 2 API and test script are syntax/static validated; run the test script against a staging database after applying scripts/legacy_ledger_phase2.sql.

Migration-date payment routing to normal loans is intentionally not implemented in Phase 2 because the normal-loan migration workflow is a later controlled phase. The Phase 2 payment engine requires an explicit legacy account and financial year and does not provide an arbitrary redirect path.

