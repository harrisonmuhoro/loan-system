# Phase 4 Legacy Debt RBAC and Approval Controls

## Implementation

Phase 4 adds database-backed granular permissions and server-side authorization.

Apply after the earlier legacy migrations:

1. `scripts/legacy_debt_foundation.sql`
2. `scripts/legacy_ledger_phase2.sql`
3. `scripts/legacy_import_phase3.sql`
4. `scripts/legacy_rbac_phase4.sql`

The runtime authorization layer is `api/legacy_authorization.php`. It resolves the authenticated user id from the session, then reads the user's active state and role from `users`. The session role and request payload are not trusted for authorization.

## Permission groups

- `legacy.view`
- `legacy.import`
- `legacy.payment.create`
- `legacy.payment.reconcile`
- payment reversal request/approve/process
- adjustment request/approve/process
- waiver request/approve/process
- write-off request/approve/process
- migration request/approve
- `legacy.interest.apply`
- `legacy.penalty.apply`
- `legacy.audit.view`

Loanees receive no legacy permission by default. Cashiers and accountants can operate within their approved workflows. Write-offs require both an Accountant and an Admin approval; no amount threshold is used.

## Approval rules

Approval and execution are checked on the server. The requester cannot approve the same action. Existing database triggers provide a second enforcement layer for requester/approver identity fields. Write-off approvals must contain both required roles before execution.

## Test

Run against a dedicated database whose name begins with `legacy_test_`:

```text
LEGACY_TEST_DB=legacy_test_phase4 php tests/legacy_rbac_phase4_test.php
```

The ledger and import endpoints now enforce permissions through the shared authorization layer.
